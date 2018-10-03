<?php

/**
This is a horrible hack, because WordPress doesn't support dependencies/load-order.
We wrap our entire class definition in a function, and then only call that on a hook
where we know that the class we're extending is available. *hangs head in shame*
NB: phpcs wants this to be "keyring_strava_importer()", but I'm just following Beau's convention
 **/
function Keyring_Strava_Importer() {

	/**
	This is a class to import data from the Strava.com API

	@author        Mark Drovdahl
	@Category      class
	**/
	class Keyring_Strava_Importer extends Keyring_Importer_Base {
		const SLUG              = 'strava';    // e.g. 'twitter' (should match a service in Keyring).
		const LABEL             = 'Strava';    // e.g. 'Twitter'.
		const KEYRING_SERVICE   = 'Keyring_Service_Strava';    // Full class name of the Keyring_Service this importer requires.
		const REQUESTS_PER_LOAD = 1; // How many remote requests should be made before reloading the page?
		const NUM_PER_LOAD      = 30; // How many activities per API request? We'll use Strava's default.

		/**
		Borrowed from other keyring-social-importers
		**/
		function handle_request_options() {
			// Validate options and store them so they can be used in auto-imports.
			if ( empty( $_POST['category'] ) || ! ctype_digit( $_POST['category'] ) ) {
				$this->error( __( 'Make sure you select a valid category to import your activities into.', 'keyring' ) );
			}

			if ( empty( $_POST['author'] ) || ! ctype_digit( $_POST['author'] ) ) {
				$this->error( __( 'You must select an author to assign to all activities.', 'keyring' ) );
			}

			if ( isset( $_POST['auto_import'] ) ) {
				$_POST['auto_import'] = true;
			} else {
				$_POST['auto_import'] = false;
			}

			// If there were errors, output them, otherwise store options and start importing.
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

		/**
		Create the request which will be sent to the Strava API
		TODO: this probably needs to be paged?
		**/
		function build_request_url() {
			// This Strava endpoint returns a list of activities for the authenticated user in descending order (most recent first)
			// This Strava endpoint can be filtered for activities that have taken place "before" or "after" a certain time. These can be combined.
			// We use the API date filter to request activities more recent than the latest activty we have stored.
			// This endpoint can also be paged, but for now we're WRONGLY ASSUMING no paging required.
			// Our Strava keyring token has a "first_date" which (maybe) corresponds to the earliest activity for this Strava account
			// first date example: "first_date: 2014-06-07T19:13:55Z" is UTC
			// First import should query using "before" now and walk backwards towards the "first_date".
			// Auto import should query using "before" now and walk backwards towards the date of the most recently imported activity.
			$url = 'https://www.strava.com/api/v3/athlete/activities';

			// Get the latest imported activities.
			$latest = get_posts(
				array(
					'numberposts' => 1,
					'orderby'     => 'date',
					'order'       => 'DESC',
					'tax_query'   => array(
						array(
							'taxonomy' => 'keyring_services',
							'field'    => 'slug',
							'terms'    => array( $this->taxonomy->slug ),
							'operator' => 'IN',
						),
					),
				)
			);

			// If we already have activities imported, only query Strava for activities more recent than the most recently imported activity.
			if ( $latest ) {
				// Convert the post_date.
				$last = date( 'Ymd H:i:s', strtotime( $latest[0]->post_date_gmt ) );
				$url  = add_query_arg( 'after', strtotime( $last ), $url );
				error_log( 'we have prior imports, latest activity date is: ' . $last . "\n" . 'titled: ' . $latest[0]->post_title );
			} else {
				// If we have no activities imported, we will assume this is our first import and we query for activites after the "first_date".
				// Queries to Strava for ?after=[epoch_date] return activities in ascending order and can be paged.
				$date = $this->service->token->get_meta( 'first_date' ); // We should have the profile creation date for the Strava Athlete.
				error_log( 'first date: ' . $date );
				$url = add_query_arg( 'page', $this->get_option( 'page', 1 ), $url );
				$url = add_query_arg( 'per_page', self::NUM_PER_LOAD, $url );
				$url  = add_query_arg( 'after', strtotime( $date ), $url );
			}
			error_log( "querying strava: " . $url );
			return $url;
		}

		/**
		Helper function to convert between meters and kilometers
		Anything less than 1 kilometer is formatted in meters, otherwise kilometers
		todo: extend with a switch statement and a second parameter of "units" to eg: convert meters to miles

		@param number $num is a distance value in meters
		**/
		function format_distance( $num ) {
				if ( $num < 1000 ) {
					// Translators: todo add comment.
					return sprintf( __( '%s meters', 'keyring' ), $num );
				} else {
					// Translators: todo add comment.
					return sprintf( __( '%s kilometers', 'keyring' ), round( $num / 1000, 1 ) );
				}
			}

		/**
		Helper function to convert between seconds, minutes and hours
		Anything less than 1 hour is shown in minutes, otherwise hours + minutes

		@param number $num is a time value in seconds
		**/
		function format_duration( $num ) {
			if ( $num < 3600 ) {
				// Translators: there are 60 seconds in a minute.
				return sprintf( __( '%s minutes', 'keyring' ), round( $num / 60 ) );
			} else {
				$hours = floor( $num / 60 / 60 );
				$num   = $num - ( $hours * 60 * 60 );
				// Translators: there are 60 minutes in an hour.
				return sprintf( __( '%1$s hours, %2$s minutes', 'keyring' ), $hours, round( $num / 60 ) );
			}
		}

		/**
		This function converts Strava activities to WordPress post objects

		@param json $importdata is the json coming from the Strava API.
		returns an array of posts:
		$this->posts[] = compact(
			'post_author',
			'post_date',
			'post_content',
			'post_title',
			'post_status',
			'post_category',
			'tags',
			'strava_raw'
		);
		**/
		function extract_posts_from_data( $importdata ) {
			//error_log( $importdata );
			// TODO: need to catch cases where $importdata == []

			// If we get back an empty array, it may be b/c we're querying for a date beyond which there are no activities to import.
			// or we have no data to process.
			if ( null === $importdata || empty( $importdata ) ) {
				$this->finished = true;
				error_log( 'nothing to import');
				return new Keyring_Error( 'keyring-strava-importer-failed-download', __( 'Failed to download activities from Strava. Please wait a few minutes and try again.', 'keyring' ) );
			}

			// If we have the wrong type of data.
			if ( ! is_array( $importdata ) || ! is_object( $importdata[0] ) ) {
				$this->finished = true;
				error_log( 'nothing to import');
				return new Keyring_Error( 'keyring-strava-importer-failed-download', __( 'Failed to download your activities from Strava. Please wait a few minutes and try again.', 'keyring' ) );
			}

			// Iterate over the activities
			foreach ( $importdata as $post ) {
				// Set WP "post_date" to the Strava activity "start_date" which is UTC, eg: "2018-02-06T17:36:50Z"
				// TODO: get more discrete and add time to post_date
				// Set WP post category and post tags from the import options.
				// Set WP post title to the Strava activity "name".
				error_log( $post->start_date );
				$post_date = substr( $post->start_date, 0, 4 ) . '-' . substr( $post->start_date, 5, 2 ) . '-' . substr( $post->start_date, 8, 2 ) . ' ' . substr( $post->start_date, 11, 8 );
				error_log( $post_date );
				$post_category = array( $this->get_option( 'category' ) );
				$tags          = $this->get_option( 'tags' );
				$post_title = $post->name;

				// Set WP post content to a summary of the Strava activity
				// Strava activities have a "type". Initially we'll only import types: "Hike", "Run" and "Ride" and use Strava's distance and "moving time" fields
				// Check if the activity has a distance value
				// TODO: add heartrate, but conditionally on "has_heartrate":true in the API response.
				if ( ! empty( $post->distance ) ) {
					switch ( $post->type ) {
						case 'Hike':
							$post_content = sprintf(
								// Translators: Hiked [distance] in [duration].
								__( 'Hiked %1$s in %2$s' ),
								$this->format_distance( $post->distance ),
								$this->format_duration( $post->moving_time )
							);
							break;

						case 'Run':
							$post_content = sprintf(
								// Translators: Ran [distance] in [duration].
								__( 'Ran %1$s in %2$s' ),
								$this->format_distance( $post->distance ),
								$this->format_duration( $post->moving_time )
							);
							break;

						case 'Ride':
							$post_content = sprintf(
								// Translators: Cycled [distance] in [duration].
								__( 'Cycled %1$s in %2$s' ),
								$this->format_distance( $post->distance ),
								$this->format_duration( $post->moving_time )
							);
							break;
					}
				}

				// Set post author from the import options.
				$post_author = $this->get_option( 'author' );
				// Set post status from import options, default to published.
				$post_status = $this->get_option( 'status', 'publish' );
				// Keep the raw JSON activity from Strava.
				$strava_raw  = $post;
				// Build an array of post objects.
				$this->posts[] = compact(
					'post_author',
					'post_date',
					'post_content',
					'post_title',
					'post_status',
					'post_category',
					'tags',
					'strava_raw'
				);
			}
		}

		/**
		This function inserts WP post objects into the database
		The first time this is run, there might be years of activities to import...
		On subsequent runs, we should only import net-new activities
		**/
		function insert_posts() {
			global $wpdb;
			$imported = 0;
			$skipped  = 0;

			foreach ( $this->posts as $post ) {
				extract( $post );
				error_log ( $post_title . ', ' . $post_content . ', ' . $post_date );
				// Avoid inserting duplicate activities
				if (
					// TODO: get more defensive here, in case one of these doesn't exist
					$post_id = post_exists( $post_date )
				) {
					// Looks like a duplicate
					error_log( "skipping" );
					$skipped++;
				} else {
					$post_id = wp_insert_post( $post, $wp_error = TRUE );

					if ( is_wp_error( $post_id ) ) {
						return $post_id;
					}

					if ( ! $post_id ) {
						continue;
					}

					// Track which Keyring service was used.
					wp_set_object_terms( $post_id, self::LABEL, 'keyring_services' );

					set_post_format( $post_id, 'status' );

					// Update Category.
					wp_set_post_categories( $post_id, $post_category );

					if ( count( $tags ) ) {
						wp_set_post_terms( $post_id, implode( ',', $tags ) );
					}

					add_post_meta( $post_id, 'raw_import_data', wp_slash( wp_json_encode( $strava_raw ) ) );
					error_log( 'importing' );
					$imported++;

					do_action( 'keyring_post_imported', $post_id, static::SLUG, $post );
				}
			}
			$this->posts = array();

			// Return, so that the handler can output info (or update DB, or whatever).
			return array( 'imported' => $imported, 'skipped' => $skipped );
		}
	} // end class Keyring_Strava_Importer
} // end function Keyring_Strava_Importer

add_action( 'init', function() {
	Keyring_Strava_Importer(); // Instantiate the class
	keyring_register_importer(
		'strava',
		'Keyring_Strava_Importer',
		plugin_basename( __FILE__ ),
		__( '<strong>[Under Development!]</strong> Import your daily activities as single Posts, marked with the "status" format. You can also use the data as part of other maps or whatever else you\'d like to do with it.', 'keyring' )
	);
} );
