<?php

if ( !class_exists('FV_Player_Pro_YouTube') ) :

class FV_Player_Pro_YouTube {

  static $instance = null;

  public static function _get_instance() {
    if( !self::$instance ) {
      self::$instance = new self();
    }

    return self::$instance;
  }

  function __construct() {
    add_action( 'fv_player_youtube_inputs_after', array( $this, 'settings_pro' ) );

    add_filter( 'fv_player_meta_data_youtube', array( $this, 'add_chapters' ), 10, 5 );
  }

  function add_chapters( $videoData, $video_url, $youtube_obj, $videoObj, $fv_player_meta ) {

    if ( ! empty( $youtube_obj->items[0]->snippet->description ) ) {
      // Parse and save chapters if video doesn't have them yet
      if ( ! $videoObj || ! method_exists( $videoObj, 'getMetaValue' ) || ! $videoObj->getMetaValue( 'chapters', true ) ) {
        $videoData['chapters'] = $this->save_chapters_as_vtt( $youtube_obj->items[0]->snippet->description, $videoData['name']);
      }
    }

    return $videoData;
  }

  function get_timestamp_to_seconds( $timestamp ) {
    $parts = array_reverse( explode( ':', $timestamp ) );

    $seconds = intval( $parts[0] );

    if ( isset( $parts[1] ) ) {
      $seconds += intval( $parts[1] ) * MINUTE_IN_SECONDS;
    }

    if ( isset( $parts[2] ) ) {
      $seconds += intval( $parts[2] ) * HOUR_IN_SECONDS;
    }

    return $seconds;
  }

  function is_youtube( $sURL ) {
    if(
      (
        preg_match( "~(?:youtube\.com|youtube-nocookie\.com)/.*?(?:v|list)=([a-zA-Z0-9_-]+)(?:\?|$|&)~i", $sURL, $aDynamic ) ||
        preg_match( "~(?:youtube\.com|youtube-nocookie\.com)/shorts/([a-zA-Z0-9_-]+)(?:\?|$|&)~i", $sURL, $aDynamic ) ||
        preg_match( "~youtu.be/([a-zA-Z0-9_-]+)(?:\?|$|&)~i", $sURL, $aDynamic ) ||
        preg_match( "~(?:youtube\.com|youtube-nocookie\.com)/embed/([a-zA-Z0-9_-]+)(?:\?|$|&)~i", $sURL, $aDynamic ) ||
        preg_match( "~(?:youtube\.com|youtube-nocookie\.com)/user/([a-zA-Z0-9_-]+)(?:\?|$|&)~i", $sURL, $aDynamic ) ||
        preg_match( "~(?:youtube\.com|youtube-nocookie\.com)/channel/([a-zA-Z0-9_-]+)(?:\?|$|&)~i", $sURL, $aDynamic )
      ) && ! FV_Player_Pro()->_get_option( array('pro','youtube_disable') )
    ) {
      return $aDynamic;
    }
    return false;
  }

