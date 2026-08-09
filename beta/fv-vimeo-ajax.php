<?php
//if( !isset($_POST['action']) || $_POST['action'] != 'fv_fp_get_vimeo' ) die ('¯\_(ツ)_/¯');

global $cacheDirMpd;
global $pathMatch;
global $mpd;

global $iLoadingRecursion, $pregDirSeparator;
$iLoadingRecursion = 0;
$pregDirSeparator = DIRECTORY_SEPARATOR;

// escape directory separator for regex calls
if ($pregDirSeparator == '\\') {
  $pregDirSeparator = '\\\\';
} else if ($pregDirSeparator == '/') {
  $pregDirSeparator = '\/';
}

if(!preg_match('/^.*wp-content'.$pregDirSeparator.'/', __FILE__, $pathMatch )){
  die;
}
$cacheDir = $pathMatch[0] . 'cache'.DIRECTORY_SEPARATOR.'fv-player-vimeo'.DIRECTORY_SEPARATOR;
$cacheDirMpd = $pathMatch[0] . 'cache'.DIRECTORY_SEPARATOR.'fv-player-mpd'.DIRECTORY_SEPARATOR;

if(!file_exists($cacheDir)){
  mkdir($cacheDir,0755,true);
}
if(!file_exists($cacheDir)){
  die;
}
ajax__get_vimeo();



//create cache folder

function ajax__get_vimeo() {
  @header( 'Content-Type: application/json; charset=UTF-8' );

  if ( ! empty( $_POST['sources'][0]['src'] ) ) {
    $aData = func__get_vimeo( $_POST['sources'][0]['src'] );
  }

  // todo: vimeo_security

  echo '<FVFLOWPLAYER>';
  if( is_object($aData) ) {
    echo json_encode( $aData );
  } else {
    echo json_encode( array( 'error' => 'Unknown error' ) );
  }
  echo '</FVFLOWPLAYER>';

  die();
}

/**
 * Get 37b01a6991 out of https://vimeo.com/12345678/?h=37b01a6991 or https://vimeo.com/12345678/37b01a6991
 *
 * @param string $video_url
 * @return string|bool
 */
function func__get_password( $video_url ) {

  $parsed = parse_url($video_url);
  if( !empty($parsed['query']) ) {
    parse_str( $parsed['query'], $query_args );
    if( !empty($query_args['h']) ) {
      return $query_args['h'];
    }
  }

  $video_id = func__get_vimeo_id( $video_url );
  $has_password = explode( $video_id.'/', $video_url );
  if( count($has_password) == 2 ) {
    return $has_password[1];
  }
  return false;
}

