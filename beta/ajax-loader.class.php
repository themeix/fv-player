<?php

if( !class_exists('FV_Player_Pro_Ajax_Loader') ) :

abstract class FV_Player_Pro_Ajax_Loader {

  var $aDomains;

  var $aSecureTokens;

  var $key = false;

  var $title = false;

  var $help_link = false;

  var $is_live = false;

  var $regexDomain = false;

  function __construct( $args ) {
    if( !empty($args['key']) ) $this->key = $args['key'];
    if( !empty($args['title']) ) $this->title = $args['title'];
    if( !empty($args['help_link']) ) $this->help_link = $args['help_link'];

    if( !$this->aDomains && !$this->aSecureTokens ) {
      add_action( 'admin_init', array( $this, 'register_meta_boxes' ), 20 );
      add_action( 'admin_init', array( $this, 'fix_bad_options' ), 19 );
    }

    add_filter( 'plugins_loaded', array( $this, 'load_options' ), 8 );
    add_filter( 'get_user_option_closedpostboxes_fv_flowplayer_settings_hosting', array( $this, 'close_hosting_metabox'), 13 );
  }

  function ajax() {
    if( isset($_POST['action']) && $_POST['action'] == 'fv_fp_get_video_url' ) {

      if( isset($_POST['is_live']) && $_POST['is_live'] ) $this->is_live = true;

      // Safari detection to avoid broken video start if video plays without signature
      $agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '';
      $is_safari = !empty($_SERVER['HTTP_X_PLAYBACK_SESSION_ID']) && stripos($agent,'Mac OS X') !== false && preg_match("/Version\/[\d\.]+.*Safari/", $agent);

      foreach( $this->aDomains AS $i => $sDomains ) {
        $aDomains = explode(',',$sDomains);
        foreach( $aDomains AS $sDomain ) {
          if( !trim($sDomain) ) {
            continue;
          }

          foreach( $_POST['sources'] AS $key => $aVideo ) {
            if( !isset($aVideo['src']) || !isset($aVideo['type']) ) continue;

            if(
              stripos($aVideo['src'], $sDomain) !== false ||
              $this->regexDomain && preg_match( '~'.$sDomain.'~', $aVideo['src'], $matches )
            ) {
              $secureToken = isset($this->aSecureTokens[$i]) ? $this->aSecureTokens[$i] : '';
              $originalSrc = $aVideo['src'];
              
              // For Safari, ensure we always apply the signature to avoid broken video start
              if( $is_safari && !empty($secureToken) ) {
                $aVideo['src'] = $this->secure_link($aVideo['src'], $secureToken);
              } else if( !empty($secureToken) ) {
                $aVideo['src'] = $this->secure_link($aVideo['src'], $secureToken);
              }
              
              $_POST['sources'][$key] = $aVideo;

              $subtitles = $this->get_subtitles($originalSrc, $secureToken);
              if( !empty($subtitles) ) {
                $_POST['subtitles'] = $subtitles;
              }

              $timeline_previews = $this->get_timeline_previews($originalSrc, $secureToken);
              if ( !empty( $timeline_previews ) ) {
                $_POST['timeline_previews'] = $timeline_previews;
              }

            }
          }
        }
      }

    }

  }


  function args( $args ) {
    // add the query arg you use in URL into this array
    return $args;
  }


  function domains( $aDomains ) {
    foreach( $this->aDomains AS $sDomains ) {
      $aTemp = explode(',',$sDomains);
      foreach( $aTemp AS $sDomain ) {
        if( $sDomain ) $aDomains[] = $sDomain;
      }
    }

    return $aDomains;
  }


  function fix_bad_options() {
    if( $this->key == 'bunnycdn' ) {
      $option = get_option('fvwpflowplayer');
      if( isset($option['pro']) && isset($option['pro']['_domain']) ) {
        $option['pro'][$this->key.'_domain'] = $option['pro']['_domain'];
        unset($option['pro']['_domain']);
        update_option('fvwpflowplayer', $option);
      }
      if( isset($option['pro']) && isset($option['pro']['_secure_token']) ) {
        $option['pro'][$this->key.'_secure_token'] = $option['pro']['_secure_token'];
        unset($option['pro']['_secure_token']);
        update_option('fvwpflowplayer', $option);
      }
    }
  }


  function get_backend_link( $url, $args, $ttl = false ) {
    if( is_array($args) && isset($args['dynamic']) && $args['dynamic'] ) {
      $bFound = false;
      foreach( $this->aDomains AS $i => $sDomains ) {
        $aDomains = explode(',',$sDomains);
        foreach( $aDomains AS $sDomain ) {
          if( !empty( $sDomain ) && ( stripos($url,$sDomain) !== false || $this->regexDomain && preg_match( '~'.$sDomain.'~', $url, $matches ) ) ) {
            $bFound = true;
            $secureToken = isset($this->aSecureTokens[$i]) ? $this->aSecureTokens[$i] : '';
            $url = $this->secure_link($url, $secureToken, $ttl);
          }
        }
      }
    }

    return $url;
  }


  function get_backend_link_long( $url ) {
    return $this->get_backend_link($url, array( 'dynamic' => true ), 172800);
  }


  function get_domains() {
    global $fv_fp;
    if( isset($fv_fp->conf['pro']) && isset($fv_fp->conf['pro'][$this->key.'_domain']) ) {
      return array( $fv_fp->conf['pro'][$this->key.'_domain'] );
    }
    return false;
  }


  function get_secure_tokens() {
    global $fv_fp;
    if( isset($fv_fp->conf['pro']) && isset($fv_fp->conf['pro'][$this->key.'_secure_token']) ) {
      return array( $fv_fp->conf['pro'][$this->key.'_secure_token'] );
    }
    return false;
  }