  function save_chapters_as_vtt( $description, $video_name ) {

    // Parse YouTube timestamp format like "0:00 Intro" or "1:23:45 Chapter Name"
    preg_match_all('/((?:\d{1,2}:)?\d{1,2}:\d{2})\s+([^\n]+)/', $description, $matches);

    if( empty($matches[0]) ) return false;

    $chapters = array();
    foreach( $matches[1] as $k => $timestamp ) {
      $chapters[] = array(
        'time' => $this->get_timestamp_to_seconds($timestamp),
        'title' => trim($matches[2][$k])
      );
    }

    if( empty($chapters) ) return false;

    // Generate VTT content
    $vtt = "WEBVTT\n\n";
    foreach( $chapters as $chapter ) {
      $vtt .= gmdate("H:i:s", $chapter['time']) . ".000 --> ";
      $vtt .= isset($chapters[$k+1]) ? gmdate("H:i:s", $chapters[$k+1]['time']) : '99:59:59';
      $vtt .= ".000\n";
      $vtt .= $chapter['title'] . "\n\n";
    }

    // save to file
    $upload_dir = wp_upload_dir();
    $upload_path = str_replace( '/', DIRECTORY_SEPARATOR, $upload_dir['path'] ) . DIRECTORY_SEPARATOR;

    $filename = $video_name .'-chapters.vtt';

    $res = file_put_contents( $upload_path . $filename, $vtt );

    if ( ! $res ) {die( __CLASS__ . '::' . __FUNCTION__ . ':' . __LINE__ . "\n" );
      return false;
    }

    // Handle upload file
    if( !function_exists( 'wp_handle_sideload' ) ) {
      require_once( ABSPATH . 'wp-admin/includes/file.php' );
    }

    // Debug error
    if( !function_exists( 'wp_get_current_user' ) ) {
      require_once( ABSPATH . 'wp-includes/pluggable.php' );
    }

    // New file
    $file             = array();
    $file['error']    = '';
    $file['tmp_name'] = $upload_path . $filename;
    $file['name']     = $filename;
    $file['type']     = 'text/vtt';
    $file['size']     = filesize( $upload_path . $filename );

    $file_return = wp_handle_sideload( $file, array( 'test_form' => false ) );

    // if no error
    if ( empty( $file_return['error'] ) ) {
      $filename = $file_return['file'];

      $attachment = array(
        'post_mime_type' => $file_return['type'],
        'post_title' => preg_replace('/\.[^.]+$/', '', basename($filename)),
        'post_content' => '',
        'post_status' => 'inherit',
        'guid' => $upload_dir['url'] . '/' . basename($filename)
      );

      $attach_id = wp_insert_attachment( $attachment, $filename, 0, true );

      if( !is_wp_error( $attach_id ) ) {
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        $attach_data = wp_generate_attachment_metadata( $attach_id, $filename );
        wp_update_attachment_metadata( $attach_id, $attach_data );

        $chapters_src = wp_get_attachment_url( $attach_id );
      }

    }

    return $chapters_src;
  }

  function settings_pro() {
    ?>
    <tr>
      <td class="first"><label for="pro[youtube_qs]">Enable quality switching:</label></td>
      <td>
        <p class="description">
          <input id="pro[youtube_qs]" checked="checked" type="checkbox" readonly disabled>
          Unfortunately the quality switching has been <strong>deprecated</strong> by YouTube - it will automatically serve the best possible video quality.
        </p>
      </td>
    </tr>
    <?php if( FV_Player_Pro()->_get_option( array('pro','youtube_titles_disable') ) ) FV_Player_Pro()->_get_checkbox(__('Disable video captions', 'fv-player-pro'), array('pro', 'youtube_titles_disable'), __('Normally the video title is parsed into the shortcode when saving the post, with this setting it won\'t appear.', 'fv-player-pro') ); ?>
    <?php FV_Player_Pro()->_get_checkbox(__("Disable YouTube <a href='#'>Video Ads</a> on mobile", 'fv-player-pro'), array('pro', 'youtube_ads_disable'), __("Using FV Player Pro Video Ads for YouTube only works for iOS >= 10 and Android >= 6.", 'fv-player-pro'), __("Use this checkbox to disable it completely for all mobile phones if you get any complaints.", 'fv-player-pro') ); ?>
    <script>
      jQuery('[for=pro\\[youtube_ads_disable\\]] a').on("click", function(e) {
        e.preventDefault();
        e.stopPropagation();

        jQuery('[href=#postbox-container-tab_video_ads]').trigger('click');;
      });
    </script>
    <tr>
      <td colspan="4">
        <a class="fv-wordpress-flowplayer-save button button-primary" href="#" style="margin-top: 2ex;"><?php _e('Save', 'fv-wordpress-flowplayer'); ?></a>
        <input type="button" class="button" value="<?php _e('Convert YouTube embed codes', 'fv-player-pro'); ?>" style="margin-top: 2ex;" onclick="if( confirm('<?php _e('This converts the IFRAME and OBJECT YouTube embeds in post content and post meta into [fvplayer] shortcodes.\n\n Please make sure you backup your database before continuing.', 'fv-player-pro'); ?>') ) location.href='<?php echo wp_nonce_url( admin_url( 'options-general.php?page=fvplayer' ), 'convert_youtube', 'convert_youtube'); ?>'; "/>
        <a class="button fv-help-link" style="margin-top: 2ex;" href="https://foliovision.com/player/video-hosting/youtube-with-fv-player" target="_blank">Help</a>
      </td>
    </tr>
    <?php
  }
}

function FV_Player_Pro_YouTube() {
  return FV_Player_Pro_YouTube::_get_instance();
}

FV_Player_Pro_YouTube();

endif;
