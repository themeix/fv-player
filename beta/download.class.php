<?php

/*
 *  Simple shortcode which works for logged in users. It gives them link like http://site.com/?fvplayer_download=http://private-videos.site.com/video.mp4 which checks if the user is logged in and adds the signature/Secure Token and initiates the download. Also logs into Simple History plugin
 */

if( !class_exists('FV_Player_Pro_Download') ) :

class FV_Player_Pro_Download {

  function __construct() {
    add_action( 'admin_init', array( $this, 'register_meta_boxes' ), 20 );
    add_filter( 'fv_player_item', array( $this, 'add_download_link' ), 14, 3 );

    add_shortcode( 'fvplayer_download', array( $this, 'shortcode' ) );

    if( isset($_GET['fvplayer_download']) ) {
      add_action( 'init', array( $this, 'download') );
    }

    add_filter( 'fv_player_title', array( $this, 'button_below_player' ) );

    add_filter( 'fv_player_pro_conf', array( $this, 'add_global_download_hint_force' ) );
  }

  // Hooked on "fv_player_pro_conf" filter if any video indeed has downloads
  function add_global_download_hint( $aOptions ) {
    $aOptions['download_hint'] = $this->get_download_hint_text();
    return $aOptions;
  }

  function add_global_download_hint_force( $aOptions ) {
    if ( $this->is_logged_in() && FV_Player_Pro()->should_force_load_js() ) {
      $aOptions = $this->add_global_download_hint( $aOptions );
    }
    return $aOptions;
  }

  function button_below_player( $title ) {
    global $fv_fp;

    if ( ! $this->is_logged_in() ) {
      return $title;
    }

    if ( method_exists($fv_fp, 'current_video') && $fv_fp->current_video() ) {

      // check if download is enabled
      if( $fv_fp->current_video()->getMetaValue( 'download_enabled', true ) ) {
        $download_sd = $fv_fp->current_video()->getMetaValue( 'download_sd', true );
        $download_hd = $fv_fp->current_video()->getMetaValue( 'download_hd', true );

        $src = $fv_fp->current_video()->getSrc();

        if( strpos( $src, '.mp4' ) !== false ) { // check if mp4 or vimeo
          $download_hd = $src;
        } else if( FV_Player_Pro_Vimeo()->is_vimeo( $src ) ) {
          $download_hd = $src;
          $download_sd = $src;
        }

        $buttons = '';

        foreach( array(
          'SD' => $download_sd,
          'HD' => $download_hd
        ) as $resolution => $link ) {
          if ( ! $link ) {
            continue;
          }

          $html = $this->get_link_template();

          // Show quality label if both are present
          $quality_label = $download_sd && $download_hd ? ' ' . $resolution : false;

          $html = str_replace( '%name%', esc_attr( $this->create_download_filename( $link ) ), $html );
          $html = str_replace( '%class%', '', $html );
          $html = str_replace( '%src%', esc_attr( $this->create_download_link( $link, strtolower( $resolution ) ) ), $html );
          $html = str_replace( '%caption%', '<svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-download" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2"/><path d="M7 11l5 5l5 -5"/><path d="M12 4l0 12"/></svg>' . $quality_label, $html );

          $html = str_replace( '</a>', '<span style="display: none" class="fvplayer_download_hint">'.$this->get_download_hint_text().'</span></a>', $html );

          $buttons .= $html;
        }

        $title .= $buttons;
      }

    }

    return $title;
  }

