<?php

if( !class_exists('FV_Player_Pro_KeyCDN') ) :

class FV_Player_Pro_KeyCDN extends FV_Player_Pro_Ajax_Loader {
  
  function __construct() {
    parent::__construct( array( 'key' => 'keycdn', 'title' => 'KeyCDN', 'help_link' => 'https://foliovision.com/player/video-security/cdn/using-keycdn-with-fvplayer') );
  }
  
  function args($args) {
    $args[] = 'token';
    return $args;
  }
  
  function secure_link( $url, $secret, $ttl = false ) {
    $path = preg_replace( '~.*?//.*?/~', '/', $url );
    $expires = time() + ( $ttl ? $ttl : apply_filters('fv_player_secure_link_timeout', 900) );
    $md5 = base64_encode(md5($path . $secret . $expires, true));
    $md5 = strtr($md5, '+/', '-_');
    $md5 = str_replace('=', '', $md5);
    $url = str_replace( $path, $path."?token=".$md5."&expire=".$expires, $url );
    return $url;    
  }

}
global $FV_Player_Pro_KeyCDN;
$FV_Player_Pro_KeyCDN = new FV_Player_Pro_KeyCDN;

endif;
