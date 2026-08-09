<?php

if( !class_exists('FV_Player_Pro_Peertube_Private_Browser') && class_exists('FV_Player_Media_Browser') ) :

class FV_Player_Pro_Peertube_Private_Browser extends FV_Player_Media_Browser {

  function init() {
    if( $this->isSetUpCorrectly() ) {
      wp_enqueue_script( 'fv-player-peertube-private-browser', plugins_url( 'js/peertube-private-browser.js', __FILE__ ), array( 'flowplayer-browser-base' ), filemtime( dirname( __FILE__ ) . '/js/peertube-private-browser.js' ) );

      $options = get_option('fv_player_peertube_private');
      wp_localize_script( 'fv-player-peertube-private-browser', 'fv_player_peertube_private', array(
        'tab_name' => !empty( $options['peertube_private_url'] ) ? wp_parse_url( $options['peertube_private_url'], PHP_URL_HOST ) : false
      ) );
    }
  }

  function init_for_gutenberg() {}

  function get_formatted_assets_data() {
    $search = !empty($_POST['search']) ? sanitize_text_field( trim( $_POST['search'] ) ) : false;

    $output = array(
      'name' => 'Home',
      'type' => 'folder',
      'path' => 'Home/',
      'items' => array()
    );

    try {
      $options = get_option('fv_player_peertube_private', array());

      $url = $options['peertube_private_url'];
      $access_token = $options['peertube_private_access_token'];

      $api_url = '/api/v1/videos';

      if ( !empty($_POST['search']) ) {
        $api_url = '/api/v1/search/videos';

        $api_url = add_query_arg( 'search', sanitize_text_field( trim( $_POST['search'] ) ), $api_url );
      }

      $api_url = $url . $api_url;

      $limit   = 100;
      $api_url = add_query_arg( 'count', $limit, $api_url );
      $page    = ! empty( $_POST['page'] ) ? intval( $_POST['page'] ) : 1;
      $api_url = add_query_arg( 'start', ( $page - 1 ) * $limit, $api_url );

      //$access_token = '3796734dab74fa02630d29627586352fbb123524';

      $args = array(
        'headers' => array(
          'Authorization' => 'Bearer ' . $access_token,
        ),
        'timeout' => 20,
      );

      $response = wp_remote_get( $api_url, $args );

      if( is_wp_error( $response ) ) {
        throw new Exception( $response->get_error_message() );
      }

      $http_body = wp_remote_retrieve_body( $response );

      $videos_data = json_decode( $http_body, true );

      foreach( $videos_data['data'] as $video ) {
        // ignore internal
        if( $video['privacy']['id'] == 4 ) continue;

        // search
        if( !empty( $search ) ) {
          if( stripos( $video['name'], $search ) === false ) continue; // skip if not found
        }

        // add video to output
        $item = array(
          'name' => $video['name'],
          'type' => 'file',
          'path' => 'Home/' . $video['name'],
          'link' => $url . '/w/' . $video['uuid'],
          'modified' => $this->convertTime($video['updatedAt']),
          'splash' => !empty($video['previewPath']) ? $url . $video['previewPath'] : $url . $video['thumbnailPath'],
        );

        // check if video is still processing
        if( isset($video['state']) && $video['state']['id'] == 2 ) {
          $item['extra'] = array(
            'encoding_job_status' => 'processing',
            'percentage' => '0%', // no percentage available so just add 0%
            'displayData' => __('This file is currently being processed by Peertube.', 'fv-player-pro')
          );
        }

        $output['items'][] = $item;

      }

      $output['is_last_page'] =  $videos_data['total'] <= $limit || $videos_data['total'] < $limit * $page;

    } catch( Exception $e ) {
      $err = $e->getMessage();
      $output = array(
        'items' => array(),
        'name' => '/',
        'path' => '/',
        'type' => 'folder'
      );
    }

    $json_final = array(
      'items' => $output
    );

    if (isset($err) && $err) {
      $json_final['err'] = $err;
    }

    return $json_final;
  }

 /**
  * Converts date like `2021-05-04T08:01:01.502Z` to `2021-05-04 08:01:01`
  *
  * @param string $time
  *
  * @return string
  */
  function convertTime($time) {
    $time = str_replace('T', ' ', $time);
    $time = str_replace('Z', '', $time);
    $time = substr($time, 0, -4); // remove last 4 chars (miliseconds)
    return $time;
  }

  public function isSetUpCorrectly() {
    $options = get_option('fv_player_peertube_private', array());

    if( !empty($options) &&
        !empty($options['peertube_private_url']) &&
        !empty($options['peertube_private_client_id']) &&
        !empty($options['peertube_private_client_secret']) &&
        !empty($options['peertube_private_username']) &&
        !empty($options['peertube_private_password']) &&
        !empty($options['peertube_private_access_token']) &&
        !empty($options['peertube_private_refresh_token'])
      ) {
      return true;
    }

    return false;
  }

}

new FV_Player_Pro_Peertube_Private_Browser( 'wp_ajax_load_peertube_private_assets' );

endif;