  function download() {
    if( !isset($_GET['fvplayer_download']) ) return;

    $url = $_GET['fvplayer_download'];

    $message = $url." from '".get_the_title($_GET['post_id'])."' (".$_GET['post_id'].") IP ".FV_Player_Pro()->get_client_ip();

    if( empty($_GET['signature']) || empty($_GET['expire']) ) {
      if( function_exists("SimpleLogger") ) SimpleLogger()->warning("Unathorized video download attempt (no signature) ".$message);
      wp_die('Error processing download.');
    }

    if( !$this->is_logged_in() ) {
      if( function_exists("SimpleLogger") ) SimpleLogger()->warning("Unathorized video download attempt (user not logged in) ".$message);
      wp_die('Error processing download.');
    }

    // We create different encoding versions of the URL
    $url_parsed = parse_url($url);
    $path = $url_parsed['path'];
    $path_parts = explode( '/', $path);
    $path_encoded = implode( '/', array_map( 'urlencode', $path_parts ) );
    $path_encoded_20percent = implode( '/', array_map( 'rawurlencode', $path_parts ) );

    $passed = false;
    foreach( array(
      $url,
      str_replace( ' ', '%20', $url ),
      str_replace( ' ', '+', $url ),
      str_replace( $path, $path_encoded, $url ),
      str_replace( $path, $path_encoded_20percent, $url )
    ) AS $url_check ) {
      $check = md5( $url_check.FV_Player_Pro()->get_client_ip().$_GET['expire'].NONCE_SALT.get_current_user_id());
      if( $check == $_GET['signature'] ) {
        $passed = true;
      }
    }

    if( !$passed ) {
      if( function_exists("SimpleLogger") ) SimpleLogger()->warning("Unathorized video download attempt (bad signature) ".$message);
      wp_die('Error processing download.');
    }

    if( $_GET['expire'] < time() ) {
      if( function_exists("SimpleLogger") ) SimpleLogger()->warning("Expired video link ".$message);
      wp_die('Your link has expired, please go back and reload the page.');
    }

    if( function_exists("SimpleLogger") ) SimpleLogger()->info("User downloaded video ".$message);

    $src = esc_url_raw($_GET['fvplayer_download']);

    // bunny stream
    global $FV_Player_Pro_Bunny_Stream;
    if( $FV_Player_Pro_Bunny_Stream->is_bunny_stream($src)) {
      // change src, example:
      // from - https://vz-7cc93c10-24a.b-cdn.net/e6e0f0a5-3aa6-48f0-9bea-dc2abe9362b4/playlist.m3u8
      // to - https://vz-7cc93c10-24a.b-cdn.net/e6e0f0a5-3aa6-48f0-9bea-dc2abe9362b4/original
      $src = dirname($src) . '/original';
    }

    global $fv_fp;
    $src = $fv_fp->get_video_src($src, array( 'dynamic' => true, 'url_only' => true ) );

    $quality = ! empty( $_GET['quality'] ) ? $_GET['quality'] : 'hd';

    // vimeo
    $src = $this->get_vimeo_link($src, $quality);

    wp_redirect($src);
    exit;
  }

  function get_vimeo_link( $src, $quality = 'hd' ) {
    if( FV_Player_Pro_Vimeo()->is_vimeo( $src ) ) {
      $objVideo = FV_Player_Pro_Vimeo()->get_vimeo( $src );
      $video_qualities = $objVideo->request->files->progressive;

      // It's sad, but some Vimeo videos have only width and some have only height
      $try_fields = array( 'width', 'height' );

      foreach ( $try_fields as $field ) {
        $video_qualities = $this->sort_vimeo_qualities( $video_qualities, $field );

        // Get maximum quality
        if( 'hd' === $quality ) {
          $max = 0;
          foreach( $video_qualities as $item ) {
            if ( !empty( $item[ $field ] ) && $item[ $field ] > $max ) {
              $max = $item[ $field ];
              $src = $item[ 'url' ];
            }
          }

        // Get the middle quality
        } else {
          $count = 0;
          foreach( $video_qualities as $item ) {
            $count++;

            if ( !empty( $item[ $field ] ) ) {
              $src = $item[ 'url' ];

              if ( $count > count( $video_qualities ) / 2 ) {
                break;
              }
            }
          }
        }

      }
    }

    return $src;
  }

  function get_link_template() {
    global $fv_fp;
    $html = '<a download="%name%" class="fvplayer_download %class%" href="%src%">%caption%</a>';
    if( isset($fv_fp->conf['pro']['download_template']) && $fv_fp->conf['pro']['download_template'] ) $html = stripslashes($fv_fp->conf['pro']['download_template']);
    return $html;
  }

