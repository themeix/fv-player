<?php

if ( !class_exists('FV_Player_Pro_Vimeo') ) :

class FV_Player_Pro_Vimeo {

  static $instance = null;

  var $iLoadingRecursion = 0;

  public static function _get_instance() {
    if( !self::$instance ) {
      self::$instance = new self();
    }

    return self::$instance;
  }

  function __construct() {
    add_action( 'admin_notices', array( $this, 'admin_key_check_note') );

    if( isset($_POST['action']) && $_POST['action'] === 'fv_fp_get_vimeo' ) {
      add_action( 'plugins_loaded', array( $this, 'ajax' ) );
    }

    // Cleanup legacy MPD .htaccess file
    add_action( 'shutdown', array( $this, 'remove_mpd_htaccess') );
  }

  function admin_key_check_cache() {
    if( !FV_Player_Pro()->get__vimeo_key() ) {
      return false;
    }

    $aCache = get_option('fv_player_pro_vimeo_check');
    if( $aCache && !empty($aCache['time']) && $aCache['time'] + 6 * 3600 > time() ) {
      $result = $aCache;

    } else {
      $result = array();
      $result['time'] = time() - 6 * 3600 + 900;

      try {
        $result = FV_Player_Pro_Vimeo::request('/oauth/verify');

        $this->log_details( " /oauth/verify on ".$_SERVER['REQUEST_URI']."\n", $result );

        if( isset($result['body']) ) {

          $errors = array();

          if ( ! empty( $result['body']['scope']) && stripos( $result['body']['scope'], 'video_files' ) === false ) {
            $html = '<p>You are missing the Video Files capability on your Vimeo API token. Due to recent changes on Vimeo it will have to be regenerated with the proper permissions.</p>';
            $html .= '<p>Please generate a new token here: <a href="https://developer.vimeo.com' . $result['body']['app']['uri'] . '" target="_blank">' . $result['body']['app']['name'] . ' app on developer.vimeo.com</a>.</p>';
            $html .= '<p><img src="https://cdn.foliovision.com/images/2019/09/authentication_vimeo_api.jpg" style="max-width: 800px" /></p>';
            $html .= '<p>Then put it into the field above.</p>';
            $html .= '<p>Full instructions are available here: <a href="https://foliovision.com/player/video-hosting/how-to-use-vimeo#access-token-setup" target="_blank">How to Use Vimeo with WordPress</a></p>';

            $errors[] = $html;
          }

          if ( ! empty( $result['body']['user']['account'] ) && ! in_array( $result['body']['user']['account'], array( 'pro', 'pro_unlimited', 'business', 'premium', 'standard', 'advanced', 'live_premium' ) ) ) {
            $html = '<p>Your Vimeo plan is <code>' . $result['body']['user']['account'] . '</code>. You need at least a Vimeo "Pro" or "Standard" plan to use Vimeo with 3rd party players, including FV Player Pro.</p>';
            $html .= '<p>Then you will be able to play the Vimeo videos which belong to your account.</p>';

            $errors[] = $html;
          }

          if ( ! empty( $errors) ) {
            $html = '';
            foreach( $errors as $num => $error ) {
              $html .= preg_replace( '~^<p>~', '<p><strong>' . ( $num + 1 ) . '.</strong> ', $error );
            }
            $result['error'] = $html;
            $result['time'] = time();

          } else {
            $result = $result['body'];
            $result['time'] = time();
          }
        } else {
          $result['error'] = 'Vimeo key check failed';
        }

      } catch( Exception $e ) {
        $result['error'] = 'Vimeo key check failed: '.$e->getMessage();
      }

      update_option( 'fv_player_pro_vimeo_check', $result );

    }

    return $result;
  }

  function admin_key_check_note() {
    if( !FV_Player_Pro()->get__vimeo_key() ) {
      return;
    }

    $result = $this->admin_key_check_cache();
    if( !empty($result['error']) ) : ?>
      <div class="error">
        <p><?php
        echo sprintf(
          __('FV Player Pro: Your Vimeo access token is invalid, your videos won\'t play. <a href="%s">Click here</a> to fix this issue.', 'fv-player-pro'),
          admin_url( 'admin.php?page=fvplayer#postbox-container-tab_hosting' )
        );
        ?></p>
      </div>
    <?php endif;
  }

