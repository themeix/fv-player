<?php

if( !class_exists('FV_Player_Pro_Peertube_Private') ) :

class FV_Player_Pro_Peertube_Private extends FV_Player_Pro_Ajax_Loader {
  function __construct() {

    $option = get_option('fv_player_peertube_private', array());

    $peertube_url = isset($option['peertube_private_url']) ? $option['peertube_private_url'] : false;

    if ( $peertube_url ) {
      $this->aDomains = array( rtrim($peertube_url, '/') . '/' );
    }

    $this->aSecureTokens = array( 'override' );

    add_action( 'admin_init', array( $this, 'cron_init' ) );

    add_action( 'admin_init', array( $this, 'register_meta_boxes' ), 20 );

    add_action( 'fv_player_peertube_private_cron', array( $this, 'refresh_tokens' ) );

    add_action( 'wp_ajax_fv_player_peertube_get_tokens', array( $this, 'generate_tokens' ) );

    add_action( 'wp_ajax_fv_player_peertube_refresh_tokens', array( $this, 'refresh_tokens_ajax' ) );

    add_filter( 'fv_flowplayer_get_mime_type', array( $this, 'set_file_type' ), 10 , 2 );

    add_filter( 'fv_player_meta_data', array( $this, 'fetch_peertube_data' ), 10, 2); // splash, caption, duration

    add_filter( 'fv_player_video_checker_skip', array( $this, 'skip_video_checker'), 10, 2 ); // takes too long to load page if not skipped

    parent::__construct( array( 'key' => 'peertube_private', 'title' => 'PeerTube') );
  }

  /**
   * Schedule cron job
   *
   * @return void
   */
  function cron_init() {
    $option = get_option('fv_player_peertube_private', array());

    $configured = isset($option['peertube_private_refresh_token']) && isset($option['peertube_private_client_id']) && isset($option['peertube_private_client_secret']) && isset($option['peertube_private_access_token']);

    if ( $configured && !wp_next_scheduled( 'fv_player_peertube_private_cron' ) ) {
      wp_schedule_event( time(), 'hourly', 'fv_player_peertube_private_cron' );
    } else if( !$configured && wp_next_scheduled( 'fv_player_peertube_private_cron' ) ) {
      wp_clear_scheduled_hook( 'fv_player_peertube_private_cron' );
    }

  }