  function is_logged_in() {
    if( class_exists('am4PluginsManager') && method_exists('am4PluginsManager','getAPI') ) {
      if( am4PluginsManager::getAPI() && am4PluginsManager::getAPI()->isLoggedIn() ) {
        return true;
      }
    }

    return  get_current_user_id() > 0;
  }

  function options() {
    global $fv_fp;
    ?>
    <p><?php _e('Use the <code>[fvplayer_download src="..." caption="..."]</code> shortcode to provide downloadable video to your site members - logged in users.', 'fv-player-pro'); ?></p>
    <table class="form-table2" style="margin: 5px; ">
      <tr>
        <td style="vertical-align:top"><label for="pro[download_template]"><?php _e('Link template', 'fv-player-pro'); ?>:</label></td>
        <td>
          <input type="text" size="40" name="pro[download_template]" id="pro[download_template]" value="<?php echo esc_attr($this->get_link_template()); ?>" />
          <p class="description"><?php _e('Available tags: <code>%name%</code> <code>%class%</code> <code>%src%</code> <code>%capton%</code>', 'fv-player-pro'); ?></p>
        </td>
      </tr>
      <?php $fv_fp->_get_checkbox(__('Disable right click note', 'fv-player-pro'), array('pro', 'download_no_right_click'), __('If your video server forces video download you can disable the \'Please click again with right mouse button and select "Save Link As...", or "Download Linked File".\' popup note here.', 'fv-player-pro') ); ?>
      <tr>
        <td colspan="4">
          <a class="fv-wordpress-flowplayer-save button button-primary" href="#" style="margin-top: 2ex;"><?php _e('Save', 'fv-wordpress-flowplayer'); ?></a>
          <a class="button fv-help-link" href="https://foliovision.com/player/features/sharing/video-downloading-with-simple-history" target="_blank">Help</a>
        </td>
      </tr>
    </table>
    <?php
  }

  function register_meta_boxes() {
    add_meta_box( 'fv_player_pro_download', __('Download', 'fv-player-pro'), array( $this, 'options' ), 'fv_flowplayer_settings', 'normal', 'low' );
  }

  function create_download_link( $src, $quality = false ) {
    global $post, $fv_fp;

    $url = trim($src);

    $url_home = parse_url( get_home_url() );
    $url_src = parse_url( $url );

    $expire = time()+3600;
    $src = add_query_arg( 'fvplayer_download', $url, get_home_url() );
    $src = add_query_arg( 'post_id', $post->ID, $src );
    $src = add_query_arg( 'expire', $expire, $src );
    $src = add_query_arg( 'signature', md5($url.FV_Player_Pro()->get_client_ip().$expire.NONCE_SALT.get_current_user_id()), $src );

    if ( $quality ) {
      $src = add_query_arg( 'quality', $quality, $src );
    }

    // we need to detect if the link is external. If it's from the same domain as the website Chrome will allow download instantly.
    if( !$fv_fp->_get_option( array('pro', 'download_no_right_click') ) && !empty($url_home['host']) && !empty($url_src['host']) && $url_home['host'] != $url_src['host'] ) {
      $src = add_query_arg( 'fvplayer_download_external', true, $src );
    }

    return $src;
  }

  function create_download_filename( $url, $extra = false ) {
    $aSrc = explode( '/', strrev( $url ), 2 );
    $name = strrev( $aSrc[0] );

    if ( $extra ) {
      // Get file extension out of $name
      $ext = pathinfo( $name, PATHINFO_EXTENSION );

      // Add $extra right before the file extension
      $name = str_replace( '.' . $ext, $extra . '.' . $ext, $name );
    }

    return $name;
  }

  function generate_download( $args ) {
    $url = trim($args['src']);

    if($args['caption'] === '') {
      $args['caption'] = 'Download';
    }

    $src = $this->create_download_link( $url );

    $html = $this->get_link_template();

    $name = $this->create_download_filename( $url );

    $html = str_replace( '%name%', esc_attr($name), $html );
    $html = str_replace( '%class%', $args['class'], $html );
    $html = str_replace( '%src%', esc_attr($src), $html );
    $html = str_replace( '%caption%', $args['caption'], $html );

    return $html;
  }

