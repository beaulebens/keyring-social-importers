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

	function __construct() {
		parent::__construct();

		// Allow users to re-process old posts and handle video posts better
		add_filter( 'keyring_importer_reprocessors', function( $reprocessors ) {
			$reprocessors[ 'instagram-videos' ] = array(
				'label'       => __( 'Instagram video posts', 'keyring' ),
				'description' => __( 'Previously, Instagram video posts were just handled like images. This will download the video, embed it in the post, and mark the post as a video post-format.', 'keyring' ),
				'callback'    => array( $this, 'reprocess_videos' ),
				'service'     => $this->taxonomy->slug,
			);
			return $reprocessors;
		} );

		// If we have People & Places available, then allow re-processing old posts as well
		if ( class_exists( 'People_Places' ) ) {
			add_filter( 'keyring_importer_reprocessors', function( $reprocessors ) {
				$reprocessors[ 'instagram-people' ] = array(
					'label'       => __( 'People tagged or mentioned on Instagram', 'keyring' ),
					'description' => __( 'Identify People tagged directly in your Instagram photos, or @mentioned in your captions, and assign them via taxonomy.', 'keyring' ),
					'callback'    => array( $this, 'reprocess_people' ),
					'service'     => $this->taxonomy->slug,
				);
				return $reprocessors;
			} );

			add_filter( 'keyring_importer_reprocessors', function( $reprocessors ) {
				$reprocessors[ 'instagram-places' ] = array(
					'label'       => __( 'Places you posted from on Instagram', 'keyring' ),
					'description' => __( 'Check your Instagram photos for tagged locations, and reference them locally as Places.', 'keyring' ),
					'callback'    => array( $this, 'reprocess_places' ),
					'service'     => $this->taxonomy->slug,
				);
				return $reprocessors;
			} );
		}
	}

	function handle_request_options() {
		// Validate options and store them so they can be used in auto-imports
		if ( empty( $_POST['status'] ) || ! in_array( $_POST['status'], array( 'publish', 'pending', 'draft', 'private' ) ) ) {
			$this->error( __( "Make sure you select a valid post status to assign to your imported posts.", 'keyring' ) );
		}

		if ( empty( $_POST['category'] ) || ! ctype_digit( $_POST['category'] ) ) {
			$this->error( __( "Make sure you select a valid category to import your pictures into.", 'keyring' ) );
		}

		if ( empty( $_POST['author'] ) || ! ctype_digit( $_POST['author'] ) ) {
			$this->error( __( "You must select an author to assign to all pictures.", 'keyring' ) );
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
		$url = "https://api.instagram.com/v1/users/" . (int) $this->service->get_token()->get_meta( 'user_id' ) . "/media/recent/?count=" . self::NUM_PER_REQUEST;

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
			$latest_id = get_post_meta( $latest[0]->ID, 'instagram_id', true );
			if ( $this->auto_import ) {
				$url = add_query_arg( 'min_id', $latest_id, $url );
			} else {
				$url = add_query_arg( 'max_id', $latest_id, $url );
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
		if ( ! is_object( $importdata ) || ! count( $importdata->data ) ) {
			$this->finished = true;
			return;
		}

		// Parse/convert everything to WP post structs
		foreach ( $importdata->data as $post ) {
			// Post title can be empty for Images, but it makes them easier to manage if they have *something*
			$post_title = __( 'Posted on Instagram', 'keyring' );
			if ( !empty( $post->caption ) ) {
				$post_title = strip_tags( $post->caption->text );
			}

			// Parse/adjust dates
			$post_date_gmt = $post->created_time;
			$post_date_gmt = gmdate( 'Y-m-d H:i:s', $post_date_gmt );
			$post_date     = get_date_from_gmt( $post_date_gmt );

			// Apply selected category
			$post_category = array( $this->get_option( 'category' ) );

			// Construct a post body.
			$instagram_video = false;
			if ( ! empty( $post->videos->standard_resolution->url ) ) {
				// We've got a video to handle
				$instagram_video = $post->videos->standard_resolution->url;

				$post_content = '<p class="instagram-video">';
				$post_content .= "\n\n" . esc_url( $post->videos->standard_resolution->url ) . "\n\n";
				$post_content .= '</p>';
			} else {
				// Just an image. By default we'll just link to the external image.
				// In insert_posts() we'll attempt to download/replace that with a local version.
				$post_content = '<p class="instagram-image">';
				$post_content .= '<a href="' . esc_url( $post->link ) . '" class="instagram-link">';
				$post_content .= '<img src="' . esc_url( $post->images->standard_resolution->url ) . '" width="' . esc_attr( $post->images->standard_resolution->width ) . '" height="' . esc_attr( $post->images->standard_resolution->height ) . '" alt="' . esc_attr( $post_title ) . '" class="instagram-img" />';
				$post_content .= '</a></p>';
			}
			if ( ! empty( $post->caption ) ) {
				$post_content .= "\n<p class='instagram-caption'>" . $post->caption->text . '</p>';
			}

			// Include geo Data
			$geo = false;
			if ( ! empty( $post->location ) && ! empty( $post->location->latitude ) ) {
				$geo = array(
					'lat'  => $post->location->latitude,
					'long' => $post->location->longitude,
				);
			}

			// Tags
			$tags = $this->get_option( 'tags' );
			if ( is_array( $tags ) && ! empty( $post->tags ) ) {
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

			// User mentions in captions
			if ( ! empty( $post->caption->text ) && stristr( $post->caption->text, '@' ) ) {
				preg_match_all( '/(^|[(\[\s\.])?@(\w+)/', $post->caption->text, $matches );
				foreach ( (array) $matches[2] as $match ) {
					$people[ trim( $match ) ] = array(
						'name' => trim( $match )
					);
				}
			}

			// Extract specific details of the place/venue
			$place = array();
			if ( ! empty( $post->location ) ) {
				$place['name']          = $post->location->name;
				$place['geo_latitude']  = $post->location->latitude;
				$place['geo_longitude'] = $post->location->longitude;
				$place['id']            = $post->location->id;
			}

			// Other bits
			$post_author      = $this->get_option( 'author' );
			$post_status      = $this->get_option( 'status', 'publish' );
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
				'instagram_video',
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

				if ( is_wp_error( $post_id ) ) {
					return $post_id;
				}

				if ( ! $post_id ) {
					continue;
				}

				// Track which Keyring service was used
				wp_set_object_terms( $post_id, self::LABEL, 'keyring_services' );

				// Mark post format, based on what we actually imported
				if ( $instagram_video ) {
					set_post_format( $post_id, 'video' );
				} else {
					set_post_format( $post_id, 'image' );
				}

				// Update Category
				wp_set_post_categories( $post_id, $post_category );

				add_post_meta( $post_id, 'instagram_id', $instagram_id );
				add_post_meta( $post_id, 'instagram_url', $instagram_url );
				add_post_meta( $post_id, 'instagram_filter', $instagram_filter );

				if ( $instagram_video ) {
					add_post_meta( $post_id, 'instagram_video', $instagram_video );
				}

				if ( count( $tags ) ) {
					wp_set_post_terms( $post_id, implode( ',', $tags ) );
				}

				// Store geodata if it's available
				if ( ! empty( $geo ) ) {
					add_post_meta( $post_id, 'geo_latitude', $geo['lat'] );
					add_post_meta( $post_id, 'geo_longitude', $geo['long'] );
					add_post_meta( $post_id, 'geo_public', 1 );
				}

				add_post_meta( $post_id, 'raw_import_data', wp_slash( json_encode( $instagram_raw ) ) );

				if ( $instagram_video ) {
					// Sideload thumbnail image just for "completeness"
					media_sideload_image( $instagram_img, $post_id );

					// Sideload the video itself
					$this->sideload_video( $instagram_video, $post_id );
				} else {
					// Sideload and embed image
					$this->sideload_media( $instagram_img, $post_id, $post, apply_filters( 'keyring_instagram_importer_image_embed_size', 'full' ) );
				}

				// If we found people, and have the People & Places plugin available
				// to handle processing/storing, then store references to people against
				// this picture as well.
				if ( ! empty( $people ) && class_exists( 'People_Places' ) ) {
					foreach ( $people as $value => $person ) {
						People_Places::add_person_to_post( static::SLUG, $value, $person, $post_id );
					}
				}

				// Handle linking to a global location, if People & Places is available
				if ( ! empty( $place ) && class_exists( 'People_Places' ) ) {
					People_Places::add_place_to_post( static::SLUG, $place['id'], $place, $post_id );
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

	/**
	 * Reprocess a $post and identify/link up People.
	 */
	function reprocess_people( $post ) {
		// Get raw data
		$raw = get_post_meta( $post->ID, 'raw_import_data', true );
		if ( ! $raw ) {
			return Keyring_Importer_Reprocessor::PROCESS_SKIPPED;
		}

		// Decode it, and bail if that fails for some reason
		$raw = json_decode( $raw );
		if ( null == $raw ) {
			return Keyring_Importer_Reprocessor::PROCESS_FAILED;
		}

		// Users in Photo
		if ( ! empty( $raw->users_in_photo ) ) {
			foreach ( $raw->users_in_photo as $user ) {
				People_Places::add_person_to_post(
					static::SLUG,
					$user->user->username,
					array(
						'name' => trim( $user->user->full_name ),
						'id'   => $user->user->id
					),
					$post->ID
				);
			}
		}

		// Mentions in captions
		if ( ! empty( $raw->caption->text ) && stristr( $raw->caption->text, '@' ) ) {
			preg_match_all( '/(^|[(\[\s\.])?@(\w+)/', $raw->caption->text, $matches );
			foreach ( (array) $matches[2] as $match ) {
				People_Places::add_person_to_post(
					static::SLUG,
					$match,
					array(
						'name' => trim( $match )
					),
					$post->ID
				);
			}
		}

		return Keyring_Importer_Reprocessor::PROCESS_SUCCESS;
	}

	/**
	 * Check posts for Places, and update accordingly. Instagram
	 * only gives us geo-data, no addresses.
	 */
	function reprocess_places( $post ) {
		// Get raw data
		$raw = get_post_meta( $post->ID, 'raw_import_data', true );
		if ( ! $raw ) {
			return Keyring_Importer_Reprocessor::PROCESS_SKIPPED;
		}

		// Decode it, and bail if that fails for some reason
		$raw = json_decode( $raw );
		if ( null == $raw ) {
			return Keyring_Importer_Reprocessor::PROCESS_FAILED;
		}

		// Places
		if (
			! empty( $raw->location )
		&&
			! empty( $raw->location->name )
		&&
			! empty( $raw->location->latitude )
		&&
			! empty( $raw->location->longitude )
		) {
			$place = array();
			$place['name']          = $raw->location->name;
			$place['geo_latitude']  = $raw->location->latitude;
			$place['geo_longitude'] = $raw->location->longitude;

			if ( ! empty( $raw->location->id ) ) {
				$place['id'] = $raw->location->id;
			}

			People_Places::add_place_to_post(
				static::SLUG,
				$place['id'],
				$place,
				$post->ID
			);
		}

		return Keyring_Importer_Reprocessor::PROCESS_SUCCESS;
	}

	/**
	 * Now that we know how to handle videos, we can go back over old posts and download them
	 */
	function reprocess_videos( $post ) {
		// Get raw data
		$raw = get_post_meta( $post->ID, 'raw_import_data', true );
		if ( ! $raw ) {
			return Keyring_Importer_Reprocessor::PROCESS_SKIPPED;
		}

		// Decode it, and bail if that fails for some reason
		$raw = json_decode( $raw );
		if ( null == $raw ) {
			return Keyring_Importer_Reprocessor::PROCESS_FAILED;
		}

		// Look for video elements and if found, handle it
		if ( empty( $raw->videos->standard_resolution->url ) ) {
			return Keyring_Importer_Reprocessor::PROCESS_SKIPPED;
		} else {
			// Change the content, so we're ready to sideload
			$post_content = '<p class="instagram-video">';
			$post_content .= "\n\n" . esc_url( $raw->videos->standard_resolution->url ) . "\n\n";
			$post_content .= '</p>';

			if ( ! empty( $raw->caption ) ) {
				$post_content .= "\n<p class='instagram-caption'>" . $raw->caption->text . '</p>';
			}

			$post_data = get_post( $post->ID );
			$post_data->post_content = $post_content;
			wp_update_post( $post_data );

			// Handle the video
			$this->sideload_video( $raw->videos->standard_resolution->url, $post->ID );

			// Update the post format
			set_post_format( $post->ID, 'video' );
		}

		return Keyring_Importer_Reprocessor::PROCESS_SUCCESS;
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
		__( 'Download copies of your Instagram photos and videos, and publish them all as individual Posts (marked as "image" or "video" format).', 'keyring' )
	);
} );

// Add importer-specific integration for People & Places (if installed)
add_action( 'init', function() {
	if ( class_exists( 'People_Places') ) {
		Taxonomy_Meta::add( 'people', array(
			'key'   => 'instagram',
			'label' => __( 'Instagram username', 'keyring' ),
			'type'  => 'text',
			'help'  => __( "This person's Instagram username.", 'keyring' ),
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