  /**
   * Generate required tokens from PeerTube
   *
   * @param bool $force when true tokens are generated without checking $_POST data
   *
   * @return void|array when $force is true array is returned
   */
  function generate_tokens($force = false) {
    if( (defined('DOING_AJAX') && DOING_AJAX && !empty($_POST['url']) && !empty($_POST['username']) && !empty($_POST['password'])) || $force ) {

      $output = array();

      if( !$force ) {
        $url = rtrim( esc_url_raw( trim( $_POST['url'] ) ), '/');
        $username = sanitize_text_field( trim( $_POST['username'] ) );
        $password = sanitize_text_field( trim( $_POST['password'] ) );
      } else {
        $options = get_option('fv_player_peertube_private', array());
        if( !isset($options['peertube_private_url']) || !isset($options['peertube_private_username']) || !isset($options['peertube_private_password']) ) {
          return array( 'error' => 'Error: Missing required data.' );
        }

        $url = $options['peertube_private_url'];
        $username = $options['peertube_private_username'];
        $password = $options['peertube_private_password'];
      }

      $tokens_url = $url.'/api/v1/oauth-clients/local';

      // get client id & secret
      $response = wp_remote_get( $tokens_url, array( 'timeout' => 20 ) );

      if( !is_wp_error($response) ) {
        $response = json_decode( wp_remote_retrieve_body( $response ) );

        if( isset($response->client_id) && isset($response->client_secret) ) {
          $client_id = $response->client_id;
          $client_secret = $response->client_secret;

          // get access token
          $tokens_url = $url.'/api/v1/users/token';

          $args = array(
            'body' => array(
              'client_id' => $client_id,
              'client_secret' => $client_secret,
              'username' => $username,
              'password' => $password,
              'grant_type' => 'password',
              'response_type' => 'code'
            ),
            'timeout' => 20,
          );

          $response = wp_remote_post( $tokens_url, $args );

          if( is_wp_error($response) ) {
            $output = array('error' => 'Error: ' . $response->get_error_message() );

            if(!$force) {
              wp_send_json( $output );
              die();
            } else {
              return $output;
            }
          }

          $response = json_decode( wp_remote_retrieve_body( $response ) );

          if( isset($response->access_token) ) {
            $options = get_option('fv_player_peertube_private', array());

            unset( $options['error'] );
            unset( $options['error_time'] );

            // save tokens
            $options['peertube_private_url'] = $url;
            $options['peertube_private_username'] = $username;
            $options['peertube_private_password'] = $password;
            $options['peertube_private_access_token'] = $response->access_token;
            $options['peertube_private_refresh_token'] = $response->refresh_token;
            $options['peertube_private_client_id'] = $client_id;
            $options['peertube_private_client_secret'] = $client_secret;
            $options['peertube_private_token_created'] = time();
            $options['peertube_private_token_expires'] = $response->expires_in;

            update_option('fv_player_peertube_private', $options);

            $output = array('success' => true);
          } else {
            $output = array('error' => 'Invalid credentials');
          }
        } else {
          $output = array('error' => 'Unexpected response: ' . var_export( $response, true ) );
        }
      } else {
        $output = array('error' => 'Error: ' . $response->get_error_message() );
      }
    } else {
      $output = array('error' => 'Missing data');
    }

    if( !$force ) {
      wp_send_json( $output );
      die();
    }

    return $output;
  }

  /**
   * Refresh access token using refresh token
   *
   * @return void|stdClass returns void if missing data
   */
  function refresh_tokens() {
    $options = get_option('fv_player_peertube_private', array());

    // check if we have all required data
    if( empty($options) || empty($options['peertube_private_client_id']) || empty($options['peertube_private_client_secret']) || empty($options['peertube_private_refresh_token']) || empty($options['peertube_private_url']) ) {
      return;
    }

    // load data
    $client_id = $options['peertube_private_client_id'];
    $client_secret = $options['peertube_private_client_secret'];
    $refresh_token = $options['peertube_private_refresh_token'];
    $url = $options['peertube_private_url'].'/api/v1/users/token';

    $args = array(
      'body' => array(
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'refresh_token' => $refresh_token,
        'grant_type' => 'refresh_token',
      ),
    );

    // refresh token
    $response = wp_remote_post( $url, $args );

    if( is_wp_error($response) ) {

      if( FV_Player_Pro()->_get_option( array('pro', 'debug_log') ) === 'verbose' ) {
        file_put_contents( ABSPATH.'fv-player-debug-'.wp_hash('fv-player-debug-log','log').'.log', date('Y-m-d h:j:s') . ' PeerTube HTTP Error: ' . $response->get_error_message() . "\n\n", FILE_APPEND );
      }

      $options['error'] = $response->get_error_message();
      $options['error_time'] = time();
      update_option('fv_player_peertube_private', $options);
      return;
    }

    $response = json_decode( wp_remote_retrieve_body( $response ) );

    if( isset($response->access_token) ) {

      if( FV_Player_Pro()->_get_option( array('pro', 'debug_log') ) === 'verbose' ) {
        file_put_contents( ABSPATH.'fv-player-debug-'.wp_hash('fv-player-debug-log','log').'.log', date('Y-m-d h:j:s') . " PeerTube Succeeded at refreshing the token.\n\n", FILE_APPEND );
      }

      // save new tokens
      $options['peertube_private_access_token'] = $response->access_token;
      $options['peertube_private_refresh_token'] = $response->refresh_token;
      $options['peertube_private_token_created'] = time();
      $options['peertube_private_token_expires'] = $response->expires_in;

      unset( $options['error'] );
      unset( $options['error_time'] );

      update_option('fv_player_peertube_private', $options);

    } else {
      // falback to generating new client and new tokens
      $output = $this->generate_tokens(true);

      if( isset($output['success']) ) {
        if( FV_Player_Pro()->_get_option( array('pro', 'debug_log') ) === 'verbose' ) {
          file_put_contents( ABSPATH.'fv-player-debug-'.wp_hash('fv-player-debug-log','log').'.log', date('Y-m-d h:j:s') . " PeerTube Succeeded at getting a new token.\n\n", FILE_APPEND );
        }

        $response = (object) $output;
      } else {
        if( isset($response->error) ) {
          if( FV_Player_Pro()->_get_option( array('pro', 'debug_log') ) === 'verbose' ) {
            file_put_contents( ABSPATH.'fv-player-debug-'.wp_hash('fv-player-debug-log','log').'.log', date('Y-m-d h:j:s') . ' PeerTube Error: ' . $response->error . "\n\n", FILE_APPEND );
          }

          $options['error'] = $response->error;
          $options['error_time'] = time();
          update_option('fv_player_peertube_private', $options);
        } else {
          if( FV_Player_Pro()->_get_option( array('pro', 'debug_log') ) === 'verbose' ) {
            file_put_contents( ABSPATH.'fv-player-debug-'.wp_hash('fv-player-debug-log','log').'.log', date('Y-m-d h:j:s') . " PeerTube Unknown error.\n\n", FILE_APPEND );
          }

          $options['error'] = 'Unknown error';
          $options['error_time'] = time();
          update_option('fv_player_peertube_private', $options);
        }
      }
    }

    return $response;
  }

