<?php

if ( !class_exists('FV_Player_Pro_Transcript') ) :

class FV_Player_Pro_Transcript {

  function __construct() {
    add_action( 'admin_init', array( $this, 'cron_init' ) );
    add_action( 'fv_player_pro_update_transcript_cache', array( $this, 'update_transcript_cache' ) );
    add_filter( 'cron_schedules', array( $this, 'fv_cron_schedules' ) );
    add_filter( 'fv_flowplayer_html', array( $this, 'transcript_below_player' ), 11, 2 );

    add_shortcode( 'fvplayer_transcript', array( $this, 'transcript_separate' ) );

    add_action( 'fv_player_pro_update', array( $this, 'updateTableConversion' ) );
  }

  function updateTableConversion() {
    global $wpdb;

    $res = $wpdb->query("INSERT INTO `{$wpdb->prefix}fv_player_videometa` (id_video, meta_key, meta_value) SELECT m.id_video, 'transcript_src', m.meta_value FROM wp_fv_player_videometa AS m LEFT JOIN wp_fv_player_videometa AS n ON m.id_video = n.id_video AND n.meta_key = 'transcript_src' WHERE m.meta_key = 'transcript' AND n.id IS NULL");
  }

  public function transcript_below_player( $html, $player ) {
    global $post;

    if ( empty( $post->post_content ) || strpos( $post->post_content, '[fvplayer_transcript' ) === false ) {
      $html = $this->transcript_html( $html, $player );
    }

    return $html;
  }

