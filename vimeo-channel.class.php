<?php

if( !class_exists('FV_Player_Pro_Vimeo_Channel') ) :

class FV_Player_Pro_Vimeo_Channel {
  function __construct() {
    // if it's [fvplayer channel|album|portfolio=".*?"] we need to build the playlist earlier
    add_filter( 'fv_flowplayer_shortcode', array( $this, 'is_channel_shortcode' ) );

    // we need to try to build it later again as the src might be loaded from database
    add_filter( 'fv_flowplayer_args_pre', array( $this, 'vimeo_channel_front_end' ) );

    // FV player screen
    add_filter( 'fv_flowplayer_caption_src', array( $this, 'vimeo_caption' ), 11, 2 );

    add_action( 'fv_player_pro_update_vimeo_cache', array( $this, 'update_vimeo_cache' ) );

  }

  function is_channel_shortcode( $attrs ) {
    if( !empty($attrs['channel']) || !empty($attrs['album']) || !empty($attrs['portfolio']) ) {
      $attrs = $this->vimeo_channel_front_end($attrs);
    }

    return $attrs;
  }

  /**
   *  Perform the API call to load Vimeo Album/Channel/Portfolio video playlist
   *  If we get back unexpected response, we hang on to the old data
   *
   *  @param  string  $type     The Vimeo playlist type - album/channel/portfolio
   *  @param  string  $string   The ID of the Vimeo playlist
   *  @param  string  $log      Log prefix to use
   *  @param  bool    $debug    Should it output debug information? Used in the WP loop
   *  @param  object  $objCache Old cache data as fallback
   *
   *  @return object|bool       Object with shortcode, date and API call duration, or FALSE in
   *                            case of HTTP failure.
   */
  function get_vimeo_cache_http($type, $item, $log, $debug , $objCache = Null) {
    if( !$objCache ) {
      $objCache = new stdClass;
    }

    // set the next page to 1 if it's not set
    if( empty($objCache->next_page) ) {
      $objCache->next_page = 1;
    }

    // var_dump( 'vimeo channel debug 1', func_get_args() );
    $tStart = microtime(true);
    $sResponse = FV_Player_Pro_Vimeo()->http( array( 'action' => 'v_'.$type, 'item_id' => $item, 'next_page' => $objCache->next_page ) );

    $valid_json = false;
    if( preg_match( '~<FVSERVICES>(.*?)</FVSERVICES>~', $sResponse, $match ) ) {
      $objCacheNew = json_decode($match[1]);
      if( json_last_error() === JSON_ERROR_NONE ) {
        $valid_json = true;
      }
      if( isset($objCacheNew->error) ) {
        FV_Player_Pro_Vimeo()->log_error( $type.' '.$item, $objCacheNew->error );
      }
    }

    if( !$valid_json ) {
      FV_Player_Pro_Vimeo()->log_error( $type.' '.$item, "Can't parse API server response!" );
    }

    // did we obtain the new playlist successfully?
    if( isset($objCacheNew->items) ) {
      $objCache = $objCacheNew;
      $objCache->date = time();
      $objCache->duration = microtime(true) - $tStart;

      if( $debug ) echo "<!-- $log cached ".$objCache->date." in ".$objCache->duration."-->\n";

    // did we obtain at least the currrent data sucessfully?
    } else if( isset($objCacheNew->date) ) {
      if( $debug ) echo "<!-- $log from cache ".$objCache->date." although it's old! -->\n";

      $objCacheNew = new stdClass;
      $objCache->date = time() - 600;
      $objCache->duration = 0;

    // if the output was completely mangled
    } else {
      if( $debug ) echo "<!-- $log from cache due to parse error! -->\n";

      $objCache->date = time() - 600;
      $objCache->duration = 0;

    }

    return $objCache;
  }

  function check_vimeo_type( $attrs ) {
    $src = $attrs['src'];
    if( stripos($src, 'https://vimeo.com/manage/' ) === 0 ) {
      return array( 'attrs' => false, 'type' => false );
    }

    // check if we match channel, album or portfolio Vimeo URL
    // It must not match https://vimeo.com/channels/staffpicks/65107797 format
    $channel = preg_match( "~vimeo(pro)?.com/channels/.+~i", $src ) && !preg_match( '~/channels/[^>]+/\d+~', $src );
    $album = preg_match( "~vimeo(pro)?.com/(album|showcase)/.+~i", $src );

    // the Vimeo Portfolio link doesn't have a fixed prefix in the URL path
    // so we just make sure it's not a link to individual video
    $portfolio = FV_Player_Pro_Vimeo()->get_vimeo_id($src) === false &&
      // and it's not a Pro file URL
      stripos($src,'/player.vimeo.com/') === false &&
      // and not an event link
      !FV_Player_Pro_Vimeo()->is_vimeo_event($src) &&
      // but it has to be on vimeo.com or vimeopro.com obviously
      preg_match( "~//vimeo(pro)?.com/.+/.+~i", $src );

    if ($channel) {
      $attrs['channel'] = $src;
    } else if ($album) {
      $attrs['album'] = $src;
    } else if ($portfolio) {
      // portfolio is set as "portfolio" parameter when coming from shortcode instead of DB
      $attrs['portfolio'] = (!empty($src) ? $src : $attrs['portfolio']);
    }

    if( !isset($attrs['channel']) && !isset($attrs['album']) && !isset($attrs['portfolio']) ) {
      return array('attrs' => $attrs , 'type' => Null);
    }

    $type = 'src';
    if( !empty($attrs['channel']) ) $type = 'channel';
    if( !empty($attrs['album']) ) $type = 'album';
    if( !empty($attrs['portfolio']) ) $type = 'portfolio';

    return array( 'attrs' => $attrs, 'type' => $type );
  }