  /**
   * Refresh tokens via AJAX
   *
   * @return void
   */
  function refresh_tokens_ajax() {
    if ( ! wp_verify_nonce( $_POST['nonce'], 'fv_player_peertube_refresh_tokens' ) ) {
      wp_send_json( array( 'error' => 'Nonce error, please reload the page and try again.' ) );
    }

    $refresh = $this->refresh_tokens();

    if ( ! empty( $refresh->error ) ) {
    wp_send_json( $this->refresh_tokens() );
    }

    ob_start();
    $this->options();
    $settings_box = ob_get_clean();

    wp_send_json( array(
      'success' => true,
      'html'    => $settings_box
    ) );
  }

  /**
   * Add item to args for check in js to enable is_dynamic
   *
   * @param array $args
   *
   * @return array
   */
  function args($args) {
    $args[] = 'videoFileToken';
    return $args;
  }

  /**
   * Settings box
   *
   * @return void
   */
  function options() {

    $options = get_option('fv_player_peertube_private', array());
    $configured = false;

    if( !empty( $options ) ) {
      $url = isset($options['peertube_private_url']) ? $options['peertube_private_url'] : '';
      $username = isset($options['peertube_private_username']) ? $options['peertube_private_username'] : '';
      $password = isset($options['peertube_private_password']) ? $options['peertube_private_password'] : '';
      $client_id = isset($options['peertube_private_client_id']) ? $options['peertube_private_client_id'] : '';
      $client_secret = isset($options['peertube_private_client_secret']) ? $options['peertube_private_client_secret'] : '';
      $access_token = isset($options['peertube_private_access_token']) ? $options['peertube_private_access_token'] : '';
      $peertube_private_token_created = isset($options['peertube_private_token_created']) ? $options['peertube_private_token_created'] : 0;
      $peertube_private_token_expires = isset($options['peertube_private_token_expires']) ? $options['peertube_private_token_expires'] : 0;
      $refresh_token = isset($options['peertube_private_refresh_token']) ? $options['peertube_private_refresh_token'] : '';

      $error = isset($options['error']) ? $options['error'] : '';
      $error_time = isset($options['error_time']) ? $options['error_time'] : '';

      $configured = $client_id && $client_secret && $url && $username && $password && $access_token && $refresh_token;
    }

    ?>
    <table id="fv_player_peertube_new_token" class="form-table2" style="margin: 5px; <?php if ( $configured ) echo 'display: none'; ?>">
      <?php
        FV_Player_Pro()->_get_input_text( array(
          'first_td_class' => 'first',
          'key' => array( 'pro', 'peertube_private_url' ),
          'name' => __('URL', 'fv-player-pro')
        ));

        FV_Player_Pro()->_get_input_text( array(
          'key' => array( 'pro', 'peertube_private_username' ),
          'name' => __('Username', 'fv-player-pro')
        ));

        FV_Player_Pro()->_get_input_text( array(
          'key' => array( 'pro', 'peertube_private_password' ),
          'name' => __('Password', 'fv-player-pro')
        ));
      ?>
      <tr>
        <td></td>
        <td>
          <span id="peertube-tokens-status" style="display: none;"></span>
          <a class="button button-primary" href="#" id="peertube-get-tokens" >Generate tokens</a>
        </td>
      </tr>
    </table>

    <?php if( $configured ): ?>

      <table class="form-table2" style="margin: 5px; ">
        <tr>
          <td style="width: 250px"><label>Instance:</labe></td>
          <td>
            <?php echo $url; ?>
          </td>
        </tr>
        <tr>
          <td style="width: 250px"><label>User:</label></td>
          <td>
            <?php echo $username; ?>
          </td>
        </tr>
        <?php
        $expiration = $peertube_private_token_created + $peertube_private_token_expires;

        if ( $expiration > time() ) :
          ?>
          <tr>
            <td><label>Token:</label></td>
            <td id="fv_player_peertube_token_status">
              Expires on <?php echo date_i18n( get_option( 'date_format' ), $expiration ) . '  ' . date_i18n( get_option( 'time_format' ), $expiration ); ?> with auto-renewing.
            </td>
          </tr>
        <?php else : ?>
          <tr data-fv_player_peertube_error>
            <td><label><strong>Error</strong>:</label></td>
            <td>
              Your PeerTube token has expired on <?php echo date_i18n( get_option( 'date_format' ), $expiration ) . '  ' . date_i18n( get_option( 'time_format' ), $expiration ); ?>. Is your WP Cron failing?
            </td>
          </tr>
        <?php endif;
        if ( $error ) : ?>
          <tr data-fv_player_peertube_error>
            <td><label><strong>Error</strong>:</label></td>
            <td>
              <?php echo $error; ?> (<?php echo date_i18n( get_option( 'date_format' ), $error_time ) . '  ' . date_i18n( get_option( 'time_format' ), $error_time ); ?>)
            </td>
          </tr>
        <?php endif; ?>
        <tr>
          <td></td>
          <td>
            <?php if ( $expiration < time() ) : ?>
              <a class="button" href="#" id="fv_player_peertube_refresh_tokens">Refresh Tokens</a>
            <?php endif; ?>
            <a class="button" href="#" id="fv_player_peertube_reset">Reset Tokens</a>
          </td>
        </tr>

      </table>

    <?php endif; ?>

    <script>
      jQuery(function($) {
        $('#peertube-get-tokens').on('click', function(e) {
          e.preventDefault();
          var status_span = $('#peertube-tokens-status').show();
          status_span.text('Generating tokens...');
          $.post(ajaxurl, {
            action: 'fv_player_peertube_get_tokens',
            url: $('#'+$.escapeSelector('pro[peertube_private_url]')).val(),
            username: $('#'+$.escapeSelector('pro[peertube_private_username]')).val(),
            password: $('#'+$.escapeSelector('pro[peertube_private_password]')).val(),
          }, function(response) {

            if ( typeof response != 'object' ) {
              alert( "Ajax error, invalid response:\n\n" + response );
              return;
            }

            if( response.success ) {
              status_span.text('Tokens saved');
            } else if ( response.error ) {
              status_span.text( response.error );
            }
          });
        });

        $( '#fv_player_peertube_refresh_tokens' ).on( 'click', function() {
          let button = $(this);

          button.prop( 'disabled', true ).text( 'Refreshing...' );

          $.post(ajaxurl, {
            action: 'fv_player_peertube_refresh_tokens',
            nonce: '<?php echo wp_create_nonce( 'fv_player_peertube_refresh_tokens' ); ?>'
          }, function(response) {
            button.prop( 'disabled', false ).text( 'Refresh Tokens' );

            if ( typeof response != 'object' ) {
              alert( "Ajax error, invalid response:\n\n" + response );
              return;
            }

            if ( response.error ) {
              $( '#fv_player_peertube_token_status' ).html( response.error );

              if ( response.error.match( /is invalid/ ) ) {

              }
            }

            if ( response.html ) {
              $( '#fv_player_peertube_private .inside' ).html( response.html );
            }
          });

          return false;
        });

        $( '#fv_player_peertube_reset' ).on( 'click', function() {
          $( '#fv_player_peertube_new_token' ).show();
          return false;
        });
      });
    </script>

    <?php
  }

