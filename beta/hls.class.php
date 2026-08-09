<?php

/*
 *  What's missing here: the editor part
 */

if( !class_exists('FV_Player_Pro_Hls') ) :

class FV_Player_Pro_Hls {

  var $table_name;

  function __construct() {
    global $wpdb;
    $this->table_name = $wpdb->prefix.'fv_fp_hls_access_tokens';

    // Step 1. Things start here, hls.module.js takes care of sending in Ajax request with a bit obsfucated video URL to play
    if ( isset( $_POST['action'] ) && 'fv_player_performance' === $_POST['action'] ) {
      add_action( 'plugins_loaded', array( $this, 'ajax__store_hls_access_tokens' ) );
    }

    add_action( 'wp_ajax_fv_fp_decrypt_hlskey', array( $this, 'ajax__get_decrypt_hlskey' ) );

    add_action( 'wp_ajax_fv_player_airplay', array( $this, 'allow_airplay' ) );
    add_action( 'wp_ajax_nopriv_fv_player_airplay', array( $this, 'allow_airplay' ) );

    add_filter( 'fv_player_item', array( $this, 'player_item_flag' ) );
    add_filter( 'fv_flowplayer_attributes', array( $this, 'player_attribute_flag' ), 12, 2 );
    add_filter( 'fv_flowplayer_args_pre', array( $this, 'player_stop_autoplay' ), 9, 2 );

    // Step 2. the HLS stream engine comes asking for the key here
    if( !empty($_GET['fv_player_hls_key']) || !empty($_GET['stream_optim']) ) {

      // Avoid showing PHP warnings or notices, as it might break the HLS key for Safari, but keep showing fatal errors
      error_reporting( E_CORE_ERROR | E_COMPILE_ERROR | E_ERROR | E_PARSE | E_USER_ERROR | E_RECOVERABLE_ERROR );
      ini_set( 'display_errors', 1 );

      add_action( 'plugins_loaded', array( $this, 'secure_hls_key_send' ) );
      add_filter( 'redirect_canonical', '__return_false' );
    }

    add_action( 'admin_init', array( $this, 'admin__add_meta_boxes' ) );

    add_action( 'fv_player_pro_update', array( $this, 'plugin_update_database' ) );

    add_filter('fv_flowplayer_conf', array($this, 'allow_chromecast_for_encrypted_hls'), 10, 2);

    add_filter( 'fv_player_drm_stream_loader_output', array( $this, 'decryption_key_subdomains' ) );

    add_filter( 'fv_player_drm_stream_loader_output', array( $this, 'add_domain' ), 12 );

    if( is_admin() ) {
      add_action( 'wp_ajax_fv_player_pro_add_encrypted_domain', array( $this, 'add_encrypted_domain' ) );
      add_action( 'wp_ajax_fv_player_pro_remove_encrypted_domain', array( $this, 'remove_encrypted_domain' ) );
    }

  }

  function admin__add_meta_boxes() {
    // This could be useful if the tokens would not completely vanish once used
    // it could use some flag for example. But in current state let's keep it hidden
    if( isset($_GET['debug']) ) {
      add_meta_box( 'fv_player_pro_hls_tokens', __('Encrypted HLS tokens', 'fv-wordpress-flowplayer'), array( $this, 'admin__list_hls_tokens' ), 'fv_flowplayer_settings_tools', 'normal', 'low' );
    }

    add_meta_box( 'fv_player_pro_encrypted_playback', __('Encrypted Playback for 3rd Party Domains (Pro)', 'fv-player-pro'), array( $this, 'admin__encrypted_playback_domains' ), 'fv_flowplayer_settings_tools', 'normal', 'low' );

    // Amazon Elastic Transcoder is slowly getting deprecated
    if( !FV_Player_Pro()->_get_option( array('pro','elastic_key') ) ) {
      return;
    }

    add_meta_box( 'fv_player_pro_aws_decoder', __('Amazon AWS Decoder (Pro)', 'fv-player-pro'), array( $this, 'admin__aws_decoder' ), 'fv_flowplayer_settings_hosting', 'normal', 'low' );

  }

