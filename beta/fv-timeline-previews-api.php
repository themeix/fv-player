<?php

if( class_exists('FV_Player_Video_Encoder') && !class_exists('FV_Player_Pro_Timeline_Previews_API') ):

class FV_Player_Pro_Timeline_Previews_API extends FV_Player_Video_Encoder {

  private static $instance = null;

  private $sprite_name; // store downloaded sprite filename

  public $plugin_api;

  /**
   * gets the instance via lazy initialization (created on first usage)
   */
  public static function getInstance( $encoder_id, $encoder_name, $encoder_wp_url_slug ) {
    if ( self::$instance === null ) {
      self::$instance = new static( $encoder_id, $encoder_name, $encoder_wp_url_slug );
    }

    return self::$instance;
  }

  /**
   * prevent the instance from being cloned (which would create a second instance of it)
   */
  private function __clone() {}

  /**
   * prevent from being unserialized (which would create a second instance of it)
   */
  public function __wakeup() {
    throw new Exception("Cannot unserialize singleton");
  }

  protected function __construct( $encoder_id, $encoder_name, $encoder_wp_url_slug) {
    $this->version = '7.5.25.7210';

    add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
    add_action( 'wp_ajax_fv_player_timeline_previews_api_job_check', array( $this, 'timeline_previews_api_job_check') );

    parent::__construct( $encoder_id, $encoder_name, $encoder_wp_url_slug);
  }

  public function heartbeat_check( $response, $data, $screen_id ) {}

  public function admin_menu() {
    if( isset($_GET['fv_thumbs']) || defined( 'FV_PLAYER_TIMELINE_PREVIEWS_GENERATOR' ) || !empty($_GET['page']) && $_GET['page'] == 'fv_player_pro_timeline_previews_api' ) {
      parent::admin_menu();
    }
  }

  public function admin_notices() {}

  function admin_enqueue_scripts( $page ) {
    if( $page == 'post.php' || $page == 'post-new.php' || $page == 'toplevel_page_fv_player' || $page == 'settings_page_fvplayer' || $page == 'fv_player_pro_generate_vtt_sprite' ) {
      wp_enqueue_script( 'fv-player-pro-timeline-previews-api', plugins_url( 'js/timeline-previews-api.js', __FILE__ ), array( 'jquery' ), filemtime( dirname(__FILE__).'/js/timeline-previews-api.js' ), true );

      wp_localize_script( 'fv-player-pro-timeline-previews-api', 'fv_player_pro_timeline_previews_api', array(
        'job_submit_nonce' => wp_create_nonce('fv_player_timeline_previews_api'),
        'job_check_nonce'  => wp_create_nonce('fv_player_timeline_previews_api_job_check'),
        'enabled'          => isset( $_GET['fv_thumbs'] ) || defined( 'FV_PLAYER_TIMELINE_PREVIEWS_GENERATOR' )
      ));

      $this->jobs_check(true);
    }
  }

  /**
   * Bunny Stream doesn't use configurations, so we'll just return the same config that we received.
   *
   * @param $conf Pre-populated configuration array into which the extending Encoder's class configuration should go.
   *
   * @return array Simply returns the same config that we received, since Bunny Stream doesn't use configurations.
   */
  public function default_settings( $conf ) {
    return $conf;
  }

  /**
   * Verifies the currently used endpoint supported by the extending Encoder, such as (S)FTP or S3 credentials
   * and either directly outputs a JSON-formatted error (for AJAX purposes) or returns the error to be processed further.
   *
   * @return mixed Returns TRUE if the current endpoint is set up properly, an error object/array otherwise.
   *               If we're running an AJAX request, this method must return a valid JSON-formatted error for that request
   *               by utilizing the wp_send_json() method in this format: wp_send_json( array('error' => $error) );
   */
  protected function verify_active_endpoint( $target ) {
    // if we have API key, library ID & CDN hostname, we're good and any errors (such as edited & invalid / expired API key) would show up
    // when we try to contact the API
    return $this->is_configured();
  }

