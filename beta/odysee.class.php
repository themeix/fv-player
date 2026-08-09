<?php

if( !class_exists('FV_Player_Pro_Odysee') ) :

class FV_Player_Pro_Odysee extends FV_Player_Pro_Ajax_Loader {

  function __construct() {
    $this->aDomains      = array( 'https://odysee.com/' );

    $this->aSecureTokens = array( 'override' );

    add_filter( 'fv_flowplayer_get_mime_type', array( $this, 'set_file_type' ), 10 , 2 );

    add_filter('fv_player_meta_data', array( $this, 'fetch_odysee_data' ), 10, 2); // splash, caption

    add_filter( 'fv_player_video_checker_skip', array( $this, 'skip_video_checker'), 10, 2 ); // takes too long to load page if not skipped

    add_filter( 'the_content', array( $this, 'handle_odysee_links' ) );

    add_action( 'wp_ajax_fv_fp_get_odysee_video_url', array( $this, 'store_broken_videos' ) );
    add_action( 'wp_ajax_nopriv_fv_fp_get_odysee_video_url', array( $this, 'store_broken_videos' ) );

    parent::__construct( array( 'key' => 'odysee', 'title' => 'Odysee') );
  }

  function args($args) {
    $args[] = 'verify';
    return $args;
  }

  function secure_link( $url, $securityKey, $ttl = false ) {
    $video_id = $this->get_video_id($url);
    $new_cache = false;

    if( !$video_id ) {
      return $url;
    }

    if ( $cached_url = $this->load_cache( $video_id ) ) {
      return $cached_url;
    }

    // we need to decode url otherwise request will fail
    $url_decoded = urldecode( $url );

    if( $this->is_live ) {
      $live_data = $this->get_live_data($url_decoded);

      if( is_array($live_data ) ) {
        if($live_data['Live']) {
          return $this->store_cache( $video_id, $live_data['VideoURL'] );
        }
      }
    }

    $api_url = str_replace( 'https://odysee.com/', 'lbry://', $url_decoded );

    // get streaming url
    $data = array(
      'jsonrpc' => '2.0',
      'method' => 'get',
      'params' => array(
        'uri' => $api_url,
        'save_file' => false
      )
    );

    // get streaming url, example: https://player.odycdn.com/api/v4/streams/free/unreal-engine-5-games-look-even-better/ed8c48fee27eaeac5cd18e6733e2436ad9c21632/95da7c
    $response = wp_remote_post( 'https://api.na-backend.odysee.com/api/v1/proxy?m=get', array(
      'body'    => json_encode($data),
      'headers' => array(
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; rv:112.0) Gecko/20100101 Firefox/112.0',
        'Host' => 'api.na-backend.odysee.com',
        'Content-Type' => 'application/json-rpc',
        'Referer' => 'https://odysee.com/',
        'Origin' => 'https://odysee.com',
        'Pragma' => 'no-cache',
      ),
    ));

