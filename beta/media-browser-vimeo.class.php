<?php
if( !class_exists('FV_Player_Media_Browser_Vimeo') && class_exists('FV_Player_Media_Browser') ) :

class FV_Player_Media_Browser_Vimeo extends FV_Player_Media_Browser {

  function init() {
    if ( FV_Player_Pro()->get__vimeo_key() ) {
      wp_enqueue_script('fv-player-vimeo-browser', plugins_url('js/vimeo.browser.js',__FILE__), array('flowplayer-browser-base'), FV_Player_Pro()->version );
    }
  }

  // Legacy
  function init_for_gutenberg() {}

  function get_formatted_assets_data() {
    global $FV_Player_Pro, $fv_wp_flowplayer_ver;

    $debug = array();
    $i = 0;
    $done = false;
    $video_data_is_last_page = 1;

    // check version requirements
    if (version_compare( str_replace( '.beta','', $fv_wp_flowplayer_ver ),'7.3.16.727', '<')) {
      return array(
        'active_album_link' => - 1,
        'items'             => $debug,
        'debug'             => $debug,
        'err'               => __( 'Your FV Player version (' . $fv_wp_flowplayer_ver . ') is too old to work with this feature. Please update to at least FV Player 7.3.16.727', 'fv-wordpress-flowplayer' )
      );
    }

    if( function_exists('curl_init') ) {
      try {
        $api_url = (!empty($_POST['album']) ? '/me'.$_POST['album'] : '/me/videos');
  
        // sometimes, Vimeo API fails on the first try... so, give it at least 2
        while ($i < 2 && !$done) {
          $result = FV_Player_Pro_Vimeo::request($api_url.'?fields=name,link,duration,pictures.sizes,modified_time,uri,status&page='.(!empty($_POST['page']) && is_numeric($_POST['page']) && (int) $_POST['page'] == $_POST['page'] ? $_POST['page'] : 1).'&per_page=50' . (!empty($_POST['search']) ? '&weak_search=true&query='.$_POST['search'] : ''));
  
          if (defined('WP_DEBUG') && WP_DEBUG === true) {
            $debug[] = array(
              'try'    => $i,
              'time'   => time(),
              'result' => $result,
            );
          }
  
          if( isset($result['body']) ) {
            $result = $result['body'];
            $result['time'] = time();
            $video_data_is_last_page = (empty($result['paging']['next']) ? 1 : 0);
            $done = true;
          } else {
            $result['error'] = 'No results were returned from the Vimeo API. Please try again and if this error persists, check your Vimeo API key in your FV Player settings.';
            $video_data_is_last_page = 1;
          }
          $i++;
        }
      } catch( Exception $e ) {
        $result['error'] = 'Vimeo API Error: '.$e->getMessage();
      }
    } else {
      $result['error'] = 'cURL PHP library missing!';
    }

    // prepare base folder
    $body = array();
    $body['name'] = 'Home';
    $body['path'] = 'Home/';
    $body['type'] = 'folder';
    $body['items'] = array();

    // prepare result for browser
    if ($done && !isset($result['error'])) {
      $date_format = get_option( 'date_format' );
      foreach ($result['data'] as $video) {
        // don't list errors and unavailable videos
        if ($video['status'] != 'available') {
          continue;
        }

        $item = array(
          'link' => $video['link'],
          'name' => $video['name'],
          'size' => '',
          'type' => 'file',
          'path' => 'Home/' . $video['name'],
          'size' => /*$video['files'][0]['size']*/-1,
          'duration' => $video['duration'],
          'modified' => date($date_format, strtotime($video['modified_time'])),
          /*'width' => $video['width'],
          'height' => $video['height'],*/
          'splash' => $video['pictures']['sizes'][1]['link']
        );

        if (preg_match('/\.(jpg|jpeg|png|gif)$/i', $item['name'])) {
          $item['splash'] = apply_filters('fv_flowplayer_splash', $video['link'] );
        }

        // Accept video links like https://vimeo.com/123456789
        // or https://vimeo.com/123456789/c842cd1ca3 (video password)
        if(
          preg_match( '~https://vimeo.com/[0-9]+~', $item['link'] ) ||
          preg_match( '~https://vimeo.com/[0-9]+/[0-9a-z]+~', $item['link'] )
        ) {

        // Otherwise do some replacements
        } else {
          // check for aliased links and replace them by numeric ones
          $splitted = explode('/', str_replace(array('https://', 'http://'), '', $item['link']));
          if (!is_numeric($splitted[count($splitted) - 1])) {
            $id = explode('/', $video['uri']);
            $item['link'] = 'https://' . $splitted[0] . '/' . $id[count($id) - 1];
          }
        }

        $body['items'][] = $item;
      }
    }

    if ($_POST['firstLoad'] == 1) {
      // get list of albums for the dropdown menu
      $i    = 0;
      $done = false;
      // it's called buckets to ensure compatibility with JS code, which was originally built for AWS
      $albums = array();
      try {
        // sometimes, Vimeo API fails on the first try... so, give it at least 2
        while ( $i < 2 && ! $done ) {
          $result = FV_Player_Pro_Vimeo::request( '/me/albums?fields=name,metadata.connections.videos.uri' );

          if ( defined( 'WP_DEBUG' ) && WP_DEBUG === true ) {
            $debug[] = array(
              'try'    => $i,
              'time'   => time(),
              'result' => $result,
            );
          }

          if ( isset( $result['body'] ) ) {
            $result         = $result['body'];
            $result['time'] = time();
            $done           = true;
          } else {
            $result['error'] = 'No results were returned from the Vimeo API. Please try again and if this error persists, check your Vimeo API key in your FV Player settings.';
          }
          $i ++;
        }
      } catch ( Exception $e ) {
        $result['error'] = 'Vimeo API Error: ' . $e->getMessage();
      }

      // prepare result for browser
      if ( $done && ! isset( $result['error'] ) ) {
        foreach ( $result['data'] as $album ) {
          $item = array(
            'link' => $album['metadata']['connections']['videos']['uri'],
            'name' => $album['name']
          );

          $albums[ $album['name'] ] = $item;
        }
      }

      // sort albums by name
      ksort( $albums, SORT_NATURAL | SORT_FLAG_CASE );
    }

    $json_final = array(
      'active_album_link' => (!empty($_POST['album']) ? $_POST['album'] : -1),
      'items' => $body,
      'debug' => $debug,
      'is_last_page' => $video_data_is_last_page,
    );

    if ($_POST['firstLoad'] == 1) {
      $json_final['albums'] = $albums;
    }

    if (!empty($result['error'])) {
      $json_final['err'] = $result['error'];
    }

    return $json_final;
  }

}

new FV_Player_Media_Browser_Vimeo('wp_ajax_load_vimeo_assets');

endif;
