jQuery( function($) {
  var firstLoad = true;

  function fv_flowplayer_vimeo_browser_load_assets() {
    var
      $this = jQuery(this),
      $media_frame_content = jQuery('.media-frame-content:visible'),
      $overlay_div = jQuery('#fv-player-shortcode-editor-preview-spinner').clone().css({
        'height' : '100%'
      }),
      page = 1,
      ajax_data = {
        action: "load_vimeo_assets",
        cookie: encodeURIComponent(document.cookie),
        page: page
      },
      appending = false,
      allLoaded = false;

    $this.addClass('active').siblings().removeClass('active')

    function loadMoreFunction(force) {
      if ((!appending && !allLoaded) || force === true) {
        appending = true;
        // reset allLoaded if we're forcing a load after API error
        if (force === true) {
          allLoaded = false;
        }
        page++;
        getVimeoData();
      }
      return false;
    }

    function getVimeoData() {
      // check if we have search data to include
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

      // check if we have any album selected
      var albumVal = jQuery('#browser-dropdown').val();
      if (albumVal != -1) {
        ajax_data['album'] = albumVal;
      } else {
        delete(ajax_data['album']);
      }

      ajax_data['appending'] = (appending ? 1 : 0);
      ajax_data['firstLoad'] = (firstLoad ? 1 : 0);

      jQuery.post(ajaxurl, ajax_data, function(ret) {
        // don't overwrite the page if we've shown the browser for the first time already
        // ... instead, we'll be either clearing and rewriting the UL or appending data to it
        if (firstLoad) {
          var
            renderOptions = {
              'dropdownItems' : [],
              'dropdownItemSelected' : ret.active_album_link,
              'dropdownDefaultOption' : {
                'value' : -1,
                'text' : 'Choose Album...'
              }
            };

          // fill dropdown options
          for (var i in ret.albums) {
            renderOptions.dropdownItems.push({
              'value' : ret.albums[i].link,
              'text' : ret.albums[i].name
            });
          }

          // add errors, if any
          if (ret.err) {
            renderOptions['errorMsg'] = ret.err;
          }

          $media_frame_content.html( renderBrowserPlaceholderHTML(renderOptions) );

          // add change event listener to the playlists dropdown
          jQuery('#browser-dropdown').on('change', function() {
            allLoaded = false;
            appending = false;
            page = 1;
            // disable Choose button
            jQuery('.media-button-select').prop('disabled', 'disabled');
            // load album contents
            fv_flowplayer_vimeo_browser_load_assets();
          });
        } else if (!appending && !allLoaded) {
          // clear the UL if we're not appending
          jQuery('#__assets_browser').find('li').remove();
        }

        // if our result didn't return any items, there's been an error
        // and we don't need to bother with any display logic below
        if (!ret.items.items) {
          return;
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

        fv_flowplayer_browser_browse( ret.items, {
          noFileName: true,
          append: appending,
          extraAttachmentClass: 'fullsize',
          ajaxSearchCallback: function() {
            allLoaded = false;
            appending = false;
            page = 1;
            getVimeoData();
          },
          loadMoreButtonAction: (ret.is_last_page ? false : loadMoreFunction)
        } );

        appending = false;
      } );
    }

    getVimeoData();
    return false;
  }

  $( document ).on( "mediaBrowserOpen", function(event) {
    fv_flowplayer_media_browser_add_tab('fv_flowplayer_vimeo_browser_media_tab', 'Vimeo', fv_flowplayer_vimeo_browser_load_assets, null, function() {
      firstLoad = true;
    });
  });
});