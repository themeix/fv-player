<?php

if( !class_exists('FV_Player_Pro_Rumble') ) :

class FV_Player_Pro_Rumble extends FV_Player_Pro_Ajax_Loader {

  var $aRumbleQualities = array( '-mobile' => '240p', '-sd' => '360p', '-md' => '480p', '-hd' => '720p', '-fullhd' => '1080p', '-qhd' => '1440p', '-4k' => '2160p' );

  function __construct() {
    $this->aDomains = array( 'rumble\.com\/.*?-.*?\.html$' );

    $this->regexDomain = true;

    $this->aSecureTokens = array( 'override' );

    add_filter( 'fv_flowplayer_get_mime_type', array( $this, 'set_file_type' ), 10 , 2 );

    add_filter('fv_player_meta_data', array( $this, 'fetch_rumble_data' ), 10, 2); // splash, caption

    add_filter( 'fv_player_video_checker_skip', array( $this, 'skip_video_checker'), 10, 2 ); // takes too long to load page if not skipped

    add_filter( 'fv_flowplayer_attributes', array( $this, 'quality_attributes' ), 10, 3 );

    parent::__construct( array( 'key' => 'rumble', 'title' => 'Rumble') );
  }

  function args( $args ) {
    $args[] = 'verify';
    return $args;
  }

  function ajax() {
    if( isset($_POST['action']) && $_POST['action'] == 'fv_fp_get_video_url' ) {

      if( isset($_POST['is_live']) && $_POST['is_live'] ) $this->is_live = true;

      foreach( $this->aDomains AS $i => $sDomains ) {
        $aDomains = explode(',',$sDomains);
        foreach( $aDomains AS $sDomain ) {
          if( !trim($sDomain) ) {
            continue;
          }

          foreach( $_POST['sources'] AS $key => $aVideo ) {
            if( !isset($aVideo['src']) || !isset($aVideo['type']) ) continue;

            if( stripos($aVideo['src'], $sDomain) !== false || $this->regexDomain && preg_match( '~'.$sDomain.'~', $aVideo['src'], $matches ) ) {
              $secureToken = isset($this->aSecureTokens[$i]) ? $this->aSecureTokens[$i] : '';
              $this->secure_link($aVideo['src'], $secureToken); // add new sources from secure_link
              unset($_POST['sources'][$key]); // remove original source
              $_POST['sources'] = array_values($_POST['sources']); // reindex
            }
          }
        }
      }
    }
  }

  function secure_link( $url, $securityKey, $ttl = false ) {
    $new_cache = false;

    if( !$this->is_rumble_video($url)) {
      return $url;
    }

    if ( $cached_data = $this->load_cache( $url ) ) { // use cache if stored
      foreach ( $cached_data as $item ) {
        $_POST['sources'][] = $item;
      }

      return $cached_data;
    }

    $data = $this->get_video_data($url);

    if( !is_array($data) ) {
      return $url;
    }

    // get parsed embed id
    $embed_id = $this->get_embed_id( $data[0]['embedUrl'] );

    if( !$embed_id ) {
      return $url;
    }

    // get hls/mp4 list
    $body = $this->get_embed_streams_data($embed_id);

    $have_source = false;

    if( $this->is_live || ( isset($body['ua']['hls']['auto']['url']) && isset($body['live']) && $body['live'] > 0 ) ) { // live stream or non live hls
      $new_cache = array();

      if ( ! empty( $body['ua']['hls']['auto']['url'] ) ) {
        $have_source = true;

        $stream =  array(
          'src' => $body['ua']['hls']['auto']['url'],
          'type' => 'application/x-mpegurl'
        );

        $_POST['sources'][] = $stream;

        $new_cache[] = $stream;
      }

    }

    if( ! $have_source && isset( $body['ua']['mp4'] ) ) { // multiple mp4
      krsort($body['ua']['mp4']); // sort qualities

      $body['ua']['mp4'] = array_reverse( $body['ua']['mp4'], true );

      $new_cache = array();
      // we get multiple mp4 qualities
      foreach( $body['ua']['mp4'] as $quality => $metadata ) {
        $quality = array(
          'src' => $metadata['url'] . '#-' . $this->quality_map($quality),
          'type' => 'video/mp4'
        );

        $_POST['sources'][] =  $quality;
        $new_cache[] = $quality;
      }
    }

    return $this->store_cache( $url, $new_cache );
  }

  function set_file_type( $type ) {
    $args = func_get_args();
    if( isset($args[1]) ) {
       if( $this->is_rumble_video($args[1]) ) {
        $type = "video/mp4";
      }
    }

    return $type;
  }

