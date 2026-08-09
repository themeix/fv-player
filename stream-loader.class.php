<?php
if( !class_exists('FV_Player_Pro_Stream_Loader') ) :

  class FV_Player_Pro_Stream_Loader {
    static $instance = null;

    public static function _get_instance() {
      if( !self::$instance ) {
        self::$instance = new self();
      }

      return self::$instance;
    }

    function __construct() {
      // Video Checker obtains its media with priority 10, we run after that
      add_filter( 'fv_player_item', array( $this, 'player_use_stream_loader' ), 11 );

      // Vtt Timeline Thumbnails
      add_filter( 'fv_player_item', array( $this, 'vtt_use_stream_loader' ), 12 );

      // Old Stream Loader in FV Player DRM runs at priority 10, we run after that too
      add_filter( 'fv_player_pro_store_hls_access_tokens', array( $this, 'store_hls_access_tokens' ), 11 );

      // Filter qualities based on user settings
      add_filter( 'fv_player_drm_stream_loader_output', array( $this, 'filter_qualities' ), 10, 2 );

      add_filter( 'fv_flowplayer_settings_save', array($this, 'settings_save'), 10, 2 );

      if( !empty($_GET['stream_loader']) ) {

        // Avoid showing PHP warnings or notices, as it might break the HLS key for Safari, but keep showing fatal errors
        error_reporting( E_CORE_ERROR | E_COMPILE_ERROR | E_ERROR | E_PARSE | E_USER_ERROR | E_RECOVERABLE_ERROR );
        ini_set( 'display_errors', 1 );

        // Old Stream Loader in FV Player DRM runs at priority 10, we run after that too
        add_action( 'init', array( $this, 'stream_loader' ), 11 );
      }

      // Clean cache on version change
      add_action( 'fv_player_pro_update', array( $this, 'stream_loader_clear_cache' ) );

      // TODO: Should also run when you enable the setting
      add_action( 'fv_player_pro_update', array( $this, 'plugin_update_database' ), 9 );

      add_action( 'wp_ajax_stream_loader_dismiss', array( $this, 'stream_loader_dismiss') );

      add_action( 'admin_notices', array( $this, 'admin_show_error_log' ), 11 );

      add_action( 'admin_notices', array( $this, 'admin_clean_cache'), 12 );

      add_action( 'admin_init', array( $this, 'register_meta_boxes' ), 20 );

      add_action( 'admin_init', array( $this, 'cron_init' ), 20 );

      add_action( 'fv_player_pro_stream_loader_clear_log', array( $this, 'clear_log' ) );
    }

    /**
     * Schedule cron job for clearing log
     *
     * @return void
     */
    public function cron_init() {
      if ( FV_Player_Pro()->_get_option( array('pro','stream_loader_on') ) && !wp_next_scheduled('fv_player_pro_stream_loader_clear_log') ) {
        wp_schedule_event( time(), 'daily', 'fv_player_pro_stream_loader_clear_log' );
      } else if( !FV_Player_Pro()->_get_option( array('pro','stream_loader_on') ) && wp_next_scheduled('fv_player_pro_stream_loader_clear_log') ) {
        wp_clear_scheduled_hook('fv_player_pro_stream_loader_clear_log');
      }
    }

    /**
     * Manual chache clean from options screen
     *
     * @return void
     */
    function admin_clean_cache() {
      if( current_user_can('manage_options') && isset($_GET['stream_loader_clear_cache']) && wp_verify_nonce($_GET['stream_loader_clear_cache'],'stream_loader_clear_cache') ) {
        echo "<div class='updated'>";
        echo "<p>Deleting Stream Loader cache&hellip;</p>";

        $time_start = microtime(true);
        $deleted = $this->stream_loader_clear_cache();
        $time_end = microtime(true);
        $execution_time = ($time_end - $time_start);

        if( $deleted ) {
          echo "<p>Total rows deleted: " . esc_html($deleted . " in " . $execution_time) . " seconds.<p>";
        } else {
          echo "<p>No rows to delete, cache is empty.<p>";
        }

        echo "<p>Done !</p>";
        echo "</div>";
      }
    }

    /**
     * Dismiss and remove error logs
     *
     * @return void
     */
    function stream_loader_dismiss() {
      if( current_user_can('manage_options') ) {
        delete_option( 'fv_player_stream_loader_errors' );
      }
    }

    /**
     * Show logged error for stream loader
     *
     * @return void
     */
    function admin_show_error_log() {
      if( current_user_can('manage_options') ) { // dissmissed
        $serious_issues = $this->show_domain_errors();

        if( !empty($serious_issues) ) {
          echo "<div id='stream-loader-notice' class='error'>";
          echo "<p>FV Player Pro: Stream Loader encountered errors while serving HLS streams: <a class='more-details' href='#'>show details</a></p>";
          echo "<div id='error-logs' style='display: none;'>".$serious_issues."</div>";
          echo "<a id='stream-loader-dismiss' href='#'>Dismiss</a>";
          echo "</div>";

          ?>
          <script>
          // toggle
          jQuery('.more-details').on('click', function(e) {
            e.preventDefault();
            jQuery('#error-logs').toggle();
          });

          // dismiss & remove logs
          jQuery('#stream-loader-dismiss').on('click', function(e) {
            var data = {
              'action': 'stream_loader_dismiss'
            };

            if( confirm('Remove error logs?') ) {
              jQuery.post( ajaxurl, data, function(response) {
              jQuery('#stream-loader-notice').remove();
            });
            }
          });
          </script>
          <?php
        }
      }
    }

    /**
     * Log error message if stream loader encounters error
     *
     * @param string $message
     *
     * @return void
     */
    function log_error( $src, $message ) {
      $host = wp_parse_url($src);
      $host = $host['host'];

      $logged_messages = $this->log_init( $host );

      $original_message = $message;

      $ratio = 0;

      // Some error messages might use report timeout values, but they are
      // all the same, so we unify them here:
      //
      // Operation timed out after 10001 milliseconds with 0 out of 0 bytes received
      $message = preg_replace( '~Operation timed out after (.*?) with (.*?) received~', 'Operation timed out after {milliseconds} with {bytes} received', $message );

      if( isset($logged_messages[$host]['messages'][$message]) ) { // host & error message exists, add to count and modify last date
        $last_mail = get_option( 'fv_player_stream_loader_mail', 0 );

        $logged_messages[$host]['messages'][$message]['last'] = date("Y-m-d H:i:s");
        $logged_messages[$host]['messages'][$message]['count'] += 1;

        // new day, reset
        if( strtotime( date('Y-m-d') ) > strtotime( $logged_messages[$host]['messages'][$message]['date'] ) ) {
          $logged_messages[$host]['messages'][$message]['last'] = date("Y-m-d H:i:s");
          $logged_messages[$host]['messages'][$message]['count'] = 1;
          $logged_messages[$host]['messages'][$message]['date'] = date("Y-m-d");
        }

        // ratio error / success
        if( isset($logged_messages[$host]['success']['count']) && $logged_messages[$host]['success']['count'] > 0 ) {
          $ratio = $logged_messages[$host]['messages'][$message]['count'] / $logged_messages[$host]['success']['count'];
        }

        if( $ratio > 0.05 && time() > $last_mail + 3600 ) { // one email every 5 errors each hour
          update_option( 'fv_player_stream_loader_mail', time(), false );

          $subject = 'FV Player Pro Stream Loader encountered errors while serving HLS streams';
          $content = "<p>Stream URL: ".$src."</p>\n";
          $content .= "<p>Error message: ".$original_message."</p>\n";

          $content .= "<p>Here are the overall domain error stats:</p>\n";
          $content .= $this->show_domain_errors( $logged_messages );

          $headers = array('Content-Type: text/html; charset=UTF-8');

          wp_mail( get_option( 'admin_email' ), $subject, $content, $headers); // send mail to administrator
        }
      } else { // host exists, new error message
        $logged_messages[$host]['messages'][$message] = array(
          'date' => date('Y-m-d'),
          'first' => date("Y-m-d H:i:s"),
          'last' => date("Y-m-d H:i:s"),
          'count' => 1
        );
      }

      update_option( 'fv_player_stream_loader_errors', $logged_messages, false );
    }

    /**
     * Log on success
     *
     * @param string $src
     *
     * @return void
     */
    function log_success( $src ) {
      $host = wp_parse_url($src);
      $host = $host['host'];
      $current_date = date("Y-m-d");
      $current_datetime = date("Y-m-d H:i:s");

      $logged_messages = $this->log_init( $host );

      if( !isset( $logged_messages[$host]['success']['date']) || ( strtotime( $current_date ) > strtotime( $logged_messages[$host]['success']['date']) ) ) {
        $logged_messages[$host]['success']['count'] = 1;
        $logged_messages[$host]['success']['date'] = $current_date;
      } else {
        $logged_messages[$host]['success']['count'] += 1;
      }

      $logged_messages[$host]['success']['last'] = $current_datetime;

      update_option( 'fv_player_stream_loader_errors', $logged_messages, false );
    }

    /**
     * Initialize log variable and return it
     *
     * @param string $src
     *
     * @return array
     */
    function log_init( $host ) {
      $logged_messages = get_option( 'fv_player_stream_loader_errors', array() );

      if( empty( $logged_messages ) || !isset($logged_messages[$host]) ) {
        $logged_messages[$host] = array(
          'messages' => array(),
          'success' => array()
        );
      }

      return $logged_messages;
    }

    /**
     * Show serious domain errors
     *
     * @param boolean $logged_messages
     *
     * @return void
     */
    function show_domain_errors( $logged_messages = false ) {
      if( !$logged_messages ) {
        $logged_messages = get_option('fv_player_stream_loader_errors', array());
      }

      $serious_issues = '';

      if( !empty($logged_messages) ) {
        foreach( $logged_messages as $domain => $messages ) {
          $domain_errors = 0;
          $domain_details = '';
          foreach( $messages['messages'] as $message => $data ) {
            $domain_errors += $data['count'];
            $domain_details .= "<p>" . esc_html( "Message: " . $message . " First: " . $data['first'] . " Last: " . $data['last'] . " Count: " . $data['count'] ) . "</p>";
          }

          $percentage = 0;
          if( $logged_messages[$domain]['success']['count'] > 0 ) {
            $percentage = $domain_errors/$logged_messages[$domain]['success']['count'] * 100;
          } else if( $domain_errors > 0 ) {
            $percentage = 100;
          }

          if( $percentage > 5 ) {
            $serious_issues .= "<p>Domain <code>" . esc_html( $domain ) . "</code> has error rate of ".round($percentage)."%</p>";
            $serious_issues .= $domain_details;
          }
        }
      }

      return $serious_issues;
    }

    function plugin_update_database( $force = false ) {
      /**
       * Do not attempt to upgrade the database if:
       *
       * * not a forced run (each time Stream Loader runs)
       * * Stream Loader is not enabled
       * * or if it's the "Speed-up" setting of Stream Loader which uses SHORTINIT
       */
      if( !$force && !FV_Player_Pro()->_get_option( array('pro','stream_loader_on') ) || SHORTINIT ) return;

      global $wpdb;

      $sql = "CREATE TABLE ".$wpdb->prefix."fv_player_stream_loader_cache (
        id int(11) NOT NULL auto_increment,
        stream_url varchar(1024) NOT NULL,
        stream_content longtext NOT NULL,
        expires int(11) NOT NULL,
        PRIMARY KEY  (id),
        KEY url_stream_url (stream_url)
      )" . $wpdb->get_charset_collate() . ";";

      // delete also old cache from wp options
      $wpdb->query("DELETE FROM `{$wpdb->prefix}options` WHERE `option_name` LIKE 'fv_player_stream_loader_%' AND option_name NOT LIKE 'fv_player_stream_loader_mail' AND option_name NOT LIKE 'fv_player_stream_loader_errors' ");

      require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
      dbDelta( $sql );

    }

    /**
     * Delete cache rows
     *
     * @return void
     */
    function stream_loader_clear_cache() {
      global $wpdb;
      $deleted = $wpdb->query("DELETE FROM `{$wpdb->prefix}fv_player_stream_loader_cache`"); // delete all rows
      return $deleted;
    }

    /**
     * @param string $url       Possibly relative URL - example: segment-1.ts
     * @param string $full_url  Full URL to use - example: https://cdn.site.com/intro-lesson/index.m3u8
     *
     * @return string Absolute URL - example: https://cdn.site.com/intro-lesson/segment-1.ts
     */
    function get_abs_path( $url, $full_url ) {
      if( !$this->is_abs_path($url) ) { // check if not absolute path
        $url = dirname($full_url) .'/'. $url; // Convert relative paths to absolute
      }
      return $url;
    }

    function get_eol( $text ) {
      if( preg_match( '/\r\n|\r|\n/', $text, $eol ) ) {
        return $eol[0];
      }
      return PHP_EOL;
    }

    /**
     * The WordPress page cache might be enabled if the user is not logged in
     * and if DONOTCACHEPAGE is either not defined or false
     *
     * @return bool
     */
    function is_cache_on() {
      return !is_user_logged_in() && ( !defined( 'DONOTCACHEPAGE' ) || !DONOTCACHEPAGE );
    }

    /**
     * Show the Stream Loader options
     *
     * @return void
     */
    function options() {
      global $fv_fp;
      ?>
      <p><?php _e('Since the <code>m3u8</code> files are static they cannot use video segment URLs with time-sensitive tokens. Stream Loader gets past this limitation by inserting proper URL tokens on the fly.', 'fv-player-pro'); ?></p>
      <p><?php _e('Enhance your HLS video security by enabling the URL tokens on your CDN and configuring it in the Hosting tab.', 'fv-player-pro'); ?></p>
      <?php
      global $wpdb;
      $cache = $wpdb->get_results( "SELECT * FROM `{$wpdb->prefix}fv_player_stream_loader_cache`");

      $expired = 0;
      if( $cache ) {
        foreach( $cache AS $entry ) {
          if( $entry->expires < time() ) {
            $expired++;
          }
        }
      }

      echo "<p>Currently ". esc_html(count($cache)." files in cache, out of which ".$expired) ." are expired.</p>\n";
      ?>
      <table class="form-table2" style="margin: 5px; ">
        <?php $fv_fp->_get_checkbox(__('Enable', 'fv-player-pro'), array('pro', 'stream_loader_on'), __('Make sure your CDN Domain and Secure Token in configured in the Hosting tab.', 'fv-player-pro') ); ?>
        <?php $fv_fp->_get_checkbox(__('Speed-up', 'fv-player-pro').' (beta)', array('pro', 'stream_loader_speed_up'), sprintf( __('Activate for faster serving.', 'fv-player-pro'), plugins_url( 'stream-loader.php', __FILE__ ) ), sprintf( __('Will bypass WordPress and use <code>%s</code> URL directly. Unfortunately the email notifications on HTTP failures when loading HLS will be disabled.', 'fv-player-pro'), plugins_url( 'stream-loader.php', __FILE__ ) ) ); ?>
        <?php $fv_fp->_get_checkbox(__('Limit Video Quality', 'fv-player-pro'), array('pro', 'stream_loader_qualities_limit'), __('Change offered video qualities without having to reencode the video.', 'fv-player-pro') ); ?>
        <?php
        $qualities = array(
          '240' => __('240p' , 'fv-player-pro'),
          '480'  => __('480p' , 'fv-player-pro'),
          '720'  => __('720p' , 'fv-player-pro'),
          '1080' => __('1080p', 'fv-player-pro'),
          '1440' => __('1440p', 'fv-player-pro'),
          '2160' => __('2160p (4K)', 'fv-player-pro'),
        );
        ?>
        <tr>
          <td></td>
          <td>
            <table id="stream_loader_qualities_limit-table" class="widefat striped">
              <tr>
                <td></td>
                <td>Min</td>
                <td>Max</td>
              </tr>
              <tr>
                <td>Mobile</td>
                <td>
                  <?php
                  FV_Player_Pro()->_get_select(
                    array(
                      'key'     => array( 'pro', 'stream_loader_mobile_min' ),
                      'options' => $qualities,
                      'no_row'  => true
                    )
                  );
                  ?>
                </td>
                <td>
                  <?php
                  FV_Player_Pro()->_get_select(
                    array(
                      'key'     => array( 'pro', 'stream_loader_mobile_max' ),
                      'options' => $qualities,
                      'no_row'  => true
                    )
                  );
                  ?>
                </td>
              </tr>
              <tr>
                <td>Desktop</td>
                <td>
                  <?php
                  FV_Player_Pro()->_get_select(
                    array(
                      'key'     => array( 'pro', 'stream_loader_desktop_min' ),
                      'options' => $qualities,
                      'no_row'  => true
                    )
                  );
                  ?>
                </td>
                <td>
                  <?php
                  FV_Player_Pro()->_get_select(
                    array(
                      'key'     => array( 'pro', 'stream_loader_desktop_max' ),
                      'options' => $qualities,
                      'no_row'  => true
                    )
                  );
                  ?>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <script>
          jQuery(function() {
            var checkbox = jQuery( '#'+ jQuery.escapeSelector( 'pro[stream_loader_qualities_limit]' ) ),
              table = jQuery( '#stream_loader_qualities_limit-table' );

            if ( ! checkbox.prop('checked') ) {
              table.hide();
            }

            checkbox.on('change', function(e) {
              table.toggle( jQuery(this).prop('checked') );
            });
          });
        </script>

        <tr>
          <td colspan="4">
            <?php if( get_option( 'fv_player_stream_loader_errors' ) ) : ?>
            <p><strong>Request stats</strong></p>
            <table class="widefat striped">
              <thead>
              <tr>
                <th>Domain</th>
                <th>Success</th>
                <th>Errors</th>
                <th>Last Error</th>
                <th>Last Success</th>
              </tr>
            </thead>
            <tbody id="the-list">
            <?php

            $logged_data = get_option('fv_player_stream_loader_errors', array());

              if( !empty($logged_data) ) {

                foreach( $logged_data as $domain => $messages ) {
                  $domain_errors = 0;
                  $domain_successes = 0;
                  $last_success = '-';
                  $last_error = 0;

                  if( isset( $logged_data[$domain]['success']['count'] ) ) $domain_successes = $logged_data[$domain]['success']['count'];
                  if( isset( $logged_data[$domain]['success']['last'] ) ) $last_success = $logged_data[$domain]['success']['last'];

                  foreach( $messages['messages'] as $message => $data ) {
                    $domain_errors += $data['count'];

                    if(  strtotime( $data['last'] ) > $last_error ) {
                      $last_error = $data['last'];
                    }
                  }

                  if( $domain_successes > 0 ) {
                    $percentage = 100 * $domain_errors/$domain_successes;
                  } else if ( $domain_errors > 0 ) {
                    $percentage = 100;
                  }
                  ?>

              <tr>
                <td><?php echo esc_html( $domain ); ?></td>
                <td><?php echo esc_html( $domain_successes ); ?></td>
                <td><?php echo esc_html( $domain_errors ); ?> <?php if( $percentage ) echo esc_html( '('.round($percentage).'%)' ); ?></td>
                <td><?php echo esc_html( $last_error ); ?></td>
                <td><?php echo esc_html( $last_success ); ?></td>
              </tr>

            <?php
                }
              }
            ?>
              </tbody>
            </table>
            <?php endif; ?>
          </td>
        </tr>

        <tr>
          <td colspan="4">
            <a class="fv-wordpress-flowplayer-save button button-primary" href="#" style="margin-top: 2ex;"><?php _e('Save', 'fv-wordpress-flowplayer'); ?></a>
            <input type="button" class="button" value="<?php _e('Clear cache', 'fv-player-pro'); ?>" style="margin-top: 2ex;" onclick="if( confirm('<?php _e('This will clear your Stream Loader cache.', 'fv-player-pro'); ?>') ) location.href='<?php echo wp_nonce_url( admin_url('options-general.php?page=fvplayer'), 'stream_loader_clear_cache', 'stream_loader_clear_cache'); ?>'; "/>
            <a class="button fv-help-link" style="margin-top: 2ex;" href="https://foliovision.com/player/video-security/video-protection-methods/signed-urls-hls-protection" target="_blank">Help</a>
          </td>
        </tr>
      </table>
      <?php
    }

    /**
     * Changes the video file URL to use the Stream Loader, if it's HLS and it's configured to use URL token
     *
     * @param array $data_item        data-item attribute of the player/playlist item
     *
     * @return array                  data-item attribute of the player/playlist item with Stream Loder URL
     */
    function player_use_stream_loader( $data_item ) {
      // is it enabled globally?
      $enabled = FV_Player_Pro()->_get_option( array('pro','stream_loader_on') );

      // if it's not enabled check if it's not on BunnyCDN due to FV Player Coconut
      // TODO: remove and instead make sure FV Player Coconut Wizard enables Stream Loader
      if( !$enabled ) {
        // for now enable only for BunnyCDN, unless it's enabled globally
        $domains = FV_Player_Pro()->_get_option( array('pro','bunnycdn_domain') );
        if( !$domains ) {
          return $data_item;
        }

        $domains = explode( ',', $domains );
        if( !count($domains) ) {
          return $data_item;
        }

        $domains = array_map('trim',$domains);
      }

      foreach( $data_item['sources'] AS $k => $v ) {

        // Ignore stream if it has ?no_sig in video URL
        $query_string = wp_parse_url( $v['src'], PHP_URL_QUERY );
        if ( $query_string ) {
          parse_str( $query_string, $query_string_parsed );
          if ( ! empty( $query_string_parsed['no_sig'] ) ) {
            continue;
          }
        }

        if( strcasecmp($v['type'],'application/x-mpegurl') == 0 || stripos( $v['src'], '.m3u8' ) !== false ) {
          $src = $data_item['sources'][$k]['src'];
          $type = $data_item['sources'][$k]['type'];

          $title_tmp = explode('/', $src);

          $fv_title = end($title_tmp);
          $fv_title = preg_replace('/\.(\w{3,4})(\?.*)?$/i', '', $fv_title);

          if( strcmp($type,'application/x-mpegurl') === 0 ) { // if mpegurl take second last element + last
            end($title_tmp);
            $title_tmp = prev($title_tmp);
            $title_tmp = preg_replace('/\.(\w{3,4})(\?.*)?$/i', '', $title_tmp);
            $fv_title = $title_tmp . '/' . $fv_title;
          }

          $data_item['sources'][$k]['fv_title'] = $fv_title;

          // if not enabled globally a BunnyCDN domains much match!
          // TODO: remove and instead make sure FV Player Coconut Wizard enables Stream Loader
          if( !$enabled ) {
            $found = false;
            foreach( $domains AS $domain ) {
              if( stripos($src,'//'.$domain) !== false ) {
                $found = true;
              }
            }

            // bail if BunnyCDN domain is not matched
            if( !$found ) continue;
          }

          $signed_src = apply_filters('fv_flowplayer_video_src', $src, array('dynamic' => true) );

          $ignore_domains = apply_filters('fv_flowplayer_stream_loader_ignore_domains', array() );

          $ignore_domain = false;

          foreach( $ignore_domains as $ignore_domains_item ) {
            if ( ! empty( $ignore_domains_item ) && stripos( $src, $ignore_domains_item ) !== false ) {
              $ignore_domain = true;
            }
          }

          $ignore_signature = (defined('FV_PLAYER_PRO_STREAM_LOADER_FORCE') && FV_PLAYER_PRO_STREAM_LOADER_FORCE) ? true : false;

          // does the video link require URL token?
          if(
            !$ignore_domain &&
            (
              $ignore_signature ||
              strcmp($src, $signed_src) != 0 ||
              stripos($src,'?stream_loader') !== false
            ) // or does it have ?stream_loader in it?
          ) {

            // Do we have the database video URL?
            $db_url = false;
            if( !empty($data_item['id']) ) {
              global $FV_Player_Db;
              $objVideo = new FV_Player_Db_Video( $data_item['id'], array(), $FV_Player_Db );
              $db_url = $objVideo->getSrc();

              // Check if the src and video ID src is the same as some plugin might put in a different src
              // like FV Player Pay Per View for the preview video
              if( $db_url != $src ) {
                $db_url = false;
              }
            }

            // we need to use the video ID or URL if it's preview
            $data_item['sources'][$k]['src'] = $this->stream_loader_url( $db_url && empty($_GET['fv_player_preview']) ? $data_item['id'] : $src );
            $data_item['sources'][$k]['type'] = 'application/x-mpegurl';

          }

        }
      }

      return $data_item;
    }

    function register_meta_boxes() {
      add_meta_box( 'fv_player_pro_stream_loader', __('Stream Loader', 'fv-player-pro'), array( $this, 'options' ), 'fv_flowplayer_settings', 'normal', 'low' );
    }

    /**
     * Changes the vtt file URL to use the Stream Loader
     *
     * @param array $data_item
     *
     * @return array
     */
    function vtt_use_stream_loader( $data_item ) {
      if(isset( $data_item['timeline_vtt'])) {
        foreach ( $data_item['timeline_vtt'] as $i => $vtt_link ) {
          $vtt_link = $vtt_link['src'];
          // remove query args
          $link_arr = explode('?', $vtt_link);
          $vtt_link = reset($link_arr);

          // is it enabled globally?
          $enabled = FV_Player_Pro()->_get_option( array('pro','stream_loader_on') );

          // if it's not enabled check if it's not on BunnyCDN due to FV Player Coconut
          // TODO: remove and instead make sure FV Player Coconut Wizard enables Stream Loader
          if( !$enabled ) {
            // for now enable only for BunnyCDN, unless it's enabled globally
            $domains = FV_Player_Pro()->_get_option( array('pro','bunnycdn_domain') );
            if( !$domains ) {
              return $data_item;
            }

            $domains = explode( ',', $domains );
            if( !count($domains) ) {
              return $data_item;
            }

            $domains = array_map('trim',$domains);
          }

          foreach( $data_item['sources'] AS $k => $v ) {
            // if not enabled globally a BunnyCDN domains much match!
            // TODO: remove and instead make sure FV Player Coconut Wizard enables Stream Loader
            if( !$enabled ) {
              $found = false;
              foreach( $domains AS $domain ) {
                if( stripos($vtt_link,'//'.$domain) !== false ) {
                  $found = true;
                }
              }

              // bail if BunnyCDN domain is not matched
              if( !$found ) continue;
            }

            $signed_src = apply_filters('fv_flowplayer_video_src', $vtt_link, array('dynamic' => true) );

            // does the video link require URL token?
            if( strcmp($vtt_link, $signed_src) != 0 ) {
              $data_item['timeline_vtt'][$i]['src'] = $this->stream_loader_url( $vtt_link );
            }
          }
        }
      }

      return $data_item;
    }

    /**
     * Decode stream loader URLs for HLS decryption tokens
     * ...if the URL has stream_loader query argument
     * ...check if it's beginning with http or it's a video ID number
     *
     * @param string $url
     *
     * @return string
     */
    function store_hls_access_tokens( $url ) {

      $parsed = parse_url($url);
      if( !empty($parsed['query']) ) {
        // parse query string into $query_string
        parse_str($parsed['query'],$query_string);
        if( !empty($query_string['stream_loader']) ) {
          if( stripos($query_string['stream_loader'],'http') === 0 ) {
            $url = $query_string['stream_loader'];

          } else if( is_numeric($query_string['stream_loader']) ) {
            global $FV_Player_Db;
            $objVideo = new FV_Player_Db_Video( $query_string['stream_loader'], array(), $FV_Player_Db );
            $url = $objVideo->getSrc();

          }
        }
      }

      return $url;
    }

    /**
     * Handle the Stream Loader quality selection saving
     *
     * @param array $aSettings
     *
     * @return array
     */
    function settings_save( $aSettings ) {
      // mobile
      if( isset($aSettings['pro']['stream_loader_mobile_min']) &&  $aSettings['pro']['stream_loader_mobile_min'] > $aSettings['pro']['stream_loader_mobile_max'] ) {
        $aSettings['pro']['stream_loader_mobile_max'] = '2160'; // set to max
      }

      // same for desktop
      if( isset($aSettings['pro']['stream_loader_desktop_min']) && $aSettings['pro']['stream_loader_desktop_min'] > $aSettings['pro']['stream_loader_desktop_max'] ) {
        $aSettings['pro']['stream_loader_desktop_max'] = '2160'; // set to max
      }

      return $aSettings;
    }

    /**
     * Filter the video qualities
     *
     * @param string $new_body
     * @param string $src
     *
     * @return string
     */
    function filter_qualities($body, $src) {
      if( !FV_Player_Pro()->_get_option( array('pro','stream_loader_qualities_limit') )) {
        return $body;
      }

      $new_body = '';

      $eol = $this->get_eol($body);
      $lines = preg_split('/\r\n|\r|\n/', $body);
      $skip_lines = array();

      // detect if mobile or desktop and get min and max height
      if( FV_Player_Pro()->is_mobile() ) {
        $min = FV_Player_Pro()->_get_option( array('pro','stream_loader_mobile_min') );
        $max = FV_Player_Pro()->_get_option( array('pro','stream_loader_mobile_max') );
      } else {
        $min = FV_Player_Pro()->_get_option( array('pro','stream_loader_desktop_min') );
        $max = FV_Player_Pro()->_get_option( array('pro','stream_loader_desktop_max') );
      }

      // skip lines that are lower than min and higher than max
      foreach($lines as $k => $line) {
        if( preg_match( '~RESOLUTION=(\d+)x(\d+)~', $line, $resolution ) ) {
          $width = $resolution[1];
          $height = $resolution[2];

          if( $height < $min || $height > $max ) {
            $skip_lines[] = $k; // skip this line
            $skip_lines[] = $k+1; // and also next line
          }
        }

        if( in_array($k, $skip_lines) ) {
          continue;
        }

        $new_body .= $line . $eol;
      }

      // Did we end up removing all the qualities? Then use the original m3u8.
      if ( stripos( $new_body, 'RESOLUTION=' ) === false ) {
        return $body;
      }

      return $new_body;
    }

    /**
     * Serve a Stream Loader request.
     *
     * Checks the signature and all.
     *
     * @param  string|int  $src The HLS m3u8 URL or FV Player DB video ID
     *
     * @return void
     */
    function stream_loader( $src = false ) {

      do_action( 'fv_player_stream_loader' );

      if( empty($_GET['signature']) || empty($_GET['expire']) ) {
        do_action( 'fv_player_stream_loader_bad_request', $_GET );

        if( defined('PHPUnitTestMode') ) return false;
        exit;
      }

      if( !$this->stream_loader_check_signature() ) {
        do_action( 'fv_player_stream_loader_bad_request', $_GET );

        if( defined('PHPUnitTestMode') ) return false;
        exit;
      }

      if( $_GET['expire'] < time() ) {
        do_action( 'fv_player_stream_loader_expired_request' );

        if( defined('PHPUnitTestMode') ) return false;
        exit;
      }

      if( !$src ) {
        // when using preview or [fvplayer src="..."] kind of shortcode
        if( strpos( $_GET['stream_loader'], 'http' ) === 0) {
          $src = $_GET['stream_loader'];
        } else {
          global $FV_Player_Db;
          $objVideo = new FV_Player_Db_Video( intval($_GET['stream_loader']), array(), $FV_Player_Db );
          $src = $objVideo->getSrc();
        }
      }

      $body = $this->stream_loader_http($src);

      $this->stream_loader_parse_and_serve($body,$src);

      if( defined('PHPUnitTestMode') ) return true;
      exit;
    }

    /**
     * @param string $body m3u8 manifest file
     *
     * @return string m3u8 manifest only with qualities above 1080p or whatever is the highest quality if it's lower
     */
    function stream_loader_quality_limit( $body ) {
      $lines = preg_split('/\r\n|\r|\n/', $body);
      $new_body = '';
      $previous_line = '';

      // Find out what is the top video quality (height in pixels)
      $top_quality = false;
      if(  preg_match_all( '~RESOLUTION=(\d+)x(\d+)~', $body, $qualities ) ) {
        foreach( $qualities[2] AS $video_height ) {
          if( $video_height > $top_quality ) {
            $top_quality = $video_height;
          }
        }

      // Do not change anything if the resolutions are not there
      } else {
        return $body;
      }

      // If the top quality is above 1080, then accept 1080 too to not play 4K for all
      if( $top_quality > 1080 ) {
        $top_quality = 1080;
      }

      $eol = $this->get_eol($body);

      // Go through m3u8 line by line and remove the variant playlists for lower qualities
      foreach($lines as $line) {
        $backup = $line;

        if( stripos( $previous_line, '#EXT-X-STREAM-INF' ) === 0 ) { // sub playlist link
          if( preg_match( '~RESOLUTION=(\d+)x(\d+)~', $previous_line, $resolution ) ) {
            if( $resolution[2] < $top_quality ) {
              $previous_line = $backup;
              continue;
            }
          }
        } else if( stripos( $line, '#EXT-X-STREAM-INF' ) === 0 ) { // sub playlist heading
          if( preg_match( '~RESOLUTION=(\d+)x(\d+)~', $line, $resolution ) ) {
            if( $resolution[2] < $top_quality ) {
              $previous_line = $backup;
              continue;
            }
          }
        }

        $previous_line = $backup;

        $new_body .= $line . $eol;
      }

      return $new_body;
    }

    function stream_loader_parse_and_serve( $body, $src ) {
      if( $body ) {
        if (preg_match('/^WEBVTT/', $body, $vtt)) {
          $body = $this->stream_loader_parse_vtt( $body, $src );
          if( $this->is_ssl() ) {
            $body = str_replace('http://', 'https://', $body);
          }
          $this->stream_loader_serve_vtt($body);

        } else {
          $body = $this->stream_loader_parse_m3u8( $body, $src );
          if( $this->is_ssl() ) {
            $body = str_replace('http://', 'https://', $body);
          }

          // If it's Apple TV only load the 1080p stream and above as the low quality streams look horrible on big screens
          // We assume your TV is using a proper internet connection
          // We do not do this for other AirPlay devices, like Roku, as that one just wouldn't play the HLS without the low quality, although it seems to pick the FullHD right away
          if( stripos( $_SERVER['HTTP_USER_AGENT'], 'Apple TV' ) !== false ) {
            $body = trim( $this->stream_loader_quality_limit( $body ) );
          }

          $this->stream_loader_serve_m3u8($body);

        }
      }
    }

    /**
     * @param string $body Full content of the original m3u8 manifest file
     * @param string $src Full path of the original m3u8 file
     *
     * @return string Modified m3u8 manifest
     */
    function stream_loader_parse_m3u8( $body, $src ) {
      $lines = preg_split('/\r\n|\r|\n/', $body);
      $new_body = '';
      $previous_line = '';

      $eol = $this->get_eol($body);

      // Keep track of the sub-playlists
      $sub_count = 0;
      $sub_playlists = array();

      foreach($lines as $line) {
        $line_bck = $line;
        if( // video segment
          preg_match( '~^\#(EXTINF|EXT-X-(BITRATE|BYTERANGE))~', $previous_line ) && // if the previous line started with #EXTINF or EXT-X-BITRATE or EXT-X-BYTERANGE
          !preg_match( '~^\#EXT-X-(BITRATE|BYTERANGE)~', $line ) // and at the same time it must not be EXT-X-BITRATE nor EXT-X-BYTERANGE
        ) {
          $line = $this->get_abs_path($line,$src);
          $line = $this->stream_loader_segments($line);

        } else if( stripos( $previous_line, '#EXT-X-STREAM-INF' ) === 0  && !isset($_GET['non_recursive']) ) { // sub playlist

          $sub_count++;
          $sub_playlists[ $sub_count ] = $this->get_abs_path($line,$src);

          $line = $this->stream_loader_sub_playlists($line, $sub_count);

        } else if( preg_match('/^#EXT-X-MEDIA:TYPE=AUDIO.*?URI="(.*?)"/', $line, $match) ) { // audio

          $sub_count++;
          $sub_playlists[ $sub_count ] = $this->get_abs_path($match[1],$src);

          $line = $this->stream_loader_audio($match, $sub_count);

        } else if( preg_match('/^#EXT-X-MAP:URI="(.*?)"/', $line, $match) ) { // cmaf map
          $url = $match[1];
          $url = $this->get_abs_path($url,$src);
          $line = str_replace($match[1], $this->stream_loader_segments($url), $match[0]);
        }

        $previous_line = $line_bck;
        $new_body .= $line . $eol;
      }

      // are we loading a sub-playlist and are there any found?
      if( !empty($_GET['pl']) && count($sub_playlists) > 0 ) {
        $this->stream_loader( $sub_playlists[ $_GET['pl'] ] );
      }

      $new_body = apply_filters( 'fv_player_drm_stream_loader_output', $new_body, $src );
      $new_body = rtrim($new_body);

      return $new_body;
    }

    function stream_loader_serve_m3u8( $body ) {
      @header('Content-Type: application/x-mpegURL');
      @header('Accept-Ranges: bytes');

      // CORS for Chromecast
      if( strpos($_SERVER['HTTP_USER_AGENT'], 'CrKey') ) {
        @header('Access-Control-Allow-Methods: GET,POST,OPTIONS');
        @header('Access-Control-Allow-Origin: *'); // using *.gstatic.com won't work
      }

      // prevent browser caching
      @header('Cache-Control: no-store, no-cache, must-revalidate');
      @header('Cache-Control: post-check=0, pre-check=0', FALSE);
      @header('Pragma: no-cache');

      // important for Apple as not all servers support it
      if( !empty($_SERVER['HTTP_RANGE']) && preg_match('~bytes=(\d+)-(\d+)~', $_SERVER['HTTP_RANGE'], $range) ) {
        $start = intval($range[1]);
        $end = intval($range[2]);

        // if the range is 0-1, we need to return 0 and 1 bytes = 2 byte length
        $length = $end - $start + 1;
        $response_length = strlen($body);
        $body = substr( $body, $start, $length );

        @header('HTTP/1.1 206 Partial Content');
        @header('Content-Range: bytes '.$start.'-'.$end.'/'.$response_length);
        @header('Content-Length: '.$length);
      }

      echo $body;
    }

    /**
     * Replace sprite img paths with absolute paths + token, expects sprite img to be jpg
     *
     * @param string $body
     *
     * @param string $src
     *
     * @return string
     */
    function stream_loader_parse_vtt( $body, $src ) {
      $lines = preg_split('/\r\n|\r|\n/', $body);

      $eol = $this->get_eol($body);

      $new_body = '';

      $parsed_url = parse_url($src);

      foreach ($lines as $line) {
        if( preg_match('~(.*?\.(jpg|png))~', $line, $sprite) ) { // matched sprite
          $sprite = $sprite[0];
          if ( $this->is_abs_path($sprite) ) { // absolute path
            $sprite_new = apply_filters( 'fv_flowplayer_video_src', $sprite, array('dynamic' => true) );
            $line = str_ireplace( $sprite, $sprite_new, $line );
          } else if(!preg_match('/^\/\//', $sprite, $sprite_match)) { // ignore // ,
            $sprite_new = '';
            if($sprite[0] === '/') { // path starts with /
              $sprite_new = $parsed_url['scheme'] . '://' . $parsed_url['host'] . $sprite;
            } else {
              $sprite_new = dirname($src) . '/' . $sprite;
            }
            $sprite_new = apply_filters( 'fv_flowplayer_video_src', $sprite_new, array('dynamic' => true) );
            $line = str_ireplace( $sprite, $sprite_new, $line );
          }
        }
        $new_body .= $line . $eol;
      }

      $new_body = rtrim($new_body);

      return $new_body;
    }

    /**
     * Send header and body for vtt file
     *
     * @return void
     */
    function stream_loader_serve_vtt( $body ) {
      @header('Content-Type: text/vtt');

      echo $body;
    }

    /**
     * Check the Stream Loader signature
     *
     * @global array $_GET  Query string args
     *
     * @return bool  True if the signature is correct
     */
    function stream_loader_check_signature() {
      $sub_playlist_id = isset($_GET['pl']) ? $_GET['pl'] : '-1';

      if( !empty($_GET['protection']) && $_GET['protection'] == 'false' ) {
        $string_to_sign = $_GET['stream_loader'].$sub_playlist_id;

      // If Chromecast is allowed to process the HLS, we must not check the IP nor login status
      } else if( FV_Player_Pro()->_get_option( array('pro', 'chromecast_enc_hls') ) ) {
        $string_to_sign = $_GET['stream_loader'].$sub_playlist_id;

      // If it's AirPlay we must not test if the user is logged in
      } else if( FV_Player_Pro_Hls::is_airplay() ) {
        $string_to_sign = $_GET['stream_loader'].$sub_playlist_id.$_GET['track'];

      // use the user ID (track) for logged in users
      // we do not check the IP here as the IP might change even for logged in user (?)
      } else if( is_user_logged_in() && !empty($_GET['track']) ) {
        // we check with the 'track' argument which is the user_id
        $string_to_sign = $_GET['stream_loader'].$sub_playlist_id.$_GET['track'];

      // no IP in the signature if the caching might be on
      } else if( $this->is_cache_on() ) {
        $string_to_sign = $_GET['stream_loader'].$sub_playlist_id;

      // we can sign even with the IP, but only if the user is HTML cache is not on
      } else {
        $string_to_sign = $_GET['stream_loader'].$sub_playlist_id.FV_Player_Pro()->get_client_ip();
      }
      $check = md5( $string_to_sign.$_GET['expire'].NONCE_SALT );

      return strcmp($check,$_GET['signature']) == 0;
    }

    /**
     *  Perform a HTTP request for Stream Loader and store into fv_player_stream_loader_cache cache
     *  or load it from fv_player_stream_loader_cache cache.
     *
     *  @param  string  $src  The HLS m3u8 URL
     *
     *  @return strng         HLS m3u8 manifest file
     */
    function stream_loader_http( $src ) {
      global $wpdb;

      $this->plugin_update_database(true); // make sure the database is up to date

      $no_cache = (defined('FV_PLAYER_PRO_STREAM_LOADER_FORCE') && FV_PLAYER_PRO_STREAM_LOADER_FORCE) ? true : false;

      // is there a cache entry that is not expired?
      $cache = $wpdb->get_row( $wpdb->prepare("SELECT * FROM `{$wpdb->prefix}fv_player_stream_loader_cache` WHERE stream_url = %s", $src), ARRAY_A );

      if( !empty($cache['stream_content']) && !empty($cache['expires']) && $cache['expires'] > time() && !$no_cache ) {
        return $cache['stream_content'];
      }

      // we might need the body even if it's old
      $old_cached_body = (!empty($cache['stream_content']) && !$no_cache) ? $cache['stream_content'] : false;

      // load the src URL and cache for 15 minutes
      // we need to provide longer TTL for the these sub-playlists
      add_filter( 'fv_player_secure_link_timeout', array($this,'stream_loader_segments_expiration') );
      $signed_src = apply_filters('fv_flowplayer_video_src', $src, array('dynamic' => true));
      remove_filter( 'fv_player_secure_link_timeout', array($this,'stream_loader_segments_expiration') );

      $response = wp_remote_get( $signed_src, array(
        'headers' => array(
          'Referer' => home_url(),
        ),
        'timeout' => 15
      ) );

      if( is_array($response) && !is_wp_error($response) ) {
        $cache_expire = 300;

        if( isset($_GET['cache_expire_override']) && intval($_GET['cache_expire_override']) ) {
          $cache_expire = intval( $_GET['cache_expire_override'] );
        }

        $cache_id = isset($cache['id']) ? $cache['id'] : false;

        // Cache for short time if it does not look like a valid m3u8 file
        if ( stripos( $src, '.m3u8' ) !== false && stripos( $response['body'], 'EXTM3U') === false ) {
          $this->log_error($src, "This does not look like a m3u8 file: " . substr( $response['body'], 0, 32 ) );

          $cache_expire = false;
        }

        $this->set_cache( $response['body'], $cache_id, $src, $cache_expire );

        $this->log_success( $src );

        return $response['body'];

      // if there was any error, we cache it for a moment
      // to prevent too many HTTP requests if many people
      // play the video
      // but we still give them the old data
      } else {

        // Log error only if there is no cached value
        if( !$old_cached_body ) {
          $this->log_error($src, is_wp_error($response) ? $response->get_error_message() : 'Unknown error' );
        }

        $cache_id = isset($cache['id']) ? $cache['id'] : false;

        $this->set_cache( $old_cached_body, $cache_id, $src );

        return $old_cached_body;
      }
    }

    /**
     *  Helps with numbering of nested m3u8 files
     *
     *  @param string $m3u8_url URL of the m3u8 sub-playlist
     *  @param int $i Order of the sub-playlist
     *
     *  @return string Stream Loader URL for a sub-playlist - using the ?pl argument
     */
    function stream_loader_sub_playlists( $m3u8_url, $i ) {
      return $this->stream_loader_url($_GET['stream_loader'], $i);
    }

    /*
    *  Adds URL tokens to the HLS video segments URLs
    */
    function stream_loader_segments($match) {
      // we need to provide longer TTL for the video segments
      add_filter( 'fv_player_secure_link_timeout', array($this,'stream_loader_segments_expiration') );
      $url = apply_filters('fv_flowplayer_video_src',$match,array('dynamic'=>true));
      remove_filter( 'fv_player_secure_link_timeout', array($this,'stream_loader_segments_expiration') );
      return $url;
    }

    /**
     * Helper function to check if path is absolute
     *
     * @param string $path
     *
     * @return bool
     */
    function is_abs_path($path) {
      if( preg_match('/^https?:\/\//', $path, $path_match) ) {
        return true;
      }
      return false;
    }

    function stream_loader_segments_expiration( $ttl ) {
      return 14400;
    }

    /**
     * Take a full line of m3u8 file like
     * #EXT-X-MEDIA:TYPE=AUDIO.*?URI="(.*?)"
     * and use stream loader URL in it.
     *
     * @param array $match  0 => full matched audio track m3u8 line
     *                       1 => relative URL of the audio track
     * @param int $i Order of the sub-playlist
     *
     * @return string       Full line with replaced URL
     */
    function stream_loader_audio( $match, $i ) {
      $url = $this->stream_loader_url($_GET['stream_loader'], $i);
      $url = str_replace( $match[1], $url, $match[0]);
      return $url;
    }

    /**
     * Generate a Stream Loader URL with siganture depending on user login status and caching
     *
     * @param   string|int  $video_id         The FV Player DB video ID or URL if using
     *                                         [fvplayer src="..."]
     * @param   int         $sub_playlist_id  Optional sub-playlist ID as m3u8 file might
     *                                         nest another
     * @param   bool        $non_recursive    Allow to skip subplaylists so only master
     *                                         m3u8 will use stream loader
     * @param   int         $cache_expire_override  Allows to override expire time for cache
     * @param   bool        $no_protection    Do not check logged in user status
     *
     * @return  string                        The Stream Loader URL with signature
     */
    function stream_loader_url($video_id, $sub_playlist_id = -1, $non_recursive = false, $cache_expire_override = 0, $no_protection = false ) {
      if( is_string($video_id) && stripos($video_id, '?stream_loader') !== false ) return $video_id; // prevent modifying url multiple times

      // if page cache is on, we need to make these links work for much longer
      if( $this->is_cache_on() ) {
        $expire = time()+48*3600;

      // for not we make it long anyway as even logged in user might continue video playback
      // after a day or so
      } else {
        $expire = time()+48*3600;
      }

      // should we bypass WordPress?
      if(
        FV_Player_Pro()->_get_option( array('pro','stream_loader_speed_up') )
        // only works if FV Player DRM is not enabled
        && (
          !function_exists('FV_Player_DRM') || !FV_Player_Pro()->_get_option( array('pro','improve_hls') )
        )
      ) {
        $url = plugins_url( 'stream-loader.php', __FILE__ );
      } else {
        $url = trailingslashit( home_url() );
      }

      /**
       * I would though that add_query_arg() will urlencode it, but...
       * If the URL was like this there would be issues:
       *
       * https://space.fra1.cdn.digitaloceanspaces.com/Ballad-Voicings,-Fills,-&-Improv/4-A-Section-Stride-Shells/index.m3u8 -->
       */
      $url = add_query_arg( 'stream_loader', urlencode( $video_id ), $url );

      if( $sub_playlist_id > -1 ) {
        $url = add_query_arg( 'pl', $sub_playlist_id, $url );
      }

      if( $cache_expire_override ) {
        $url = add_query_arg( 'cache_expire_override', $cache_expire_override, $url );
      }

      $url = add_query_arg( 'expire', $expire, $url );

      global $fv_fp;

      // If you cache the player HTML for logged in users, you need to pass this in shortcode
      if( $no_protection || isset($fv_fp->aCurArgs['protection']) && $fv_fp->aCurArgs['protection'] == 'false' ) {
        $string_to_sign = $video_id.$sub_playlist_id;
        $url = add_query_arg( 'protection', 'false', $url );

      // If Chromecast is allowed to process the HLS, we must not check the IP nor login status
      } else if( FV_Player_Pro()->_get_option( array('pro', 'chromecast_enc_hls') ) ) {
        $string_to_sign = $video_id.$sub_playlist_id;

      // For logged in users we check the login cookies
      // we do not check the IP here as the IP might change even for logged in user (?)
      } else if( is_user_logged_in() ) {
        $string_to_sign = $video_id.$sub_playlist_id.get_current_user_id();
        // we use "track" on purpose
        $url = add_query_arg( 'track', get_current_user_id(), $url );

      // Cache means different IP users will get the same m3u8 Stream Loader link
      } else if( $this->is_cache_on() ) {
        $string_to_sign = $video_id.$sub_playlist_id;

      // we can sign even with the IP, but only if the user is HTML cache is not on
      } else {
        $string_to_sign = $video_id.$sub_playlist_id.FV_Player_Pro()->get_client_ip();
      }

      if( $non_recursive ) {
        $url = add_query_arg( 'non_recursive', 1, $url );
      }

      $url = add_query_arg( 'signature', md5($string_to_sign.$expire.NONCE_SALT), $url );

      return $url;
    }

    /**
     * Check if is ssl connection
     *
     * @return boolean
     */
    function is_ssl() {
      // cloudflare
      if ( ! empty( $_SERVER['HTTP_CF_VISITOR'] ) ) {
        $cfo = json_decode( $_SERVER['HTTP_CF_VISITOR'] );
        if ( isset( $cfo->scheme ) && 'https' === $cfo->scheme ) {
          return true;
        }
      }

      // other proxy
      if ( ! empty( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] ) {
        return true;
      }

      return function_exists( 'is_ssl' ) ? is_ssl() : false;
    }

    /**
     * Clear the log
     *
     * @return void
     */
    function clear_log() {
      delete_option( 'fv_player_stream_loader_errors' );
    }

    /**
     * Insert or update cache entry
     *
     * @param string $cache_body
     * @param int|boolean $id
     * @param string $src
     * @param boolean $cache_expire
     *
     * @return void
     */
    function set_cache( $cache_body, $id, $src , $cache_expire = false) {
      global $wpdb;

      if( !$cache_expire ) {
        $cache_expire = time() + 30;
      } else {
        $cache_expire = time() + $cache_expire;
      }

      if( !$id ) { // insert new cache entry
        $wpdb->insert( $wpdb->prefix.'fv_player_stream_loader_cache', array(
          'stream_url' => $src,
          'stream_content' => $cache_body,
          'expires' => $cache_expire
        ), array(
          '%s',
          '%s',
          '%d'
        ) );
      } else { // update existing cache entry
        $wpdb->update( $wpdb->prefix.'fv_player_stream_loader_cache', array(
          'stream_content' => $cache_body,
          'expires' => $cache_expire
        ), array(
          'id' => $id
        ), array(
          '%s',
          '%d'
        ), array(
          '%d'
        ) );
      }
    }

  }

global $FV_Player_Pro_Stream_Loader;
$FV_Player_Pro_Stream_Loader = FV_Player_Pro_Stream_Loader::_get_instance();

function FV_Player_Pro_Stream_Loader() {
  return FV_Player_Pro_Stream_Loader::_get_instance();
}

endif;