  public function transcript_html( $html ) {
    global $fv_fp;

    $aArgs = func_get_args();

    $transcript_video_arr = array();
    $element_id = $aArgs[1]->hash;
    $output = '';
    $aVideos = false;
    $has_transcript = false;
    $aLangs = flowplayer::get_languages(); // get array of languages, like: array('EN' => 'English', ...

    require_once( ABSPATH . 'wp-admin/includes/translation-install.php' );
    $translations = wp_get_available_translations();

    // get currnent language
    $default_lang = get_locale();
    $default_lang_code = 'EN';
    if( $default_lang == 'en_US' ) {
      $default_lang = 'English';
    } else if( isset($translations[$default_lang]) ) {
      $default_lang_code = $translations[$default_lang]['iso'][1];
      $default_lang = $translations[$default_lang]['native_name'];
    }

    $args = $aArgs[1]->aCurArgs;

    if ( isset($args['transcript']) && strlen(trim($args['transcript'])) ) { // shortcode
      $transcript_video_arr['shortcode']['transcript'] = $args['transcript'];
      $transcript_video_arr['shortcode']['src'] = $args['src'];
      $has_transcript  = true;

      if ( !empty($args['original_formatting']) ) {
        $transcript_video_arr['shortcode']['transcript_original_formatting'] = $args['original_formatting'];
      }

    } else if( method_exists($fv_fp,'current_video') && $fv_fp->current_player() && $fv_fp->current_player()->getVideos() ) { // db player
      $aVideos = $fv_fp->current_player()->getVideos(); // get all videos for current player
      foreach($aVideos as $index => $video) { // iterate over all videos for current player
        $video_id = $video->getId();

        if ( method_exists( $fv_fp, 'get_current_video_to_edit' ) && $fv_fp->get_current_video_to_edit() > -1 ) {
          if( $index != $fv_fp->get_current_video_to_edit() ) {
            continue;
          }
        }

        $transcript_video_arr[ $video_id ]['src'] = $video->getSrc();

        foreach ($video->getMetaData() as $meta) { // iterate over all meta data for current video
          $meta_value = $meta->getMetaValue();

          $lang = $this->get_lang_from_metakey($meta->getMetaKey());

          if ( strpos( $meta->getMetaKey(), 'transcript_src' ) !== false && ! empty( $meta_value) ) {
            if( !isset( $transcript_video_arr[ $video_id ]['transcript_src'] ) ) {
              $transcript_video_arr[ $video_id ]['transcript_src'] = array();
            }

            $transcript_video_arr[ $video_id ]['transcript_src'][$lang] = $meta->getMetaValue();
            $has_transcript = true;
          }

          if ( strpos( $meta->getMetaKey(), 'transcript_urlcached' ) !== false && !empty( $meta_value ) ) {
            if( !isset( $transcript_video_arr[ $video_id ]['transcript_urlcached'] ) ) {
              $transcript_video_arr[ $video_id ]['transcript_urlcached'] = array();
            }

            $transcript_video_arr[ $video_id ]['transcript_urlcached'][$lang] = $meta->getMetaValue();
          }

          if ($meta->getMetaKey() == 'transcript_original_formatting') {
            $transcript_video_arr[ $video_id ]['transcript_original_formatting'] = $meta->getMetaValue();
          }

          if ( strpos( $meta->getMetaKey(), 'transcript_text' ) !== false && !empty( $meta_value ) ) {
            if( !isset( $transcript_video_arr[ $video_id ]['transcript_text'] ) ) {
              $transcript_video_arr[ $video_id ]['transcript_text'] = array();
            }

            // check if src exists before adding text
            if( isset( $transcript_video_arr[ $video_id ]['transcript_src'][$lang])) {
              $transcript_video_arr[ $video_id ]['transcript_text'][] = array(
                'lang' => $lang,
                'lang_display' => isset($aLangs[strtoupper($lang)]) ? $aLangs[strtoupper($lang)] : ( $lang == '' ? $default_lang : strtoupper($lang) ),
                'text' => $meta->getMetaValue(),
              );
            }
          }
        }
      }
    }

    if( !$has_transcript ) {
      return $html;
    }

    // build transcrtipt html for every video with transcript
    $first_video = true;

    $transcriptLangHtml = '';
    $transcriptLangs = array();
    $video_langs = array();
//var_dump($transcript_video_arr);die();
    foreach( $transcript_video_arr as $video_id => $meta_values ) {

      $transcriptRaw = '';

      // $video_id might be a number of "shortcode" string
      if ( is_numeric($video_id) ) { // db no transcript text
        $transcriptRaw = array();

        if ( ! empty( $meta_values['transcript_src'] ) && is_array( $meta_values['transcript_src'] ) ) {
          foreach( $meta_values['transcript_src'] as $lang => $url) {
            $transcriptRaw[] = array(
              'lang' => $lang,
              'lang_display' => isset($aLangs[strtoupper($lang)]) ? $aLangs[strtoupper($lang)] : ( $lang == '' ? $default_lang : strtoupper($lang) ),
              'text' => $this->get_transcript_cache($url, $video_id, $meta_values['src'], $lang),
            );
          }
        }

      } else { // shortcode
        $url = $this->get_transcript_url($meta_values['transcript'], $meta_values['src']);
        $transcriptRaw = $this->get_transcript_cache($url, false, $meta_values['src']);
      }

      if( empty($transcriptRaw) ) {
        continue; // no transcript
      }

      // convert to new format
      if( !empty($transcriptRaw) && !is_array($transcriptRaw) ) {
        $transcriptRaw = array(
          array(
            'lang' => 'default',
            'lang_display' => $default_lang,
            'text' => $transcriptRaw,
          )
        );
      }

      $transcriptHtml = '';

      if( is_array($transcriptRaw) ) {
        // build transcript html, separate langage to divs
        foreach( $transcriptRaw as $transcript ) {
          // prevent duplicate languages
          if( !in_array($transcript['lang'], $transcriptLangs) ) {
            $transcriptLangs[] = $transcript['lang'];
            $transcriptLangHtml .= '<a data-lang="'.$transcript['lang'].'">'.$transcript['lang_display'].'</a>';
          }

          if( !isset($video_langs[$video_id]) ) $video_langs[$video_id] = array();

          if( !in_array($transcript['lang'], $video_langs[$video_id]) ) {
            $video_langs[$video_id][] = $transcript['lang'];
          }

          $transcriptHtml .= '<div class="fv_fp_transcript_lang" data-lang="'.$transcript['lang'].'" data-id="'.$video_id.'" >';
          $transcriptHtml .= $this->format_transcript($transcript['text'] , !empty($meta_values['transcript_original_formatting']));
          $transcriptHtml .= '</div>';
        }

      }

      if ( ! empty( $transcriptHtml ) ) {
        $output .= '<div ';

        // hide transcript for all videos except the first one
        if ( ! $first_video ) {
          $output .= 'style="display:none"';
        }

        $output .= ' class="fv_fp_transcript" data-id="' . esc_attr( $video_id ) . '" data-player="#wpfp_' . esc_attr( $element_id ) . '">' . $transcriptHtml . '</div>';
      }
      $first_video = false;

      // TODO: error message if one or more transcripts not found
    }

    $transcriptLangHtml = '<button type="button" class="fvp-transcript-language-button" data-default="' . $default_lang_code . '">' . $default_lang_code . '</button><div class="fvp-transcript-languages-menu" data-langs="'.esc_attr(json_encode($video_langs)).'"><strong>Language</strong>'.$transcriptLangHtml.'</div>';

    if ( ! empty( $output ) ) {
      wp_enqueue_script('fvplayer-pro-mark-js', plugins_url('js/jquery.mark.min.js',__FILE__), array('jquery'), FV_Player_Pro()->version );

      $class = 'fv_fp_transcript_wrapper';

      $sizer = '';

      if( !apply_filters( 'fv_player_no_transcript_dragging', false ) || !apply_filters( 'fv_player_no_transcript_sizing', false ) ) {
        $class .= ' fv_fp_transcript_wrapper_resizable';

        wp_enqueue_script('interact-js', plugins_url('js/interact.min.js',__FILE__), array('jquery'), '1.3.4' );

        $sizer = !apply_filters( 'fv_player_no_transcript_sizing', false ) ? '<div class="fv_fp_transcript_sizer"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16"><path d="M6.7 16l9.3-9.3v-1.4l-10.7 10.7z" /><path d="M9.7 16l6.3-6.3v-1.4l-7.7 7.7z" /><path d="M12.7 16l3.3-3.3v-1.4l-4.7 4.7z" /><path d="M15.7 16l0.3-0.3v-1.4l-1.7 1.7z" /></svg></div>' : '';
      }

      $theme = FV_Player_Pro()->_get_option( array('pro','transcript_theme') );
      if ( !empty($args['transcript_theme']) ) $theme = $args['transcript_theme'];

      $class .= ' fv_fp_transcript_'.$theme;

      if ( isset($args['transcript_hidden']) || FV_Player_Pro()->_get_option( array('pro','transcript_hidden') ) ) {
        $class .= ' fv_fp_transcript_hidden';
      }

      return $html . '<div class="'.$class.'" data-player-id="#wpfp_'.$element_id.'" >'
              . '<div class="fv_fp_transcript_head"><div class="fv_fp_transcript_head_left"><label for="fv_fp_transcript_autoscroll' . $element_id . '">Autoscroll:</label>&nbsp;<input class="fv_fp_transcript_autoscroll" id="fv_fp_transcript_autoscroll' . $element_id . '" type="checkbox" checked /></div>'
              . '<input class="fv_fp_transcript_search" id="fv_fp_transcript_search' . $element_id . '" type="text" placeholder="Search" />'
              . '<div class="fv_fp_transcript_head_right"><button type="button" class="search_result_prev" disabled></button><button type="button" class="search_result_next" disabled></button>'
              . '<button type="button" class="toggle_transcript_collapse"></button> ' . $transcriptLangHtml . ' </div>'
              .'</div>'
              . $output . $sizer .'</div>';
    } else {
      return $html . '<div class="fv_fp_transcript_wrapper">Error loading transcript. Please try again in a minute. No transcript found</div>';
    }
  }

