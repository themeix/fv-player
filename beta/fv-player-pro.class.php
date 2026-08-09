<?php
/*
Version: 8.0.18.beta
*/

if( !class_exists('FV_Player_Pro') ) :

include( dirname(__FILE__).'/rcp-bridge.php' );
include( dirname(__FILE__).'/s3.class.php' );
include( dirname(__FILE__).'/ads.exoclick.class.php' );
include( dirname(__FILE__).'/download.class.php' );
include( dirname(__FILE__).'/hls.class.php' );
include( dirname(__FILE__).'/amazon-drive.class.php' );
include( dirname(__FILE__).'/ajax-master.class.php' );
include( dirname(__FILE__).'/ajax-loader.class.php' );
include( dirname(__FILE__).'/bunnycdn.class.php' );
include( dirname(__FILE__).'/bunny-stream.class.php' );
include( dirname(__FILE__).'/bunny-stream-collections.class.php' );
include( dirname(__FILE__).'/cloudflare.class.php' );
include( dirname(__FILE__).'/cloudflare-ip.class.php' );
include( dirname(__FILE__).'/digitalocean-spaces.class.php' );
include( dirname(__FILE__).'/gumlet.class.php' );
include( dirname(__FILE__).'/vimeo-channel.class.php' );
include( dirname(__FILE__).'/youtube-channel.class.php' );
include( dirname(__FILE__).'/keycdn.class.php' );
include( dirname(__FILE__).'/stackpath.class.php' );
include( dirname(__FILE__).'/stream-loader.class.php' );
include( dirname(__FILE__).'/transcript.class.php' );
include( dirname(__FILE__).'/timeline-previews.class.php' );
include( dirname(__FILE__).'/vimeo.class.php' );
include( dirname(__FILE__).'/fv-wistia.class.php' );
include( dirname(__FILE__).'/drm-text.class.php' );
include( dirname(__FILE__).'/odysee.class.php' );
include( dirname(__FILE__).'/ok-ru.class.php' );
include( dirname(__FILE__).'/rumble.class.php' );
include( dirname(__FILE__).'/peertube.class.php' );
include( dirname(__FILE__).'/peertube-private.class.php' );
include( dirname(__FILE__).'/universal-cdn.class.php' );
include( dirname(__FILE__).'/youtube-pro.class.php' );

class FV_Player_Pro {
  static $instance = null;

  var $version = '8.0.19.beta.1';

  var $autoplay_count = 0;

  var $license_key = false;

  var $aChapters = array();

  var $aStartEnd = array();

  var $aVideoAds = array();

  var $aVimeoQualities = array( '-mobile' => '240p', '-mobile2' => '270p', '-sd' => '360p', '-md' => '540p', '-hd' => '720p', '-fullhd' => '1080p', '-qhd' => '1440p', '-4k' => '2160p' );

  var $bVideoAdsStatus = array();

  var $bYoutube = false;

  var $bWistia = false;

  var $htmlAfter = '';

  var $aFrontendErrors = array();

  var $fTimeSpent_AutoSplash = 0;

  function __construct() {
    $flowplayer_opt = get_option('fvwpflowplayer');
    $this->license_key = ( isset($flowplayer_opt['key']) && !empty($flowplayer_opt['key']) ) ? $flowplayer_opt['key'] : false;

    add_filter( 'fv_flowplayer_get_mime_type', array( $this, 'set_file_type'), 10, 2 );
    add_filter( 'fv_flowplayer_player_type', array( $this, 'set_player_type' ), 10, 5 );  //  switch FV Flowplayer not to use the iframe Vimeo integration
    add_filter( 'fv_player_is_admin_screen', array( $this, 'is_video_ads_screen' ));

    add_action( 'wp_enqueue_scripts', array( $this, 'styles' ), 11 );
    add_action( 'wp_footer', array( $this, 'styles' ) ); // sometimes FV Player styles load in footer
    add_action( 'wp_footer', array( $this, 'scripts' ), 0 );

    // video ads save
    add_filter( 'fv_flowplayer_settings_save', array( $this, 'video_ads_save' ), 12, 2 );

//    add_action( 'wp_ajax_nopriv_fv_fp_get_video_url', array( $this, 'ajax__get_video_url' ) );
//    add_action( 'wp_ajax_fv_fp_get_video_url', array( $this, 'ajax__get_video_url' ) );
    add_action( 'plugins_loaded', array( $this, 'ajax__get_video_url' ) );  //  todo: speed up?

    add_action( 'fv_player_load_video_encoder_libs', array( $this, 'video_encoder_load_files' ), 11 );
    add_action( 'fv_player_extensions_admin_load_assets', array( $this, 'admin_load_assets' ) );
    add_action( 'fv_flowplayer_shortcode_editor_after', array( $this, 'shortcode_editor_actions' ) );
    add_action( 'fv_flowplayer_shortcode_editor_tab_options', array( $this, 'shortcode_editor_options' ) );
    add_action( 'fv_flowplayer_shortcode_editor_item_after', array( $this, 'shortcode_editor_item' ) );
    add_filter( 'fv_flowplayer_shortcode', array( $this, 'shortcode' ), 10, 3 );

    // new editor
    add_filter( 'fv_player_editor_video_fields', array( $this, 'shortcode_video_tab_fields' ) );
    add_filter( 'fv_player_editor_subtitle_fields', array( $this, 'shortcode_subtitles_tab_fields' ) );
    add_filter( 'fv_player_editor_actions', array( $this, 'shortcode_actions_tab_fields') );
    add_filter( 'fv_player_editor_player_options', array( $this, 'editor_player_options') );

    add_filter( 'fv_flowplayer_buttons_left', array( $this, 'hflip_button' ) );

    add_filter( 'fv_flowplayer_attributes', array( $this, 'ab_loop_button' ), 10, 3 );
    add_filter( 'fv_flowplayer_attributes', array( $this, 'quality_attributes' ), 10, 3 );
    add_filter( 'fv_flowplayer_attributes', array( $this, 'attributes_playlist_randomize' ) );

    add_filter( 'fv_flowplayer_attributes', array( $this, 'watching_prompt_attributes' ), 10, 3 );

    add_filter( 'fv_flowplayer_buttons_right', array( $this, 'button_playlist_refresh' ) );

    add_filter( 'fv_player_item_pre', array($this, 'quality_media' ), 10, 3 );

    add_filter( 'fv_flowplayer_html', array( $this, 'chapters_below_player' ), 10, 2 );

    add_shortcode( 'fvplayer_chapters', array( $this, 'chapters_separate' ) );

    add_filter( 'fv_flowplayer_video_src', array( $this, 'get_cloudfront_secure'), 10, 2 );
    add_filter( 'fv_flowplayer_splash', array( $this, 'get_cloudfront_secure_long') );
    add_filter( 'fv_flowplayer_playlist_splash', array( $this, 'get_cloudfront_secure_long') );
    add_filter( 'fv_flowplayer_resource', array( $this, 'get_cloudfront_secure_long') );

    if( !is_admin() ) {
      add_filter( 'fv_flowplayer_splash', array( $this, 'get__cached_splash' ), 10, 2 );
      add_filter( 'fv_flowplayer_playlist_splash', array( $this, 'get__cached_splash' ), 10, 2 );
    }

    add_filter( 'plugin_action_links', array( $this, 'admin__plugin_action_links' ), 10, 2);
    add_action( 'admin_menu', array($this, 'admin__menu'), 11 );
    add_filter( 'fv_flowplayer_settings_save', array($this, 'settings_save'), 10, 2 );

    add_filter( 'content_save_pre', array($this, 'save_post'), 10 );  //  exp: downside - it's called twice on post save

    add_action( 'admin_init', array( $this, 'admin__add_meta_boxes' ), 11 );
    add_action( 'admin_init', array( $this, '_get_conf' ) );
    add_action( 'admin_notices', array( $this, 'admin__version_check') );
    add_action( 'admin_notices', array( $this, 'convert__start') );
    add_action( 'admin_footer-plugins.php', array( $this, 'disable_deactivate_fv_player') );

    add_action( 'wp_ajax_fv_foliopress_ajax_pointers', array( $this, 'ajax__pointers' ) );

    add_filter( 'fv_flowplayer_playlist_items', array( $this, 'start_endtime' ), 10, 2 ); //  old_code

    add_filter( 'fv_flowplayer_playlist_items', array( $this, 'video_ads' ), 10, 2 );
    add_filter( 'fv_flowplayer_playlist_item_html', array( $this, 'video_ads_item_html') );
    add_filter( 'fv_flowplayer_html', array( $this, 'append_warnings'), 999 );

    add_action('amp_post_template_css', array( $this, 'amp_post_template_css' ) );
    add_action('amp_post_template_footer', array( $this, 'amp_post_template_footer' ), 9 );

    if( !empty($_POST['action']) && $_POST['action'] == 'fv_fp_get_video_url' ) {
      if( isset($_REQUEST['fvpexpirelow']) ) {
        add_filter( 'fv_flowplayer_amazon_expires', array( $this, 'low_expiration' ), PHP_INT_MAX );
        add_filter( 'fv_player_secure_link_timeout', array( $this, 'low_expiration' ), PHP_INT_MAX );
      } else if( preg_match( '~i(Pad|Pod|Phone)~', $_SERVER['HTTP_USER_AGENT']) ) {
        add_filter( 'fv_flowplayer_amazon_expires', array( $this, 'ios_expiration' ), PHP_INT_MAX );
        add_filter( 'fv_player_secure_link_timeout', array( $this, 'ios_expiration' ), PHP_INT_MAX );
      }
    }

    //add_filter( 'the_content', array($this,'convert__vimeo_callback') );

    // Localization phrases
    add_action( 'plugins_loaded', array( $this, 'init_plugin_textdomain' ) );

    add_action( 'init', array( $this, 'upgrade_check' ) );

    add_action( 'fv_player_pro_update', array( $this, 'plugin_update_database' ) );

    add_action( 'fv_player_pro_update', array( $this, 'check_chapters_usage' ) );

    //add_action( 'fv_player_pro_update', array( 'FV_Player_Pro_Vimeo', 'refresh_splash_and_durations_silent' ) );

    add_action( 'fv_player_pro_update', array( $this, 'video_ads_update' ) );

    add_filter('cron_schedules', array( $this, 'fv_cron_schedules' ) );

    add_action( 'fv_player_pro_clear_cache', array( $this, 'clear_cache' ) );

    add_action( 'admin_init', array( $this, 'cron_init' ) );

    add_filter( 'fv_flowplayer_args', array( $this, 'disable_titles_vimeo_youtube') );

    add_action( 'fv_flowplayer_admin_interface_options_after', array( $this, 'interface_options'), 11 );

    add_filter( 'fv_player_item', array($this, 'item_chapters'), 10, 3);
    add_filter( 'fv_player_item', array($this, 'item_start_end'), 10, 3);

    // DB saving
    add_filter('fv_player_db_video_meta_save', array($this, 'parse_post_metadata'), 10, 3);

    // transcript & chapters under Subtitles tab
    add_action('fv_flowplayer_shortcode_editor_subtitles_tab_prepend', array($this, 'shortcode_editor_subtitles_prepend'), 10);

    // filter to retrieve video data ia fetch_vimeo_yt_data()
    add_filter('fv_player_meta_data', array($this, 'fetch_vimeo_yt_data'), 10, 3);

    add_action( 'plugins_loaded', array($this, 'include_vimeo_media_browser') );

    add_action( 'plugin_loaded', array($this, 'include_peertube_private_media_browser') );

    add_filter('fv_player_skin_settings', array($this, 'ab_loop_color'), 10, 2);

    add_filter('fp_api_license_check', array($this, 'features_check'), 10, 2);

    // Deprecated, for old versions of FV Player Coconut only
    add_filter('fv_player_coconut_conf_output', array($this, 'fv_player_coconut_conf_output'), 10, 2);

    add_filter('fv_player_coconut_conf', array($this, 'fv_player_coconut_conf'), 10, 2);

    add_filter('fv_player_coconut_encryption_notice', '__return_false');

    add_action( 'admin_init', array( $this, 'pointer_boxes' ) );
  }

  public static function _get_instance() {
    if( !self::$instance ) {
      self::$instance = new self();
    }

    return self::$instance;
  }

  public function _get_conf() {
    global $fv_fp;
    if( !isset($fv_fp->conf) )
      return false;

    $conf = get_option( 'fvwpflowplayer' );

    if(!isset($conf['pro'])) $conf['pro'] = array();

    if(empty($conf['pro']['quality'])) $conf['pro']['quality'] = ',';
    if(empty($conf['pro']['transcript_theme'])) $conf['pro']['transcript_theme'] = 'light';

    if(empty($conf['pro']['video_ads_default'])) $conf['pro']['video_ads_default'] = 'no';
    if(empty($conf['pro']['video_ads_postroll_default'])) $conf['pro']['video_ads_postroll_default'] = 'no';
    if( !isset($conf['pro']['video_ads_skip']) || !is_numeric($conf['pro']['video_ads_skip']) ) $conf['pro']['video_ads_skip'] = 5;
    if( !isset($conf['pro']['video_ads_skip_minimum']) || !is_numeric($conf['pro']['video_ads_skip_minimum']) ) $conf['pro']['video_ads_skip_minimum'] = 10;
    if( empty($conf['pro']['watching_prompt_msg']) ) $conf['pro']['watching_prompt_msg'] = 'Are you still watching?';
    global $FV_Player_Pro_DRM_Text;
    $conf = $FV_Player_Pro_DRM_Text->default_values($conf);

    update_option( 'fvwpflowplayer', $conf );
    $fv_fp->conf = $conf;
    return true;
  }



  function is_option_enabled( $option = false ) {
    if( !$option ) {
      return false;
    }

    global $fv_fp;
    if( is_array($option) && count($option) == 2 && isset($fv_fp->conf['pro']) && isset($fv_fp->conf['pro'][$option[0]]) && isset($fv_fp->conf['pro'][$option[0]][$option[1]]) && $fv_fp->conf['pro'][$option[0]][$option[1]] == 'true' ) {
      return true;
    } else if( !is_array($option) && isset($fv_fp->conf['pro']) && is_array($fv_fp->conf['pro']) && isset($fv_fp->conf['pro'][$option]) && strcmp($fv_fp->conf['pro'][$option],'false') != 0 ) {
      return true;
    } else {
      return false;
    }

  }

  public function _get_option($key) {
    global $fv_fp;
    if( !isset($fv_fp) ) return false;

    $value = $fv_fp->conf;
    if(is_string($key)){
      $key = array($key);
    }
    foreach($key as $val){
        $value = isset($value[$val]) ? $value[$val] : false;
    }

    if( is_string($value) ) $value = trim($value);

    if($value === 'false')
      $value = false;
    else if($value === 'true')
      $value = true;

    return $value;
  }

  public function _get_checkbox( $name, $key, $help = false, $more = false ) {
    $checked = $this->_get_option($key);
    if($checked === 'false' ) $checked = false;
    if( is_array($key) && count($key) > 1 ) {
      $key = $key[0] . '[' . $key[1] . ']' . (isset($key[2]) ? '[' . $key[2] . ']' : '');
    }
    ?>
      <tr>
        <td class="first"><label for="<?php echo $key; ?>"><?php if( !empty($name) ) echo $name.":"; ?></label></td>
        <td>
          <p class="description">
              <input type="hidden" name="<?php echo $key; ?>" value="false" />
              <input type="checkbox" name="<?php echo $key; ?>" id="<?php echo $key; ?>" value="true" <?php if( $checked ) echo 'checked="checked"'; ?> />
            <?php if( $help ) echo $help; ?>
            <?php if( $more ) : ?>
                <span class="more"><?php echo $more; ?></span> <a href="#" class="show-more">(&hellip;)</a>
            <?php endif; ?>
          </p>
        </td>
      </tr>
    <?php
  }

  public function _get_input_text($options = array()) {
    // options must be an array
    if (!is_array($options)) {
      throw new Exception('Options parameter passed to the _get_input_text() method needs to be an array!');
    }

    $first_td_class = (!empty($options['first_td_class']) ? ' class="'.$options['first_td_class'].'"' : '');
    $class_name     = (!empty($options['class']) ? ' class="'.$options['class'].'"' : '');
    $key            = (!empty($options['key']) ? $options['key'] : '');
    $name           = (!empty($options['name']) ? $options['name'] : '');
    $title          = (!empty($options['title']) ? ' title="'.$options['title'].'" ' : '');
    $default        = (!empty($options['default']) ? $options['default'] : '');
    $help           = (!empty($options['help']) ? $options['help'] : '');

    if (!$key || !$name) {
      throw new Exception('Both, "name" and "key" options need to be set for _get_input_text()!');
    }

    $saved_value = esc_attr( $this->_get_option($key) );
    if ( is_array( $key ) && count( $key ) > 1 ) {
      $key = $key[0] . '[' . $key[1] . ']';
    }
    ?>
      <tr>
        <td<?php echo $first_td_class; ?>><label for="<?php echo $key; ?>"><?php echo $name; ?> <?php if( $help ) echo '<a href="#" class="show-info"><span class="dashicons dashicons-info"></span></a>'; ?>:</label></td>
        <td>
          <input <?php echo $class_name; ?> id="<?php echo $key; ?>" name="<?php echo $key; ?>" <?php if ($title) { echo $title; } ?>type="text"  value="<?php echo (!empty($saved_value) ? $saved_value : $default); ?>"<?php
            if (isset($options['data']) && is_array($options['data'])) {
              foreach ($options['data'] as $data_item => $data_value) {
                echo ' data-'.$data_item.'="'.$data_value.'"';
              }
            }
          ?> />
          <?php if ( $help ) { ?>
            <p class="description fv-player-admin-tooltip"><span class="info"><?php echo $help; ?></span></p>
          <?php } ?>
        </td>
      </tr>

    <?php
  }

  public function _get_select() {
    $args_num = func_num_args();
    $no_row = false;

    // new method syntax with all options in the first parameter (which will be an array)
    if ($args_num == 1) {
      $options = func_get_arg(0);

      // options must be an array
      if (!is_array($options)) {
        throw new Exception('Options parameter passed to the _get_select() method needs to be an array!');
      }

      $first_td_class = (!empty($options['first_td_class']) ? ' class="'.$options['first_td_class'].'"' : '');
      $key            = (!empty($options['key']) ? $options['key'] : '');
      $name           = (!empty($options['name']) ? $options['name'] : '');
      $aOptions       = (!empty($options['options']) ? $options['options'] : '');
      $class_name     = (!empty($options['class']) ? ' class="'.$options['class'].'"' : '');
      $help           = (!empty($options['help']) ? $options['help'] : '');
      $more           = (!empty($options['more']) ? $options['more'] : '');
      $default        = (isset($options['default']) ? $options['default'] : '');
      $no_row         = (isset($options['no_row']) ? $options['no_row'] : '');

      if (!$key || !$aOptions) {
        throw new Exception('The items "name", "key" and "options" need to be set in options for _get_select()!');
      }
    } else if ($args_num >= 5) {
      // old method syntax with function parameters defined as ($name, $key, $help = false, $more = false)
      $first_td_class = '';
      $name = func_get_arg(0);
      $key = func_get_arg(1);
      $aOptions = func_get_arg(4);
      $help = ($args_num >= 3 ? func_get_arg(2) : false);
      $more = ($args_num >= 4 ? func_get_arg(3) : false);
      $class_name = '';
      $default = '';
    } else {
      throw new Exception('Invalid number of arguments passed to the _get_checkbox() method!');
    }

    // check which option should be selected by default
    $option = $this->_get_option($key);
    foreach( $aOptions AS $k => $v ) {
        if ( $option === true && $k == 'true' ) {
            $selected = $k;
            break;
        } else if ($k == $option) {
            $selected = $k;
        }
    }

    // if no option is selected, make a default one selected
    if (!isset($selected) && $default) {
        $selected = $default;
    }

    if ( is_array( $key ) && count( $key ) > 1 ) {
      $key = $key[0] . '[' . $key[1] . ']';
    }

    ob_start();
    ?>
    <select <?php echo $class_name; ?>id="<?php echo $key ?>" name="<?php echo $key ?>"<?php
      if (!isset($options) || !isset($options['data']) || !isset($options['data']['fv-preview'])) { echo ' data-fv-preview=""'; }

      if (isset($options) && isset($options['data']) && is_array($options['data'])) {
        foreach ($options['data'] as $data_item => $data_value) {
          echo ' data-'.$data_item.'="'.$data_value.'"';
        }
      }
    ?>>
      <?php foreach( $aOptions AS $k => $v ) : ?>
        <option value="<?php echo esc_attr($k); ?>"<?php if( (isset($selected) && $selected == $k) || ($option === $k) ) echo ' selected="selected"'; ?>><?php echo $v; ?></option>
      <?php endforeach; ?>
    </select>
    <?php
    $select = ob_get_clean();

    if ( $no_row ) {
      echo $select;
      return;
    }

    $key = esc_attr($key);
    ?>
      <tr>
        <td<?php echo $first_td_class; ?>><label for="<?php echo $key ?>"><?php echo $name ?></label></td>
        <td>
          <p class="description">
            <?php echo $select; ?>
            <?php if( $help ) echo $help; ?>
            <?php if( $more ) : ?>
                <span class="more"><?php echo $more; ?></span> <a href="#" class="show-more">(&hellip;)</a>
            <?php endif; ?>
          </p>
        </td>
      </tr>

    <?php
  }

