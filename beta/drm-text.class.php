<?php

if( !class_exists('FV_Player_Pro_DRM_Text') ) :

class FV_Player_Pro_DRM_Text {
  function __construct() {
    add_filter( 'fv_flowplayer_attributes', array( $this, 'copy_text_attributes' ), 10, 3 );
    add_filter( 'fv_player_pro_conf', array( $this, 'global_copy_text_details' ) );
  }

  public function default_values( $conf ) {
    if( !isset($conf['pro']['copy_text_ip']) ) $conf['pro']['copy_text_ip'] = true;
    if( !isset($conf['pro']['copy_text_email']) ) $conf['pro']['copy_text_email'] = true;
    if( !isset($conf['pro']['copy_text_name']) ) $conf['pro']['copy_text_name'] = true;
    if( !isset($conf['pro']['copy_text_site']) ) $conf['pro']['copy_text_site'] = true;
    if( !isset($conf['pro']['copy_text_date']) ) $conf['pro']['copy_text_date'] = true;
    if( !isset($conf['pro']['copy_text_time']) ) $conf['pro']['copy_text_time'] = '5';
    if( !isset($conf['pro']['copy_text_opacity']) ) $conf['pro']['copy_text_opacity'] = 0.3;

    if( $conf['pro']['copy_text_time'] == 20 ) $conf['pro']['copy_text_time'] = 15;

    // prevent random if drm plugin is enabled
    if( function_exists('FV_Player_DRM') && $conf['pro']['copy_text_time'] === 'random' ) $conf['pro']['copy_text_time'] = '5';

    return $conf;
  }