  public function update_vimeo_cache() {
    global $wpdb;

    $aVimeo = $wpdb->get_results( "SELECT id, src FROM `{$wpdb->prefix}fv_player_videos` WHERE src LIKE '%vimeo.com/%' AND src NOT LIKE '%player.vimeo.com/%' AND src NOT LIKE '%vimeo.com/event%' AND src NOT REGEXP 'vimeo.com/[0-9]{8,9}' " );

    if( $aVimeo ) {
      foreach( $aVimeo AS $objVideo ) {
        $id = $objVideo->id;

        global $FV_Player_Db;

        $objVideo = new FV_Player_Db_Video( $id, array(), $FV_Player_Db );

        $objCache = $objVideo->getMetaValue('playlist_data', true);

        if( is_serialized($objCache) ) {
          $objCache = @unserialize(trim($objCache));
        } else {
          $objCache = json_decode($objCache);
        }

        $playlist_last_check_date = $objVideo->getMetaValue('playlist_last_check_date',true);

        if( $playlist_last_check_date && intval($playlist_last_check_date) + 900 > time() && !empty($objCache->finished_iteration) ) {
          continue;
        }

        $item = $objVideo->getSrc();

        $data = $this->check_vimeo_type( array('src' => $item) );
        $type = $data['type'];

        if( !is_null($type) ) {
          $debug = in_the_loop() && FV_Player_Pro()->is_option_enabled('debug_log');
          $log = "FV Player Pro - Vimeo $type $item";

          if( stripos($item,'vimeo.com/') || stripos($item,'vimeopro.com/') ) {
            $item = preg_replace( '~.*?([^/]+)/?$~', '$1', $item );
          }

          $newObjCache = $this->get_vimeo_cache_http($type, $item, $log , $debug, $objCache);

          if( empty($objCache->finished_iteration) && isset($newObjCache->next_page) && isset($objCache->next_page) && ( $newObjCache->next_page > $objCache->next_page || !$newObjCache->next_page ) ) {

            // only append if there was a next page
            if($objCache->next_page) {
              if( !isset($objCache->items) ) {
                $objCache->items = array();
              }

              // append new
              $newObjCache->items = array_merge( $objCache->items, $newObjCache->items );
            }

            // no new data, we're done
            if( empty($newObjCache->next_page) ) {
              $newObjCache->next_page = 1;
              $newObjCache->finished_iteration = true;
            }

          } else {

            if($objCache->finished_iteration) {

              $objCache_items = $objCache->items;

              // add new items to the beginning
              foreach( array_reverse( $newObjCache->items ) as $item ) {
                $add_item = true;
                foreach( $objCache->items as $item2 ) {
                  // check if the item is already in the list
                  if( strcmp($item->src, $item2->src) == 0 ) {
                    $add_item = false;
                    break;
                  }
                }

                // add item to the beginning
                if($add_item) {
                  array_unshift($objCache_items, $item);
                }

              }

              // set the new items
              $newObjCache->items = $objCache_items;

              $newObjCache->next_page = 1;
              $newObjCache->finished_iteration = true;
            }

            if( empty($newObjCache->next_page) ) {
              $newObjCache->next_page = 1;
              $newObjCache->finished_iteration = true;
            }

          }

          $objVideo->updateMetaValue( "playlist_data", json_encode( $newObjCache, JSON_HEX_QUOT ) );
          $objVideo->updateMetaValue( "playlist_last_check_date", $newObjCache->date );
        }
      }
    }
  }

  function vimeo_channel_front_end( $attrs ) {
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
      $vids = $pl->getVideos();
      if( $vids && !empty($vids[0]) ) {
        $objVideDBinstance = $vids[0];
      }
    }

    /**
     * Is this a Vimeo channel, showcase, album or portfolio?
     */
    $data = $this->check_vimeo_type( $attrs );
    $type = $data['type'];
    $attrs = $data['attrs'];

