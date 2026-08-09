<?php

if( !class_exists('FV_Player_Pro_Peertube') ) :

class FV_Player_Pro_Peertube extends FV_Player_Pro_Ajax_Loader {
  function __construct() {

    // Match https://framatube.org/w/akGMgK9ZtnKfYAgnEtQxbv?start=0
    // Match https://framatube.org/w/akGMgK9ZtnKfYAgnEtQxbv
    // Do not match: https://your-site.com/w/video.mp4
    $this->aDomains = array( '/w/([a-zA-Z0-9]+)(\?.+)?$' );

    $this->regexDomain = true;

    $this->aSecureTokens = array( 'override' );

    add_action( 'admin_init', array( $this, 'update_peertube_domains' ), 12 ); // update peertube domains

    add_action( 'admin_init', array( $this, 'register_meta_boxes' ), 20 );

    add_filter( 'fv_flowplayer_get_mime_type', array( $this, 'set_file_type' ), 10 , 2 );

    add_filter( 'fv_player_meta_data', array( $this, 'fetch_peertube_data' ), 10, 2); // splash, caption, duration

    add_filter( 'fv_player_video_checker_skip', array( $this, 'skip_video_checker'), 10, 2 ); // takes too long to load page if not skipped

    parent::__construct( array( 'key' => 'peertube', 'title' => 'PeerTube') );
  }

  function update_peertube_domains() {
    if ( wp_doing_ajax() ) return;

    $domains = $this->load_cache('domains'); // get cached values

    if( empty($domains) ) {
      $domains_new = $this->get_peertube_domains();

      if( $domains_new ) {
        $this->store_cache('domains', $domains_new); // update cache with new domains
      } else { // fallback
        $domains_old = $this->load_cache('domains', true);

        if( $domains_old ) {
          $this->store_cache('domains', $domains_old); // update cache with old domains
        }
      }
    }

  }

  function args($args) {
    $args[] = 'verify';
    return $args;
  }

  function options() {
    global $fv_fp;
    ?>
    <table class="form-table2" style="margin: 5px; ">
      <tr>
        <td style="width: 250px">Supported PeerTube instances</td>
        <td>
          <p>FV Player Pro supports PeerTube instances found in the <a href="https://joinpeertube.org/instances" target="_blank">registry of instances</a>.</p>
        </td>
      </tr>
      <tr>
        <td></td>
        <td>
          <a class="button" href="#" onclick="jQuery(this).next().toggle(); return false">Show cached domains list</a>
          <ul style="display: none">
            <?php
            $domains = $this->load_cache('domains');
            sort($domains);
            foreach( $domains AS $domain ) {
              echo "<li>".$domain."</li>\n";
            }
            ?>
          </ul>
        </td>
      </tr>
    </table>
    <?php
  }

  function register_meta_boxes() {
    add_meta_box( 'fv_player_peertube', __('PeerTube', 'fv-player-pro'), array( $this, 'options' ), 'fv_flowplayer_settings_hosting', 'normal', 'low' );
  }

  function secure_link( $url, $securityKey, $ttl = false ) {
    $domain = $this->get_peertube_domain($url);
    $video_id = $this->get_video_id($url);

    $new_cache = false;

    // Not an actual PeerTube video URL
    if( !$video_id ) {
      return $url;
    }

    if( !$domain ) {
      $_POST['error'] = __( 'PeerTube error: Unsupported domain or private video.', 'fv-player-pro');
      return false;
    }

    if ( $cached_url = $this->load_cache( $video_id ) ) {
      return $cached_url;
    }

    $api_url = str_replace('/w/','/', $domain) . 'api/v1/videos/' . $video_id;

    $response = wp_remote_get( $api_url );

    if( !is_wp_error( $response ) ) {
      $body = wp_remote_retrieve_body( $response );

      $video_data = json_decode( $body, true );

      if( isset($video_data['streamingPlaylists'][0]['playlistUrl']) ) { // get m3u8
        $new_cache = $video_data['streamingPlaylists'][0]['playlistUrl'];
      }
    }

    return $this->store_cache( $video_id, $new_cache );
  }

