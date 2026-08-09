<?php

if( !class_exists('FV_Player_Pro_Cloudflare') ) :

class FV_Player_Pro_Cloudflare extends FV_Player_Pro_Ajax_Loader {

  function __construct() {
    parent::__construct( array( 'key' => 'cloudflare', 'title' => 'Cloudflare') );
  }

  function args($args) {
    $args[] = 'verify';
    return $args;
  }

  function register_meta_boxes() {
    if ( FV_Player_Pro()->_get_option( array( 'pro', $this->key.'_domain' ) ) ) {
      add_meta_box( 'fv_player_pro_'.$this->key, $this->title, array( $this, 'options' ), 'fv_flowplayer_settings_hosting', 'normal', 'low' );
    }
  }

  function secure_link( $url, $securityKey, $ttl = false ) {
    $path = preg_replace( '~.*?//.*?/~', '/', $url );

    // https://blog.cloudflare.com/token-authentication-for-cached-private-content-and-apis/
    $secret = $securityKey;
    $time   = time();
    $token  = $time . "-" . urlencode(base64_encode(hash_hmac("sha256", $path.$time, $secret, true)));

    $url = str_replace( $path, $path."?verify=".$token, $url );
    return $url;
  }

}
global $FV_Player_Pro_Cloudflare;
$FV_Player_Pro_Cloudflare = new FV_Player_Pro_Cloudflare;

endif;
