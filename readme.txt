=== Keyring Social Importers ===
Tags: import, sync, social, keyring, delicious, flickr, foursquare, instagram, instapaper, tripit, twitter
Requires at least: 3.3
Tested up to: 4.4
Stable Tag: 1.5

Import your posts/images/etc from other web services into your WordPress install. Own your content.

== Description ==

**Please [read about each importer](http://wordpress.org/extend/plugins/keyring-social-importers/other_notes/) before running this plugin**

**NOTE: This is "pre-release" software! It's likely to miss importing some content, import it in a format you don't like, or something else. You should run it on a test WordPress install to see how it works before running it anywhere near a live site!**

This package of social importers provide you with the ability to pull in your content that gets created on other sites, and re-publish it on your own WordPress site. Rather than leaving others in control of everything you've put time and effort into, why not host it yourself on your one true, home-on-the-web, WordPress?

After an initial import, all of these importers will also optionally check each hour and automatically download new content as well, keeping things in sync over time. They all currently import as Posts, with specific [Post Formats](http://codex.wordpress.org/Post_Formats).

Importers included currently:

* [Delicious](http://delicious.com/)
* [Fitbit](http://fitbit.com/)
* [Flickr](http://flickr.com/)
* [Foursquare](http://foursquare.com/)
* [Instagram](http://instagram.com/)
* [Instapaper](http://instapaper.com/)
* [Moves](http://moves-app.com/)
* [TripIt](http://tripit.com/)
* [Twitter](http://twitter.com/)

You can potentially write your own importers as well, using the base class included.

== Installation ==

0. Install and activate the [Keyring plugin](http://wordpress.org/extend/plugins/keyring/), which is required for authentication.
1. Install Keyring Social Importers either via the WordPress.org plugin directory, or by uploading the files to your server
2. Activate Keyring in Plugins > Installed Plugins
3. Go to Tools > Import > (service) and follow the prompts

== Importers ==

= Common Features =
* If you select to 'auto-import new content', all importers will check once per hour for new content
* A custom field called 'keyring_service' will be stored against each imported item, containing the name of the service used to import the content (e.g. 'twitter', 'instagram')
* Every attempt is made to download/store as much data as possible, and use it intelligently (e.g. tags)
* Raw import data is stored in a custom field ('raw_import_data') as a json_encode()ed string

= Delicious =
* Every bookmark from your [Delicious](http://delicious.com/) account is imported as a post
* All imported posts are marked with the 'link' Post Format
* delicious_id and the href/link itself are saved as custom fields
* Tags used on Delicious are used in WordPress

= Fitbit =
* Very basic for now, just imports your data and creates a simple summary post
* Summary post only contains a statement about how many steps you took that day

= Flickr =
* Every photo in your [Flickr](http://flickr.com/) account is downloaded (the actual, original image) and imported into your Media Library
* For every photo, a Post is created and published, containing that one image (and it is attached within WordPress)
* Posts are marked with the 'image' Post Format
* Posts are created with the publish date matching the 'Taken' date of the photo. The modified date (of the Post) is set to the 'Upload' date from Flickr
* There is no support/handling of Galleries, Sets or anything else in Flickr, just one Post per photo
* Tags used on Flickr are used on WordPress
* If available, geo data is downloaded and stored per the [WordPress Geodata guidelines](http://codex.wordpress.org/Geodata)
* flickr_id and the full URL to the photo page are stored as custom fields

= Foursquare =
* Imports each check-in on [Foursquare](http://foursquare.com/) as a separate Post
* Marks those Posts with the 'status' Post Format
* foursquare_id plus geo lat/long are stored as separate custom fields, per the [WordPress Geodata guidelines](http://codex.wordpress.org/Geodata)

= Instagram =
* Each photo on your [Instagram](http://instagram.com/) account is downloaded and imported into your Media Library
* For every photo, a Post is created and published, containing that one image (and it is attached within WordPress)
* Posts are marked with the 'image' Post Format
* The name of the filter used is stored as instagram_filter, the URL to the photo page is stored as instagram_url

= Instapaper =
* Requires a [paid account](http://www.instapaper.com/subscription) (to access their API)
* Imports your *Archived* links and creates a post for each of them (with post format of Link)
* Uses the title from the document in Instapaper, if there is a description associated then it uses that as well

= Moves =
* Imports your data daily
* Creates a summary post, which is a bulleted list detailing each category of activity for the day
* Stores raw and summary data for further processing

= TripIt =
* Trips are imported, with flights mapped and posted as Status-format posts
* Geo data is stored using something resembling the [WordPress Geodata guidelines](http://codex.wordpress.org/Geodata)
* Posts are tagged using airport codes and city names
* Now supports paging through the API to avoid timeouts on accounts with lots of trip data

= Twitter =
* Every [tweet](http://twitter.com/) will be downloaded as an individual Post
* Posts are marked with the 'aside' Post Format
* If available, geo data is downloaded and stored per the [WordPress Geodata guidelines](http://codex.wordpress.org/Geodata)
* Twitter_id and twitter_permalink are stored
* If your tweet contains #hashtags, they are applied as tags within WordPress
* "Entities" are expanded (URLs are not t.co, they are the real/final URLs)

== Changelog ==
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