  function init_plugin_textdomain() {
    load_plugin_textdomain('fv-player-pro', false, dirname( plugin_basename(FV_PLAYER_PRO_FILE) ) . '/languages/');
  }

  function fv_player_pro_get_js_translations()
  {
    $a_strings = array(
      'invalid_youtube'     => __('Invalid Youtube video ID.', 'fv-player-pro'),
      'reload_page'         => __('Please reload the page and try again.', 'fv-player-pro'),
      'reload_page_later'   => __('Please reload the page and try again in a couple of minutes. ', 'fv-player-pro'),
      'required_type'       => __('Couldn\'t find the required video type: ', 'fv-player-pro'),
      'skip_ad'             => __('Skip', 'fv-player-pro'),
      'cva_contiunue'       => __('Continue to video', 'fv-player-pro'),
      'cva_visit'           => __('Visit advertiser', 'fv-player-pro'),
      'video_decryption_e'  => __('Video Decryption Error', 'fv-player-pro'),
      'video_expired'       => __('Video file expired.<br />Please reload the page and play it again.', 'fv-player-pro'),
      'video_loaded'        => __('Video loaded, click to play.', 'fv-player-pro'),
      'old_android'         => __('Your old Android device doesn\'t support this video type.', 'fv-player-pro'),
      'ab_loop'             => __("Tip: Use 'i' and 'o' keys for precise loop selection", 'fv-player-pro'),
      'ab_loop_failed'      => __("AB loop not available when editing with Elementor", 'fv-player-pro'),
      'ab_loop_start'       => __("Loop start set", 'fv-player-pro'),
      'ab_loop_end'         => __("Loop end set", 'fv-player-pro'),
      'use_modern_browser'  => __('Please use a modern up-to-date browser like <a href="https://www.mozilla.org/en-GB/firefox/new/" target="_blank">Firefox</a> or <a href="https://www.google.com/chrome/" target="_blank">Chrome</a>.', 'fv-player-pro'),
      'ab_button'           => __("AB", 'fv-player-pro'),
      'chapters_menu'       => __("CHAPTERS", 'fv-player-pro'),
      'cloud_private_relay' => __( 'iCloud+ Private Internet Relay blocks HLS playback. Please use another browser.', 'fv-player-pro' ),
    );

    return $a_strings;
  }




  function ab_loop_button($aAttributes) {
    global $fv_fp;

    if ((isset($fv_fp->aCurArgs['ab']) && ( $fv_fp->aCurArgs['ab'] === 'on' || $fv_fp->aCurArgs['ab'] === 'true' ) || empty($fv_fp->aCurArgs['ab']) && $this->_get_option( array('pro','ab_loop') ) )
        && (isset($fv_fp->aCurArgs['controlbar']) && $fv_fp->aCurArgs['controlbar'] != 'no' || !isset($fv_fp->aCurArgs['controlbar']) )) {
      $aAttributes['data-ab'] = "true";
    }

    return $aAttributes;
  }




  function ab_loop_color( $options ) {
    $options['items'][] = array(
      'type'    => 'input_text',
      'key'     => array('skin-'.$options['skin_radio_button_value'], 'accent'),
      'name'    => __( 'Accent', 'fv-player-pro' ),
      'class'   => 'color',
      'default' => '4682B4',
      'data'    => array( 'fv-preview' => '.flowplayer .fv-ab-loop .noUi-connect { background-color: #%val% !important; }' ),
      'help'    => __( 'Used for AB loop color', 'fv-player-pro' ),
    );
    return $options;
  }




  /*
   * Triggered when loading the FV Player editor, we will need the
   * editor scripts and the player scripts as well - for preview.
   */
  function admin_load_assets( $page ) {
    wp_enqueue_script('fvplayer-shortcode-editor-pro', plugins_url('js/shortcode-editor-pro.js',__FILE__),array('jquery','fvwpflowplayer-shortcode-editor'), filemtime( dirname( __FILE__ ) . '/js/shortcode-editor-pro.js' ) );
    wp_localize_script('fvplayer-shortcode-editor-pro', 'fv_player_editor_pro', array(
      'video_qualities' => $this->func__get_qualities()
    ));

    $this->styles();
    $this->scripts();
  }




  function admin__add_meta_boxes() {
    global $FV_Player_Pro_DRM_Text;

    //Basic tab
    add_meta_box( 'fv_player_pro', __('Pro Features', 'fv-player-pro'), array( $this, 'fv_player_admin_pro' ), 'fv_flowplayer_settings', 'normal', 'low' );
    add_meta_box( 'fv_player_pro_quality', __('Quality Switching', 'fv-player-pro'), array( $this, 'fv_player_admin_pro_quality' ), 'fv_flowplayer_settings', 'normal', 'low' );
    add_meta_box( 'fv_player_pro_drm_text', __('DRM Text', 'fv-player-pro'), array( $FV_Player_Pro_DRM_Text, 'fv_player_admin_pro_drm_text' ), 'fv_flowplayer_settings', 'normal', 'low' );
    add_meta_box( 'fv_player_pro_watching_prompt', __('Prompt to Continue Watching', 'fv-player-pro'), array( $this, 'fv_player_admin_pro_watching_prompt' ), 'fv_flowplayer_settings', 'normal', 'low' );
    add_meta_box( 'fv_player_pro_transcript', __('Video Transcript', 'fv-player-pro'), array( $this, 'fv_player_admin_pro_transcript' ), 'fv_flowplayer_settings', 'normal', 'low' );

    //Hosting Tab
    add_meta_box( 'fv_player_pro_cloudfront', __('CloudFront (Pro)', 'fv-player-pro'), array( $this, 'fv_player_admin_pro_cloudfront' ), 'fv_flowplayer_settings_hosting', 'normal', 'low' );
    add_meta_box( 'fv_player_pro_vimeo', __('Vimeo (Pro)', 'fv-player-pro'), array( $this, 'fv_player_admin_pro_vimeo' ), 'fv_flowplayer_settings_hosting', 'normal' );

    //Video Ads Tab
    add_meta_box( 'fv_player_pro_video_ads_description', ' ', array( $this, 'fv_player_admin_pro_video_ads_description' ), 'fv_flowplayer_settings_video_ads', 'normal' ,'high');
    add_meta_box( 'fv_player_pro_video_ads', __('Video Ads (Pro)', 'fv-player-pro'), array( $this, 'fv_player_admin_pro_video_ads' ), 'fv_flowplayer_settings_video_ads', 'normal', 'low' );
  }

  function plugin_update_database(){
    $this->remove_duplicated_videometa();
    $this->clear_cache(true);
  }

  function admin__menu() {

    // Add menu item if FV Player not found
    global $fv_fp;
    if ( empty( $fv_fp ) ) {
      add_options_page( 'FV Player Pro', 'FV Player Pro', 'manage_options', 'fvplayer', array($this, 'admin__screen_warn') );
    }

    add_submenu_page(  'fv_player', 'Video Ads', 'Video Ads', 'edit_others_posts', 'fv_player_video_ads', array($this, 'video_ad_tools_panel') );
  }

  function upgrade_check() {

    // Do not perform DB upgrades if FV Player is not active as it might be needed
    global $fv_fp;
    if ( empty( $fv_fp ) ) {
      return;
    }

    $version = get_option( 'fv_player_pro_ver' );
    if( $this->version != $version ) {
      update_option( 'fv_player_pro_ver', $this->version );
      do_action( 'fv_player_pro_update', $version );
    }
  }

  function video_ad_tools_panel() {
    ?>
    <div class="wrap">
      <form id="wpfp_options" method="post" action="">
        <div id="dashboard-widgets" class="metabox-holder fv-metabox-holder columns-1">
          <div id="postbox-container-tab_video_ads" class="postbox-container">
            <?php do_meta_boxes('fv_flowplayer_settings_video_ads', 'normal', false ); ?>
          </div>
        </div>
        <?php wp_nonce_field( 'fv_flowplayer_settings_ajax_nonce', 'fv_flowplayer_settings_ajax_nonce' ); ?>
      </form>
      <div id="fv-player-popup-container"></div>

      <?php
        do_action( 'fv_player_pro_video_ads_panel')
      ?>

    </div>
    <?php
  }

  function video_ads_update() {
    global $fv_fp;

    $aSettings = get_option( 'fvwpflowplayer' );

    // update only once
    if( ! isset( $aSettings['pro']['video_ads_ids'] ) ) {

      if ( ! empty( $aSettings['pro']['video_ads'] ) ) {

        $post = array(
          'aVideoAdDisabled' => array(),
          'aVideoAd_mp4'     => array(),
          'aVideoAdClick'    => array(),
          'aVideoAd_name'    => array()
        );

        foreach($aSettings['pro']['video_ads'] as $videoAd ) {
          $post['aVideoAdDisabled'][] = $videoAd['disabled'];
          $post['aVideoAd_mp4'][]     = $videoAd['videos']['mp4'];
          $post['aVideoAdClick'][]    = $videoAd['click'];
          $post['aVideoAd_name'][]    = $videoAd['name'];
        }

        $new_video_ads_ids = $this->pro_video_ads_save( $post );

        $fv_fp->_set_option( array('pro','video_ads_ids'), $new_video_ads_ids );

      } else if ( ! empty( $aSettings['pro'] ) ) {
        $fv_fp->_set_option( array('pro','video_ads_ids'), array() );
      }
    }
  }

  function video_ads_save( $settings ) {
    if ( empty( $settings['pro'] ) ) {
      $settings['pro'] = array();
    }

    if ( isset( $_POST['aVideoAdDisabled'] ) ) {
      $settings['pro']['video_ads_ids'] = $this->pro_video_ads_save();
    }

    // these are the inputs in the Video Ads box which should not be saved as we save them as FV Players
    unset( $settings['aVideoAd_name'] );
    unset( $settings['aVideoAd_mp4'] );
    unset( $settings['aVideoAdClick'] );
    unset( $settings['aVideoAdDisabled'] );
    unset( $settings['aVideoAdPlayerId'] );

    return $settings;
  }




  function video_encoder_load_files() {
    global $fv_wp_flowplayer_ver;

    if( version_compare( $fv_wp_flowplayer_ver, '7.5.18.727.2') != -1 && version_compare(PHP_VERSION, '5.2.17') >= 0 ) {
      include_once( dirname(__FILE__).'/fv-timeline-previews-api.php' );
    }
  }




  /*
   * Acts as a filter on fv_player_is_admin_screen to help FV Player (free core plugin)
   * determine if various admin styles should be loaded
   */
  function is_video_ads_screen( $is = false ) {
    if( isset($_GET['page']) && strcmp($_GET['page'],'fv_player_video_ads') == 0 ) {
      return true;
    }

    return $is;
  }




  /**
   * Test if the current browser runs on a mobile device (smart phone, tablet, etc.)
   *
   * @since 3.4.0
   *
   * @return bool
   */
  function is_mobile() {
    if ( empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
      $is_mobile = false;
    } elseif ( strpos( $_SERVER['HTTP_USER_AGENT'], 'Mobile' ) !== false // Many mobile devices (all iPhone, iPad, etc.)
      || strpos( $_SERVER['HTTP_USER_AGENT'], 'Android' ) !== false
      || strpos( $_SERVER['HTTP_USER_AGENT'], 'Silk/' ) !== false
      || strpos( $_SERVER['HTTP_USER_AGENT'], 'Kindle' ) !== false
      || strpos( $_SERVER['HTTP_USER_AGENT'], 'BlackBerry' ) !== false
      || strpos( $_SERVER['HTTP_USER_AGENT'], 'Opera Mini' ) !== false
      || strpos( $_SERVER['HTTP_USER_AGENT'], 'Opera Mobi' ) !== false ) {
        $is_mobile = true;
    } else {
      $is_mobile = false;
    }

    return $is_mobile;
  }




  function admin__plugin_action_links($links, $file) {
  	$plugin_file = basename(__FILE__);
  	if( basename($file) == $plugin_file ) {
      $settings_link =  '<a href="'.admin_url('options-general.php?page=fvplayer#fv_player_pro').'">'.__('Settings', 'fv-player-pro').'</a>';
  		array_unshift($links, $settings_link);
  	}
  	return $links;
  }




  function admin__screen() {
    //  dummy
  }




  function admin__screen_warn() {
    global $fv_fp;
    if( isset($fv_fp) || $fv_fp ) return;

    ?>
    <div class="wrap">
      <div style="position: absolute; margin-top: 10px; right: 10px;">
        <a href="https://foliovision.com/player" target="_blank" title="<?php _e('Documentation', 'fv-player-pro'); ?>"><img alt="visit foliovision" src="<?php echo plugins_url('images/fv-logo.png', FV_PLAYER_PRO_FILE ); ?>" /></a>
      </div>
      <div>
        <div id="icon-options-general" class="icon32"></div>
        <h2>FV Player Pro</h2>
      </div>

    </div>
    <?php
  }




  function admin__select_video_ads($aArgs) {

    $sId = (isset($aArgs['id']) ? $aArgs['id']:'pro[video_ads_default]');
    $aArgs = wp_parse_args( $aArgs, array( 'id'=>$sId, 'cva_id'=>'', 'show_default' => false ) );
    ?>
    <select id="<?php echo $aArgs['id']; ?>" name="<?php echo $aArgs['id']; ?>">
      <?php if( $aArgs['show_default'] ) : ?>
        <option><?php _e('Use site default', 'fv-player-pro'); ?></option>
      <?php endif; ?>
      <option <?php if( $aArgs['cva_id'] == 'no' ) echo 'selected '; ?>value="no"><?php _e('No ad', 'fv-player-pro'); ?></option>
      <option <?php if( $aArgs['cva_id'] == 'random' ) echo 'selected '; ?>value="random"><?php _e('Random', 'fv-player-pro'); ?></option>
      <?php
      $videoAds = $this->_get_option( array('pro','video_ads_ids') );
      if( is_array($videoAds) && count($videoAds) > 0 ) {
        foreach( $videoAds AS $key => $aVideoAd ) {
          $player = new FV_Player_Db_Player( $aVideoAd );

          $videos = $player->getVideos();

          if( empty( $videos[0] ) ) {
            continue;
          }

          $video = $videos[0];

          $name = method_exists( $video, 'getTitle' ) ? $video->getTitle() : $video->getCaption();
          $src = $video->getSrc();
          $disabled = $video->getMetaValue( 'video_ad_disabled', true );

          ?><option <?php if( $aArgs['cva_id'] == $key+1 ) echo 'selected'; ?> value="<?php echo $key+1; ?>"><?php
          echo $key+1;

          if( !empty($name) ) echo ' - '. $name;
          if( $disabled == 1 ) echo ' (currently disabled)';
          if( trim( $src ) === '' ) echo ' (no video URL)';
          ?></option><?php
        }
      } ?>
    </select>
    <?php
  }


  function admin__version_check() {
    global $fv_wp_flowplayer_ver;
    if( $fv_wp_flowplayer_ver && version_compare($fv_wp_flowplayer_ver,'8') == -1 ) :
    ?>
    <div class="error">
        <p><?php _e( 'FV Player Pro: Please upgrade to FV Player version 8 or above!', 'fv-player-pro' ); ?></p>
    </div>
    <?php
    endif;

    global $FV_Player_Vimeo_Security;
    if(
      class_exists( 'FV_Player_Vimeo_Security' ) && empty( $FV_Player_Vimeo_Security ) ||
      ! empty( $FV_Player_Vimeo_Security ) && version_compare($FV_Player_Vimeo_Security->version,'7.5.46.727') == -1
    ) :
    ?>
    <div class="error">
        <p><?php _e( 'FV Player Pro: Please upgrade to FV Player Vimeo Security version 7.5.46.727 or above, otherwise the Vimeo videos are not protected!', 'fv-player-pro' ); ?></p>
        <?php if ( class_exists( 'FV_Player_Vimeo_Security' ) && empty( $FV_Player_Vimeo_Security ) ) : ?>
          <p><?php echo sprintf( __( 'Please reinstall FV Player Vimeo Security from a ZIP file which you download on %s.', 'fv-player-pro' ), '<a href="https://foliovision.com/my-licenses" target="_blank">foliovision.com/my-licenses</a>' ); ?></p>
        <?php endif; ?>
    </div>
    <?php
    endif;

    if( isset($_GET['fv-licensing']) && $_GET['fv-licensing'] == "check" ){
      echo '<div class="updated inline">
              <p>Thank you for purchase. Your license will be renewed in couple of minutes.<br/>
              Please make sure you upgrade <strong>all the FV Player</strong> plugins.</p>
            </div>';
    }

    global $fv_fp;
    if( !isset($fv_fp) ) {
      ?>
        <div class="error">
          <p><?php _e('<strong>FV Player Pro:</strong> Base plugin FV Player is not installed or activated. Please <a href="plugin-install.php?tab=plugin-information&plugin=fv-player" target="_blank" title="FV Wordpress Flowplayer install page">install</a> it and activate the license.', 'fv-player-pro'); ?></p>
        </div>
      <?php
    }
  }

  function disable_deactivate_fv_player(){
    ?>
      <script>
      jQuery('document').ready(function(){
        jQuery('[data-slug=fv-wordpress-flowplayer] .deactivate>a').on('click', function(e){
          e.preventDefault();
          if(confirm("<?php _e('The FV Player plugin is essential for FV Player Pro plugin.\nDeactivating it will cause it not to function.\nAre You sure You want to Deactivate it?', 'fv-player-pro'); ?>")){
            location.assign(jQuery(this).attr('href'));
          }
        });
      })
      </script>
    <?php
  }

  function disable_scroll_autoplay() {
    add_filter( 'fv_player_pro_conf', array( $this, 'disable_scroll_autoplay_indeed' ) );
  }

  function disable_scroll_autoplay_indeed( $conf ) {
    unset($conf['autoplay_scroll']);
    return $conf;
  }

  function ajax__get_video_url() {
    if( isset($_POST['action']) && $_POST['action'] === 'fv_fp_get_video_url' && $this->_get_option( array('pro','cf_key_id') ) && $this->_get_option( array('pro','cf_pk') )) {
      $aRequest = parse_url($_SERVER['HTTP_REFERER']);
      $aHome = parse_url(home_url());

      if( $aRequest['host'] != $aHome['host'] ) {
        echo json_encode( array( 'error' => 'Bad request!' ) );
        die();
      }

      $aMP4 = array();
      foreach( $_POST['sources'] AS $key => $value ) {
        if( !isset($value['src']) || !isset($value['type']) ) continue;

        if( 1<0 && $value['type'] == "video/flash" ) { //  todo: what about this?
          $bSkip = false;
          foreach( $aMP4 AS $sMP4 ) {
            //  we need to find if the same filename is already signed for mp4 before we create a new signature for flash. For this we strip all but the basic characters and the signature
            if( preg_replace( '~(\?Expires=.+|%[A-Z0-9]{2}|[^a-zA-Z0-9-_])~', '', $sMP4 ) == preg_replace( '~(%[A-Z0-9]{2}|[^a-zA-Z0-9-_])~', '', $value['src'] ) ) { //  todo: unfortunatelly we get the Flash video URL like this - does it work with RTMP though?
              $sMP4 = str_replace('+', '%2B', $sMP4);
              $_POST['sources'][$key]['source'] = $sMP4;  //  v6 check!
              $bSkip = true;
              continue;
            }
          }
          if( $bSkip ) {
            continue;
          }
        }

        $value['src'] = $this->get_cloudfront_secure($value['src'], array( 'dynamic' => true, 'flash' => ( $value['type'] == "video/flash" ) ? true : false ) );
        $_POST['sources'][$key] = $value;
        if( $value['type'] == "video/mp4" ) {
          $aMP4[] = $value['src'];
        }


      }

    }
  }




  function ajax__pointers() {
    if( isset($_POST['key']) && isset($_POST['value']) ) {
      foreach( array(
        'fv_player_7_beta',
        'fv_player_pro_vimeo_splash_notice'
      ) AS $notice_key ) {
        if( $_POST['key'] == $notice_key  ) {
          check_ajax_referer($notice_key);
          global $fv_fp;
          $aNew = $fv_fp->conf;
          if( empty($aNew['notices']) ) $aNew['notices'] = array();
          $aNew['notices'][$notice_key] = true;
          $fv_fp->_set_conf( $aNew );
          die();
        }
      }

    }
  }




  function amp_post_template_css() {
    $this->styles();
  }




  function amp_post_template_footer() {
    $this->scripts();
  }




  function append_warnings($sHTML) {
    if( count($this->aFrontendErrors) ) {
      $sHTML .= "<p>".implode( "</p><p>", $this->aFrontendErrors )."</p>";
    }

    $this->aFrontendErrors = array();

    return $sHTML;
  }

