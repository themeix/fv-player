/*global fv_player_pro_timeline_previews_api */

jQuery( function($) {

  if ( ! fv_player_pro_timeline_previews_api.enabled ) {
    return;
  }

  function stop_interval() {
    if( typeof fv_player_pro_timeline_previews_api.ajax_interval != 'undefined' ) {
      clearInterval(fv_player_pro_timeline_previews_api.ajax_interval);
      delete fv_player_pro_timeline_previews_api.ajax_interval;
    }
  }

  function enable_button( enable ) {
    var field = fv_player_editor.get_field('timeline_previews_generate', true),
      button = field.find('.fv_generate_vtt_sprite'),
      spinner = field.find('#fv-player-shortcode-editor-generate-spinner');

    button.toggleClass( 'disabled', ! enable );
    spinner.toggle( ! enable);
  }

  function is_supported() {
    var button_enable = false;
    for (var vtype in fv_player_editor_matcher) {
      if (fv_player_editor_matcher[vtype].matcher.exec(fv_player_editor.get_current_video_object().src) !== null && fv_player_editor_matcher[vtype].support_thumb_generate) {
        button_enable = true;
        break;
      }
    }

    return button_enable;
  }

  function show_status_notice(status) {
    var field = fv_player_editor.get_field('timeline_previews_generate', true);

    field.find( '.fv-vtt-generate-notice' ).remove();

    var message = '';

    if ( status == 'new' ) {
      message ='Waiting To Be Processed';
    }

    if ( status == 'processing' ) {
      message ='Processing...';
    }

    if( status == 'not-supported' ) {
      message = 'Not Supported For This Video';
    }

    jQuery('<span class="fv-vtt-generate-notice">'+message+'</span>').insertAfter( field.find('.fv_generate_vtt_sprite') );
  }

  function job_check_interval(data) {
    // use interval to check job
    fv_player_pro_timeline_previews_api.ajax_interval = setInterval(function() {
      jQuery.post(fv_player_pro.ajaxurl, data, function(response) {
        var done = false;

        console.log('job_check_interval', response);

        if( response.error || !response ) {
          done = true;
        }

        var index = - 1;
        fv_player_editor.get_current_player_object().videos.forEach( function( v, k ) {
          if ( v.id == response.video_id ) {
            index = k;
          }
        });

        if( response.status == 'new' || response.status == 'processing' ) {
          show_status_notice(response.status);
        }

        if( response.status == 'downloaded' ) {
          done = true;
          // fill the field
          fv_player_editor.get_field('timeline_previews', fv_player_editor.get_tab(index, 'subtitles') ).val(response.output.vtt).trigger('change');
        }

        // show error alert
        if( response.status.indexOf('error') !== -1 || response.status.indexOf('failed') !== -1 ) {
          done = true;
          alert(response.status);
        }

        if( done ) {
          console.log('job_check_interval done');

          jQuery('.fv-vtt-generate-notice').remove();
          stop_interval();
          enable_button( true );
          return;
        }
      });
    }, 10000);
  }

  // start job
  jQuery(document).on('click', '.fv_generate_vtt_sprite', function(e) {
    if(jQuery(this).hasClass('disabled')) {
      return;
    }

    e.preventDefault();
    console.log('sending request to generate vtt/sprite');

    var src = fv_player_editor.get_field('src', true).val(),
      id_video = fv_player_editor.get_current_video_db_id(),
      title = fv_player_editor.get_field('caption', true).val();

    var data = {
      'action': 'fv_player_timeline_previews_api_submit',
      'source': src,
      'target': title,
      'id_video': id_video,
      'no_source_verify': 1,
      'ignore_duplicates': 1,
      'nonce': fv_player_pro_timeline_previews_api.job_submit_nonce
    };

    enable_button( false );

    // submit job
    jQuery.post(fv_player_pro.ajaxurl, data, function(response) {
      // periodically check status
      if( response.result.status.indexOf('error') == -1 && response.result.status.indexOf('failed') == -1  && response.id ) {
        console.log('vtt job created', response);

        // prevent duplicate intervals
        if( fv_player_pro_timeline_previews_api.ajax_interval) {
          clearInterval(fv_player_pro_timeline_previews_api.ajax_interval);
        }

        var id = response.id,
          field = fv_player_editor.get_field('timeline_previews_job_id', true);

        field.val(id);
        field.trigger('change');

        var data = {
          'action': 'fv_player_timeline_previews_api_job_check',
          'id': id,
          'nonce': fv_player_pro_timeline_previews_api.job_check_nonce
        }

        job_check_interval(data);
      } else {
        alert(response.result.status);
        enable_button( true );
      }
    });
  });

  // check running job on video open
  jQuery(document).on('fv-player-editor-video-opened', function(e, index) {
    if( index > -1 ) {
      setTimeout(function(){
        var button = jQuery('.fv-player-tab-subtitles [data-index='+index+']').find('.fv_generate_vtt_sprite');

        // check if src is supported to generate
        if(is_supported()) {
          button.removeClass('disabled');
          jQuery('.fv-vtt-generate-notice').remove();
        } else {
          button.addClass('disabled');
          show_status_notice('not-supported');
        }
      },0);

      var job_id = fv_player_editor.get_current_video_meta_value('timeline_previews_job_id'),
        status = fv_player_editor.get_current_video_meta_value('timeline_previews_job_status');

      if( job_id && status == 'processing' ) {
        enable_button( false );

        var data = {
          'action': 'fv_player_timeline_previews_api_job_check',
          'video_id': fv_player_editor.get_current_video_db_id(),
          'nonce': fv_player_pro_timeline_previews_api.job_check_nonce
        }

        job_check_interval(data);

      } else {
        enable_button( true );
      }
    } else {
      // stop
      stop_interval();
    }
  });

  // cleanup
  jQuery(document).on('fv-player-editor-init', function() {
    stop_interval();
  });

  // show/hide button on src change
  $( document ).on( 'fvp-preview-complete', function() {
    var button = fv_player_editor.get_field('timeline_previews_generate', true).find( '.fv_generate_vtt_sprite' );

    if(is_supported()) {
      button.removeClass('disabled');
      jQuery('.fv-vtt-generate-notice').remove();
    } else {
      button.addClass('disabled');
      show_status_notice('not-supported');
    }
  });

});

