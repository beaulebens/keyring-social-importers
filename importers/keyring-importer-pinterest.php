<?php

// This is a horrible hack, because WordPress doesn't support dependencies/load-order.
// We wrap our entire class definition in a function, and then only call that on a hook
// where we know that the class we're extending is available. *hangs head in shame*
function Keyring_Pinterest_Importer() {

class Keyring_Pinterest_Importer extends Keyring_Importer_Base {
	const SLUG              = 'pinterest'; // e.g. 'twitter' (should match a service in Keyring)
	const LABEL             = 'Pinterest'; // e.g. 'Twitter'
	const KEYRING_SERVICE   = 'Keyring_Service_Pinterest'; // Full class name of the Keyring_Service this importer requires
	const REQUESTS_PER_LOAD = 3; // How many remote requests should be made before reloading the page?
	const NUM_PER_REQUEST   = 50; // Number of pins per request to ask for

	// @todo: Allow selection of a single board to import from
	function handle_request_options() {
		// Validate options and store them so they can be used in auto-imports
		if ( empty( $_POST['status'] ) || ! in_array( $_POST['status'], array( 'publish', 'pending', 'draft', 'private' ) ) ) {
			$this->error( __( "Make sure you select a valid post status to assign to your imported posts.", 'keyring' ) );
		}

		if ( empty( $_POST['category'] ) || ! ctype_digit( $_POST['category'] ) ) {
			$this->error( __( "Make sure you select a valid category to import your pins into.", 'keyring' ) );
		}

		if ( empty( $_POST['author'] ) || ! ctype_digit( $_POST['author'] ) ) {
			$this->error( __( "You must select an author to assign to all pins.", 'keyring' ) );
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
		// Pinterest uses cursors for pagination, so it's easier to just use those when available.
		if ( $url = $this->get_option( 'next_request_url' ) ) {
			$this->set_option( 'next_request_url', null );
		} else {
			// Default request URL (also used for auto-import)
			$url = "https://api.pinterest.com/v1/me/pins/?fields=url,original_link,image[large],note,board,created_at&limit=" . self::NUM_PER_REQUEST;
		}

		return $url;
	}

	function extract_posts_from_data( $importdata ) {
		if ( null === $importdata ) {
			$this->finished = true;
			return new Keyring_Error( 'keyring-pinterest-importer-failed-download', __( 'Failed to download your pins from Pinterest. Please wait a few minutes and try again.', 'keyring' ) );
		}

		// Make sure we have some pictures to parse
		if ( ! is_object( $importdata ) || ! count( $importdata->data ) ) {
			$this->finished = true;
			return;
		}

		// Parse/convert everything to WP post structs
		foreach ( $importdata->data as $post ) {
			// Post title can be empty for Images, but it makes them easier to manage if they have *something*
			$post_title = __( 'Pinned on Pinterest', 'keyring' );
			if ( ! empty( $post->note ) ) {
				$post_title = strip_tags( $post->note );
			}

			// Parse/adjust dates
			$post_date_gmt = $post->created_at;
			$post_date_gmt = gmdate( 'Y-m-d H:i:s', strtotime( $post_date_gmt ) );
			$post_date     = get_date_from_gmt( $post_date_gmt );

			// Apply selected category
			$post_category = array( $this->get_option( 'category' ) );

			// Construct a post body. By default we'll just link to the external image.
			// In insert_posts() we'll attempt to download/replace that with a local version.
			$post_content = '<p class="pinterest-pin">';
			$post_content .= '<a href="' . esc_url( $post->original_link ) . '" class="pinterest-link">';
			$post_content .= '<img src="' . esc_url( $post->image->original->url ) . '" width="' . esc_attr( $post->image->original->width ) . '" height="' . esc_attr( $post->image->original->height ) . '" alt="' . esc_attr( $post_title ) . '" class="pinterest-img" />';
			$post_content .= '</a></p>';

			// Tags
			// @todo Extract #tags from note?
			$tags = $this->get_option( 'tags' );

			// Other bits
			$post_author   = $this->get_option( 'author' );
			$post_status   = $this->get_option( 'status', 'publish' );
			$pinterest_id  = $post->id;
			$pinterest_url = $post->url;
			$pinterest_img = $post->image->original->url;
			$pinterest_raw = $post;

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
				'pinterest_id',
				'pinterest_url',
				'pinterest_img',
				'pinterest_filter',
				'pinterest_raw'
			);
		}

		// Grab the cursor for the next URL to request
		if ( ! empty( $importdata->page->next ) ) {
			$this->set_option( 'next_request_url', $importdata->page->next );
		}
	}

	function insert_posts() {
		global $wpdb;
		$imported = 0;
		$skipped  = 0;
		foreach ( $this->posts as $post ) {
			// See the end of extract_posts_from_data() for what is in here
			extract( $post );

			if (
				! $pinterest_id
			||
				$wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = 'pinterest_id' AND meta_value = %s", $pinterest_id ) )
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

				if ( !$post_id ) {
					continue;
				}

				// Track which Keyring service was used
				wp_set_object_terms( $post_id, self::LABEL, 'keyring_services' );

				// Mark it as an image
				set_post_format( $post_id, 'image' );

				// Update Category
				wp_set_post_categories( $post_id, $post_category );

				add_post_meta( $post_id, 'pinterest_id', $pinterest_id );
				add_post_meta( $post_id, 'pinterest_url', $pinterest_url );

				if ( count( $tags ) ) {
					wp_set_post_terms( $post_id, implode( ',', $tags ) );
				}

				// Store geodata if it's available
				if ( ! empty( $geo ) ) {
					add_post_meta( $post_id, 'geo_latitude', $geo['lat'] );
					add_post_meta( $post_id, 'geo_longitude', $geo['long'] );
					add_post_meta( $post_id, 'geo_public', 1 );
				}

				add_post_meta( $post_id, 'raw_import_data', wp_slash( json_encode( $pinterest_raw ) ) );

				// @todo uncomment
				$this->sideload_media( $pinterest_img, $post_id, $post, apply_filters( 'keyring_pinterest_importer_image_embed_size', 'full' ) );

				$imported++;

				do_action( 'keyring_post_imported', $post_id, static::SLUG, $post );
			}
		}
		$this->posts = array();

		// If we're doing a normal import and the last request was all skipped, then we're at "now"
		if ( ! $this->auto_import && self::NUM_PER_REQUEST == $skipped ) {
			$this->finished = true;
		}

		// Return, so that the handler can output info (or update DB, or whatever)
		return array( 'imported' => $imported, 'skipped' => $skipped );
	}
}

} // end function Keyring_Pinterest_Importer


add_action( 'init', function() {
	Keyring_Pinterest_Importer(); // Load the class code from above
	keyring_register_importer(
		'pinterest',
		'Keyring_Pinterest_Importer',
		plugin_basename( __FILE__ ),
		__( 'Import your Pins from Pinterest, and save a copy of the associated images in your Media Library (marked as "image" format).', 'keyring' )
	);
} );