  /**
   * Bunny Stream uses no configuration, it will encode videos according to each stream's settings.
   * This method simply therefore returns exactly what it was given.
   *
   * @param $args array Config array from the base encoder class.
   *
   * @return array      Returns the same $args array that it was given, since Bunny Streams use no configurations.
   */
  function get_conf( $args ) {
    return $args;
  }

  /**
   * Determines whether this Encoder has been properly configured.
   */
  function is_configured() {
    global $fv_fp;
    // return !empty($fv_fp) && method_exists($fv_fp,'_get_option') && $fv_fp->_get_option( array('timeline_previews_api','api_key') ) && $fv_fp->_get_option( array('timeline_previews_api','lib_id') ) && $fv_fp->_get_option( array('timeline_previews_api','cdn_hostname') );
    return true;
  }

  /**
   * Prepares and returns data to be inserted into the "output" column of this encoder's DB table.
   */
  protected function prepare_job_output_column_value() {
    return '';
  }

  public function job_create_expiration( $ttl ) {
    return 4 * 3600;
  }

  function jobs_check( $all = false ) {
    global $wpdb;

    $ids = array();
    if( $wpdb->get_var("SHOW TABLES LIKE '".$this->table_name."'") != $this->table_name ) {
      return $ids;
    }

    $pending_jobs = $wpdb->get_results( "SELECT * FROM ".  $this->table_name . " WHERE type = 'timeline_previews_api' AND status = 'processing'" . ( $all ? '' : ' AND date_checked < DATE_SUB( UTC_TIMESTAMP(), INTERVAL 30 SECOND )' ) );

    // $last_query = $wpdb->last_query;
    // $last_error = $wpdb->last_error;

    foreach( $pending_jobs AS $pending_job ) {
      $ids[] = $pending_job->id;

      $check_result = $this->job_check( $pending_job );

      // if this job was completed, update SRC of all players where its temporary placeholder is used
      // if ( $check_result['status'] == 'completed' ) {
      //   $this->update_temporary_job_src( $check_result, $pending_job->id );
      // }
    }

    return $ids;
  }