function func__get_vimeo( $video_url ) {

  $mpd = !empty($_POST['v_dash']) && $_POST['v_dash'] == 1;

  if( !$video_id = func__get_vimeo_id($video_url) ) {
    return (object)array( 'error' => 'Bad video ID!' );
  }

  $password = func__get_password($video_url);

  $settings = get_settings();

  $bFound = false;

  $objVideo = get_cache_file( 'fv_player_pro_vimeo_' . md5($video_id . '-' . $settings['key'] . '-' . $password) );
  if( empty($_GET['fv_retry_count']) && $objVideo && isset($objVideo->time) && isset($objVideo->ttl) && (intval($objVideo->time) + intval($objVideo->ttl)) > time() ) {
    $objVideo->cache = true;
    $objVideo->time_now = time();
    $bFound = true;
   // if( isset($objVideo->id) ) $sResponse = func__http( array( 'action' => 'v_ping', 'item_id' => intval($objVideo->id), 'async' => true ) );
  }

  if( !$bFound ) {

    require_once( dirname(__FILE__).'/../includes/Vimeo/Vimeo.php');

    // Avoiding PHP 5.2 lint errors
    $classname = 'Vimeo\Vimeo';
    $hVimeo = new $classname( false, false, $settings['key'] );
    $result = $hVimeo->request('/videos/'.intval($video_id).'?fields=uri,name,download,files,play,metadata' );
    if( isset($result['body']) ) {
      $vimeo_data = $result['body'];

      $objVideo = new stdClass;
      $objVideo->video = new stdClass;
      if( preg_match('~\d+~',$vimeo_data['uri'],$match) ) {
        $objVideo->video->id = $match[0];
      }
      $objVideo->video->title = $vimeo_data['name'];
      $objVideo->request = new stdClass;
      $objVideo->request->files = new stdClass;
      $objVideo->request->files->hls = array();
      $objVideo->request->files->progressive = array();
      $objVideo->request->files->dash = array();

      $failure = true;

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

      if ( empty( $video_files ) && is_array($vimeo_data['files']) && count($vimeo_data['files']) > 0 ) {
        $video_files = $vimeo_data['files'];
      }

      if ( empty( $video_files ) && is_array($vimeo_data['download']) && count($vimeo_data['download']) > 0 ) {
        $video_files = $vimeo_data['download'];
      }

      if ( ! empty( $video_files ) ) {
        $failure = false;
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
          $result = $hVimeo->request('/videos/'.intval($video_id).'/texttracks?per_page=100' );
          if( isset($result['body']) ) {
            $texttracks_data = $result['body'];

            $objVideo->request->text_tracks = array();
            if( is_array($texttracks_data['data']) ) {
              foreach( $texttracks_data['data'] AS $subtitles ) {
                if ( empty( $subtitles['active'] ) ) {
                  continue;
                }

                $new = new stdClass;
                $new->lang = $subtitles['language'];
                $new->url = $subtitles['link'];
                $new->kind = $subtitles['type'];
                $new->label = $subtitles['display_language'];

                $objVideo->request->text_tracks[] = $new;
              }
            }
          }
        }

      }

    }

    if( $failure ) {
      $objVideo = new stdClass;
      $objVideo->error = "It seems this video doesn't belong into your Vimeo account or you miss the video_files capability for your API key.";
      $objVideo->time = time();
      $objVideo->ttl = 120;
    }

  }

  $time = $objVideo->time;
  global $cacheDirMpd;
  $sDashFolder = $cacheDirMpd.'1'.DIRECTORY_SEPARATOR;

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

  if( file_exists($cacheDirMpd) ) {
    @rmdir($cacheDirMpd);
  }

  // this is the URL which stays visible in the browser, so let's adjust it a bit to make it harder.
  if( !empty($objVideo->request->files->dash) && $objVideo->request->files->dash->url ) $objVideo->request->files->dash->url = preg_replace( '~sep/video/(.*?),.*?/~', 'sep/video/$1/', $objVideo->request->files->dash->url);

  if( !$bFound ) {
    $password = func__get_password($video_url);

    if(!set_cache_file( 'fv_player_pro_vimeo_'.md5($video_id . '-' . $settings['key'] . '-' . $password), $objVideo, $video_id )){
      $objVideo = array( 'error' => 'Server cache Error' );
    }
  }

  return $objVideo;
}

