<?php

if( !class_exists('FV_Player_Pro_YouTube_Channel') ) :

class FV_Player_Pro_YouTube_Channel {

  function __construct() {
    add_filter( 'fv_flowplayer_shortcode', array( $this, 'youtube_channel' ), 10, 3 );

    // we need to try to build it later again as the src might be loaded from database
    add_filter( 'fv_flowplayer_args_pre', array( $this, 'youtube_channel' ) );

    // FV player screen
    add_filter( 'fv_flowplayer_caption_src', array( $this, 'youtube_caption' ), 10, 2 );

    add_action( 'fv_player_pro_update_youtube_cache', array( $this, 'update_youtube_cache' ) );
  }

  function check_youtube_cache( $is_channel, $sYouTubeKey, $sListID, $sMessage, $objCache ) {

    if ( ! $objCache  ) {
      $objCache = new stdClass();
      $objCache->date = time();
    }

    $url = 'https://www.googleapis.com/youtube/v3/playlistItems?part=snippet&maxResults=50&playlistId=' . $sListID . '&key=' . $sYouTubeKey;

    if ( $is_channel ) {

      /**
       * Get channel ID out of the channel name in URL
       */
      $channel_id = false;

      $response = wp_remote_get( 'https://youtube.googleapis.com/youtube/v3/search?part=snippet&q=' . $sListID . '&type=channel&key=' . $sYouTubeKey );

      if ( ! is_wp_error( $response ) ) {
        $obj = json_decode( wp_remote_retrieve_body( $response ) );

        // This might return multiple results, we take the first one
        if ( ! empty( $obj->items[0]->id->channelId ) ) {
          $channel_id = $obj->items[0]->id->channelId;

          $this->log_details( " channel " . $sListID . " is channel id: " . $channel_id . "\n", $obj );

        } else {
          $this->log_details( " channel  " . $sListID . " is not a channel.\n", $obj );

          if( FV_Player_Pro()->_get_option( array('pro', 'debug_log') ) === 'verbose' ) {
            FV_Player_Pro()->aFrontendErrors[] = '<strong>Error:</strong> YouTube channel ' . $sListID . ' not found: ' . var_export( $obj, true );

          } else {
            FV_Player_Pro()->aFrontendErrors[] = '<strong>Error:</strong> YouTube channel not found.';
          }
          return Null;
        }

      } else {
        $this->log_details( " channel " . $sListID . " API failed.\n", $obj );

        FV_Player_Pro()->aFrontendErrors[] = '<strong>Error:</strong> YouTube API failure.';
        return Null;
      }

      $url = 'https://www.googleapis.com/youtube/v3/search?key=' . $sYouTubeKey . '&channelId=' . $channel_id . '&part=snippet,id&order=date&maxResults=100&type=video&order=date';
    }

    $aResponse = wp_remote_get( $url );

    if( is_wp_error($aResponse) || (!empty($aResponse['response']['code']) && $aResponse['response']['code'] != 200) ) {
      FV_Player_Pro()->aFrontendErrors[] = '<strong>Error:</strong> YouTube API call failed.';

      $this->log_details( " API call failled.\n", false );
      return Null;
    }

    $objCacheNew = json_decode( wp_remote_retrieve_body( $aResponse ) );

    if( count((array)$objCacheNew->items) ) {
      $this->log_details( " API call succeeded.\n", count((array)$objCacheNew->items) );

      $objCache = $objCacheNew;

      $objCache->date = time();

      if( in_the_loop() && FV_Player_Pro()->is_option_enabled('debug_log') ) {
        echo "<!-- FV Player Pro - $sMessage $sListID cached ".$objCache->date."-->\n";
      }

    } else {
      if( in_the_loop() && FV_Player_Pro()->is_option_enabled('debug_log') ) {
        echo "<!-- FV Player Pro - $sMessage $sListID from cache ".$objCache->date." although it's old! -->\n";
      }
      $objCache->date = time() - 600;

    }

    // Load second page of videos too
    if ( ! empty( $objCache->nextPageToken ) ) {
      $aResponse = wp_remote_get( $url . '&pageToken=' . $objCache->nextPageToken );

      if ( ! is_wp_error($aResponse) ) {
        $objSecondPage = json_decode( wp_remote_retrieve_body( $aResponse ) );
        if ( is_array( $objSecondPage->items ) ) {
          $this->log_details( " API call for second page succeeded.\n", count( $objSecondPage->items) );

          $objCache->items = array_merge( $objCache->items, $objSecondPage->items );
        }
      }
    }

    return $objCache;
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

      file_put_contents( ABSPATH.'fv-player-debug-'.wp_hash('fv-player-debug-log','log').'.log', "YouTube API action on ".date('r').$msg.$data."\n\n", FILE_APPEND );
    }
  }

  public function update_youtube_cache() {
    global $wpdb;

    $sYouTubeKey = FV_Player_Pro()->_get_option( array('pro','youtube_key') );

    $aYoutube = $wpdb->get_results( "SELECT id, src FROM `{$wpdb->prefix}fv_player_videos` WHERE src LIKE '%youtube%' AND ( src like '%list=%' or src like '%/channel/%' or src like '%/user/%' or src like '%/@%' or src like '%/c/%' )" );

    if( $aYoutube ) {
      foreach( $aYoutube as $objVideo ) {
        $id = $objVideo->id;

        global $FV_Player_Db;

        $objVideo = new FV_Player_Db_Video( $id, array(), $FV_Player_Db );

        $objCache = unserialize( $objVideo->getMetaValue('playlist_data',true) );
        $playlist_last_check_duration = $objVideo->getMetaValue('playlist_last_check_date',true);

        if( $playlist_last_check_duration && intval($playlist_last_check_duration) + 900 > time() ) {
          continue;
        }

        $res = $this->get_playlist_type_and_id( $objVideo->getSrc() );
        $is_channel = 'channel' === $res['type'];

        if ( $res['id'] && $res['type'] ) {
          $sMessage = $is_channel ? 'YouTube Channel' : 'YouTube Playlist';

          $objCache = $this->check_youtube_cache( $is_channel, $sYouTubeKey, $res['id'], $sMessage, $objCache );

          $objVideo->updateMetaValue( "playlist_data", serialize($objCache) );
          $objVideo->updateMetaValue( "playlist_last_check_date", $objCache->date );
        }

      }
    }
  }

  function youtube_channel( $attrs ) {
    global $fv_fp;
    $attrs_original = $attrs;
    $objVideDBinstance = false;

    if ( ! isset( $attrs['src'] ) ) {
      return $attrs_original;
    }

    // fill-in the video arguments from database, so we enable
    // playlists parsing at this point
    if (!empty($attrs['id']) && is_numeric($attrs['id'])) {
      if (!$fv_fp->current_player() || $fv_fp->current_player()->getId() != $attrs['id']) {
        $pl = new FV_Player_Db_Player( $attrs['id'] );
      } else {
        $pl = $fv_fp->current_player();
      }
      $vids = $pl->getVideos();
      if( $vids && !empty($vids[0]) ) {
        $objVideDBinstance = $vids[0];
      }
    }

    $sPlaylistURL = trim($attrs['src']);

    if( stripos($sPlaylistURL,'watch') !== false ) {
      return $attrs_original;
    }

    $res = $this->get_playlist_type_and_id( $sPlaylistURL );
    if ( ! $res['type'] || ! $res['id'] ) {
      return $attrs_original;
    }

    $sYouTubeKey = FV_Player_Pro()->_get_option( array('pro','youtube_key') );
    if( !$sYouTubeKey  ) {
      FV_Player_Pro()->aFrontendErrors[] = '<strong>Error:</strong> Google Developer Key required.';
      return $attrs_original;
    }

    $is_channel = 'channel' === $res['type'];

    $sPrefix = $is_channel ? 'fv_player_pro_youtube_channel_' : 'fv_player_pro_youtube_playlist_';
    $sMessage = $is_channel ? 'YouTube Channel' : 'YouTube Playlist';

    if( is_object($objVideDBinstance) ) {
      $objCache = $objVideDBinstance->getMetaValue('playlist_data');
      if( !empty($objCache) ) {
        $objCache = unserialize($objCache[0]);
      }
    } else {
      $objCache = get_option( $sPrefix . $res['id'] );
    }

    $aCaption = array();
    $aPlaylist = array();

    if( $objCache && isset($objCache->date) && $objCache->date + 900 > time() ) {
      if( in_the_loop() && FV_Player_Pro()->is_option_enabled('debug_log') ) {
        echo "<!-- FV Player Pro - $sMessage " . $res['id'] . " from cache ".$objCache->date."-->\n";
      }
    } else {
      $objCache = $this->check_youtube_cache( $is_channel, $sYouTubeKey, $res['id'], $sMessage, $objCache);

      if( is_null($objCache) ) {
        return $attrs_original;
      }

      if( is_object($objVideDBinstance) ) {
        $objVideDBinstance->updateMetaValue( "playlist_data", serialize($objCache) );
        $objVideDBinstance->updateMetaValue( "playlist_last_check_date", $objCache->date );
      } else {
        update_option( $sPrefix . $res['id'], $objCache, false );
      }
    }

    $iCount = 0;
    if ( ! empty( $objCache->items ) ) {
      foreach( (array) $objCache->items AS $aItem ) {
        if( empty($aItem->snippet->resourceId->videoId) && empty($aItem->id->videoId) ) {
          continue;
        }

        $video_id = !empty( $aItem->snippet->resourceId->videoId ) ? $aItem->snippet->resourceId->videoId : $aItem->id->videoId;

        if( $iCount == 0 ) {
          $attrs['src'] = 'https://www.youtube.com/watch?v=' . $video_id;
          $attrs['splash'] = (isset( $aItem->snippet->thumbnails->standard ) ? $aItem->snippet->thumbnails->standard->url : $aItem->snippet->thumbnails->medium->url );
        } else {
          if( !empty($aItem->snippet->thumbnails->medium) ) {
            $aPlaylist[] = 'https://www.youtube.com/watch?v=' . $video_id . ','. $aItem->snippet->thumbnails->medium->url;
          } else {
            $aPlaylist[] = 'https://www.youtube.com/watch?v=' . $video_id;
          }
        }
        $aCaption[] = str_replace( ';', '\;', $aItem->snippet->title );
        $iCount++;
      }
    }

    if( count($aPlaylist) ) {
      $attrs['playlist'] = implode(';',$aPlaylist);

      // If caption is set, we use that for the first video, so get rid of the first caption from YouTube Channel
      if ( ! empty($attrs['caption']) ) {
        $aCaption = array_splice( $aCaption, 1 );
        $attrs['caption'] .= ';';

      // Otherwise use all the captions from YouTube Channel
      } else {
        $attrs['caption'] = '';
      }

      $attrs['caption'] .= implode(';',$aCaption);
    }

    return $attrs;
  }

  function get_playlist_type_and_id( $src ) {
    $id = false;
    $type = false;

    preg_match( '~(?:youtube.com|youtu.be).*?list=([a-zA-Z0-9-_]*+)~', $src, $aListID );

    // https://www.youtube.com/@MiddleNation
    preg_match( '~youtube.com/@([a-zA-Z0-9-_]+)~', $src, $aChanelName );

    // https://www.youtube.com/c/SamPilgrim/videos
    if ( empty( $aChanelName ) ) {
      preg_match( '~youtube.com/c/([a-zA-Z0-9-_]+)~', $src, $aChanelName );
    }

    if ( ! empty( $aListID ) ) {
      $id = $aListID[1];
      $type = 'playlist';
    } else if ( ! empty( $aChanelName ) ) {
      $id = $aChanelName[1];
      $type = 'channel';
    }

    return array(
      'id'   => $id,
      'type' => $type
    );
  }

  function youtube_caption( $caption, $src ) {
    if( FV_Player_Pro()->is_youtube( $src ) ) {
      $youtube = 'Youtube';
      $res = $this->get_playlist_type_and_id( $src );

      if ( $res['type'] ) {
        $youtube .= ' ' . ucfirst( $res['type'] );
      }
      $caption = $youtube . ': ' . $caption;
      $caption = preg_replace('/watch\?v=/', '', $caption); // if single video remove watch?v=
    }
    return $caption;
  }

}

global $FV_Player_Pro_YouTube_Channel;
$FV_Player_Pro_YouTube_Channel = new FV_Player_Pro_YouTube_Channel;

endif;
