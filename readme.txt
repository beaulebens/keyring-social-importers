=== Keyring Social Importers ===
Tags: import, sync, social, keyring, indieweb, foursquare, instagram, instapaper, tripit, twitter, pinterest
Requires at least: 4.0
Tested up to: 4.7.3
Stable Tag: 1.8

Import your posts/images/etc from Twitter, Instagram, Pinterest, and more into your WordPress install. Own your content.

== Description ==

**Please [read about each importer](http://wordpress.org/extend/plugins/keyring-social-importers/other_notes/) before running this plugin**

This package of social importers provide you with the ability to pull in your content that gets created on other sites, and re-publish it on your own WordPress site. Rather than leaving others in control of everything you've put time and effort into, why not host it yourself on your one true, home-on-the-web, WordPress? [Read more about this technique/approach to data ownership](http://dentedreality.com.au/2012/10/07/where-is-your-digital-hub-home/).

After an initial import, all of these importers can also optionally check each hour and automatically download new content as well, keeping things in sync over time. They all currently import as Posts, with specific [Post Formats](http://codex.wordpress.org/Post_Formats), depending on the content type.

Importers included currently:

* [Delicious](https://delicious.com/)
* [Fitbit](https://fitbit.com/)
* [Flickr](https://flickr.com/)
* [Foursquare/Swarm](https://foursquare.com/)
* [Instagram](https://instagram.com/)
* [Instapaper](https://instapaper.com/)
* [Jetpack](https://jetpack.com/)/[WordPress.com](https://wordpress.com/)
* [Moves](https://moves-app.com/)
* [Nest Cameras](https://nest.com/camera/meet-nest-cam/)
* [Pinterest](https://pinterest.com/)
* [TripIt](https://tripit.com/)
* [Twitter](https://twitter.com/)

You can potentially [write your own importers](https://github.com/cfinke/Keyring-Facebook-Importer) as well, using the base class included.

== Installation ==

0. Install and activate the [Keyring plugin](http://wordpress.org/extend/plugins/keyring/), which is required for authentication.
1. Install Keyring Social Importers either via the WordPress.org plugin directory, or by uploading the files to your server.
2. Activate both plugins via Plugins > Installed Plugins.
3. Go to Tools > Import > (service) and follow the prompts.

== Importers ==

= Common Features =
* If you select to 'auto-import new content', all importers will check once per hour for new content.
* All posts created by the importers are associated with a taxonomy called `keyring_service`, which allows you to filter/select them. Appears in wp-admin as "Imported From" under the Posts menu.
* Every attempt is made to download/store as much data as possible, and use it intelligently (e.g. tags).
* Raw import data is stored in a custom field (`raw_import_data`) as a json_encode()ed string.

= Delicious =
* Every bookmark from your [Delicious](https://delicious.com/) account is imported as a post.
* All imported posts are marked with the 'link' Post Format.
* delicious_id and the href/link itself are saved as custom fields.
* Tags used on Delicious are used in WordPress.

= Fitbit =
* Very basic for now, just imports your data and creates a simple summary post.
* Summary post only contains a statement about how many steps you took that day.

= Flickr =
* Every photo in your [Flickr](https://flickr.com/) account is downloaded (the actual, original image) and imported into your Media Library.
* For every photo, a Post is created and published, containing that one image (and it is attached within WordPress).
* Posts are marked with the 'image' Post Format.
* Posts are created with the publish date matching the 'Taken' date of the photo. The modified date (of the Post) is set to the 'Upload' date from Flickr.
* There is no support/handling of Galleries, Sets or anything else in Flickr, just one Post per photo.
* Tags used on Flickr are used on WordPress.
* If available, geo data is downloaded and stored per the [WordPress Geodata guidelines](http://codex.wordpress.org/Geodata).
* flickr_id and the full URL to the photo page are stored as custom fields.

= Foursquare =
* Imports each check-in on [Foursquare](https://foursquare.com/) as a separate Post.
* Marks those Posts with the 'status' Post Format.
* foursquare_id plus geo lat/long are stored as separate custom fields, per the [WordPress Geodata guidelines](http://codex.wordpress.org/Geodata).

= Instagram =
* Each photo on your [Instagram](https://instagram.com/) account is downloaded and imported into your Media Library.
* For every photo, a Post is created and published, containing that one image (and it is attached within WordPress).
* Posts are marked with the 'image' Post Format.
* The name of the filter used is stored as instagram_filter, the URL to the photo page is stored as instagram_url.

= Instapaper =
* Imports your *Archived* links and creates a post for each of them (with post format of Link).
* Uses the title from the document in Instapaper, if there is a description associated then it uses that as well.
* NEW: Downloads the full content of the article using Instapaper's API, and stores that *in the post content*, so that you can search it later. Disable it by creating a stub plugin, or dropping this in your theme's functions.php; add_filter( 'keyring_instapaper_download_article_texts', '__return_false' );

= Jetpack/WordPress.com =
* Import posts from either self-hosted, or hosted copies of WordPress, via the Jetpack/WordPress.com API.
* Post author is always overridden.
* Tags, content, title, excerpt are all carried over.

= Moves =
* Imports your data daily.
* Creates a summary post, which is a bulleted list detailing each category of activity for the day.
* Stores raw and summary data for further processing.

= Nest (Camera) =
* Allows you to pick hours of the day to take snapshots from your cameras.
* You can pick anything between no snapshots, or one every hour, per camera.
* Each snapshot will be downloaded directly into your Media Library.
* Each snapshot will also be published as a Post (with post type of "image") using the Author/Category/Tag options you select.
* If you click the "Check For New Content Now" button, when configured for auto-import, then all cameras with at least one scheduled snapshot will take one right now, regardless of what time they're scheduled (good for verifying that things work, or taking a specific snapshot for whatever reason).
* Does not require a Nest Aware subscription, since the relatively infrequent snapshots are under request limits.

= Pinterest =
* NEW: This is a new addition, and is pretty rough still. Not recommended for production sites.
* Imports every individual pin as a post (can be a LOT), with a Post Format of "image".
* Stores the image for each pin in your Media Library.

= TripIt =
* Trips are imported, with flights mapped and posted as Status-format posts.
* Geo data is stored using something resembling the [WordPress Geodata guidelines](http://codex.wordpress.org/Geodata).
* Posts are tagged using airport codes and city names.
* Now supports paging through the API to avoid timeouts on accounts with lots of trip data.

= Twitter =
* Every [tweet](https://twitter.com/) will be downloaded as an individual Post.
* Posts are marked with the 'aside' Post Format.
* If available, geo data is downloaded and stored per the [WordPress Geodata guidelines](http://codex.wordpress.org/Geodata).
* Twitter_id and twitter_permalink are stored.
* If your tweet contains #hashtags, they are applied as tags within WordPress.
* "Entities" are expanded (URLs are not t.co, they are the real/final URLs).

== Changelog ==

= 1.8 =
* Enhancement: Added a Jetpack/WordPress.com importer.
* Enhancement: Fix Twitter importer so that it correctly obeys the option to import retweets. Props @glueckpress for reporting.
* Enhancement: Lots of code cleanup/linting.

= 1.7 =
* Enhancement: Places support for Nest and TripIt importers.
* Enhancement: Instagram importer now supports Video posts, and People.
* Enhancement: REST API support.
* Enhancement: New filter for disabling Instapaper remote content download.
* Enhancement: New filter for injecting custom CSS into the header of an importer.

= 1.6 =
* NOTE: Update to Keyring 1.7 for full compatibility.
* Enhancement: Nest Camera importer.
* Enhancement: Pinterest importer.
* Enhancement: Instapaper now downloads the full content of articles and stores it within the content of posts, so that you can search them later. It applies a `noindex` tag to those post pages to avoid duplicate content issues. Note that this is retroactive, so it'll go back and find all your old links and try to download their content over time. If you don't want that to happen, you'll need to set a postmeta/Custom Field against all of them called `remote_content` (set it to '1'), or you can filter `keyring_instapaper_download_article_texts` and `__return_false` to always turn it off; add_filter( 'keyring_instapaper_download_article_texts', '__return_false' );
* Enhancement: Compatible with [People & Places](https://github.com/beaulebens/people-places) (must be installed manually currently) to associate people and places across all services under a single taxonomy.
* Enhancement: Added "Reprocessor" concept (accessible via Tools > Import > Reprocess Keyring Data) to allow developers to re-use the raw import data for posts, and update/reprocess information.
* Enhancement: Bundle Reprocessors to fix an old encoding bug, plus to process Twitter, Instagram and Foursquare imports for People & Places.
* Enhancement: Default the UI to save imported content against the current user/author.
* Enhancement: Link back to the importer on the success screen.
* Enhancement: Add a new filter to the default header (`keyring_importer_header_css`) so that you can easily inject some custom CSS, without completely recreating the header.
* Bugfix: Remove a bunch of unused global variables.

= 1.5 =
* NOTE: Update Keyring to 1.6.2 for best results
* Bugfix: Discovered a problem with the way "raw" import data was being encoded and stored (as JSON), which rendered it (sometimes) un-encodeable. Changed to use `wp_slash()` before storing it, which means all future data is "clean". Working on a script to recover as much as possible, or you can delete old posts and re-import them, and their import data will be stored using the new approach, and thus be more accessible.
* Enhancement: New Fitbit Importer (very basic currently)
* Enhancement: New Moves Importer (also pretty basic)
* Enhancement: Assigns imported media as the Featured Image on the post created to display it
* Enhancement: Sort photos attached to Foursquare check-ins the same as the original post
* Enhancement: Use pagination in TripIt API which makes results more reliable, and avoids timeout issues on accounts with lots of trip data
* Enhancement: Handle multiple images on Tweets
* Enhancement: Better handling of retweets (load full content, don't truncate), props @petermolnar
* Enhancement: Add a filter drop-down to the Posts page so that you can filter posts by the service from which they were imported
* Bugfix: Switch to HTTPS for Flickr (now required)
* Bugfix: Use user-id in URLs for Flickr (more reliable)
* Bugfix: Auto-import Instagram photos using timestamps instead of ids, which is more reliable

= 1.4 =
* BREAKING: Change from using a value in post meta (keyring_service) to using a custom taxonomy ('keyring_services') to reference the service a post was imported from. Entries are automatically created for all importers.
* There is a script called 'migrate-keyring-postmeta-to-taxonomy.php' included in the plugin. Put it in the root of your WP install and run it (as many times as necessary, even if it crashes/runs out of memory) until it produces no output. That will convert all existing posts and remove their keyring_service postmeta.
* Fix deprecated notice and use esc_sql()
* Fix a string that didn't havea a textdomain
* Tweak Instapaper importer to use last progress date to get links published closer to when you read them

= 1.3 =
* Update Twitter API URLs to use new 1.1 API
* Foursquare check-ins that contain photos now download and attach the photo to your local post, props Chris Finke
* Added an action which is fired after *each post* is created/imported: do_action( 'keyring_post_imported', $post_id, $service_name, $post );
* Add import_start/end actions which are called in core importers (for consistency)

= 1.2 =
* NOTE: Requires the latest version of Keyring (v1.4)
* NEW: Instapaper importer
* NEW: TripIt importer
* Only send the "must install Keyring" message once per page
* Better handling of options for each importer
* Check if a service is configured in Keyring before attempting to use it
* Allow default tags to be applied to all imported posts
* Clean up help text and instructions on a few importers

= 1.1 =
* Updated to work with Keyring 1.2
* Added TripIt importer (for air travel only, 1 post per "flight series")
* Added "Default tags" option for all importers

= 1.0 =
* Initial check-in without templating engine
* Auto-import working for all services **except** Flickr, and maybe Instagram