  public function transcript_separate( $args ) {
    global $fv_fp;
    return $this->transcript_html( '', $fv_fp );
  }

  public static function format_transcript($transcriptRaw, $originalFormatting) {
    $transcriptLines = explode("\n", $transcriptRaw);
    $trancsriptData = array();
    $tId = 0;
    $time_last = 0;

    $last_line = false;

    foreach ($transcriptLines as $line_no => $line) {
      $matches = array();

      // hiding cue/chapter IDs
      if ( preg_match('/^(Chapter )?\d+$/', trim($line) ) && preg_match('/^((?:[0-9]{2}:){1,2}[0-9]{2}[,.][0-9]{3}) --\> ((?:[0-9]{2}:){1,2}[0-9]{2}[,.][0-9]{3})(.*)/', $transcriptLines[$line_no+1], $matches)  ) {
        continue;
      }

      if (preg_match('/^((?:[0-9]{2}:){1,2}[0-9]{2}[,.][0-9]{3}) --\> ((?:[0-9]{2}:){1,2}[0-9]{2}[,.][0-9]{3})(.*)/', $line, $matches)) {
        $caption_start = FV_Player_Pro::hms_to_seconds($matches[1]);
        $caption_end = FV_Player_Pro::hms_to_seconds($matches[2]);

        if ($originalFormatting) {
          $bShouldBreak = true;
        } else {
          $bShouldBreak = ( $caption_start - $time_last > 2 ) ? true : false;  // insert paragraph break if entries are more than 5 seconds apart
        }

        $trancsriptData[$tId++] = array(
          'id' => $tId - 1,
          'start' => $matches[1],
          'end' => $matches[2],
          'text' =>  $time_last && $bShouldBreak ? '<br />' : '',
        );

        $time_last = $caption_end;
        $last_line = 'cue';

      } else if ( count($trancsriptData) ) {
        if ( $last_line == 'cue' && strlen(trim($line)) == 0 ) {
          $trancsriptData[$tId - 1]['text'].= ( $originalFormatting ? '<br />' : '<br /><br />')."\n";
          $last_line = 'blank';
        } else if ( trim($line) ) {
          $trancsriptData[$tId - 1]['text'].= ' '.$line."\n";
          $last_line = 'text';
        }
      }
    }

    usort( $trancsriptData, array( 'FV_Player_Pro_Transcript', 'transcript_sort_test') );
    $transcriptHtml = "";

    foreach ($trancsriptData as $key => $val) {
      // Remove 00: at start, including 00:0
      $val['start'] = preg_replace( '~^(00:)+(0)?~', '', $val['start'] );
      $transcriptHtml .= '<span data-start="' . esc_attr($val['start']) . '">' . $val['text'] . ' </span>';
    }

    return $transcriptHtml;
  }