  function fv_player_admin_pro_drm_text() {
    ?>
    <table class="form-table2" style="margin: 5px; ">
      <tr>
        <td colspan="2">
          <p class="description">
            <?php _e('Show user ID, IP and date over the video for short periods to allow you to track screen capture of your videos.', 'fv-player-pro'); ?>
          </p>
        </td>
      </tr>
      <?php FV_Player_Pro()->_get_checkbox(__('Global Enable', 'fv-player-pro'), array('pro', 'copy_text'), __('To enable for individual video, enable the option in <a href="#fv_flowplayer_interface_options">Post Interface Options</a> and then use shortcode editor.', 'fv-player-pro') ); ?>
      <?php FV_Player_Pro()->_get_checkbox(__('Show IP', 'fv-player-pro'), array('pro', 'copy_text_ip'), __('User IP will be visible.', 'fv-player-pro') ); ?>
      <?php FV_Player_Pro()->_get_checkbox(__('Show Email', 'fv-player-pro'), array('pro', 'copy_text_email'), __('User email will be visible.', 'fv-player-pro') ); ?>
      <?php FV_Player_Pro()->_get_checkbox(__('Show Name', 'fv-player-pro'), array('pro', 'copy_text_name'), __('User name will be visible.', 'fv-player-pro') ); ?>
      <?php FV_Player_Pro()->_get_checkbox(__('Show Date', 'fv-player-pro'), array('pro', 'copy_text_date'), __('Current date will be visible, example: ' . $this->get_drm_date() , 'fv-player-pro') ); ?>
      <?php FV_Player_Pro()->_get_checkbox(__('Show Site', 'fv-player-pro'), array('pro', 'copy_text_site'), __('Site name will be visible, site name: '. $this->get_drm_sitename() , 'fv-player-pro') ); ?>
      <tr>
        <td><label for="display_preset"><?php _e('Preset', 'fv-player-pro'); ?>:</label></td>
        <td colspan="3">
          <p class="description">
            <?php $value = FV_Player_Pro()->_get_option( array('pro','display_preset') ); ?>
            <select id="display_preset" name="pro[display_preset]">
              <option value="flash"  <?php if( $value == 'flash' ) echo ' selected="selected"'; ?> ><?php _e('Flash - Appears just for a single frame', 'fv-player-pro'); ?></option>
              <option value="watermark"   <?php if( $value == 'watermark' ) echo ' selected="selected"'; ?> ><?php _e('Watermark - Visible at all times', 'fv-player-pro'); ?></option>
            </select>
          </p>
        </td>
      </tr>
      <tr>
        <td><label for="copy_text_time"><?php _e('Interval', 'fv-player-pro'); ?>:</label></td>
        <td colspan="3">
          <p class="description">
            <?php
              $value = FV_Player_Pro()->_get_option( array('pro','copy_text_time') );
              $drm_plugin = function_exists('FV_Player_DRM');
            ?>
            <select id="copy_text_time" name="pro[copy_text_time]">
              <option value="5" <?php if( $value == 5 ) echo ' selected="selected"'; ?> ><?php _e('5 seconds', 'fv-player-pro'); ?></option>
              <option value="15" <?php if( $value == 15 ) echo ' selected="selected"'; ?> ><?php _e('15 seconds', 'fv-player-pro'); ?></option>
              <option value="random" <?php if( $drm_plugin ) echo ' disabled="disabled"'; if( $value == 'random' ) echo ' selected="selected"'; ?> ><?php _e('0 to 10 seconds random', 'fv-player-pro'); ?></option>
            </select>
          </p>
        </td>
      </tr>
      <tr>
        <td><label for="copy_text_opacity"><?php _e('Opacity', 'fv-player-pro'); ?>:</label></td>
        <td colspan="3">
          <?php $value = FV_Player_Pro()->_get_option( array('pro','copy_text_opacity') ); ?>
          <input type="range" name="pro[copy_text_opacity]" min="0.2" max="1.0" step="0.1" value="<?php echo $value; ?>" id="copy_text_opacity"><span class="more" style="display: none;"><?php _e('The more opacity, the more the text will be visible', 'fv-player-pro'); ?></span> <a href="#" class="show-more">(&hellip;)</a>
        </td>
      </tr>
      <tr>
        <td colspan="4">
          <a class="fv-wordpress-flowplayer-save button button-primary" href="#"><?php _e('Save', 'fv-wordpress-flowplayer'); ?></a>
          <a class="button fv-help-link" href="https://foliovision.com/player/video-security/drm-watermarking/protecting-videos-with-drm-text" target="_blank">Help</a>
        </td>
      </tr>
    </table>
    <?php
  }

  public function copy_text_attributes($aAttributes, $media, $fv_fp) {
    // Not for audio player
    if( !empty($aAttributes['class']) && stripos($aAttributes['class'],' is-audio') !== false ) {
      return $aAttributes;
    }

    $args = $fv_fp->aCurArgs;
    if (
      // if global setting is enabled and the player either doesn't have it set or it's true or on
      FV_Player_Pro()->_get_option( array('pro','copy_text') ) && (!isset($args['copy_text']) || $args['copy_text'] === 'true' || $args['copy_text'] === 'on' ) ||
      // or if it's enabled for the player
      isset($args['copy_text']) && ( $args['copy_text'] === 'true' || $args['copy_text'] === 'on' )
    ) {
      add_filter( 'fv_player_pro_conf', array( $this, 'copy_text_details' ) );

      // This is what really enables the feature for any given player
      $aAttributes['data-ut'] = 'fullhd';
    }
    return $aAttributes;
  }