  function fetch_rumble_data( $url, $post_id = false ) {
    if( !$this->is_rumble_video($url) ) {
      return $url;
    }

    $data = $this->get_video_data($url);
    $videoData = false;

    if( is_array($data) ) {
      $videoData = array();

      if( isset($data[0]['thumbnailUrl']) || isset($data[0]['name']) || isset($data[0]['duration']) ) {

        if( isset($data[0]['duration']) ) {
          $videoData['duration'] = $this->ISO8601ToSeconds($data[0]['duration']);
        }

        if( isset($data[0]['thumbnailUrl']) ) {
          $videoData['thumbnail'] = esc_url(html_entity_decode($data[0]['thumbnailUrl']));
        }

        if( isset($data[0]['name']) ) {
          $videoData['name'] = $data[0]['name'];
        }

        $embed_id = $this->get_embed_id( $data[0]['embedUrl'] );

        // check if live stream
        if( $embed_id ) {
          $body = $this->get_embed_streams_data($embed_id);
          if( is_array($body) ) {
            if( isset($body['live']) && $body['live'] == 2 ) { // 2 - live, 1 - live stream done, still in hls
              $videoData['is_live'] = true;
            }
          }
        }
      }
    }

    return $videoData;
  }

  function quality_attributes( $aAttributes ) {
    $aArgs = func_get_args();
    if( isset($aArgs[2]->aCurArgs['src']) && $this->is_rumble_video($aArgs[2]->aCurArgs['src']) ) {
      $aAttributes['data-qsel'] = implode( ',', array_reverse(array_keys($this->aRumbleQualities)));
      $aAttributes['data-qlabels'] = implode( ',', array_reverse($this->aRumbleQualities));
    }

    return $aAttributes;
  }

  function skip_video_checker( $skip, $media ) {
    if( $this->is_rumble_video($media) ) {
      $skip = true;
    }

    return $skip;
  }

  /**
   * Check if link is rumble video
   *
   * @param mixed $url
   *
   * @return bool if matched id then returns it, otherwise returns false
   */
  function is_rumble_video($url) {
    if( is_string($url) && stripos($url,'https://rumble.com') !== false ) {
      if( preg_match('/rumble\.com\/.*?-.*?\.html/', $url, $matches) ) {
        return true;
      }
    }

    return false;
  }

  /**
   * Retrieve parsed JSON from script tag
   *
   * @param string $url
   *
   * @return array|false
   */
  function get_video_data($url) {
    $response = wp_remote_get($url);

    if( !is_wp_error($response) ) {
      $body = wp_remote_retrieve_body($response);

      if( preg_match('/<script type=application\/ld\+json>([\s\S]*?)<\/script>/', $body, $metadata) ) {
        $json = json_decode($metadata[1], true);

        if( !empty($json) ) return $json;
      }
    }

    return false;
  }

  /**
   * Retrieve embed streams data
   *
   * @param string $embed_id
   *
   * @return array|false
   */
  function get_embed_streams_data($embed_id) {
    // compose embed request link
    $url = 'https://rumble.com/embedJS/u3/';
    $url = add_query_arg( 'request', 'video', $url);
    $url = add_query_arg( 'ver', '2', $url);
    $url = add_query_arg( 'v', $embed_id, $url);
    $url = add_query_arg( 'ext', '{"ad_count":null}', $url);
    $url = add_query_arg( 'ad_wt', 0, $url);

    $response = wp_remote_get($url);

    if( !is_wp_error($response) ) {
      $body = wp_remote_retrieve_body( $response );
      $body = json_decode( $body, true );

      if( is_array($body) ) return $body;
    }

    return false;
  }

  /**
   * Get embed id from url
   *
   * @param string $url
   *
   * @return string|false
   */
  function get_embed_id($url) {
    preg_match('/rumble\.com\/embed\/(.*?)\//', $url, $matches);
    if( isset($matches[1]) ) return $matches[1];

    return false;
  }

  /**
   * Convert ISO 8601 values like P2DT15M33S
   * to a total value of seconds.
   *
   * @param string $ISO8601
   *
   * @return int seconds
   */
  function ISO8601ToSeconds($ISO8601) {
    $interval = new DateInterval($ISO8601);

    return ($interval->d * 24 * 60 * 60) +
      ($interval->h * 60 * 60) +
      ($interval->i * 60) +
      $interval->s;
  }

  function quality_map($height) {
    $height = intval($height);

    $qualities = array(
      240 => 'mobile',
      360 => 'sd',
      480 => 'md',
      720 => 'hd',
      1080 => 'fullhd',
      1440 => 'qhd',
      2160 => '4k'
    );

    return isset($qualities[$height]) ? $qualities[$height] : 'custom';
  }

}

global $FV_Player_Pro_Rumble;
$FV_Player_Pro_Rumble = new FV_Player_Pro_Rumble;

endif;