  public static function transcript_sort_test($a,$b) {
    $a_value = FV_Player_Pro::hms_to_seconds($a['start']);
    $b_value = FV_Player_Pro::hms_to_seconds($b['start']);

    if ( $a_value > $b_value ) {
      return 1;
    } else if( $a_value < $b_value ) {
      return -1;
    } else {
      return 0;
    }
  }

  /**
   * Get transcript URL.
   *
   * If it's a Vimoe video with auto transcript, then fetch the first active texttrack.
   */
  private function get_transcript_url($transcript, $src) {
    $transcriptUrl = '';
    if ( $transcript === 'auto' && FV_Player_Pro_Vimeo()->is_vimeo($src) ) { // vimeo with 'auto'
      // work with data cached from Vimeo API
      $objVideo = FV_Player_Pro_Vimeo()->get_vimeo($src);
      if( $objVideo && !empty($objVideo->request->text_tracks) ) {

        $active_track = array_filter(
          $objVideo->request->text_tracks,
          array( $this, 'find_first_active_track' )
        );

        // Fall back to the first track
        if ( ! empty( $active_track ) ) {
          $active_track = array_values( $active_track );
          $found_track = $active_track[0];
        } else {
          $found_track = $objVideo->request->text_tracks[0];
        }

        if ( $found_track && ! empty( $found_track->url ) ) {
          $transcriptUrl = $found_track->url;
          if( stripos($transcriptUrl,'http://') !== 0 && stripos($transcriptUrl,'https://') !== 0 ) {
            $transcriptUrl = 'https://vimeo.com' . $transcriptUrl;
          }
        }
      }
    } else {
      $transcriptUrl = apply_filters( 'fv_flowplayer_resource', $transcript );
    }

    return $transcriptUrl;
  }

