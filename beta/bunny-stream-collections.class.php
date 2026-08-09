<?php

if( !class_exists('FV_Player_Pro_Bunny_Stream_Collections') ) :

class FV_Player_Pro_Bunny_Stream_Collections {

  function __construct() {
    add_filter( 'fv_flowplayer_args_pre', array( $this, 'bunny_stream_collections' ) );
    add_filter( 'fv_flowplayer_caption_src', array( $this, 'collections_caption' ), 12, 3 );
    add_action( 'fv_player_pro_update_bunny_stream_collections_cache', array( $this, 'update_cache' ) );
  }

  /**
   * Replace attributes with Bunny Stream Collection data
   *
   * @param array $attrs
   *
   * @return array
   */
  public function bunny_stream_collections( $attrs ) {
    global $fv_fp;

    $attrs_original = $attrs;
    $objVideDBinstance = false;

    // load player video src from database, if ID present
    if (!empty($attrs['id']) && is_numeric($attrs['id'])) {
      if (!$fv_fp->current_player() || $fv_fp->current_player()->getId() != $attrs['id']) {
        $pl = new FV_Player_Db_Player($attrs['id']);
      } else {
        $pl = $fv_fp->current_player();
      }

      // get videos from playlist
      $vids = $pl->getVideos();

      if( $vids && !empty($vids[0]) ) {
        $objVideDBinstance = $vids[0]; // get first video
        $attrs = array_merge($attrs, $objVideDBinstance->getAllDataValues());
        unset($attrs['id']); // remove id attribute
      }
    }

    if ( empty( $attrs['src'] ) ) {
      return $attrs;
    }

    $collection_data = $this->is_collection($attrs['src']);

    // check if src is Bunny Stream Collection
    if( !$collection_data ) {
      return $attrs_original;
    }

    // get option name for Bunny Stream Collection cache
    $option = 'fv_player_pro_cloudflare_collection_cache_' . md5($attrs['src']);

    $item = $collection_data['collection_id'];
    $log = "FV Player Pro - Cloudlflare Stream Collection $item";
    $debug = in_the_loop() && FV_Player_Pro()->is_option_enabled('vimeo_debug');

    // Load cached channel (playlist) from FV Player DB
    if( is_object($objVideDBinstance) ) {
      $objCache = $objVideDBinstance->getMetaValue('playlist_data');

      if( !empty($objCache) ) {
        $objCache = unserialize ($objCache[0]); // cache is serialized

        if( isset($objCache->shortcode) ) {
          if( !empty($attrs['splash']) ) { // If custom splash is set then use it
            $objCache->shortcode['splash'] = $attrs['splash'];
          }

          $attrs = wp_parse_args( $objCache->shortcode, $attrs );
        }
      }

    // Load cached channel (playlist) from wp_options
    } else {
      $objCache = get_option( $option );
    }

    if( !$objCache ) $objCache = new stdClass;

    // Have cache
    if( $objCache && isset($objCache->date) && $objCache->date + 900 > time() ) {
      if( $debug ){
        if( is_object($objVideDBinstance) ) {
          echo "<!-- $log from meta cache ".$objCache->date." saved ".$objCache->duration."-->\n";
        } else {
          echo "<!-- $log from options cache ".$objCache->date." saved ".$objCache->duration."-->\n";
        }
      }

    // Cache not there or too old
    } else {
      $objCache = $this->get_bunny_stream_cache_http( $item, $log, $debug, $objCache );

      // Store cache in FV Player DB (video meta)
      if( is_object($objVideDBinstance) ) {
        $objVideDBinstance->updateMetaValue( "playlist_data", serialize($objCache) );
        $objVideDBinstance->updateMetaValue( "playlist_last_check_date", $objCache->date );
      } else { // ...or in wp_options
        update_option( $option, $objCache, false );
      }
    }

    if( isset($objCache->shortcode) ) {
      if( !empty($attrs['splash']) ) { // If custom splash is set then use it
        $objCache->shortcode['splash'] = $attrs['splash'];
      }
      $attrs = wp_parse_args( $objCache->shortcode, $attrs );
    }

    return $attrs;
  }