  /**
   * Update job status
   *
   * @param object|int $pending_job Table row from wp_fv_player_encoding_jobs or its job ID
   *
   * @global object $wpdb       WordPress database object
   * @global object $fv_fp      FV Player
   *
   * @return array
   * array(
   *  'result' object Job info from Bunny.net
   *  'status' string You get either "processing", "completed" or "error"
   *  'output' object URLs for resources - for Bunny Streams, we don't change CDN URLs, so both URLs here will be the same
   * )
   */
  protected function job_check( $pending_job ) {
    global $wpdb, $fv_fp, $FV_Player_Db;

    if( is_numeric($pending_job) ) {
      $pending_job = $wpdb->get_row( $wpdb->prepare( "SELECT id, id_video, job_id, progress, result, output FROM " . $this->table_name . " WHERE id = %d", $pending_job ) );
    }

    if( !$pending_job ) {
      return;
    }

    $output = json_decode( $pending_job->output );

    $job_id = $pending_job->job_id;
    $video_id =  $pending_job->id_video;

    $url = add_query_arg( 'job_id', $job_id, 'https://timeline-previews.foliovision.com/fv-video-thumbnails/job-download.php' );
    $url = add_query_arg( 'status', 1, $url );

    $job = wp_remote_get($url);

    if ( ! is_wp_error( $job ) ) {
      $job = json_decode( wp_remote_retrieve_body($job));
      $progress = 0;
      $status = $job->status;
      $frontend_status = $job->status;

      // new - waiting for processing
      if( $status == 'new' ) {
        $status = 'processing';
        $frontend_status = 'new';
        $progress = 25;
      }

      // download data
      if( $status == 'completed' ) {
        $sprite_downloaded = $this->download_file( 'sprite', $job_id );
        $vtt_downloaded = $this->download_file( 'vtt', $job_id );
        if( $sprite_downloaded && $vtt_downloaded ) { // check if both files were downloaded
          $status = $frontend_status = 'downloaded';
          $progress = 100;
          $output = array( 'sprite' => $sprite_downloaded, 'vtt' => $vtt_downloaded );
        } else {
          $status = 'download failed';
        }
      }

      $objVideo = new FV_Player_Db_Video( $video_id, array(), $FV_Player_Db );

      if ( $vtt_downloaded ) {
        $objVideo->updateMetaValue( "timeline_previews", $vtt_downloaded );
      }

      $objVideo->updateMetaValue( "timeline_previews_job_status", $status );

      $wpdb->update( $this->table_name, array(
        'result'       => json_encode( $job ),
        'status'       => $status,
        'date_checked' => date( "Y-m-d H:i:s" ),
        'progress'     => $progress . '%',
        'output'       => json_encode( $output ),
      ), array(
        'id' => $pending_job->id
      ), array(
        '%s',
        '%s',
        '%s',
        '%s',
        '%s'
      ), array(
        '%d'
      ) );
    } else {
      $wpdb->update( $this->table_name, array(
        'date_checked' => date( "Y-m-d H:i:s" ),
        'error'        => 'error',
      ), array(
        'id' => $pending_job->id
      ), array(
        '%s'
      ), array(
        '%d'
        )
      );

      $status = 0;
      $frontend_status = 'error - job check failed';
    }

    $ret = array(
      'result' => $job,
      'status' => $frontend_status,
      'output' => $output,
      'id' => $pending_job->id,
      'video_id' => $video_id
    );

    if ( isset($progress) ) {
      $ret['progress'] = $progress;
    } else if ( isset( $pending_job ) && !empty( $pending_job->progress ) ) {
      $ret['progress'] = $pending_job->progress;
    }

    return $ret;
  }