function func__get_vimeo_id( $url ) {
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

function func__http( $aArgs ) {
  $settings = get_settings();
  if( $settings['domain'] ) {
    $parts = parse_url($settings['domain']);
    if( count($parts) == 1 && !empty($parts['path'])) {
      $settings['domain'] = 'https://'.$settings['domain'];
    }
  }

  $aPOST = array_merge( $aArgs,
                array(
                  'version' => '8.0.18-turbocharged',
                  'site' => $settings['domain'] ? $settings['domain'] : $_SERVER['HTTP_HOST'],
                  'key' => 'turbocharged',
                  'vimeo_at' => $settings['key'],
                )
              );

  $sPOST = '';
  foreach( $aPOST as $key=>$value) {
    $sPOST .= $key.'='.$value.'&';
  }
  rtrim( $sPOST, '&' );


  //  first we check the stored scores for each API
  $aServers = array( 'api' => 0, 'api-us' => 0, 'api-eu' => 0 );
  $aServersCache = get_cache_file( 'fv_player_pro_api' );
  if( !$aServersCache ) $aServersCache = array();

  // admin setting for server selected by default
  $defaultServer = $_POST['default_api_server'];

  // if there is no _time key in servers list, add it there,
  // since this is our first clean run without any cache
  if (!isset($aServersCache['_time'])) {
    $aServersCache['_time'] = time();
  }

  // only check once every 24 hours or 1 hour if we have a preferred server
  $cacheClearTime = $aServersCache['_time'] + 24 * 3600;
  if ($defaultServer) {
    $cacheClearTime = $aServersCache['_time'] + 3600;
  }

  if( isset($aServersCache['_time']) && $cacheClearTime < time() ) {
    $aServersCache = array();
  }

  $aServers = array_merge( $aServers, $aServersCache );
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

  // if not API server is available fall back to Vimeo API for 15 minutes
  if( $iServersAvailable == 0 && time() - $aServersCache['_time'] < 900 ) {
    return '';
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
    $hCurl = curl_init("https://".$sWinner.".foliovision.com/");
    curl_setopt($hCurl, CURLOPT_RETURNTRANSFER, true);
    //curl_setopt($hCurl, CURLOPT_SSL_VERIFYPEER, false);
    //curl_setopt($hCurl, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($hCurl, CURLOPT_TIMEOUT, 7);
    curl_setopt($hCurl, CURLOPT_POSTFIELDS, $sPOST);
    $response = curl_exec($hCurl);


    //  store what you measured, but only if it's testing phase or there was an error!
    $tDuration = curl_errno( $hCurl ) ? 9999 : ceil( ( microtime( true ) - $tStart ) * 1000 );

    global $iLoadingRecursion;
    $aServers[$sWinner] = $tDuration;
    $aServers['_time'] = time();
    set_cache_file( 'fv_player_pro_api', $aServers );
    //

    // we need to figure out if there was an error
    // it can be a HTTP error or the API might give in some other error
    $error = false;
    $error_message = false;

    if(curl_errno($hCurl)){
      $error = true;

    } else {
      $response_body = $response;

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

    if( $error ) {
      $iLoadingRecursion++;
      if( $iLoadingRecursion > 1 || $iLoadingRecursion > $iServersAvailable ) {
        // If we output empty string it will already say "Please reload the page and try again in a couple of minutes."
        return $error_message ? $response : '';
      } else {
        return func__http($aArgs);
      }

    }
    curl_close($hCurl);
  }

  return $response;
}

function get_cache_file($filename){
  global $cacheDir;

  if (!file_exists($cacheDir.$filename)) {
    return array();
  }

  $contents  = '';
  $file = fopen($cacheDir.$filename,"r");

  if (flock($file,LOCK_SH))
  {
    $contents = file_get_contents($cacheDir.$filename);
    if ($contents) {
      $contents = unserialize($contents);
    } else {
      $contents = array();
    }
    flock($file,LOCK_UN);
  }else{
    //not in cache
    //echo json_encode( array( 'error' => 'File lock read error' ) );
  }

  if( $file ) fclose($file);

  return $contents;
}

function set_cache_file($filename,$data,$video_id = null){
  global $cacheDir;
  $file = fopen($cacheDir.$filename,"w+");
  if (flock($file,LOCK_EX))
  {
    fwrite($file,serialize($data));
    flock($file,LOCK_UN);
  }else{
    return false;
  }
  fclose($file);

  $htaccess = $cacheDir.'.htaccess';
  if( !file_exists($htaccess) ) {
    $file = fopen($htaccess,"w+");
    if (flock($file,LOCK_EX)) {
      fwrite($file,'deny from all');
      flock($file,LOCK_UN);
    }
    fclose($file);
  }
  return true;
}



function plugins_url(){
  $ulr = $_SERVER['HTTP_ORIGIN'].$_SERVER['REQUEST_URI'];

  preg_match('/.*wp-content/',$ulr,$result);

  return $result[0] . '/cache/';
}

function get_settings(){
  global $pathMatch, $pregDirSeparator;
  static $settings = null;

  if ($settings !== null) {
    return $settings;
  }

  $dir = str_replace('wp-content'.DIRECTORY_SEPARATOR,'',$pathMatch[0]);
  if(!file_exists($dir.'wp-config.php')){
    $dir = preg_replace('/'.$pregDirSeparator.'[^'.$pregDirSeparator.']*$/','',$dir);
    if(!file_exists($dir.'wp-config.php'))
      die;
  }
  $confg = file_get_contents($dir.'wp-config.php');

  // Remove commented code
  $confg = preg_replace( '~/\*.*?\*/~s', '', $confg );
  $confg = preg_replace( '~\s*?//.+~', '', $confg );

  if(!preg_match('/define.*FV_PLAYER_VIMEO_KEY[^;]*;/',$confg,$matches)){
    die;
  };
  eval ($matches[0]);

  if( preg_match('/define.*FV_PLAYER_VIMEO_LOCATION[^;]*;/',$confg,$matches) ){
    eval ($matches[0]);
  };

  if( preg_match('/define.*FV_PLAYER_VIMEO_DOMAIN[^;]*;/',$confg,$matches) ){
    eval ($matches[0]);
  };

  $settings = array(
    'key' => defined('FV_PLAYER_VIMEO_KEY') ? FV_PLAYER_VIMEO_KEY : false,
    'region' => defined('FV_PLAYER_VIMEO_LOCATION') ? FV_PLAYER_VIMEO_LOCATION : false,
    'domain' => defined('FV_PLAYER_VIMEO_DOMAIN') ? FV_PLAYER_VIMEO_DOMAIN : false,
  );
  return $settings;
}
