<?php
/*
Plugin Name: FV Player Pro
Plugin URI: http://foliovision.com/wordpress/plugins/fv-wordpress-flowplayer
Description: Provides Vimeo and YouTube integration, custom video ads, encrypted HLS, AB loop and more for FV Player.
Version: 8.0.18
Author: Foliovision
Author URI: http://foliovision.com/
*/

if( !class_exists('FV_Player_Pro_loader') ) :

define('FV_PLAYER_PRO_DIR', dirname(__FILE__) );
define('FV_PLAYER_PRO_FILE', FV_PLAYER_PRO_DIR.'/'.basename(__FILE__) );

include( dirname(__FILE__).'/fp-api-private.php' );

class FV_Player_Pro_loader extends FV_Player_Pro_Foliopress_Plugin_Private {

  var $strPluginSlug = 'fv-player-pro';

  var $version = '8.0.18';

	function __construct() {
    if( $this->get_release() == 'beta' ) {
      require_once( dirname(__FILE__).'/beta/fv-player-pro.class.php' );

      // read the version number from the actual plugin class
      global $FV_Player_Pro;
      $this->version = $FV_Player_Pro->version; // for FV updates
      add_filter( 'all_plugins', array( $this, 'admin__adjust_plugin_version') ); // for plugins screen
      add_action( 'wp_ajax_fv_foliopress_ajax_pointers', array( $this, 'fv_pro_pointers_ajax' ), 11 );

      add_action( 'admin_init', array( $this ,'admin_pointer_box' ), 11 );
    } else if( $this->get_release() == 'stable' ) {
      require_once( dirname(__FILE__).'/stable/fv-player-pro.class.php' );
    } else {
      require_once( dirname(__FILE__).'/fv-player-pro.class.php' );
    }

    $flowplayer_opt = get_option('fvwpflowplayer');
    $this->license_key = ( isset($flowplayer_opt['key']) && !empty($flowplayer_opt['key']) ) ? $flowplayer_opt['key'] : false;

    parent::__construct();
    parent::auto_updates();

    add_action( 'admin_init', array( $this, 'admin_init' ) );
    add_action( 'admin_footer', array( $this, 'admin_footer' ) );

    add_action( 'admin_notices', array( $this, 'admin__version_switcher_start'), 999999 );
    add_action( 'fv_player_settings_pre', array( $this, 'admin__version_switcher') );
    add_action( 'fv_flowplayer_admin_buttons_after', array( $this, 'admin__version_switcher_script') );

    add_filter( 'get_user_option_closedpostboxes_fv_flowplayer_settings', array( $this, 'close_settings_metaboxes'), 11 );
    add_filter( 'get_user_option_closedpostboxes_fv_flowplayer_settings_hosting', array( $this, 'close_hosting_metaboxes'), 11 );

    add_action( 'admin_notices', array( $this, 'fv_player_8_version_check'), 0 );

    add_action( 'admin_notices', array( $this, 'fv_player_8_auto_update' ), -1 );

    add_action( 'admin_menu', array( $this, 'fv_player_8_upgrade_start' ) );
  }



  function admin_footer() {
    if( isset($_POST['fv-player-pro-release']) && isset($_REQUEST['fv_player_pro_switch']) && wp_verify_nonce( $_REQUEST['fv_player_pro_switch'], 'fv_player_pro_switch') ) {
      update_option('fv-player-pro-release',$_POST['fv-player-pro-release']);

      global $fv_fp;
      $fv_fp->css_writeout();
    }
  }

  function admin_init() {
    // No license check if the user does not have the permissions or it's just WP Ajax
    if( !current_user_can('manage_options') || wp_doing_ajax() ) {
      return;
    }

    parent::setLicenseTransient();

    $aCheck = get_transient( $this->strPluginSlug . '_license' );
    if( !empty($aCheck->expired) ) {
      add_filter( 'site_transient_update_plugins', array( $this, 'fv_player_remove_update' ) );
      add_action( 'admin_notices', array( $this, 'admin_notice_license_error') );
      add_action( 'fv_player_settings_pre', array( $this, 'admin_notice_license_error') );

    } else {
      // Stop FV Player from showing the "To update FV Player please either renew your license or disable FV Player Pro." notice
      delete_option('fv_wordpress_flowplayer_expired_license_update_notice');
    }

    if( !empty($aCheck->error) ) {
      add_action( 'admin_notices', array( $this, 'admin_notice_license_error') );
      add_action( 'fv_player_settings_pre', array( $this, 'admin_notice_license_error') );
    }
  }