  /**
    * Submit the job to Bunny Stream and store the result in table
    *
    * @param int $job_id     Job ID

    * @global object $wpdb   WordPress database object
    * @global object $fv_fp  FV Player instance to load options with
    *
    * @return bool           Result
    */
  function job_submit( $id ) {
    global $fv_fp, $FV_Player_Db, $wpdb;

    $job = false;

    $row = $wpdb->get_row(
      $wpdb->prepare( "SELECT `source`, `id_video`, `target` FROM " . $this->table_name . " WHERE id = %s", $id )
    );

    $video_url = $row->source;
    $video_id = $row->id_video;
    $title = $row->target;

    $objVideo = new FV_Player_Db_Video( $video_id, array(), $FV_Player_Db );

    $webhook = add_query_arg('fv_player_timeline_previews_api_job_webhook', $id, home_url() );

    // extract src from url
    if( FV_Player_Pro_Vimeo()->is_vimeo( $video_url ) ) {
      $objVimeo = FV_Player_Pro_Vimeo()->get_vimeo($video_url);

      $response = $objVimeo->request->files->progressive;

      $min = PHP_INT_MAX;
      foreach( $response as $item ) {
        if( $item->height < $min ) {
          $min = $item->height;
          $src = $item->url;
        }
      }

      $video_src = $src;

      if( empty($title) || strcmp($title, 'target') == 0 ) {
        $title = $objVimeo->video->title;
      }

    } else {
      $hls_meta = $objVideo->getMetaValue('hls_hlskey', true );

      if( !empty($hls_meta) ) { // video is encrypted
        global $FV_Player_Pro_Hls;
        $ip = gethostbyname('timeline-previews.foliovision.com');

        $FV_Player_Pro_Hls->store_hls_access_tokens($video_url, $ip); // ffprobe
        $FV_Player_Pro_Hls->store_hls_access_tokens($video_url, $ip); // ffmpeg
      }

      $video_src = apply_filters('fv_flowplayer_video_src', $video_url, array('dynamic' => true) );

      // if hls then pick lowest quality
      if( stripos( $video_url, '.m3u8' ) != false ) {
        $lowest_quality = $this->m3u8_get_lowest_quality($video_src);

        if( !empty($lowest_quality) ) {
          $video_src = apply_filters('fv_flowplayer_video_src', $lowest_quality, array('dynamic' => true) );
          if( strcmp( $lowest_quality, $video_src ) != 0 ) {
            $video_src = FV_Player_Pro_Stream_Loader()->stream_loader_url( $lowest_quality, -1, false, 0, true );
          }
        } else {
          if( strcmp( $video_url, $video_src ) != 0 ) {
            $video_src = FV_Player_Pro_Stream_Loader()->stream_loader_url( $video_url, -1, false, 0, true );
          }
        }
      }

      if( empty($title) || strcmp($title, 'target') == 0 ) {
        $title = $objVideo->getCaptionFromSrc();
      }
    }

    $title = sanitize_title($title);

    if( preg_match( '/\.(mp4|m3u8)/', $title, $matches ) ) {
      $title = pathinfo($title, PATHINFO_FILENAME); // remove file extension
    }

    $data = array(
      'video_url' => $video_src,
      'webhook' => $webhook,
      'origin' => home_url('/'),
      'referer' => home_url('/'),
      'title' => $title
    );

    $response = wp_remote_post('https://timeline-previews.foliovision.com/fv-video-thumbnails/job-register.php', array(
      'timeout' => 20,
      'headers' => array(
        'content-type' => 'application/json'
      ),
      'body' => json_encode($data)
    ));

    if( !is_wp_error( $response ) ) {
      $job = json_decode( wp_remote_retrieve_body( $response ) );
    } else {
      $job = false;
    }

    $job_id = 0;
    $progress = '0%';

    if ( !empty($job) && isset($job->job_id) ) {
      $job_id = $job->job_id;
      $status = 'processing';
      $result = $job;

      // update video meta
      $objVideo->updateMetaValue( "timeline_previews_job_status", $status );

    } else {
      $result = array( 'exception' => 'error' );
      $status = 'error';
      $progress = 'failed';
      if(isset($job->status)) {
        $status = $job->status;
      }
    }

    $wpdb->update( $this->table_name, array(
      'job_id' => $job_id,
      'target' => $title,
      'result' => $result,
      'status' => $status,
      'progress' => $progress,
    ), array(
      'id' => $id // where id
    ), array(
      '%s', // job_id
      '%s', // target
      '%s', // result
      '%s', // status
      '%s'  // progress
    ), array(
      '%d'  // id
    ) );

    return array(
      'status' => $status,
      'result' => $result,
    );
  }

  /**
   * Displays the jobs listing page contents.
   */
  function tools_panel_jobs() {}

  /**
   * Displays the Encoder's settings page contents.
   */
  function tools_panel_settings() {}

  /**
   * Handle webhook
   *
   * @return void
   */
  function email_notification() {
    if( isset( $_GET['fv_player_timeline_previews_api_job_webhook'] ) && !empty($_GET['fv_player_timeline_previews_api_job_webhook']) ) {
      global $wpdb, $fv_fp , $FV_Player_Db;
      $body = file_get_contents( "php://input" );
      $webhook = json_decode( $body );

      if ( ! $webhook ) {
        return;
      }

      $id = intval($_GET['fv_player_timeline_previews_api_job_webhook'] );

      $row = $wpdb->get_row(
        $wpdb->prepare( "SELECT `job_id`, `author` , `target`, `id_video` FROM " . $this->table_name . " WHERE id = %d", $id )
      );

      $status = $webhook->status;

      // download data
      if( $status == 'completed' ) {
        $sprite_downloaded = $this->download_file( 'sprite', $row->job_id );
        $vtt_downloaded = $this->download_file( 'vtt', $row->job_id );
        $output = array( 'sprite' => $sprite_downloaded, 'vtt' => $vtt_downloaded );
        if( $sprite_downloaded && $vtt_downloaded ) {
          $status = 'downloaded';
          $progress = 100;
        } else {
          $status = 'download failed';
        }
      }

      $objVideo = new FV_Player_Db_Video( $row->id_video, array(), $FV_Player_Db );
      $objVideo->updateMetaValue( "timeline_previews_job_status", $status );

      $wpdb->update( $this->table_name, array(
        'result'       => json_encode( $webhook ),
        'status'       => $status,
        'date_checked' => date( "Y-m-d H:i:s" ),
        'progress'     => $progress . '%',
        'output'       => $output,
      ), array(
        'id' => $id
      ), array(
        '%s',
        '%s',
        '%s',
        '%s',
        '%s'
      ), array(
        '%d'
      ) );

      // $this->send_email( $job_id, $row->author, $status, $row->target, $row->result );

      die();
    }
  }

