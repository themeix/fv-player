/*global fv_wp_flowplayer_shortcode_parse_arg */

if (typeof(window.fv_player_editor_matcher) == 'undefined') {
  window.fv_player_editor_matcher = {};
}

fv_player_editor_matcher.youtube = {
  matcher: /(youtube\.com|youtu\.be|youtube\-nocookie\.com)\/(shorts\/)?(watch\?(.*&)?v=|v\/|u\/|embed\/?)?(videoseries\?list=(.*)|[\w-]{11}|\?listType=(.*)&list=(.*))(.*)/i,
  update_fields: ['duration', 'caption', 'splash', 'auto_splash', 'auto_caption', 'last_video_meta_check']
};

fv_player_editor_matcher.vimeo = {
  matcher: /.+vimeo\.com\/(.*\/)?([\d]+)(.*)?/i,
  update_fields: ['duration', 'caption', 'splash', 'auto_splash', 'auto_caption', 'last_video_meta_check', 'chapters'],
  support_thumb_generate: true
};

fv_player_editor_matcher.ok_ru = {
  matcher: /ok\.ru\/video\/\d+/i,
  update_fields: ['duration', 'caption', 'splash', 'auto_splash', 'auto_caption'],
};

fv_player_editor_matcher.odysee = {
  matcher: /odysee\.com\/@.*?:.*?\/.*?:./i,
  update_fields: ['duration', 'caption', 'splash', 'auto_splash', 'auto_caption'],
};

fv_player_editor_matcher.rumble = {
  matcher: /rumble\.com\/.*?-.*?\.html/i,
  update_fields: ['duration', 'caption', 'splash', 'auto_splash', 'auto_caption'],
};

fv_player_editor_matcher.peertube = {
  matcher: /\/w\/\w+/,
  update_fields: ['duration', 'caption', 'splash', 'auto_splash', 'auto_caption'],
}

jQuery(document).trigger('fv_player_matchers_loaded');

jQuery(document).on('fv_flowplayer_shortcode_new', function() {
  jQuery('[name=fv_wp_flowplayer_field_start]').val('');
  jQuery('[name=fv_wp_flowplayer_field_end]').val('');
  jQuery('.fv_wp_flowplayer_hlskey_decoder').hide();
});

jQuery(document).on('fv_flowplayer_shortcode_parse', function(e, shortcode) {
  let sQSel = fv_player_editor.shortcode_parse_arg( shortcode, 'qsel' );
  if ( sQSel ) {
    fv_player_editor.get_field('qsel').prop( 'checked', sQSel && sQSel[1] == "true" ).trigger('change');
  }

  let sAB = fv_player_editor.shortcode_parse_arg( shortcode, 'ab' );
  if ( sAB ) {
    fv_player_editor.get_field('ab').prop( 'checked', sAB && sAB[1] == "true" ).trigger('change');
  }

  let sHflip = fv_player_editor.shortcode_parse_arg( shortcode, 'hflip' );
  if ( sHflip ) {
    fv_player_editor.get_field('hflip').prop( 'checked', sHflip[1] == "true" ).trigger('change');
  }

  let sCopyText = fv_player_editor.shortcode_parse_arg( shortcode, 'copy_text' );
  if ( sCopyText ) {
    fv_player_editor.get_field('copy_text').prop( 'checked', sCopyText && sCopyText[1] == "true" ).trigger('change');
  }

  let sAds = fv_player_editor.shortcode_parse_arg( shortcode, 'preroll' );
  if ( ! sAds ) {
    sAds = fv_player_editor.shortcode_parse_arg( shortcode, 'ads' );
  }

  if ( sAds ) {
    sAds = sAds[1];
    let iAds = parseInt(sAds);

    let field = fv_player_editor.get_field('video_ads')

    if( sAds && sAds === "random" ) {
      field[0].selectedIndex = 2;
    } else if( sAds && sAds === "no" ) {
      field[0].selectedIndex = 1;
    } else if( sAds && iAds > 0 ) {
      field[0].selectedIndex = 2 + iAds;
    }
  }

  let sAdsPost = fv_player_editor.shortcode_parse_arg( shortcode, 'postroll' );

  if ( sAdsPost ) {
    sAdsPost = sAdsPost[1];
    let iAdsPost = parseInt(sAdsPost);

    let field = fv_player_editor.get_field('video_ads_post')

    if( sAds && sAds === "random" ) {
      field[0].selectedIndex = 2;
    } else if( sAds && sAds === "no" ) {
      field[0].selectedIndex = 1;
    } else if( sAds && iAdsPost > 0 ) {
      field[0].selectedIndex = 2 + iAdsPost;
    }
  }

  let hlskey = fv_wp_flowplayer_shortcode_parse_arg( shortcode, 'hlskey' );
  if ( hlskey ) {
    fv_player_editor.get_field('hls_hlskey').val( hlskey[1] ).trigger( 'change' );
  }

  fv_wp_flowplayer_shortcode_parse_arg( shortcode, 'startend', false, fv_wp_flowplayer_start_end_parse_arg );
  fv_wp_flowplayer_shortcode_parse_arg( shortcode, 'chapters', false, fv_wp_flowplayer_chapters_parse_arg );
  fv_wp_flowplayer_shortcode_parse_arg( shortcode, 'transcript', false, fv_wp_flowplayer_transcript_parse_arg );

  fv_wp_flowplayer_shortcode_parse_arg( shortcode, 'start',false, fv_wp_flowplayer_start_end_parse_arg);
  fv_wp_flowplayer_shortcode_parse_arg( shortcode, 'end' , false, fv_wp_flowplayer_start_end_parse_arg);

} );