  function admin_pointer_box() {
    global $fv_fp;
    if( empty($fv_fp) || !method_exists($fv_fp, '_get_option') ) {
      return;
    }

    $option = $fv_fp->_get_option('notice_fv_player_pro_beta_message');

    if( !$option || ($option && version_compare( $option , $this->version ) !== 0 ) ) {
      $fv_fp->pointer_boxes['fv_player_pro_beta_message'] = array(
        'id' => '#wp-admin-bar-new-content',
        'pointerClass' => 'fv_player_pro_beta_message',
        'heading' => sprintf( __('FV Player Pro: You just upgraded to %s release with the following improvements:', 'fv-player-pro'), $this->version ),
        'content' => $this->get_beta_message(),
        'position' => array( 'edge' => 'top', 'align' => 'center' ),
        'button1' => __('Thanks for letting me know!', 'fv-player-pro')
      );
    }

  }

  function close_settings_metaboxes( $closed ) {
    global $fv_fp;

    $to_close = array( 'fv_player_pro_drm_text', 'fv_player_pro_watching_prompt', 'fv_player_pro_stream_loader' );

    if ( $fv_fp->_get_option( array('pro', 'copy_text') ) ) {
      $key = array_search('fv_player_pro_drm_text', $to_close);
      unset($to_close[$key]);
    }

    if ( $fv_fp->_get_option( array('pro', 'watching_prompt') ) ) {
      $key = array_search('fv_player_pro_watching_prompt', $to_close);
      unset($to_close[$key]);
    }

    if ( $fv_fp->_get_option( array('pro', 'stream_loader_on') ) ) {
      $key = array_search('fv_player_pro_stream_loader', $to_close);
      unset($to_close[$key]);
    }

    if( is_array($closed) ) {
      $closed = array_unique( array_merge( $closed, $to_close ) );
    } else if( false === $closed ) {
      $closed = $to_close;
    }

    return $closed;
  }

  function close_hosting_metaboxes( $closed ) {
    global $fv_fp;

    $to_close = array( 'fv_player_pro_vimeo', 'fv_player_pro_youtube' );

    if( FV_Player_Pro()->get__vimeo_key() ) {
      $key = array_search('fv_player_pro_vimeo', $to_close);
      unset($to_close[$key]);
    }

    if( $fv_fp->_get_option( array('pro','youtube_key'))  ) {
      $key = array_search('fv_player_pro_youtube', $to_close);
      unset($to_close[$key]);
    }

    if(is_array($closed)) {
      $closed = array_unique( array_merge( $closed, $to_close ) );
    } else if( false === $closed ) {
      $closed = $to_close;
    }

    return $closed;
  }

  function fv_pro_pointers_ajax() {
    if( isset($_POST['key']) && $_POST['key'] == 'fv_player_pro_beta_message' && isset($_POST['value'])) {
      check_ajax_referer('fv_player_pro_beta_message');
      $conf = get_option( 'fvwpflowplayer' );
      if( $conf ) {
        $conf['notice_fv_player_pro_beta_message'] = $this->version;
        update_option( 'fvwpflowplayer', $conf );
      }
      die();
    }
  }

  function admin_notice_license_error() {
    $screen = get_current_screen();
    if( $screen && $screen->id == 'plugins' || did_action('fv_player_settings_pre') ) {
      $aCheck = get_transient( $this->strPluginSlug . '_license' );
      if( ( !empty($aCheck->expired) || !empty($aCheck->error) ) && ( !empty($aCheck->message) || !empty($aCheck->changelog) ) ) :
      ?>
        <div class="updated fv-player-pro-admin_notice_license_error">
          <?php if( !empty($aCheck->message) ) echo $aCheck->message; ?>
          <?php if( !empty($aCheck->changelog) ) echo $aCheck->changelog; ?>
        </div>
      <?php endif;
    }
  }