    if( !is_wp_error($response) ) {
      $body = wp_remote_retrieve_body( $response );

      $body = json_decode( $body, true );

      /**
       * If we got an error then try use the video URL without decoding.
       * It seems this happens if the video URL has + in it.
       */
      if ( ! empty( $body['error']['message'] ) ) {
        $api_url = str_replace( 'https://odysee.com/', 'lbry://', $url );

        $data = array(
          'jsonrpc' => '2.0',
          'method' => 'get',
          'params' => array(
            'uri'       => $api_url,
            'save_file' => false
          )
        );

        $response = wp_remote_post( 'https://api.na-backend.odysee.com/api/v1/proxy?m=get', array(
          'body'    => json_encode($data),
          'headers' => array(
            'Content-Type' => 'application/json-rpc'
          ),
        ));

        if( !is_wp_error($response) ) {
          $body = wp_remote_retrieve_body( $response );
    
          $body = json_decode( $body, true );
        }
      }

      if( isset( $body['result']['streaming_url'] ) ) {
        $new_cache = $body['result']['streaming_url'];
        $m3u8_found = false;

        // check resolve endpoint
        $data = array(
          'jsonrpc' => '2.0',
          'method' => 'resolve',
          'params' => array(
            'urls' => array(
              $api_url // example: lbry://@coreteks#5/unreal-engine-5-games-look-even-better#e
            )
          )
        );

        // try to get resolve response to get sd_hash
        $resolve_response = wp_remote_post( 'https://api.na-backend.odysee.com/api/v1/proxy?m=resolve', array(
          'body'    => json_encode($data),
          'headers' => array(
            'Accept' => '*/*',
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; rv:112.0) Gecko/20100101 Firefox/112.0',
            'Host' => 'api.na-backend.odysee.com',
            'Content-Type' => 'application/json-rpc',
            'Referer' => 'https://odysee.com/',
            'Origin' => 'https://odysee.com',
            'Pragma' => 'no-cache',
          ),
        ));

        if( !is_wp_error($resolve_response) ) {
          $resolve_body = wp_remote_retrieve_body( $resolve_response );

          $resolve_body = json_decode( $resolve_body, true );

          // try to get master.m3u8 - we need to modidy streaming_url to get it
          // original :
          // https://player.odycdn.com/api/v4/streams/free/unreal-engine-5-games-look-even-better/ed8c48fee27eaeac5cd18e6733e2436ad9c21632/95da7c
          // modified :
          // https://player.odycdn.com/api/v4/streams/tc/unreal-engine-5-games-look-even-better/ed8c48fee27eaeac5cd18e6733e2436ad9c21632/95da7c60e0f045f9e997990ed228ae6e025d4edc55dcbdbbcbd1bab69b43ab96b6aa5b11b3cc1980c320615c4c09e969/master.m3u8
          if( isset( $resolve_body['result'][$api_url]['value']['source']['sd_hash'] ) ) {
            $sd_hash = $resolve_body['result'][$api_url]['value']['source']['sd_hash'] . '/master.m3u8';

            // replace /free/ to /tc/ in new_cache
            $m3u8_url = str_replace( '/free/', '/tc/', $new_cache );

            // remove last 6 characters from new_cache
            $m3u8_url = substr( $m3u8_url, 0, -6 );

            // add sd_hash to new_cache
            $m3u8_url .= $sd_hash;

            $m3u8_found = true;

            // try to get master.m3u8
            $response = wp_remote_get($m3u8_url, array(
              'headers' => array(
                'Accept' => '*/*',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; rv:112.0) Gecko/20100101 Firefox/112.0',
                'Host' => 'player.odycdn.com',
                'Referer' => 'https://odysee.com/',
                'Origin' => 'https://odysee.com',
                'Pragma' => 'no-cache',
              )
            ));

            // check if m3u8 file is valid
            if( !is_wp_error($response) ) {
              $body = wp_remote_retrieve_body( $response );

              if( stripos($body,'#EXTM3U') !== false ) {
                $new_cache = $m3u8_url;
              } else {
                $m3u8_found = false;
              }
            }

          }

        }

