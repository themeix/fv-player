<?php
if (!defined('ABSPATH'))
  exit;
// $changelog
// $version
?>
<div class="wrap about-wrap">
  <h1>Welcome to FV Player Pro</h1>
  <div id="poststuff" class="ui-sortable">
    <div class="postbox">
      <div class="inside">
        <div class="fv-badge">Version <?php echo $version; ?></div>
        <p class="about-text">Thank you for updating to the latest version!</p>
        <p>In this version we were focused to bring the beta features into the main release, as they have been tested for long enough.</p>
        <p>The biggest improvements were done for <strong>YouTube on mobile devices</strong>, which no longer require a second tap to get the video playing. <strong>Splash screens and captions</strong> are also fetched automatically into the shortcode for Vimeo and YouTube videos.</p>
        <p><strong><a href="https://foliovision.com/player/advanced/interactive-video-transcript">Video transcript</a></strong> is another great feature which allows you to show video annotations below the player.</p>
        
        <?php if( function_exists('fetch_feed') && function_exists('wp_widget_rss_output') ) : ?>
          <h3>More news from our blog</h3>
          <?php        
          $rss = fetch_feed('https://foliovision.com/weblog/flowplayer/feed');
          wp_widget_rss_output($rss, array( 'items' => 5 ) );
          ?>
        <?php endif; ?>
        
        <div class="changelog">
          <h3>Changelog</h3>
          <?php echo $changelog; ?>
        </div>
      </div>
    </div>
  </div>

</div>
<style>
  .changelog li{
    margin-left:30px!important;
    width:calc(50% - 30px)!important;
  }
  .fv-badge{
    float:right;
    width: 128px;
    height: 128px;
    background-size: 128px 128px;
    background-image: url(//ps.w.org/fv-wordpress-flowplayer/assets/icon-128x128.png?rev=1085604);
    background-repeat: no-repeat;
    font-size: 14px;
    text-align: center;
    font-weight: 600;
    padding-top: 120px;
    height: 40px;
    display: inline-block;
  }
  #wpfooter{
    display:none;
  }
</style>