    // ...no it's not.
    if( is_null($type) ) {
      return $attrs_original;
    }

    $item = $attrs[$type];

    $log = "FV Player Pro - Vimeo $type $item";
    $debug = in_the_loop() && FV_Player_Pro()->is_option_enabled('debug_log');
    $option = FV_Player_Pro_Vimeo()->get_transient_name('vimeo_'.$type).$item;

    // Additional verification of the video src
    if( stripos($item,'vimeo.com/') || stripos($item,'vimeopro.com/') ) {
      $item = preg_replace( '~.*?([^/]+)/?$~', '$1', $item );
    } else {
      return $attrs_original;
    }

    // Load cached channel (playlist) from FV Player DB
    if( is_object($objVideDBinstance) ) {
      $objCache = $objVideDBinstance->getMetaValue('playlist_data');
      if( !empty($objCache) ) {

        $objCache = $objCache[0];

        if( is_serialized($objCache) ) {
          $objCache = @unserialize(trim($objCache));
        } else {
          $objCache = json_decode($objCache);
        }
      }

    // Load cached channel (playlist) from wp_options
    } else {
      $objCache = get_option( $option );
    }

    if( !$objCache ) $objCache = new stdClass;

    // Have cache
    if( $objCache && (
      is_object($objVideDBinstance) && isset($objCache->items) || // accept even old cache if it's using FV Player DB as that one gets background updates
      isset($objCache->date) && $objCache->date + 900 > time() // for wp_options cache also refresh the content
    ) ) {
      if( $debug ){
        if( is_object($objVideDBinstance) ) {
          echo "<!-- $log from meta cache ".$objCache->date." saved ".$objCache->duration."-->\n";
        } else {
          echo "<!-- $log from options cache ".$objCache->date." saved ".$objCache->duration."-->\n";
        }
      }

    // Cache not there or too old
    } else {
      $objCache = $this->get_vimeo_cache_http($type, $item, $log, $debug, $objCache);

      // Store cache in FV Player DB (video meta)
      if( is_object($objVideDBinstance) ) {
        $objVideDBinstance->updateMetaValue( "playlist_data", json_encode( $objCache,  JSON_HEX_QUOT ) );
        $objVideDBinstance->updateMetaValue( "playlist_last_check_date", $objCache->date );

      // ...or in wp_options
      } else {
        update_option( $option, $objCache, false );
      }
    }

    /**
     * If the channel (or album, showcase or portfolio) parsed properly $objCache->items has a structure like:
     *
     *  (object) array(
     *    'duration' => '01:23',
     *    'src'      => 'first-video',
     *    'splash'   => 'image',
     *    'synopsis' => 'Often comes with chars like &hellip;',
     *    'title'    => 'Video title',
     *  ),
     */
    if( isset($objCache->items) ) {

       // If custom splash is set then use it
      if( !empty($attrs['splash']) ) {
        $objCache->items[0]->splash = $attrs['splash'];
      }

      // TODO: refactor to use wp_parse_args on new format
      // $attrs = wp_parse_args( $objCache->shortcode, $attrs );

      $duration = array();
      $playlist = array();
      $synopsis = array();
      $title = array();

      $limit = 5000; // TODO: this should be a setting
      $current = 0;

      $did_first = false;
      foreach ( $objCache->items as $item ) {

        // First playlist item needs to use src and splash
        if ( ! $did_first ) {
          $attrs['splash'] = $item->splash;
          $attrs['src'] = $item->src;
          $did_first = true;

        // Further playlist items go to just "playlist"
        } else {
          $playlist[] = $item->src . ',' . $item->splash;
        }

        $duration[] =  $item->duration;

        $synopsis[] = str_replace( ';', '\;', $item->synopsis );

        $title[] = str_replace( ';', '\;', $item->title );

        $current++;

        // break if we reached the limit
        if( $current >= $limit ) {
          break;
        }
      }

      $attrs['durations'] = implode( ';', $duration );
      $attrs['playlist'] = implode( ';', $playlist );
      $attrs['synopsis'] = implode( ';', $synopsis );
      $attrs['title'] = implode( ';', $title );

    }

    return $attrs;
  }

  function vimeo_caption( $caption ,$src ) {
    if( preg_match('/^https:\/\/vimeo\.com/', $src, $match) ) {
      $vimeo = 'Vimeo';
      $data = $this->check_vimeo_type( array('src' => $src) );
      $type = $data['type'];

      if( !is_null($type) ) {
        $vimeo .= ' ' . ucfirst($type);
      }
      $caption = $vimeo . ': ' . $caption;
    }
    return $caption;
  }

}

global $FV_Player_Pro_Vimeo_Channel;
$FV_Player_Pro_Vimeo_Channel = new FV_Player_Pro_Vimeo_Channel;

endif;