  private function get_transcript_cache($transcriptUrl, $id, $src, $lang = '') {
    $transcript_expire_key = 'transcript_expire';
    $transcript_text_key = 'transcript_text';
    $transcript_src_key = 'transcript_src';
    $transcript_urlcached_key = 'transcript_urlcached';

    if( !empty($lang) ) {
      $transcript_expire_key.= '_'. $lang;
      $transcript_text_key.= '_'. $lang;
      $transcript_src_key.= '_'. $lang;
      $transcript_urlcached_key .= '_' . $lang;
    }

    if ( !empty($transcriptUrl) ) { // ignore auto
      if( $id ) { // db video
        global $FV_Player_Db;

        $objVideo = new FV_Player_Db_Video( $id, array(), $FV_Player_Db );
        $text = $objVideo->getMetaValue( $transcript_text_key, true );
        $url_cached = $objVideo->getMetaValue( $transcript_urlcached_key, true );
        $last_check = $objVideo->getMetaValue( $transcript_expire_key, true );

        // check if auto, get transcript url
        if( strcmp($transcriptUrl, 'auto') === 0 ) {
          $transcriptUrl = $this->get_transcript_url($transcriptUrl, $src);

          // Did the transcript URL change?
          if( strcmp( $url_cached, $this->get_transcript_base_url( $transcriptUrl ) ) != 0 ) {
            $last_check = false;
          }
        }

        $local = $this->is_local($transcriptUrl);

        if( empty($last_check) || $local ) { // first run or local file
          $text = $this->get_transcript_text($transcriptUrl, $src, $local);

          if( $local ) {
            $objVideo->deleteMetaValue( $transcript_expire_key );
            $objVideo->deleteMetaValue( $transcript_text_key );
            return $text;
          }

          if( !empty($text) ) {
            $objVideo->updateMetaValue( $transcript_expire_key, time() + 900 );
            $objVideo->updateMetaValue( $transcript_text_key, $text );
            $objVideo->updateMetaValue( $transcript_urlcached_key, $this->get_transcript_base_url( $transcriptUrl ) );
          }
        }

        // check if older than 15 minutes or if empty and older than 1 minute
        if( !empty($last_check) && ( (time() - intval($last_check) > 900) || ( empty($text) && time() - intval($last_check) > 60 ) ) ) {
          $text = $this->get_transcript_text($transcriptUrl, $src);
          if( !empty($text) ) {
            $objVideo->updateMetaValue( $transcript_expire_key, time() + 900 );
            $objVideo->updateMetaValue( $transcript_text_key, $text );
            $objVideo->updateMetaValue( $transcript_urlcached_key, $this->get_transcript_base_url( $transcriptUrl ) );
          }
        }

        if ( empty($text) ) {
          $text = '';
          if(!$local) $objVideo->updateMetaValue( $transcript_expire_key, time() + 60 ); // 1min cache , if no text
        }

        return $text;

      } else {
        $option_name = 'fv_player_pro_transcript_' . sanitize_title( $this->get_transcript_base_url($transcriptUrl) );
        $option = get_option($option_name, array('transcript_expire' => 0, 'text' => '', 'src' => '', 'transcript_url' => ''));
        $local = $this->is_local($transcriptUrl);

        if ( empty($option['transcript_expire']) || $local ) { // first time or local
          $text = $this->get_transcript_text($transcriptUrl, $src, $local);
          $option['text'] = $text;

          if( $local ) {
            $option['transcript_expire'] = 0;
            delete_option($option_name);
            return $text;
          }

          if( !empty($text) ) {
            $option['transcript_expire'] = time() + 900;
          }
        }

        if ( !empty($option['transcript_expire']) && ( ( time() - intval($option['transcript_expire']) ) || ( $option['text'] && time() - intval($option['transcript_expire']) > 60 ) ) ) {
          $text = $this->get_transcript_text($transcriptUrl, $src);
          $option['text'] = $text;
          if( !empty($option['text']) ) {
            $option['transcript_expire'] = time() + 900;
          }
        }

        if ( (!isset($option['src']) || empty($option['src']) && $src) ) {
          $option['src'] = $src;
        }

        if ( !isset($option['transcript_url']) || empty($option['transcript_url']) ) {
          $option['transcript_url'] = $transcriptUrl;
        }

        if( empty($option['text']) ) {
          $option['text'] = '';
          if(!$local) $option['transcript_expire'] = time() + 60; // 1min cache , if no text
        }

        update_option( $option_name, $option, false );

        return $option['text'];
      }
    }

    return '';
  }