  public function ajax() {
    if( FV_Player_Pro()->is_option_enabled('vimeo') ) {
      @header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );

      $aRequest = parse_url($_SERVER['HTTP_REFERER']);
      $aHome = parse_url(home_url());

      if( $aRequest['host'] != $aHome['host'] ) {
        echo json_encode( array( 'error' => 'Bad request!' ) );
        die();
      }

      $aData = $this->get_vimeo( $_POST['sources'][0]['src'] );

      unset($aData->request->referrer);

      if( isset($aData->request->files->dash) && !empty($aData->request->files->dash->cdns) && empty($aData->request->files->dash->url) ) {
        foreach( $aData->request->files->dash->cdns AS $k => $v ) {
          $aData->request->files->dash->url = $aData->request->files->dash->cdns->$k->url;
          break;
        }
      }

      if( is_object($aData) ) {
        $aData = apply_filters( 'fv_player_pro_vimeo_data', $aData );

        echo '<FVFLOWPLAYER>';
        echo json_encode( $aData );
      } else {
        echo '<FVFLOWPLAYER>';
        echo json_encode( array( 'error' => 'Unknown error' ) );
      }
      echo '</FVFLOWPLAYER>';
      die();
    }
  }

  public function can_vimeo_dash_manifest() {
    global $fv_wp_flowplayer_ver;
    return version_compare($fv_wp_flowplayer_ver,'6.6.1') != -1; // some future version
  }

  public function get_iframe_for_fv_player_vimeo_live( $video_id ) {
    $url = '//player.vimeo.com/video/'.intval($video_id);

    $objVideo = new stdClass;
    $objVideo->video = new stdClass;
    // here we assume the stream is still live just to be safe
    $objVideo->video->live_event = true;
    $objVideo->video->embed_code = '<iframe src="'.esc_attr($url).'" allow="autoplay; fullscreen; picture-in-picture" />';
    $objVideo->time = time();
    $objVideo->ttl = 900;

    return $objVideo;
  }

  function get_vimeo( $video_url ) {

    // If it's Vimeo Event, we need to obtain the actual video ID
    if( $event_id = $this->is_vimeo_event($video_url) ) {
      $video_url = $this->get_vimeo_event($video_url);
    }

    if( !$video_id = $this->get_vimeo_id($video_url) ) {
      return json_decode(json_encode(array( 'error' => 'Bad video ID!' )), FALSE);
    }

    $password = $this->get_password($video_url);
    $cache_key = $this->get_transient_name('vimeo').$video_id.$password;

    $bFound = false;
    $objVideo = get_option( $cache_key );
    if( empty($_GET['fv_retry_count']) && $objVideo && isset($objVideo->time) && isset($objVideo->ttl) && (intval($objVideo->time) + intval($objVideo->ttl)) > time() ) {
      $objVideo->cache = true;
      $objVideo->time_now = time();
      $bFound = true;
    }

    if( defined('FV_PLAYER_PRO_VIMEO_API_URL') ) {
      $bFound = false;
    }

    if( !$bFound ) {
      $objVideo = $this->get_vimeo_video_via_api( $video_id );
    }

    $time = $objVideo->time;
    $blog_id = get_current_blog_id();
    $sDashFolder = WP_CONTENT_DIR . '/cache/fv-player-mpd/'.$blog_id . '/';

    if( empty($objVideo->error) ) {
      // todo: delete cached vimeo data
      // todo: will backup work?

      if( file_exists($sDashFolder) ) {
        $files = glob($sDashFolder.'{,.}*', GLOB_BRACE);
        foreach($files as $file){
          if(is_file($file)) {
            @unlink($file);
          }
        }
        @rmdir($sDashFolder);
      }

      // this is the URL which stays visible in the browser, so let's adjust it a bit to make it harder.
      if( !empty($objVideo->request->files->dash) && !empty($objVideo->request->files->dash->url) ) $objVideo->request->files->dash->url = preg_replace( '~sep/video/(.*?),.*?/~', 'sep/video/$1/', $objVideo->request->files->dash->url);

    }

    if( !$bFound ) {
      update_option( $cache_key, $objVideo, false );

    }

    return $objVideo;
  }

  function get_vimeo_event( $url ) {
    if( $event_id = $this->is_vimeo_event($url) ) {
      // Load from cache
      $event = get_option( $this->get_transient_name('fv_player_vimeo_event').$event_id );
      if( $event && isset($event->time) && isset($event->ttl) && (intval($event->time) + intval($event->ttl)) > time() ) {
        $event->cache = true;

      // Not in cache?
      } else {

        // Fallback to error
        $event = new stdClass;
        $event->time = time();
        $event->ttl = 60;
        $event->error = 'Unknown error';

        $response = wp_remote_get( $url, array(
          'headers' => array( 'referer' => home_url() ),
          'sslverify' => false
        ) );

        if( !is_wp_error($response) ) {
          $body = wp_remote_retrieve_body($response);

          // Can you find the open graph video URL?
          if( stripos($body,'og:video:url') !== false ) {

            if( preg_match( '~<meta property="og:video:url" content="https://player.vimeo.com/video/(\d+)~', $body, $video_id ) ) {

              $event->time = time();
              $event->video_id = $video_id[1];
              $event->video_url = 'https://vimeo.com/'.$video_id[1];
              $event->error = false;
              $event->ttl = 120;
            }
          } else {
            $event->error = "Couldn't load the event video ID";
            $this->log_error( $url, $event->error );
          }

        } else {
          $event->error = "Couldn't load the event URL";
          $this->log_error( $url, $event->error );

        }

        // Store in cache
        update_option( $this->get_transient_name('fv_player_vimeo_event').$event_id, $event, false );
      }

      if( !empty($event->video_url) ) {
        $url = $event->video_url;
      }
    }

    return $url;
  }

  public function get_vimeo_id( $url ) {
    /*
     * Must detect:
     *
     * https://vimeo.com/737033761
     * https://player.vimeo.com/video/65107797
     * https://vimeo.com/manage/videos/737033761
     * https://vimeo.com/channels/staffpicks/65107797
     */
    if( preg_match( "~vimeo.com/(?:video/|manage/videos/|channels/[^/]+/|moogaloop\.swf\?clip_id=)?(\d+)~i", $url, $id ) ) {
      return $id[1];
    } else {
      return false;
    }
  }

  /**
   * Get 37b01a6991 out of https://vimeo.com/12345678/?h=37b01a6991 or https://vimeo.com/12345678/37b01a6991
   *
   * @param string $video_url
   * @return string|bool
   */
  public function get_password( $video_url ) {

    $parsed = wp_parse_url($video_url);
    if( !empty($parsed['query']) ) {
      parse_str( $parsed['query'], $query_args );
      if( !empty($query_args['h']) ) {
        return $query_args['h'];
      }
    }

    $video_id = $this->get_vimeo_id( $video_url );
    $has_password = explode( $video_id.'/', $video_url );
    if( count($has_password) == 2 ) {
      return $has_password[1];
    }
    return false;
  }

  public function get_vimeo_video_via_api( $video_id ) {
    $access_key = FV_Player_Pro()->get__vimeo_key();
    if( $access_key ) {
      $failures = array();

      $api_check = FV_Player_Pro_Vimeo()->admin_key_check_cache();
      if ( ! empty( $api_check['body']['scope']) && stripos( $api_check['body']['scope'], 'video_files' ) === false ) {
        $failures[] = "Vimeo access token is missing the Video Files scope.";
      }

      try {
        $result = FV_Player_Pro_Vimeo::request('/videos/'.intval($video_id).'?fields=uri,name,download,files,play,metadata,type,user' );

        if( isset($result['body']) ) {
          $vimeo_data = $result['body'];

          if ( ! empty( $api_check['body']['user']['uri'] ) && $api_check['body']['user']['uri'] != $vimeo_data['user']['uri'] ) {
            $failures = array( "This video does not belong to the website Vimeo account." );
          }

          $objVideo = new stdClass;
          $objVideo->video = new stdClass;
          if ( ! empty( $vimeo_data['uri'] ) && preg_match('~\d+~',$vimeo_data['uri'],$match) ) {
            $objVideo->video->id = $match[0];
          }
          $objVideo->video->title = ! empty( $vimeo_data['name'] ) ? $vimeo_data['name'] : '';
          $objVideo->request = new stdClass;
          $objVideo->request->files = new stdClass;
          $objVideo->request->files->hls = array();
          $objVideo->request->files->progressive = array();
          $objVideo->request->files->dash = array();

          $video_files = array();
          if ( ! empty( $vimeo_data['play']['progressive'] ) &&  is_array( $vimeo_data['play']['progressive'] ) && count($vimeo_data['play']['progressive']) > 0 ) {
            $video_files = array_merge( $video_files, $vimeo_data['play']['progressive'] );
          }

          if ( ! empty( $vimeo_data['play']['hls']['link'] ) ) {
            $vimeo_data['play']['hls']['type'] = 'application/x-mpegURL';
            $video_files = array_merge( $video_files, array( $vimeo_data['play']['hls'] ) );
          }

          if ( ! empty( $vimeo_data['play']['dash']['link'] ) ) {
            $vimeo_data['play']['dash']['type'] = 'application/dash+xml';
            $video_files = array_merge( $video_files, array( $vimeo_data['play']['dash'] ) );
          }

          if ( empty( $video_files ) && ! empty( $vimeo_data['files'] ) && is_array($vimeo_data['files']) && count($vimeo_data['files']) > 0 ) {
            $video_files = $vimeo_data['files'];
          }

          if ( empty( $video_files ) && ! empty( $vimeo_data['download'] ) && is_array($vimeo_data['download']) && count($vimeo_data['download']) > 0 ) {
            $video_files = $vimeo_data['download'];
          }

          if( $video_files ) {
            $failures = array();;
            foreach( $video_files AS $stream ) {
              if( strcmp($stream['type'],'video/mp4') == 0 && ( empty( $stream['quality'] ) || strcmp($stream['quality'],'hls') !== 0 ) ) {
                $objVideo->request->files->progressive[] = array(
                  'url' => $stream['link'],
                  'width' => $stream['width']
                );
              } else if( ! empty( $stream['quality'] ) && strcmp($stream['quality'],'hls') == 0 || strcmp($stream['type'],'application/x-mpegURL') == 0 ) {
                $objVideo->request->files->hls = array('url' => $stream['link']);
              } else if( strcmp($stream['type'],'application/dash+xml') == 0 ) {
                $objVideo->request->files->dash = array('url' => $stream['link']);
              }
            }

            $objVideo->time = time();
            $objVideo->ttl = 3600;

            if( !empty($vimeo_data['metadata']['connections']['texttracks']) && $vimeo_data['metadata']['connections']['texttracks']['total'] > 0 ) {
              $result = FV_Player_Pro_Vimeo::request( '/videos/'.intval($video_id).'/texttracks?per_page=100' );
              if( isset($result['body']) ) {
                $texttracks_data = $result['body'];

                $objVideo->request->text_tracks = array();
                if( is_array($texttracks_data['data']) ) {
                  foreach( $texttracks_data['data'] AS $subtitles ) {
                    if ( empty( $subtitles['active'] ) ) {
                      continue;
                    }

                    $lang_code = strtoupper( $subtitles['language'] );
                    $label = false;

                    if ( method_exists( 'flowplayer', 'get_languages' ) ) {
                      $languages = flowplayer::get_languages();
                      if ( ! empty( $languages [ $lang_code ] ) ) {
                        $label = $languages [ $lang_code ];
                      }
                    }

                    if ( ! $label ) {
                      $label = $subtitles['display_language'];
                    }

                    $new = new stdClass;
                    $new->lang = $subtitles['language'];
                    $new->url = $subtitles['link'];
                    $new->kind = $subtitles['type'];
                    $new->label = $label;

                    $objVideo->request->text_tracks[] = $new;
                  }
                }
              }
            }

            $result = FV_Player_Pro_Vimeo::request( '/videos/'.intval($video_id).'/chapters' );
            if( !empty($result['body']['data']) && is_array($result['body']['data']) ) {
              $chapters = array();
              foreach( $result['body']['data'] AS $k => $v ) {
                $chapter = new stdClass;
                $chapter->timecode = $v['timecode'];
                $chapter->title = $v['title'];
                $chapters[] = $chapter;
              }

              if( count($chapters) ) {
                $objVideo->chapters = $chapters;
              }
            }

          }

          // If no video files were found and it's a live stream request
          // let FV Player Vimeo Live Streaming show the iframe
          if( empty($objVideo->request->files->progressive) && empty($objVideo->request->files->hls) && !empty($vimeo_data['type']) && $vimeo_data['type'] == 'live' ) {
            return $this->get_iframe_for_fv_player_vimeo_live($video_id);
          }

          if( empty($objVideo->request->files->progressive) && empty($objVideo->request->files->hls) && empty($objVideo->request->files->dash) ) {
            $failures = array( "This video does not belong to the website Vimeo account!" );
          }

        }

      } catch (Exception $e) {
        $failures= array( "Vimeo API error: ".$e->getMessage() );
      }
    } else {
      $failures = array( "Missing Vimeo API key!" );
    }

    if ( ! empty( $failures ) ) {
      $objVideo = new stdClass;
      $objVideo->error = implode( '</br >', $failures );
      $objVideo->time = time();
      $objVideo->ttl = 120;
    }

    return $objVideo;
  }

  function get_transient_name( $type = false ) {
    if( !$type ) {
      return '';
    }
    if( strcmp($type,'vimeo') == 0 ) {
      return 'fv_player_pro_vimeo_'.substr(md5(FV_Player_Pro()->get__vimeo_key() . FV_Player_Pro()->_get_option('key')), 0, 16);
    }
    return $type.'_';
  }

  public function http( $aArgs ) {

    $aPOST = array_merge( $aArgs,
                  array(
                  'version' => FV_Player_Pro()->version,
                  'site' => apply_filters( 'fv_player_pro_http_referer', home_url() ),
                  'key' => FV_Player_Pro()->_get_option('key'),
                  'vimeo_at' => FV_Player_Pro()->get__vimeo_key()
                  )
                );

    $sPOST = '';
    foreach( $aPOST as $key=>$value) {
      $sPOST .= $key.'='.$value.'&';
    }
    $sPOST = rtrim( $sPOST, '&' );


    //  first we check the stored scores for each API
    $aServersDefault = array( 'api' => 0, 'api-us' => 0, 'api-eu' => 0 );
    $aServersCache = get_option( 'fv_player_pro_api', array() );

    if( defined('FV_PLAYER_PRO_VIMEO_API_URL') ) {
      $aServersCache = $aServersDefault;
    }

    // admin setting for server selected by default
    $defaultServer = FV_Player_Pro()->_get_option(array('pro','vimeo_location'));

    // if there is no _time key in servers list, add it there,
    // since this is our first clean run without any cache
    if (!isset($aServersCache['_time'])) {
      $aServersCache['_time'] = time();
    }

    // only check once every 24 hours or 1 hour if we have a preferred server
    $cacheClearTime = $aServersCache['_time'] + 24 * 3600;
    if ($defaultServer) {
      $cacheClearTime = $aServersCache['_time'] + 900;
    }

    if( isset($aServersCache['_time']) && $cacheClearTime < time() ) {
      $aServersCache = array();
    }

    $aServers = array_merge( $aServersDefault, $aServersCache );
    $noPredefinedServer = ($defaultServer ? false : true);

    $iServersAvailable = 0;

    $iMin = 9999;

    // if the default server is not set, set it to API, or it might not be available at all
    if( !$defaultServer || empty($aServers[$defaultServer]) ) {
      $defaultServer = 'api-us';
    }

    $sWinner = $defaultServer;
    $defaultServerAvailable = false;
    foreach( $aServers AS $sAPI => $sScore ) {
      if( $sAPI == '_time' ) continue;

      if( $iMin > $sScore ) {
        $sWinner = $sAPI;
        $iMin = $sScore;
      }

      if( $sScore < 9999 ) {
        $iServersAvailable++; //  we need to know how many servers are left for retry

        // if default server is usable, use it
        if ($sAPI === $defaultServer && !$noPredefinedServer) {
          $defaultServerAvailable = true;
        }
      }
    }

    if ($defaultServerAvailable && $sWinner !== $defaultServer) {
      $sWinner = $defaultServer;
    }
    //

    // if not API server is available
    if( $iServersAvailable == 0 && time() - $aServersCache['_time'] < 900 ) {
      // fall back to Vimeo API for 15 minutes if allowed
      if( apply_filters( 'fv_player_vimeo_fallback_enable', true) ) {
        return '';

      // otherwise just try again
      } else {
        $aServers = $aServersDefault;
        $iServersAvailable = count($aServers);
      }
    }

    if( isset($aArgs['async']) ) {
      $pSocket = @fsockopen( $sWinner.".foliovision.com", 443, $errno, $errstr, 10 );
      if( $pSocket ) {
        $strOut =  "POST / HTTP/1.1\r\n";
        $strOut .= "Host: ".$sWinner.".foliovision.com\r\n";
        $strOut .= "Content-Type: application/x-www-form-urlencoded\r\n";
        $strOut .= "Content-Length: ".strlen( $sPOST )."\r\n";
        $strOut .= "Connection: Close\r\n\r\n";
        $strOut .= $sPOST;

        fwrite( $pSocket, $strOut );
        fclose( $pSocket );
      }
      return null;

    } else {
      $tStart = microtime(true);
      $url ="https://".$sWinner.".foliovision.com/" ;
      if( defined('FV_PLAYER_PRO_VIMEO_API_URL') ) {
        $url = FV_PLAYER_PRO_VIMEO_API_URL;
      }

      $response = wp_remote_post( $url, array(
          'body' => $aPOST,
          'user-agent' => $_SERVER['HTTP_USER_AGENT'],
          'sslverify' => false,
          'timeout' => stripos($aArgs['action'],'v_grab') === 0 ? 15 : 5
        ) );

      // we need to figure out if there was an error
      // it can be a HTTP error or the API might give in some other error
      $error = false;
      $error_message = false;

      // did HTTP fail
      if( is_wp_error( $response ) ) {
        $error = true;

      } else {
        $response_body = $response['body'];

        // try to parse the response
        $aResponse = explode( '<FVSERVICES>', $response_body );
        $objVideo = false;

        // did that parsing work?
        if( isset($aResponse[1]) ) {
          $aResponse = explode( '</FVSERVICES>', $aResponse[1] );
          $objVideo = json_decode($aResponse[0]);

          // did JSON parse?
          if ( $objVideo === null && json_last_error() !== JSON_ERROR_NONE) {
            $error = true;

          // did API signal error?
          } else if( !empty($objVideo->error) ) {
            $error = true;
            $error_message = true;
          }

        } else {
          $error = true;
        }

      }

      //  remember the performance for this API server
      $tDuration = ceil( ( microtime(true) - $tStart ) * 1000 );

      // We there any soft error?
      if( $error_message && (
        stripos( $objVideo->error, "Because of its privacy settings, this video cannot be played here.") !== false ||
        stripos( $objVideo->error, "Invalid video response or private video!") !== false ||
        stripos( $objVideo->error, "This video does not exist.") !== false ||
        stripos( $objVideo->error, "The requested video couldn't be found") !== false
      ) ) {
        $error = false;

      } else if( $error ) {
        // Remember to give that API server a break
        $tDuration = 9999;
      }

      if( stripos($aArgs['action'],'v_grab') === 0 ) {
        $aServers[$sWinner] = $tDuration;
        $aServers['_time'] = time();
        update_option( 'fv_player_pro_api', $aServers );
      }

      if( $error ) {

        $this->iLoadingRecursion++;
        // We give up if the Vimeo API fallback is allowed and we tried more than once or if we do not have any server left
        if( apply_filters( 'fv_player_vimeo_fallback_enable', true) && $this->iLoadingRecursion > 1 ||
          $this->iLoadingRecursion > $iServersAvailable
        ) {
          // If we output empty string it will already say "Please reload the page and try again in a couple of minutes."
          return $error_message ? $response['body'] : '';
        } else {
          return $this->http($aArgs);
        }

      } else {
        return $response['body'];
      }

    }
  }

  public function save_chapters( $raw_chapters, $video_name ) {
    $chapters_string = "WEBVTT" . PHP_EOL;
    $chapter_id = 1;
    $chapters_src = false;

    $video_name = sanitize_title( $video_name );

    // sort by timecodes ascend
    usort( $raw_chapters, array($this, 'sort_timecodes'));

    // parse chapters to webvtt format
    foreach( $raw_chapters as $raw_chapter ) {
      $chapter_start_hms = gmdate("H:i:s", $raw_chapter->timecode) . ".000";
      $chapter_end_hms = gmdate("H:i:s", $raw_chapter->timecode + 1) . ".000";

      $chapters_string .= PHP_EOL;
      $chapters_string .= "Chapter" . $chapter_id . PHP_EOL;
      $chapters_string .= $chapter_start_hms . " --> " . $chapter_end_hms . PHP_EOL;
      $chapters_string .= $raw_chapter->title . PHP_EOL ;

      $chapter_id++;
    }

    $chapters_string = trim($chapters_string);
    $chapters_string = rtrim($chapters_string);

    // save to file
    $upload_dir = wp_upload_dir();
    $upload_path = str_replace( '/', DIRECTORY_SEPARATOR, $upload_dir['path'] ) . DIRECTORY_SEPARATOR;

    $filename = $video_name .'-chapters.vtt';

    $res = file_put_contents( $upload_path . $filename, $chapters_string );

    if ( ! $res ) {
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
    $file['tmp_name'] = $upload_path . $filename;
    $file['name']     = $filename;
    $file['type']     = 'text/vtt';
    $file['size']     = filesize( $upload_path . $filename );

    $file_return = wp_handle_sideload( $file, array( 'test_form' => false ) );

    // if no error
    if ( empty( $file_return['error'] ) ) {
      $filename = $file_return['file'];

      $attachment = array(
        'post_mime_type' => $file_return['type'],
        'post_title' => preg_replace('/\.[^.]+$/', '', basename($filename)),
        'post_content' => '',
        'post_status' => 'inherit',
        'guid' => $upload_dir['url'] . '/' . basename($filename)
      );

      $attach_id = wp_insert_attachment( $attachment, $filename, 0, true );

      if( !is_wp_error( $attach_id ) ) {
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        $attach_data = wp_generate_attachment_metadata( $attach_id, $filename );
        wp_update_attachment_metadata( $attach_id, $attach_data );

        $chapters_src = wp_get_attachment_url( $attach_id );
      }

    }

    // return url
    return $chapters_src;
  }

  function sort_timecodes( $a, $b ) {
    return $a->timecode > $b->timecode;
  }

  public static function include_sdk() {
    if( version_compare(phpversion(), '7.1.0', '<') || !function_exists('curl_init') ) {
      return false;
    }

    if( !class_exists('Vimeo\Exceptions') ) {
      require_once( FV_PLAYER_PRO_DIR.'/includes/Vimeo/Exceptions/ExceptionInterface.php');
    }
    if( !class_exists('Vimeo\Exceptions\VimeoException') ) {
      require_once( FV_PLAYER_PRO_DIR.'/includes/Vimeo/Exceptions/VimeoException.php');
    }
    if( !class_exists('Vimeo\Exceptions\VimeoRequestException') ) {
      require_once( FV_PLAYER_PRO_DIR.'/includes/Vimeo/Exceptions/VimeoRequestException.php');
    }

    if( !class_exists('Vimeo\Vimeo') ) {
      require_once( FV_PLAYER_PRO_DIR.'/includes/Vimeo/Vimeo.php');
    }

    // Avoiding PHP 5.2 lint errors
    $classname = 'Vimeo\Vimeo';
    return new $classname(false, false, FV_Player_Pro()->get__vimeo_key() );
  }

  public function is_vimeo( $url ) {
    $check = $this->get_vimeo_id($url);
    if( $check ) {
      global $fv_fp;
      $fv_fp->load_dash = true;
      return true;
    }
    return false;
  }

  public function is_vimeo_event( $url ) {
    if( stripos($url,'//vimeo.com/event/') !== false ) {
      if( preg_match( '~//vimeo.com/event/(\d+)~', $url, $event_id ) ) {
        return $event_id[1];
      }
    }
    return false;
  }

  function log_details( $msg, $data ) {
    if( FV_Player_Pro()->_get_option( array('pro', 'debug_log') ) === 'verbose' ) {

      $backtrace = array();
      $full_backtrace = debug_backtrace(2);
      if( $full_backtrace && is_array($full_backtrace) ) {
        foreach( $full_backtrace AS $trace ) {
          if( !empty($trace['function']) ) $backtrace[] = $trace['function'];
        }
      }

      $data = var_export($data,true);

      $data .= "\nBacktrace: ".implode(', ',$backtrace);

      file_put_contents( ABSPATH.'fv-player-debug-'.wp_hash('fv-player-debug-log','log').'.log', "Vimeo API action on ".date('r').$msg.$data."\n\n", FILE_APPEND );
    }
  }

  public function log_error( $id, $msg ) {
    $errors = get_option('fv_player_vimeo_errors', array() );
    if( count($errors) > 100 ) {
      array_shift($errors);
    }
    $errors[] = array( 'id' => $id, 'date'=> date("Y-m-d H:i:s"), 'error' => $msg );
    update_option('fv_player_vimeo_errors', $errors, false );
  }


  public function refresh_splash_and_durations() {

    // we can't go in without a working Vimeo API key
    $result = FV_Player_Pro_Vimeo()->admin_key_check_cache();
    if( !empty($result['error']) ) {
      ?>
      <p><?php
      _e('Your Vimeo access token appears to be invalid: ', 'fv-player-pro');
      if( !empty($result['error_code']) && $result['error_code'] == 8003 ) {
        echo "<strong>The user credentials are invalid.</strong> ";
      } else {
        echo $result['error'].' ';
      }
      _e("We cannot refresh the Vimeo splash images and durations without it.", 'fv-player-pro');
      ?> (<?php _e('last check', 'fv-player-pro'); ?>: <?php echo date('r', $result['time']); ?>)</p>
      <?php

    } else {
      echo self::refresh_splash_and_durations_db_splash_purge();

      echo self::refresh_splash_and_durations_db_duration_last_check_purge();

      echo self::refresh_splash_and_durations_options_purge();

      echo self::refresh_splash_and_durations_postmeta_purge();

      echo self::refresh_splash_and_durations_enable_cron();
    }

    echo "<p><a href='".admin_url('options-general.php?page=fvplayer')."'>Back to FV Player Settings</a></p>";
    die();
  }

  public static function refresh_splash_and_durations_enable_cron() {
    $output = '';

    global $fv_fp;
    if( method_exists($fv_fp, '_get_option') && !$fv_fp->_get_option('db_duration') ) {
      global $fv_fp;
      $aNew = $fv_fp->conf;
      $aNew['db_duration'] = true;
      $fv_fp->_set_conf( $aNew );
      $output .= "<p>Settings -> FV Player Pro -> Integrations/Compatibility -> <strong>Scan video length</strong> enabled automatically.</p>";
    }

    return $output;
  }

  public static function refresh_splash_and_durations_db_splash_purge( $silent = false ) {
    global $wpdb;

    $output = '';

    // Get count of Vimeo videos with auto splash
    if( $wpdb->get_var( "SELECT count(*) FROM {$wpdb->prefix}fv_player_videos WHERE src LIKE '%vimeo.com%' AND splash LIKE '%vimeocdn.com%'" ) == 0 ) {
      if( $silent ) {
        return false;
      }
      $output .= "<p>No automated Vimeo splash screens found!</p>";

    } else {
      // Remove Vimeo auto splash screens
      if( $res = $wpdb->query( "UPDATE {$wpdb->prefix}fv_player_videos SET splash = '' WHERE src LIKE '%vimeo.com%' AND splash LIKE '%vimeocdn.com%'" ) ) {
        $output .= "<p>".$res." automated Vimeo splash screens removed.</p>";
      } else {
        if( $silent ) {
          return -1;
        }
        $output .= "<p>Error removing automated Vimeo asplash screens!</p>";
      }
    }

    return $output;
  }

  public static function refresh_splash_and_durations_db_duration_last_check_purge( $silent = false ) {
    global $wpdb;

    $output = '';

    // Get meta rows for duration
    $duration_meta_ids = $wpdb->get_col( "SELECT m.id FROM {$wpdb->prefix}fv_player_videometa AS m JOIN {$wpdb->prefix}fv_player_videos AS v ON v.id = m.id_video WHERE src LIKE '%vimeo.com%' AND splash = '' AND meta_key = 'duration'" );

    // Get meta rows for last check date
    $last_video_meta_check_meta_ids = $wpdb->get_col( "SELECT m.id FROM {$wpdb->prefix}fv_player_videometa AS m JOIN {$wpdb->prefix}fv_player_videos AS v ON v.id = m.id_video WHERE src LIKE '%vimeo.com%' AND splash = '' AND meta_key = 'last_video_meta_check'" );

    $duration_meta_ids = array_filter( $duration_meta_ids, 'intval' );
    $last_video_meta_check_meta_ids = array_filter( $last_video_meta_check_meta_ids, 'intval' );

    if( count($last_video_meta_check_meta_ids) == 0 ) {
      if( $silent ) {
        return false;
      }
      $output .= "<p>No Vimeo video durations found!</p>";

    } else {
      // Remove durations
      if( $res = $wpdb->query( "DELETE FROM {$wpdb->prefix}fv_player_videometa WHERE id IN ( ".implode( ', ', $duration_meta_ids )." )" ) ) {
        $output .= "<p>".$res." Vimeo video durations removed.</p>";
      }  else {
        if( $silent ) {
          return -1;
        }
        $output .= "<p>Error removing Vimeo video durations!</p>";
      }

      // Remove last check date
      if( $res = $wpdb->query( "DELETE FROM {$wpdb->prefix}fv_player_videometa WHERE id IN ( ".implode( ', ', $last_video_meta_check_meta_ids )." )" ) ) {
        $output .= "<p>".$res." Vimeo video last_video_meta_check removed.</p>";
      }  else {
        if( $silent ) {
          return -1;
        }
        $output .= "<p>Error removing Vimeo video last_video_meta_check!</p>";
      }

    }

    return $output;
  }

  public static function refresh_splash_and_durations_options_purge( $silent = false ) {
    global $wpdb;

    $output = '';

    // Remove cached splash screens
    if( $wpdb->get_var( "SELECT count(*) FROM {$wpdb->options} WHERE option_name LIKE 'fv_player_vimeo_splash_%'" ) == 0 ) {
      if( $silent ) {
        return false;
      }
      $output .= "<p>No cached shortcode Vimeo splash screens found in options!</p>";

    } else {
      // Remove Vimeo auto splash screens
      if( $res = $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'fv_player_vimeo_splash_%'" ) ) {
        $output .= "<p>".$res." cached shortcode Vimeo splash screens removed from options.</p>";
      } else {
        if( $silent ) {
          return -1;
        }
        $output .= "<p>Error removing cached shortcode Vimeo splash screens screens from options!</p>";
      }
    }

    return $output;
  }

  public static function refresh_splash_and_durations_postmeta_purge( $silent = false ) {
    global $wpdb;

    $output = '';

    // Remove cached splash screens also from wp_postmeta
    if( $wpdb->get_var( "SELECT count(*) FROM {$wpdb->postmeta} WHERE meta_key LIKE '_fv_flowplayer_https-vimeo-com-%'" ) == 0 ) {
      if( $silent ) {
        return false;
      }
      $output .= "<p>No cached shortcode Vimeo splash screens found in postmeta!</p>";

    } else {
      // Remove Vimeo auto splash screens
      if( $res = $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_fv_flowplayer_https-vimeo-com-%'" ) ) {
        $output .= "<p>".$res." cached shortcode Vimeo splash screens removed from postmeta.</p>";
      } else {
        if( $silent ) {
          return -1;
        }
        $output .= "<p>Error removing cached shortcode Vimeo splash screens screens from postmeta!</p>";
      }
    }

    return $output;
  }

  public static function refresh_splash_and_durations_silent() {
    if( !FV_Player_Pro()->get__vimeo_key() || get_option('fv_player_pro_vimeo_splash_notice') ) {
      return;
    }

    $output = '<p>Vimeo has changed the splash screen URLs, we will now reset the cached data to ensure you get working Vimeo splash screens.</p>';
    $error = '<p>If you see this message it means the process has failed, please use the "Refresh splash screens and durations" button in <a href="'.admin_url( 'options-general.php?page=fvplayer#postbox-container-tab_hosting' ).'">Settings -> FV Player Pro -> Hosting</a>.</p>';

    update_option( 'fv_player_pro_vimeo_splash_notice', $output.$error, false );

    // we can't go in without a working Vimeo API key
    $result = FV_Player_Pro_Vimeo()->admin_key_check_cache();
    if( !empty($result['error']) ) {
      return;
    }

    $db_splash_purge = self::refresh_splash_and_durations_db_splash_purge(true);
    if( $db_splash_purge && $db_splash_purge != -1 ) {
      $output .= $db_splash_purge;

      $db_duration_last_check_purge = self::refresh_splash_and_durations_db_duration_last_check_purge(true);
      if( $db_duration_last_check_purge && $db_duration_last_check_purge != -1 ) {
        $output .= $db_duration_last_check_purge;
      }
    }

    $options_purge = self::refresh_splash_and_durations_options_purge(true);
    if( $options_purge && $options_purge != -1 ) {
      $output .= $options_purge;
    }

    $postmeta_purge = self::refresh_splash_and_durations_postmeta_purge(true);
    if( $postmeta_purge && $postmeta_purge != -1 ) {
      $output .= $postmeta_purge;
    }

    // Only enable background processing if there was any actual purge
    if( $db_splash_purge ) {
      $output .= self::refresh_splash_and_durations_enable_cron();
    }

    if( (!empty( $db_duration_last_check_purge ) && $db_duration_last_check_purge == -1) || $db_splash_purge == -1 || $options_purge == -1 || $postmeta_purge == -1 ) {
      $output .= $error;
    } else {
      $output .= "<p>New Vimeo splash screens will now be added in background processing which might take couple of hours.</p>";
    }

    update_option( 'fv_player_pro_vimeo_splash_notice', $output, false );
  }

  function remove_mpd_htaccess() {
    if( $this->can_vimeo_dash_manifest() ) return;

    $sDashFolder = WP_CONTENT_DIR . '/cache/fv-player-mpd/'.get_current_blog_id() . '/';
    if( file_exists($sDashFolder.'.htaccess') ){
      unlink( $sDashFolder.'.htaccess' );
    }
  }

  public static function request( $request ) {
    $hVimeo = FV_Player_Pro_Vimeo::include_sdk();
    if( !$hVimeo ) {
      throw new Exception('Could not load Vimeo PHP SDK');
    }

    if( !function_exists('curl_init') ) {
      throw new Exception('cURL PHP library missing');
    }

    // Check existing rate limitting
    if( $time = get_option( 'fv_player_pro_vimeo_ratelimitting' ) ) {
      if( $time > time() ) {
        throw new Exception('Rate limitting effective until: '.date('r', $time ) );
      } else {
        // Clear the limit
        delete_option( 'fv_player_pro_vimeo_ratelimitting' );
      }
    }

    $result = $hVimeo->request($request);

    if( !empty($result['headers']) ) {
      $headers = $result['headers'];

      // Is the rate limitting getting close?
      if( $remaining = self::request_get_header( $headers, 'X-RateLimit-Remaining') ) {
        if( intval($remaining) < 10 ) {
          $time = strtotime( self::request_get_header( $headers, 'X-RateLimit-Reset') );

          // What if there is no time or it's in the past? Can we trust Vimeo data?
          if( !$time || $time < time() ) {
            $time = time() + 3600;
          }

          update_option( 'fv_player_pro_vimeo_ratelimitting', $time, false );
        }
      }

    }

    return $result;
  }

  /*
   * Get array key case insensitive
   *
   * @param   array   $headers  Array of HTTP headers
   * @param   string  $key      Header key to look up while ignoring the case
   *
   * @return  string|bool       Header value or false
   */
  public static function request_get_header( $headers, $key ) {
    $idx = array_search( strtolower($key), array_map( "strtolower", array_keys($headers) ) );
    if($idx !== FALSE) {
      $array_values = array_values($headers);
      return $array_values[$idx];
    }

    return false;
  }
}

function FV_Player_Pro_Vimeo() {
  return FV_Player_Pro_Vimeo::_get_instance();
}

FV_Player_Pro_Vimeo();

endif;
