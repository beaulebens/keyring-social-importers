<?php

// This is a horrible hack, because WordPress doesn't support dependencies/load-order.
// We wrap our entire class definition in a function, and then only call that on a hook
// where we know that the class we're extending is available. *hangs head in shame*
function Keyring_Foursquare_Importer() {

class Keyring_Foursquare_Importer extends Keyring_Importer_Base {
	const SLUG              = 'foursquare';    // e.g. 'twitter' (should match a service in Keyring)
	const LABEL             = 'Foursquare';    // e.g. 'Twitter'
	const KEYRING_SERVICE   = 'Keyring_Service_Foursquare';    // Full class name of the Keyring_Service this importer requires
	const REQUESTS_PER_LOAD = 3;     // How many remote requests should be made before reloading the page?
	const NUM_PER_LOAD      = 100; // How many check-ins per API request?

	function __construct() {
		parent::__construct();

		// If we have People & Places available, then allow re-processing old posts as well
		if ( class_exists( 'People_Places' ) ) {
			add_filter( 'keyring_importer_reprocessors', function( $reprocessors ) {
				$reprocessors[ 'foursquare-places' ] = array(
					'label'       => __( 'Places you checked into via Foursquare/Swarm', 'keyring' ),
					'description' => __( 'Reprocess your Swarm checkins and link up Places properly.', 'keyring' ),
					'callback'    => array( $this, 'reprocess_places' ),
					'service'     => $this->taxonomy->slug,
				);
				return $reprocessors;
			} );

			add_filter( 'keyring_importer_reprocessors', function( $reprocessors ) {
				$reprocessors[ 'foursquare-people' ] = array(
					'label'       => __( 'People you checked in with on Foursquare/Swarm', 'keyring' ),
					'description' => __( 'If you have checked in with People on Swarm, this will identify and link them up.', 'keyring' ),
					'callback'    => array( $this, 'reprocess_people' ),
					'service'     => $this->taxonomy->slug,
				);
				return $reprocessors;
			} );

			add_filter( 'keyring_importer_reprocessors', function( $reprocessors ) {
				$reprocessors[ 'foursquare-createdBy' ] = array(
					'label'       => __( 'Someone else checked you in to Swarm', 'keyring' ),
					'description' => __( 'Detects when a Swarm/Foursquare check-in was created by someone else on your behalf, and assumes you were there with them, so it creates a reference to their "Person".', 'keyring' ),
					'callback'    => array( $this, 'reprocess_createdBy' ),
					'service'     => $this->taxonomy->slug,
				);
				return $reprocessors;
			} );

			add_filter( 'keyring_importer_reprocessors', function( $reprocessors ) {
				$reprocessors[ 'foursquare-private' ] = array(
					'label'       => __( 'Off-the-grid check-ins', 'keyring' ),
					'description' => __( 'Marks off-the-grid/private check-ins as Private posts within WordPress, and marks their geo-data as being non-public to avoid them being mapped.', 'keyring' ),
					'callback'    => array( $this, 'reprocess_private' ),
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
			$this->error( __( "Make sure you select a valid category to import your checkins into.", 'keyring' ) );
		}

		if ( empty( $_POST['author'] ) || ! ctype_digit( $_POST['author'] ) ) {
			$this->error( __( "You must select an author to assign to all checkins.", 'keyring' ) );
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
		$url = "https://api.foursquare.com/v2/users/" . $this->get_option( 'user_id', 'self' ) . "/checkins?limit=" . self::NUM_PER_LOAD;

		if ( $this->auto_import ) {
			// Get most recent checkin we've imported (if any), and its date so that we can get new ones since then
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
				$url = add_query_arg( 'afterTimestamp', strtotime( $latest[0]->post_date_gmt ) + 1, $url );
			}
		} else {
			// Handle page offsets (only for non-auto-import requests)
			$url = add_query_arg( 'offset', $this->get_option( 'page', 0 ) * self::NUM_PER_LOAD, $url );
		}

		return $url;
	}

	function extract_posts_from_data( $raw ) {
		$importdata = $raw;

		if ( null === $importdata ) {
			$this->finished = true;
			return new Keyring_Error( 'keyring-foursquare-importer-failed-download', __( 'Failed to download your checkins from foursquare. Please wait a few minutes and try again.', 'keyring' ) );
		}

		// Make sure we have some checkins to parse
		if ( ! is_object( $importdata ) || ! count( $importdata->response->checkins->items ) ) {
			$this->finished = true;
			return;
		}

		// Parse/convert everything to WP post structs
		foreach ( $importdata->response->checkins->items as $post ) {
			// Sometimes the venue has no name. There's not much we can do with these.
			if ( empty( $post->venue->name ) ) {
				continue;
			}

			// Post title can be empty for Status, but it makes them easier to manage if they have *something*
			$post_title = sprintf( __( 'Checked in at %s', 'keyring' ), $post->venue->name );

			// Parse/adjust dates
			$post_date_gmt = $post->createdAt;
			$post_date_gmt = gmdate( 'Y-m-d H:i:s', $post_date_gmt );
			$post_date     = get_date_from_gmt( $post_date_gmt );

			// Apply selected category
			$post_category = array( $this->get_option( 'category' ) );

			// Construct a post body
			if ( ! empty( $post->venue->id ) ) {
				$venue_link = '<a href="' . esc_url( 'http://foursquare.com/v/' . $post->venue->id ) . '" class="foursquare-link">' . esc_html( $post->venue->name ) . '</a>';
			} else {
				$venue_link = $post->venue->name;
			}

			if ( isset( $post->event ) ) {
				$post_content = sprintf(
					__( 'Checked in at %1$s, for %2$s.', 'keyring' ),
					$venue_link,
					esc_html( $post->event->name )
				);
			} else {
				$post_content = sprintf(
					__( 'Checked in at %s.', 'keyring' ),
					$venue_link
				);
			}

			// Include any comment/shout the user made when posting
			$tags = $this->get_option( 'tags' );
			if ( isset( $post->shout ) ) {
				// Any hashtags used in a note will be applied to the Post as tags in WP
				if ( preg_match_all( '/(^|[(\[\s])#(\w+)/', $post->shout, $tag ) ) {
					$tags = array_merge( $tags, $tag[2] );
				}

				$post_content .= "\n\n<blockquote class='foursquare-note'>" . $post->shout . "</blockquote>";
			}

			// Include geo Data. Note if it was an "off the grid" check-in
			$geo = array(
				'lat'  => $post->venue->location->lat,
				'long' => $post->venue->location->lng,
			);
			if ( ! empty( $post->private ) && 1 == $post->private ) {
				$geo['public'] = 0;
			} else {
				$geo['public'] = 1;
			}

			// Pull out any media/photos
			$photos = array();
			if ( $post->photos->count > 0 ) {
				foreach ( $post->photos->items as $photo ) {
					$photos[] = $photo->prefix . 'original' . $photo->suffix;
				}
			}

			// Any people associated with this check-in
			// Relies on the People & Places plugin to store (later)
			$people = array();
			if ( ! empty( $post->with ) ) {
				foreach ( $post->with as $with ) {
					if ( empty( $with->lastName ) ) {
						$with->lastName = '';
					}
					$people[ $with->id ] = array(
						'name' => trim( $with->firstName . ' ' . $with->lastName )
					);
				}
			}

			// And if you were checked in by someone else, add them as well
			if ( ! empty( $post->createdBy ) ) {
				$people[ $post->createdBy->id ] = array(
					'name' => trim( $post->createdBy->firstName . ' ' . $post->createdBy->lastName )
				);
			}

			// @todo also look in $post->entities for type=user (mentions in shout)

			// Extract specific details of the place/venue
			$place = array();
			if ( ! empty( $post->venue ) ) {
				$place['name']          = $post->venue->name;
				$place['geo_latitude']  = $post->venue->location->lat;
				$place['geo_longitude'] = $post->venue->location->lng;
				$place['id']            = $post->venue->id;

				if ( ! empty( $post->venue->location->formattedAddress ) ) {
					$place['address'] = implode( ', ', (array) $post->venue->location->formattedAddress );
				}
			}

			// Other bits
			$post_author    = $this->get_option( 'author' );
			$post_status    = $this->get_option( 'status', 'publish' );
			$foursquare_id  = $post->id;
			$foursquare_raw = $post;

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
				'foursquare_id',
				'tags',
				'foursquare_raw',
				'photos',
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
				! $foursquare_id
			||
				$wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = 'foursquare_id' AND meta_value = %s", $foursquare_id ) )
			||
				$post_id = post_exists( $post_title, $post_content, $post_date )
			) {
				// Looks like a duplicate
				$skipped++;
			} else {
				// Set this as a private post, because it was "off the grid"
				if ( 0 === $geo['public'] ) {
					$post['post_status'] = 'private';
				}

				$post_id = wp_insert_post( $post );

				if ( is_wp_error( $post_id ) ) {
					return $post_id;
				}

				if ( ! $post_id ) {
					continue;
				}

				$post['ID'] = $post_id;

				// Track which Keyring service was used
				wp_set_object_terms( $post_id, self::LABEL, 'keyring_services' );

				// Mark it as an aside
				set_post_format( $post_id, 'status' );

				// Update Category
				wp_set_post_categories( $post_id, $post_category );

				add_post_meta( $post_id, 'foursquare_id', $foursquare_id );

				if ( is_array( $tags ) && count( $tags ) ) {
					wp_set_post_terms( $post_id, implode( ',', $tags ) );
				}

				// Store geodata if it's available
				if ( ! empty( $geo ) ) {
					add_post_meta( $post_id, 'geo_latitude',  $geo['lat'] );
					add_post_meta( $post_id, 'geo_longitude', $geo['long'] );
					add_post_meta( $post_id, 'geo_public',    $geo['public'] );
				}

				add_post_meta( $post_id, 'raw_import_data', wp_slash( json_encode( $foursquare_raw ) ) );

				if ( ! empty( $photos ) ) {
					// Reverse so they stay in order when prepended, and sideload
					$photos = array_reverse( $photos );
					$this->sideload_media( $photos, $post_id, $post, apply_filters( 'keyring_foursquare_importer_image_embed_size', 'full' ), 'prepend' );
				}

				// If we found people, and have the People & Places plugin available
				// to handle processing/storing, then store references to people against
				// this check-in as well.
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

		// Return, so that the handler can output info (or update DB, or whatever)
		return array( 'imported' => $imported, 'skipped' => $skipped );
	}

	/**
	 * Reprocess a $post and identify/link up Places.
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
			! empty( $raw->venue )
		&&
			! empty( $raw->venue->name )
		&&
			! empty( $raw->venue->location->lat )
		&&
			! empty( $raw->venue->location->lng )
		) {
			$place = array();
			$place['name']          = $raw->venue->name;
			$place['geo_latitude']  = $raw->venue->location->lat;
			$place['geo_longitude'] = $raw->venue->location->lng;
			$place['id']            = $raw->venue->id;

			if ( ! empty( $raw->venue->location->formattedAddress ) ) {
				$place['address'] = implode( ', ', (array) $raw->venue->location->formattedAddress );
			}

			if ( ! empty( $raw->venue->id ) ) {
				$place['id'] = $raw->venue->id;
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

		// Users explicitly referenced
		if ( ! empty( $raw->with ) ) {
			foreach ( $raw->with as $with ) {
				if ( empty( $with->lastName ) ) {
					$with->lastName = '';
				}

				People_Places::add_person_to_post(
					static::SLUG,
					$with->id,
					array(
						'name' => trim( $with->firstName . ' ' . $with->lastName ),
						'id'   => $with->id
					),
					$post->ID
				);
			}
		}

		return Keyring_Importer_Reprocessor::PROCESS_SUCCESS;
	}

	/**
	 * Reprocess a $post and identify/link up People if someone else checked
	 * you in.
	 */
	function reprocess_createdBy( $post ) {
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

		// Someone else created this check-in
		if ( ! empty( $raw->createdBy ) ) {
			People_Places::add_person_to_post(
				static::SLUG,
				$raw->createdBy->id,
				array(
					'name' => trim( $raw->createdBy->firstName . ' ' . ( ! empty( $raw->createdBy->lastName ) ? $raw->createdBy->lastName : '' ) ),
					'id'   => $raw->createdBy->id
				),
				$post->ID
			);
		}

		return Keyring_Importer_Reprocessor::PROCESS_SUCCESS;
	}

	/**
	 * Reprocess a $post and mark it as private if the original check-in was
	 * off-the-grid
	 */
	function reprocess_private( $post ) {
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

		// Off-the-grid check-ins are marked as `private`
		if ( ! empty( $raw->private ) && 1 == $raw->private ) {
			// Mark geodata as non-public
			update_post_meta( $post->ID, 'geo_public', 0 );

			// Modify post status
			wp_update_post( array(
				'ID'          => $post->ID,
				'post_status' => 'private'
			) );
		}

		return Keyring_Importer_Reprocessor::PROCESS_SUCCESS;
	}
}

} // end function Keyring_Foursquare_Importer

// Register Importer
add_action( 'init', function() {
	Keyring_Foursquare_Importer(); // Load the class code from above
	keyring_register_importer(
		'foursquare',
		'Keyring_Foursquare_Importer',
		plugin_basename( __FILE__ ),
		__( 'Download all of your Foursquare checkins as individual Posts (with a "status" post format).', 'keyring' )
	);
} );

// Add importer-specific integration for People & Places (if installed)
add_action( 'init', function() {
	if ( class_exists( 'People_Places') ) {
		Taxonomy_Meta::add( 'people', array(
			'key'   => 'foursquare',
			'label' => __( 'Foursquare id', 'keyring' ),
			'type'  => 'text',
			'help'  => __( "This person's Foursquare id.", 'keyring' ),
			'table' => false,
		) );
		Taxonomy_Meta::add( 'places', array(
			'key'   => 'foursquare',
			'label' => __( 'Foursquare Venue id', 'keyring' ),
			'type'  => 'text',
			'help'  => __( "Unique identifier from Foursquare (md5-looking hash).", 'keyring' ),
			'table' => false,
		) );

		/**
		 * Get the full URL to the Foursquare profile page for someone, based on their term_id
		 * @param  Int $term_id The id for this person's term entry
		 * @return String URL to their Foursquare profile, or empty string if none.
		 */
		function get_foursquare_url( $term_id ) {
			if ( $user = get_term_meta( $term_id, 'people-foursquare', true ) ) {
				$user = 'https://foursquare.com/user/' . $user; // have to use this format because we don't always have username
			}
			return $user;
		}
	}
}, 102 );