  function maybe_process_playlist( $args ) {
    // check if vimeo channel/showcase
    global $FV_Player_Pro_Vimeo_Channel;
    $data = $FV_Player_Pro_Vimeo_Channel->vimeo_channel_front_end( $args );
    $output = array();

    // handle vimeo channel/showcase
    if( isset( $data['playlist'] ) ) {
      $captions = $data['caption'];

      // parse captions if not in args
      if( !empty( $captions ) ) {
        $replace_from = array('&amp;quot;','&amp;','\;','&quot;');
        $replace_to = array('"','<!--amp-->','<!--semicolon-->','"');
        $captions = str_replace( $replace_from, $replace_to, $captions );
        $captions = explode( ';', $captions );

        if( isset($captions) && count($captions) > 0 ) {
          foreach( $captions AS $key => $item ) {
            $captions[$key] = str_replace('<!--amp-->','&',$item);
          }
        }

        $current_caption =  isset($captions[0]) ? $captions[0] : '';
      } else {
        $current_caption = $args['caption'];
      }

      $playlist = explode( ';', $data['playlist'] );

      // playlist items
      foreach( $playlist as $index => $src ) {
        $src = explode( ',', $src );
        $current_caption = isset( $captions[ $index + 1 ] ) ? $captions[ $index + 1 ] : $args['caption'];
        $output[] = $this->generate_download( array( 'src' => $src[0], 'class' => $args['class'], 'caption' => $current_caption ) );
      }

    } else { // no playlist, just single video or download_sd & download_hd
      // sd
      if( !empty( $data['src_sd'] ) ) {
        $output[] = $this->generate_download( array(  'src' => $data['src_sd'], 'class' => $args['class'], 'caption' => $args['caption'] . ' - 480p' ) );
      }

      // hd
      if( !empty( $data['src_hd'] ) ) {
        $output[] = $this->generate_download( array(  'src' => $data['src_hd'], 'class' => $args['class'], 'caption' => $args['caption'] . ' - full size' ) );
      }

      // use normal src if no sd or hd
      if( empty( $data['src_sd'] ) && empty( $data['src_hd'] ) ) {
        $output[] = $this->generate_download( array(  'src' => $data['src'], 'class' => $args['class'], 'caption' => $args['caption'] ) );
      }
    }

    return $output;
  }

  function download_button( $output ) {
    if( count($output) > 1 ) {
      $html = '<ul class="fv_player_downloads"><li>'.implode('</li><li>', $output ).'</li></ul>';
    } else {
      $html = implode('',$output);
    }

    return $html;
  }

  function get_download_hint_text() {
    return __('Please click again with right mouse button and select "Save Link As...", or "Download Linked File".', 'fv-player-pro');
  }

  function shortcode( $args ) {
    if( !$this->is_logged_in() ) return;

    global $fv_fp_scripts;
    if( !isset($fv_fp_scripts) ) $fv_fp_scripts = array(); //  todo: some better way of signaling to FV Player it should load its JS!

    $args = wp_parse_args( $args, array('caption' => __('Download'), 'class' => '' ) );
    $html = '';

    if( isset($args['id']) ) {
      global $FV_Player_Db;
      $objPlayer = new FV_Player_Db_Player( $args['id'], array(), $FV_Player_Db );
      $items = array();

      if( $objPlayer ) {
        $aVideos = $objPlayer->getVideos();
        if( count($aVideos) > 0 ) {
          foreach( $aVideos AS $k => $objVideo ) {
            // check meta first
            $download_sd = $objVideo->getMetaValue( 'download_sd', true );
            $download_hd = $objVideo->getMetaValue( 'download_hd', true );

            if ( method_exists( $objVideo, 'getTitle' ) ) {
              $args['caption'] = $objVideo->getTitle();
            } else if ( method_exists( $objVideo, 'getCaption' ) ) {
              $args['caption'] = $objVideo->getCaption();
            }

            if( $download_sd ) {
              $args['src_sd'] = $download_sd;
            }

            if( $download_hd ) {
              $args['src_hd'] = $download_hd;
            }

            // use src only if no download_sd and download_hd
            if( !$download_sd && !$download_hd ) {
              $args['src'] = $objVideo->getSrc();
            }

            $output = $this->maybe_process_playlist($args);

            // merge processed playlist items to main items download array
            $items = array_merge( $items, $output);
          }
        }

        $html = $this->download_button( $items );
      }
    } else if( isset($args['src']) ) {
      // shortcode can contain playlist argument
      $output = $this->maybe_process_playlist($args);

      $html = $this->download_button($output);
    }

    $html .= '<div style="display: none" class="fvplayer_download_hint">'.$this->get_download_hint_text().'</div>';

    return $html;
  }

