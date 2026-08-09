<?php

// With this the wp-load.php takes 20 ms, without it about 900 ms. Of course it largely depends on what/how many plugins you use.
if( !defined('SHORTINIT') ) {
  define('SHORTINIT',true);
}

// FV Player Pro Release
if( file_exists('../../../wp-load.php') ) {
  require('../../../wp-load.php');

// FV Player Pro Beta
} else if( file_exists('../../../../wp-load.php') ) {
  require('../../../../wp-load.php');
}

// including what's necessary for user login status, base FV Player and FV Player Pro to load:
require_once( ABSPATH . WPINC . '/capabilities.php' );
require_once( ABSPATH . WPINC . '/class-wp-roles.php' );
require_once( ABSPATH . WPINC . '/class-wp-role.php' );
require_once( ABSPATH . WPINC . '/class-wp-user.php' );
require_once( ABSPATH . WPINC . '/user.php' );
require_once( ABSPATH . WPINC . '/pluggable.php' );
require_once( ABSPATH . WPINC . '/formatting.php' );
require_once( ABSPATH . WPINC . '/link-template.php' );
require_once( ABSPATH . WPINC . '/shortcodes.php' );
require_once( ABSPATH . WPINC . '/general-template.php' );
require_once( ABSPATH . WPINC . '/class-wp-session-tokens.php' );
require_once( ABSPATH . WPINC . '/class-wp-user-meta-session-tokens.php' );
require_once( ABSPATH . WPINC . '/meta.php' );
require_once( ABSPATH . WPINC . '/kses.php' );
require_once( ABSPATH . WPINC . '/rest-api.php' );

// and of course the WP_HTTP
require_once( ABSPATH . WPINC . '/http.php' );
if( file_exists( ABSPATH . WPINC . '/class-wp-http.php' ) ) {
  require_once( ABSPATH . WPINC . '/class-wp-http.php' );
} else {
  require_once( ABSPATH . WPINC . '/class-http.php' );
}
require_once( ABSPATH . WPINC . '/class-wp-http-streams.php' );
require_once( ABSPATH . WPINC . '/class-wp-http-curl.php' );
require_once( ABSPATH . WPINC . '/class-wp-http-proxy.php' );
require_once( ABSPATH . WPINC . '/class-wp-http-cookie.php' );
require_once( ABSPATH . WPINC . '/class-wp-http-encoding.php' );
require_once( ABSPATH . WPINC . '/class-wp-http-response.php' );
require_once( ABSPATH . WPINC . '/class-wp-http-requests-response.php' );
require_once( ABSPATH . WPINC . '/class-wp-http-requests-hooks.php' );

/**
 * WordPress 6.3.2 started to call this action for the Footnotes Block:
 * https://github.com/WordPress/wordpress-develop/commit/048c80951f589d17b4ec776eedab88956a3d2ebb
 *
 * It would only show a PHP warning in our case as we do not include WPINC/blocks.php
 */
remove_action( 'set_current_user', '_wp_footnotes_kses_init' );

// Avoid showing PHP warnings or notices, as it might break the HLS m3u8 output, but keep showing fatal errors
error_reporting( E_CORE_ERROR | E_COMPILE_ERROR | E_ERROR | E_PARSE | E_USER_ERROR | E_RECOVERABLE_ERROR );
ini_set( 'display_errors', 1 );

// Without this plugins_url() won't work
wp_plugin_directory_constants();
$GLOBALS['wp_plugin_paths'] = array();

// Without this the user login status won't work
wp_cookie_constants();

// This function is hard to make work and FV Player might need it in constructor
if( !function_exists('__') ) {
  function __() {
    return false;
  }
}

// Load FV Player and FV Player Pro
foreach ( wp_get_active_and_valid_plugins() as $plugin ) {
  if(
    stripos($plugin,'/fv-player') !== false && stripos($plugin,'/fv-player.php') !== false ||
    stripos($plugin,'/fv-wordpress-flowplayer') !== false && stripos($plugin,'/flowplayer.php') !== false ||
    stripos($plugin,'/fv-wordpress-flowplayer') !== false && stripos($plugin,'/fv-player.php') !== false ||
    stripos($plugin,'/fv-player') !== false && stripos($plugin,'/flowplayer.php') !== false ||
    stripos($plugin,'/fv-player') !== false && stripos($plugin,'/fv-player.php') !== false ||
    stripos($plugin,'/fv-player-pro') !== false && stripos($plugin,'/fv-player-pro.php') !== false
  ) {
	  wp_register_plugin_realpath( $plugin );
    include_once( $plugin );
  }
}
unset( $plugin );


// TODO: Somehow init all the available URL tokens
global $FV_Player_Pro_BunnyCDN;
$FV_Player_Pro_BunnyCDN->load_options();

global $FV_Player_Pro_Bunny_Stream;
$FV_Player_Pro_Bunny_Stream->load_options();

do_action('fv_player_shortinit_loaded');

// By adding all of the above requires we get to about 70 ms.

FV_Player_Pro_Stream_Loader()->stream_loader();