  /**
   * Respond to thumb check ajax
   *
   * @return void
   */
  public function timeline_previews_api_job_check() {
    if( defined('DOING_AJAX') && ( !isset( $_POST['nonce'] ) || !wp_verify_nonce( $_POST['nonce'], 'fv_player_timeline_previews_api_job_check' ) ) ) {
      wp_send_json( array('error' => 'Bad nonce') );
    }

    if( isset($_POST['id']) ) {
      $id = intval($_POST['id']);
    } else if( isset($_POST['video_id']) ) {
      global $wpdb;
      $video_id = intval( $_POST['video_id'] );
      $id = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(id) AS id FROM " . $this->table_name ." WHERE id_video = %d", $video_id ) ); // get latest job for video id
    }

    if( isset($id) ) {
      $result = $this->job_check( $id );
      wp_send_json( $result );
    }

    die();
  }

  /**
   * Get lowest quality sub playlist from HLS stream
   *
   * @param string $src
   *
   * @return string|bool lowest quality url or false on fail
   */
  private function m3u8_get_lowest_quality($src) {
    $response = wp_remote_get( $src, array( 'timeout' => 15 ) );

    $bandwith_min = PHP_INT_MAX;
    $url_min = false;

    if( !is_wp_error($response) ) {
      $body = wp_remote_retrieve_body($response);

      $lines = preg_split('/\r\n|\r|\n/', $body);
      $previous_line = '';

      foreach ( $lines as $line ) {
        if( stripos( $previous_line, '#EXT-X-STREAM-INF' ) === 0) {

          if( !preg_match('/^https?:\/\//', $line, $path_match) ) {
            $sub_url = dirname($src) .'/'. $line;
          } else {
            $sub_url = $line;
          }

          $values = explode(',', $previous_line);
          foreach($values as $index => $element) {
            if(preg_match('/BANDWIDTH=(\d+)/', $element, $match )) {
              if(isset($match[1])) {
                $bandwith = intval($match[1]);
                if( $bandwith < $bandwith_min ) {
                  $bandwith_min = $bandwith;
                  $url_min = $sub_url;
                }
              }
              continue;
            }
          }
        }

        $previous_line = $line;
      }
    }

    return $url_min;
  }

