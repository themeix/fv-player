/*global fv_player_peertube_private*/

jQuery( function($) {

  function fv_flowplayer_peertube_private_browser_load_assets(bucket, path, search) {
    var
      $this = jQuery(this),
      $media_frame_content = jQuery('.media-frame-content:visible'),
      $overlay_div = jQuery('#fv-player-shortcode-editor-preview-spinner').clone().css({
        'height': '100%'
      }),
      ajax_data = {
        action: "load_peertube_private_assets",
      };

    $this.addClass('active').siblings().removeClass('active');

    // replace content by the new DOS content
    $media_frame_content.html($overlay_div);

    // if (typeof bucket === 'string' && bucket) {
    //   ajax_data['bucket'] = bucket;
    // }
    // if (typeof path === 'string' && path) {
    //   ajax_data['path'] = path;
    // }

    if (search) {
      ajax_data['search'] = search;
    } else {
      delete(ajax_data['search']);
    }

    jQuery.post(ajaxurl, ajax_data, function (ret) {
      var renderOptions = {};

      // add errors, if any
      if (ret.err) {
        renderOptions['errorMsg'] = ret.err;
      }

      $media_frame_content.html(renderBrowserPlaceholderHTML(renderOptions));

      fv_flowplayer_browser_browse(ret.items, {
        'breadcrumbs' : 1,
        'ajaxSearchCallback': function() {
          fv_flowplayer_peertube_private_browser_load_assets(false, false, jQuery('#media-search-input').val());
        }
      });
    });

    return false;
  }

  $(document).on("mediaBrowserOpen", function () {
    fv_flowplayer_media_browser_add_tab('fv_flowplayer_peertube_private_browser_media_tab', 'Peertube: ' + fv_player_peertube_private.tab_name, fv_flowplayer_peertube_private_browser_load_assets);
  });
});