  function register_meta_boxes() {
    add_meta_box( 'fv_player_peertube_private', __('PeerTube Self-Hosted', 'fv-player-pro'), array( $this, 'options' ), 'fv_flowplayer_settings_hosting', 'normal', 'low' );
  }

  function secure_link( $url, $securityKey, $ttl = false ) {
    $video_id = $this->get_video_id($url);

    $new_cache = false;
    $error_message = 'Failed to load video';

    // Not an actual PeerTube video URL
    if( !$video_id ) {
      return $url;
    }

    $option = get_option('fv_player_peertube_private', array());

    $peertube_url = isset($option['peertube_private_url']) ? $option['peertube_private_url'] : false;
    $access_token = isset($option['peertube_private_access_token']) ? $option['peertube_private_access_token'] : false;

    if( !$peertube_url || !$access_token ) {
      $_POST['error'] = __( 'PeerTube error: Unsupported domain or private video.', 'fv-player-pro');
      return false;
    }

    if ( $cached_url = $this->load_cache( $video_id ) ) {
      return $cached_url;
    }

    // Refresh token if necessary
    $peertube_private_token_created = isset($option['peertube_private_token_created']) ? intval( $option['peertube_private_token_created'] ) : 0;
    $peertube_private_token_expires = isset($option['peertube_private_token_expires']) ? intval( $option['peertube_private_token_expires'] ) : 0;

    $expiration = $peertube_private_token_created + $peertube_private_token_expires;
    if ( $expiration < time() ) {
      $this->refresh_tokens();

      $option = get_option('fv_player_peertube_private', array());

      $access_token = isset($option['peertube_private_access_token']) ? $option['peertube_private_access_token'] : false;
    }

    $api_url = $peertube_url . '/api/v1/videos/' . $video_id;

    $args = array(
      'headers' => array(
        'Authorization' => 'Bearer ' . $access_token,
      ),
      'timeout' => 20,
    );

    // get m3u8
    $response = wp_remote_get( $api_url, $args );

    if( !is_wp_error( $response ) ) {
      $body = wp_remote_retrieve_body( $response );

      $video_data = json_decode( $body, true );

      if( isset($video_data['streamingPlaylists'][0]['playlistUrl']) ) { // get m3u8
        $new_cache = $video_data['streamingPlaylists'][0]['playlistUrl'];

        // get token to access m3u8 without bearer token
        $api_url = $peertube_url . '/api/v1/videos/' . $video_id . '/token';

        $response = wp_remote_post( $api_url, $args );

        if( !is_wp_error( $response ) ) {
          $body = wp_remote_retrieve_body( $response );

          $video_data = json_decode( $body, true );

          // add token to m3u8 url and set flag to reinject token to m3u8
          if( isset($video_data['files']['token']) ) {
            $new_cache = add_query_arg( 'videoFileToken', $video_data['files']['token'], $new_cache );
            $new_cache = add_query_arg( 'reinjectVideoFileToken', 'true', $new_cache );
          } else {
            $error_message = isset($video_data['error']) ? $video_data['error'] : 'Unknown error';
          }
        } else {
          $error_message = $response->get_error_message();
        }
      } else {
        // check if still transcodeing - https://docs.joinpeertube.org/api-rest-reference.html#tag/Video/operation/getVideo (state)
        if( isset($video_data['state']) && $video_data['state']['id'] == 2 ) {
          $error_message = 'Video is still being transcoded';
        } else {
          $error_message = isset($video_data['error']) ? $video_data['error'] : 'Unknown error';
        }
      }
    } else {
      $error_message = $response->get_error_message();
    }

    if( !$new_cache ) {
      $_POST['error'] = 'PeerTube error: ' . $error_message;
      return false;
    }

    return $this->store_cache( $video_id, $new_cache );
  }

