<?php

// This is a horrible hack, because WordPress doesn't support dependencies/load-order.
// We wrap our entire class definition in a function, and then only call that on a hook
// where we know that the class we're extending is available. *hangs head in shame*
function Keyring_Instagram_Importer() {

class Keyring_Instagram_Importer extends Keyring_Importer_Base {
	const SLUG              = 'instagram';    // e.g. 'twitter' (should match a service in Keyring)
	const LABEL             = 'Instagram';    // e.g. 'Twitter'
	const KEYRING_SERVICE   = 'Keyring_Service_Instagram';    // Full class name of the Keyring_Service this importer requires
	const REQUESTS_PER_LOAD = 3;     // How many remote requests should be made before reloading the page?
	const NUM_PER_REQUEST   = 20;     // Number of images per request to ask for

	var $auto_import = false;

	function handle_request_options() {
		// Validate options and store them so they can be used in auto-imports
		if ( empty( $_POST['category'] ) || !ctype_digit( $_POST['category'] ) )
			$this->error( __( "Make sure you select a valid category to import your pictures into." ) );

		if ( empty( $_POST['author'] ) || !ctype_digit( $_POST['author'] ) )
			$this->error( __( "You must select an author to assign to all pictures." ) );

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
		// Base request URL
		$url = "https://api.instagram.com/v1/users/self/media/recent/?count=" . self::NUM_PER_REQUEST;

		if ( $this->auto_import ) {
			// Get most recent image we've imported (if any), and its date so that we can get new ones since then
			$order = 'DESC';
		} else {
			$order = 'ASC';
		}

		// First import starts from now and imports back to day-0.
		// Auto imports start from the most recently imported and go up to "now"
		$latest = get_posts( array(
			'numberposts' => 1,
			'orderby'     => 'date',
			'order'       => $order,
			'tax_query'   => array( array(
				'taxonomy' => 'keyring_services',
				'field'    => 'slug',
				'terms'    => array( $this->taxonomy->slug ),
				'operator' => 'IN',
			) ),
		) );

		// If we have already imported some, then import around that
		if ( $latest ) {
			$latest_timestamp = mysql2date( 'G', $latest[0]->post_date_gmt );
			if ( $this->auto_import ) {
				$url = add_query_arg( 'min_timestamp', $latest_timestamp + 1, $url );
			} else {
				$url = add_query_arg( 'max_timestamp', $latest_timestamp, $url );
			}
		}

		return $url;
	}

	function extract_posts_from_data( $raw ) {
		$importdata = $raw;

		if ( null === $importdata ) {
			$this->finished = true;
			return new Keyring_Error( 'keyring-instagram-importer-failed-download', __( 'Failed to download your images from Instagram. Please wait a few minutes and try again.', 'keyring' ) );
		}

		// Make sure we have some pictures to parse
		if ( !is_object( $importdata ) || !count( $importdata->data ) ) {
			$this->finished = true;
			return;
		}

		// Parse/convert everything to WP post structs
		foreach ( $importdata->data as $post ) {
			// Post title can be empty for Images, but it makes them easier to manage if they have *something*
			$post_title = __( 'Posted on Instagram', 'keyring' );
			if ( !empty( $post->caption ) )
				$post_title = strip_tags( $post->caption->text );

			// Parse/adjust dates
			$post_date_gmt = $post->created_time;
			$post_date_gmt = gmdate( 'Y-m-d H:i:s', $post_date_gmt );
			$post_date     = get_date_from_gmt( $post_date_gmt );

			// Apply selected category
			$post_category = array( $this->get_option( 'category' ) );

			// Construct a post body. By default we'll just link to the external image.
			// In insert_posts() we'll attempt to download/replace that with a local version.
			$post_content = '<p class="instagram-image">';
			$post_content .= '<a href="' . esc_url( $post->link ) . '" class="instagram-link">';
			$post_content .= '<img src="' . esc_url( $post->images->standard_resolution->url ) . '" width="' . esc_attr( $post->images->standard_resolution->width ) . '" height="' . esc_attr( $post->images->standard_resolution->height ) . '" alt="' . esc_attr( $post_title ) . '" class="instagram-img" />';
			$post_content .= '</a></p>';
			if ( !empty( $post->caption ) )
				$post_content .= "\n<p class='instagram-caption'>" . $post->caption->text . '</p>';

			// Include geo Data
			$geo = false;
			if ( !empty( $post->location ) ) {
				$geo = array(
					'lat'  => $post->location->latitude,
					'long' => $post->location->longitude,
				);
			}

			// Tags
			$tags = $this->get_option( 'tags' );
			if ( !empty( $post->tags ) ) {
				$tags = array_merge( $tags, $post->tags );
			}

			// Any people mentioned in this post
			// Relies on the People & Places plugin to store (later)
			$people = array();
			if ( ! empty( $post->users_in_photo ) ) {
				foreach ( $post->users_in_photo as $user ) {
					$people[ $user->user->username ] = array(
						'name'    => $user->user->full_name,
						'picture' => $user->user->profile_picture,
						'id'      => $user->user->id
					);
				}
			}

			// Extract specific details of the place/venue
			$place = array();
			if ( !empty( $post->location ) ) {
				$place['name']          = $post->location->name;
				$place['geo_latitude']  = $post->location->latitude;
				$place['geo_longitude'] = $post->location->longitude;
				$place['id']            = $post->location->id;
			}

			// Other bits
			$post_author      = $this->get_option( 'author' );
			$post_status      = 'publish';
			$instagram_id     = $post->id;
			$instagram_url    = $post->link;
			$instagram_img    = $post->images->standard_resolution->url;
			$instagram_filter = $post->filter;
			$instagram_raw    = $post;

			// Build the post array, and hang onto it along with the others
			$this->posts[] = compact(
				'post_author',
				'post_date',
				'post_date_gmt',
				'post_content',
				'post_title',
				'post_status',
				'post_category',
				'geo',
				'tags',
				'instagram_id',
				'instagram_url',
				'instagram_img',
				'instagram_filter',
				'instagram_raw',
				'people',
				'place'
			);
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
				! $instagram_id
			||
				$wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = 'instagram_id' AND meta_value = %s", $instagram_id ) )
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

				// Track which Keyring service was used
				wp_set_object_terms( $post_id, self::LABEL, 'keyring_services' );

				// Mark it as an aside
				set_post_format( $post_id, 'image' );

				// Update Category
				wp_set_post_categories( $post_id, $post_category );

				add_post_meta( $post_id, 'instagram_id', $instagram_id );
				add_post_meta( $post_id, 'instagram_url', $instagram_url );
				add_post_meta( $post_id, 'instagram_filter', $instagram_filter );

				if ( count( $tags ) )
					wp_set_post_terms( $post_id, implode( ',', $tags ) );

				// Store geodata if it's available
				if ( !empty( $geo ) ) {
					add_post_meta( $post_id, 'geo_latitude', $geo['lat'] );
					add_post_meta( $post_id, 'geo_longitude', $geo['long'] );
					add_post_meta( $post_id, 'geo_public', 1 );
				}

				add_post_meta( $post_id, 'raw_import_data', wp_slash( json_encode( $instagram_raw ) ) );

				$this->sideload_media( $instagram_img, $post_id, $post, apply_filters( 'keyring_instagram_importer_image_embed_size', 'full' ) );

				// If we found people, and have the People & Places plugin available
				// to handle processing/storing, then store references to people against
				// this picture as well.
				if ( ! empty( $people ) && class_exists( 'People_Places' ) ) {
					foreach ( $people as $value => $person ) {
						People_Places::add_person_to_post( 'instagram', $value, $person, $post_id );
					}
				}

				// Handle linking to a global location, if People & Places is available
				if ( ! empty( $place ) && class_exists( 'People_Places' ) ) {
					People_Places::add_place_to_post( 'instagram', $place['id'], $place, $post_id );
				}

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

} // end function Keyring_Instagram_Importer

// Register Importer
add_action( 'init', function() {
	Keyring_Instagram_Importer(); // Load the class code from above
	keyring_register_importer(
		'instagram',
		'Keyring_Instagram_Importer',
		plugin_basename( __FILE__ ),
		__( 'Download copies of your Instagram photos and publish them all as individual Posts (marked as "image" format).', 'keyring' )
	);
} );

// Add importer-specific integration for People & Places (if installed)
add_action( 'init', function() {
	if ( class_exists( 'People_Places') ) {
		Taxonomy_Meta::add( 'people', array(
			'key'   => 'instagram',
			'label' => __( 'Instagram username' ),
			'type'  => 'text',
			'help'  => __( "This person's Instagram username." ),
			'table' => true,
		) );

		/**
		 * Get the full URL to the Instagram profile page for someone, based on their term_id
		 * @param  Int $term_id The id for this person's term entry
		 * @return String URL to their Instagram profile, or empty string if none.
		 */
		function get_instagram_url( $term_id ) {
			if ( $user = get_term_meta( $term_id, 'people-instagram', true ) ) {
				$user = 'https://instagram.com/' . $user;
			}
			return $user;
		}
	}
}, 101 );
