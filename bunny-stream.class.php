<?php

if( !class_exists('FV_Player_Pro_Bunny_Stream') ) :

class FV_Player_Pro_Bunny_Stream extends FV_Player_Pro_Ajax_Loader {

  function __construct() {
    // Used to prevent the settings box from creating
    $this->aDomains = array( 'FV_Player_Pro_Bunny_Stream do not show settings' );
    $this->aSecureTokens = array( 'override' );

    parent::__construct( array( 'key' => 'bunnystream', 'title' => 'BunnyStream') );
    add_filter( 'fv_player_item', array( $this, 'add_stream_loader' ), 14, 3 );
    add_action( 'fv_player_bunny_stream_settings_saved', array( $this, 'settings' ), 10 );
  }

  function args($args) {
    $args[] = 'token';
    return $args;
  }

  public function load_options() {
    global $fv_fp;

    if( !empty($fv_fp) && method_exists( $fv_fp, '_get_option' ) ) {
      $bunny_cdn_hostname = $fv_fp->_get_option( array('bunny_stream', 'cdn_hostname') );
      $bunny_security_token = $fv_fp->_get_option( array('bunny_stream', 'security_token') );
    }

    if( !empty($bunny_cdn_hostname) && !empty($bunny_security_token) ) {
      $this->aDomains      = array( 'https://'.$bunny_cdn_hostname.'/');
      $this->aSecureTokens = array( $bunny_security_token );
    }

    parent::load_options();
  }

  function secure_link( $url, $securityKey, $ttl = false ) {
    $parsed = wp_parse_url( $url );
    $expires = time() + ( $ttl ? $ttl : apply_filters('fv_player_secure_link_timeout', 900) );
    $hashableBase = $securityKey.urldecode($parsed['path']).$expires;

    $token = md5($hashableBase, true);
    $token = base64_encode($token);
    $token = strtr($token, '+/', '-_');
    $token = str_replace('=', '', $token);  

    $url = add_query_arg( 'token', $token, $url);
    $url = add_query_arg( 'expires', $expires, $url);

    return $url;
  }

  function add_stream_loader($aItem, $index, $aArgs) {
    if ( is_array($aItem['sources']) && FV_Player_Pro()->_get_option(array('bunny_stream', 'video_token')) ) {
      foreach( $aItem['sources'] as $index => $source ) {
        if ( $this->is_bunny_stream($source['src']) ) {
          $aItem['sources'][$index]['src'] =  FV_Player_Pro_Stream_Loader()->stream_loader_url( $source['src'], -1 );
        }
      }
    }

    return $aItem;
  }

  /**
   * @param array $data $_POST['bunny_stream'] from wp-admin -> FV Player -> Bunny Stream Jobs -> Settings
   */
  function settings($data) {
    foreach( $data as $k => $v ) {
      if(strcmp($k, 'video_token') === 0) {
        $this->set_video_token_auth(filter_var($v, FILTER_VALIDATE_BOOLEAN));
      }
    }
  }

  /**
   * Enable or disable token authentification for video library
   *
   * @param bool $enable
   *
   * @return void
   */
  private function set_video_token_auth($enable) {
    global $fv_fp;

    // Use the nonce on wp-admin -> FV Player -> Bunny Stream Jobs -> Settings
    if (
      empty( $_POST['fv_player_bunny_stream_settings_nonce'] ) ||
      ! wp_verify_nonce( $_POST['fv_player_bunny_stream_settings_nonce'], 'fv_player_bunny_stream_settings_nonce' )
    ) {
      return;
    }

    $lib_id = $fv_fp->_get_option( array('bunny_stream', 'lib_id') );
    if( !empty($lib_id) && isset($_POST['bunny_stream']['api_access_key']) ) {
      $api_access_key = sanitize_key($_POST['bunny_stream']['api_access_key']);

      if( !empty($api_access_key)) {
        $api = new FV_Player_Bunny_Stream_API($api_access_key);
  
        $response = $api->api_call( 'https://api.bunny.net/videolibrary/' . $lib_id ,array( "PlayerTokenAuthenticationEnabled" => $enable,"EnableTokenAuthentication" => $enable), 'POST' );

        if( is_wp_error($response) ) {
          $error_msg = $response->get_error_message();
        } else if($enable) { // get security token if token auth enabled
          $pull_zone_id = intval($response->PullZoneId);

          $response = $api->api_call( 'https://api.bunny.net/pullzone/' . $pull_zone_id );

          if( is_wp_error($response) ) {
            $error_msg = $response->get_error_message();
          } else { 
            if(!empty($response->ZoneSecurityKey)) {
              $fv_fp->conf['bunny_stream']['security_token'] = trim($response->ZoneSecurityKey);
              $fv_fp->_set_conf( $fv_fp->conf );
            }
          }
        } else {
          unset($fv_fp->conf['bunny_stream']['security_token']);
          $fv_fp->_set_conf( $fv_fp->conf );
        }
      }
    }
  }

  /**
   * Detect if src is bunny stream
   *
   * @param mixed $src
   *
   * @return boolean
   */
  public function is_bunny_stream($src) {
    global $fv_fp;

    $bunny_cdn_hostname = $fv_fp->_get_option( array('bunny_stream', 'cdn_hostname') );
    if( !empty($bunny_cdn_hostname) ) {
      $parsed_src = wp_parse_url($src);
      if( !empty($parsed_src) && strcmp($parsed_src['host'], $bunny_cdn_hostname) === 0 ) {
        return true;
      }
    }

    return false;
  }

}

global $FV_Player_Pro_Bunny_Stream;
$FV_Player_Pro_Bunny_Stream = new FV_Player_Pro_Bunny_Stream;

endif;