  function get_subtitles( $video_id, $token ) {
    return false;
  }

  function get_timeline_previews( $video_id, $token ) {
    return false;
  }

  public function load_cache( $video_id, $allow_expired = false, $allow_empty = false ) {
    $cache_name = $this->get_cache_name( $video_id );

    $cached_value = get_option( $cache_name, array() );

    // check value and if not expired,
    if( !empty($cached_value) && ($cached_value['value'] || $allow_empty ) && ( $cached_value['expire'] > time() || $allow_expired ) ) {
      return $cached_value['value'];
    }

    return false;
  }


  public function store_cache( $video_id, $cache_value, $new_cache_expire = 3600, $old_cache_expire = 900, $store_empty = false ) {
    $cache_name = $this->get_cache_name( $video_id );
    // default values if no old cache
    $save_value = false;
    $expire = time() + 60; // 1min if no cache

    if( !empty($cache_value) || $store_empty ) { // store new cache, save for 1h
      $save_value = $cache_value;
      $expire = time() + $new_cache_expire;
    } else { // check for old cache
      $old_cache = $this->load_cache( $cache_name, true );

      if( !empty( $old_cache ) && $old_cache['value'] ) { // use old cache, save for 15 min
        $save_value = $old_cache['value'];
        $expire = time() + $old_cache_expire;
      }
    }

    update_option( $cache_name, array( 'value' => $save_value, 'expire' => $expire ), false );

    return $cache_value;
  }


  public function get_cache_name( $video_id ) {
    $cache_name = sanitize_title( "fv-player-pro-cache-".$this->key."-".$video_id );
    return $cache_name;
  }


  function load_options() {
    global $fv_fp;
    if( empty($fv_fp) ) return;

    if( !$this->aDomains ) {
      $this->aDomains = $this->get_domains();
    }
    if( !$this->aSecureTokens ) {
      $this->aSecureTokens = $this->get_secure_tokens();
    }

    if( $this->aDomains && $this->aSecureTokens ) {
      add_filter( 'fv_player_pro_video_ajaxify_domains', array( $this, 'domains'), 999, 2 );
      add_filter( 'fv_player_pro_video_ajaxify_args', array( $this, 'args'), 999, 2 );
      add_action( 'plugins_loaded', array( $this, 'ajax' ), 9 );
      add_filter( 'fv_flowplayer_video_src', array( $this, 'get_backend_link'), 10, 2 );

      add_filter( 'fv_flowplayer_splash', array( $this, 'get_backend_link_long') );
      add_filter( 'fv_flowplayer_playlist_splash', array( $this, 'get_backend_link_long') );
      add_filter( 'fv_flowplayer_resource', array( $this, 'get_backend_link_long') );
    }
  }


  function options() {
    global $fv_fp;
    ?>
    <table class="form-table2" style="margin: 5px; ">
      <?php
      $fv_fp->_get_input_text( array(
        'key' => array( 'pro', $this->key.'_domain' ),
        'name' => __('Domain', 'fv-player-pro'),
        'first_td_class' => 'first',
        'help' => __('You can enter multiple domains separated by <code>,</code>.', 'fv-player-pro')
      ) );

      $fv_fp->_get_input_text( array(
        'key' => array( 'pro', $this->key.'_secure_token' ),
        'name' => __('Secure Token', 'fv-player-pro'),
        'secret' => true
      ) );
      ?>
      <!--<tr>
        <td style="vertical-align:top"><label for="pro[<?php echo $this->key; ?>_fallback]"><?php _e('Fallback Domain', 'fv-player-pro'); ?>:</label></td>
        <td>
          <input type="text" size="40" name="pro[<?php echo $this->key; ?>_fallback]" id="pro[<?php echo $this->key; ?>_fallback]" value="<?php if( isset($fv_fp->conf['pro'][$this->key.'_fallback']) && strlen(trim($fv_fp->conf['pro'][$this->key.'_fallback'])) ) echo trim($fv_fp->conf['pro'][$this->key.'_fallback']); ?>" />
          <p class="description"><?php _e('Will be used for Download feature, you can use some other CDN which you have configured on this screen.', 'fv-player-pro'); ?></p>
        </td>
      </tr>-->
      <tr>
        <td colspan="4">
          <a class="fv-wordpress-flowplayer-save button button-primary" href="#" style="margin-top: 2ex;"><?php _e('Save', 'fv-wordpress-flowplayer'); ?></a>
          <?php if( !empty($this->help_link) ): ?>
            <a class="button fv-help-link" href="<?php echo $this->help_link; ?>" target="_blank">Help</a>
          <?php endif; ?>
        </td>
      </tr>
    </table>
    <?php
  }


  function register_meta_boxes() {
    add_meta_box( 'fv_player_pro_'.$this->key, $this->title, array( $this, 'options' ), 'fv_flowplayer_settings_hosting', 'normal', 'low' );
  }

  function close_hosting_metabox( $closed ) {
    global $fv_fp;

    $domain = trim( $fv_fp->_get_option( array('pro', $this->key . '_domain') ) );
    $token = trim( $fv_fp->_get_option( array('pro',  $this->key . '_secure_token') ) );

    if( !empty($domain) || !empty($token) ) return $closed;

    $to_close = array( 'fv_player_pro_'.$this->key );

    if( is_array($closed) ) {
      $closed = array_unique( array_merge( $closed, $to_close ) );
    } else if( false === $closed ) {
      $closed = $to_close;
    }

    return $closed;
  }


  abstract function secure_link( $url, $secret, $ttl = false );

}

endif;