  // Amazon Elastic Transcoder is slowly getting deprecated
  function admin__aws_decoder() {
    if(version_compare(phpversion(),'5.5.0','<')){
      ?>
      Your PHP version must be Newer than <b>5.5.0</b>

      <?php
      return;
    }
    ?>
    <table class="form-table2" style="margin: 5px; ">
      <tr>
        <td colspan="2">
          <p>
<strong><?php _e('Amazon Elastic Transcoder is slowly getting deprecated, AWS Elemental MediaConvert or <a href="https://foliovision.com/player/securing-your-video/encrypted-hls-coconut" target="_blank">Coconut</a> should be used instead.', 'fv-player-pro'); ?></strong>
          </p>
          <p>
<?php _e('You need to setup your key decoder to be able to use encrypted HLS streams on AWS. See our <a href="https://foliovision.com/player/video-hosting/securing-your-video/hls-stream" target="_blank">HLS guide</a>.', 'fv-player-pro'); ?>
          </p>
        </td>
      </tr>
      <tr>
        <td style="vertical-align:top"><label for="pro[elastic_key]"><?php _e('Access Key ID', 'fv-player-pro'); ?>:</label></td>
        <td>
          <input type="text" size="40" name="pro[elastic_key]" id="pro[elastic_key]" value="<?php echo FV_Player_Pro()->_get_option( array('pro','elastic_key') ); ?>" />
        </td>
      </tr>
      <tr>
        <td><label for="pro[elastic_secret]"><?php _e('Access Key Secret', 'fv-player-pro'); ?>:</label></td>
        <td>
          <input type="text" size="40" name="pro[elastic_secret]" id="pro[elastic_secret]" value="<?php echo FV_Player_Pro()->_get_option( array('pro','elastic_secret') ); ?>" />
        </td>
      </tr>
      <tr>
        <?php $sRegion = FV_Player_Pro()->_get_option( array('pro','elastic_region') ); ?>
        <td><label for="pro[elastic_region]"><?php _e('Region', 'fv-player-pro'); ?>:</label></td>
        <td>
          <select id="pro[elastic_region]" name="pro[elastic_region]">
            <option value=""><?php _e('Select the region', 'fv-player-pro'); ?></option>
            <option value="eu-central-1"<?php if ($sRegion === 'eu-central-1') echo " selected"; ?>><?php _e('Frankfurt', 'fv-player-pro'); ?></option>
            <option value="eu-west-1"<?php if ($sRegion === 'eu-west-1') echo " selected"; ?>><?php _e('Ireland', 'fv-player-pro'); ?></option>
            <option value="us-west-1"<?php if ($sRegion === 'us-west-1') echo " selected"; ?>><?php _e('Northern California', 'fv-player-pro'); ?></option>
            <option value="us-west-2"<?php if ($sRegion === 'us-west-2') echo " selected"; ?>><?php _e('Oregon', 'fv-player-pro'); ?></option>
            <option value="sa-east-1"<?php if ($sRegion === 'sa-east-1') echo " selected"; ?>><?php _e('Sao Paulo', 'fv-player-pro'); ?></option>
            <option value="ap-southeast-1"<?php if ($sRegion === 'ap-southeast-1') echo " selected"; ?>><?php _e('Singapore', 'fv-player-pro'); ?></option>
            <option value="ap-southeast-2"<?php if ($sRegion === 'ap-southeast-2') echo " selected"; ?>><?php _e('Sydney', 'fv-player-pro'); ?></option>
            <option value="ap-northeast-1"<?php if ($sRegion === 'ap-northeast-1') echo " selected"; ?>><?php _e('Tokyo', 'fv-player-pro'); ?></option>
            <option value="us-east-1"<?php if ($sRegion === 'us-east-1') echo " selected"; ?>><?php _e('US Standard', 'fv-player-pro'); ?></option>
          </select>
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

  function admin__list_hls_tokens() {
    echo "<p>Your IP: <code>".FV_Player_Pro()->get_client_ip()."</code></p>";
    global $wpdb;
    $tokens = $wpdb->get_results( "SELECT * FROM `{$this->table_name}` ORDER BY id DESC" );
    if( $tokens ) {
      echo "<table class='widefat'>";
        echo "<thead><tr>";
        foreach( (array) $tokens[0] AS $key => $value ) {
          echo "<th>".ucfirst($key)."</th>";
        }
        echo "</tr></thead>";

        echo "<tbody>";
        foreach( $tokens AS $token ) {
          echo "<tr>";
          foreach( (array) $token AS $value ) {
            echo "<td>".$value."</td>";
          }
          echo "</tr>";
        }
        echo "</tbody>";
      echo "</table>";
    }
  }

  // Amazon Elastic Transcoder is slowly getting deprecated
  function ajax__get_decrypt_hlskey() {
    global $fv_fp;

    if( version_compare(phpversion(),'5.5.0','<') ){
      die('php must be > 5.5.0');
    }

    if( empty($_POST['cryptic']) || strlen($_POST['cryptic']) < 150 ) {
      die('cryptic');
    }

    if( !FV_Player_Pro()->_get_option( array('pro','elastic_key') ) ||
        !FV_Player_Pro()->_get_option( array('pro','elastic_region') ) ||
        !FV_Player_Pro()->_get_option( array('pro','elastic_region') )
    ) {
      die('settings');
    }

    $result = false;
    if(!class_exists('\Aws\Kms\KmsClient')){
      require dirname(__FILE__) . '/../includes/aws/aws-autoloader.php';
    }

    //php5.2 compatibility - calls inside cause PHP parse error on 5.2
    require dirname(__FILE__) . '/fv-aws-function.php';
    die( base64_encode($result->get('Plaintext')) );

  }

  function admin__encrypted_playback_domains() {
    $domains = get_option( 'fv_player_pro_encrypted_hls_domains', array() );

    ?>
    <style>
      #fv_wp_flowplayer_encryped_domains_list li a {
        visibility: hidden;
      }
      #fv_wp_flowplayer_encryped_domains_list li:hover a {
        visibility: visible;
      }
    </style>

