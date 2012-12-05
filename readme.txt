=== Keyring Social Importers ===

Tags: import, sync, twitter, instagram, flickr, delicious, foursquare
Requires at least: 3.3
Tested up to: 3.5
Stable Tag: 1.0

Take back control of the content you are creating on other social websites.

== Description ==

**Please [read about each importer](http://wordpress.org/extend/plugins/keyring-social-importers/other_notes/) before running this plugin**

**NOTE: This is "pre-release" software! It's likely to miss importing some content, import it in a format you don't like, or something else. You should run it on a test WordPress install to see how it works before running it anywhere near a live site!**

This package of social importers provide you with the ability to pull in your content that gets created on other sites, and re-publish it on your own WordPress site. Rather than leaving others in control of everything you've put time and effort into, why not host it yourself on your one true, home-on-the-web, WordPress?

After an initial import, all of these importers will also optionally check each hour and automatically download new content as well, keeping things in sync over time. They all currently import as Posts, with specific [Post Formats](http://codex.wordpress.org/Post_Formats).

Importers included currently:

* [Delicious](http://delicious.com/)
* [Flickr](http://flickr.com/)
* [Foursquare](http://foursquare.com/)
* [Instagram](http://instagram.com/)
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

= Twitter =
* Every [tweet](http://twitter.com/) will be downloaded as an individual Post
* Posts are marked with the 'aside' Post Format
* If available, geo data is downloaded and stored per the [WordPress Geodata guidelines](http://codex.wordpress.org/Geodata)
* Twitter_id and twitter_permalink are stored
* If your tweet contains #hashtags, they are applied as tags within WordPress
* "Entities" are expanded (URLs are not t.co, they are the real/final URLs)

== Changelog ==
= 1.1 =
* Updated to work with Keyring 1.2

= 1.0 =
* Initial check-in without templating engine
* Auto-import working for all services **except** Flickr, and maybe Instagram