  function set_file_type( $type ) {
    $args = func_get_args();
    if( isset($args[1]) ) {
       if( $this->get_video_id($args[1]) ) {
        $type = "video/mp4";

        global $fv_fp;
        $fv_fp->load_hlsjs = true;
      }
    }

    return $type;
  }

  function fetch_peertube_data($url, $post_id = false) {
    $video_id = $this->get_video_id($url);

    if( !$video_id ) {
      return $url;
    }

    $option = get_option('fv_player_peertube_private', array());

    $peertube_url = isset($option['peertube_private_url']) ? $option['peertube_private_url'] : false;
    $access_token = isset($option['peertube_private_access_token']) ? $option['peertube_private_access_token'] : false;

    if( !$peertube_url || !$access_token ) {
      return $url;
    }

    $api_url = $peertube_url . '/api/v1/videos/' . $video_id;

    $args = array(
      'headers' => array(
        'Authorization' => 'Bearer ' . $access_token,
      ),
      'timeout' => 20,
    );

    $response = wp_remote_get( $api_url, $args );
    $videoData = false;

    if( !is_wp_error($response) ) {
      $body = wp_remote_retrieve_body( $response );

      $video_data = json_decode( $body, true );

      $duration = intval($video_data['duration']);

      // Prefer previewPath as it has higher resolution image
      $splash_url = !empty($video_data['previewPath']) ? $video_data['previewPath'] : $video_data['thumbnailPath'];

      $splash = esc_url( $peertube_url . html_entity_decode($splash_url) );
      $caption = htmlspecialchars( $video_data['name']);
      $synopsis = isset($video_data['description']) ? $video_data['description'] : '';

      $videoData = array(
        'name' => str_replace( array(';','[',']'), array('\;','(',')'), ($caption) ),
        'thumbnail' => $splash,
        'duration' => $duration,
        'synopsis' => $synopsis,
      );

      if( isset($video_data['isLive']) && $video_data['isLive'] ) {
        $videoData['is_live'] = true;
      }

    }

    return $videoData;
  }

