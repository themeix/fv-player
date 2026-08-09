<?php
if( !class_exists('FV_Player_Pro_Timeline_Previews') ) :

  class FV_Player_Pro_Timeline_Previews {
    function __construct() {
      add_action( 'admin_init', array( $this, 'register_meta_boxes' ), 20 );
      add_action( 'fv_flowplayer_shortcode_editor_subtitles_tab_prepend', array( $this, 'shortcode_editor_timeline_preview_append'), 11);
      add_filter( 'fv_player_item', array( $this, 'add_sprite_link' ), 10, 3 );
      add_filter( 'fv_player_pro_localize_script_options', array( $this, 'localize_global_values' ), 10, 2 );
      add_filter( 'fv_player_editor_subtitle_fields', array( $this, 'shortcode_subtitles_tab_fields' ), 11 );
    }

    function add_sprite_link( $aItem, $index, $aArgs ) {
      global $fv_fp;

      // TODO: Support preview
      $timeline_previews = false;

      if (method_exists($fv_fp,'current_video') && $fv_fp->current_video() && $fv_fp->current_video()->getMetaData()) {
        foreach ($fv_fp->current_video()->getMetaData() as $meta) {
          if ($meta->getMetaKey() == 'timeline_previews') {
            $timeline_previews = $meta->getMetaValue();
            break;
          }
        }
      }

      if( !empty($aArgs['timeline_previews']) && $index == 0 ) {
        $timeline_previews = $aArgs['timeline_previews'];
      }

      if( $timeline_previews ) {
        $values = array( array( 'src' => apply_filters( 'fv_flowplayer_resource', $timeline_previews ) ) );

        if( preg_match('/\.vtt$/', $timeline_previews, $matches ) ) { // vtt
          $aItem['timeline_vtt'] = $values;
        } else { // splash img
          $aItem['timeline_previews'] = $values;
        }
      }

      return $aItem;
    }

    /*
    Deprecated, only to be used if already configured
    */
    function localize_global_values( $aOptions) {
      global $FV_Player_Pro;
      if(
        $FV_Player_Pro->_get_option( array('pro','timeline_previews_count') ) ||
        $FV_Player_Pro->_get_option( array('pro','timeline_previews_interval') ) ||
        $FV_Player_Pro->_get_option( array('pro','timeline_previews_height') ) ||
        $FV_Player_Pro->_get_option( array('pro','timeline_previews_width') )
      ) {
        $aOptions['timeline_previews'] = array(
          'count' => $FV_Player_Pro->_get_option( array('pro', 'timeline_previews_count') ) ,
          'interval' => $FV_Player_Pro->_get_option( array('pro','timeline_previews_interval') ),
          'height' => $FV_Player_Pro->_get_option( array('pro','timeline_previews_height') ),
          'width' => $FV_Player_Pro->_get_option( array('pro','timeline_previews_width') ),
        );
      }
      return $aOptions;
    }

    function options() {
      global $fv_fp;
      ?>
        <p>These settings are now deprecated, please use VTT thumbnails instead.</p>
        <table class="form-table2">
          <tr>
            <?php $fv_fp->_get_input_text( array( 'name' => __('Column count', 'fv-player-pro'), 'key' => array('pro', 'timeline_previews_count'), 'help' => __('Total count of columns in sprite', 'fv-player-pro') ) ); ?>
          </tr>
          <tr>
            <?php $fv_fp->_get_input_text( array( 'name' => __('interval', 'fv-player-pro'), 'key' => array('pro','timeline_previews_interval'), 'help' => __('Interval between thumbnails (default is 1)', 'fv-player-pro') ) ); ?>
          </tr>
          <tr>
            <?php $fv_fp->_get_input_text( array( 'name' => __('Width', 'fv-player-pro'), 'key' => array('pro','timeline_previews_width'), 'help' => __('Width of single thumbnail (no total width of sprite)', 'fv-player-pro') ) ); ?>
          </tr>
          <tr>
            <?php $fv_fp->_get_input_text( array( 'name' => __('Height', 'fv-player-pro'), 'key' => array('pro','timeline_previews_height'), 'help' => __('Height of single thumbnail (no total height of sprite)', 'fv-player-pro') ) ); ?>
          </tr>
          <tr>
            <td colspan="4">
            <a class="fv-wordpress-flowplayer-save button button-primary" href="#"><?php _e('Save', 'fv-wordpress-flowplayer'); ?></a>
            </td>
          </tr>
        </table>
      <?php
    }

    /*
    Deprecated, only to be used if already configured
    */
    function register_meta_boxes() {
      global $FV_Player_Pro;
      if(
        $FV_Player_Pro->_get_option( array('pro','timeline_previews_count') ) ||
        $FV_Player_Pro->_get_option( array('pro','timeline_previews_interval') ) ||
        $FV_Player_Pro->_get_option( array('pro','timeline_previews_height') ) ||
        $FV_Player_Pro->_get_option( array('pro','timeline_previews_width') )
      ) {
        add_meta_box( 'fv_player_pro_timeline_previews', __('Timeline Previews', 'fv-player-pro'), array( $this, 'options' ), 'fv_flowplayer_settings', 'normal', 'low' );
      }
    }

    function shortcode_editor_timeline_preview_append() {
      if( !function_exists('fv_player_editor_input') ):
        $fv_flowplayer_conf = get_option( 'fvwpflowplayer' );
        $allow_uploads = false;
        if( isset($fv_flowplayer_conf["allowuploads"]) && $fv_flowplayer_conf["allowuploads"] == 'true' ) {
          $allow_uploads = $fv_flowplayer_conf["allowuploads"];
          $upload_field_class = ' with-button';
        } else {
          $upload_field_class = '';
        }

        ?>
          <tr>
              <th scope="row" class="label"><label class="alignright"><?php _e('Timeline Previews', 'fv_flowplayer'); ?></label></th>
              <td class="field fv-fp-timeline-preview" colspan="2">
                  <input type="text" class="text<?php echo $upload_field_class; ?> fv_wp_flowplayer_field_timeline_previews" name="fv_wp_flowplayer_field_timeline_previews" value=""/>
                <?php if ($allow_uploads == 'true') { ?>
                    <a class="button add_media" href="#"><span class="wp-media-buttons-icon"></span> <?php _e('Add VTT', 'fv_flowplayer'); ?></a>
                <?php }; ?>
              </td>
          </tr>
          <?php
      endif;
    }

    function shortcode_subtitles_tab_fields($fields) {
      $fields['video']['items'][] = array(
        'label' => __('Timeline Previews', 'fv-wordpress-flowplayer'),
        'name' => 'timeline_previews',
        'browser' => true,
        'type' => 'text',
        'visible' => true,
        'video_meta' => true,
      );

      return $fields;
    }

  }

global $FV_Player_Pro_Timeline_Previews;
$FV_Player_Pro_Timeline_Previews = new FV_Player_Pro_Timeline_Previews;

endif;