    <table class="form-table2" style="margin: 5px;">
      <tr>
        <td colspan="2">
          <p>
            <?php _e('Any video encrypted for FV Player uses the actual website to get the decryption key. This improves the video protection but also breaks the video playback if your website domain changes.', 'fv-player-pro'); ?>
          </p>
          <p>
            <?php _e('Use this tool to enter the website domains which should be allowed to play on your website. You will still need the decryption key in your FV Player database and the domain has to have a license too - to prevent video piracy. Note that all the subdomains are allowed by default.', 'fv-player-pro'); ?>
          </p>
        </td>
      </tr>
      <tr>
        <td colspan="2">
          <ul id="fv_wp_flowplayer_encryped_domains_list">
            <?php foreach ($domains as $domain) : ?>
              <li><?php echo $domain; ?><a href="#"><span class="dashicons dashicons-trash fv_wp_flowplayer_encryped_domain_delete"></span></a></li>
            <?php endforeach; ?>
          </ul>
        </td>
      </tr>
      <tr>
        <td style="width: 250px;">
        <label><?php _e('Add domain', 'fv-player-pro'); ?>:</label>
        </td>
        <td>
          <input id="fv_wp_flowplayer_encryped_domain" type="text" placeholder="mydomain.com" style="width: calc( 100% - 10em );"/>
          <input type="button" class="button" id="fv_wp_flowplayer_encryped_add_domain" value="<?php _e('Add domain', 'fv-player-pro'); ?>" style="margin-top: 0;"/>
          <span id="fv_wp_flowplayer_encryped_domain_indicator" style="display: none"><img data-fv-player-wizard-indicator width="16" height="16" src="<?php echo site_url('wp-includes/images/wpspin-2x.gif'); ?>" /></span>
        </td>
      </tr>
    </table>

    <script>
      jQuery(function() {
        jQuery('#fv_wp_flowplayer_encryped_add_domain').on('click', function() {
          var indicator = jQuery('#fv_wp_flowplayer_encryped_domain_indicator');
          var domain = jQuery('#fv_wp_flowplayer_encryped_domain').val();

          indicator.show();

          if (domain.length > 0) {
            jQuery.post(fv_player_pro.ajaxurl, {
              action: 'fv_player_pro_add_encrypted_domain',
              nonce: '<?php echo wp_create_nonce( 'fv_player_pro_add_encrypted_domain' ); ?>',
              domain: domain
            }, function(response) {
              if (response.success) {
                jQuery('#fv_wp_flowplayer_encryped_domain').val('');
                jQuery('#fv_wp_flowplayer_encryped_domains_list').append('<li>' + domain + '<a href="#"><span class="dashicons dashicons-trash fv_wp_flowplayer_encryped_domain_delete"></span></a></li>');
              } else {
                alert(response.error);
              }
              indicator.hide();
            }).fail(function() {
              indicator.hide();
              alert('Error: Invalid request');
            });
          } else {
            indicator.hide();
            alert('Error: No domain specified');
          }
        });

        jQuery('#fv_wp_flowplayer_encryped_domains_list').on('click', '.fv_wp_flowplayer_encryped_domain_delete', function() {
          var domain = jQuery(this).closest('li').text();
          var element = this;
          jQuery.post(fv_player_pro.ajaxurl, {
            action: 'fv_player_pro_remove_encrypted_domain',
            nonce: '<?php echo wp_create_nonce( 'fv_player_pro_remove_encrypted_domain' ); ?>',
            domain: domain
          }, function(response) {
            if (response.success) {
              jQuery(element).closest('li').remove();
            }
          });

          return false;
        });
      });