  function admin__adjust_plugin_version( $aPlugins ) {
    global $FV_Player_Pro;
    // get fv-player-pro/fv-player-pro.php
    $current_plugin = str_replace( DIRECTORY_SEPARATOR, '/', str_replace( dirname(dirname(__FILE__)).DIRECTORY_SEPARATOR, '', __FILE__ ) );
    if( !empty($aPlugins[$current_plugin]) && !empty($aPlugins[$current_plugin]['Version']) ) {
      $aPlugins[$current_plugin]['Version'] = $FV_Player_Pro->version;
    }
    return $aPlugins;
  }

  function admin__version_switcher() {
    $html = ob_get_clean();

    $select = "<select id='fv-player-pro-release' name='fv-player-pro-release'>";
    if( $this->admin__check_code('beta') ) $select .= "<option".( $this->get_release() == 'beta' ? " selected" : "" )." value='beta'>Beta</option>";
    if( $this->admin__check_code('release') ) $select .= "<option".( $this->get_release() == 'release' ? " selected" : "" )." value='release'>Release</option>";
    if( $this->admin__check_code('stable') ) $select .= "<option".( $this->get_release() == 'stable' ? " selected" : "" )." value='stable'>Stable</option>";
    $select .= "</select>";

    $message_switch = " <small><a href=\"#\" onclick=\"jQuery('[data-fv-player-pro-beta-notice]').toggle(); jQuery(this).remove(); return false\">Show beta changes</a></small>";

    $html = str_replace('<h2>FV Player</h2>','<form action="" method="post"><h2>FV Player Pro '.$select.$message_switch.'</h2><input type="hidden" name="fv_player_pro_switch" value="' . wp_create_nonce( 'fv_player_pro_switch' ) . '" /></form>',$html);

    echo $html;
    ?>

    <div class='updated' style='display: none' data-fv-player-pro-beta-notice>
      <p>The FV Player Pro Beta brings the following improvements:</p>
      <?php echo $this->get_beta_message(); ?>
    </div>
    <?php
  }

  function admin__version_switcher_script() {
    ?>
    <script>
    jQuery('#fv-player-pro-release').on( 'change', function() {
      var question = '';
      var version = jQuery(this).val().toLowerCase();

      switch( version )
      {
        case "beta":
          question = '<?php _e('Are you sure you want to switch your FV Player Pro release to beta?', 'fv-player-pro'); ?>';
          break;
        case "release":
          question = '<?php _e('Are you sure you want to switch your FV Player Pro beta to release?', 'fv-player-pro'); ?>';
          break;
        case "stable":

          break;
        default:
          console.log('version_switcher_script -> unexpected version');
      }

      if( !confirm(question) )
      {
        jQuery(this).val( '<?php echo $this->get_release(); ?>' );
        return false;
      }

      jQuery('#fv-player-pro-release').parents('form').submit();
    });
    </script>
    <?php
  }

  function admin__version_switcher_start() {
    if( isset($_GET['page']) && $_GET['page'] == 'fvplayer' ) ob_start();
  }

  function admin__check_code($directory) {
    if( $directory == 'release' ) {
      $directory = '';
    } else {
      $directory .= '/';
    }
    return file_exists( dirname(__FILE__).'/'.$directory.'fv-player-pro.class.php' );
  }

  function fv_player_8_auto_update() {
    global $fv_wp_flowplayer_ver;
    if(
      $fv_wp_flowplayer_ver && version_compare($fv_wp_flowplayer_ver,'8') == -1 &&
      // not if the plugin is activating
      empty($_GET['action']) && empty($_GET['activate']) &&
      // not if FV Player Pro just got installed on FV Player screen
      empty( $_REQUEST['nonce_fv_player_pro_install'] )
    ) {

      if( get_option('fv-player-8-upgrade-attempted') ) {
        return;
      }

      ob_start(); // first check if we can perform the update automatically!
      $creds = request_filesystem_credentials( admin_url(), '', false, false, array() );
      if( !WP_Filesystem($creds) ) { // if not, then don't try to do it at all
        ob_get_clean();
        return;
      }

      echo ob_get_clean();

      global $fv_fp, $fv_wp_flowplayer_ver;
      $fv_fp->pointer_boxes = array(); // no pointer boxes here!

      add_option('fv-player-8-upgrade-attempted',true,false,false);

      remove_action( 'admin_notices', array( FV_Player_Pro(), 'admin__version_check') );
      remove_action( 'admin_notices', array( $this, 'fv_player_8_version_check'), 0 );

      global $FV_Player_Pro_loader;
      $FV_Player_Pro_loader->fv_player_8_upgrade_process();
      exit;
    }
  }

