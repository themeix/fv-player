<?php

if( !class_exists('FV_Player_Pro_Gumlet') ) :

class FV_Player_Pro_Gumlet extends FV_Player_Pro_Ajax_Loader {

  function __construct() {
    add_filter( 'fv_flowplayer_stream_loader_ignore_domains', array( $this, 'ignore_domains' ) );

    parent::__construct( array( 'key' => 'gumlet', 'title' => 'Gumlet') );
  }

  function args($args) {
    $args[] = 'token_path';
    return $args;
  }

  /**
   * Add Gumlet domain to ignore domains to prevent stream loader
   *
   * @param array $domains
   *
   * @return array
   */
  public function ignore_domains( $domains ) {
    $domains[] = 'https://video.gumlet.io/';
    return $domains;
  }

  public function load_options() {
    $this->aDomains      = array( 'https://video.gumlet.io');

    parent::load_options();
  }

  /**
   * @param string $url
   * @param string $securityKey
   * @param int $ttl
   * @return string
   */
  function secure_link( $url, $securityKey, $ttl = false ) {

    $path = wp_parse_url( $url, PHP_URL_PATH );

    $expires = time() + ( $ttl ? $ttl : apply_filters('fv_player_secure_link_timeout', 900) );

    $signature = hash_hmac( 'sha1', $path . $expires, base64_decode( $securityKey ) );

    return add_query_arg( array(
      'token'   => $signature,
      'expires' => $expires
    ), $url );
  }

}

global $FV_Player_Pro_Gumlet;
$FV_Player_Pro_Gumlet = new FV_Player_Pro_Gumlet;

endif;