  /**
   * Get videos from Bunny Stream Collection
   *
   * @param int $collection_id
   *
   * @return array|bool
   */
  public function get_collection_streams( $collection_id ) {
    global $fv_fp;

    if( !class_exists('FV_Player_Pro_Bunny_Stream_API') ) {
      require_once( dirname(__FILE__) . '/class.fv-player-bunny_stream-api.php' );
    }

    $api = new FV_Player_Pro_Bunny_Stream_API();

    $query_string = array(
      'page' => 1,
      'itemsPerPage' => 50,
      'orderBy' => 'date',
      'collection' => $collection_id
    );

    $endpoint = add_query_arg(
      $query_string,
      'https://video.bunnycdn.com/library/' . $fv_fp->_get_option( array('bunny_stream','lib_id') ) . '/videos'
    );

    // get videos from Bunny Stream Collection
    $result_videos = $api->api_call( $endpoint );

    if( !is_object($result_videos) || is_wp_error($result_videos) ) {
      return false;
    }

    $videos = $result_videos->items;

    $endpoint = 'https://video.bunnycdn.com/library/'. $fv_fp->_get_option( array('bunny_stream','lib_id') ) . '/collections/' . $collection_id;

    // get collection name
    $result_collection = $api->api_call( $endpoint );

    if( !is_object($result_collection) || is_wp_error($result_collection) ) {
      $collection_name = false;
    } else {
      $collection_name = $result_collection->name;
    }

    return array(
      $videos,
      $collection_name
    );
  }

  /**
   * Get new Bunny Stream Collection data from API
   *
   * @param int $item collection ID
   * @param string $log debug log
   * @param bool $debug debug enabled or not
   * @param object $objCache old cache
   *
   * @return object
   */
  public function get_bunny_stream_cache_http( $item, $log, $debug, $objCache = null ) {
    global $fv_fp;

    if( !$objCache ) {
      $objCache = new stdClass;
    }

    $tStart = microtime(true);

    list( $videos, $collection_name ) = $this->get_collection_streams( $item );

    if( !$videos ) {
      if( $debug ) echo "<!-- $log failed to load videos -->\n";
      return $objCache;
    }

    $objCache->collection_name = $collection_name;

    // set date to current time
    $objCache->date = time();

    $objCache->shortcode = array(
      'caption' => '',
      'src' => '',
      'splash' => '',
      'durations' => '',
      // 'synopsis' => 'first-video;second-video;third-video', //TODO: is there synopsis in Bunny Stream?
      'playlist' => ''
    );

    $cdn_hostname = 'https://' . $fv_fp->_get_option( array('bunny_stream','cdn_hostname') ) . '/';

    foreach( $videos as $video ) {
      if( !$objCache->shortcode['src'] ) { // first video
        $objCache->shortcode['src'] = $cdn_hostname . $video->guid . '/playlist.m3u8';
        $objCache->shortcode['splash'] = $cdn_hostname . $video->guid . '/' . $video->thumbnailFileName;
      } else {
        $playlist_item = $cdn_hostname . $video->guid . '/playlist.m3u8,' . $cdn_hostname . $video->guid . '/' . $video->thumbnailFileName;
        $objCache->shortcode['playlist'] .= $playlist_item . ';';
      }

      $objCache->shortcode['durations'] .= $video->length . ';';
      $objCache->shortcode['caption'] .= $video->title . ';';
    }

    $objCache->shortcode['durations'] = rtrim( $objCache->shortcode['durations'], ';' );
    $objCache->shortcode['caption'] = rtrim( $objCache->shortcode['caption'], ';' );
    $objCache->shortcode['playlist'] = rtrim( $objCache->shortcode['playlist'], ';' );

    $objCache->duration = microtime(true) - $tStart;

    if( $debug ) echo "<!-- $log loaded in ".$objCache->duration." -->\n";

    return $objCache;
  }