  function skip_video_checker( $skip, $media ) {
    if( $this->get_video_id($media) ) {
      $skip = true;
    }

    return $skip;
  }

  /**
   * Return matched url from link
   *
   * @param string $src
   *
   * @return string|false if matched id then returns it, otherwise returns false
   */
  function is_peertube_private_url($src) {
    $options = get_option('fv_player_peertube_private', array());

    if( empty( $options ) || empty($options['peertube_private_url']) ) {
      return false;
    }

    $url = $options['peertube_private_url'];

    if( stripos($src, $url) !== false ) {
      return $url;
    }

    return false;
  }

  /**
   * Return video id from link
   *
   * @param mixed $url
   *
   * @return string|false if matched id then returns it, otherwise returns false
   */
  function get_video_id($url) {
    if( is_string($url) && $this->is_peertube_private_url($url) ) {
      if( preg_match('~^/w/([a-zA-Z0-9\-]+)$~', wp_parse_url($url, PHP_URL_PATH), $matches) ) {
        return $matches[1];
      }
    }

    return false;
  }

  /**
   * Get subtitles by url
   *
   * @param string $url
   * @param string|false $securityKey
   *
   * @return array|false
   */
  function get_subtitles($url, $securityKey = false) {
    $options = get_option('fv_player_peertube_private', array());

    if( empty( $options ) || empty($options['peertube_private_url']) ) {
      return false;
    }

    $video_id = $this->get_video_id($url);

    if( !$video_id ) {
      return false;
    }

    $subtitles_id = $video_id . '-subtitles';

    $cache = $this->load_cache( $subtitles_id, false, true );

    if( is_array($cache) ) {
      return $cache;
    }

    $peertube_url = $options['peertube_private_url'];

    $api_url = $peertube_url . '/api/v1/videos/' . $video_id . '/captions';

    // TODO: Really realy no API token required?
    $response = wp_remote_get( $api_url, array( 'timeout' => 10 ) );
    $captions_parsed = array();

    if( !is_wp_error($response) ) {
      $body = wp_remote_retrieve_body( $response );

      $captions = json_decode( $body, true );

      if( isset($captions['data']) && is_array($captions['data']) ) {
        foreach( $captions['data'] as $caption ) {
          $new_caption = array(
            'label' => $caption['language']['label'],
            'src' => $peertube_url . $caption['captionPath'],
            'srclang' => $caption['language']['id'],
            'kind' => 'subtitles'
          );

          // set default to the first one
          if( !isset($captions_parsed[0]) ) {
            $new_caption['default'] = true;
          }

          $captions_parsed[] = $new_caption;
        }
      }
    }

    return $this->store_cache( $subtitles_id, $captions_parsed, 3600, 900, true );
  }