  function set_file_type( $type ) {
    $args = func_get_args();
    if( isset($args[1]) ) {
       if( $this->get_peertube_domain($args[1]) && $this->get_video_id($args[1]) ) {
        $type = "video/mp4";

        global $fv_fp;
        $fv_fp->load_hlsjs = true;
      }
    }

    return $type;
  }

  function fetch_peertube_data($url, $post_id = false) {
    $domain = $this->get_peertube_domain($url);
    $video_id = $this->get_video_id($url);

    if( !$domain || !$video_id ) {
      return $url;
    }

    $api_url = str_replace('/w/','/',$domain) . 'api/v1/videos/' . $video_id;

    $response = wp_remote_get( $api_url );
    $videoData = false;

    if( !is_wp_error($response) ) {
      $body = wp_remote_retrieve_body( $response );

      $video_data = json_decode( $body, true );

      $duration = intval($video_data['duration']);

      // Prefer previewPath as it has higher resolution image
      $splash_url = !empty($video_data['previewPath']) ? $video_data['previewPath'] : $video_data['thumbnailPath'];

      $splash = esc_url( rtrim($domain, '/w/') . html_entity_decode($splash_url) );
      $caption = htmlspecialchars( $video_data['name']);
      $synopsis = isset($video_data['description']) ? $video_data['description'] : '';

      $videoData = array(
        'name' => str_replace( array(';','[',']'), array('\;','(',')'), ($caption) ),
        'thumbnail' => $splash,
        'duration' => $duration,
        'synopsis' => $synopsis,
      );

      if( isset($video_data['isLive']) && $video_data['isLive'] ) {
        $videoData['is_live'] = true;
      }

    }

    return $videoData;
  }

  function skip_video_checker( $skip, $media ) {
    if( $this->get_peertube_domain($media) && $this->get_video_id($media) ) {
      $skip = true;
    }

    return $skip;
  }

  /**
   * Return all peertube domains
   *
   * @return array|false
   */
  function get_peertube_domains() {
    $response = wp_remote_get('https://instances.joinpeertube.org/api/v1/instances?start=0&count=99999999');

    if( !is_wp_error($response) ) {
      $body = wp_remote_retrieve_body( $response );
      $body = json_decode( $body, true );

      $domains = array();

      foreach( $body["data"] as $item ) {
        $domains[] = 'https://' . $item["host"] . '/w/';
      }

      return $domains;
    }

    return false;
  }

  /**
   * Return matched domain from link
   *
   * @param string $url
   *
   * @return string|false if matched id then returns it, otherwise returns false
   */
  function get_peertube_domain($url) {
    $domains = $this->load_cache('domains', true);

    if( defined('PHPUnitTestMode') && empty($domains) ) {
      $domains = $this->get_peertube_domains();
      $this->store_cache('domains', $domains);
    }

    if( is_string($url) && is_array($domains) && !empty($domains) ) {
      foreach( $domains as $domain ) {
        if ( strpos( $url, $domain ) !== false ) {
          return $domain;
        }
      }
    }

    return false;
  }

  /**
   * Return video id from link
   *
   * @param mixed $url
   *
   * @return string|false if matched id then returns it, otherwise returns false
   */
  function get_video_id($url) {
    if( is_string($url) ) {
      if( $this->is_peertube_private($url) ) {
        return false;
      }

      if( preg_match('~^/w/([a-zA-Z0-9]+)$~', wp_parse_url($url, PHP_URL_PATH), $matches) ) {
        return $matches[1];
      }
    }

    return false;
  }

  /**
   * Check if url is private peertube video
   *
   * @param mixed $url
   *
   * @return boolean
   */
  function is_peertube_private($url) {
    $options = get_option('fv_player_peertube_private', array());

    if( is_string($url) && !empty($options) && !empty($options['peertube_private_url']) ) {
      $private_url = $options['peertube_private_url'];

      if( stripos($url, $private_url) !== false ) {
        return true;
      }

    }

    return false;
  }

}

global $FV_Player_Pro_Peertube;
$FV_Player_Pro_Peertube = new FV_Player_Pro_Peertube;

endif;
