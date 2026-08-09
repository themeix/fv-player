<?php

if( !class_exists('FV_Player_Pro_BunnyCDN') ) :

class FV_Player_Pro_BunnyCDN extends FV_Player_Pro_Ajax_Loader {

  function __construct() {
    parent::__construct( array( 'key' => 'bunnycdn', 'title' => 'BunnyCDN', 'help_link' => 'https://foliovision.com/player/video-security/cdn/using-bunnycdn-with-fvplayer-pro') );
    
    //you can also set these if you don't need settings screen:
    //$this->aDomains = array( 'domain1.your-cdn.com', 'domain2.your-cdn.com', ..., 'domainN.your-cdn.com');
    //$this->aSecureTokens = array( 'key-for-domain-1', 'key-for-domains-2', 'key-for-domain-N' );
  }

  function args($args) {
    $args[] = 'token';
    return $args;
  }

  function secure_link( $url, $securityKey, $ttl = false ) {

    $url_path = urldecode( wp_parse_url( $url, PHP_URL_PATH ) );
    $expires = time() + ( $ttl ? $ttl : apply_filters('fv_player_secure_link_timeout', 900) );
    $hashableBase = $securityKey . $url_path . $expires;

    // Encode components of the URL path
    $url = str_replace( $url_path, implode( '/', array_map( 'urlencode', explode( '/', $url_path ) ) ), $url );

    // If using IP validation
    // $hashableBase .= "146.14.19.7";    
    $token = md5($hashableBase, true);
    $token = base64_encode($token);
    $token = strtr($token, '+/', '-_');
    $token = str_replace('=', '', $token);  
    
    $url = add_query_arg( 'token', $token, $url);
    $url = add_query_arg( 'expires', $expires, $url);

    return $url;
  }

}
global $FV_Player_Pro_BunnyCDN;
$FV_Player_Pro_BunnyCDN = new FV_Player_Pro_BunnyCDN;

endif;