    </script>
    <?php
  }

  function add_encrypted_domain() {
    $result = array('success' => false);

    if( isset($_POST['domain']) && isset($_POST['nonce']) && wp_verify_nonce( $_POST['nonce'], 'fv_player_pro_add_encrypted_domain' ) ) {
      $domain = sanitize_text_field($_POST['domain']);
      $domain = rtrim(trim($domain), '/');

      // strip http(s)://
      $domain = preg_replace('/^https?:\/\//', '', $domain);

      // strip www.
      $domain = preg_replace('/^www\./', '', $domain);

      // check if domain is valid
      $valid = preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)*(\.[a-z]{2,4})$/', $domain);

      if( empty($domain) || !$valid ) {
        $result['error'] = 'Error: Invalid domain';
        wp_send_json($result);
      }

      $args = array(
        'body' => array(
          'plugin' => 'fv-wordpress-flowplayer',
          'type' => $domain,
          'action' => 'check',
        ),
        'timeout' => 10,
      );

      $resp = wp_remote_post('https://license.foliovision.com/?fv_remote=true', $args );
      $data = array();

      if(
        !is_wp_error($resp) &&
        isset($resp['body']) &&
        $resp['body'] &&
        $decoded = json_decode(
          preg_replace(
            '~[\s\s]*?<FVFLOWPLAYER>(.*?)</FVFLOWPLAYER>[\s\s]*?~',
            '$1',
            $resp['body']
          )
        )
      ) {
        $data = $decoded;
      }

      if( !empty($data) && $data->valid ) {
        $domains = get_option( 'fv_player_pro_encrypted_hls_domains', array() );
        if ( !in_array($domain, $domains) ) {
          $domains[] = $domain;
          update_option( 'fv_player_pro_encrypted_hls_domains', $domains );
        }

        $result['success'] = true;
      } else {
        $result['error'] = "I'm sorry, you do not have the FV Player license for this domain.";
      }
    } else {
      $result['error'] = 'Error: Invalid request';
    }

    wp_send_json($result);
  }

  function remove_encrypted_domain() {
    $result = array('success' => false);

    if( isset($_POST['domain']) && isset($_POST['nonce']) && wp_verify_nonce( $_POST['nonce'], 'fv_player_pro_remove_encrypted_domain' ) ) {
      $domain = sanitize_text_field($_POST['domain']);
      $domain = rtrim(trim($domain), '/');

      // strip http(s)://
      $domain = preg_replace('/^https?:\/\//', '', $domain);

      // strip www.
      $domain = preg_replace('/^www\./', '', $domain);

      $domains = get_option( 'fv_player_pro_encrypted_hls_domains', array() );
      if ( ($key = array_search($domain, $domains)) !== false ) {
        unset($domains[$key]);
        update_option( 'fv_player_pro_encrypted_hls_domains', $domains );
      }

      $result['success'] = true;
    } else {
      $result['error'] = 'Error: Invalid request';
    }

    wp_send_json($result);
  }

  /*
   * Step 1. Stores the playback request for the video URL, IP and user agent
   */
  function ajax__store_hls_access_tokens(){
    $url = apply_filters( 'fv_player_pro_store_hls_access_tokens', $_POST['summary'] );

    // TODO: Clean up these cached values:
    // $option_name = 'fv_player_pro_ajax__store_hls_access_tokens-'.md5($url);

    $this->store_hls_access_tokens($url);

    // If it's an iCloud Private Relay IP
    if (
      (
        stripos($_SERVER['HTTP_USER_AGENT'],'iPhone') !== false ||
        stripos($_SERVER['HTTP_USER_AGENT'],'iPad') !== false ||
        stripos($_SERVER['HTTP_USER_AGENT'],'iPod') !== false
      ) &&
      $this->is_icloud_private_relay()
    ) {
      wp_send_json_success( array( 'icloud_private_relay' => 'maybe' ) );
    }

    die;
  }

  function allow_airplay() {
    $airplay_allowed_ips = get_option( 'fv_player_hls_airplay', array() );

    $airplay_allowed_ips[ FV_Player_Pro()->get_client_ip() ] = time();

    update_option( 'fv_player_hls_airplay', $airplay_allowed_ips, false );
  }

  function allow_chromecast_for_encrypted_hls( $aConf ) {
    if( FV_Player_Pro()->_get_option( array('pro', 'chromecast_enc_hls') ) ) {
      $aConf['hls_cast'] = true;
    }
    return $aConf;
  }

  function debug_log( $message ) {
    if( FV_Player_Pro()->_get_option( array('pro', 'debug_log') ) === 'verbose' ) {
      file_put_contents( ABSPATH.'fv-player-debug-'.wp_hash('fv-player-debug-log','log').'.log', date('Y-m-d h:i:s') . ' ' . $message . "\n\n", FILE_APPEND );
    }
  }

  /**
   * Checks if the EXT-X-KEY URI is using one of the website sub-domains. If so, it changes it to the current website.
   *
   * Thanks to this you can encrypt (upload with FV Player Coconut) your videos on staging website like dev.site.com
   * and then still let it play on the live website at site.com
   *
   * @param string $manifest  The m3u8 manifest file
   *
   * @return string                  data-item attribute of the player/playlist item with added flag for encrypted HLS
   */
  function decryption_key_subdomains( $manifest ) {
    // Is the video encrypted at all?
    if( stripos( $manifest, '#EXT-X-KEY' ) ) {

      // Match the EXT-X-KEY line
      if( preg_match( '~#EXT-X-KEY.*?URI="(.*)"~', $manifest, $ext_x_key ) ) {

        // Compare the domain of the key and the website
        $key_host = parse_url( $ext_x_key[1], PHP_URL_HOST );
        $site_host = parse_url( home_url(), PHP_URL_HOST );

        $key_host_parts = explode( ".", $key_host);
        $site_host_parts = explode( ".", $site_host);

        if( count($key_host_parts) >= 2 && count($site_host_parts) >= 2 ) {

          // dump the TLD
          unset( $key_host_parts[ count($key_host_parts) -1 ] );
          unset( $site_host_parts[ count($site_host_parts) -1 ] );

          // get the second level domain
          $site_host_parts = array_reverse( $site_host_parts );
          $key_host_parts = array_reverse( $key_host_parts );

          // is the second level domain matches, change the EXT-X-KEY URI to the curent website domain
          if( $site_host_parts[0] == $key_host_parts[0] ) {

            // replace the host in the EXT-X-KEY line
            $new_ext_x_key = str_replace( $key_host, $site_host, $ext_x_key[0] );
            $manifest = str_replace( $ext_x_key[0], $new_ext_x_key, $manifest );
          }
        }

        // Deal with & in the decryption key argument value - not properly encoded unfortunately
        $query = wp_parse_url( $ext_x_key[1], PHP_URL_QUERY );

        /**
         * This just considers everything after first = to be the query argument and URL encodes it.
         * So it helps with URLs like this:
         *
         * https://site.com/?stream_optim=/Ballad-Voicings,-Fills,-&-Improv/1-A-Section-Harmony-Drills/index
         *
         * There is never another query query argument, the "&" symbol in the above kind of URL makes that impossible anyway.
         */
        $new_query = preg_replace_callback( '~=(.+)~', array( $this, 'decryption_key_url_replace' ), $query );

        $manifest = str_replace( $ext_x_key[1], str_replace( $query, $new_query, $ext_x_key[1] ), $manifest );
      }
    }
    return $manifest;
  }

  function decryption_key_url_replace( $match ) {
    return '=' . urlencode( $match[1] );
  }

  function add_domain($body) {
    $domains = get_option( 'fv_player_pro_encrypted_hls_domains', array() );

    $domain_variants = array();

    // create all possible domain variants
    foreach( $domains as $domain) {
      $domain .=  '/';
      $domain_variants[] = 'http://www.'.$domain;
      $domain_variants[] = 'https://www.'.$domain;
      $domain_variants[] = 'http://'.$domain;
      $domain_variants[] = 'https://'.$domain;
    }

    $body = str_replace( $domain_variants, home_url('/'), $body );

    return $body;
  }

  function get_client_id() {
    if ( FV_Player_Pro()->_get_option( array( 'pro', 'cookie_enc_hls' ) ) ) {
      return ! empty( $_COOKIE['fv_player_hls_access_token'] ) ? $_COOKIE['fv_player_hls_access_token'] : false;
    } else {
      return FV_Player_Pro()->get_client_ip();
    }
  }

  function get_key_request($url) {
    global $wpdb;
    $seconds = ( $this->is_ios() || self::is_airplay() || $_SERVER['HTTP_USER_AGENT'] == 'foliovision-thumbs' ) ? "3600" : "30";

    $sql = $wpdb->prepare("SELECT * FROM `{$this->table_name}` WHERE client = %s AND url LIKE %s AND time > DATE_SUB(NOW(),INTERVAL %d SECOND)",
      $this->get_client_id(),
      '%'.$wpdb->esc_like( sanitize_title($url) ).'%',
      $seconds
    );

    /**
     * We have to do this outside of $wpdb->prepare() call
     * As of WordPress 6.2, wpdb::prepare() supports identifiers via '%i', e.g. table/field names.
     */
    if ( ! $this->is_ios() && ! self::is_airplay() ) {
      $sql .= "AND (
        cookie NOT LIKE '%iPad%' AND cookie NOT LIKE '%iPod%' AND cookie NOT LIKE '%iPhone%'
        OR SUBSTRING(cookie, INSTR(cookie,' OS ')+4, 2 ) > 12
      )";
    }

    $sql .= "ORDER BY ID DESC";

    return $wpdb->get_results($sql, ARRAY_A);
  }

  public static function is_airplay_allowed() {
    $airplay_allowed_ips = get_option( 'fv_player_hls_airplay', array() );
    $ip = FV_Player_Pro()->get_client_ip();

    return !empty($airplay_allowed_ips[$ip]) && ( $airplay_allowed_ips[$ip] + DAY_IN_SECONDS ) > time();
  }

  public static function is_airplay() {
    return self::is_airplay_allowed() && (
      // AppleCoreMedia/1.0.0.18M60 (Apple TV; U; CPU OS 14_7 like Mac OS X; en_ca)
      preg_match( '~^AppleCoreMedia/.*?\(Apple TV; U; CPU OS [1-9_]+ like Mac OS X;~', $_SERVER['HTTP_USER_AGENT']) && !empty($_SERVER['HTTP_X_PLAYBACK_SESSION_ID']) ||

      // AirPlay on Roku Premiere
      // AirPlay/2.0 (App/31.101.0) MFi_AirPlay_Device (MFiModelGroup/NwZoHE73IZgguwepayx8nDUww6-gTbsdy49QpYhEBV8)
      preg_match( '~^AirPlay/[0-9.]+\s*\(App/[0-9.]+\)\s*MFi_AirPlay_Device\s*\(MFiModelGroup/[A-Za-z0-9-]+\)~', $_SERVER['HTTP_USER_AGENT']) ||

      // AirPlay to a Mac computer: AppleCoreMedia/1.0.0.21C52 (Macintosh; U; Intel Mac OS X 12_1; en_gb)
      // it's different than when playing in Safari directly (Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.5 Safari/605.1.15)
      preg_match( '~^AppleCoreMedia/.*?\(Macintosh; U; ~', $_SERVER['HTTP_USER_AGENT']) && !empty($_SERVER['HTTP_X_PLAYBACK_SESSION_ID'])
    );
  }

  function is_icloud_private_relay() {
    $ip = FV_Player_Pro()->get_client_ip();

    $icloud_private_replace_ips = file_get_contents( __DIR__ . '/icloud-private-relay-ips.list' );

    if ( $icloud_private_replace_ips ) {
      $icloud_private_replace_ips = explode( "\n", $icloud_private_replace_ips );

      foreach( $icloud_private_replace_ips as $icloud_private_replace_ip ) {
        $icloud_private_replace_ip = trim( $icloud_private_replace_ip );
        if ( ! empty( $icloud_private_replace_ip ) && strpos( $ip, $icloud_private_replace_ip ) === 0 ) {
          return true;
        }
      }
    }

    return false;
  }

  function is_ios() {
    return !empty($_SERVER['HTTP_X_PLAYBACK_SESSION_ID']) && (
      stripos($_SERVER['HTTP_USER_AGENT'],'iPhone') !== false ||
      stripos($_SERVER['HTTP_USER_AGENT'],'iPad') !== false ||
      stripos($_SERVER['HTTP_USER_AGENT'],'iPod') !== false
    );
  }

  function is_safari() {
    $agent = $_SERVER['HTTP_USER_AGENT'];
    return !empty($_SERVER['HTTP_X_PLAYBACK_SESSION_ID']) && stripos($agent,'Mac OS X') !== false && preg_match("/Version\/[\d\.]+.*Safari/", $agent);
  }

  function plugin_update_database(){
    global $wpdb;

    $sql = "CREATE TABLE ".$wpdb->prefix."fv_fp_hls_access_tokens (
      id int(11) NOT NULL auto_increment,
      time timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
      url varchar(1024) NOT NULL,
      client varchar(1024) NOT NULL,
      cookie varchar(1024) NOT NULL,
      PRIMARY KEY  (id),
      KEY url (url),
      KEY client (client)
    )" . $wpdb->get_charset_collate() . ";";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

    $res = dbDelta( $sql );

    $this->debug_log( "Encrypted HLS playback table creation: " . var_export( $res, true ) );
  }

  /**
   * Adds an encrypted HLS flag to a FV Player video to make sure the player
   * knows it should obtain the playback token
   *
   * Works with the FV Player instances loaded from database
   *
   * @param array $data_item        data-item attribute of the player/playlist item
   *
   * @global object $fv_fp          FV Player instance
   *
   * @return array                  data-item attribute of the player/playlist item with added flag for encrypted HLS
   */
  function player_item_flag( $data_item ) {
    global $fv_fp;

    if( FV_Player_Pro()->is_db() && method_exists($fv_fp,'current_video') && $fv_fp->current_video() ) {
      if ($meta = $fv_fp->current_video()->getMetaData()) {
        foreach ($meta as $meta_object) {
          if ($meta_object->getMetaKey() == 'hls_hlskey') {
            $data_item['fvhkey'] = 'true';
          }
        }
      }
    }

    return $data_item;
  }

  /**
   * Adds an encrypted HLS flag to a FV Player HTML container to make sure the player
   * knows it should obtain the playback token
   *
   * Works with the FV Player instances posted via shortcode (no database ID)
   *
   * @param array $data_item        Array of HTML attributes of the player element
   *
   * @global object $fv_fp          FV Player instance
   *
   * @return array                  Array of HTML attributes of the player element with added flag for encrypted HLS
   */
  function player_attribute_flag( $aAttributes ) {
    global $fv_fp;

    if( !FV_Player_Pro()->is_db() || !method_exists($fv_fp,'current_video') || !$fv_fp->current_video() ) {
      if ( isset( $fv_fp->aCurArgs['hlskey'] ) ) {
        $aAttributes['data-fvhkey'] = 'true';
      }
    }

    return $aAttributes;
  }

  /**
   * If the player is using encrypted HLS, we stop the autoplay.
   *
   * TODO: Is this still needed? In past the player would only request
   * hls_access_token if clicked by user, but then we changed that to work with
   * Flowplayer load JS event to support playlists.
   *
   * @param array $data_item        Array of player arguments
   *
   * @return array                  Array of player arguments with MAYBE stopped autoplay
   */
  function player_stop_autoplay( $aArgs ) {
    if( !empty($aArgs['hlskey']) ) {
      $aArgs['autoplay'] = 'false';
    }
    return $aArgs;
  }

  public static function remove_subdomain( $domain ) {
    return preg_replace( '~^(www|dev|stage|staging)\.~', '', $domain );
  }

  /**
   * The HLS AES-128 key stored in database or shortcode can either be base64
   * or hex encoded. In this function we perform both decoding tasks to see
   * which one gives us a 16 byte string
   *
   * @param string $val             HLS key to decode
   *
   * @return string|bool            Decoded HLS key or false if it failed.
   */
  function secure_hls_key_decode( $val ) {
    $is_it_base64 = base64_decode($val);
    if( strlen($is_it_base64) == 16 ){
      return apply_filters('fv_player_pro_secure_hls_key_decode', $is_it_base64);
    }

    $is_it_hex = hex2bin($val);
    if( strlen($is_it_hex) == 16 ){
      return apply_filters('fv_player_pro_secure_hls_key_decode', $is_it_hex);
    }

    return false;
  }

  /*
   * Step 2. This is where we get the request from the HLS playback engine
   */
  function secure_hls_key_send() {
    // We could potentionally set it to cache here for iOS and thus solve the
    // issue or it running another request for the decryption key when you
    // wake-up the device, but it doesn't seem to cache these key requests!
    @header("Expires: 0");
    @header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
    @header("Cache-Control: no-store, no-cache, must-revalidate");
    @header("Cache-Control: post-check=0, pre-check=0", false);
    @header("Pragma: no-cache");

    // CORS for Chromecast
    if( FV_Player_Pro()->_get_option( array('pro', 'chromecast_enc_hls') ) && strpos($_SERVER['HTTP_USER_AGENT'], 'CrKey') ) {
      @header('Access-Control-Allow-Methods: GET,POST,OPTIONS');
      @header('Access-Control-Allow-Origin: *'); // using *.gstatic.com won't work

    // Sometimes the user might enter https://www.site.com/?fv_player_hls_key=video instead of https://site.com/?fv_player_hls_key=video
    // So we check if the referring URL is matching the WordPress homepage
    // $_SERVER['HTTP_HOST'] is the domain for which this Ajax is running and $_SERVER['HTTP_ORIGIN'] is the website URL where that request was made
    } else if( !empty($_SERVER['HTTP_ORIGIN']) && !empty($_SERVER['HTTP_HOST']) ) {
      $origin = parse_url( $_SERVER['HTTP_ORIGIN'], PHP_URL_HOST );
      // we check if the hostnames differ in starting www. only
      if( strcmp( self::remove_subdomain( $origin ), self::remove_subdomain( $_SERVER['HTTP_HOST'] ) ) === 0 ) {
        // then we let the Ajax from the website through
        // but we still check if it's matching the WordPress homepage
        $home = parse_url( home_url(), PHP_URL_HOST );
        if( strcmp( self::remove_subdomain( $home ), self::remove_subdomain( $origin ) ) === 0 ) {
          @header("Access-Control-Allow-Origin: ".$_SERVER['HTTP_ORIGIN']);
        }
      }
    }

    global $wpdb, $FV_Player_Db;
    $wpdb->query("DELETE FROM `{$this->table_name}` WHERE `time` <= DATE_SUB( NOW(),INTERVAL 3600 SECOND ) ");

    $key = !empty($_GET['stream_optim']) ? $_GET['stream_optim'] : $_GET['fv_player_hls_key'];  //  video-cdn.com/{mystream}/index.m3u8
    $authorized = false;

    // What was actually requested?
    $aResults = false;

    // we give it 5 tries, as the "fv_player_performance' Ajax call handled by ajax__store_hls_access_tokens() might be arriving later than the m3u8 file
    for ($try = 1; $try < 5; $try++) {

      // Blindly allowing Chromecast
      if(
        FV_Player_Pro()->_get_option( array('pro', 'chromecast_enc_hls') ) &&
        strpos($_SERVER['HTTP_USER_AGENT'], 'CrKey') !== false &&
        !empty($_SERVER['HTTP_CAST_DEVICE_CAPABILITIES']) &&
        (
          strpos($_SERVER['HTTP_ORIGIN'], 'gstatic.com') !== false && strpos($_SERVER['HTTP_REFERER'], 'gstatic.com') !== false ||
          strpos($_SERVER['HTTP_REFERER'], 'https://foliovision.com/') === 0 ||
          strpos($_SERVER['HTTP_REFERER'], 'https://cdn.foliovision.com/') === 0
        )
      ) {
        $authorized = true;
        break;
      }

      $aResults = $this->get_key_request($key);

      // if we didn't the request, we try to escape the URL differently
      if( empty($aResults) ) {
        $aResults = $this->get_key_request( str_replace(' ', '+', $key) );
      }

      // also try to decode the argument, as on some hosts /?fv_player_hls_key=module-8/1/
      // might get redirected to /?fv_player_hls_key=module-8%2F1%2F
      if( empty($aResults) ) {
        $aResults = $this->get_key_request( urldecode($key) );
      }

      if( !empty($aResults) || $authorized ) {
        // each token is one time only, except for iOS devices
        // unfortunately it requires the decryption key again if you wake-up
        // the device, and it can't cache it, even if you set the headers as
        // such. Same if you enable AirPlay
        if( !$this->is_ios() && !self::is_airplay() ) {
          $wpdb->query("DELETE FROM `{$this->table_name}` WHERE `id` = ".$aResults[0]['id']);
        }
        $authorized = true;
        break;
      }

      usleep( $try * 500000 );
    }

    $authorized = apply_filters( 'fv_player_pro_secure_hls_key_send', $authorized );

    if(
      stripos($_SERVER['HTTP_USER_AGENT'],'coc_coc_browser') !== false ||
      stripos($_SERVER['HTTP_USER_AGENT'],'Lavf') !== false
    ) {
      $authorized = false;
    }

    $this->debug_log( "Encrypted HLS key request from " . $this->get_client_id() . " for " . $key . " is authorized? " . $authorized );

    if (!$authorized) {
      // if you are not authorized to play it's better to give a false decryption key
      // as that way Video Download Helper doesn't complain about AES key length
      // which only raises suspicion
      @header('Content-Type: binary/octet-stream');

      $hex = '';
      for ($i=0; $i<strlen( substr($key.$key.$key.$key.$key.$key.$key.$key.$key.$key.$key,1,16) ); $i++){
          $ord = ord($key[$i]);
          $hexCode = dechex(255-$ord);
          $hex .= substr('0'.$hexCode, -2);
      }

      echo hex2bin($hex);
      die();
    }

    // now we need to find the right decryption key
    // we try with multiple values, as the video URL might come in encoded differently
    // we also assume that out of https://video-cdn.com/mystream/index.m3u8 people enter mystream/index or just mystream in the worst case
    // really, it's a problem as m3u8 has to be part of it too
    $aKeys = array( trailingslashit($key), $key.'.m3u8' );
    if( stripos($key,' ') !== false ) {
      $new_key = str_replace( ' ', '+', $key );
      $aKeys[] = trailingslashit($new_key);
      $aKeys[] = $new_key.'.m3u8';
    }

    foreach( $aKeys AS $try_key ) {  // has to be either the m3u8 directory or the directory and full filename
      $this->debug_log( "Encrypted HLS key request from " . $this->get_client_id() . " for " . $key . " trying with: " . $try_key . " in database..." );

      if(method_exists($FV_Player_Db,'query_videos') ) {
        $query_videos = array(
          'fields_to_search' =>  array('src'),
          'search_string' => $try_key,
          'like' => true,
          'and_or' => 'OR'
        );

        $vids = $FV_Player_Db->query_videos($query_videos);
        if( !empty($vids) ) {
          foreach( $vids AS $vid ) {
            // If the video name matches multiple videos, we check if it matches what was actually requested
            if(
              count($vids) > 1 &&
              !empty($aResults) && $aResults[0]['url'] != sanitize_title( $vid->getSrc() )
            ) {
              continue;
            }

            if( $meta = $vid->getMetaData() ) {
              foreach ($meta as $meta_object) {
                if ($meta_object->getMetaKey() == 'hls_hlskey') {
                  $this->secure_hls_key_send_headers( $meta_object->getMetaValue() );
                }
              }
            }
          }

          // If the above did not give any match, we just try one video after another
          foreach( $vids AS $vid ) {
            if( $meta = $vid->getMetaData() ) {
              foreach ($meta as $meta_object) {
                if ($meta_object->getMetaKey() == 'hls_hlskey') {
                  $this->secure_hls_key_send_headers( $meta_object->getMetaValue() );
                }
              }
            }
          }
        }
      } else if( class_exists('FV_Player_Db_Video') ) {
        $vid = new FV_Player_Db_Video(null, array(
          'src' => $try_key
        ), $FV_Player_Db);

        // search for this video in src field and only return ID, which will be used
        // to load meta data with the correct hls key
        if ($vid && method_exists($vid, 'searchBySrc') && $vid->searchBySrc(true, 'id') && ($meta = $vid->getMetaData())) {
          foreach ($meta as $meta_object) {
            if ($meta_object->getMetaKey() == 'hls_hlskey') {
              $this->secure_hls_key_send_headers( $meta_object->getMetaValue() );
            }
          }
        }
      }
    }

    foreach( $aKeys AS $try_key ) {  // has to be either the m3u8 directory or the directory and full filename

      $this->debug_log( "Encrypted HLS key request from " . $this->get_client_id() . " for " . $key . " trying with: " . $try_key . " in posts..." );

      $aAllPosts = $wpdb->get_results("SELECT `post_content`, ID FROM `" . $wpdb->posts . "` WHERE `post_status` NOT IN ('trash', 'auto-draft', 'inherit') AND `post_content` LIKE '%" . esc_sql($try_key) . "%' ORDER BY post_modified DESC");
      if (!empty($aAllPosts)) {
        foreach ($aAllPosts as $aOnePost) {
          $sOnePost = $aOnePost->post_content;
          $aMatches = array();
          $aMatches2 = array();
          //  OptimizePress seems to render the shortcode HTML into the post content, so then the shortcode can't be matched. But it's still found in some textarea where it's encoded, so we also try urldecode()
          $try_key = preg_quote($try_key,'~');

          // now we look for the [fvplayer] shortcode with that video URL
          // in some weird cases we have seen base64 encoded parts of post body due to some pagebuilder too!
          if( preg_match('~\[fvplayer[^\]]*' . $try_key . '[^\]]*\]~i', $sOnePost, $aMatches) || preg_match('~\[fvplayer[^\]]*' . $try_key . '[^\]]*\]~i', urldecode($sOnePost), $aMatches) ) {
            if( ( preg_match('/(?:hlskey=")([^"]*)(?:")/', $aMatches[0], $aMatches2) || preg_match('/(?:hlskey=")([^"]*)(?:")/', html_entity_decode($aMatches[0]), $aMatches2) ) && strlen($aMatches2[1]) == 24 ) {
              $this->secure_hls_key_send_headers( $aMatches2[1] );
            }
          }
        }
      }

    }

    exit;
  }

  function secure_hls_key_send_headers( $key ) {
    header('Content-Type: binary/octet-stream');
    ob_get_clean(); // this removes the whitespace/empty newline that might by added by some PHP code

    $stream = !empty($_GET['stream_optim']) ? $_GET['stream_optim'] : $_GET['fv_player_hls_key'];

    $this->debug_log( "Encrypted HLS key request from " . $this->get_client_id() . " for " . $stream . " sending key: " . $key );

    die( $this->secure_hls_key_decode($key) );
  }

  function store_hls_access_tokens( $url, $custom_ip = false ) {
    global $wpdb;

    if ( $wpdb->get_var("SHOW TABLES LIKE '$this->table_name'") != $this->table_name) {
      $this->plugin_update_database();
    }

    $ip = $custom_ip ? $custom_ip : $this->get_client_id();

    $this->debug_log( "Encrypted HLS playback request from " . $ip . " for " . $url );

    $res = $wpdb->insert( $this->table_name, array( // video token
        'url' => sanitize_title($url),
        'client' => $ip,
        'cookie' => $_SERVER['HTTP_USER_AGENT']
    ));

    if( $res ) {
      $this->debug_log( "Encrypted HLS playback request from " . $ip . " for " . $url . " stored." );

    } else { //  wpdb::strip_invalid_text() bug, see ticket 85672724
      $this->debug_log( "Encrypted HLS playback request from " . $ip . " for " . $url . " failed to store: " . $wpdb->last_error . ", retrying..." );

      $res = $wpdb->query(
        $wpdb->prepare(
          "INSERT INTO `{$this->table_name}` (`url`, `client`, `cookie`) VALUES (%s, %s, %s)",
          sanitize_title($url),
          $ip,
          $_SERVER['HTTP_USER_AGENT']
        )
      );

      if ( $res ) {
        $this->debug_log( "Encrypted HLS playback request from " . $ip . " for " . $url . " stored (2)." );

      } else {
        $this->debug_log( "Encrypted HLS playback request from " . $ip . " for " . $url . " failed to store (2): " . $wpdb->last_error );
      }
    }
  }
}

global $FV_Player_Pro_Hls;
$FV_Player_Pro_Hls = new FV_Player_Pro_Hls;

endif;
