<?php

if( !class_exists('FV_Player_Pro_Bunny_Stream') ) :

class FV_Player_Pro_Bunny_Stream extends FV_Player_Pro_Ajax_Loader {

  function __construct() {
    // Used to prevent the settings box from creating
    $this->aDomains = array( 'FV_Player_Pro_Bunny_Stream do not show settings' );
    $this->aSecureTokens = array( 'override' );

    add_filter( 'fv_flowplayer_stream_loader_ignore_domains', array( $this, 'ignore_domains' ) );

    add_filter( 'fv_flowplayer_get_mime_type', array( $this, 'set_file_type' ), 10 , 2 );

    parent::__construct( array( 'key' => 'bunnystream', 'title' => 'BunnyStream') );

    add_action( 'fv_player_bunny_stream_settings_saved', array( $this, 'settings' ), 10 );
  }

  function args($args) {
    $args[] = 'token_path';
    return $args;
  }

  /**
   * Add current customer domain to ignore domains to prevent stream loader
   *
   * @param array $domains
   *
   * @return array
   */
  public function ignore_domains( $domains ) {
    $bunny_cdn_hostname = FV_Player_Pro()->_get_option( array('bunny_stream', 'cdn_hostname') );

    if ( ! empty( $bunny_cdn_hostname ) ) {
      $domains[] = 'https://'.$bunny_cdn_hostname.'/';
    }

    return $domains;
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

  /**
   * Get https://vz-7cc93c10-24a.b-cdn.net/bcdn_token=gxdfx3N0c7SuW-J4jt_rSQ&expires=1727091897&token_path=%2Fdd1cc6c8-20fa-4af8-b999-62abb3ca1656/dd1cc6c8-20fa-4af8-b999-62abb3ca1656/playlist.m3u8
   * kid of URL for https://vz-7cc93c10-24a.b-cdn.net/dd1cc6c8-20fa-4af8-b999-62abb3ca1656/playlist.m3u8
   *
   * Details: https://docs.bunny.net/docs/cdn-token-authentication#url-path-based-tokens
   *
   * @param string $url
   * @param string $securityKey
   * @param int $ttl
   * @return string
   */
  function secure_link( $url, $securityKey, $ttl = false ) {

    /**
     * Remove the signature if the URL is already signed.
     * 
     * Before: https://vz-492bed2c-cae.b-cdn.net/bcdn_token=YjtNcuw2NobORsNT44E3gQ&expires=1730308644&token_path=%2Fe53d8dcf-5a1e-4785-b754-bba9bafc861c/e53d8dcf-5a1e-4785-b754-bba9bafc861c/thumbnail.jpg
     * After: https://vz-492bed2c-cae.b-cdn.net/e53d8dcf-5a1e-4785-b754-bba9bafc861c/thumbnail.jpg
     *
     * This happens when you pick a new video in FV Player Editor. Somehow we need it to set the unsigned path for the Splash field
     */
    $url = preg_replace( '~/bcdn_token=[a-z0-9-_]+&expires=\d+&token_path=[a-z0-9-%]+/~i', '/', $url );

    $parsed_url = wp_parse_url( $url );

    // Take dd1cc6c8-20fa-4af8-b999-62abb3ca1656 out of https://vz-7cc93c10-24a.b-cdn.net/dd1cc6c8-20fa-4af8-b999-62abb3ca1656/playlist.m3u8
    $path = dirname( $parsed_url['path'] );

    $expires = time() + ( $ttl ? $ttl : apply_filters('fv_player_secure_link_timeout', 900) );
    $hashableBase = $securityKey . urldecode( $path ) . $expires;

    $token = md5($hashableBase, true);
    $token = base64_encode($token);
    $token = strtr($token, '+/', '-_');
    $token = str_replace('=', '', $token);

    $url = add_query_arg( 'token', $token, $url);
    $url = add_query_arg( 'expires', $expires, $url);
    $url = add_query_arg( 'token_path', $path, $url);

    $url = $parsed_url['scheme'] . '://' . $parsed_url['host'] . '/bcdn_token=' . $token . '&expires=' . $expires . '&token_path=' . urlencode( $path ) . $parsed_url['path'];
    return $url;
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

  function set_file_type( $type ) {
    $bunny_security_token = FV_Player_Pro()->_get_option( array('bunny_stream', 'security_token') );

    $args = func_get_args();
    if ( isset($args[1]) && ! empty( $bunny_security_token ) ) {
      $bunny_cdn_hostname = FV_Player_Pro()->_get_option( array('bunny_stream', 'cdn_hostname') );

      if( stripos( $args[1], '//' . $bunny_cdn_hostname . '/' ) !== false ) {
        $type = "video/mp4";

        // FV Player needs to know to load HLS.js
        global $fv_fp;
        $fv_fp->load_hlsjs = true;
      }
    }

    return $type;
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
      if( !empty($parsed_src) && ! empty( $parsed_src['host'] ) && strcmp($parsed_src['host'], $bunny_cdn_hostname) === 0 ) {
        return true;
      }
    }

    return false;
  }

}

global $FV_Player_Pro_Bunny_Stream;
$FV_Player_Pro_Bunny_Stream = new FV_Player_Pro_Bunny_Stream;

endif;