  /**
   * Get subtitles by url
   *
   * @param string $url
   * @param string|false $securityKey
   *
   * @return array|false
   */
  function get_timeline_previews($url, $securityKey = false) {
    $options = get_option('fv_player_peertube_private', array());

    if( empty( $options ) || empty($options['peertube_private_url']) ) {
      return false;
    }

    $video_id = $this->get_video_id($url);

    if( !$video_id ) {
      return false;
    }

    $cache_id = $video_id . '-timeline-previews';

    $cache = $this->load_cache( $cache_id, false, true );

    if( is_array($cache) ) {
      return $cache;
    }

    $peertube_url = $options['peertube_private_url'];

    $api_url = $peertube_url . '/api/v1/videos/' . $video_id . '/storyboards';

    // TODO: Really realy no API token required?
    $response = wp_remote_get( $api_url, array( 'timeout' => 10 ) );
    $timeline_previews_parsed = array();

    if( !is_wp_error($response) ) {
      $body = wp_remote_retrieve_body( $response );

      $timeline_previews = json_decode( $body, true );

      if( isset( $timeline_previews['storyboards']) && is_array( $timeline_previews['storyboards']) ) {
        foreach( $timeline_previews['storyboards'] as $caption ) {
          $new_caption = array(
            'src' => $peertube_url . $caption['storyboardPath'],
            'sprite_conf' => array(
              'interval'     => $caption['spriteDuration'],
              'total_height' => $caption['totalHeight'],
              'total_width'  => $caption['totalWidth'],
              'height'       => $caption['spriteHeight'],
              'width'        => $caption['spriteWidth'],
            ),
          );

          $timeline_previews_parsed[] = $new_caption;
        }
      }
    }

    return $this->store_cache( $cache_id, $timeline_previews_parsed, 3600, 900, true );
  }
}

global $FV_Player_Pro_Peertube_Private;
$FV_Player_Pro_Peertube_Private = new FV_Player_Pro_Peertube_Private;

endif;
