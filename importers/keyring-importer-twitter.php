<?php

// This is a horrible hack, because WordPress doesn't support dependencies/load-order.
// We wrap our entire class definition in a function, and then only call that on a hook
// where we know that the class we're extending is available. *hangs head in shame*
function Keyring_Twitter_Importer() {

class Keyring_Twitter_Importer extends Keyring_Importer_Base {
	const SLUG              = 'twitter';    // e.g. 'twitter' (should match a service in Keyring)
	const LABEL             = 'Twitter';    // e.g. 'Twitter'
	const KEYRING_SERVICE   = 'Keyring_Service_Twitter';    // Full class name of the Keyring_Service this importer requires
	const REQUESTS_PER_LOAD = 3;     // How many remote requests should be made before reloading the page?

	var $auto_import = false;

	function __construct() {
		parent::__construct();
		add_action( 'keyring_importer_twitter_custom_options', array( $this, 'custom_options' ) );
	}

	function custom_options() {
		?><tr valign="top">
			<th scope="row">
				<label for="include_rts"><?php _e( 'Import retweets', 'keyring' ); ?></label>
			</th>
			<td>
				<input type="checkbox" value="1" name="include_rts" id="include_rts"<?php echo checked( $this->get_option( 'include_rts', true ) ); ?> />
			</td>
		</tr>
		<tr valign="top">
			<th scope="row">
				<label for="include_replies"><?php _e( 'Import @replies', 'keyring' ); ?></label>
			</th>
			<td>
				<input type="checkbox" value="1" name="include_replies" id="include_replies"<?php echo checked( $this->get_option( 'include_replies', true ) ); ?> />
			</td>
		</tr><?php
	}

 	function handle_request_options() {
		// Validate options and store them so they can be used in auto-imports
		if ( empty( $_POST['category'] ) || !ctype_digit( $_POST['category'] ) )
			$this->error( __( "Make sure you select a valid category to import your checkins into." ) );

		if ( empty( $_POST['author'] ) || !ctype_digit( $_POST['author'] ) )
			$this->error( __( "You must select an author to assign to all checkins." ) );

		if ( isset( $_POST['auto_import'] ) )
			$_POST['auto_import'] = true;
		else
			$_POST['auto_import'] = false;

		if ( isset( $_POST['include_rts'] ) )
			$_POST['include_rts'] = true;
		else
			$_POST['include_rts'] = false;

		if ( isset( $_POST['include_replies'] ) )
			$_POST['include_replies'] = true;
		else
			$_POST['include_replies'] = false;

		// If there were errors, output them, otherwise store options and start importing
		if ( count( $this->errors ) ) {
			$this->step = 'greet';
		} else {
			$this->set_option( array(
				'category'        => (int) $_POST['category'],
				'tags'            => explode( ',', $_POST['tags'] ),
				'author'          => (int) $_POST['author'],
				'include_replies' => (bool) $_POST['include_replies'],
				'include_rts'     => (bool) $_POST['include_rts'],
				'auto_import'     => (bool) $_POST['auto_import'],
				'user_id'         => $this->service->get_token()->get_meta( 'user_id' ),
			) );

			$this->step = 'import';
		}
	}

	function build_request_url() {
		// Base request URL
		$url = "https://api.twitter.com/1.1/statuses/user_timeline.json?";
		$params = array(
			'user_id' => $this->get_option( 'user_id' ),
			'trim_user' => 'true',
			'count' => 75, // More than this and Twitter seems to get flaky
			'include_entities' => 'true',
		);
		if ( false == $this->get_option( 'include_replies' ) )
			$params['exclude_replies'] = 'true';
		if ( true == $this->get_option( 'include_rts' ) )
			$params['include_rts'] = 'true';
		$url = $url . http_build_query( $params );


		if ( $this->auto_import ) {
			// Locate our most recently imported Tweet, and get ones since then
			$latest = get_posts( array(
				'numberposts' => 1,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'tax_query'   => array( array(
					'taxonomy' => 'keyring_services',
					'field'    => 'slug',
					'terms'    => array( $this->taxonomy->slug ),
					'operator' => 'IN',
				) ),
			) );

			// If we have already imported some, then start since the most recent
			if ( $latest ) {
				$max = get_post_meta( $latest[0]->ID, 'twitter_id', true );
				$url = add_query_arg( 'since_id', $max, $url );
			}
		} else {
			// Handle page offsets (only for non-auto-import requests)
			$url = add_query_arg( 'page', $this->get_option( 'page', 0 ), $url );
		}

		return $url;
	}

	function extract_posts_from_data( $raw ) {
		global $wpdb;

		$importdata = $raw;

		if ( null === $importdata ) {
			$this->finished = true;
			return new Keyring_Error( 'keyring-twitter-importer-failed-download', __( 'Failed to download your tweets from Twitter. Please wait a few minutes and try again.', 'keyring' ) );
		}

		// Check for API overage/errors
		if ( !empty( $importdata->error ) ) {
			$this->finished = true;
			return new Keyring_Error( 'keyring-twitter-importer-throttled', __( 'You have made too many requests to Twitter and have been temporarily blocked. Please try again in 1 hour (duplicate tweets will be skipped).', 'keyring' ) );
		}

		// Make sure we have some tweets to parse
		if ( !is_array( $importdata ) || !count( $importdata ) ) {
			$this->finished = true;
			return;
		}

		// Get the total number of tweets we're importing
		if ( !empty( $importdata[0]->user->statuses_count ) )
			$this->set_option( 'total', $importdata[0]->user->statuses_count );

		// Parse/convert everything to WP post structs
		foreach ( $importdata as $post ) {
			// Double-check for @replies, which shouldn't be included at all if we chose to skip them
			if ( true == $this->get_option( 'exclude_replies' ) && null != $post->in_reply_to_screen_name )
				continue;

			// Post title can be empty for Asides, but it makes them easier to manage if they have *something*
			$title_words = explode( ' ', strip_tags( $post->text ) );
			$post_title  = implode( ' ', array_slice( $title_words, 0, 5 ) ); // Use the first 5 words
			if ( count( $title_words ) > 5 )
				$post_title .= '&hellip;';

			// Parse/adjust dates
			$post_date_gmt = strtotime( $post->created_at );
			$post_date_gmt = gmdate( 'Y-m-d H:i:s', $post_date_gmt );
			$post_date     = get_date_from_gmt( $post_date_gmt );

			// Apply selected category
			$post_category = array( $this->get_option( 'category' ) );

			// Clean up content a bit
			$post_content = $post->text;
			$post_content = esc_sql( html_entity_decode( trim( $post_content ) ) );

			// Handle entities supplied by Twitter
			if ( count( $post->entities->urls ) ) {
				foreach ( $post->entities->urls as $url ) {
					$post_content = str_replace( $url->url, $url->expanded_url, $post_content );
				}
			}

			// Any hashtags used in a tweet will be applied to the Post as tags in WP
			$tags = $this->get_option( 'tags' );
			if ( preg_match_all( '/(^|[(\[\s])#(\w+)/', $post_content, $tag ) )
				$tags = array_merge( $tags, $tag[2] );

			// Add HTML links to URLs, usernames and hashtags
			$post_content = make_clickable( esc_html( $post_content ) );

			// Include geo Data (if provided by Twitter)
			if ( !empty( $post->geo ) && 'point' == strtolower( $post->geo->type ) )
				$geo = array(
					'lat' => $post->geo->coordinates[0],
					'long' => $post->geo->coordinates[1]
				);
			else
				$geo = array();

			// Get a GUID from Twitter, plus other important IDs to store in postmeta later
			$user = $this->service->get_token()->get_meta( 'username' );
			$twitter_id              = $post->id_str;
			$twitter_permalink       = "https://twitter.com/{$user}/status/{$twitter_id}";
			$in_reply_to_user_id     = $post->in_reply_to_user_id;
			$in_reply_to_screen_name = $post->in_reply_to_screen_name;
			$in_reply_to_status_id   = $post->in_reply_to_status_id;
			$post_author             = $this->get_option( 'author' );
			$post_status             = 'publish';
			$twitter_raw             = $post;

			// Build the post array, and hang onto it along with the others
			$this->posts[] = compact(
				'post_author',
				'post_date',
				'post_date_gmt',
				'post_content',
				'post_title',
				'post_status',
				'post_category',
				'tags',
				'twitter_id',
				'twitter_permalink',
				'geo',
				'in_reply_to_user_id',
				'in_reply_to_screen_name',
				'in_reply_to_status_id',
				'twitter_raw'
			);
		}
	}

	function insert_posts() {
		global $wpdb;
		$imported = 0;
		$skipped  = 0;
		foreach ( $this->posts as $post ) {
			extract( $post );
			if (
				$wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = 'twitter_id' AND meta_value = %s", $twitter_id ) )
			||
				$post_id = post_exists( $post_title, $post_content, $post_date )
			) {
				// Looks like a duplicate
				$skipped++;
			} else {
				$post_id = wp_insert_post( $post );

				if ( is_wp_error( $post_id ) )
					return $post_id;

				if ( !$post_id )
					continue;

				// Mark it as an aside
				set_post_format( $post_id, 'aside' );

				// Track which Keyring service was used
				wp_set_object_terms( $post_id, self::LABEL, 'keyring_services' );

				// Store the twitter id, reply ids etc
				add_post_meta( $post_id, 'twitter_id', $twitter_id );
				add_post_meta( $post_id, 'twitter_permalink', $twitter_permalink );
				if ( !empty( $in_reply_to_user_id ) )
					add_post_meta( $post_id, 'twitter_in_reply_to_user_id', $in_reply_to_user_id );
				if ( !empty( $in_reply_to_screen_name ) )
					add_post_meta( $post_id, 'twitter_in_reply_to_screen_name', $in_reply_to_screen_name );
				if ( !empty( $in_reply_to_status_id ) )
					add_post_meta( $post_id, 'twitter_in_reply_to_status_id', $in_reply_to_status_id );

				// Update Category and Tags
				wp_set_post_categories( $post_id, $post_category );
				if ( count( $tags ) )
					wp_set_post_terms( $post_id, implode( ',', $tags ) );

				// Store geodata if it's available
				if ( !empty( $geo ) ) {
					add_post_meta( $post_id, 'geo_latitude', $geo['lat'] );
					add_post_meta( $post_id, 'geo_longitude', $geo['long'] );
					add_post_meta( $post_id, 'geo_public', 1 );
				}

				add_post_meta( $post_id, 'raw_import_data', json_encode( $twitter_raw ) );

				$imported++;

				do_action( 'keyring_post_imported', $post_id, static::SLUG, $post );
			}
		}
		$this->posts = array();

		// Return, so that the handler can output info (or update DB, or whatever)
		return array( 'imported' => $imported, 'skipped' => $skipped );
	}
}

} // end function Keyring_Twitter_Importer


add_action( 'init', function() {
	Keyring_Twitter_Importer(); // Load the class code from above
	keyring_register_importer(
		'twitter',
		'Keyring_Twitter_Importer',
		plugin_basename( __FILE__ ),
		__( 'Import all of your tweets from Twitter as Posts (marked as "asides") in WordPress.', 'keyring' )
	);
} );