  /**
   * Get transcript text from local file or remote URL
   *
   * @param string $transcriptUrl
   * @param string $src
   * @param bool $local
   *
   * @return string|false transcript text
   */
  private function get_transcript_text($transcriptUrl, $src, $local = false) {
    $transcript_text = '';

    // handle URL without domain
    $aURL = parse_url($transcriptUrl);
    if( !isset($aURL['host']) ) {
      $aHome = parse_url(home_url());
      $transcriptUrl = home_url($transcriptUrl);
      if( isset($aHome['path']) ) {
        $transcriptUrl = str_replace( $aHome['path'], '', $transcriptUrl );
      }
    }

    // check if is local file, if yes, use file_get_contents
    if( $local ) {
      $upload_dir = wp_upload_dir();

      $transcriptPath = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $transcriptUrl );

      if( file_exists($transcriptPath) ) {
        $transcript_text = file_get_contents($transcriptPath);
        return $transcript_text;
      }

    }

    $response = wp_remote_get($transcriptUrl);

    if ( stripos($transcriptUrl,'vimeo.com') !== false ) {
      FV_Player_Pro_Vimeo()->log_details( " getting transcript for: ".$src.", on ".$_SERVER['REQUEST_URI']."\n", $response );
    }
    if ( !is_wp_error($response) ) {
      // You might still get "<h1>Error 410 Gone</h1>" from Vimeo
      $good_transcript = !empty($response['response']['code']) && '200' == $response['response']['code'];
      if( $good_transcript ) {
        $transcript_text = $response['body'];
      }

      FV_Player_Pro_Vimeo()->log_details( " is it a good transcript? ", $good_transcript );
    }

