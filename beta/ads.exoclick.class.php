<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

if( !class_exists('FV_Player_Pro_Exoclick') ) :

class FV_Player_Pro_Exoclick {

  function __construct() {
    add_action( 'admin_init', array( $this, 'admin__add_meta_boxes' ) );
    add_action( 'wp_footer', array( $this, 'scripts' ) );
  }

  public function admin__add_meta_boxes(){
    add_meta_box( 'fv_player_pro_ads_exoclick', __('Exoclick Ads (Pro)', 'fv-player-pro'), array( $this, 'fv_player_admin_menu' ), 'fv_flowplayer_settings_actions', 'normal', 'low' );
  }


  public function fv_player_admin_menu(){
    global $fv_fp;
    ?>
    <table class="form-table2" style="margin: 5px; ">
      <tr>
        <td><label for="pro[ads_exoclick_zone]"><?php _e('Exoclick zone ID (required)', 'fv-player-pro'); ?>:</label></td>
        <td>
          <input type="text" size="40" name="pro[ads_exoclick_zone]" id="pro[ads_exoclick_zone]" value="<?php if( isset($fv_fp->conf['pro']['ads_exoclick_zone']) && strlen(trim($fv_fp->conf['pro']['ads_exoclick_zone'])) ) echo trim($fv_fp->conf['pro']['ads_exoclick_zone']); ?>" />
        </td>
      </tr>
      <tr>
        <td><label for="pro[ads_exoclick_login]"><?php _e('Exoclick login', 'fv-player-pro'); ?>:</label></td>
        <td>
          <input type="text" size="40" name="pro[ads_exoclick_login]" id="pro[ads_exoclick_login]" value="<?php if( isset($fv_fp->conf['pro']['ads_exoclick_login']) && strlen(trim($fv_fp->conf['pro']['ads_exoclick_login'])) ) echo trim($fv_fp->conf['pro']['ads_exoclick_login']); ?>" />
        </td>
      </tr>
      <tr>
        <td><label for="pro[ads_exoclick_cat]"><?php _e('Exoclick category ID', 'fv-player-pro'); ?>:</label></td>
        <td>
          <input type="text" size="40" name="pro[ads_exoclick_cat]" id="pro[ads_exoclick_cat]" value="<?php if( isset($fv_fp->conf['pro']['ads_exoclick_cat']) && strlen(trim($fv_fp->conf['pro']['ads_exoclick_cat'])) ) echo trim($fv_fp->conf['pro']['ads_exoclick_cat']); ?>" />
        </td>
      </tr>
      <tr>
        <td><label for="pro[ads_exoclick_site]"><?php _e('Exoclick site ID', 'fv-player-pro'); ?>:</label></td>
        <td>
          <input type="text" size="40" name="pro[ads_exoclick_site]" id="pro[ads_exoclick_site]" value="<?php if( isset($fv_fp->conf['pro']['ads_exoclick_site']) && strlen(trim($fv_fp->conf['pro']['ads_exoclick_site'])) ) echo trim($fv_fp->conf['pro']['ads_exoclick_site']); ?>" />
        </td>
      </tr>
      <tr>
        <td colspan="4">
          <a class="fv-wordpress-flowplayer-save button button-primary" href="#" style="margin-top: 2ex;"><?php _e('Save', 'fv-wordpress-flowplayer'); ?></a>
        </td>
      </tr>
    </table>
    <?php
  }


  public function scripts() {
    global $fv_fp, $post;
    if(empty( $fv_fp->conf['pro']['ads_exoclick_zone']))
    return;

    $aOptions = array(
      'idzone_300x250'  => $fv_fp->conf['pro']['ads_exoclick_zone' ],
      'idzone_468x60'   => $fv_fp->conf['pro']['ads_exoclick_zone' ],
      'preroll'    => (object)array(),
      'pause'      => (object)array(),
      'postroll'   => (object)array(),
      'show_thumb' => 1,
    );

    if( !empty($fv_fp->conf['pro']['ads_exoclick_cat']) ) $aOptions['cat'] = $fv_fp->conf['pro']['ads_exoclick_cat'];
    if( !empty($fv_fp->conf['pro']['ads_exoclick_login']) ) $aOptions['login'] = $fv_fp->conf['pro']['ads_exoclick_login'];
    if( !empty($fv_fp->conf['pro']['ads_exoclick_site']) ) $aOptions['idsite'] = $fv_fp->conf['pro']['ads_exoclick_site'];

    wp_localize_script( 'fv_player_pro', 'exoOpts', $aOptions );
    wp_register_script('fvplayer-pro-exoclick-inlinevideo', 'https://ads.exdynsrv.com/invideo.js',array() );
    wp_enqueue_script('fvplayer-pro-exoclick-inlinevideo');
  }

}

global $FV_Player_Pro_Exoclick;
$FV_Player_Pro_Exoclick = new FV_Player_Pro_Exoclick;

endif;
