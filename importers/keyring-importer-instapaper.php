<?php

// This is a horrible hack, because WordPress doesn't support dependencies/load-order.
// We wrap our entire class definition in a function, and then only call that on a hook
// where we know that the class we're extending is available. *hangs head in shame*
function Keyring_Instapaper_Importer() {

class Keyring_Instapaper_Importer extends Keyring_Importer_Base {
	const SLUG              = 'instapaper'; // e.g. 'twitter' (should match a service in Keyring)
	const LABEL             = 'Instapaper'; // e.g. 'Twitter'
	const KEYRING_SERVICE   = 'Keyring_Service_Instapaper'; // Full class name of the Keyring_Service this importer requires
	const REQUESTS_PER_LOAD = 1; // How many remote requests should be made before reloading the page?
	const LINKS_PER_REQUEST = 25; // How many links to request from Instapaper in each request

	var $auto_import        = false;
	var $request_method     = 'POST';

	function handle_request_options() {
		// Validate options and store them so they can be used in auto-imports
		if ( empty( $_POST['category'] ) || !ctype_digit( $_POST['category'] ) )
			$this->error( __( "Make sure you select a valid category to import your links into." ) );

		if ( empty( $_POST['author'] ) || !ctype_digit( $_POST['author'] ) )
			$this->error( __( "You must select an author to assign to all imported links." ) );

		if ( isset( $_POST['auto_import'] ) )
			$_POST['auto_import'] = true;
		else
			$_POST['auto_import'] = false;

		// If there were errors, output them, otherwise store options and start importing
		if ( count( $this->errors ) ) {
			$this->step = 'options';
		} else {
			$this->set_option( array(
				'category'    => (int) $_POST['category'],
				'tags'        => explode( ',', $_POST['tags'] ),
				'author'      => (int) $_POST['author'],
				'auto_import' => $_POST['auto_import'],
			) );

			$this->step = 'import';
		}
	}

	function build_request_url() {
		// http://www.instapaper.com/api/full
		$url = "https://www.instapaper.com/api/1/bookmarks/list?folder_id=archive";

		if ( $this->auto_import )
			$url = add_query_arg( array( 'limit' => self::LINKS_PER_REQUEST ), $url );
		else
			$url = add_query_arg( array( 'limit' => 500 ), $url ); // The most you can get

		return $url;
	}

	function extract_posts_from_data( $raw ) {
		global $wpdb;

		$importdata = $raw;

		if ( null === $importdata ) {
			$this->finished = true;
			return new Keyring_Error( 'keyring-instapaper-importer-failed-download', __( 'Failed to download or parse your links from Instapaper. Please wait a few minutes and try again.' ) );
		}

		// Make sure we have some bookmarks to parse
		if ( !is_array( $importdata ) || count( $importdata ) < 2 ) {
			$this->finished = true;
			return;
		}

		usort( $importdata, array( $this, 'sort_by_time' ) );

		// Parse/convert everything to WP post structs
		foreach ( $importdata as $post ) {
			if ( 'bookmark' != $post->type )
				continue;

			$post_title = $post->title;

			// Parse/adjust dates
			$post_date_gmt = gmdate( 'Y-m-d H:i:s', $post->progress_timestamp ); // last seen "progress"
			$post_date     = get_date_from_gmt( $post_date_gmt );

			// Apply selected category
			$post_category = array( $this->get_option( 'category' ) );

			// Just default tags here
			$tags = $this->get_option( 'tags' );

			// Construct a post body
			$href         = $post->url;
			$post_content = '<a href="' . $href . '" class="instapaper-title">' . $post_title . '</a>';
			if ( !empty( $post->description ) )
				$post_content .= "\n\n<blockquote class='instapaper-note'>" . $post->description . '</blockquote>';

			// Other bits
			$post_author   = $this->get_option( 'author' );
			$post_status   = 'publish';
			$instapaper_id  = $post->bookmark_id;
			$instapaper_raw = $post;

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
				'href',
				'instapaper_id',
				'instapaper_raw'
			);
		}
	}

	/**
	 * Sorts bookmarks returned by date, newest first
	 */
	function sort_by_time( $a, $b ) {
		if ( empty( $a->time ) || empty( $b->time ) )
			return 0;

		if ( $a->time == $b->time ) {
			return 0;
		}
		return ( $a->time > $b->time ) ? -1 : 1;
	}

	function insert_posts() {
		global $wpdb;
		$imported = 0;
		$skipped  = 0;
		foreach ( $this->posts as $post ) {
			extract( $post );
			if (
				!$instapaper_id
			||
				$wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = 'instapaper_id' AND meta_value = %s", $instapaper_id ) )
			||
				$post_id = post_exists( $post_title, $post_content, $post_date )
			) {
				// Looks like a duplicate, which means we've already processed it
				$skipped++;
			} else {
				$post_id = wp_insert_post( $post );

				if ( is_wp_error( $post_id ) )
					return $post_id;

				if ( !$post_id )
					continue;

				// Track which Keyring service was used
				wp_set_object_terms( $post_id, self::LABEL, 'keyring_services' );

				// Mark it as a link
				set_post_format( $post_id, 'link' );

				// Update Category
				wp_set_post_categories( $post_id, $post_category );

				add_post_meta( $post_id, 'instapaper_id', $instapaper_id );
				add_post_meta( $post_id, 'href', $href );

				if ( count( $tags ) )
					wp_set_post_terms( $post_id, implode( ',', $tags ) );

				add_post_meta( $post_id, 'raw_import_data', json_encode( $instapaper_raw ) );

				$imported++;

				do_action( 'keyring_post_imported', $post_id, static::SLUG, $post );
			}
		}
		$this->posts = array();
		$this->finished = true; // All done in a single request

		// Return, so that the handler can output info (or update DB, or whatever)
		return array( 'imported' => $imported, 'skipped' => $skipped );
	}
}

} // end function Keyring_Delicious_Importer


add_action( 'init', function() {
	Keyring_Instapaper_Importer(); // Load the class code from above
	keyring_register_importer(
		'instapaper',
		'Keyring_Instapaper_Importer',
		plugin_basename( __FILE__ ),
		__( 'Import all of your archived Instapaper links as Posts with the "Link" format.', 'keyring' )
	);
} );