// metadata
(function ($) {
  ('use strict');

  jQuery(document).on('fv_flowplayer_video_meta_save', function(event, data, element_data_index, element) {
    var meta_key = false;

    if (element.id == 'timeline_previews_job_id' ) {
      meta_key = 'timeline_previews_job_id';
    }

    // if (element.id == 'timeline_previews_job_status' ) {
    //   meta_key = 'timeline_previews_job_status';
    // }

    if( !meta_key ) {
      return;
    }

    if (!data['video_meta']['video']) {
      data['video_meta']['video'] = {};
    }

    if (!data['video_meta']['video'][element_data_index]) {
      data['video_meta']['video'][element_data_index] = {};
    }

    fv_flowplayer_insertUpdateOrDeleteVideoMeta({
      data: data,
      meta_section: 'video',
      meta_key: meta_key,
      meta_index: element_data_index,
      element: element
    });
  });

  jQuery(document).on('fv_flowplayer_video_meta_load', function(event, element_meta_index, metadata, $video_data_tab , $subtitles_tab) {
    if (metadata) {
      for (var i in metadata) {
        if (metadata[i].meta_key == 'timeline_previews_job_id') {
          var field = fv_player_editor.get_field('timeline_previews_job_id', true);
          field.val(metadata[i].meta_value).attr('data-id', metadata[i].id);
        }

        if (metadata[i].meta_key == 'timeline_previews_job_status') {
          var field = fv_player_editor.get_field('timeline_previews_job_status', true);
          field.val(metadata[i].meta_value).attr('data-id', metadata[i].id);
        }
      }
    }
  });
}(jQuery));