  function attributes_playlist_randomize($aAttributes) {
    global $fv_fp;

    if ( isset($fv_fp->aCurArgs['randomize']) || method_exists( $fv_fp,'current_player' ) && $fv_fp->current_player() && $fv_fp->current_player()->getMetaValue( 'randomize', true ) ) {
      $aAttributes['data-randomize'] = 'true';
    }

    return $aAttributes;
  }

  function button_playlist_refresh( $aButtons ) {
    global $fv_fp;
    if ( isset( $fv_fp->aCurArgs['randomize_button'] ) || method_exists( $fv_fp, 'current_player' ) && $fv_fp->current_player() && $fv_fp->current_player()->getMetaValue( 'randomize_button', true ) ) {
      $aButtons[] = '<ul class="fv-player-refresh-random-video"><li><a class="fvp-refresh">Refresh</a></li></ul>';
    }

    return $aButtons;
  }

  public function item_chapters( $aItem , $iIndex, $aArgs ){
    global $fv_fp;

    if(!empty($aArgs['chapters'])){
      $aItem['chapters'] = apply_filters( 'fv_flowplayer_resource', $aArgs['chapters'] );

    } else if (method_exists($fv_fp,'current_video') && $fv_fp->current_video() && $fv_fp->current_video()->getMetaData()) {
      // check meta data for chapters
      foreach ($fv_fp->current_video()->getMetaData() as $meta) {
        if ($meta->getMetaKey() == 'chapters') {
          $aItem['chapters'] = apply_filters( 'fv_flowplayer_resource', $meta->getMetaValue() );
          break;
        }
      }
    }

    // TODO: Move to core
    // Get duration from the database entry
    if (method_exists($fv_fp,'current_video') && $fv_fp->current_video() && $fv_fp->current_video()->getDuration()) {
      $aItem['duration'] = $fv_fp->current_video()->getDuration();

    // Get duration for postmeta for old [fvplayer src="..."] shortcodes
    } else if( method_exists( 'flowplayer', 'get_duration' ) ) {
      global $post;
      if( !empty($post->ID) && !empty($aItem['sources'][0]['src']) ) {
        if( $duration = flowplayer::get_duration( $post->ID, $aItem['sources'][0]['src'], true ) ) {
          $aItem['duration'] = $duration;
        }
      }
    }

    return $aItem;
  }


  public function item_start_end( $aItem , $iIndex, $aArgs ){

    if(!empty($aArgs['startend'])){
      $aStartEnd = explode(';',$aArgs['startend']);
      if( !empty($aStartEnd[$iIndex]) ) {
        $aTimes = explode('-',$aStartEnd[$iIndex]);
        if( !empty($aTimes[0]) ) $aItem['fv_start'] = self::hms_to_seconds($aTimes[0]);
        if( !empty($aTimes[1]) ) $aItem['fv_end'] = self::hms_to_seconds($aTimes[1]);
      }
    }

    // these values are put in by free FV Player, but not properly sanitized there!
    if( isset($aItem['fv_start']) ) $aItem['fv_start'] = self::hms_to_seconds($aItem['fv_start']);
    if( isset($aItem['fv_end']) ) $aItem['fv_end'] = self::hms_to_seconds($aItem['fv_end']);

    return  $aItem;
  }

  public function chapters_below_player( $html, $player ) {
    global $post;

    if ( empty( $post->post_content ) || strpos( $post->post_content, '[fvplayer_chapters' ) === false ) {
      $html = $this->chapters_html( $html, $player );
    }

    return $html;
  }

  function chapters_html( $html ) {
    global $fv_fp;

    if( !$this->_get_option( array('pro','chapters_below_player') ) ) {
      return $html;
    }

    $aArgs = func_get_args();
    $found = false;

    if( isset($aArgs[1]->aCurArgs['chapters']) && strlen(trim($aArgs[1]->aCurArgs['chapters'])) ) { // shortcode
      $found = true;
    } else if( method_exists($fv_fp,'current_video') && $fv_fp->current_player() && $fv_fp->current_player()->getVideos() ) { // meta
      $aVideos = $fv_fp->current_player()->getVideos(); // Get all videos for current player
      foreach($aVideos as $video) {
        foreach ($video->getMetaData() as $meta) {
          if ($meta->getMetaKey() == 'chapters') {
            $found = true;
            break 2;
          }
        }
      }
    }

    if( !$found ) {
      return $html;
    }

    return $html . "<ul class='fv_fp_chapters' id='fv_fp_chapters_".$aArgs[1]->hash."'></ul>";
  }

  function chapters_separate( $args ) {
    global $fv_fp;
    return $this->chapters_html( '', $fv_fp );
  }

