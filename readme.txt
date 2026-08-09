=== FV Player Pro ===
Contributors: FolioVision
Tags: video, flash, flowplayer, player pro
Requires at least: 4.1
Tested up to: 6.7
Stable tag: trunk
License: Commercial

Extension for FV Player - Wordpress's most reliable, easy to use and feature-rich video player.

== Description ==

Provides Vimeo and YouTube integration, custom video ads, encrypted HLS, AB loop and more for FV Player.

== Installation ==

See our guide here: https://foliovision.com/player/basic-setup/installation/pro-extension

== Frequently Asked Questions ==

== Screenshots ==

== Changelog ==

= 8.0.18 - 2025-03-05 =

* Bugfix: Audio: Fix splash screen to stay if using URL signature
* Bugfix: Bunny Stream Collections: Fix for new URL format
* Bugfix: Vimeo: Splash screens for new videos failing to load due to Vimeo API changes
* Beta: Gumlet.tv support
* Beta: Video Ads: Post-roll fixes

= 8.0.16 - 2025-01-10 =

Merge Beta improvements into Release version:

* Encrypted HLS: Adding setting "Cookie Protected Encrypted HLS" to allow users on iCloud Private Relay to play encrypted HLS reliably
* Randomize Autoplay Playlist
* Video ads: Go back to first video after the post-roll ad is skipped or if it finishes
* Video Ads: Limit ad playback period - set how often the ads should play for each user
* Video ads: Show End of video popup properly after the post-roll ad is skipped or if it finishes
* Video Ads: Store expiration for each video ad

= 8.0.15 - 2025-01-08 =

* Bugfix: Chapters: Fix timeline markers not showing up
* AB Loop: Make markers easier to drag

= 8.0.11.beta - 2024-12-23 =

* Beta: Downloads: Fix picking of SD quality for Vimeo videos
* Beta: Downloads: Fix styling of buttons below player

= 8.0.10.beta - 2024-12-13 =

* Beta: Auto upgrade to FV Player 8
* Beta: Encrypted HLS: Adding setting "Cookie Protected Encrypted HLS" to allow users on iCloud Private Relay to play encrypted HLS reliably
* Beta: PeerTube: Support timeline previews (Storyboards)
* Beta: Bugfix: PeerTube: Fixed broken API token caching and thus improving the video loading speed

= 8.0.9 - 2024-11-14 =

* Added notice for FV Player 7 users with a button to upgrade to FV Player 8
* Beta: Bunny Stream: Fix double signature being added
* Beta: Randomize Autoplay Playlist: New player feature to autoplay random video at a random time in the playlists

= 8.0.7.beta - 2024-10-02 =

* Beta: Bunny Stream: Use token_path and not Stream Loader to load videos faster
* Beta: Chapters: Make label part of timeline tooltip
* Beta: Fix PHP warnings
* Beta: PeerTube: Increase API timeout
* Beta: PeerTube: Refresh token during video load if needed
* Beta: Stream Loader: Emulate m3u8 files: New setting
* Beta: Video ads: Go back to first video after the post-roll ad is skipped or if it finishes
* Beta: Video ads: Show End of video popup properly after the post-roll ad is skipped or if it finishes

= 8.0.6.beta - 2024/09/17 =

* Beta: PeerTube Media Library: Proper search and "load more" button
* Beta: Vimeo Media Library: Hide "load more" button if no more videos

= 8.0.5.beta - 2024/09/05 =

* Beta: Video Ads: Store expiration for each video ad
* Beta: Video Ads: Limit ad playback period - set how often the ads should play for each user

= 8.0.2 - 2024/08/16 =

* First public release of FV Player Pro 8
* AB loop: Remembering the AB loop start and end points for the user
* Video Ads: Storing video informat in FV Player database - prefixed with "Video Ad: ", this allows it to use the built-in FV Player video stats for video ad clicks
* Transcript: Support playlists and multiple languages

* Beta: Chapters: [fvplayer_chapters] shortcode to output player chapters separately from the player
* Beta: Transcript: [fvplayer_transcript] shortcode to output player transcript separately from the player
