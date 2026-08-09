/*global fv_player_peertube_private*/

jQuery( function($) {

  var firstLoad = true;

  function fv_flowplayer_peertube_private_browser_load_assets() {

    var
      $this = jQuery(this),
      $media_frame_content = jQuery('.media-frame-content:visible'),
      $overlay_div = jQuery('#fv-player-shortcode-editor-preview-spinner').clone().css({
        'height': '100%'
      }),
      page = 1,
      ajax_data = {
        action: "load_peertube_private_assets",
        page:   page
      },
      appending = false,
      allLoaded = false;

    $this.addClass('active').siblings().removeClass('active');

    function loadMoreFunction(force) {
      if ((!appending && !allLoaded) || force === true) {
        appending = true;
        // reset allLoaded if we're forcing a load after API error
        if (force === true) {
          allLoaded = false;
        }
        page++;
        getData();
      }
      return false;
    }

    function getData() {
      var searchVal = $('#media-search-input').val();

      // show overlay if we're not appending, otherwise append the overlay and then remove it
      if (firstLoad) {
        $media_frame_content.html($overlay_div);
      } else {
        // if we're not appending, remove all LIs from the UL
        var $ul = jQuery('#__assets_browser');
        if (!appending) {
          $ul.find('li').remove();
        }

        jQuery('#overlay-loader-li div').html($overlay_div);
      }

      if (searchVal) {
        ajax_data['search'] = searchVal;
      } else {
        delete(ajax_data['search']);
      }

      ajax_data['page'] = page;

      ajax_data['appending'] = (appending ? 1 : 0);
      ajax_data['firstLoad'] = (firstLoad ? 1 : 0);

      jQuery.post(ajaxurl, ajax_data, function (ret) {
        // don't overwrite the page if we've shown the browser for the first time already
        // ... instead, we'll be either clearing and rewriting the UL or appending data to it
        if (firstLoad) {
          var renderOptions = {};

          // add errors, if any
          if (ret.err) {
            renderOptions['errorMsg'] = ret.err;
          }

          $media_frame_content.html(renderBrowserPlaceholderHTML(renderOptions));

        } else if (!appending && !allLoaded) {
          // clear the UL if we're not appending
          jQuery('#__assets_browser').find('li').remove();
        }

        var $ul = jQuery('#__assets_browser');
        firstLoad = false;

        // if we didn't get any items back and we're auto-loading data for infinite scroll,
        // set allLoaded to true, so we don't try to load any more data
        if (!ret.items.items.length && appending) {
          jQuery('#overlay-loader-li').remove();

          // if we got an error, re-add the Load More button
          if (ret.err) {
            fv_flowplayer_browser_add_load_more_button($ul, function() { loadMoreFunction(true); });
          }

          appending = false;
          allLoaded = true;
          return;
        }

        // remove temporary loading LI if we're not displaying the full browser for the first time
        if (!firstLoad) {
          jQuery('#overlay-loader-li').remove();
        }

        fv_flowplayer_browser_browse(ret.items, {
          'breadcrumbs' : 1,
          'ajaxSearchCallback': function() {
            allLoaded = false;
            appending = false;
            page = 1;
            getData();
          },
          append: appending,
          loadMoreButtonAction: ( ret.items.is_last_page ? false : loadMoreFunction )
        });

        appending = false;
      });
    }

    getData();
    return false;
  }

  $(document).on("mediaBrowserOpen", function () {
    fv_flowplayer_media_browser_add_tab('fv_flowplayer_peertube_private_browser_media_tab', 'Peertube: ' + fv_player_peertube_private.tab_name, fv_flowplayer_peertube_private_browser_load_assets);
  });
});