  /*
  Figure out if user has used the chapters feature before and if so, enable chapters_below_player
  */
  function check_chapters_usage( $old_version ) {
    global $wpdb, $fv_fp;

    // No need for check if already on a newer version, or if it's new install
    if( version_compare( $old_version, '7.5.15.727', '>=' ) || !$old_version ) {
      return;
    }

    // Additional check, only do this if the setting is not already set
    if( empty($fv_fp->conf) || isset($fv_fp->conf['pro']['chapters_below_player']) ) {
      return;
    }

    $aNew = $fv_fp->conf;
    $aNew['pro']['chapters_below_player'] = false;

    $videometa_table = "{$wpdb->prefix}fv_player_videometa";

    $query = $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $videometa_table ) );

    // Check if table exists
    if ( $wpdb->get_var( $query ) === $videometa_table  ) {
      // Are there any players in FV Player Database that use chapters?
      $used = $wpdb->get_var( "SELECT count(*) FROM $videometa_table WHERE meta_key = 'chapters' AND meta_value != ''" );

      if( !$used ) {
        // Are there any old shortcodes with chapters?
        $used = $wpdb->get_var( "SELECT count(*) FROM $wpdb->posts WHERE post_content LIKE '% chapters=%'" );
      }

      if( $used ) {
        $aNew['pro']['chapters_below_player'] = true;
        $fv_fp->_set_conf( $aNew );
      }
    }
  }


  function convert__process( $type = 'YouTube' ) {
    echo '<p>Running the conversion process for '.$type.'. If anything fails, remember to restore your backup or revert the change of the post to the previous revision.</p>';
    echo '<p>Scroll down to the end of the list to see the status.</p>';

    $sType = sanitize_title($type);

    global $wpdb;
    $aPosts = $wpdb->get_results( "SELECT * FROM {$wpdb->posts} WHERE post_status != 'inherit' AND post_content LIKE '%".$sType."%' ORDER BY post_date DESC" );

    $aPostsMeta = $wpdb->get_results( "SELECT * FROM {$wpdb->posts} AS p JOIN {$wpdb->postmeta} AS m ON p.ID = m.post_id WHERE post_status != 'inherit' AND meta_value LIKE '%".$sType."%' AND meta_value NOT LIKE '%a:%' AND meta_key NOT LIKE '_oembed_%' ORDER BY post_date DESC" );
    if( $aPostsMeta ) {
      $aPosts = array_merge( $aPosts, $aPostsMeta );
    }

    $tMax = ini_get('max_execution_time') ? ini_get('max_execution_time') : 30;
    $tStart = microtime(true);
    $iCount = 0;
    $iFound = 0;

    echo "<ul>\n";
    foreach( $aPosts AS $objPost ) {
      if( microtime(true) - $tStart > ($tMax - 2) ) {
        break;
      }

      $method = 'convert__'.$sType.'_callback';

      if( !empty($objPost->meta_value) ) {
        $new_meta = $this->$method($objPost->meta_value);

        //echo "<p>$objPost->ID<code>".htmlspecialchars($objPost->meta_value)."</code> becomes <code>".htmlspecialchars($new_meta)."</code></p>";

        if( strlen($new_meta) != strlen($objPost->meta_value) ) {
          $iFound++;

          $ret = update_post_meta( $objPost->ID, $objPost->meta_key, $new_meta );
          if( !$ret ) {
            echo "<li>".$objPost->ID." Error: Failed to update meta key ".$objPost->meta_key." with value of ".htmlspecialchars($objPost->meta_value)."</li>";
          } else {
            $iCount++;
            echo "<li>".$objPost->ID.". <a target='_blank' href='".get_permalink($objPost->ID)."'>".$objPost->post_title."</a> meta key ".$objPost->meta_key." updated ok</li>\n";
          }
        }

      } else {
        $new_content = $this->$method($objPost->post_content);

        if( strlen($new_content) != strlen($objPost->post_content) ) {
          $iFound++;

          $post_id = wp_update_post( array( 'ID' => $objPost->ID, 'post_content' => $new_content ) );
          if( is_wp_error($post_id) ) {
            $errors = $post_id->get_error_messages();
            echo "<li>".$objPost->ID." Error: ";
            foreach ($errors as $error) {
              echo $error;
            }
            echo "</li>";
          } else {
            $iCount++;
            echo "<li>".$objPost->ID.". <a target='_blank' href='".get_permalink($objPost->ID)."'>".$objPost->post_title."</a> updated ok</li>\n";
          }
        }
      }

    }
    echo "</ul>\n";

    if( $iFound == 0 ) {
      echo "<p>No more posts with ".$type." embeds found!</p>\n";
    } else {
      echo "<p>Updated ".$iCount." posts out of ".$iFound." posts with ".$type." embeds.</p>\n";
    }

    if( microtime(true) - $tStart > ($tMax - 5) ) {
      echo "<p><strong>Execution terminated</strong>: PHP max_execution_time reached, run this process again to process the remaining posts!</p>\n";
    } else {
      echo "<p>All done!</p>\n";
    }

    die();
  }




  function convert__start() {
    if( current_user_can('manage_options') && isset($_GET['convert_youtube']) && wp_verify_nonce($_GET['convert_youtube'],'convert_youtube') ) {
      $this->convert__process('YouTube');
    }

    if( current_user_can('manage_options') && isset($_GET['convert_vimeo']) && wp_verify_nonce($_GET['convert_vimeo'],'convert_vimeo') ) {
      $this->convert__process('Vimeo');
    }

    if( current_user_can('manage_options') && isset($_GET['refresh_vimeo']) && wp_verify_nonce($_GET['refresh_vimeo'],'refresh_vimeo') ) {
      FV_Player_Pro_Vimeo()->refresh_splash_and_durations();
    }
  }




  function convert__vimeo_callback( $content ) {
    $content = preg_replace( '~<iframe[^>]*?vimeo\.com/(?:video)/(\d+)[^>]*?></iframe>~', '[fvplayer src="https://vimeo.com/$1"]', $content );
    $content = preg_replace( '~<object.*?vimeo\.com/.*?clip_id=(\d+).*?</object>~', '[fvplayer src="http://vimeo.com/$1"]', $content );
    //var_dump($content);die(0);
    return $content;
  }




  function convert__youtube_callback( $content ) {
    $content = preg_replace( '~<iframe[^>]*?youtube(?:-nocookie)?\.com/(?:embed|v)/(.*?)[\'"&#\?][^>]*?></iframe>~', '[fvplayer src="http://youtube.com/watch?v=$1"]', $content );
    $content = preg_replace( '~<object.*?youtube(?:-nocookie)?\.com/(?:embed|v)/(.*?)[\'"&#].*?</object>~', '[fvplayer src="http://youtube.com/watch?v=$1"]', $content );
    //var_dump($content);die(0);
    return $content;
  }




  function disable_titles_vimeo_youtube( $aArgs ) {

    //  we don't want to avoid caption if it's set in lightbox anchor
    if( isset($aArgs['lightbox']) && $aArgs['lightbox'] ) {
      $aLightbox = preg_split('~[;]~', $aArgs['lightbox']);

      $bUseAnchor = false;
      foreach ($aLightbox AS $k => $i) {
        if ($i == 'text') {
          unset($aLightbox[$k]);
          $bUseAnchor = true;
        }
      }

      if( $bUseAnchor ) {
        return $aArgs;
      }
    }

    if( isset($aArgs['src']) && FV_Player_Pro_Vimeo()->is_vimeo($aArgs['src']) && $this->_get_option( array('pro','vimeo_titles_disable') )) {
      $aArgs['caption'] = '';
    } else if( isset($aArgs['src']) && $this->is_youtube($aArgs['src']) && $this->_get_option( array('pro','youtube_titles_disable') )) {
      $aArgs['caption'] = '';
    }
    return $aArgs;
  }




  function fv_player_admin_pro() {
    ?>
    <style>
      p.description { font-style: normal; }
    </style>
    <table class="form-table2" style="margin: 5px; ">
      <?php $this->_get_checkbox(__('Autoplay just once',            'fv-player-pro'), array('pro', 'autoplay_once'), __('Makes sure each video autoplays only once for each visitor per week. Uses a cookie.', 'fv-player-pro') ); ?>
      <?php $this->_get_checkbox(__('Custom start/end time',  'fv-player-pro'), array('pro', 'start_end'), __('Show only a smaller portion of your video.','fv-player-pro'),__('Not suitable as a content restriction, as the full video can be obtained by any skilled web developer and the feature doesn\'t work on iOS < 7 (use HLS and it will work on iOS 5 and 6) and Android < 4.', 'fv-player-pro') ); ?>
      <?php $this->_get_checkbox(__('Scroll autoplay',        'fv-player-pro'), array('pro', 'autoplay_scroll'), __('Automatically plays any video that comes into the browser viewport when scrolling down the page', 'fv-player-pro') ); ?>
      <?php $this->_get_checkbox(__('',                       'fv-player-pro'), array('pro', 'autoplay_scroll_enhanced'), __('Also work when scrolling up and do not stop autoplaying other videos on page if user paused some video', 'fv-player-pro') ); ?>
      <?php $this->_get_checkbox(__('AB Loop',                       'fv-player-pro'), array('pro', 'ab_loop'), __('Turn on AB Loop by default.', 'fv-player-pro') ); ?>
      <?php $this->_get_select(
                __('Debug', 'fv-player-pro'),
                array( 'pro', 'debug_log' ),
                __('Print debug messages to JS console.', 'fv-player-pro'),
                sprintf( __('With the Verbose setting Vimeo API calls and encrypted HLS playback is logged into %s.', 'fv-player-pro'), ABSPATH.'fv-player-debug-'.wp_hash('fv-player-debug-log','log').'.log' ),
                array(
                    'false' => __('Off' , 'fv-player-pro'),
                    'true'  => __('On' , 'fv-player-pro'),
                    'verbose'  => __('Verbose' , 'fv-player-pro')
                    )
                ); ?>

      <?php
      $status = '';
      if( $this->_get_option( array('pro', 'cf_ips_cron') ) ) {
        global $FV_Player_Pro_Cloudflare_Ip;
        $data = $FV_Player_Pro_Cloudflare_Ip->get_latest_cloudflare_ips();
        $data = json_decode( $data, true );
        $status = " Status: ".( !empty($data['success']) ? 'IP List obtained properly' : 'Failure' );

        $saved_data = get_option('fv_player_pro_cf_ips');
        if( $saved_data && !empty($saved_data['cloudflare_ips_expire']) ) {
          $status .= ", expires on ".date( 'F j, Y H:m:s', $saved_data['cloudflare_ips_expire'] );
        }
      }

      $this->_get_checkbox(__('Cloudflare support', 'fv-player-pro'), array('pro', 'cf_ips_cron'), __('Enable if your website is behind the Cloudflare WAF.', 'fv-player-pro').$status, __('Used to avoid IP spoofing. We detect the CF_CONNECTING_IP HTTP header.', 'fv-player-pro') );

      $this->_get_checkbox(__('Chapters below player', 'fv-player-pro'), array('pro', 'chapters_below_player'), __('Show VTT chapters below player.', 'fv-player-pro'), __('Normally the only show on timeline and in controlbar menu (in fullscreen).', 'fv-player-pro') );

      $this->_get_checkbox(__('Chromecast Encrypted HLS', 'fv-player-pro'), array('pro', 'chromecast_enc_hls'), __('Allow encrypted HLS streams to play via Chromecast. Lowers the video protection.', 'fv-player-pro') );

      $this->_get_checkbox(__('Cookie Protected Encrypted HLS', 'fv-player-pro'), array('pro', 'cookie_enc_hls'), __('Use cookies instead of IP addresses when serving HLS decryption keys.', 'fv-player-pro'), __('This lowers the video protection, but works with iCloud Private Relay or other VPNs that change IP addresses too often.', 'fv-player-pro') );
      ?>
      <tr>
        <td colspan="4">
          <a class="fv-wordpress-flowplayer-save button button-primary" href="#" style="margin-top: 2ex;"><?php _e('Save', 'fv-wordpress-flowplayer'); ?></a>
        </td>
      </tr>
    </table>
    <script>
    jQuery( function($) {
      var cb = jQuery('#pro\\[autoplay_scroll\\]').on( 'click', should_show_autoplay_scroll_enhanced );

      should_show_autoplay_scroll_enhanced();

      function should_show_autoplay_scroll_enhanced() {
        jQuery('#pro\\[autoplay_scroll_enhanced\\]').closest('tr').toggle( cb.prop('checked'));
      }
    });

    </script>
    <?php
  }




  function fv_player_admin_pro_cloudfront() {
    ?>
    <table class="form-table2" style="margin: 5px; ">
      <tr>
        <td colspan="2">
          <p>
            <?php _e('See Amazon Web Services Account -> Security Credentials -> CloudFront Key Pairs to get your key pair. We need the Access Key ID and the private key. See our <a href="http://foliovision.com/player/secure-amazon-s3-guide#cloudfront" target="_blank">CloudFront guide</a>.', 'fv-player-pro'); ?>
          </p>
        </td>
      </tr>
      <tr>
        <td style="padding-top: 8px; vertical-align: top"><label for="pro[cf_domain]"><?php _e('CloudFront domain', 'fv-player-pro'); ?>:</label></td>
        <td>
          <input type="text" size="40" name="pro[cf_domain]" id="pro[cf_domain]" value="<?php  echo $this->_get_option( array('pro','cf_domain') ); ?>" />
          <p class="description"><?php _e('Enter {something}.cloudfront.net or your mapped domain. You can enter both, separated by <code>,</code>.', 'fv-player-pro'); ?></p>
        </td>
      </tr>
      <tr>
        <td><label for="pro[cf_key_id]"><?php _e('Access Key ID', 'fv-player-pro'); ?>:</label></td>
        <td>
          <input type="text" size="40" name="pro[cf_key_id]" id="pro[cf_key_id]" value="<?php echo $this->_get_option( array('pro','cf_key_id') ); ?>" />
        </td>
      </tr>
      <tr>
        <td style="width: 250px; vertical-align: top"><label for="pro[cf_pk]"><?php _e('Private Key', 'fv-player-pro'); ?>:</label></td>
        <td>
          <?php
          $bShowPK = true;
          if( $this->_get_option( array('pro','cf_pk') ) ) : $bShowPK = false; ?>
            <?php _e('Your Private Key file is present', 'fv-player-pro'); ?> <?php echo openssl_get_privatekey($this->_get_option( array('pro','cf_pk') )) ? __('and appears to be valid', 'fv-player-pro') : __('but <strong>invalid</strong> (Make sure it contains both "BEGIN RSA PRIVATE KEY" and "END RSA PRIVATE KEY")', 'fv-player-pro'); ?>. <a href="#" onclick="jQuery('#pro\\[cf_pk\\]').show(); return false"><?php _e('Click', 'fv-player-pro'); ?></a><?php _e(' to put in a new one', 'fv-player-pro'); ?>.
          <?php endif; ?>
          <textarea id="pro[cf_pk]" name="pro[cf_pk]" class="large-text code" placeholder="<?php _e('Enter your Private Key file here.', 'fv-player-pro'); ?>"<?php if( !$bShowPK ) echo ' style="display: none"'; ?>></textarea>
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

  function fv_player_admin_pro_quality() {
    ?>
    <style>
      .fv-player-pro_quality-remove { visibility: hidden; }
      td:hover > .fv-player-pro_quality-remove { visibility: visible; }
    </style>
    <table class="form-table2" style="margin: 5px; ">
      <tr>
        <td colspan="2">
          <p class="description">
            <?php _e('If you upload your videos in multiple qualities like <code>my-video-hd.mp4</code>, <code>my-video-sd.mp4</code>, <code>my-video-mobile.mp4</code>, then enter the <code>-hd</code>, <code>-sd</code> and <code>-mobile</code> filename parts into the fields below together with the labels. Then enable the quality switching for the video in the [fvplayer] shortcode. See <a href="https://foliovision.com/player/switch-video-quality" target="_new">How to set up quality switching</a>', 'fv-player-pro'); ?>
          </p>
        </td>
      </tr>
      <tr>
        <td colspan="4">
          <table id="fv-player-pro_quality-settings">
            <thead>
              <tr>
                <td>
                  <?php _e('Naming scheme', 'fv-player-pro'); ?>
                </td>
                <td>
                  <?php _e('Label', 'fv-player-pro'); ?>
                </td>
                <td>

                </td>
              </tr>
            </thead>
            <tbody>
            <?php

            if( $aQualityItems = explode( "\n", $this->_get_option( array('pro', 'quality') ) ) ) {
              foreach( $aQualityItems AS $sQualityItem ) {
                $aQualityDetails = explode( ',', trim($sQualityItem) );
                $sName = isset($aQualityDetails[0]) ? trim($aQualityDetails[0]) : '';
                $sLabel = isset($aQualityDetails[1]) ? trim($aQualityDetails[1]) : '';
                echo "<tr class='data'><td><input type='text' name='aQualityItemNames[]' value='".esc_attr($sName)."' /></td><td><input type='text' name='aQualityItemLabel[]' value='".esc_attr($sLabel)."' /></td><td><a class='fv-player-pro_quality-remove' href=''>" . __('Remove', 'fv-player-pro') . "</a></td></tr>";
              }
            }

            ?>
            </tbody>
          </table>
        </td>
      </tr>
      <tr>
        <td colspan="4">
          <a class="fv-wordpress-flowplayer-save button button-primary" href="#"><?php _e('Save', 'fv-wordpress-flowplayer'); ?></a>
          <input type="button" value="<?php _e('Add quality settings', 'fv-player-pro'); ?>" class="button" id="fv-player-pro_quality-add" />
          <a class="button fv-help-link" href="https://foliovision.com/player/features/playback/quality-switching" target="_blank">Help</a>
        </td>
      </tr>
    </table>
    <input name="fv_player_admin_pro_quality_alive" type="hidden" value="1" />
    <script>
    jQuery('#fv-player-pro_quality-add').on("click", function() {
      var new_inputs = jQuery('#fv-player-pro_quality-settings tr.data:first').clone();
      new_inputs.find('input').attr('value','');
      //new_inputs.attr('class', new_inputs.attr('class') + '-' + fv_flowplayer_amazon_s3_count );
      new_inputs.insertAfter('#fv-player-pro_quality-settings tr:last');
      return false;
    } );

    jQuery(document).on('click','.fv-player-pro_quality-remove', false, function() {
      jQuery(this).parents('.data').remove();
      return false;
    } );

    jQuery(document).ready( function() {
      jQuery('#fv-player-pro_quality-settings tbody' ).sortable();
    } );
    </script>
    <?php
  }

  function fv_player_admin_pro_watching_prompt() {
    ?>
    <table class="form-table2" style="margin: 5px; ">
      <tr>
        <td colspan="2">
          <p class="description">
            <?php _e('Asks user to hit a button to continue watching a video to  save your bandwidth on long playback sessions.', 'fv-player-pro'); ?>
          </p>
        </td>
      </tr>
      <?php $this->_get_checkbox(__('Global Enable', 'fv-player-pro'), array('pro', 'watching_prompt') ); ?>
      <?php
    	$this->_get_select(
    						__('Interval', 'fv-player-pro'),
    						array( 'pro', 'watching_prompt_interval' ),
    						__('Moving a mouse or hitting a key will reset the counter.', 'fv-player-pro'),
    						false,
    						array(
    							  '1' => __('1 minute' , 'fv-player-pro'),
    							  '2' => __('2 minutes' , 'fv-player-pro'),
                    '3' => __('3 minutes' , 'fv-player-pro'),
                    '5' => __('5 minutes' , 'fv-player-pro'),
                    '10' => __('10 minutes' , 'fv-player-pro'),
                    '15' => __('15 minutes' , 'fv-player-pro'),
                    '30' => __('30 minutes' , 'fv-player-pro'),
                    '60' => __('1 hour' , 'fv-player-pro'),
                    '120' => __('2 hours' , 'fv-player-pro'),
                    '180' => __('3 hours' , 'fv-player-pro')
    							  )
    					   ); ?>
      <tr>
        <td><label for="pro[watching_prompt_msg]"><?php _e('Message', 'fv-player-pro'); ?>:</label></td>
        <td>
          <input type="text" size="40" name="pro[watching_prompt_msg]" id="pro[watching_prompt_msg]" value="<?php echo esc_attr($this->_get_option( array('pro','watching_prompt_msg') ) ); ?>" />
        </td>
      </tr>
      <tr>
        <td colspan="4">
          <a class="fv-wordpress-flowplayer-save button button-primary" href="#"><?php _e('Save', 'fv-wordpress-flowplayer'); ?></a>
        </td>
      </tr>
    </table>
    <?php
  }

  function fv_player_admin_pro_transcript() {
    ?>
    <table class="form-table2" style="margin: 5px; ">
      <?php
    	$this->_get_select(
    						__('Transcript Theme', 'fv-player-pro'),
    						array( 'pro', 'transcript_theme' ),
    						false,
    						false,
    						array(
    							  'light' => __('Light' , 'fv-player-pro'),
    							  'dark'  => __('Dark' , 'fv-player-pro'),
                    'boxy'  => __('Boxy' , 'fv-player-pro')
    							  )
    					   ); ?>
      <?php $this->_get_checkbox(__('Hidden by default', 'fv-player-pro'), array('pro', 'transcript_hidden'), __('Transcript only shows up after hitting the button in player control bar.', 'fv-player-pro') ); ?>
      <?php $this->_get_checkbox(__('Separate subtitle disabling', 'fv-player-pro'), array('pro', 'separate_subtitle_disabling'), __('The subtitles wont turn off when you enable the transcript') ); ?>
      <tr>
        <td colspan="4">
          <a class="fv-wordpress-flowplayer-save button button-primary" href="#"><?php _e('Save', 'fv-wordpress-flowplayer'); ?></a>
          <a class="button fv-help-link" href="https://foliovision.com/player/features/accessibility/interactive-video-transcript" target="_blank">Help</a>
        </td>
      </tr>
    </table>
    <?php
  }

  function fv_player_admin_pro_video_ads_description(){
   ?>
    <table class="form-table">
      <tr>
        <td colspan="4">
          <p>
            <?php _e('This feature lets You configure multiple, clickable Video Ads, that can be played before or after Your videos.', 'fv-player-pro'); ?>
          </p>
          <p>
            <?php _e('You can configure video ads globally, or on a per video basis.', 'fv-player-pro'); ?>
          </p>
        </td>
      </tr>
    </table>
<?php
  }

  function fv_player_admin_pro_video_ads() {
    global $fv_fp;
    ?>
    <style>
      #fv-player-pro_video-ads-settings tr.data:nth-child(even) { background-color: #eee; }
      .fv-player-pro_video-ad-remove { visibility: hidden; }
      td:hover > .fv-player-pro_video-ad-remove { visibility: visible; }
      table.fv-player-pro_video-ad-formats td:first-child { width: 132px }
      #fv_player_pro_video_ads input.smaller { width: 5em }
    </style>
    <table class="form-table2" style="margin: 5px; ">
      <tr>
        <td style="width: 250px"><label for="pro[video_ads_default]"><?php _e('Default pre-roll ad', 'fv-player-pro'); ?>:</label></td>
        <td>
          <p class="description">
            <?php $cva_id = $this->_get_option( array('pro','video_ads_default') ); ?>
            <?php $this->admin__select_video_ads( array('cva_id'=>$cva_id,'id'=>'pro[video_ads_default]') ); ?>
            <?php _e('Set which ad should be played before videos.', 'fv-player-pro'); ?>
          </p>
        </td>
      </tr>
      <tr>
        <td style="width: 250px"><label for="pro[video_ads_postroll_default]"><?php _e('Default post-roll ad', 'fv-player-pro'); ?>:</label></td>
        <td>
          <p class="description">
            <?php $cva_id = $this->_get_option( array('pro','video_ads_postroll_default') ); ?>
            <?php $this->admin__select_video_ads( array('cva_id'=>$cva_id,'id'=>'pro[video_ads_postroll_default]')); ?>
            <?php _e('Set which ad should be played after videos. If you mainly use YouTube, make sure you use a YouTube video for the ad to ensure best experience for iPhone users.', 'fv-player-pro'); ?>
          </p>
        </td>
      </tr>

      <?php $this->_get_checkbox(__('Pre-roll &amp; post-roll ads between videos', 'fv-player-pro'), array('pro', 'video_ads_between_vids'), __('Pre-roll and post-roll ads will be played when a playlist video changes.', 'fv-player-pro') ); ?>

      <tr>
        <td style="width: 250px"><label for="pro[video_ads_skip]"><?php _e('Default ad skip time', 'fv-player-pro'); ?>:</label></td>
        <td>
          <p class="description">
            <?php _e('Ad can be skipped after ', 'fv-player-pro'); ?>
            <input class="smaller" id="pro[video_ads_skip]" name="pro[video_ads_skip]" title="<?php _e('Enter value in seconds', 'fv-player-pro'); ?>" type="number" value="<?php echo $this->_get_option( array('pro','video_ads_skip') )?>" min="0" />
            <?php _e(' seconds if longer than ', 'fv-player-pro'); ?>
            <input class="smaller" id="pro[video_ads_skip_minimum]" name="pro[video_ads_skip_minimum]" title="<?php _e('Enter value in seconds', 'fv-player-pro'); ?>" type="number" value="<?php echo $this->_get_option( array('pro','video_ads_skip_minimum') )?>" min="0" />
            <?php _e(' seconds.', 'fv-player-pro'); ?>
          </p>
        </td>
      </tr>

      <?php $this->_get_checkbox(__('Limit ad playback', 'fv-player-pro'), array('pro', 'video_ads_once'), __('Each browser will get each ad only once per period configured below:', 'fv-player-pro') ); ?>

      <?php
      $this->_get_select(
        array(
          'name' => __( 'Limit ad playback period', 'fv-player-pro' ),
          'key'  => array( 'pro', 'video_ads_once_hours' ),
          'options' => array(
            3 =>  __( '3 hours', 'fv-player-pro' ),
            6 =>  __( '6 hours', 'fv-player-pro' ),
            12 => __( '12 hours', 'fv-player-pro' ),
            24 => __( '1 day', 'fv-player-pro' ),
            48 => __( '2 days', 'fv-player-pro' ),
          ),
          'default' => 24
        )
      ); ?>

      </table>
      <table class="form-table2" style="margin: 5px; ">
      <tr>
        <td>
          <table id="fv-player-pro_video-ads-settings">
            <thead><tr><td><?php _e('ID', 'fv-player-pro'); ?></td><td></td><td><?php _e('Status', 'fv-player-pro'); ?></td></tr></thead>
            <tbody>
            <?php
            if( !isset($fv_fp->conf['pro']['video_ads']) || !is_array($fv_fp->conf['pro']['video_ads']) || count($fv_fp->conf['pro']['video_ads']) == 0 ) {
              $fv_fp->conf['pro']['video_ads'] = array( array(
                                                'videos' => array(
                                                     'mp4' => '',
                                                     'webm' => '',
                                                     'ogv' => '',
                                                     'hls' => '',
                                                     'flash' => '',
                                                     'rtmp' => '',
                                                   ),
                                                'name' => '',
                                                'disabled' => false,
                                                'click' => false
                                                ) );
            }


            $i = 0;

            $videoAdPlayerIds = $this->_get_option( array('pro', 'video_ads_ids') );

            $videoAds = $this->func_get_db_video_ads();

            // add placehodler if there are no video ads
            if( empty($videoAds) ) {
              echo "\t<tr class='data'>\n";
              echo "\t\t<td class='id'>".(0+1)."</td>\n";
              echo "";
              echo "\t\t<td>\n";
              echo "\t\t\t<table class='fv-player-pro_video-ad-formats'>\n";
              echo "\t\t\t\t<tr><td><label>" . __('Name', 'fv-player-pro') . ":</label></td><td colspan='2'><input type='text' name='aVideoAd_name[".$i."]' value='".( '' )."' placeholder='" . __('Ad name', 'fv-player-pro') . "' /></td></tr>\n";
              echo "\t\t\t\t<tr><td><label>" . __('Click URL', 'fv-player-pro') . ":</label></td><td colspan='2'><input type='text' name='aVideoAdClick[".$i."]' value='' placeholder='" . __('Clicking the video ad will open the URL in new window', 'fv-player-pro') . "' /></td></tr>\n";
              echo "\t\t\t\t<tr><td><label>" . __('Video', 'fv-player-pro') . ":</label></td><td colspan='2'><input type='text' name='aVideoAd_mp4[".$i."]' value='' placeholder='" . __('Enter the video URL here', 'fv-player-pro') . "' /></td></tr>\n";
              echo "\t\t\t</table>\n";
              echo "\t\t</td>\n";
              echo "\t\t<td>\n";
              echo "\t\t\t<input type='hidden' name='aVideoAdDisabled[".$i."]' value='0' /><input id='VideoAdDisabled-".$i."' type='checkbox' name='aVideoAdDisabled[".$i."]' value='1' /> <label for='VideoAdDisabled-".$i."'>" . __('Disable', 'fv-player-pro') . "</label><br />\n";
              echo "\t\t\t<a class='fv-player-pro_video-ad-remove' href=''>" . __('Remove', 'fv-player-pro') . "</a></td>\n";
              echo "\t</tr>\n";
              $i++;
            } else {
              foreach( $videoAdPlayerIds as $key => $videoAdPlayerId ) {
                $player = new FV_Player_Db_Player( $videoAdPlayerId );

                $aVideos = $player->getVideos();

                if( empty( $aVideos ) ) {
                  continue;
                }

                $video = $aVideos[0];

                if(!$video) {
                  continue;
                }

                $name = method_exists( $video, 'getTitle' ) ? $video->getTitle() : $video->getCaption();
                $name = str_replace('Video Ad: ', '', $name );
                $src = $video->getSrc();
                $click = $video->getMetaValue( 'video_ad_click', true );
                $disabled = $video->getMetaValue( 'video_ad_disabled', true );

                echo "\t<tr class='data'>\n";
                echo "\t\t<td class='id'>".($key+1)."</td>\n";
                echo "";
                echo "\t\t<td>\n";
                echo "\t\t\t<table class='fv-player-pro_video-ad-formats'>\n";
                echo "\t\t\t\t<tr><td><label>" . __('Name', 'fv-player-pro') . ":</label></td><td colspan='2'><input type='text' name='aVideoAd_name[".$i."]' value='".( !empty($name) ? esc_attr($name) : '' )."' placeholder='" . __('Ad name', 'fv-player-pro') . "' /></td></tr>\n";
                echo "\t\t\t\t<tr><td><label>" . __('Click URL', 'fv-player-pro') . ":</label></td><td colspan='2'><input type='text' name='aVideoAdClick[".$i."]' value='".esc_attr($click)."' placeholder='" . __('Clicking the video ad will open the URL in new window', 'fv-player-pro') . "' /></td></tr>\n";
                echo "\t\t\t\t<tr><td><label>" . __('Video', 'fv-player-pro') . ":</label></td><td colspan='2'><input type='text' name='aVideoAd_mp4[".$i."]' value='".esc_attr($src)."' placeholder='" . __('Enter the video URL here', 'fv-player-pro') . "' /></td></tr>\n";
                echo "\t\t\t</table>\n";
                echo "\t\t</td>\n";
                echo "\t\t<td>\n";
                echo "\t\t\t<input type='hidden' name='aVideoAdDisabled[".$i."]' value='0' /><input id='VideoAdDisabled-".$i."' type='checkbox' name='aVideoAdDisabled[".$i."]' value='1' ".($disabled ? 'checked="checked"' : '')." /> <label for='VideoAdDisabled-".$i."'>" . __('Disable', 'fv-player-pro') . "</label><br />\n";
                echo "\t\t\t<input type='hidden' name='aVideoAdPlayerId[".$i."]' value='".$videoAdPlayerId."' />\n";
                echo "\t\t\t<a class='fv-player-pro_video-ad-remove' href=''>" . __('Remove', 'fv-player-pro') . "</a></td>\n";
                echo "\t</tr>\n";

                $i++;
              }
            }
            ?>
            <script>var fv_player_pro_cva_count = <?php echo ++$i; ?>;</script>
            </tbody>
          </table>
        </td>
      </tr>
      <tr>
        <td>
          <a class="fv-wordpress-flowplayer-save button button-primary" data-reload="true" href="#"><?php _e('Save', 'fv-wordpress-flowplayer'); ?></a>
          <input type="button" value="<?php _e('Add more video ads', 'fv-player-pro'); ?>" class="button" id="fv-player-pro_video-ads-add" />
        </td>
      </tr>
    </table>
    <script>
    var fv_player_pro_video_ads_count = '<?php echo $i; ?>';
    jQuery('#fv-player-pro_video-ads-add').on("click", function() {
      var new_inputs = jQuery('#fv-player-pro_video-ads-settings tr.data:first').clone();
      new_inputs.find('input[type=text]').attr('value','');
      new_inputs.find('td.id').html( fv_player_pro_cva_count );
      fv_player_pro_cva_count++;
      new_inputs.find('input[name]').each( function() {
        // check if name contains aVideoAdPlayerId and remove it to prevent duplicates
        if( jQuery(this).attr('name').indexOf('aVideoAdPlayerId') != -1 ) {
          jQuery(this).remove();
        }

        jQuery(this).attr( 'name', jQuery(this).attr('name').replace(/\[\d]$/, '['+fv_player_pro_video_ads_count+']' ) );
      });
      new_inputs.find('input[id]').each( function() {
        jQuery(this).attr( 'id', jQuery(this).attr('id').replace(/-\d$/, '-'+fv_player_pro_video_ads_count ) );
      });
      new_inputs.find('label[for]').each( function() {
        jQuery(this).attr( 'for', jQuery(this).attr('for').replace(/-\d$/, '-'+fv_player_pro_video_ads_count ) );
      });

      new_inputs.find('.fv-player-pro_extra-format').hide();
      new_inputs.insertAfter('#fv-player-pro_video-ads-settings tr.data:last');
      fv_player_pro_video_ads_count++;
      return false;
    } );

    jQuery(document).on('click','.fv-player-pro_video-ad-remove', false, function() {
      if( confirm('<?php _e('Are you sure you want to remove the video ad?', 'fv-player-pro'); ?>') )
      {
        jQuery(this).parents('.data').remove();
      }
      return false;
    } );

    jQuery(document).ready( function() {
      jQuery('#fv-player-pro_quality-settings tbody' ).sortable();
    } );
    </script>
    <?php
  }




  function fv_player_admin_pro_vimeo() {
    ?>
    <table class="form-table2" style="margin: 5px; ">
      <?php $this->_get_checkbox(__('Advanced Vimeo embedding', 'fv-player-pro'), array('pro', 'vimeo'), __('Use Vimeo as your video host and use all of FV Flowplayer features.', 'fv-player-pro') ); ?>

      <tr>
        <td><label for="pro[vimeo_at]"><?php _e('Access token', 'fv-player-pro'); ?>:</label></td>
        <td>
          <?php if( defined('FV_PLAYER_VIMEO_KEY') ) : ?>
            <input type="text" size="40" value="<?php _e('Defined as FV_PLAYER_VIMEO_KEY in your wp-config.php: ', 'fv-player-pro'); ?><?php echo FV_PLAYER_VIMEO_KEY; ?>" disabled="true" />
          <?php else : ?>
            <input type="text" size="40" name="pro[vimeo_at]" id="pro[vimeo_at]" value="<?php echo $this->_get_option( array('pro','vimeo_at') ); ?>" />
          <?php endif; ?>
        </td>
      </tr>

      <tr>
        <td style="padding-top: 15px; vertical-align: top; width: 250px"><label>Status:</label></td>
        <td>
            <?php
            if( $this->get__vimeo_key() ) {
              $result = FV_Player_Pro_Vimeo()->admin_key_check_cache();
              if( !empty($result['error']) ) : ?>
                <p style="background: #f88"><?php
                _e('Your Vimeo access token is invalid: ', 'fv-player-pro'); ?></p>
                <p><?php
                if( !empty($result['error_code']) && $result['error_code'] == 8003 ) {
                  echo "<strong>The user credentials are invalid.</strong> ";
                } else {
                  echo $result['error'].' ';
                }
                ?> (<?php _e('last check', 'fv-player-pro'); ?>: <?php echo date('r', $result['time']); ?>)</p>
              <?php else : ?>
                <p><?php _e('Your Vimeo access token has been successfully verified (last check', 'fv-player-pro'); ?>: <?php echo date('r',$result['time']); ?>)</p>

                <?php
                $errors = get_option('fv_player_vimeo_errors', array() );
                if( count($errors) > 0 ) {
                  $last = end($errors);
                  echo '<p>'.sprintf( __(' There were %s API errors, last one from %s.', 'fv-player-pro'), count($errors), $last['date'] ).' <a href="#" class="fv-player-vimeo-errors-trigger">(show)</a></p>';
                  echo "<ul class='fv-player-vimeo-errors' style='display: none'>\n";
                  foreach( $errors AS $error ) {
                    echo "<li>".$error['date'].": Error for video ".$error['id'].": ".$error['error']."</li>\n";
                  }
                  echo "</ul>\n";
                  ?>
                  <script>
                  jQuery('.fv-player-vimeo-errors-trigger').on("click", function(e) {
                    jQuery(this).remove();
                    jQuery('.fv-player-vimeo-errors').show();
                    return false;
                  });
                  </script>
                <?php
                }

                $error = false;

                global $wpdb;
                if( $this->_get_option( array('pro','vimeo_direct_ajax') ) ) {
                  if( file_exists( WP_CONTENT_DIR . '/cache/fv-player-vimeo' ) ) {
                    $files = scandir(WP_CONTENT_DIR . '/cache/fv-player-vimeo');

                    $cache_items = 0;
                    foreach( $files AS $file ) {
                      if( stripos( $file, 'fv_player_pro_vimeo_' ) === 0 ) $cache_items++;
                    }
                  } else {
                    $error = '<strong>Error</strong>: Folder <code>'.WP_CONTENT_DIR . '/cache/fv-player-vimeo</code> not found, please disable "Turbocharge Ajax Vimeo loading" if the Vimeo videos do not play.';
                  }
                } else {
                  $cache_items = $wpdb->get_var("SELECT count(*) FROM $wpdb->options WHERE option_name LIKE 'fv_player_pro_vimeo_%' ");
                }

                if( $error ) {
                  echo "<p>$error</p>";

                } else {
                  echo "<p>";
                  _e('Currently ', 'fv-player-pro');
                  echo $cache_items.( ( $cache_items == 1 ) ? __(' Vimeo video is cached.', 'fv-player-pro') : __(' Vimeo videos are cached.', 'fv-player-pro') );
                  echo "</p>";
                  if( isset($_GET['debug_log']) ) {
                    echo "Servers: ";
                    print_r( get_option('fv_player_pro_api') );
                  }
                }

              endif;
            }
            ?>
        </td>
      </tr>

  <?php if( $this->_get_option( array('pro','vimeo_titles_disable') ) ) $this->_get_checkbox(__('Disable video captions',     'fv-player-pro'), array('pro', 'vimeo_titles_disable'), __('Normally the video title is parsed into the shortcode when saving the post, with this setting it won\'t appear.', 'fv-player-pro') ); ?>

  <?php
  if( $this->_get_option( array('pro','vimeo_iframe') ) ) {
   $this->_get_checkbox(__('Force <code>iframe</code> Embedding',  'fv-player-pro'), array('pro', 'vimeo_iframe'), __('If you experience issues with our Vimeo integration you can enable this option temporarily to fix playback of at least single videos. Playlists and lightbox are not supported.', 'fv-player-pro') );
  }
  ?>

      <tr>
        <td style="width: 250px; vertical-align: top; padding-top: 5px"><label for="pro[vimeo_direct_ajax]"><?php _e('Turbocharge Ajax Vimeo loading', 'fv-player-pro'); ?>:</label></td>
        <td>
          <div>
            <p class="description">
              <input type="hidden" value="false" name="pro[vimeo_direct_ajax]" />
              <input type="checkbox" value="true" name="pro[vimeo_direct_ajax]" id="pro[vimeo_direct_ajax]"
                <?php if( defined('FV_PLAYER_VIMEO_KEY') && $this->_get_option( array('pro','vimeo_direct_ajax') ) ) echo 'checked="checked"'; ?>
                <?php if( !defined('FV_PLAYER_VIMEO_KEY') ) echo 'disabled="disabled"'; ?> />
              <?php _e('Vimeo requests will be handled by a simple PHP file rather than loading entire WordPress.', 'fv-player-pro'); ?>
              <a href="#" class="show-more">(&hellip;)</a>
            </p>
            <?php

            $key = $this->get__vimeo_key();

            ?>
            <p class="more" style="display: none">
              <?php _e('To use this feature you have to put the following into your wp-config.php file:', 'fv-player-pro'); ?>

              <?php if( $key ) : ?>
                <br /><code>define('FV_PLAYER_VIMEO_KEY','<?php echo $key; ?>');</code>
                <?php echo defined('FV_PLAYER_VIMEO_KEY') && FV_PLAYER_VIMEO_KEY == $key ? 'Found!' : '<strong>Missing!</strong>'; ?>
              <?php endif; ?>

              <?php do_action('fv_player_admin_pro_vimeo_turbo_check'); ?>

              <br /><?php _e('Please check your video playback carefully after enabling.', 'fv-player-pro'); ?>

            </p>
          </div>
        </td>
      </tr>

      <?php do_action('fv_player_admin_pro_vimeo_after'); ?>

      <tr>
        <td colspan="4">
          <a class="fv-wordpress-flowplayer-save button button-primary" href="#" style="margin-top: 2ex;" data-reload="true"><?php _e('Save', 'fv-wordpress-flowplayer'); ?></a>
          <input type="button" class="button" value="<?php _e('Convert Vimeo embed codes', 'fv-player-pro'); ?>" style="margin-top: 2ex;" onclick="if( confirm('<?php _e('This converts the IFRAME and OBJECT Vimeo embeds in post content and post meta into [fvplayer] shortcodes.\n\n Please make sure you backup your database before continuing.', 'fv-player-pro'); ?>') ) location.href='<?php echo wp_nonce_url( admin_url( 'options-general.php?page=fvplayer' ), 'convert_vimeo', 'convert_vimeo'); ?>'; "/>

          <input type="button" class="button" value="<?php _e('Refresh splash screens and durations', 'fv-player-pro'); ?>" style="margin-top: 2ex;" onclick="if( confirm('<?php _e('The automated Vimeo splash screens and video duration data will be removed and then fetched using background jobs.\n\n Please make sure you backup your database before continuing.', 'fv-player-pro'); ?>') ) location.href='<?php echo wp_nonce_url( admin_url( 'options-general.php?page=fvplayer' ), 'refresh_vimeo', 'refresh_vimeo'); ?>'; "/>
          <a class="button fv-help-link" style="margin-top: 2ex;" href="https://foliovision.com/player/video-hosting/how-to-use-vimeo" target="_blank">Help</a>
        </td>
      </tr>
    </table>
    <?php
  }




  function func_get_db_video_ads() {
    $video_ad_ids = $this->_get_option( array('pro', 'video_ads_ids') );

    $video_ads = array();

    if( !$video_ad_ids || count($video_ad_ids) === 0 ) {
      return $video_ads;
    }

    foreach( $video_ad_ids AS $key => $video_ad_id ) {
      $player = new FV_Player_Db_Player( $video_ad_id );
      $videos = $player->getVideos();
      $video = $videos[0];

      if( $video && strlen(trim($video->getSrc())) ) {
        $video_ads[] = $video;
      }
    }

    return $video_ads;
  }


  function func__get_qualities() {

    $quality = $this->_get_option( array('pro','quality') );

    if( strlen(trim($quality)) == 0 ) {
      return false;
    }

    $aOutput = false;
    $aQualities = explode( "\n", $quality );
    $aQualities = array_map('trim', $aQualities);
    foreach( $aQualities AS $key => $item ) {
      $aItem = explode( ',', trim($item) );
      $aItem = array_map('trim', $aItem);
      if( strlen($aItem[0]) && is_array($aItem) && count($aItem) >= 2 ) {
        if( !is_array($aOutput) ) $aOutput = array();
        $aOutput[$aItem[0]] = $aItem[1];
      }
    }

    return $aOutput;
  }




  function func__get_video_ads() {
    $aVideoAdds = $this->_get_option( array('pro','video_ads_ids') );

    if( !$aVideoAdds || count($aVideoAdds) === 0 ) {
      return false;
    }

    $availableVideoAds = array();

    foreach( $aVideoAdds AS $k => $i ) {
      $player = new FV_Player_Db_Player( $i );
      $videos = $player->getVideos();
      $video = $videos[0];

      if( $video && strlen(trim($video->getSrc())) ) {
        $availableVideoAds[] = $video;
      }
    }

    return $availableVideoAds;
  }




  public function get_client_ip() {
    // Cloudflare ip check
    if( isset($_SERVER["HTTP_CF_CONNECTING_IP"]) ) {
      if( $this->_get_option( array('pro', 'cf_ips_cron') ) ) {
        global $FV_Player_Pro_Cloudflare_Ip;
        return $FV_Player_Pro_Cloudflare_Ip->verify_cf_connecting_ip();
      } else {
        return $_SERVER["HTTP_CF_CONNECTING_IP"];
      }
    }

    return $_SERVER['REMOTE_ADDR'];
  }




  function get_cloudfront_secure( $media ) {
    $aArgs = func_get_args();
    $aArgs = $aArgs[1];

    if( !($aArgs instanceof flowplayer_frontend) && isset($aArgs['dynamic']) && $aArgs['dynamic'] ) {
      $media = $this->get_cloudfront_secure_url($media, apply_filters('fv_player_secure_link_timeout', 900), isset($aArgs['flash']) && $aArgs['flash'] ? true : false );
    }

    return $media;
  }




  function get_cloudfront_secure_long( $resource ) {
    $resource = $this->get_cloudfront_secure_url($resource );
    return $resource;
  }




  function get_cloudfront_secure_url( $media, $time = 172800, $flash = false ) {

    // skip URLs which already have the signature added
    $parsed = parse_url($media);
    if( !empty($parsed['query']) )  {
      parse_str( $parsed['query'], $query );
      if( !empty($query['Key-Pair-Id']) && !empty($query['Signature']) && !empty($query['Expires']) ) {
        return $media;
      }
    }

    if( $this->_get_option( array('pro','cf_key_id') ) && $this->_get_option( array('pro','cf_pk') ) ) {
      $bIsCloudFrontDomain = false;
      $aDomains = explode( ',', $this->_get_option( array('pro','cf_domain') ) ); //  todo: test!
      if( count($aDomains) > 0 ) {
        foreach( $aDomains AS $sDomain ) {
          if( stripos($media, trim($sDomain) ) !== false ) {
            $bIsCloudFrontDomain = true;
          }
        }
      }

      if( !$bIsCloudFrontDomain && !$this->is_rtmp($media) ) {
        return $media;
      }

      if( !$this->is_rtmp($media) ) {
        $media = str_replace( ' ', '%20', $media );
      }

      if( isset($_REQUEST['fvpexpirelow']) ) {
        $time = 5;
      }

      $keyPairId = $this->_get_option( array('pro','cf_key_id') );
      $expires = time() + $time;  //  todo: use video duration

      $media_prepared = $this->strip_rtmp_ext($media);

      //$sURL = str_replace(array('+', '=', '/'), array('-', '_', '~'), $sURL);
      /*$sURL = str_replace('%2F', '/', $sURL);
      $sURL = str_replace('%2B', '+', $sURL);*/
      $json = '{"Statement":[{"Resource":"'.$media_prepared.'","Condition":{"DateLessThan":{"AWS:EpochTime":' . $expires . '}}}]}';

      // create the private key
      $key = openssl_get_privatekey($this->_get_option( array('pro','cf_pk') ));
      if( $key ) {
        openssl_sign($json, $signed_policy, $key);

        unset($key);

        $base64_signed_policy = base64_encode($signed_policy);
        $signature = str_replace(array('+', '=', '/'), array('-', '_', '~'), $base64_signed_policy);

        if( $flash ) {
          $media = str_replace( '+', '%2B', $media);
        }
        $media = $media . '?Key-Pair-Id=' . $keyPairId . '&Signature=' . $signature . '&Expires=' . $expires;
      }
    }

    return $media;
  }




  function get__vimeo_key() {
    if( defined('FV_PLAYER_VIMEO_KEY') ) {
      return FV_PLAYER_VIMEO_KEY;
    }

    if( $this->_get_option( array('pro','vimeo_at') ) ) {
      return $this->_get_option( array('pro','vimeo_at') );
    }

    return false;
  }




	function get__XML_value( $path, $xml, $number = 0 ) {
		$aItems = array();
		foreach( $xml->xpath( $path ) AS $key => $duration ) {
			$xmlDuration = simplexml_load_string($duration->asXML());
			if( $number === 0 ) {
				return (string)$xmlDuration;
			} else {
				$aItems[] = (string)$xmlDuration;
			}
		}

		if( $aItems ) {
			return $aItems;
		}

		return false;
	}




  function hflip_button($aButtons) {
    global $fv_fp;
    if( isset($fv_fp->aCurArgs['hflip']) ) {
      $aButtons[] = "<ul class='fv-player-hflip'><li><a href='#'>" . __('Flip', 'fv-player-pro') . "</a></li></ul>";
    }
    return $aButtons;
  }




  public static function hms_to_seconds( $hms ) {
    // change , decimal separator to .
    $hms = str_replace( ',' , '.', $hms );

    if( is_numeric($hms) ) {
      return $hms;
    }

    if (preg_match_all('/([0-9.,]+)/', $hms, $parts)) {
      if (isset($parts[0][0]) && isset($parts[0][1]) && isset($parts[0][2])) { // example: 05:20:11.600 or 05:20:11
        $seconds = 3600 * $parts[0][0]; // hour
        $seconds += 60 * $parts[0][1]; // minute
        $seconds += $parts[0][2]; // second
      } else if (isset($parts[0][0]) && isset($parts[0][1])) { // example: 20:11.600 or 05:20
        $seconds = 60 * $parts[0][0]; // minute
        $seconds +=  $parts[0][1]; // second
      }
    }

    return $seconds;
  }




  function interface_options() {
    $this->_get_checkbox(__('AB Loop',            'fv-player-pro'), array('interface','ab'), __('Adds shortcode editor option for AB loop function', 'fv-player-pro') );
    $this->_get_checkbox(__('Chapters',    'fv-player-pro'), array('pro','interface','chapters'), __('Supports VTT chapters.', 'fv-player-pro') );
    $this->_get_checkbox(__('Download',    'fv-player-pro'), array('pro','interface','download') );
    $this->_get_checkbox(__('DRM Text',    'fv-player-pro'), array('pro','interface','copy_text') );
    $this->_get_checkbox(__('Horizontal Flip',    'fv-player-pro'), array('pro','interface','hflip'), __('Adds shortcode editor option for a user button to flip video horizontally.', 'fv-player-pro') );
    $this->_get_checkbox( __( 'Randomize Autoplay Playlist', 'fv-player-pro' ), array('pro','interface','randomize'), __( 'Playlist will autoplay random video at a random time.', 'fv-player' ) );
    $this->_get_checkbox( __( 'Refresh Random Video Button', 'fv-player-pro' ), array('pro','interface','randomize_button'), __('Adds a button to let viewer go to another random video in playlist.', 'fv-player') );
  }




  function ios_expiration( $ttl ) {
    return 4*3600;
  }




  function is_dynamic_item( $sURL ) { //  todo: check!
    if( $this->_get_option( array('pro','cf_domain') ) && $this->_get_option( array('pro','cf_key_id') ) && $this->_get_option( array('pro','cf_pk') )){
      $aDomains = explode( ',', $this->_get_option( array('pro','cf_domain') ) ); //  todo: test!
      if( count($aDomains) > 0 ) {
        foreach( $aDomains AS $sDomain ) {
          if( stripos($sURL, trim($sDomain) ) !== false ) {
            return true;
          }
        }
      }

    }
    return false;
  }




  function is_rtmp( $sURL ) {
    return preg_match( '~^(mp4|flv):~', $sURL );
  }




  // Provided for compatibility reasons
  public function is_vimeo( $sURL ) {
    return FV_Player_Pro_Vimeo()->is_vimeo( $sURL );
  }




  // Provided for compatibility reasons
  public function is_vimeo_event( $url ) {
    return FV_Player_Pro_Vimeo()->is_vimeo_event( $url );
  }


  function editor_player_options( $player_options ) {
    global $fv_fp;

    $player_options['controls']['items'][] = array(
      'label' => __('AB Loop', 'fv-player-pro'),
      'name' => 'ab',
      'description' => __('Allow user to loop part of the video.', 'fv-wordpress-flowplayer'),
      'default' => $this->_get_option( array( "pro", "ab_loop" ) ),
      'dependencies' => array( 'controlbar' => true ),
      'visible' => $this->_get_option( array( "interface", "ab" ) )
    );

    $player_options['controls']['items'][] = array(
      'label'        => __('Horizontal Flip', 'fv-player-pro'),
      'name'         => 'hflip',
      'description' => __('Adds a button to let viewer flip the video horizontally.', 'fv-wordpress-flowplayer'),
      'dependencies' => array( 'controlbar' => true ),
      'visible'      => $this->_get_option( array( "pro", "interface", "hflip" ) )
    );

    $player_options['controls']['items'][] = array(
      'label'        => __('Randomize Autoplay Playlist', 'fv-player-pro'),
      'name'         => 'randomize',
      'description'  => __( 'Playlist will autoplay random video at a random time.', 'fv-player' ),
      'player_meta'  => true,
      'scope'        => 'playlist',
      'visible'      => $this->_get_option( array( "pro", "interface", "randomize" ) )
    );

    $player_options['controls']['items'][] = array(
      'label'        => __('Refresh Random Video Button', 'fv-player-pro'),
      'name'         => 'randomize_button',
      'description'  => __('Adds a button to let viewer go to another random video in playlist.', 'fv-player'),
      'scope'        => 'playlist',
      'player_meta'  => true,
      'visible'      => $this->_get_option( array( "pro", "interface", "randomize_button" ) )
    );

    $player_options['controls']['items'][] = array(
      'label'        => __('Quality Switching', 'fv-player-pro'),
      'name'         => 'qsel',
      'description' => __('Enable if your MP4 video is available in different qualities.', 'fv-wordpress-flowplayer'),
      'dependencies' => array( 'controlbar' => true ),
      'visible'      => $this->func__get_qualities()
    );

    $player_options['general']['items'][] = array(
      'label'   => __('DRM Text', 'fv-player-pro'),
      'name'    => 'copy_text',
      'visible' => $this->_get_option( array( "pro", "interface", "copy_text" ) )
    );

    return $player_options;
  }


  public function esc_shortcode_arg($arg) {
    return str_replace( array(';','[',']'), array(';','(',')'), htmlspecialchars($arg) );
  }




  function is_youtube( $sURL ) {
    $result = FV_Player_Pro_YouTube()->is_youtube( $sURL );

    if( $result) {
      $this->bYoutube = true;
      return $result;
    }

    return false;
  }




  function is_wistia( $sURL ) {
    global $fv_fp;
    if ($fv_fp->_get_option('wistia_use_fv_player')) {
      $check = preg_match( "~wistia.(com|net)/(embed|medias)/.*~i", $sURL, $aDynamic );
      if ( $check ) {
        $this->bWistia = true;
      }

      return $check;
    } else {
        return false;
    }
  }




  function low_expiration( $ttl ) {
    return 5;
  }





  function quality_attributes( $aAttributes ) {
    global $fv_fp;

    $aArgs = func_get_args();
    if( isset($aArgs[2]->aCurArgs['src']) && FV_Player_Pro_Vimeo()->is_vimeo($aArgs[2]->aCurArgs['src']) ) {
      $aAttributes['data-qsel'] = implode( ',', array_keys($this->aVimeoQualities) );
      $aAttributes['data-qlabels'] = implode( ',', $this->aVimeoQualities );

    } else if( isset($aArgs[2]->aCurArgs['qsel']) && $aQualities = $this->func__get_qualities() ) {
      $aAttributes['data-qsel'] = implode( ',', array_keys($aQualities) );
      $aAttributes['data-qlabels'] = implode( ',', $aQualities );

    }

    return $aAttributes;
  }




  function quality_media( $aItem ) {
    $aArgs = func_get_args();

    if( !isset($aArgs[2]['qsel']) || !$aQualities = $this->func__get_qualities() ) return $aItem;

    $aQualityMedia = array();
    foreach($aItem['sources'] AS $i => $aSource ) {
      $sDefaultQuality = false;
      foreach( $aQualities AS $key => $item ) {
        if( stripos($aSource['src'], (string)$key) ) {
          $sDefaultQuality = (string)$key;
          break;
        }
      }

      $qualities = array();
      foreach( $aQualities AS $key => $item ) {
        if( $sDefaultQuality == $key ) continue;

        // we remove the URL query parameters as the URL token/signature will have to be recalculated
        $src = explode( '?', $aSource['src'] );

        // we change the URL to target the new quality
        $src = str_replace( $sDefaultQuality, (string)$key, $src[0] );

        // we apply the URL token/signature etc. here
        $src = apply_filters( 'fv_flowplayer_video_src', str_replace( $sDefaultQuality, (string)$key, $src ), array() );

        $qualities[] =  array(
          'src' => $src,
          'type' => $aSource['type']
          );
      }

      if( count($qualities) ) {
        $qualities = array_merge( array($aSource), $qualities );
        $aItem['sources'][$i]['sources_fvqs'] = $qualities;
      }
    }

    return $aItem;
  }

  function pointer_boxes() {
    global $fv_fp;

    if( !empty($fv_fp) && get_option('fv_player_pro_vimeo_splash_notice') && !$fv_fp->_get_option( array( 'notices', 'fv_player_pro_vimeo_splash_notice' ) ) ) {
      $fv_fp->pointer_boxes['fv_player_pro_vimeo_splash_notice'] = array(
        'id' => '#wp-admin-bar-new-content',
        'pointerClass' => 'fv_player_pro_vimeo_splash_notice',
        'heading' => __('FV Player Pro', 'fv-player-pro'),
        'content' => get_option('fv_player_pro_vimeo_splash_notice'),
        'position' => array( 'edge' => 'top', 'align' => 'center' ),
        'button1' => __('Dismiss', 'fv-player-pro')
      );
    }
  }

  function fetch_vimeo_yt_data($video, $post_id = false, $videoObj = false) {
    // must be url string
    if( !is_string($video) ) {
      return $video;
    }

    // If it's Vimeo Event, we need to obtain the actual video ID
    if( $event_id = FV_Player_Pro_Vimeo()->is_vimeo_event($video) ) {
      $video = FV_Player_Pro_Vimeo()->get_vimeo_event($video);
    }

    if ( FV_Player_Pro_Vimeo()->is_vimeo($video) && $this->get__vimeo_key() && function_exists('curl_init') ) {

      $fv_flowplayer_meta = false;
      if( $post_id ) {
        $fv_flowplayer_meta = get_post_meta($post_id, flowplayer::get_video_key($video), true);
        if( !$fv_flowplayer_meta || !isset($fv_flowplayer_meta['date']) || ( $fv_flowplayer_meta['date'] + 3600 ) < time() ) {
          $fv_flowplayer_meta = false;
        }
      }

      if( !$fv_flowplayer_meta ) {
        $tStart = microtime(true);
        $vimeo_id = FV_Player_Pro_Vimeo()->get_vimeo_id($video);

        try {
          // If we request the specific fields, SDK will set the Accept field in HTTP requiest and Vimeo API will give back splash images which respect the video aspect ratio
          $result = FV_Player_Pro_Vimeo::request('/videos/' . intval( $vimeo_id ) . '?fields=name,duration,files,width,height,pictures' );
        } catch( Exception $e ) {}

        FV_Player_Pro_Vimeo()->log_details( " getting splash for: ".$vimeo_id.", on ".$_SERVER['REQUEST_URI']."\n", $result );

        if (!isset($result['body'])) {
          return false;
        }

        $fv_flowplayer_meta = ($fv_flowplayer_meta) ? $fv_flowplayer_meta : array();

        $aVimeoInfo = $result['body'];

        if( !empty($aVimeoInfo['error']) ) {
          $msg = $aVimeoInfo['error'];
          if( !empty($aVimeoInfo['developer_message']) ) {
            $msg .= ' '.$aVimeoInfo['developer_message'];
          }

          if( !empty($aVimeoInfo['invalid_parameters']) && !empty($aVimeoInfo['invalid_parameters'][0]) && !empty($aVimeoInfo['invalid_parameters'][0]['error']) ) {
            $msg .= "; ".$aVimeoInfo['invalid_parameters'][0]['error'];
          }

          $fv_flowplayer_meta['error'] = $msg;
          FV_Player_Pro_Vimeo()->log_error( $video, $msg );
        }

        $fv_flowplayer_meta = array();
        $fv_flowplayer_meta['duration'] = isset($aVimeoInfo['duration']) ? $aVimeoInfo['duration'] : false;
        $fv_flowplayer_meta['width'] = isset($aVimeoInfo['width']) ? $aVimeoInfo['width'] : false;
        $fv_flowplayer_meta['height'] = isset($aVimeoInfo['height']) ? $aVimeoInfo['height'] : false;

        if ( ! empty( $aVimeoInfo['files'] ) ) {
          $max_width = 0;
          $max_key = 0;
          foreach( $aVimeoInfo['files'] as $file_key => $file ) {
            if ( ! empty( $file['width'] ) && $file['width'] > $max_width ) {
              $max_width = $file['width'];
              $max_key = $file_key;
            }
          }

          if ( $max_width > $aVimeoInfo['width'] ) {
            $fv_flowplayer_meta['width'] = $aVimeoInfo['files'][ $max_key ]['width'];
            $fv_flowplayer_meta['height'] = $aVimeoInfo['files'][ $max_key ]['height'];
          }
        }

        if( isset($aVimeoInfo['pictures']['sizes']) ) {
          $iCount = count($aVimeoInfo['pictures']['sizes']);
          $fv_flowplayer_meta['splash'] = ( isset($aVimeoInfo['pictures']['sizes'][$iCount-1]) ) ? $aVimeoInfo['pictures']['sizes'][$iCount-1]['link'] : false;
        } else {
          $fv_flowplayer_meta['splash'] = ( isset($aVimeoInfo['pictures'][0]) ) ? $aVimeoInfo['pictures'][0]['link'] : false;
        }
        $fv_flowplayer_meta['caption'] = isset($aVimeoInfo['name']) ? $aVimeoInfo['name'] : false;
        $fv_flowplayer_meta['date'] = time();
        $fv_flowplayer_meta['check_time'] = microtime(true) - $tStart;

        // Remove the black bars if the video is not 16:9
        $fv_flowplayer_meta['splash'] = str_replace( array( '?r=pad', '?&r=pad' ), '', $fv_flowplayer_meta['splash'] );

        if ($post_id) {
          update_post_meta($post_id, flowplayer::get_video_key($video), $fv_flowplayer_meta);
        }
      }

      $videoData = false;
      if( $fv_flowplayer_meta['caption'] && $fv_flowplayer_meta['splash'] ) {
        $videoData = array(
            'name'      => $fv_flowplayer_meta['caption'],
            'thumbnail' => $fv_flowplayer_meta['splash'],
            'duration'  => $fv_flowplayer_meta['duration'],
            'width'     => $fv_flowplayer_meta['width'],
            'height'    => $fv_flowplayer_meta['height'],
        );

        // get chapters
        $objVimeo = FV_Player_Pro_Vimeo()->get_vimeo($video);
        if( !empty($objVimeo->chapters) ) {

          // parse, save and retrieve chapters url if video does not have chapters
          if ( ! $videoObj || ! method_exists( $videoObj, 'getMetaValue' ) || ! $videoObj->getMetaValue( 'chapters', true ) ) {
            $videoData['chapters'] = FV_Player_Pro_Vimeo()->save_chapters($objVimeo->chapters, $fv_flowplayer_meta['caption']);
          }

        }

      } else if( !empty($fv_flowplayer_meta['error']) ) {
        return $fv_flowplayer_meta;
      }

      return $videoData;

    } else {
      return $video; // no vimeo or yt, pass to another filter
    }
  }


  function save_post($sPost) {
    $post_id = get_the_ID();

    if (!method_exists('FV_Player_Checker', 'get_videos') || (!$this->get__vimeo_key() && !$this->_get_option( array('pro','youtube_key') ))) {
      return $sPost;
    }

    $aShortcodes = array();
    $aShortcodesNew = array();
    preg_match_all('/\[fvplayer[^\]]*\]/', $sPost, $aShortcodes);

    $aMeta = get_post_custom($post_id);
    if( $aMeta && is_array($aMeta) && count($aMeta) > 0) {
      $meta_values = '';
      foreach( $aMeta AS $values ) {
        $meta_values .= implode('', $values);
      }

      if( preg_match_all( '~\[(?:flowplayer|fvplayer).*?\]~', $meta_values, $meta_matches ) ) {
        $aShortcodes[0] = array_merge($aShortcodes[0], $meta_matches[0]);
      }
    }

    foreach ($aShortcodes[0] AS $key => $shortcode) {
      $bChanged = false;
      $shortcode = preg_replace('/\\"]/', '" ]', $shortcode);
      $shortcode = stripslashes($shortcode);

      // some shortcode attributes are not save for shortcode, so base64 on them
      $shortcode = preg_replace_callback( '!(?:ad|popup)="(.*?[^\\\])"!', array( $this, 'shortcode_attr_escape' ), $shortcode ); // the " at the end of the match must not be proceeded by \ !

      $attrs = shortcode_parse_atts($shortcode);
      if( !isset($attrs['src']) ) continue;

      $videoData = $this->fetch_vimeo_yt_data($attrs['src'], $post_id); //  we run this even if splash is not going to change as we might need to get new data, such as duration. The function only makes the HTTP call once per hour.

      // If you have no splash or old Vimeo splash URL, you will need a new one
      $need_new_splash = empty($attrs['splash']) || preg_match( '~//i.vimeocdn.com/video/[0-9]+_~', $attrs['splash'] );
      if ($need_new_splash && !empty($videoData['thumbnail'])) {
        $attrs['splash'] = $this->esc_shortcode_arg($videoData['thumbnail']);
        $bChanged = true;
      }

      if (isset($attrs['playlist'])) {
        $attrs['playlist'] = explode(';', $attrs['playlist']);
        foreach ($attrs['playlist'] AS $playlistkey => $sItem) {
          $aItem = explode(',', $sItem);

          $videoData = $this->fetch_vimeo_yt_data($aItem[0], $post_id);

          // Take last item from the shortcode playlist format (src,src1,src2,splash;)
          $current_splash = $aItem[count($aItem) - 1];
          // Ignore it if it's not...
          if(
            // with an image file extension
            !preg_match( '~\.(jpg|jpe|jpeg|png|gif)($|\?)~', $current_splash ) &&
            // domain often used for images without extension (Vimeo, Odysee)
            !preg_match( '~//(i|img|thumb|thumbs|thumbnails)\.~', $current_splash )
          ) {
            $current_splash = false;
          }

          // If there is no splash image or it's the old Vimeo splash URL we need a new one
          $need_new_splash = !$current_splash || preg_match( '~//i.vimeocdn.com/video/[0-9]+_~', $current_splash );
          if ($need_new_splash && !empty($videoData['thumbnail']) ) {
            if( $current_splash ) { // replace existing splash
              $attrs['playlist'][$playlistkey] = str_replace( $current_splash, $this->esc_shortcode_arg($videoData['thumbnail']), $attrs['playlist'][$playlistkey] );
            } else { // add the splash
              $attrs['playlist'][$playlistkey] = $sItem . ',' . $this->esc_shortcode_arg($videoData['thumbnail']);
            }
            $bChanged = true;
          }
        }
        $attrs['playlist'] = implode(';', $attrs['playlist']);
      }

      $aShortcodesNew[$key] = $attrs[0];
      $sRemains = '';
      foreach ($attrs as $attKey => $attVal) {
        if (is_int($attKey) ) {
          if($attKey){
             $sRemains .= ' '.$attVal;
          }
          continue;
        }
        $aShortcodesNew[$key].= ' ' . $attKey . '=\\"' . $attVal . '\\"';
      }
      $aShortcodesNew[$key] .= trim($sRemains);

      if($bChanged){
        // put back the base64 encoded attributes
        $aShortcodesNew[$key] = preg_replace_callback( '~(ad|popup)=\\\"<!--fv_flowplayer_base64_encoded-->(.*?)"~', array( $this, 'shortcode_attr_unescape' ), $aShortcodesNew[$key] );
        $sPost = str_replace($aShortcodes[0][$key], $aShortcodesNew[$key], $sPost);
      }
    }

    return $sPost;
  }




	function scripts() {
    global $post, $fv_fp, $fv_wp_flowplayer_ver;

    //  todo: something better for video checker
    if( isset($GLOBALS['fv_fp_scripts']) && isset($GLOBALS['fv_fp_scripts']['fv_flowplayer_admin_test_media']) && count($GLOBALS['fv_fp_scripts']['fv_flowplayer_admin_test_media']) ) {
      foreach( $GLOBALS['fv_fp_scripts']['fv_flowplayer_admin_test_media'] AS $key => $item ) {
        if( !is_array($item[0]) && ( FV_Player_Pro_Vimeo()->is_vimeo(stripslashes($item[0])) || $this->is_youtube(stripslashes($item[0])) ) || apply_filters('fv_player_video_checker_exclude',false,$item[0]) ) {
          unset( $GLOBALS['fv_fp_scripts']['fv_flowplayer_admin_test_media'][$key] );
        }
      }
    }

    // Was there any player or do we expect any to load in Ajax?
    if(
      isset($GLOBALS['fv_fp_scripts']) ||
      $this->should_force_load_js()
    ) {

      $dev = false;
      if( defined('SCRIPT_DEBUG') && SCRIPT_DEBUG ) {
        foreach( glob( dirname(__FILE__).'/js/*.module.js') as $filename ) {
          $dev = true;

          $handle = 'fv_player_pro-'.basename($filename);
          if( basename($filename) == 'general.module.js' ) $handle = 'fv_player_pro'; // it's very important to use fv_player_pro for the main script!
          wp_enqueue_script( $handle, plugins_url('/js/'.basename($filename), __FILE__), array('jquery','flowplayer'), filemtime( dirname(__FILE__).'/js/'.basename($filename) ), true);
        }
      }

      if( $fv_wp_flowplayer_ver && version_compare($fv_wp_flowplayer_ver,'7.5.27.7210.5') == -1 ) {
        if($fv_fp->should_force_load_js() || $this->bYoutube || did_action('fv_player_extensions_admin_load_assets')) {
          $youtube_js = 'youtube.min.js';

          if($dev && file_exists( dirname(__FILE__).'/js/youtube.dev.js' ) ) {
            $youtube_js = 'youtube.dev.js';
          }

          wp_enqueue_script( 'fv-player-pro-youtube', plugins_url('/js/' . $youtube_js , __FILE__), array('jquery','fv_player_pro'), filemtime( dirname(__FILE__).'/js/'.$youtube_js ), true);
        }
      }

      if( !$dev ) {
        wp_enqueue_script('fv_player_pro', plugins_url('/js/fv_player_pro.min.js', __FILE__), array('jquery','flowplayer'), $this->version, true);
      }

      $aCFDomains = array();
      if( $this->_get_option( array('pro','cf_domain') ) ) {
        $aCFDomains = explode( ',', $this->_get_option( array('pro','cf_domain') ) ); //  todo: test!
        $aCFDomains = array_map( 'trim', $aCFDomains );
      }
      if( $this->get__vimeo_key() ) {
        $aCFDomains[] = '//vimeo.com';
        $aCFDomains[] = '//vimeopro.com';
        $aCFDomains[] = '//player.vimeo.com/video/';
      }
      $bVimeoDirectAjax = $this->_get_option( array('pro','vimeo_direct_ajax') );

      // If we expect players to load in Ajax, YouTube API needs to
      // be there at all times
      if( $this->should_force_load_js() ) $this->bYoutube = 1;

      $aOptions = array(
        'ajaxurl' => admin_url( 'admin-ajax.php' ),
        'vimeo_ajax_url' => $bVimeoDirectAjax && defined('FV_PLAYER_VIMEO_KEY') ? plugins_url('fv-vimeo-ajax.php', __FILE__) : false,
        'autoplay_once' => $this->is_option_enabled('autoplay_once') || isset($post) && get_post_meta( $post->ID, 'fv-player-autoplay-once', true ),
        'dynamic_domains' => apply_filters('fv_player_pro_video_ajaxify_domains',$aCFDomains),
        'dynamic_args' => array_unique( apply_filters('fv_player_pro_video_ajaxify_args', array('Key-Pair-Id', 'Signature', 'uss_token' /* TODO: Move to FV Player Vzaar? */ ) ) ),
        'debug' => $this->is_option_enabled('debug_log'),
        'no_ui_slider_js' => plugins_url( 'js/noUiSlider.min.js', __FILE__ ),
        'youtube' => $this->bYoutube,
        'youtube_ads_disable' => $this->_get_option( array('pro','youtube_ads_disable') )
      );

      $aOptions['video_ads_skip'] = 5;
      if( is_numeric( $this->_get_option( array('pro','video_ads_skip') ) ) ) {
        $aOptions['video_ads_skip'] = intval($this->_get_option( array('pro','video_ads_skip') ));
      }

      $aOptions['video_ads_skip_minimum'] = 10;
      if( is_numeric( $this->_get_option( array('pro','video_ads_skip_minimum') ) ) ) {
        $aOptions['video_ads_skip_minimum'] = intval($this->_get_option( array('pro','video_ads_skip_minimum') ));
      }

      if( $this->_get_option( array('pro','video_ads_once') ) ) {
        $aOptions['video_ads_once'] = true;
      }

      $video_ads_once_hours = $this->_get_option( array('pro','video_ads_once_hours') );
      if ( $video_ads_once_hours && 24 !== intval( $video_ads_once_hours ) ) {
        $aOptions['video_ads_once_hours'] = intval( $video_ads_once_hours );
      }

      if( $this->_get_option('subtitleOn')) {
        $aOptions['subtitleOn'] = true;
      }

      if( $this->_get_option( array('pro', 'separate_subtitle_disabling') ) ) {
        $aOptions['separate_subtitle_disabling'] = true;
      }

      if( $this->_get_option( array('pro','autoplay_scroll') ) || isset($post) && get_post_meta($post->ID, 'fv_flowplayer_scroll_autoplay',true) ) {
        $aOptions['autoplay_scroll'] = true;
        if( !$this->_get_option( array('pro','autoplay_scroll_enhanced') ) ) {
          $aOptions['autoplay_scroll'] = array('conservative' => true);
        }
      }

      if( $this->_get_option( array('pro','chapters_below_player') ) ) {
        $aOptions['chapters_below_player'] = true;
      }

      if( $this->_get_option( array('pro','youtube_cookies') ) ) {
        $aOptions['youtube_cookies'] = true;
      }

      $aOptions['watching_prompt'] = array(
        'int' => $this->_get_option( array('pro','watching_prompt') ) ? $this->_get_option( array('pro','watching_prompt_interval') ) : 0,
        'msg' => $this->_get_option( array('pro','watching_prompt_msg') )
      );

      if( apply_filters( 'fv_player_no_transcript_dragging', false ) ) {
        $aOptions['no_transcript_dragging'] = true;
      }

      if( apply_filters( 'fv_player_no_transcript_sizing', false ) ) {
        $aOptions['no_transcript_sizing'] = true;
      }

      $aOptions = apply_filters('fv_player_pro_conf',$aOptions);

      $aOptions = apply_filters( 'fv_player_pro_localize_script_options' , $aOptions);

      wp_localize_script( 'fv_player_pro', 'fv_player_pro', $aOptions );
      wp_localize_script( 'fv_player_pro', 'fv_player_pro_js_translations', $this->fv_player_pro_get_js_translations() );
    }
  }


  function styles() {
    $css_file = '/css/style.min.css';

    if( defined('SCRIPT_DEBUG') && SCRIPT_DEBUG ) {
      $css_file = '/css/style.css';
    }

    if( is_admin() && did_action('admin_footer') ) {
      echo "<link rel='stylesheet' id='fv-player-pro'  href='".plugins_url( $css_file, __FILE__ )."?ver=".$this->version."' type='text/css' media='all' />\n";
    } else if( wp_style_is('fv_flowplayer') && !wp_style_is('fv-player-pro') ){ // only enqueue if FV Player CSS is there and if not already enqueued
      wp_enqueue_style( 'fv-player-pro', plugins_url( $css_file, __FILE__ ), array('fv_flowplayer'), $this->version );
    }
  }




  function set_file_type( $type ) {
    $args = func_get_args();
    if( isset($args[1]) ) {
      if( FV_Player_Pro_Vimeo()->is_vimeo($args[1]) || FV_Player_Pro_Vimeo()->is_vimeo_event($args[1]) || $this->is_wistia($args[1]) ) {
        $type = "video/mp4";
      } else if( $this->is_dynamic_item($args[1]) ) {
        /**
         * We only need to change the video type if it's not already a HTML5
         * video/audio, but something like a HLS stream with mime type of
         * application/x-mpegurl.
         * We force the file to be treated as HTML5 that way and let it load
         * through Ajax.
         *
         * In Freedom Video Player 8.0.18.1 we improved the Ajax loading to work for HLS.js too,
         * so it does not need video/mp4 type set to let video URL load with Ajax.
         */
        global $fv_wp_flowplayer_core_ver;
        if ( version_compare( $fv_wp_flowplayer_core_ver, '8.0.18', '<=' ) ) {
          if ( stripos( $type, 'audio/' ) !== 0 && stripos( $type, 'video/' ) !== 0 ) {
            $type = "video/mp4";
          }
        }
      }
    }
    return $type;
  }




  function set_player_type( $player_type ) {
    $args = func_get_args();

    if( (!isset($args[4]['playlist']) || !$args[4]['playlist']) && function_exists('is_amp_endpoint') && is_amp_endpoint() ) {
      return $player_type;
    }

    if( isset($args[3]) && count($args[3]) > 0 ) {
      $aVideos = $args[3];
    } else {
      $aVideos[] = $args[2];
    }

    foreach( $aVideos AS $sVideo ) {
      if ( !isset($sVideo['sources'][0]['src']) ) continue;

      if( is_array($sVideo) ) {
        $sVideo = $sVideo['sources'][0]['src'];
      }
      if( is_array($sVideo) ) {
        $aVideo = array_values($sVideo);
        $sVideo = $aVideo['sources'][0]['src'];
      }

      if( $this->is_option_enabled('vimeo') && FV_Player_Pro_Vimeo()->is_vimeo($sVideo) ) {
        if( $this->_get_option( array('pro','vimeo_iframe') ) && ( empty($args[4]['engine']) || $args[4]['engine'] != 'html5' ) ){
          $player_type = 'vimeo';
        }else{
          $player_type = 'video';
        }

      } else if( $this->is_youtube($sVideo) || $this->is_wistia($sVideo) ) {
        $player_type = 'video';
      }
    }

    return $player_type;
  }




  function settings_save( $aSettings ) {
    $aArgs = func_get_args();
    $aOldSettings = $aArgs[1];

    if( isset($_POST['fv_player_admin_pro_quality_alive']) ) {
      $aSettings['pro']['quality'] = '';
      if( isset($_POST['aQualityItemNames']) && isset($_POST['aQualityItemLabel']) ) {
        $sQuality = '';
        foreach( $_POST['aQualityItemNames'] AS $key => $item ) {
          $sQuality .= str_replace( ',', '', trim($item) ).",".str_replace( ',', '', trim($_POST['aQualityItemLabel'][$key]) )."\n";
        }
        $aSettings['pro']['quality'] = trim($sQuality);;
        unset($aSettings['aQualityItemNames']);
        unset($aSettings['aQualityItemLabel']);
      }
    }

    // TODO: its still saving, fix
    // do not save peertube private settings
    if( isset($aSettings['pro']['peertube_private_domain']) && isset($aSettings['pro']['peertube_private_username']) && isset($aSettings['pro']['peertube_private_password']) ) {
      unset($aSettings['pro']['peertube_private_domain']);
      unset($aSettings['pro']['peertube_private_username']);
      unset($aSettings['pro']['peertube_private_password']);
    }

    if( isset($aOldSettings['pro']['cf_pk']) && ( !isset($aSettings['pro']['cf_pk']) || strlen(trim($aSettings['pro']['cf_pk'])) == 0 ) ) {
      $aSettings['pro']['cf_pk'] = $aOldSettings['pro']['cf_pk'];
    }

    if( isset($aSettings['pro']['ppv_description']) ) {
      $aSettings['pro']['ppv_description'] = stripslashes($aSettings['pro']['ppv_description']);
    }

    // update stream loader table if it's enabled
    if( isset($aSettings['pro']['stream_loader_on']) && $aSettings['pro']['stream_loader_on'] == 'true' ) {
      FV_Player_Pro_Stream_Loader()->plugin_update_database(true);
    }

    // if it's a manual save action we purge the Vimeo, transcript and splash caches
    if(
      (!empty($_POST['fv_flowplayer_settings_nonce']) && wp_verify_nonce($_POST['fv_flowplayer_settings_nonce'],'fv_flowplayer_settings_nonce') ) ||
      (isset($_POST['postbox_id']) && ( $_POST['postbox_id'] == 'fv_player_pro_vimeo') || ! empty( $_POST['postbox_id'] ) && $_POST['postbox_id'] == 'fv_player_pro_youtube') )  {
      $this->clear_cache(true);
    }
    return $aSettings;
  }




  /*
   * What are the circumstances under which the player scripts should
   * load even if no player was present on page?
   */
  function should_force_load_js() {
    // New way of FV Player telling every extension to load
    if( did_action('fv_player_extensions_admin_load_assets') ) {
      return true;
    }

    // Deals with js-everywhere and page builders, in core FV Player
    global $fv_fp;
    if( !empty($fv_fp) && method_exists($fv_fp,'should_force_load_js') && $fv_fp->should_force_load_js() ) {
      return true;
    }

    // Legacy
    return $this->_get_option( 'js-everywhere' );
  }




  function pro_video_ads_save( $post = false ) {

    if ( ! $post ) {
      $post = $_POST;
    }

    if ( isset( $post['aVideoAdDisabled'] ) ) {
      global $FV_Player_Db;

      $new_video_ads_ids = array();

      foreach( $post['aVideoAdDisabled'] AS $key => $item ) {

        // get new values
        $click = esc_url_raw( trim( $post['aVideoAdClick'][$key] ) );
        $disabled = filter_var($item, FILTER_VALIDATE_BOOLEAN);
        $src = esc_url_raw( trim( stripslashes( $post['aVideoAd_mp4'][$key] ) ) );
        $title = sanitize_text_field( trim( $post['aVideoAd_name'][$key] ) );

        if ( ! $click && ! $src && ! $title ) {
          // TODO: Remove the video ad player from the database
          continue;
        }

        $video_ads_ids = $this->_get_option( array('pro','video_ads_ids') );

        // check if id was already saved in old settings
        if ( isset( $post['aVideoAdPlayerId'][$key] ) && $video_ads_ids ) {
          $player_id = intval( $post['aVideoAdPlayerId'][$key] );

          if( in_array($player_id, $video_ads_ids ) ) {
            $player = new FV_Player_Db_Player( $player_id );

            $videos = $player->getVideos();
            $video = $videos[0];

            // prevent updating non-video ad players
            if( !$video->getMetaValue('is_video_ad', true) ) continue;

            // update existing values
            $video->set( 'src', $src );
            $video->set( 'title', 'Video Ad: ' . $title );

            // save it
            $video->save( array(), true );

            // update meta values
            $video->updateMetaValue( 'video_ad_click', $click );
            $video->updateMetaValue( 'video_ad_disabled', $disabled );
            $video->updateMetaValue( 'is_video_ad', true );

            // add it to new settings
            $new_video_ads_ids[] = $player_id;

            continue;
          }

        }

        // create new meta & player for video ad
        $meta = array();

        $meta[] = array(
          'meta_key' => 'video_ad_click',
          'meta_value' => $click
        );

        $meta[] = array(
          'meta_key' => 'video_ad_disabled',
          'meta_value' => $disabled
        );

        $meta[] = array(
          'meta_key' => 'is_video_ad',
          'meta_value' => true
        );

        $player_id =  $FV_Player_Db->import_player_data(false, false, array(
          'date_created' => gmdate('Y-m-d H:i:s'),
          'videos' => array(
            array(
              'src' => $src,
              'title' => 'Video Ad: ' . $title,
              'meta' => $meta
            ),
          )
        ));

        // add it to new settings
        $new_video_ads_ids[] = $player_id;
      }
    }

    return $new_video_ads_ids;
  }




  function shortcode( $attrs ) {
    $aArgs = func_get_args();

    if( isset($aArgs[2]) && isset($aArgs[2]['qsel']) ) {
      $attrs['qsel'] = $aArgs[2]['qsel'];
    }

    if( isset($aArgs[2]) && isset($aArgs[2]['ads']) ) {
      $attrs['preroll'] = $aArgs[2]['ads'];
    }
    if( isset($aArgs[2]) && isset($aArgs[2]['preroll']) ) {
      $attrs['preroll'] = $aArgs[2]['preroll'];
    }
      if( isset($aArgs[2]) && isset($aArgs[2]['postroll']) ) {
      $attrs['postroll'] = $aArgs[2]['postroll'];
    }

    if( isset($aArgs[2]) && isset($aArgs[2]['chapters']) ) {
      $attrs['chapters'] = $aArgs[2]['chapters'];
    }

    if( isset($aArgs[2]) && isset($aArgs[2]['transcript']) ) {
      $attrs['transcript'] = $aArgs[2]['transcript'];
    }

    if( isset($aArgs[2]) && isset($aArgs[2]['ab']) ) {
      $attrs['ab'] = $aArgs[2]['ab'];
    }

    if( isset($aArgs[2]) && isset($aArgs[2]['hflip']) ) {
      $attrs['hflip'] = $aArgs[2]['hflip'];
    }

    if (isset($aArgs[2]) && isset($aArgs[2]['copy_text'])) {
      $attrs['copy_text'] = $aArgs[2]['copy_text'];
    }

    if( isset($aArgs[2]) && is_array($aArgs[2]) ) {
      foreach( $aArgs[2] AS $key => $value ) {
        if( $key == 'end' && $value == 'show' ) continue;
        if( stripos($key,'start') === 0 || stripos($key,'end') === 0 ) {
          $attrs[$key] = $value;
        }
      }
    }

    if(isset($aArgs[2]['hlskey'])){
      $attrs['hlskey'] = $aArgs[2]['hlskey'];
    }

    return $attrs;
  }




  function shortcode_attr_escape( $aMatch ) {
    $aMatch[0] = str_replace( $aMatch[1], '<!--fv_flowplayer_base64_encoded-->'.base64_encode($aMatch[1]), $aMatch[0] );
    return $aMatch[0];
  }




  function shortcode_attr_unescape( $aMatch ) {
    $html = str_replace( array('"','[',']'), array('\\\"','\\\[','\\\]'), base64_decode($aMatch[2]) );
    return $aMatch[1].'=\\"'.$html.'\\"';
  }




  function shortcode_editor_actions() {
    if(!function_exists('fv_player_editor_input')):
    $bVideoAds = $this->func__get_video_ads();

    ?>
    <tr<?php if( empty($bVideoAds) ) echo ' style="display: none"'; ?>>
      <th scope="row" class="label"><label for="fv_wp_flowplayer_field_video_ads" class="alignright"><?php _e('Pre-roll Ad', 'fv-player-pro'); ?></label></th>
      <td class="field">
        <?php $this->admin__select_video_ads( array( 'id'=>'fv_wp_flowplayer_field_video_ads', 'show_default' => true )); ?>
      </td>
    </tr>
    <tr<?php if( empty($bVideoAds) ) echo ' style="display: none"'; ?>>
      <th scope="row" class="label"><label for="fv_wp_flowplayer_field_video_ads_post" class="alignright"><?php _e('Post-roll Ad', 'fv-player-pro'); ?></label></th>
      <td class="field">
        <?php $this->admin__select_video_ads( array( 'id'=>'fv_wp_flowplayer_field_video_ads_post', 'show_default' => true )); ?>
      </td>
    </tr>
    <?php
    endif;
  }




  function shortcode_editor_options() {
    if(!function_exists('fv_player_editor_input')):
    // TODO: Move to new system
    $bQualities = $this->func__get_qualities();
    ?>
    <tr<?php if( !$bQualities ) echo ' style="display: none"'; ?>>
      <th scope="row" class="label"><label for="fv_wp_flowplayer_field_qsel" class="alignright"><?php _e('Quality Switching', 'fv-player-pro'); ?></label></th>
      <td class="field">
        <input type="checkbox" id="fv_wp_flowplayer_field_qsel" name="fv_wp_flowplayer_field_qsel" />
        <a href="#" onclick="return fv_player_shortcode_editor_qs()"><?php _e('Show hint', 'fv-player-pro'); ?></a>
      </td>
    </tr>
    <tr id="fv_player_shortcode_editor_qualities_sample_wrap" style="display: none">
      <th></th>
      <td><div id="fv_player_shortcode_editor_qualities_sample"></div></td>
    </tr>

    <tr<?php if( $this->is_db() ) echo ' style="display: none"'; ?>>
      <th scope="row" class="label" valign="top"><label for="fv_wp_flowplayer_hlskey" class="alignright"><?php _e('Encrypted HLS', 'fv-player-pro'); ?></label></th>
      <td class="field">
        <input id="fv_wp_flowplayer_hlskey" class="text" type="text" name="fv_wp_flowplayer_hlskey" placeholder="<?php _e('Decryption key', 'fv-player-pro'); ?>" style="width: 93%" />

        <?php if( $this->_get_option( array('pro','elastic_key') ) ) : ?>
          <textarea id="fv_wp_flowplayer_hlskey_cryptic" name="fv_wp_flowplayer_hlskey_cryptic" class="text with-button" placeholder="<?php _e('Encryption key', 'fv-player-pro'); ?>" style="width: 93%"></textarea>
          <br />
          <a id="button-hls-decrypt" href="#"><?php _e('Decrypt', 'fv-player-pro'); ?></a>
        <?php endif; ?>
      </td>
    </tr>
    <?php
    endif;
  }




  function shortcode_editor_item() {
    if( !function_exists('fv_player_editor_input') ):

    $bStartEnd = $this->is_option_enabled( 'start_end' );
    $bChapters = $this->is_option_enabled( array( 'interface', 'chapters' ) );
    $bAllowUploads = $this->_get_option( 'allowuploads' );

    ?>
    <tr<?php if( !$bStartEnd ) echo ' style="display: none"'; ?>>
      <th scope="row" class="label"><label for="fv_wp_flowplayer_field_start" class="alignright"><?php _e('Start/End', 'fv-player-pro'); ?></label></th>
      <td class="field" colspan="2">
        <input type="text" class="text half-field extra-field" id="fv_wp_flowplayer_field_start" name="fv_wp_flowplayer_field_start" />
        <input type="text" class="text half-field extra-field" id="fv_wp_flowplayer_field_end" name="fv_wp_flowplayer_field_end" />
        <span class="hint">(<abbr title="<?php _e('Enter hh:mm:ss. You may enter both or just one value, leave empty for full video.', 'fv-player-pro'); ?>">?</abbr>)</span>
      </td>
    </tr>
    <?php if( !$this->is_db() ) : ?>
      <tr<?php if( !$bChapters ) echo ' style="display: none"'; ?>>
        <th scope="row" class="label"><label for="fv_wp_flowplayer_field_chapters" class="alignright"><?php _e('Chapters', 'fv-player-pro'); ?></label></th>
        <td class="field" colspan="2">
          <input type="text" class="text with-button extra-field" id="fv_wp_flowplayer_field_chapters" name="fv_wp_flowplayer_field_chapters" value="" />
           <?php if ($bAllowUploads == 'true') { ?>
              <a class="button add_media" href="#"><span class="wp-media-buttons-icon"></span> <?php _e('Add Chapters', 'fv-player-pro'); ?></a>
              <a class="fv-fp-subtitle-remove" href="#" style="display: none">X</a>
            <?php }; ?>
        <td>
      </tr>
    <?php endif; ?>
    <?php if( $this->is_db() ) : ?>
      <tr class="fv_wp_flowplayer_hlskey_decoder" style="display: none">
        <th scope="row" class="label" valign="top"><label for="fv_wp_flowplayer_hlskey" class="alignright" style="margin-top: 5px;"><?php _e('Encrypted HLS', 'fv-player-pro'); ?></label></th>
        <td class="field" colspan="2">
            <input id="fv_wp_flowplayer_hlskey" class="text with-button" type="text" name="fv_wp_flowplayer_hlskey" placeholder="<?php _e('Decryption key', 'fv-player-pro'); ?>" />
        </td>
      </tr>
    <?php
      endif;
    endif;
  }




  function shortcode_editor_subtitles_prepend() {
    if( !function_exists('fv_player_editor_input') ):

    $fv_flowplayer_conf = get_option( 'fvwpflowplayer' );
    $allow_uploads = false;
    $bChapters = $this->is_option_enabled( array( 'interface', 'chapters' ) );
    if( isset($fv_flowplayer_conf["allowuploads"]) && $fv_flowplayer_conf["allowuploads"] == 'true' ) {
      $allow_uploads = $fv_flowplayer_conf["allowuploads"];
      $upload_field_class = ' with-button';
    } else {
      $upload_field_class = '';
    }

    ?>
      <tr>
          <th scope="row" class="label"><label for="fv_wp_flowplayer_field_transcript" class="alignright"><?php _e('Transcript', 'fv-player-pro'); ?></label></th>
          <td class="field fv-fp-transcript" colspan="2">
              <input type="text" class="text<?php echo $upload_field_class; ?> fv_wp_flowplayer_field_transcript" name="fv_wp_flowplayer_field_transcript" value=""/>
            <?php if ($allow_uploads == 'true') { ?>
                <a class="button add_media" href="#"><span class="wp-media-buttons-icon"></span> <?php _e('Add Transcript', 'fv-player-pro'); ?></a>
                <input type="checkbox" id="fv-fp-transcript-checkbox" class="fv_wp_flowplayer_field_transcript_original_formatting" name="fv_wp_flowplayer_field_transcript_original_formatting"/>Preserve original formatting
            <?php }; ?>
          </td>
      </tr>

      <tr<?php if( !$bChapters ) echo ' style="display: none"'; ?>>
          <th scope="row" class="label"><label for="fv_wp_flowplayer_field_chapters" class="alignright"><?php _e('Chapters', 'fv-player-pro'); ?></label></th>
          <td class="field" colspan="2">
            <input type="text" class="text with-button extra-field" id="fv_wp_flowplayer_field_chapters" name="fv_wp_flowplayer_field_chapters" value="" />
            <?php if ($allow_uploads == 'true') { ?>
              <a class="button add_media" href="#"><span class="wp-media-buttons-icon"></span> <?php _e('Add Chapters', 'fv-player-pro'); ?></a>
            <?php }; ?>
          </td>
      </tr>
    <?php
    endif;
  }




  function shortcode_video_tab_fields($fields) {
    $fields['video']['items'][] = array(
      'label'      => __('Encrypted HLS', 'fv-player-pro'),
      'name'       => 'hls_hlskey',
      'type'       => 'text',
      'visible'    => false,
      'video_meta' => true,
    );

    $fields['video']['items'][] = array(
      'label'   => __('Custom Start', 'fv-player-pro'),
      'name'    => 'start',
      'type'    => 'text',
      'visible' => $this->_get_option( array('pro', 'start_end') ),
      'width'   => 'half',
    );

    $fields['video']['items'][] = array(
      'label'   => __('Custom End', 'fv-player-pro'),
      'name'    => 'end',
      'type'    => 'text',
      'visible' => $this->_get_option( array('pro', 'start_end') ),
      'width'   => 'half',
    );

    $fields['video']['items'][] = array(
      'label'      => __('Enable Download', 'fv-player-pro'),
      'name'       => 'download_enabled',
      'visible'    => $this->_get_option( array( "pro", "interface", "download" ) ),
      'video_meta' => true,
      'children'   => array(
        array(
          'name'        => 'download_supported',
          'type'        => 'notice_info',
          'description' => 'Logged in users with access to the player will be able to download your video.',
          'visible'     => true
        ),
        array(
          'name' => 'download_requires_urls',
          'type' => 'notice_info',
          'description' => 'Your video type does not support downloading, please provide the download links below.',
        ),
        array(
          'label'      => __('Download (SD)', 'fv-player-pro'),
          'name'       => 'download_sd',
          'browser'    => true,
          'type'       => 'text',
          'visible'    => true,
          'video_meta' => true
        ),
        array(
          'label'      => __('Download (HD)', 'fv-player-pro'),
          'name'       => 'download_hd',
          'browser'    => true,
          'type'       => 'text',
          'visible'    => true,
          'video_meta' => true
        ),
      )
    );

    return $fields;
  }




  function shortcode_subtitles_tab_fields($fields) {
    $fields['video']['items'][] = array(
      'label' => __('Chapters', 'fv-player-pro'),
      'name' => 'chapters',
      'browser' => true,
      'type' => 'text',
      'visible' => true,
      'video_meta' => true,
    );

    $fields['video']['items'][] = array(
      'label' => __('Transcript', 'fv-player-pro'),
      'name' => 'transcript_src',
      'browser' => true,
      'language' => true,
      'type' => 'text',
      'visible' => true,
      'video_meta' => true,
      'children' => array(
        array(
          'label' => __('Preserve original formatting', 'fv-player-pro'),
          'name' => 'transcript_original_formatting',
          'visible' => true,
          'type' => 'checkbox',
          'video_meta' => true,
        )
      )
    );

    return $fields;
  }




  function shortcode_actions_tab_fields($fields) {
    $video_ads = $this->func__get_video_ads();

    $video_ads_options = array(
      array( '' , 'Use site default' ),
      array( 'no' , 'No Ad' ),
      array( 'random' , 'Random' )
    );

    if( is_array($video_ads) ) {
      foreach ( $video_ads as $key => $video_ad ) {
        $video_ad_name = '';
        $video_ad_title = $video_ad->getTitle();
        if( !empty( $video_ad_title ) ) $video_ad_name = $video_ad_title;
        else $video_ad_name = 'Video Ad #'.($key + 1);
        if( $video_ad->getMetaValue( 'video_ad_disabled', true ) ) $video_ad_name .= ' (currently disabled)';
        if( trim( $video_ad->getSrc() ) === '' ) $video_ad_name .= ' (no video URL)';
        $video_ads_options[] = array( $key + 1, $video_ad_name );
      }
    }

    $fields['actions']['items'][] = array(
      'label' => __('Pre-roll Ad', 'fv-wordpress-flowplayer'),
      'name' => 'video_ads',
      'options' => $video_ads_options,
      'type' => 'select',
      'visible' => is_array($video_ads)
    );

    $fields['actions']['items'][] = array(
      'label' => __('Post-roll Ad', 'fv-wordpress-flowplayer'),
      'name' => 'video_ads_post',
      'options' => $video_ads_options,
      'type' => 'select',
      'visible' => is_array($video_ads)
    );

    return $fields;
  }




  function is_db() {
    global $fv_wp_flowplayer_ver;
    if( version_compare($fv_wp_flowplayer_ver,'7.3.0.727') != -1 ) return true;

    return false;
  }




  function start_endtime($aItems){
    global $fv_fp;
    if( empty($aItems) ){
      return $aItems;
    }
    if( isset($fv_fp->aCurArgs) && isset($fv_fp->aCurArgs['startend']) ) {
      foreach( explode( ';', $fv_fp->aCurArgs['startend'] ) as $key => $value){
        $value = explode('-',$value);
        $aItems[$key]['fv_start'] = self::hms_to_seconds($value[0]);
        if( isset($value[1]) ) {
          $aItems[$key]['fv_end'] = self::hms_to_seconds($value[1]);
        }
      };
    }

    return $aItems;
  }

  public function watching_prompt_attributes($aAttributes) {
    $aArgs = func_get_args();
    if (
      isset($aArgs[2]->aCurArgs['stillwatching']) && (
        floatval($aArgs[2]->aCurArgs['stillwatching']) > 0 ||
        $aArgs[2]->aCurArgs['stillwatching'] == 'false'
      )
    ) {
      $aAttributes['data-watching_prompt'] = floatval($aArgs[2]->aCurArgs['stillwatching']);
    }
    return $aAttributes;
  }

  function strip_rtmp_ext( $rtmp ) {
    return preg_replace( '~^(mp4|flv):~', '', $rtmp );
  }




  function video_ads( $aItems ) {
    global $fv_fp;

    $this->bVideoAdsStatus = array();

    $aArgs = func_get_args();
    if (!isset($aArgs[1]->hash)) {
      return $aItems;
    }

    if (!$this->_get_option( array('pro','video_ads_ids') )) {
      return $aItems;
    }

    $player_args = ! empty( $aArgs[1]->aCurArgs ) ? $aArgs[1]->aCurArgs : false;

    if ( method_exists( $fv_fp, 'get_current_video_to_edit' ) && $fv_fp->get_current_video_to_edit() > -1 ) {
      return $aItems;
    }

    // Do not use ads if using lightbox with playlist
    if ( isset( $player_args['lightbox'] ) && stripos( $player_args['lightbox'], 'true' ) !== false && ! empty($player_args['playlist'] ) ) {
      return $aItems;
    }

    $aVideoAds = $this->_get_option( array('pro','video_ads_ids') );
    $aVideoAdsEnabled = array();

    // filter out disabled video ads
    foreach ($aVideoAds AS $k => $id) {
      $player = new FV_Player_Db_Player( $id );

      $videos = $player->getVideos();

      if( empty( $videos[0] ) ) {
        continue;
      }

      $video = $videos[0];

      $src = $video->getSrc();
      $disabled = $video->getMetaValue( 'video_ad_disabled', true );

      // overwrite item to video object
      $aVideoAds[ $k ] = $video;

      if (!$disabled && trim( $src ) !== '' ) {
        $aVideoAdsEnabled[] = $video;
      }
    }

    // no video ads enabled
    if (count($aVideoAdsEnabled) == 0) {
      return $aItems;
    }

    $found_audio_streams = 0;
    foreach( $aItems AS $aItem ) {
      foreach( $aItem['sources'] AS $aSource ) {
        if( stripos($aSource['type'],'audio/') === 0 ) {
          $found_audio_streams++;
        }
      }
    }

    if( $found_audio_streams > 0 ) {
      return $aItems;
    }

    $aCvaIds = array(
      0 => isset( $player_args['preroll']) ? $player_args['preroll']  : '',
      1 => isset( $player_args['postroll']) ? $player_args['postroll'] : ''
    );

    $aItems_new = array();
    $all_items_count = count($aItems);

    foreach ($aItems as $videoItemIndex => $videoItem) {
      // check if we should add ad to this item
      if( !$this->_get_option( array('pro','video_ads_between_vids') ) ) {
        // we've not opted-in to show pre-rolls and post-rolls in between
        // of all playlist videos, let's see if this is the first or last one
        // and bail out if it's neither
        if ( !($videoItemIndex == 0 || $videoItemIndex == $all_items_count - 1) ) {
          $aItems_new[] = $videoItem;
          continue;
        }
      }

      $item_done = false;
      foreach ( $aCvaIds as $iPosition => $cva_id ) {

        $cva_id = trim( $cva_id );

        // get pre-roll and post-roll settings
        if ( $cva_id == "default" || empty( $cva_id ) ) {
          if ( $iPosition && $this->_get_option( array( 'pro', 'video_ads_postroll_default' ) ) ) {
            $cva_id = $this->_get_option( array( 'pro', 'video_ads_postroll_default' ) );
          } else if ( $this->_get_option( array( 'pro', 'video_ads_default' ) ) ) {
            $cva_id = $this->_get_option( array( 'pro', 'video_ads_default' ) );
          }
        }

        // check if we should skip this ad
        if ( $cva_id == "no" ) {
          continue;
        }

        // check if we should show a random ad
        if ( $cva_id == "random" ) {
          $cva_id   = rand( 0, count( $aVideoAdsEnabled ) - 1 );
          $aVideoAd = $aVideoAdsEnabled[ $cva_id ];

        } else {
          $cva_id = intval($cva_id) - 1;
          if ( ! isset( $aVideoAds[ $cva_id ] ) || $aVideoAds[ $cva_id ]->getMetaValue( 'video_ad_disabled', true ) || trim( $aVideoAds[ $cva_id ]->getSrc() ) === '' ) {
            continue;
          }
          $aVideoAd = $aVideoAds[ $cva_id ];
        }

        $aVideoObj = array( 'sources' => array() );

        $aVideoObj['sources'][] = array(
          'id'       => $aVideoAd->getId(),
          'src'      => $aVideoAd->getSrc(),
          'type'     => $fv_fp->get_mime_type( $aVideoAd->getSrc() ),
          'fv_title' => trim( $aVideoAd->getTitle() ) == '' ? '' : ( 'Video Ad:' . trim( $aVideoAd->getTitle() ) )
        );

        $aVideoObj['click'] = $aVideoAd->getMetaValue( 'video_ad_click', true );

        if ( $iPosition == 0 ) {
          if( $this->_get_option( array('pro','video_ads_between_vids') ) || $videoItemIndex == 0 ) {
              $aItems_new[] = $aVideoObj;

            if ( ! $item_done ) {
              $aItems_new[] = $videoItem;
              $item_done    = true;
            }

            if ( ! isset( $this->bVideoAdsStatus[ $videoItemIndex ] ) ) {
              $this->bVideoAdsStatus[ $videoItemIndex ] = array();
            }

            $this->bVideoAdsStatus[ $videoItemIndex ]['preroll'] = $aVideoObj;
          }

        } elseif ( $iPosition == 1 ) {

          /**
           * Insert the post-roll ad if it's either
           *
           * * "Pre-roll & post-roll ads between videos" is enabled and there was no pre-roll ad for current video
           * * it's the last video
           */
          if(
            $this->_get_option( array('pro','video_ads_between_vids') ) && empty( $this->bVideoAdsStatus[ $videoItemIndex ]['preroll'] ) ||
            $videoItemIndex == $all_items_count - 1
          ) {
            if ( ! $item_done ) {
              $aItems_new[] = $videoItem;
              $item_done    = true;
            }

            $aItems_new[] = $aVideoObj;

            if ( ! isset( $this->bVideoAdsStatus[ $videoItemIndex ] ) ) {
              $this->bVideoAdsStatus[ $videoItemIndex ] = array();
            }

            $this->bVideoAdsStatus[ $videoItemIndex ]['postroll'] = $aVideoObj;
          }
        }
      }

      if (!$item_done) {
        $aItems_new[] = $videoItem;
      }
    }

    return $aItems_new;
  }



  function video_ads_item_html( $aHTML ) {
    $aHTML_new = array();
    $items_count = count($aHTML);

    foreach ( $aHTML as $videoItemIndex => $videoItem ) {
      if ( isset( $this->bVideoAdsStatus[ $videoItemIndex ] ) && isset( $this->bVideoAdsStatus[ $videoItemIndex ]['preroll'] ) ) {
        $aHTML_new[] = "\t\t<a href='' data-item='" . json_encode( $this->bVideoAdsStatus[ $videoItemIndex ]['preroll'] ) . "' style='display: none'></a>\n";
      }

      // adding video ad means creating a playlist. So if there was just a single video make sure it's not visible as otherwise a single playlist thumb would show
      if ( $items_count == 1 && isset( $this->bVideoAdsStatus[ $videoItemIndex ] ) && (
          isset( $this->bVideoAdsStatus[ $videoItemIndex ]['preroll'] ) || isset( $this->bVideoAdsStatus[ $videoItemIndex ]['postroll'] )
          ) ) {
        $videoItem = str_replace( "<a ", "<a style='display: none' ", $videoItem );
      }
      $aHTML_new[] = $videoItem;

      if ( isset( $this->bVideoAdsStatus[ $videoItemIndex ] ) && isset( $this->bVideoAdsStatus[ $videoItemIndex ]['postroll'] ) ) {
        $aHTML_new[] = "\t\t<a href='' data-item='" . json_encode( $this->bVideoAdsStatus[ $videoItemIndex ]['postroll'] ) . "' style='display: none'></a>\n";
      }
    }

    return $aHTML_new;
  }




  function get__cached_splash( $splash, $src = false ) {
    global $post;

    if( !$splash && is_string($src) ) {

      $sVideoMeta = isset($post) ? get_post_meta( $post->ID, flowplayer::get_video_key($src, true ), true ) : false;
      // We do not accept the Vimeo image URLs which stopped working on 2021-09-20 or so
      if( !empty($sVideoMeta['splash']) && !preg_match( '~i.vimeocdn.com/video/[0-9]+_~', $sVideoMeta['splash']) ) {
        return $sVideoMeta['splash'];
      }

      // If we have no image we accept it if it's recent
      if( !empty($sVideoMeta['date']) && $sVideoMeta['date'] + 3600 > time() ) {
        return false;
      }

      if( $this->fTimeSpent_AutoSplash < 1 ) {
        global $post, $FV_Player_Pro_Odysee;
        if( $video_id = $this->is_youtube($src) ) {
          $video_id = $video_id[1];
          $type = 'youtube';

        } else if ( ! empty( $FV_Player_Pro_Odysee ) && method_exists( $FV_Player_Pro_Odysee, 'get_video_id' ) && $video_id = $FV_Player_Pro_Odysee->get_video_id( $src) ) {
          $type = 'odysee';

        } else {
          $video_id = FV_Player_Pro_Vimeo()->get_vimeo_id($src);  //  returns false if not Vimeo
          $type = 'vimeo';
        }

        if( $video_id ) {
          $tStart = microtime(true);
          $splash = get_option('fv_player_'.$type.'_splash_'.$video_id);
          if( !$splash ) {
            $post_id = !empty($post->ID) ? $post->ID : false;

            $videoData = apply_filters( 'fv_player_meta_data', $src, $post_id );

            if( $videoData && isset($videoData['thumbnail']) ) {
              $this->fTimeSpent_AutoSplash += microtime(true) - $tStart;
              update_option( 'fv_player_'.$type.'_splash_'.$video_id, $videoData['thumbnail'], false );
              return $this->esc_shortcode_arg($videoData['thumbnail']);
            }

          } else {
            return $splash;

          }

        }
      }
    }

    return $splash;
  }




  /*
   * Because of bug in FV_Player_Db_Video::updateMetaValue() in
   * FV PLayer < 7.4.46.727 the meta row would duplicate when the
   * value was not changed. So we remove these duplicate
   * transcript_text values here.
   */
  public function remove_duplicated_videometa() {
    global $wpdb;
    $table_name = $wpdb->prefix."fv_player_videometa";

    // deletes duplicate transcript_text rows if the table exists
    if( $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") == $table_name ) {
      $wpdb->query( "DELETE FROM `{$table_name}`
        WHERE id NOT IN
        (
          SELECT max_id FROM (
            SELECT  MAX(id) AS max_id
            FROM `{$wpdb->prefix}fv_player_videometa`
            WHERE meta_key IN ('transcript_text')
            GROUP BY id_video,
                    meta_key
          ) as c
        )
        AND meta_key IN ('transcript_text')"
      );
    }
  }

  public function clear_cache( $force = false ) {

    global $wpdb;
    foreach( array(
      "SELECT option_name FROM $wpdb->options WHERE option_name LIKE '%fv_player_pro_vimeo_%'", // clears Vimeo cache including the key check
      "SELECT option_name FROM $wpdb->options WHERE option_name LIKE 'fv_player_pro_transcript_%'",
      "SELECT option_name FROM $wpdb->options WHERE option_name LIKE 'fv_player_vimeo_splash_%'",
      "SELECT option_name FROM $wpdb->options WHERE option_name LIKE 'fv_player_youtube_splash_%'",
      "SELECT option_name FROM $wpdb->options WHERE option_name LIKE 'fv_player_pro_ajax__store_hls_access_tokens%'" // TODO: deprecated
    ) AS $clean_up ) {
      $aOptions = $wpdb->get_col( $clean_up  );
      if( $aOptions ) {
        foreach( $aOptions AS $option ) {
          delete_option($option);
        }
      }
    }

    $pathMatch = array();
    if (!preg_match('/^.*wp-content\//', __FILE__, $pathMatch)) {
      return;
    }
    $blog_id = $this->_get_option( array('pro','vimeo_direct_ajax') ) ? 1 : get_current_blog_id();

    $files = array();
    $cacheDirMpd = $pathMatch[0] . "cache/fv-player-mpd/$blog_id/";
    if( file_exists($cacheDirMpd) ) {
      foreach( scandir($cacheDirMpd) AS $file ) $files[] = $cacheDirMpd . $file;
    }
    $cacheDirVimeo = $pathMatch[0] . "cache/fv-player-vimeo/";
    if( file_exists($cacheDirVimeo) ) {
      foreach( scandir($cacheDirVimeo) AS $file ) $files[] = $cacheDirVimeo . $file;
    }

    if( count($files) ) {
      foreach ($files as $file) {
        if (is_file($file)) {
          unlink($file);
        }
      }
    }
  }

  public function fv_cron_schedules($schedules) {
    if(!isset($schedules["5min"])){
      $schedules["5min"] = array(
        'interval' => 5*60,
        'display' => __('Once every 5 minutes')
        );
    }
    return $schedules;
  }

  public function cron_init() {
    global $fv_fp;

    if ( !wp_next_scheduled( 'fv_player_pro_clear_cache' ) ) {
      wp_schedule_event( time(), 'daily', 'fv_player_pro_clear_cache' );
    }

    if ( $this->get__vimeo_key() && !wp_next_scheduled( 'fv_player_pro_update_vimeo_cache' ) ) {
      wp_schedule_event( time(), '5min', 'fv_player_pro_update_vimeo_cache' );
    }

    if ( isset($fv_fp) && method_exists( $fv_fp, '_get_option' ) && $fv_fp->_get_option( array('bunny_stream', 'lib_id') ) && !wp_next_scheduled( 'fv_player_pro_update_bunny_stream_collections_cache' ) ) {
      wp_schedule_event( time(), '5min', 'fv_player_pro_update_bunny_stream_collections_cache' );
    }

    if ( $this->_get_option( array('pro','youtube_key') ) && !wp_next_scheduled( 'fv_player_pro_update_youtube_cache' ) ) {
      wp_schedule_event( time(), '5min', 'fv_player_pro_update_youtube_cache' );
    }

  }

  private function features_check_cleanup( $conf ) {
    $contain_remove = array('nonce','secure_token','css_writeout');
    $count = array('amazon_bucket','video_ads','email_lists');
    $remove = array('amazon_key','amazon_secret','amazon_region','_wp_http_referer','elastic_secret','cf_pk','cf_key_id','mailchimp_api','backgroundColor','canvas','sliderColor','durationColor','timeColor','progressColor','bufferColor','timelineColor','borderColor','hasBorder','adTextColor','adLinksColor','subtitleBgColor','subtitleSize','playlistBgColor','playlistFontColor','playlistSelectedColor','skin-slim','skin-youtuby','skin-custom','fv-wp-flowplayer-submit','sharing_email_text','liststyle','ui_speed_increment','logoPosition','sticky_place','sticky_width','subtitleFontFace','font-face','cf_domains_list');

    foreach ( $conf AS $k => $v ) {
      if ( in_array($k, $remove) ) {
        unset($conf[$k]);
      } else if ( in_array($k, $count) ) {
        $conf[$k] = $this->features_check_array($conf[$k]);
      } else if ( is_array($conf[$k]) ) {
        $conf[$k] = $this->features_check_cleanup( $conf[$k] );
      }else if ( $v === "false" ) {
        $conf[$k] = false;
      }else{
        $conf[$k] = !empty($v) ? true : false;
        foreach( $contain_remove as $contain ) {
          if ( strpos($k,$contain) !== false ) {
            unset( $conf[$k] );
          }
        }
      }
    }
    return $conf;
  }

  private function features_check_array($array) {
    $count = 0;
    foreach($array as $k => $v) {
      if( !empty($v) ) {
        $count++;
      }
    }
    return $count;
  }

  private function features_check_additions(&$arg) {
    global $wpdb;

    $arg['system_info'] = array(
      'php_version' => phpversion(),
      'db_version' => $wpdb->db_version(),
      'webserver_version' => $_SERVER['SERVER_SOFTWARE'],
      'wp_version' => get_bloginfo('version'),
      'mb_string_enabled' => extension_loaded('mbstring'),
      'curl_enabled' => function_exists( 'curl_init' ),
      'permalink_structure' => get_option( 'permalink_structure' ),
      'active_theme' => wp_get_theme()->Name .  ' - ' . wp_get_theme()->Version,
      'multisite' => is_multisite()
    );
  }

  function features_check($arg) {
    $arg['features'] = $this->features_check_cleanup( get_option('fvwpflowplayer') );
    $this->features_check_additions($arg);
    return $arg;
  }

  /**
   * Method used in WP filter. Receives video meta data array
   * as well as post data to extract HLS from and returns
   * updated video meta array with HLS formatted in a way
   * that can be stored in the database.
   *
   * @param array $video_meta     Existing video meta data to merge
   *                              new HLS meta data into.
   * @param array $meta_post_data Relevant data from $_POST which include
   *                              all HLS metadata.
   * @param int   $video_index    Index of the video currently being processed,
   *                              so we can retrieve the correct HLS meta
   *                              data for it.
   *
   * @return array Returns an augmented array of the video meta data,
   *               adding HLS meta data into it.
   */
  function parse_post_metadata($video_meta, $meta_post_data, $video_index) {
    if (empty($meta_post_data['hls'])) {
      // if we have no HLS, just return what we received
      return $video_meta;
    }

    if (!empty($meta_post_data['hls'][$video_index]['hlskey'])) {
      // add hls key data
      $hlskey = array(
        'meta_key'   => 'hls_hlskey',
        'meta_value' => $meta_post_data['hls'][$video_index]['hlskey']['value']
      );

      if (!empty( $meta_post_data['hls'][$video_index]['hlskey']['id'] ) ) {
        $hlskey['id'] = $meta_post_data['hls'][$video_index]['hlskey']['id'];
      }

      $video_meta[] = $hlskey;
    }

    return $video_meta;
  }




  public function include_vimeo_media_browser( $force = false) {
    if( is_admin() || $force ) {
      include( dirname(__FILE__).'/media-browser-vimeo.class.php' );
    }
  }


  public function include_peertube_private_media_browser() {
    if( is_admin() ) {
      include( dirname(__FILE__).'/peertube-private-browser.class.php' );
    }
  }


  public function fv_player_coconut_conf( $conf, $args ) {
    // New Coconut API
    if(
      is_array($conf) &&
      !empty($args['encryption']) && empty($args['is_trailer']) &&
      !empty($conf['outputs'])
    ) {

      $encryption_key = openssl_random_pseudo_bytes(16);

      // We need to process all the outputs, some of them might be HLS, like httpstream or httpstream#above4k, but not httpstream#trailer
      foreach( $conf['outputs'] AS $k => $v ) {
        if( stripos($k, '#trailer') === false && !empty($v['hls']) ) {
          $conf['outputs'][$k]['hls']["encryption_mode"] = "AES-128";
          $conf['outputs'][$k]['hls']["encryption_key"] = bin2hex($encryption_key);

          $hls_conf = $conf['outputs'][$k]['hls'];

          // Example: https://site.com/?stream_optim=/target/index
          $url = add_query_arg( 'stream_optim', $hls_conf['path'].'/'.$conf['outputs'][$k]['playlist_name'], home_url('/') );
          $conf['outputs'][$k]['hls']["encryption_key_uri"] = $url;
        }
      }
    }

    // Old Coconut API
    if( !is_array($conf) ) {
      $conf = str_replace( '%encryption%', bin2hex(openssl_random_pseudo_bytes(16)), $conf );

    }
    return $conf;
  }


  public function fv_player_coconut_conf_output( $template, $args ) {
    if( !empty($args['encryption']) && empty($args['is_trailer']) ) {
      $template .= ",\n" . <<< CONF
   hls_encryption_mode=AES-128,
   hls_encryption_key=%encryption%,
   hls_encryption_key_uri=%site%/?stream_optim=/%target%/index
CONF;
    }
    return $template;
  }

}


global $FV_Player_Pro;
$FV_Player_Pro = FV_Player_Pro::_get_instance();

function FV_Player_Pro() {
  return FV_Player_Pro::_get_instance();
}

endif;
