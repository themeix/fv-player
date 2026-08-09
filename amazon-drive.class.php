<?php

if( !class_exists('FV_Player_Pro_Amazon_Drive') ) :

class FV_Player_Pro_Amazon_Drive {
  
  var $aDomains;
  
  var $aSecureTokens;
      
  function __construct() {    
    add_action( 'admin_init', array( $this, 'register_meta_boxes' ), 20 );
    
    add_filter( 'fv_player_pro_video_ajaxify_domains', array( $this, 'domains'), 999, 2 );      
    add_action( 'plugins_loaded', array( $this, 'ajax' ), 9 );      
    add_filter( 'fv_flowplayer_video_src', array( $this, 'get_backend_link'), 10, 2 );
    add_filter( 'fv_flowplayer_get_mime_type', array( $this, 'set_file_type'), 10, 2 );
  }

  
  function ajax() {
    if( isset($_POST['action']) && $_POST['action'] == 'fv_fp_get_video_url' ) {
      $bFound = false;
      foreach( $_POST['sources'] AS $key => $aVideo ) {
        if( !isset($aVideo['src']) || !isset($aVideo['type']) ) continue;
        
        if( $this->is_amazon_drive($aVideo['src']) ) {
          $res = $this->secure_link($aVideo['src']);
          if( $res ) {
            $bFound = true;
            $aVideo['src'] = $res;
            $_POST['sources'][$key] = $aVideo;
          }
        }          
      }
      
    }
    
  }
  
  
  function domains( $aDomains ) {
    $aDomains[] = 'amazon.ca/clouddrive/share/';
    $aDomains[] = 'amazon.ca/photos/share/';

    $aDomains[] = 'amazon.cn/clouddrive/share/';
    $aDomains[] = 'amazon.cn/photos/share/';

    $aDomains[] = 'amazon.com/clouddrive/share/';
    $aDomains[] = 'amazon.com/photos/share/';

    $aDomains[] = 'amazon.com.au/clouddrive/share/';
    $aDomains[] = 'amazon.com.au/photos/share/';

    $aDomains[] = 'amazon.com.br/clouddrive/share/';
    $aDomains[] = 'amazon.com.br/photos/share/';

    $aDomains[] = 'amazon.co.jp/clouddrive/share/';
    $aDomains[] = 'amazon.co.jp/photos/share/';

    $aDomains[] = 'amazon.co.uk/clouddrive/share/';
    $aDomains[] = 'amazon.co.uk/photos/share/';

    $aDomains[] = 'amazon.de/clouddrive/share/';
    $aDomains[] = 'amazon.de/photos/share/';

    $aDomains[] = 'amazon.es/clouddrive/share/';
    $aDomains[] = 'amazon.es/photos/share/';    

    $aDomains[] = 'amazon.fr/clouddrive/share/';
    $aDomains[] = 'amazon.fr/photos/share/';

    $aDomains[] = 'amazon.it/clouddrive/share/';
    $aDomains[] = 'amazon.it/photos/share/';

    return $aDomains;
  }
  
  
  function get_backend_link( $url, $args ) {
    if( is_array($args) && isset($args['dynamic']) && $args['dynamic'] ) {
      $bFound = false;

      if( $this->is_amazon_drive($url) ) {
        $res = $this->secure_link($url);
        if( $res ) {
          $url = $res;
        }
      }
    }
    
    return $url;
  }
  
  
  function is_amazon_drive( $url ) {
    // Must work also with:
    // https://www.amazon.fr/clouddrive/share/xdshwx6MPx7JM5E6b8UwoeBSx15OzYo5rFW1zxqBnYr?_encoding=UTF8&*Version*=1&*entries*=0&mgh=1
    if( preg_match( '~//(?:www\.)?amazon\.([a-z]{2,5}).*?(?:photos|clouddrive).*?share/([A-Za-z0-9]+)~', $url, $link_info ) ) {
      return $link_info;
    }
    
    return false;
  }
  
  
  function options() {
    global $fv_fp;
    ?>
    <p>Just use your Amazon Drive video links as video source like <code>https://www.amazon.com/clouddrive/share/{code}</code> and FV Player Pro takes care of the rest.</p>
    <?php
    
    global $wpdb;
    $count = $wpdb->get_var( "SELECT count(*) FROM $wpdb->options WHERE option_name LIKE 'fv_player_pro_amazon_drive-%' ");
    if( $count ) : ?>
      <p>Currently there are <?php echo $count; ?> video<?php if( $count > 1 ) echo 's'; ?> in cache.</p>
    <?php endif;
  }
  
  
  function register_meta_boxes() {
    add_meta_box( 'fv_player_pro_amazon_drive', __('Amazon Drive', 'fv-player-pro'), array( $this, 'options' ), 'fv_flowplayer_settings_hosting', 'normal', 'low' );
  }
  
  
  function secure_link( $url ) {
    if( $link_info = $this->is_amazon_drive($url) ) {
      $tld = $link_info[1];
      $id = $link_info[2];
      
      global $FV_Player_Pro;
      $objVideo = get_option( 'fv_player_pro_amazon_drive-'.$id );
      if( $objVideo && isset($objVideo->time) && (intval($objVideo->time) + $objVideo->ttl) > time() ) {
        return $objVideo->url;
      }      
      
      $objVideo = new stdClass;
      $objVideo->url = false;
      $objVideo->time = time();
      $objVideo->ttl = 60;
      
      $response = wp_remote_get("https://www.amazon.".$tld."/drive/v1/shares/{$id}?resourceVersion=V2&ContentType=JSON&asset=ALL");
      if( !is_wp_error($response) ) {
        $obj = json_decode($response['body']);
        if( $obj && isset($obj->nodeInfo) && !empty($obj->nodeInfo->id) ) {
          $response = wp_remote_get("https://www.amazon.".$tld."/drive/v1/nodes/{$obj->nodeInfo->id}/children?resourceVersion=V2&ContentType=JSON&tempLink=true&shareId={$id}");
          if( !is_wp_error($response) ) {
            $obj2 = json_decode($response['body']);
            
            if( $obj2 && isset($obj2->data) && isset($obj2->data[0]) && !empty($obj2->data[0]->tempLink) ) {
              $objVideo->time = time();
              $objVideo->ttl = 120;
              $objVideo->url = $obj2->data[0]->tempLink;
            }
          }
        }
      }
      
      update_option( 'fv_player_pro_amazon_drive-'.$id, $objVideo, false );
      
      return $objVideo->url;      
    }
    
    return false;
  }
  
  
  function set_file_type( $type ) {
    $args = func_get_args();
    if( isset($args[1]) && $this->is_amazon_drive($args[1]) ) {
      $type = 'video/mp4';
    }
    return $type;
  }

}

global $FV_Player_Pro_Amazon_Drive;
$FV_Player_Pro_Amazon_Drive = new FV_Player_Pro_Amazon_Drive;

endif;