  /**
   * Load cache from FV Player DB and update outdated cache
   *
   * @return void
   */
  public function update_cache() {
    global $wpdb;

    $aVimeo = $wpdb->get_results( "SELECT id, src FROM `{$wpdb->prefix}fv_player_videos` WHERE src LIKE 'https://panel.bunny.net/stream/library/manage/%' OR src LIKE 'https://dash.bunny.net/stream/%/library/video%' OR src LIKE 'https://dash.bunny.net/stream/%/library/collections/%'", OBJECT );

    if( $aVimeo ) {
      foreach( $aVimeo AS $objVideo ) {
        $id = $objVideo->id;

        global $FV_Player_Db;

        $objVideo = new FV_Player_Db_Video( $id, array(), $FV_Player_Db );

        $objCache = unserialize( $objVideo->getMetaValue('playlist_data',true) );
        $playlist_last_check_duration = $objVideo->getMetaValue('playlist_last_check_date',true);

        // check if cache is older than 15 minutes
        if( $playlist_last_check_duration && intval($playlist_last_check_duration) + 900 > time() ) {
          continue;
        }

        $src = $objVideo->getSrc();

        $collection_data = $this->is_collection($src);

        // check if it's Bunny Stream Collection
        if( !$collection_data ) {
          continue;
        }

        $item = $collection_data['collection_id'];
        $log = "FV Player Pro - Cloudlflare Stream Collection $item";
        $debug = in_the_loop() && FV_Player_Pro()->is_option_enabled('vimeo_debug');

        $objCache = $this->get_bunny_stream_cache_http( $item, $log, $debug, $objCache );
        $objVideo->updateMetaValue( "playlist_data", serialize($objCache) );
        $objVideo->updateMetaValue( "playlist_last_check_date", $objCache->date );
      }
    }

  }

  /**
   * Set caption for Bunny Stream Collection
   *
   * @param string $caption
   * @param string $src
   *
   * @return string
   */
  public function collections_caption( $caption, $src, $objVideo = false ) {
    if( $data = $this->is_collection($src) ) {
      if ( $objVideo ) {
        $objCache = $objVideo->getMetaValue('playlist_data');

        if( !empty($objCache) ) {
          $objCache = unserialize ($objCache[0]); // cache is serialized
        }

        if( !empty($objCache->collection_name) ) {
          $caption = 'Bunny Stream Collection: ' . $objCache->collection_name;
        } else {
          $caption = 'Bunny Stream Collection: ' . $data['collection_id'];
        }
      }
    }

    return $caption;
  }

  /**
   * Match Bunny Stream Collection - like https://panel.bunny.net/stream/library/manage/20539?page=1&perPage=50&search=&collectionId=4842b62c-0f21-4bd2-921d-3c1053beb130&show=collection
   * or https://dash.bunny.net/stream/20539/library/video?collection=4842b62c-0f21-4bd2-921d-3c1053beb130&orderBy=date
   * or https://dash.bunny.net/stream/20184/library/collections/429a0050-97d8-46e4-b129-a45efacb08c0
   *
   * @param string $src
   *
   * @return array|false
   */
  public function is_collection( $src ) {
    global $fv_fp;

    $library_id = $fv_fp->_get_option( array('bunny_stream','lib_id') );

    if( !$library_id ) {
      return false;
    }

    $url_to_match_legacy = 'https://panel.bunny.net/stream/library/manage/' . $library_id;
    $url_to_match_new = 'https://dash.bunny.net/stream/' . $library_id . '/library/video';
    $url_to_match_new_2  = 'https://dash.bunny.net/stream/' . $library_id . '/library/collections';

    if( strpos($src, $url_to_match_legacy) !== false ) {
      // get collection ID from URL
      preg_match('/collectionId=([a-z0-9\-]+)/', $src, $matches);

      if( isset($matches[1]) ) {
        $collection_id = $matches[1];

        return array(
          'library_id' => $library_id,
          'collection_id' => $collection_id,
        );
      }
    }

    if( strpos($src, $url_to_match_new) !== false ) {
      // get collection ID from URL
      preg_match('/collection=([a-z0-9\-]+)/', $src, $matches);

      if( isset($matches[1]) ) {
        $collection_id = $matches[1];

        return array(
          'library_id' => $library_id,
          'collection_id' => $collection_id,
        );
      }
    }

    if( strpos($src, $url_to_match_new_2) !== false ) {
      // get collection ID from URL
      preg_match('~collections/([a-z0-9\-]+)~', $src, $matches);

      if( isset($matches[1]) ) {
        $collection_id = $matches[1];

        return array(
          'library_id' => $library_id,
          'collection_id' => $collection_id,
        );
      }
    }

    return false;
  }

}

global $FV_Player_Pro_Bunny_Stream_Collections;
$FV_Player_Pro_Bunny_Stream_Collections = new FV_Player_Pro_Bunny_Stream_Collections;

endif;