        // fallback to parsing m3u8 from streaming_url
        if( !$m3u8_found ) {
          // Check if opening the URL gives you a HLS stream, it uses redirection
          // This can sometimes get a big MP4 file, so we only check a bit of it
          $response = wp_remote_get( $new_cache, array(
            'headers' => array(
              'Accept' => '*/*',
              'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; rv:112.0) Gecko/20100101 Firefox/112.0',
              'Host' => 'player.odycdn.com',
              'Range' => 'bytes=0-65536',
              'Referer' => 'https://odysee.com/',
              'Origin' => 'https://odysee.com',
              'Pragma' => 'no-cache',
            )
          ) );

          // try to parse m3u8 url from response
          if( !is_wp_error($response) && !empty($response['http_response']) && method_exists( $response['http_response'], 'get_response_object' ) ) {
            $obj = $response['http_response']->get_response_object();

            if( !empty($obj->url) && stripos($obj->url,'.m3u8') !== false ) {
              $new_cache = $obj->url; // m3u8 url found
            } else {
              // fallback to open graph mp4
              $response = wp_remote_get( $url );

              if( !is_wp_error($response) ) {
                $body = wp_remote_retrieve_body( $response );

                // match contentUrl link in application/ld+json script tag
                preg_match('~"contentUrl": "(.*?)"~', $body, $matches);

                if( isset($matches[1]) ) {
                  $new_cache = esc_url_raw($matches[1]);
                }
              }
            }
          }
        }

      } else { // no streaming_url found
        $_POST['error'] = 'Unable to parse the Odysee video.';
      }
    }

    return $this->store_cache( $video_id, $new_cache );
  }

  function set_file_type( $type ) {
    $args = func_get_args();
    if( isset($args[1]) ) {
       if( $this->get_video_id($args[1]) ) {
        $type = "video/mp4";
      }
    }

    return $type;
  }

  function fetch_odysee_data($url, $post_id = false) {
    if( !$this->get_video_id($url) ) {
      return $url;
    }

    $response = wp_remote_get( $url );
    $videoData = false;

    if( !is_wp_error($response) ) {
      $body = wp_remote_retrieve_body( $response );

      preg_match('~<meta property="og:video:duration" content="(.*?)"/>~', $body, $duration); // match duration in meta tag
      preg_match('~<meta property="og:image" content="(.*?)"/>~', $body, $splash); // match splash in meta, maybe add #.jpg to link ?
      preg_match('~<title>(.*?)</title>~', $body, $caption); // match caption in title

      if( isset($duration[1]) || isset($splash[1]) || isset($caption[1]) ) {
        $videoData = array();
        if( isset($duration[1]) ) {
          $videoData['duration'] = intval($duration[1]);
        }

        if( isset($splash[1]) ) {
          $videoData['thumbnail'] = esc_url(html_entity_decode($splash[1]));
        }

        if( isset($caption[1]) ) {
          $videoData['name'] = $caption[1];
        }
      }
    }

    $live_data = $this->get_live_data($url);

    if( is_array($live_data) ) {
      if( $live_data['Live'] ) {
        $videoData['is_live'] = true;
      }
    }

    return $videoData;
  }

  function get_live_data($url) {
    // check if live stream
    $data = array(
      'jsonrpc' => '2.0',
      'method' => 'resolve',
      'params' => array(
        'urls' => array( 'lbry://' . $this->get_video_id($url) ),
      )
    );

    $response = wp_remote_post( 'https://api.na-backend.odysee.com/api/v1/proxy?m=resolve', array(
      'body'    => json_encode($data),
      'headers' => array(
        'Content-Type' => 'text/plain; charset=utf-8'
      ),
    ));

    if( !is_wp_error($response) ) {
      $body = wp_remote_retrieve_body( $response );

      $body = json_decode( $body, true );

      if( isset($body['result']['lbry://' . $this->get_video_id($url)]['signing_channel']['claim_id']) ) {
        $claim_id = $body['result']['lbry://' . $this->get_video_id($url)]['signing_channel']['claim_id'];

        $get_url = esc_url_raw('https://api.odysee.live/livestream/is_live?channel_claim_id=' . $claim_id );

        $response = wp_remote_get($get_url);

        if( !is_wp_error($response) ) {
          $body = json_decode($response['body'], true);

          if( isset($body['data']['Live']) ) {
            return $body['data'];
          }
        }
      }
    }

    return false;
  }

  function skip_video_checker( $skip, $media ) {
    if( $this->get_video_id($media) ) {
      $skip = true;
    }

    return $skip;
  }

  /**
   * Return video id from link
   *
   * @param mixed $url
   *
   * @return string|bool if matched id then returns it, otherwise returns false
   */
  function get_video_id($url) {
    if( is_string($url) && stripos($url,'https://odysee.com') !== false ) {
      // match id without user https://odysee.com/Halloween.Kills.2021.1080p.WEBRip:f --> Halloween.Kills.2021.1080p.WEBRip:f
      // or with user https://odysee.com/@Destiny:6/trump-promises-to-take-back-everything:0 --> @Destiny:6/trump-promises-to-take-back-everything:0
      if( preg_match('~\.com/(@?.*:.*$)~', $url, $matches) ) {
        return $matches[1];
      }
    }

    return false;
  }

  function handle_odysee_iframes( $match ) {
    if( preg_match( '~src=[\'"](.*?)[\'"]~', $match[0], $src ) ) {

      /**
       * Change "https://odysee.com/%24/embed/%40sayatarucreation%3A0%2Fhow-to-draw-landscape-with-pencil%3A8#?secret=FvN5f2czd4" to "https://odysee.com/@sayatarucreation:0/how-to-draw-landscape-with-pencil:8"
       */
      $actual_src = urldecode( $src[1] );
      $actual_src = str_replace( '/$/embed/', '/', $actual_src );

      // Remove ?query_string part of the URL
      $src_query = wp_parse_url( $actual_src, PHP_URL_QUERY );
      if ( $src_query ) {
        $actual_src = str_replace( '?' . $src_query, '', $actual_src );
      }

      // Remove #frament part of the URL
      $src_fragment = wp_parse_url( $actual_src, PHP_URL_FRAGMENT );
      if ( $src_fragment ) {
        $actual_src = str_replace( '#' . $src_fragment, '', $actual_src );
      }

      $odysee_url = $this->get_video_id( $actual_src );
      if ( $odysee_url ) {
        return '[fvplayer src="https://odysee.com/' . $odysee_url . '"]<!-- link converted by FV Player  -->';
      }
    }
    return $match[0];
  }

  function handle_odysee_links( $post_content ) {
    global $fv_fp;

    if ( is_object( $fv_fp ) && method_exists( $fv_fp, '_get_option' ) && $fv_fp->_get_option( array( 'integrations', 'wp_core_video' ) ) ) {
      $post_content = preg_replace_callback( '~<iframe[^>]*?odysee.com/[^>]*?></iframe>~', array( $this, 'handle_odysee_iframes' ), $post_content );
    }

    return $post_content;
  }

  // Remember failing videos
  function store_broken_videos() {
    $video_id = $this->get_video_id( $_POST['src'] );
    if( !$video_id ) {
      return;
    }

    $broken_videos = get_option( 'fv_player_pro_odysee_broken_videos', array() );
    $broken_videos[ $video_id ] = time();
    update_option( 'fv_player_pro_odysee_broken_videos', $broken_videos, false ); 
  }

}

global $FV_Player_Pro_Odysee;
$FV_Player_Pro_Odysee = new FV_Player_Pro_Odysee;

endif;
