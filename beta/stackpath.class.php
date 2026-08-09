<?php

if( !class_exists('FV_Player_Pro_StackPath') ) :

class FV_Player_Pro_StackPath extends FV_Player_Pro_Ajax_Loader {
  
  var $aDomains;
  
  var $aSecureTokens;
      
  function __construct() {    
    parent::__construct( array( 'key' => 'stackpath', 'title' => 'StackPath') );
  }
  
  function args($args) {
    $args[] = 'st';
    return $args;
  }
  
  function secure_link( $url, $secret, $ttl = false ) {
    $path = preg_replace( '~.*?//.*?/~', '/', $url );
    $expires = time() + ( $ttl ? $ttl : apply_filters('fv_player_secure_link_timeout', 900) );
    $md5 = base64_encode(md5($secret . $path . $expires, true));
    $md5 = strtr($md5, '+/', '-_');
    $md5 = str_replace('=', '', $md5);
    $url = str_replace( $path, $path."?st=".$md5."&e=".$expires, $url );
    return $url;    
  }

}
global $FV_Player_Pro_StackPath;
$FV_Player_Pro_StackPath = new FV_Player_Pro_StackPath;

endif;
