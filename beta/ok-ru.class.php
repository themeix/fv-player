<?php

if( !class_exists('FV_Player_Pro_Ok_RU') ) :

class FV_Player_Pro_Ok_RU extends FV_Player_Pro_Ajax_Loader {

  function __construct() {
    $this->aDomains      = array( '//ok.ru/video/', '//m.ok.ru/video/');

    $this->aSecureTokens = array( 'override' );

    add_filter( 'fv_flowplayer_get_mime_type', array( $this, 'set_file_type'), 10 , 2 );

    add_filter('fv_player_meta_data', array( $this, 'fetch_ok_ru_data' ), 10, 2);

    add_filter( 'fv_player_video_checker_skip', array( $this, 'skip_video_checker'), 10, 2 );

    add_filter( 'fv_player_editor_screenshot_disable_domains', array( $this, 'editor_screenshot_disabled_domains' ) );

    parent::__construct( array( 'key' => 'ok_ru', 'title' => 'Ok_RU') );
  }

  function args($args) {
    $args[] = 'verify';
    return $args;
  }

  function secure_link( $url, $securityKey, $ttl = false ) {
    $video_id = $this->get_video_id($url);
    $new_cache = false;

    if( !$video_id ) {
      return $url;
    }

    if ( $cached_url = $this->load_cache( $video_id ) ) {
      return $cached_url;
    }

    $api_url = 'https://m.ok.ru/video/' . $video_id; // use mobile url

    $response = wp_remote_get( $api_url, array( // must use ios to get to the video-data
      'user-agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.8; rv:20.0) Gecko/20100101 Firefox/20.0'
      )
    );

    if( !is_wp_error( $response ) ) {
      $body = wp_remote_retrieve_body( $response );

      $video_data = preg_match('~data-video="(.*?)"~', $body, $matches); // video src is storred in data-video attribute
      $video_data = html_entity_decode($matches[1]);
      $video_data = json_decode( $video_data, true );

      if( isset($video_data['videoSrc']) ) {
        $new_cache = $video_data['videoSrc'];
      }
    }

    if( !$url = $this->store_cache( $video_id, $new_cache ) ) { // no video-data
      $_POST['error'] =  __( 'ok.ru error - please check if video is not live or 18+', 'fv-player-pro');
    }

    return $url;
  }

  function fetch_ok_ru_data($url, $post_id = false) {
    if( !$this->get_video_id($url) ) {
      return $url;
    }

    $response = wp_remote_get( $url );
    $videoData = false;

    if( !is_wp_error($response) ) {
      $body = wp_remote_retrieve_body( $response );

      preg_match('~<meta property="og:video:duration" content="(.*?)">~', $body, $duration); // match duration in meta tag
      preg_match('~<meta property="og:image" content="(.*?)">~', $body, $splash); // match splash in meta
      preg_match('~<title>(.*?)</title>~', $body, $caption); // match caption in title

      if( isset($duration[1]) && isset($caption[1]) && isset($splash[1]) ) {
        $duration = intval($duration[1]);
        $splash = esc_url(html_entity_decode($splash[1]));
        $caption = $caption[1];

        $videoData = array(
          'name' => $caption,
          'thumbnail' => $splash,
          'duration' => $duration,
        );
      }
    }

    return $videoData;
  }

  function set_file_type( $type ) {
    $args = func_get_args();
    if( isset($args[1]) ) {
       if( $this->get_video_id($args[1]) ) {
        $type = "video/mp4";
      }
    }

    return $type;
  }

  function skip_video_checker( $skip, $media ) {
    if( $this->get_video_id($media) ) {
      $skip = true;
    }

    return $skip;
  }

  function get_video_id($url) {
    if( is_string($url) && ( stripos( $url, 'https://ok.ru' ) !== false || stripos( $url, 'https://m.ok.ru' ) !== false ) ) {
      if( preg_match('~\/video\/(\d+)~', $url, $matches) ) {
        if( isset($matches[1]) ) {
          return $matches[1];
        }
      }
    }

    return false;
  }

  function editor_screenshot_disabled_domains( $domains ) {
    $domains[] = 'https://ok.ru';
    $domains[] = 'https://m.ok.ru';

    return $domains;
  }

}

global $FV_Player_Pro_Ok_RU;
$FV_Player_Pro_Ok_RU = new FV_Player_Pro_Ok_RU;

endif;