if( fv_player_editor_pro.video_qualities ) {

  /**
   * Show a notice about MP4 Quality Switching when the video preview finishes (when the video saves)
   */
  ( function($) {
    $( document ).on( 'fvp-preview-complete', function() {
      let video_field = fv_player_editor.get_field( 'src', true ),
        enabled = fv_player_editor.get_field('qsel').prop( 'checked' ),
        video_info = fv_player_editor.get_field('video_info'),
        sURL = video_field.val(),
        video_qualities = fv_player_editor_pro.video_qualities;

      video_info.find( '.fv-player-pro-qsel' ).remove();

      if( enabled && sURL ) {
        let sQualityHint = '';

        if ( sURL.match( /m3u8/ ) ) {
          sQualityHint = "HLS streams have the quality switching built in.";

        } else {
          let sMatched = '';
          for( let i in video_qualities ){
            if( sURL.match(i) ) {
              sQualityHint = 'Your primary video matches '+video_qualities[i]+' quality. Make sure following is available:';
              sMatched = i;
            }
          }

          for( let i in video_qualities ){
            if( i == sMatched ) continue;
            sQualityHint += '<br />'+video_qualities[i]+': <strong>'+sURL.replace(sMatched,i)+'</strong>';
          }

          if ( ! sMatched ){
            sQualityHint = "Your primary video is not matching the quality prefixes!";
          }

        }

        video_info.append( '<li class="fv-player-pro-qsel">' + sQualityHint + '</li>' );
      }

    });
  } )( jQuery );
}

function fv_wp_flowplayer_start_end_parse_arg(args) {
  /*legacy*/
  if(args[0].match(/^start=/)){
    jQuery('[name=fv_wp_flowplayer_field_start]:eq(0)').val(args[1].trim());
    return;
  }
  if(args[0].match(/^end=/)){
    if(args[1].trim().match(/-/)){
      return;
    }
    jQuery('[name=fv_wp_flowplayer_field_end]:eq(0)').val(args[1].trim());
    return;
  }
  /*end legacy*/

  var item;
  args = args[1].split(';');
  for (item in args) {
    args[item] = args[item].split('-');
    if (typeof (args[item][0]) !== 'undefined')
      jQuery('[name=fv_wp_flowplayer_field_start]:eq(' + item + ')').val(args[item][0].trim()).parents('tr').show();
    if (typeof (args[item][1]) !== 'undefined')
      jQuery('[name=fv_wp_flowplayer_field_end]:eq(' + item + ')').val(args[item][1].trim()).parents('tr').show();
  }
}

function fv_wp_flowplayer_chapters_parse_arg(args) {
  var item;
  args = args[1].split(';');
  for (item in args) {
    if (typeof (args[item]) !== 'undefined')
      jQuery('[name=fv_wp_flowplayer_field_chapters]:eq(' + item + ')').val(args[item].trim());
  }
}

