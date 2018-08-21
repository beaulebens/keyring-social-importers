<?php

// This is a horrible hack, because WordPress doesn't support dependencies/load-order.
// We wrap our entire class definition in a function, and then only call that on a hook
// where we know that the class we're extending is available. *hangs head in shame*
function Keyring_Delicious_Importer() {

class Keyring_Delicious_Importer extends Keyring_Importer_Base {
	const SLUG              = 'delicious';    // e.g. 'twitter' (should match a service in Keyring)
	const LABEL             = 'Delicious';    // e.g. 'Twitter'
	const KEYRING_SERVICE   = 'Keyring_Service_Delicious';    // Full class name of the Keyring_Service this importer requires
	const REQUESTS_PER_LOAD = 3;     // How many remote requests should be made before reloading the page?

	function handle_request_options() {
		// Validate options and store them so they can be used in auto-imports
		if ( empty( $_POST['status'] ) || ! in_array( $_POST['status'], array( 'publish', 'pending', 'draft', 'private' ) ) ) {
			$this->error( __( "Make sure you select a valid post status to assign to your imported posts.", 'keyring' ) );
		}

		if ( empty( $_POST['category'] ) || ! ctype_digit( $_POST['category'] ) ) {
			$this->error( __( "Make sure you select a valid category to import your bookmarks into.", 'keyring' ) );
		}

		if ( empty( $_POST['author'] ) || ! ctype_digit( $_POST['author'] ) ) {
			$this->error( __( "You must select an author to assign to all bookmarks.", 'keyring' ) );
		}

		if ( isset( $_POST['auto_import'] ) ) {
			$_POST['auto_import'] = true;
		} else {
			$_POST['auto_import'] = false;
		}

		// If there were errors, output them, otherwise store options and start importing
		if ( count( $this->errors ) ) {
			$this->step = 'options';
		} else {
			$this->set_option( array(
				'status'      => (string) $_POST['status'],
				'category'    => (int) $_POST['category'],
				'tags'        => explode( ',', $_POST['tags'] ),
				'author'      => (int) $_POST['author'],
				'auto_import' => $_POST['auto_import'],
			) );

			$this->step = 'import';
		}
	}

	function build_request_url() {
		// Base request URL
		$url = "https://api.del.icio.us/v1/posts/all?results=200";

		if ( $this->auto_import ) {
			// Get most recent bookmark we've imported (if any), and its date so that we can get new ones since then
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
				$max = str_replace( ' ', 'T', $latest[0]->post_date_gmt ) . 'Z'; // Ridiculous format
				$url = add_query_arg( 'fromdt', $max, $url );
			}
		} else {
			// Handle page offsets (only for non-auto-import requests)
			$url = add_query_arg( 'start', $this->get_option( 'page', 0 ) * 200, $url );
		}

		return $url;
	}

	function extract_posts_from_data( $raw ) {
		global $wpdb;

		$importdata = $raw;

		if ( null === $importdata ) {
			$this->finished = true;
			return new Keyring_Error( 'keyring-delicious-importer-failed-download', __( 'Failed to download or parse your bookmarks from Delicious. Please wait a few minutes and try again.', 'keyring' ) );
		}

		// Make sure we have some bookmarks to parse
		if ( !is_object( $importdata ) || !count( $importdata->post ) ) {
			$this->finished = true;
			return;
		}

		// Parse/convert everything to WP post structs
		foreach ( $importdata->post as $post ) {
			$post_title = (string) $post['description'];

			// Parse/adjust dates
			$post_date_gmt = strtotime( (string) $post['time'] );
			$post_date_gmt = gmdate( 'Y-m-d H:i:s', $post_date_gmt );
			$post_date     = get_date_from_gmt( $post_date_gmt );

			// Apply selected category
			$post_category = array( $this->get_option( 'category' ) );

			// Figure out tags
			$tags = (string) $post['tag'];
			$tags = array_merge( $this->get_option( 'tags' ), explode( ' ', strtolower( $tags ) ) );

			// Construct a post body
			$href         = (string) $post['href'];
			$extended     = (string) $post['extended'];
			$post_content = '<a href="' . $href . '" class="delicious-title">' . $post_title . '</a>';
			if ( !empty( $extended ) ) {
				$post_content .= "\n\n<blockquote class='delicious-note'>" . $extended . '</blockquote>';
			}

			// Other bits
			$post_author   = $this->get_option( 'author' );
			$post_status   = $this->get_option( 'status', 'publish' );
			$delicious_id  = (string) $post['hash'];
			$delicious_raw = $post;

			// Build the post array, and hang onto it along with the others
			$this->posts[] = compact(
				'post_author',
				'post_date',
				'post_date_gmt',
				'post_content',
				'post_title',
				'post_status',
				'post_category',
				'delicious_id',
				'tags',
				'href',
				'delicious_raw'
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
				$wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = 'delicious_id' AND meta_value = %s", $delicious_id ) )
			||
				$post_id = post_exists( $post_title, $post_content, $post_date )
			) {
				// Looks like a duplicate
				$skipped++;
			} else {
				$post_id = wp_insert_post( $post );

				if ( is_wp_error( $post_id ) ) {
					return $post_id;
				}

				if ( ! $post_id ) {
					continue;
				}

				// Track which Keyring service was used
				wp_set_object_terms( $post_id, self::LABEL, 'keyring_services' );

				// Mark it as a link
				set_post_format( $post_id, 'link' );

				// Update Category
				wp_set_post_categories( $post_id, $post_category );

				add_post_meta( $post_id, 'delicious_id', $delicious_id );
				add_post_meta( $post_id, 'href', $href );

				if ( count( $tags ) ) {
					wp_set_post_terms( $post_id, implode( ',', $tags ) );
				}

				add_post_meta( $post_id, 'raw_import_data', wp_slash( json_encode( $delicious_raw ) ) );

				$imported++;

				do_action( 'keyring_post_imported', $post_id, static::SLUG, $post );
			}
		}
		$this->posts = array();

		// Return, so that the handler can output info (or update DB, or whatever)
		return array( 'imported' => $imported, 'skipped' => $skipped );
	}
}

} // end function Keyring_Delicious_Importer


add_action( 'init', function() {
	Keyring_Delicious_Importer(); // Load the class code from above
	keyring_register_importer(
		'delicious',
		'Keyring_Delicious_Importer',
		plugin_basename( __FILE__ ),
		__( 'Import all of your Delicious bookmarks as Posts with the "Link" format.', 'keyring' )
	);
} );