    return $transcript_text; // raw transcript
  }

  private function is_local($transcriptUrl) {
    if( parse_url(site_url() , PHP_URL_HOST) == parse_url($transcriptUrl, PHP_URL_HOST) ) {
      return true;
    }

    return false;
  }

  /**
   * Transcript URL without the query arguments, if any.
   */
  function get_transcript_base_url( $transcriptUrl ) {
    if ( $query_string = strpos( $transcriptUrl, "?" ) ) {
      return substr( $transcriptUrl, 0, $query_string );
    } else {
      return $transcriptUrl;
    }
  }

  private function get_lang_from_metakey( $metakey ) {
    $aParts = explode( '_', $metakey );

    if( count($aParts) > 2 ) {
      return $aParts[2];
    }

    return '';
  }

  public function update_transcript_cache() {
    global $wpdb;

    // get videos with transcript metadata
    $aVideos = $wpdb->get_results( "SELECT `{$wpdb->prefix}fv_player_videos`.id as id, `{$wpdb->prefix}fv_player_videos`.src as src FROM `{$wpdb->prefix}fv_player_videos` INNER JOIN `{$wpdb->prefix}fv_player_videometa` ON `{$wpdb->prefix}fv_player_videos`.id = `{$wpdb->prefix}fv_player_videometa`.id_video WHERE `{$wpdb->prefix}fv_player_videometa`.meta_key LIKE 'transcript_src%' GROUP BY id" );
    if( $aVideos ) {
      foreach( $aVideos AS $objVideo ) {
        global $FV_Player_Db;

        $id = $objVideo->id;
        $src = $objVideo->src;
        $objVideo = new FV_Player_Db_Video( $id, array(), $FV_Player_Db );

        foreach ($objVideo->getMetaData() as $meta) {
          if (strpos($meta->getMetaKey(), 'transcript_src') !== false) { // search for transcript_src*
            $lang = $this->get_lang_from_metakey($meta->getMetaKey());

            if( !empty($lang) ) {
              $lang = '_' . $lang;
            }

            $transcript_src = $meta->getMetaValue();
            $last_check = $objVideo->getMetaValue('transcript_expire'. $lang, true);

            if( $last_check && intval($last_check) + 900 > time() ) { // update every 15min only
              continue;
            }

            $url = $this->get_transcript_url($transcript_src, $src);

            if( $this->is_local($url) ) { // ignore local files
              continue;
            }

            $text = $this->get_transcript_text($url, $src);

            if( !empty($text) ) {
              $objVideo->updateMetaValue( 'transcript_expire' . $lang , time() + 900 );
              $objVideo->updateMetaValue( 'transcript_text' . $lang , $text );
              $objVideo->updateMetaValue( 'transcript_urlcached' . $lang, $this->get_transcript_base_url( $url ) );
            } else {
              $objVideo->updateMetaValue( 'transcript_expire' . $lang , time() + 60 );
            }
          }
        }
      }
    }

    // get data with transcript in wp_options - shortcodes only
    $aOptions = $wpdb->get_results( "SELECT * FROM $wpdb->options WHERE option_name LIKE 'fv_player_pro_transcript_src%' ");

    if($aOptions) {
      foreach( $aOptions as $option ) {
        $values = unserialize($option->option_value); // need to unserialize wp_options
        $last_check = $values['transcript_expire'];
        $option_name = $option->option_name;

        if( $last_check && intval($last_check) + 900 > time() ) { // update every 15min only
          continue;
        }

        $url = $values['transcript_url'];
        $src = $values['src'];
        $text = $values['text'];

        if( $this->is_local($url) ) { // ignore local files
          continue;
        }

        $option_new = $this->get_transcript_text($url, $src);
        if(!empty($option_new)) {
          update_option( $option_name, array('transcript_expire' => time() + 900, 'text' => $option_new, 'src' => $src, 'transcript_url' => $url), false );
        } else {
          update_option( $option_name, array('transcript_expire' => time() + 60, 'text' => $text, 'src' => $src, 'transcript_url' => $url), false );
        }

      }
    }
  }

  function find_first_active_track( $track ) {
    return ! empty( $track->active ) && $track->active;
  }

  public function fv_cron_schedules( $schedules ) {
    if( !isset($schedules["15min"]) ) {
      $schedules["15min"] = array(
        'interval' => 15*60,
        'display' => __('Once every 15 minutes')
        );
    }
    return $schedules;
  }

  public function cron_init() {
    if ( !wp_next_scheduled( 'fv_player_pro_update_transcript_cache' ) ) {
      wp_schedule_event( time(), '15min', 'fv_player_pro_update_transcript_cache' );
    }
  }

}

global $FV_Player_Pro_Transcript;
$FV_Player_Pro_Transcript = new FV_Player_Pro_Transcript;

endif;