  function fv_player_8_upgrade_process() {
    $network_plugins = function_exists( 'wp_get_active_network_plugins' ) ? wp_get_active_network_plugins() : array();

    echo "<h1>FV Player 8 Upgrade</h1>";

    // Message for Multisite users
    if ( is_multisite() ) {
      foreach( $network_plugins as $network_plugin ) {
        if (
          stripos( $network_plugin,'/fv-wordpress-flowplayer') !== false &&
          stripos( $network_plugin,'/flowplayer.php') !== false
        ) {

          printf(
            __( 'Your FV Player 7 plugin is Network Activated, please go to Network Admin -> Plugins and <a href="%s">install FV Player 8</a>.', 'fv-player-pro' ),
            network_admin_url( 'plugin-install.php?tab=plugin-information&plugin=fv-player' )
          );
          return;
        }
      }
    }

    $fv_player_8_install_link = sprintf(
      __( 'Please go to wp-admin -> Plugins and <a href="%s">install FV Player 8</a> manually.', 'fv-player-pro' ),
      admin_url( 'plugin-install.php?tab=plugin-information&plugin=fv-player' )
    );

    // Deactivate FV Player 7
    $fv_player_7_plugin_slug = $this->get_active_plugin( 'fv-wordpress-flowplayer', 'flowplayer.php' );

    if ( $fv_player_7_plugin_slug ) {
      deactivate_plugins( $fv_player_7_plugin_slug, true );

      $fv_player_7_plugin_slug = $this->get_active_plugin( 'fv-wordpress-flowplayer', 'flowplayer.php' );

      if ( $fv_player_7_plugin_slug ) {
        _e( "FV Player 7 failed to deactivate.", 'fv-player-pro' );
        echo ' ' . $fv_player_8_install_link;
        return;
      }
    }

    // Install FV Player 8
    if ( ! file_exists( WP_PLUGIN_DIR . '/fv-player/fv-player.php' ) ) {
      include_once(ABSPATH . 'wp-admin/includes/class-wp-upgrader.php');
      $upgrader = new Plugin_Upgrader();
      $result   = $upgrader->install('https://downloads.wordpress.org/plugin/fv-player.zip');

      if ( is_wp_error( $result ) ) {
        echo '<p>'. __( "FV Player 8 failed to install.", 'fv-player-pro' ) . '</p>';
        echo '<p>' . $result->get_error_message() . '</p>';
        echo '<p>' . $fv_player_8_install_link . '</p>';
        return;
      }
    }

    $activate_plugin = add_query_arg(
      array(
        'action'   => 'activate',
        'plugin'   => 'fv-player/fv-player.php',
        '_wpnonce' => wp_create_nonce( 'activate-plugin_fv-player/fv-player.php')
      ),
      admin_url( 'plugins.php' )
    );

    _e( 'Activating FV Player 8. If this action does not complete, please click the button below.', 'fv-player-pro' );

    echo '<p><a href="' . $activate_plugin . '" class="button button-primary">' . __( 'Activate FV Player 8', 'fv-player-pro' ) . '</a></p>';

    echo '<script>location.href = "' . $activate_plugin . '";</script>';
    exit;
  }

  function fv_player_8_upgrade_start() {
    if (
      empty( $_GET['page'] ) ||
      'fv_player_pro_base_plugin_upgrade' !== $_GET['page'] ||
      ! wp_verify_nonce( $_GET['nonce'], 'fv_player_pro_base_plugin_upgrade')
    ) {
      return;
    }

    remove_action( 'admin_notices', array( FV_Player_Pro(), 'admin__version_check') );
    remove_action( 'admin_notices', array( $this, 'fv_player_8_version_check'), 0 );

    add_management_page( 'FV Player 8 Upgrade', 'FV Player 8 Upgrade', 'manage_options', 'fv_player_pro_base_plugin_upgrade', array($this, 'fv_player_8_upgrade_process') );
  }

