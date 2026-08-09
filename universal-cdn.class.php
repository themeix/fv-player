<?php

if( !class_exists('FV_Player_Pro_UniversalCDN') ) :

class FV_Player_Pro_UniversalCDN extends FV_Player_Pro_Ajax_Loader {
      
  function __construct() {
    parent::__construct( array( 'key' => 'universal_cdn', 'title' => 'Universal CDN', 'help_link' => 'https://foliovision.com/2021/08/universal-cdn-support') );
  }
  
  function args($args) {
    $args[] = 'token';
    return $args;
  }
  
  function secure_link( $url, $securityKey, $ttl = false ) {
    $parsed = parse_url( $url );
    if( !$ttl ) $ttl = apply_filters('fv_player_secure_link_timeout', 900);

    // https://help.ucdn.com/limit-access-using-a-secret-key/
    $cdn_creation_time = time();

    $hash = md5( $parsed['path'] . $securityKey . $cdn_creation_time . $ttl );
    
    $url = add_query_arg( 'cdn_hash', $hash, $url);
    $url = add_query_arg( 'cdn_creation_time', $cdn_creation_time, $url);
    $url = add_query_arg( 'cdn_ttl', $ttl, $url);

    return $url;
  }

}
global $FV_Player_Pro_UniversalCDN;
$FV_Player_Pro_UniversalCDN = new FV_Player_Pro_UniversalCDN;

endif;
