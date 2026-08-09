<?php

if( !class_exists('FV_Player_Pro_S3') ) :

class FV_Player_Pro_S3 {

  function __construct() {
    add_filter( 'fv_flowplayer_video_src', array( $this, 's3_strip'), 999, 2 );
    add_filter( 'fv_player_pro_video_ajaxify_domains', array( $this, 's3_domains'), 999, 2 );
    add_filter( 'fv_player_pro_video_ajaxify_args', array( $this, 'args'), 999, 2 );
    add_action( 'plugins_loaded', array( $this, 's3_ajax' ), 9 );

    add_action( 'fv_player_admin_amazon_options', array( $this, 's3_option' ) );
  }

  function args($args) {
    $args[] = 'X-Amz-Signature';
    $args[] = 'AWSAccessKeyId';
    return $args;
  }

  function s3_ajax() {
    global $fv_fp;
    if( isset($_POST['action']) && $_POST['action'] == 'fv_fp_get_video_url' && isset($_POST['sources']) && isset($fv_fp->conf['amazon_bucket']) && is_array($fv_fp->conf['amazon_bucket']) && count($fv_fp->conf['amazon_bucket']) > 0 ) {
      $bFound = false;

      foreach( $fv_fp->conf['amazon_bucket'] AS $bucket ) {
        foreach( $_POST['sources'] AS $key => $aVideo ) {
          if( !isset($aVideo['src']) || !isset($aVideo['type']) ) continue;

          add_filter( 'fv_flowplayer_amazon_expires', array( $this, 's3_timeout' ), 999999 );

          if( isset($_REQUEST['fvpexpirelow']) ) {
            add_filter( 'fv_flowplayer_amazon_expires', array( $this, 'test_timeout' ), PHP_INT_MAX );
          }

          if( stripos($aVideo['src'],'amazonaws.com/'.$bucket.'/') !== false || stripos($aVideo['src'],'//'.$bucket.'.s3') !== false ) {
            $bFound = true;
            $aVideo['src'] = $fv_fp->get_amazon_secure($aVideo['src'], array( 'url_only' => true, 'flash' => false ) );
            $_POST['sources'][$key] = $aVideo;
          }
        }
      }

    }

  }


  function s3_domains( $aDomains ) {
    global $fv_fp;
    if( isset($fv_fp->conf['pro']['amazon_s3']) && $fv_fp->conf['pro']['amazon_s3'] == 'true' ) {
      if( isset($fv_fp->conf['amazon_bucket']) && is_array($fv_fp->conf['amazon_bucket']) && count($fv_fp->conf['amazon_bucket']) > 0 ) {
        foreach( $fv_fp->conf['amazon_bucket'] AS $bucket ) {
          $aDomains[] = 'amazonaws.com/'.$bucket.'/';
          $aDomains[] = '//'.$bucket.'.s3';
        }
      }
    }
    return $aDomains;
  }


  function s3_option( $aDomains ) {
    global $fv_fp;
    ?>
      <tr>
        <td style="width: 250px"><label for="pro[amazon_s3]"><?php _e('Amazon S3 Ajax (Pro)', 'fv-player-pro'); ?>:</label></td>
        <td>
          <p class="description">
            <input type="hidden" value="false" name="pro[amazon_s3]" />
            <input type="checkbox" value="true" name="pro[amazon_s3]" id="pro[amazon_s3]" <?php if( isset($fv_fp->conf['pro']['amazon_s3']) && $fv_fp->conf['pro']['amazon_s3'] == 'true' ) echo 'checked="checked"'; ?> />
            <?php _e('Check this to improve security of your Amazon S3 videos configured in Hosting -> Amazon S3 Protected Content.', 'fv-player-pro'); ?>
          </p>
        </td>
      </tr>
    <?php
  }


  function s3_strip( $media, $args ) {
    global $fv_fp;
    if( isset($fv_fp->conf['pro']['amazon_s3']) && $fv_fp->conf['pro']['amazon_s3'] == 'true' ) {
      if( !is_array($args) || !isset($args['dynamic']) || !$args['dynamic'] ) { //  somehow the $args can be an instance of flowplayer_frontend for RTMP videos?
        $media = preg_replace( '~\?AWSAccessKeyId=.+~', '', $media );  //  AWS Signature Version 2
        $media = preg_replace( '~\?X-Amz-Algorithm=.+~', '', $media );  //  AWS Signature Version 4
      }
    }
    return $media;
  }


  function s3_timeout( $time ) {
    return apply_filters('fv_player_secure_link_s3_timeout', 900);
  }

  function test_timeout( $time ) {
    return 5;
  }

}
global $FV_Player_Pro_S3;
$FV_Player_Pro_S3 = new FV_Player_Pro_S3;

endif;