  function fv_player_8_version_check() {
    global $fv_wp_flowplayer_ver;
    if ( ! empty( $fv_wp_flowplayer_ver ) && version_compare( $fv_wp_flowplayer_ver, '8' ) == -1 ) :

      // Skip FV Player Pro version check as the basic check here already triggered the notice
      remove_action( 'admin_notices', array( FV_Player_Pro(), 'admin__version_check') );
      ?>
      <div class="error">
        <p>
          <?php
          _e( 'FV Player Pro: You are using the old FV Player 7 plugin.', 'fv-player-pro' );

          echo ' ';

          if ( current_user_can( 'manage_options' ) ) {

            printf(__( 'Please install FV Player 8. <a href="%s" class="button button-primary">Upgrade</a>', 'fv-player-pro' ),
              add_query_arg(
                array(
                  'page'  => 'fv_player_pro_base_plugin_upgrade',
                  'nonce' => wp_create_nonce( 'fv_player_pro_base_plugin_upgrade' ),
                ),
                admin_url( 'tools.php' )
              )
            );

          } else {
            _e( 'Please contact your WordPress website administrator.', 'fv-player-pro' );
          }
          ?>
        </p>
      </div>
    <?php endif;
  }

  function fv_player_remove_update( $objUpdates ) {
    if( !$objUpdates || !isset($objUpdates->response) || count($objUpdates->response) == 0 ) return $objUpdates;

    foreach( $objUpdates->response AS $key => $objUpdate ) {
      if( stripos($key,'fv-wordpress-flowplayer') === 0 ) {
        unset($objUpdates->response[$key]);

        // Let FV Player show the "To update FV Player please either renew your license or disable FV Player Pro." notice
        update_option('fv_wordpress_flowplayer_expired_license_update_notice', true, false);
      }
    }

    return $objUpdates;
  }

  /**
   * Get the active plugin by matching beginning of folder name and exact match for main plugin file name.
   * We do it like this as we can't rely on the plugin being in the exact folder name.
   *
   * @param string $folder_starts_with
   * @param string $main_plugin_filename
   * @return string|false
   */
  function get_active_plugin( $folder_starts_with, $main_plugin_filename ) {
    $active_plugins = get_option( 'active_plugins' );
    foreach( $active_plugins as $plugin ) {
      if (
        stripos( $plugin, $folder_starts_with) === 0 &&
        stripos( $plugin, '/' . $main_plugin_filename) !== false
      ) {
        return $plugin;
      }
    }

    return false;
  }

  function get_beta_message() {
    return "<ul class='ul-disc'>
      <li>Basic Wistia support</li>
      <li><code>[fvplayer_chapters]</code> shortcode to let you show chapters anywhere in the post.</li>
      <li><code>[fvplayer_transcript]</code> shortcode to let you show transcript anywhere in the post.</li>
      <li>Gumlet.tv support</li>
      <li>PeerTube: Support for Storyboards (Timeline Previews)</li>
      <li>Video Ads: Post-roll fixes</li>
    </ul>";
  }

  function get_release() {
    $release = isset($_POST['fv-player-pro-release']) && isset($_REQUEST['fv_player_pro_switch']) ? $_POST['fv-player-pro-release'] : get_option('fv-player-pro-release');
    if( !$release ) {
      $release = 'release';
    }
    return $release;
  }

}

global $FV_Player_Pro_loader;
$FV_Player_Pro_loader = new FV_Player_Pro_loader;

endif;

function fv_player_pro_deactivate() {
  wp_unschedule_hook( 'fv_player_pro_update_cloudflare_ips' );
  wp_unschedule_hook( 'fv_player_pro_clear_cache' );
  wp_unschedule_hook( 'fv_player_pro_update_vimeo_cache' );
  wp_unschedule_hook( 'fv_player_pro_update_youtube_cache' );
}

register_deactivation_hook( __FILE__, 'fv_player_pro_deactivate' );