function fv_wp_flowplayer_transcript_parse_arg(args) {
  var item;
  args = args[1].split(';');
  for (item in args) {
    if (typeof (args[item]) !== 'undefined')
      jQuery('[name=fv_wp_flowplayer_field_transcript]:eq(' + item + ')').val(args[item].trim());
  }
}

jQuery(document).ready(function () {

  // parse start time from YouTube links
  jQuery(document).on('keyup', '[name=fv_wp_flowplayer_field_src]', function () {
    var time = jQuery(this).val().match(/(?:vimeo|youtu).*[#?&]t=((?:\d*[hms]?){1,3})/);
    var field = jQuery(this).parents('table').find('#fv_wp_flowplayer_field_start');
    if(time && field.length){
      time = time[1].replace(/[hm]/g,':').replace(/s/,'');//.replace(/[\^^](\d{1})/g,'0$1');
      field.val(time).parents('tr').show();
      jQuery(this).val( jQuery(this).val().replace(/(\?|&|#)t=((?:\d*[hms]?){1,3})&?/,'$1').replace(/(#|&)$/,'') ); //  https://www.youtube.com/watch?t=123&v=kH6QJzmLYtw OR https://www.youtube.com/watch?v=kH6QJzmLYtw&t=123 TO https://www.youtube.com/watch?v=kH6QJzmLYtw and also https://vimeo.com/258828793?title=0&byline=0&portrait=0#t=01m46s
    }
  });

  // show or hide interface for HLS key
  function hls_decoder_show_hide(e,index) {
    var src = jQuery(this).val(),
      item = jQuery(this).parents('table');

    if( typeof(index) != "undefined" ) {
      item = jQuery('.fv-player-playlist-item[data-index='+index+']');
      src = item.find('[name=fv_wp_flowplayer_field_src]').val();
    }

    var hls_row = item.find('.fv_wp_flowplayer_hlskey_decoder');
    if( src.match(/m3u8/) ) {
      hls_row.show();
    } else {
      hls_row.hide();
    }

    jQuery('.fv_wp_flowplayer_hlskey_encrypted').hide();
  }

  jQuery(document).on('keyup', '[name=fv_wp_flowplayer_field_src]', hls_decoder_show_hide );
  jQuery(document).on('fv_flowplayer_shortcode_item_switch', hls_decoder_show_hide );

  // show the HLS key decryption tool
  jQuery(document).on('click','[name=fv_wp_flowplayer_hls_hlskey]', function() {
    jQuery(this).next().slideDown();
  });

  // HLS key decryption action
  jQuery(document).on('click', '#button-hls-decrypt', function(){
    var
      $that = jQuery(this),
      $crypt_textarea = jQuery(this).prevAll('#fv_wp_flowplayer_hlskey_cryptic:first'),
      $crypt_key = jQuery(this).parents('.fv_wp_flowplayer_hlskey_decoder').find('#fv_wp_flowplayer_hls_hlskey'),
      $label = $that.find('.label');

    $label.html('Decrypting...');
    jQuery.post(ajaxurl, { action: 'fv_fp_decrypt_hlskey', cryptic: $crypt_textarea.val() },
      function (response) {
        $label.html('Decrypt');
        if (response.length == 24) {
          $crypt_key.val(response);
        } else if (response == 'php') {
          alert('Please check Your PHP version');
        } else if (response == 'cryptic') {
          alert('Please check Your Cryptic key');
        } else if (response == 'settings') {
          alert('Please check Your Amazon Credentials an Region in plugin settings');
        } else {
          alert(response);
        }
      }).fail(function() {
        $label.html('Decrypt');
        alert( "Please check Your Amazon Credentials And crypic Key" );
      });

    return false;
  });

});


// Save chapters, HLS decryption key, timeline previews, transcript and transcript formatting preserve
(function ($) {
  ('use strict');

  $(document).on('fv_flowplayer_video_meta_save', function(event, data, element_data_index, element) {
    var meta_key = false,
      input_name = $(element).attr('name');

    if( [
      'fv_wp_flowplayer_field_chapters',
      'fv_wp_flowplayer_field_hls_hlskey',
      'fv_wp_flowplayer_field_transcript',
      'fv_wp_flowplayer_field_timeline_previews',
      'fv_wp_flowplayer_field_transcript_original_formatting'
    ].indexOf(input_name) > -1 ) {
      meta_key = input_name.replace( /fv_wp_flowplayer_field_/, '' );
    }

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
        let meta_key = metadata[i].meta_key,
          meta_value = metadata[i].meta_value;

        if (meta_key == 'transcript_original_formatting') {
          if ( meta_value ) {
            fv_player_editor.get_field('transcript_original_formatting', $subtitles_tab).prop('checked', true).attr('data-id', metadata[i].id).trigger('change');
          }

        } else if ( [ 'hls_hlskey' ].indexOf(meta_key) > -1 ) {
          fv_player_editor.get_field( meta_key, $video_data_tab ).val(meta_value).attr('data-id', metadata[i].id).trigger('change');

        } else if ( [ 'chapters', 'timeline_previews', 'transcript' ].indexOf(meta_key) > -1 ) {
          fv_player_editor.get_field( meta_key, $subtitles_tab ).val(meta_value).attr('data-id', metadata[i].id).trigger('change');
        }
      }
    }
  });

  jQuery(document).on('fv_flowplayer_player_meta_load fv_flowplayer_shortcode_item_switch', function(event, response) {
    var src = fv_player_editor.get_field('src', true).val(),
      enabled = fv_player_editor.get_field( 'download_enabled', true).prop('checked'),
      download_requires_urls = fv_player_editor.get_field('download_requires_urls', true),
      download_sd = fv_player_editor.get_field('download_sd', true),
      download_hd = fv_player_editor.get_field('download_hd', true);

    if (enabled || download_sd.val() || download_hd.val()) {
      // check if mp4 or vimeo
      if (src.match(/\.mp4/) || src.match(/vimeo\.com/)) {
        download_requires_urls.closest('.fv-player-editor-field-wrap-download_requires_urls').hide();
        download_sd.closest('.fv-player-editor-field-wrap-download_sd').hide();
        download_hd.closest('.fv-player-editor-field-wrap-download_hd').hide();
      } else {
        download_sd.parents('.fv_player_interface_hide').removeClass('fv_player_interface_hide');
        download_requires_urls.closest('.fv-player-editor-field-wrap-download_requires_urls').show();
        download_sd.closest('.fv-player-editor-field-wrap-download_sd').show();
        download_hd.closest('.fv-player-editor-field-wrap-download_hd').show();
      }
    }
  });

  // fired up by shortcode-editor in the free player
  //
  // checks whether the URL is not a source of an external playlist and enabled/disables
  // adding new videos into a this player's playlist if this is the first video in the player
  // and is an external playlist already
  jQuery(document).on('fv-player-editor-src-change', function(event, src_input_value, result) {
    // make sure our supported types of videos don't raise a false notice
    if (
      src_input_value.indexOf('vimeo.com') > -1 ||
      src_input_value.indexOf('vimeopro.com') > -1 ||
      src_input_value.indexOf('youtube.com') > -1 ||
      src_input_value.indexOf('youtu.be') > -1
    ) {
      result.supported = true;
    }

    // check how many playlist items we have currently
    var
      playlist_length = fv_player_editor.get_playlist_items_count(),
      is_youtube_playlist = ( (src_input_value.indexOf('youtube.com') > -1 || src_input_value.indexOf('youtu.be') > -1) && src_input_value.indexOf('list=') > -1 ),
      is_vimeo_playlist_style_url = /vimeo(pro)?\.com\/(channels|album|showcase)\/.*/gi.exec( src_input_value );

    // exclude https://vimeo.com/channels/staffpicks/65107797
    if( src_input_value.match(/\/channels\/[^>]+\/\d+/) ) {
      is_vimeo_playlist_style_url = false;
    }

    // make sure that if we're adding a playlist item at the first index position,
    // we hide the "+ Add playlist item" button
    if ( playlist_length == 1 && (is_youtube_playlist || is_vimeo_playlist_style_url) ) {
      jQuery('.playlist_add, .playlist_edit').hide();
    } else {
      jQuery('.playlist_add, .playlist_edit').show();
    }
  });
}(jQuery));
