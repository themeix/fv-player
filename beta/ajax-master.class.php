<?php
if( !class_exists('FV_Player_Pro_Ajax_Master') ) :

final class FV_Player_Pro_Ajax_Master {
  static $instance = null;

  function __construct() {
    add_action( 'plugins_loaded', array( $this, 'ajax_serve' ), PHP_INT_MAX );
  }

  public static function _get_instance() {
    if( !self::$instance ) {
      self::$instance = new self();
    }

    return self::$instance;
  }

  function ajax_serve() {
    if( isset($_POST['action']) && $_POST['action'] == 'fv_fp_get_video_url' ) {
      echo '<FVFLOWPLAYER>';

      // TODO: Have the child classes use some proper method to register errors
      if( isset($_POST['error'])) { // check if error is set
        $all_items_failed = true;
        $error = $_POST['error'];

        unset($_POST['error']);

        // first iteration to check for item without error
        foreach($_POST['sources'] as $k => $source) {
          if( $source['src'] ) {
            $all_items_failed = false;
            break;
          }
        }

        if( !$all_items_failed ) { // we have working videos, remove faulty ones
          $this->remove_faulty_videos();
        } else { // all items failed
          $_POST['sources'] = array( 0 => array( 'src' => false, 'error' => $error ) ); // create sources with error
        }
      } else {
        $this->remove_faulty_videos();
      }

      $new_post = array();
      foreach( $_POST['sources'] AS $key => $video ) {
        $new_post['sources'][ $key ]['src']  = sanitize_url( $video['src'] );
        if ( ! empty( $video['type'] ) ) {
          $new_post['sources'][ $key ]['type'] = sanitize_text_field( $video['type'] );
        }
        if ( ! empty( $video['error'] ) ) {
          $new_post['sources'][ $key ]['error'] = sanitize_text_field( $video['error'] );
        }
      }

      if ( ! empty( $_POST['is_live'] ) ) {
        $new_post['is_live'] = sanitize_text_field( $_POST['is_live'] );
      }

      if ( ! empty( $_POST['subtitles'] ) ) {
        foreach( $_POST['subtitles'] AS $key => $subtitle ) {
          $new_post['subtitles'][ $key ]['label']   = sanitize_text_field( $subtitle['label'] );
          $new_post['subtitles'][ $key ]['src']     = sanitize_url( $subtitle['src'] );
          $new_post['subtitles'][ $key ]['srclang'] = sanitize_text_field( $subtitle['srclang'] );
          $new_post['subtitles'][ $key ]['kind']    = sanitize_key( $subtitle['kind'] );
          if ( ! empty( $subtitle['default'] ) ) {
            $new_post['subtitles'][ $key ]['default'] = sanitize_text_field( $subtitle['default'] );
          }
        }
      }

      if ( ! empty( $_POST['timeline_previews'] ) ) {
        $new_post['timeline_previews'] = $_POST['timeline_previews'];
      }

      echo json_encode( $new_post ); // send entire POST array back to the player
      echo '</FVFLOWPLAYER>';

      if (!defined('PHPUnitTestMode')) {
        exit;
      } else {
        return;
      }
    }
  }

  /**
   * Remove items with invalid src
   *
   * @return void
   */
  private function remove_faulty_videos() {
    foreach($_POST['sources'] as $k => $source) {
      if( !$source['src'] ) {
        unset($_POST['sources'][$k]);
      }
    }

    $_POST['sources'] = array_values($_POST['sources']); // reindex
  }

}

FV_Player_Pro_Ajax_Master::_get_instance();

function FV_Player_Pro_Ajax_Master() {
  return FV_Player_Pro_Ajax_Master::_get_instance();
}

endif;