  public function copy_text_details($aOptions) {
    $user_data = wp_get_current_user();
    $drm_text = array(
      'id' => isset($user_data->ID) ? $user_data->ID : 'none',
      'preset' => FV_Player_Pro()->_get_option( array('pro','display_preset') ) ? FV_Player_Pro()->_get_option( array('pro','display_preset') ) : 'flash',
      'opacity' => FV_Player_Pro()->_get_option( array('pro','copy_text_opacity') )
    );

    if( FV_Player_Pro()->_get_option( array('pro','copy_text_ip') ) ) {
      $drm_text['IP'] = FV_Player_Pro()->get_client_ip();
    }

    if( FV_Player_Pro()->_get_option( array('pro','copy_text_email') ) ) {
      $drm_text['email'] = isset($user_data->user_email) ? $user_data->user_email : 'none';
    }

    if( FV_Player_Pro()->_get_option( array('pro','copy_text_name') ) ) {
      $drm_text['name'] = isset($user_data->display_name) ? $user_data->display_name : 'none';
    }

    if( FV_Player_Pro()->_get_option( array('pro','copy_text_date') ) ) {
      $drm_text['date'] = $this->get_drm_date();
    }

    if( FV_Player_Pro()->_get_option( array('pro','copy_text_site') ) ) {
      $drm_text['site'] =  $this->get_drm_sitename();
    }

    if( FV_Player_Pro()->_get_option( array('pro','copy_text_time') ) && FV_Player_Pro()->_get_option( array('pro','copy_text_time') ) != 5 ) {
      $drm_text['copy_text_time'] = FV_Player_Pro()->_get_option( array('pro','copy_text_time') );
    }

    if( function_exists('FV_Player_DRM') && FV_Player_Pro()->_get_option( array('pro','improve_drm_text') ) ) {
      $drm_text['drm_plugin'] = true;
    }

    $drm_text['hide_pip_style'] = 'display: block !important; top: 0 !important; bottom: 0 !important; right: -4950% !important; max-width: 10000% !important; width: 10000% !important; left: -4950% !important; height: 100% !important; position: absolute !important; transform: none !important; translate: none !important; margin: 0 !important; z-index: unset !important;';

    $drm_text['default_style'] = 'display: block !important; width: 100% !important; position: absolute !important; top: 0 !important; bottom: 0 !important; left: 0 !important; right: 0% !important; height: 100% !important; transform: none !important; translate: none !important; margin: 0 !important; z-index: unset !important;';

    $drm_text['element'] = '<div class="{r_id}" style="pointer-events:none; opacity: 1 !important; color:rgba(128, 128, 128, {r_op}) !important; font-size: {r_fo}px !important; line-height: {r_lh}px !important; font-family: sans-serif !important; text-align: left !important; position: absolute !important; display: block !important; width: auto !important; border: none !important; visibility: visible !important; z-index: 2147483647 !important; max-width: 100% !important; height: auto !important; transform: none !important; filter: none !important; -webkit-filter: none !important; margin: 0 !important; -webkit-text-fill-color: initial !important; text-fill-color: initial !important; clip: unset !important; clip-path: none !important; -webkit-clip-path: none !important; content: normal !important; content-visibility: visible !important; inset: auto !important; mix-blend-mode: normal !important; scale: none !important; translate: none !important; {r_po}">{r_te}</div>';

    if( current_user_can('manage_options') && isset($_GET['drm_text_red']) ) {
      $drm_text['element'] = preg_replace( '~rgba\(.*?\)~', 'rgba(255,0,0,1)', $drm_text['element'] );
    }

    $aOptions['utrack'] = base64_encode(
      json_encode(
        apply_filters( 'fv_player_pro_drm_text', $drm_text )
      )
    );

    return $aOptions;
  }

  /*
   * Prepare the data as it might be used for some player in Ajax
   */
  public function global_copy_text_details( $aOptions ) {

    if( FV_Player_Pro()->_get_option( array('pro','copy_text') ) || FV_Player_Pro()->should_force_load_js() ) {
      $aOptions = $this->copy_text_details($aOptions);
    }

    return $aOptions;
  }

  public function get_drm_date() {
    return date( get_option('date_format') );
  }

  public function get_drm_sitename() {
    return preg_replace( '~.*?//~', '', home_url() );
  }

}

global $FV_Player_Pro_DRM_Text;
$FV_Player_Pro_DRM_Text = new FV_Player_Pro_DRM_Text;

endif;
