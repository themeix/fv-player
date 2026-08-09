<?php

if( !class_exists('FV_Player_Pro_Wistia') ) :

class FV_Player_Pro_Wistia extends FV_Player_Pro_Ajax_Loader {

  private $bWistia;
  private $parsed_video_data = array();
  private  $supported_sources = array(
      '360p'  => '-sd',
      '540p'  => '-md',
      '720p'  => '-hd',
      '1080p' => '-fullhd'
  );

  function __construct() {
    parent::__construct( array( 'key' => 'wistia', 'title' => 'Wistia' ) );

    add_action( 'plugins_loaded', array( $this, 'load_options' ), 8 );
  }

  // override the parent AJAX call to add POST values for the determined source URLs
  // so we can enable real qualities for them
  function ajax() {
    if( isset($_POST['action']) && $_POST['action'] == 'fv_fp_get_video_url' ) {

      // load HTML embed data, since we only have the generic source URL
      if ( isset( $_POST['sources'] ) && is_array($_POST['sources']) && isset( $_POST['sources'][0] ) && isset( $_POST['sources'][0]['src'] ) && $this->is_wistia($_POST['sources'][0]['src']) ) {
        $this->getEmbedData( $_POST['sources'][0]['src'] );

        if ( count( $this->parsed_video_data[ $_POST['sources'][0]['src'] ] ) ) {
          foreach ( $this->parsed_video_data[ $_POST['sources'][0]['src'] ] as $video_source ) {

            // only load supported source dimensions
            if ( in_array( $video_source['display_name'], array_keys( $this->supported_sources ) ) ) {
              // determine predefined suffix for our player quality options
              $_POST['sources'][] = array(
                'src'  => $video_source['url'] . '#' . $this->supported_sources[ $video_source['display_name'] ],
                'type' => 'video/mp4'
              );
              unset($this->supported_sources[ $video_source['display_name'] ]); // only load each quality once!

              // todo: these files should work with quality switching
              // todo: support HLS!
            }

          }

        }

        // remove the original embed source and leave just the ones we'll output
        // if we have more than a single source
        if ( count( $_POST['sources'] ) > 1 ) {
          array_shift( $_POST['sources'] );
        }

      }
    }
  }

  function args($args) {
    $args[] = 'token'; // TODO: What it really should be?
    return $args;
  }

  // fetch EMBED HTML data for the Wistia video
  public function getEmbedData( $url ) {
    if (!is_string($url)) {
      return array();
    }

    $original_url = $url;

    if( preg_match('~\.wistia\.(?:com|net)/medias/([a-z0-9]+)~',$url,$match) ) {
      $url = 'http://fast.wistia.net/embed/iframe/'.$match[1];
    }

    if ( ! isset( $this->parsed_video_data[ $original_url ] ) ) {
      // check cache
      $bFound = false;

      $video_id = explode('/', $url);
      $video_id = 'fv_player_pro_wistia_'.$video_id[count($video_id) - 1];

      $objVideo = get_option( $video_id );

      if( $objVideo && isset($objVideo->time) && isset($objVideo->ttl) && (intval($objVideo->time) + intval($objVideo->ttl)) > time() ) {
        $objVideo->cache = true;
        $bFound = true;
      }

      if( !$bFound ) {
        $html_data = wp_remote_get( $url );

        if ( ! is_wp_error( $html_data ) && isset($html_data['body']) ) {
          // parse all asset URLs
          preg_match_all( '/W\.iframeInit\({.*?"assets":(\[[^]]+])/i', $html_data['body'], $matches );

          if ( $matches && count( $matches ) && isset($matches[1][0]) ) {
            $parsed = json_decode( $matches[1][0], true );

            // check that we have the required structure
            if (isset($parsed[0]) && isset($parsed[0]['display_name'])) {
              // check that original file record exists as well as other different qualities
              $original_found = false;
              $more_qualities_found = false;

              $supported_source_types = array_keys($this->supported_sources);
              foreach ($parsed as $video_data) {
                  if (strtolower($video_data['display_name']) == 'original file') {
                      $original_found = true;
                  } else if (in_array($video_data['display_name'], $supported_source_types)) {
                      $more_qualities_found = true;
                  }
              }

              // check that we have original video
              if ($original_found) {
                // set th original video to a super-high quality if it's not the only video in set,
                // otherwise rename it to 720p
                if ($more_qualities_found) {
                  $parsed[0]['display_name'] = '1080p';
                } else {
                  // only the original video is found, make it a HD one
                  $parsed[0]['display_name'] = '720p';
                }

                // prepare the return value
                $this->parsed_video_data[ $original_url ] = $parsed;

                // update cache
                if (!$bFound) {
                  $objVideo = new stdClass;
                  $objVideo->time = ( isset($objVideo->time) && intval($objVideo->time) > 0 ) ? $objVideo->time : time();
                  $objVideo->ttl = ( isset($objVideo->ttl) && intval($objVideo->ttl) > 0 ) ? $objVideo->ttl : 1200;
                  $objVideo->data = $this->parsed_video_data[ $original_url ];
                }

                //update_option($video_id, $objVideo, false);
              } else {
                // original video not found, we don't have the expected structure
                $this->parsed_video_data[ $original_url ] = array();
              }
            } else {
              // we don't seem to have gotten any items
              $this->parsed_video_data[ $original_url ] = array();
            }
          }
        }
      } else {
        // load data from cache
        $this->parsed_video_data[ $original_url ] = $objVideo->data;
      }
    }

    if ( ! isset( $this->parsed_video_data[ $original_url ] ) ) {
      $this->parsed_video_data[ $original_url ] = array();
    }

    return $this->parsed_video_data[ $original_url ];
  }