  function add_download_link($aItem, $index, $aArgs) {
    if( ! $this->is_logged_in() ) return $aItem; // only for logged in users

    global $fv_fp;

    if ( method_exists($fv_fp, 'current_video') && $fv_fp->current_video() ) {

      // check if download is enabled
      if( !$fv_fp->current_video()->getMetaValue( 'download_enabled', true ) ) {
        return $aItem;
      }

      // check if download_sd and download_hd are set
      $download_sd = $fv_fp->current_video()->getMetaValue( 'download_sd', true );
      $download_hd = $fv_fp->current_video()->getMetaValue( 'download_hd', true );

      if ( $download_sd || $download_hd ) {
        if( $download_sd ) {
          $aItem['download_sd'] = $download_sd;
        }

        if( $download_hd ) {
          $aItem['download_hd'] = $download_hd;
        }

      } else {
        $src = $aItem['sources'][0]['src'];

        if( strpos( $src, '.mp4' ) !== false ) { // check if mp4 or vimeo
          $aItem['download_hd'] = $src;
        } else if( FV_Player_Pro_Vimeo()->is_vimeo( $src ) ) {
          $aItem['download_sd'] = $src;
          $aItem['download_hd'] = $src;
        }
      }

      if ( isset($aItem['download_sd']) ) {
        $aItem['download_sd_filename'] = $this->create_download_filename( $aItem['download_sd'], '-sd' );
        $aItem['download_sd'] = $this->create_download_link( $aItem['download_sd'] );
        $aItem['download_sd'] = add_query_arg( 'quality', 'sd', $aItem['download_sd'] );
      }

      if ( isset($aItem['download_hd']) ) {
        $aItem['download_hd_filename'] = $this->create_download_filename( $aItem['download_hd'], '-hd' );
        $aItem['download_hd'] = $this->create_download_link( $aItem['download_hd'] );
        $aItem['download_hd'] = add_query_arg( 'quality', 'hd', $aItem['download_hd'] );
      }

      if( isset($aItem['download_sd']) || isset($aItem['download_hd']) ) {
        add_filter( 'fv_player_pro_conf', array( $this, 'add_global_download_hint' ) );
      }

    }

    return $aItem;
  }

  private function sort_vimeo_qualities( $video_qualities, $field ) {
    if ( 'width' === $field ) {
      usort( $video_qualities, array( $this, 'sort_vimeo_qualities_width' ) );
    } elseif ( 'height' === $field ) {
      usort( $video_qualities, array( $this, 'sort_vimeo_qualities_height' ) );
    }

    return $video_qualities;
  }

  private function sort_vimeo_qualities_width( $a, $b ) {
    return ! empty( $a['width'] ) && ! empty( $b['width'] ) && intval( $a['width'] ) < intval( $b['width'] );
  }

  private function sort_vimeo_qualities_height( $a, $b ) {
    return ! empty( $a['height'] ) && ! empty( $b['height'] ) && intval( $a['height'] ) < intval( $b['height'] );
  }
}

global $FV_Player_Pro_Download;
$FV_Player_Pro_Download = new FV_Player_Pro_Download;

endif;