  private function download_file( $type, $job_id ) {
    global $wpdb;

    $title = $wpdb->get_var(
      $wpdb->prepare( "SELECT `target` FROM " . $this->table_name ." WHERE job_id = %d", $job_id )
    );

    $limit = 128 - 5; // .jpeg

    $title = sanitize_title($title);

    if( function_exists('mb_strinwidth') ) {
      $title = mb_strimwidth($title, 0, $limit, '', 'UTF-8');
    } else if( strlen( $title ) > $limit ) {
      $title = substr($title, 0, $limit);
    }

    $upload_dir = wp_upload_dir();
    $upload_path = str_replace( '/', DIRECTORY_SEPARATOR, $upload_dir['path'] ) . DIRECTORY_SEPARATOR;

    // if the function its not available, require it
    if ( ! function_exists( 'download_url' ) ) {
      require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    $download_url = add_query_arg( 'job_id', $job_id, 'https://timeline-previews.foliovision.com/fv-video-thumbnails/job-download.php' );

    if( $type == 'vtt' ) {
      $download_url = add_query_arg( 'download_vtt', 1, $download_url );
      $file_name = $title . '.vtt';
    } else {
      $download_url = add_query_arg( 'download_image', 1, $download_url );
      $file_name = $title . '.jpg';
    }

    $file_path = $upload_path . $file_name;
    $file_path = download_url( $download_url );

    if ( is_wp_error( $file_path ) ) {
      @unlink( $file_path );
      return false;
    }

     // Handle upload file
    if( !function_exists( 'wp_handle_sideload' ) ) {
      require_once( ABSPATH . 'wp-admin/includes/file.php' );
    }

    // Debug error
    if( !function_exists( 'wp_get_current_user' ) ) {
      require_once( ABSPATH . 'wp-includes/pluggable.php' );
    }

    // New file
    $file             = array();
    $file['error']    = '';
    $file['tmp_name'] = $file_path;
    $file['name']     = $file_name;
    $file['type']     = mime_content_type( $file_path );
    $file['size']     = filesize( $file_path );

    $file_return = wp_handle_sideload( $file, array( 'test_form' => false ) );

    if ( ! empty( $file_return['error'] ) ) {
      return false;
    }

    $file_name = $file_return['file'];

    $attachment = array(
      'post_mime_type' => $file_return['type'],
      'post_title' => preg_replace('/\.[^.]+$/', '', basename($file_name)),
      'post_content' => '',
      'post_status' => 'inherit',
      'guid' => $upload_dir['url'] . '/' . basename($file_name)
    );

    $attach_id = wp_insert_attachment( $attachment, $file_name, 0, true );

    if( is_wp_error( $attach_id ) ) {
      return false;
    } else {
      global $wpdb, $FV_Player_Db;

      $video_id =  $wpdb->get_var(
        $wpdb->prepare( "SELECT `id_video` FROM " . $this->table_name ." WHERE job_id = %d", $job_id )
      );

      $objVideo = new FV_Player_Db_Video( $video_id, array(), $FV_Player_Db );

      $output_url = '';

      if( $type == 'sprite' ) {
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        $this->sprite_name = basename( $file_name );

        $attach_data = wp_generate_attachment_metadata( $attach_id, $file_name );
        wp_update_attachment_metadata( $attach_id, $attach_data );

        $img_url = wp_get_attachment_image_url($attach_id, 'full', false);
        $objVideo->updateMetaValue( "timeline_previews_sprite", $img_url );
        $output_url = $img_url;
      }

      if( $type = 'vtt' ) {
        // update vtt file
        if( !empty( $this->sprite_name ) ) {
          $vtt_content = file_get_contents( $file_name );
          $vtt_content = str_replace('sprite.jpg#xywh', $this->sprite_name . '#xywh', $vtt_content );

          file_put_contents(  $file_name, $vtt_content );

          $vtt_url = wp_get_attachment_url( $attach_id );
          $objVideo->updateMetaValue( "timeline_previews", $vtt_url );
          $output_url = $vtt_url;
        }
      }

      return $output_url;
    }

  }

  /**
   * Must return __FILE__ from the extending class.
   * Used to determine plugin path for registering JS and CSS.
   */
  function getFILE() {
    return __FILE__;
  }
}

function FV_Player_Pro_Timeline_Previews_API() {
  return FV_Player_Pro_Timeline_Previews_API::getInstance( 'timeline_previews_api', 'Video Thumbnails', 'fv_player_pro_timeline_previews_api' );
}

// create the instance right away, so the browser and other assets are loaded correctly where they should be
FV_Player_Pro_Timeline_Previews_API();

endif;
