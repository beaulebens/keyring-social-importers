<?php

// This is a horrible hack, because WordPress doesn't support dependencies/load-order.
// We wrap our entire class definition in a function, and then only call that on a hook
// where we know that the class we're extending is available. *hangs head in shame*
function Keyring_TripIt_Importer() {

class Keyring_TripIt_Importer extends Keyring_Importer_Base {
	const SLUG              = 'tripit';    // e.g. 'twitter' (should match a service in Keyring)
	const LABEL             = 'TripIt';    // e.g. 'Twitter'
	const KEYRING_SERVICE   = 'Keyring_Service_TripIt';    // Full class name of the Keyring_Service this importer requires
	const REQUESTS_PER_LOAD = 1;     // How many remote requests should be made before reloading the page?
	const MIN_HOURS_GAP     = 24; // Number of hours required to trigger a new "flight grouping" (and Post)

	var $auto_import = false;

	function handle_request_options() {
		// Validate options and store them so they can be used in auto-imports
		if ( empty( $_POST['category'] ) || !ctype_digit( $_POST['category'] ) )
			$this->error( __( "Make sure you select a valid category to import your activities into." ) );

		if ( empty( $_POST['author'] ) || !ctype_digit( $_POST['author'] ) )
			$this->error( __( "You must select an author to assign to all activities." ) );

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
		// Base request URL - http://tripit.github.com/api/doc/v1/index.html
		// Because we want to go for the AirObjects, it's actually easier to just do this request
		// and get all the data, every time. Internal de-duping will clear out anything we already have.
		$url = "https://api.tripit.com/v1/list/object/past/true/type/air";
		return $url;
	}

	function extract_posts_from_data( $raw ) {
		$importdata = $raw;

		if ( null === $importdata ) {
			$this->finished = true;
			return new Keyring_Error( 'keyring-tripit-importer-failed-download', __( 'Failed to download your trips from TripIt. Please wait a few minutes and try again.' ) );
		}

		// Make sure we have some trips to parse
		if ( !is_object( $importdata ) || !count( $importdata->AirObject ) ) {
			$this->finished = true;
			return;
		}

		// Parse/convert everything to WP post structs
		foreach ( $importdata->AirObject as $trip ) {
			// We are likely to create at least 2 (there and back) posts per trip
			$trip_posts = array();

			// Each trip is made up of a series of segments, some of which are compiled into single posts
			$prev_end = 0;
			$post_title = '';
			for ( $s = 0; $s < count( $trip->Segment ); $s++ ) {
				// TripIt return a single-segment trip as an object, ugh!
				if ( is_object( $trip->Segment ) ) {
					$trip->Segment = array( $trip->Segment );
				}

				$segment = $trip->Segment[$s];
				$this_post = array();

				// Parse/adjust dates
				$start_time = strtotime( $segment->StartDateTime->date . 'T' . $segment->StartDateTime->time . $segment->StartDateTime->utc_offset );
				$end_time   = strtotime( $segment->EndDateTime->date . 'T' . $segment->EndDateTime->time . $segment->EndDateTime->utc_offset );

				// If a segment occurs more than 24 hours after the previous
				// one, then we create a new post for it
				$new_post = false;
				if ( $start_time > $prev_end + ( self::MIN_HOURS_GAP * 60 * 60 ) )
					$new_post = true;

				if ( $new_post ) {
					// Post title is an abbreviated version of post content
					$post_title = sprintf( __( 'Flew %1$s:%2$s', 'keyring' ), $segment->start_airport_code, $segment->end_airport_code );

					// Date of the post is the start of the first flight segment
					$post_date_gmt = gmdate( 'Y-m-d H:i:s', $start_time );
					$post_date     = get_date_from_gmt( $post_date_gmt );

					// Apply selected category
					$post_category = array( $this->get_option( 'category' ) );

					// Construct a post body. It's going to contain a text summary of flights
					$post_content = '<ol class="tripit-flights">' . "\n";
					if ( !empty( $segment->distance ) && !empty( $segment->duration ) )
						$time_dist = " {$segment->distance} on {$segment->marketing_airline} in {$segment->duration}.";
					else
						$time_dist = '';
					$post_content .= "<li>Flew {$segment->start_city_name} ({$segment->start_airport_code}) to {$segment->end_city_name} ({$segment->end_airport_code}).$time_dist</li>\n";

					// The path is a series of points, defined by ordered xy co-ords
					if (
						!empty( $segment->start_airport_latitude )
					&&
						!empty( $segment->start_airport_longitude )
					&&
						!empty( $segment->end_airport_latitude )
					&&
						!empty( $segment->end_airport_longitude )
					) {
						$geo_polyline = array(
							"{$segment->start_airport_latitude},{$segment->start_airport_longitude}",
							"{$segment->end_airport_latitude},{$segment->end_airport_longitude}"
						);
					} else {
						$geo_polyline = array();
					}

					// Tags use the default ones, plus each airport code and city name
					$tags = $this->get_option( 'tags' );
					$tags[] = $segment->start_city_name;
					$tags[] = $segment->start_airport_code;
					$tags[] = $segment->end_city_name;
					$tags[] = $segment->end_airport_code;

					// Other bits
					$post_author       = $this->get_option( 'author' );
					$post_status       = 'publish';
					$tripit_id         = $trip->trip_id;
					$tripit_segment_id = $segment->id;
				} else {
					// If it's not a new post, then keep adding segments to the title
					$post_title .= ':' . $segment->end_airport_code;

					// Add this flight to the list of summaries...
					if ( !empty( $segment->distance ) && !empty( $segment->duration ) )
						$time_dist = " {$segment->distance} on {$segment->marketing_airline} in {$segment->duration}.";
					else
						$time_dist = '';
					$post_content .= "<li>Flew {$segment->start_city_name} ({$segment->start_airport_code}) to {$segment->end_city_name} ({$segment->end_airport_code}).$time_dist</li>\n";
					$tags[] = $segment->end_city_name;
					$tags[] = $segment->end_airport_code;

					// ...and the geo path. Only need the end airport since it continues from the previous location
					if ( !empty( $segment->end_airport_latitude ) && !empty( $segment->end_airport_longitude ) )
						$geo_polyline[] = "{$segment->end_airport_latitude},{$segment->end_airport_longitude}";
				}

				// For tracking lay-over/new post splits
				$prev_end = $end_time;

				// "Done" with a post when:
				// 1. there are no more segments OR
				// 2. the next segment will trigger a new post
				$write_out_post = false;
				if ( $s + 1 >= count( $trip->Segment ) ) {
					$write_out_post = true;
				} else {
					$next_start = strtotime( $trip->Segment[ $s + 1 ]->StartDateTime->date . 'T' . $trip->Segment[ $s + 1 ]->StartDateTime->time . $trip->Segment[ $s + 1 ]->StartDateTime->utc_offset );
					if ( $next_start > $prev_end + ( self::MIN_HOURS_GAP * 60 * 60 ) )
						$write_out_post = true;
				}

				if ( $write_out_post ) {
					// The first post created for each trip contains the full raw import data
					if ( !count( $trip_posts ) )
						$tripit_raw = $trip;
					else
						$tripit_raw = false;

					// Need to "finish up" the post content
					$post_content .= "</ol>";

					$trip_posts[] = compact(
						'post_author',
						'post_date',
						'post_date_gmt',
						'post_content',
						'post_title',
						'post_status',
						'post_category',
						'tags',
						'geo_polyline',
						'tripit_id',
						'tripit_segment_id',
						'tripit_raw'
					);
				}
			}

			// Add these posts into the global list for insertion in a minute
			$this->posts = array_merge( $this->posts, $trip_posts );
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
				!$tripit_segment_id
			||
				$wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = 'tripit_segment_id' AND meta_value = %s", $tripit_segment_id ) )
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

				// Mark it as a status
				set_post_format( $post_id, 'status' );

				// Update Category + Tags
				wp_set_post_categories( $post_id, $post_category );

				if ( count( $tags ) )
					wp_set_post_terms( $post_id, implode( ',', $tags ) );

				add_post_meta( $post_id, 'tripit_id', $tripit_id );

				// Store geodata if it's available
				if ( !empty( $geo_polyline ) ) {
					add_post_meta( $post_id, 'geo_polyline', json_encode( $geo_polyline ) );
					add_post_meta( $post_id, 'geo_public', 1 );
				}

				if ( $tripit_raw )
					add_post_meta( $post_id, 'raw_import_data', json_encode( $tripit_raw ) );

				$imported++;

				do_action( 'keyring_post_imported', $post_id, static::SLUG, $post );
			}
		}
		$this->posts = array();

		$this->finished = true;

		// Return, so that the handler can output info (or update DB, or whatever)
		return array( 'imported' => $imported, 'skipped' => $skipped );
	}

	/**
	 * The parent class creates an hourly cron job to run auto import. That's unnecessarily aggressive for
	 * TripIt, so we're going to cut that downt to once every 12 hours by just skipping the job depending
	 * on the hour. If we want to run, then call the parent auto_import.
	 */
	function do_auto_import() {
		if ( 01 == date( 'H' ) || 12 == date( 'H' ) )
			parent::do_auto_import();
	}
}

} // end function Keyring_Instagram_Importer


add_action( 'init', function() {
	Keyring_TripIt_Importer(); // Load the class code from above
	keyring_register_importer(
		'tripit',
		'Keyring_TripIt_Importer',
		plugin_basename( __FILE__ ),
		__( 'Download your travel details from TripIt and auto-post maps of your flights. Each flight is saved as a Post containing a map, marked with the Status format.', 'keyring' )
	);
} );