  public function load_options() {
    if( $this->is_enabled() ) {
      add_filter( 'fv_flowplayer_buttons_right', array( $this, 'getQualityButtons' ) );
      add_filter( 'fv_flowplayer_attributes', array( $this, 'getQualityAttributes' ), 10, 3 );

      $this->aDomains      = array( 'wistia.net/embed', 'wistia.com/medias' );
      $this->aSecureTokens = array( 'override' );

      parent::load_options();
    }
  }

  function secure_link( $url, $securityKey, $ttl = false ) {
    $this->getEmbedData( $url );

    if ( isset( $this->parsed_video_data[ $url ] ) && count( $this->parsed_video_data[ $url ] ) ) {
      return $this->parsed_video_data[ $url ][0]['url'];
    }

    // video not found or parsing error - this will automatically show error in player on front-end
    return '';
  }

  // add HTML markup for the determined qualities to the player
  function getQualityButtons( $aButtons ) {
    global $fv_fp;

    if ( $this->is_wistia( $fv_fp->aCurArgs['src'] ) ) {
      $sContent   = array();
      $first_done = false;
      foreach ( $this->supported_sources as $quality_name => $suffix ) {
        $sContent[] = '<li><a href=\'#\' data-quality=\'' . $suffix . '\'' . ( ! $first_done ? ' class=\'current\'' : '' ) . '>' . $quality_name . '</a></li>';
        $first_done = true;
      }

      $aButtons[] = "<ul class='fv-player-quality'>" . implode( '', $sContent ) . "</ul>";
    }

    return $aButtons;
  }

  // add defined (hard-coded in this case) quality attributes to the player
  function getQualityAttributes( $aAttributes ) {
    global $fv_fp;

    if ( $this->is_wistia( $fv_fp->aCurArgs['src'] ) ) {
      $aAttributes['data-qsel'] = implode( ',', array_values( $this->supported_sources ) );
    }

    return $aAttributes;
  }

  private function is_enabled() {
    global $fv_fp;
    if( isset($fv_fp) ) return $fv_fp->_get_option('wistia_use_fv_player');
    return false;
  }

  private function is_wistia( $sURL ) {
    if (!is_string($sURL)) {
        return false;
    }

    $check = preg_match( "~wistia.(com|net)/(embed|medias)/.*~i", $sURL, $aDynamic );
    if ( $check ) {
      $this->bWistia = true;
    }

    return $check;
  }

  function options() {
    global $fv_fp;
    ?>
    <table class="form-table2" style="margin: 5px; ">
        <?php $fv_fp->_get_checkbox(__('Use advanced embedding', 'fv-player-pro').' (beta)', 'wistia_use_fv_player', __('Instead of using Wistia player, videos will be played in FV Player Pro. Doesn\'t currently support Wistia timeline actions though.', 'fv-player-pro')); ?>
        <tr>
            <td colspan="4">
              <a class="fv-wordpress-flowplayer-save button button-primary" href="#" style="margin-top: 2ex;"><?php _e('Save', 'fv-wordpress-flowplayer'); ?></a>
            </td>
        </tr>
    </table>
    <?php
  }

}

  global $FV_Player_Pro_Wistia;
  $FV_Player_Pro_Wistia = new FV_Player_Pro_Wistia;

endif;
