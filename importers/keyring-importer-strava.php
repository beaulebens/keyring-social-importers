<?php

/**
 * This is a horrible hack, because WordPress doesn't support dependencies/load-order.
 * We wrap our entire class definition in a function, and then only call that on a hook
 * where we know that the class we're extending is available. *hangs head in shame*
 * NB: phpcs wants this to be "keyring_strava_importer()", but I'm just following Beau's convention
 */
function Keyring_Strava_Importer() {

class Keyring_Strava_Importer extends Keyring_Importer_Base {
	const SLUG              = 'strava';    // e.g. 'twitter' (should match a service in Keyring).
	const LABEL             = 'Strava';    // e.g. 'Twitter'.
	const KEYRING_SERVICE   = 'Keyring_Service_Strava';    // Full class name of the Keyring_Service this importer requires.
	const REQUESTS_PER_LOAD = 1; // How many remote requests should be made before reloading the page?
	const NUM_PER_LOAD      = 30; // How many activities per API request? We'll use Strava's default of 30.
	
	function __construct() {
		parent::__construct();
		
		// Fix problem with polyline escaping in postmeta
		add_filter( 'keyring_importer_reprocessors', function( $reprocessors ) {
			$reprocessors[ 'strava-geo-polyline' ] = array(
				'label'       => __( 'Correct geo polyline in post meta of Strava activities', 'keyring' ),
				'description' => __( 'Polyline was not properly escaped before saving into postmeta previously. This will attempt to re-save the data correctly from saved raw data.', 'keyring' ),
				'callback'    => array( $this, 'reprocess_polylines' ),
				'service'     => $this->taxonomy->slug,
			);
			return $reprocessors;
		} );
	}

	/**
	 * Borrowed from other keyring-social-importers
	 */
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
	 * Create the request which will be sent to the Strava API
	 * - This MVP uses the "athlete/activities" endpoint which returns activity summaries https://developers.strava.com/docs/reference/#api-models-SummaryActivity
	 * - The "athlete/activities" endpoint can be filtered for activities that have taken place "before" or "after" a given time. These can be combined to target specific date ranges. Queries to the endpoint with ?after=[epoch_date] return activities in ascending order (oldest first) and can be paged with ?page= and segmented by ?per_page=
	 * TODO: use the Keyring reprocessor w/the strava "id" from the first API call to then call "/activities/{id}" endpoint which returns moar! activity details https://developers.strava.com/docs/reference/#api-models-DetailedActivity
	 */
	function build_request_url() {
		$url = 'https://www.strava.com/api/v3/athlete/activities';

		// Get the latest imported activity.
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

		// If we already have activities imported, only query Strava for activities more recent than the latest imported activity.
		if ( $latest ) {
			// Build our API request url with ?after=[epoch_time] param.
			$url = add_query_arg( 'after', strtotime( $latest[0]->post_date_gmt ), $url );
		} else {
			$url = add_query_arg( 'after', '0', $url );
			$url = add_query_arg( 'page', $this->get_option( 'page', 1 ), $url );
			$url = add_query_arg( 'per_page', self::NUM_PER_LOAD, $url );
		}
		return $url;
	}

	/**
	 * Helper function to format meters to kilometers. Could go with format_duration() in a Convert_Units() class.
	 * Anything less than 1 kilometer is formatted in meters, otherwise kilometers
	 * TODO: extend with a switch statement and a second parameter of "units" to eg: convert meters to miles
     *
	 * @param number $num is a distance value in meters
	 */
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
	 * Helper function to format seconds to minutes and hours
	 * Anything less than 1 hour is shown in minutes, otherwise hours + minutes
     * 
	 * @param number $num is a time value in seconds
	 */
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
	 * This function converts Strava activity objects to WordPress post objects
     *
	 * @param json object $importdata The json returned from the Strava api.
	 * @return Array of posts:
	 */
	function extract_posts_from_data( $importdata ) {
		// Looks like we ran out of results.
		if ( is_array( $importdata ) && empty( $importdata ) ) {
			$this->finished = true;
			return;
		}

		// Early return if we get back an empty array, it may be b/c we're querying for a date beyond which there are no activities to import.
		// TODO: the "Failed to download..." message is not being output, so when there are no more activites to return, the user gets a confusing message.
		if ( null === $importdata || empty( $importdata ) ) {
			$this->finished = true;
			return new Keyring_Error( 'keyring-strava-importer-failed-download', __( 'Failed to download activities from Strava. Please wait a few minutes and try again.', 'keyring' ) );
		}

		// Early return if we have the wrong type of data.
		if ( ! is_object( $importdata[0] ) ) {
			$this->finished = true;
			return new Keyring_Error( 'keyring-strava-importer-failed-download', __( 'Failed to download your activities from Strava. Please wait a few minutes and try again.', 'keyring' ) );
		}

		// Iterate over the activities.
		foreach ( $importdata as $post ) {
			// Map Strava data model to WP post model. post->start_date is in UTC.
			// Set WP post_title to the Strava activity "name".
			$post_date     = substr( $post->start_date, 0, 4 ) . '-' . substr( $post->start_date, 5, 2 ) . '-' . substr( $post->start_date, 8, 2 ) . ' ' . substr( $post->start_date, 11, 8 );
			$post_category = array( $this->get_option( 'category' ) );
			$tags          = $this->get_option( 'tags' );
			$post_title    = $post->name;

			// Set WP post content to a summary of the Strava activity.
			// TODO: support any other activity types.
			// TODO: add heartrate, but conditionally on "has_heartrate":true in the API response.
			if ( ! empty( $post->distance ) ) {
				switch ( $post->type ) {
					case 'Swim':
						$post_content = sprintf(
							// Translators: Swam [distance] in [duration].
							__( 'Swam %1$s in %2$s.' ),
							$this->format_distance( $post->distance ),
							$this->format_duration( $post->moving_time )
						);
						break;

					case 'Walk':
						$post_content = sprintf(
							// Translators: Walked [distance] in [duration].
							__( 'Walked %1$s in %2$s.' ),
							$this->format_distance( $post->distance ),
							$this->format_duration( $post->moving_time )
						);
						break;

					case 'Hike':
						$post_content = sprintf(
							// Translators: Hiked [distance] in [duration].
							__( 'Hiked %1$s in %2$s.' ),
							$this->format_distance( $post->distance ),
							$this->format_duration( $post->moving_time )
						);
						break;

					case 'Run':
						$post_content = sprintf(
							// Translators: Ran [distance] in [duration].
							__( 'Ran %1$s in %2$s.' ),
							$this->format_distance( $post->distance ),
							$this->format_duration( $post->moving_time )
						);
						break;

					case 'Ride':
						$post_content = sprintf(
							// Translators: Cycled [distance] in [duration].
							__( 'Cycled %1$s in %2$s.' ),
							$this->format_distance( $post->distance ),
							$this->format_duration( $post->moving_time )
						);
						break;

					case 'Workout':
					default:
						if ( $post->has_heartrate ) {
							$post_content = sprintf(
								// Translators: Worked out for [duration] with a max heartrate of [heartrate]
								__( 'Worked out for %1$s with a max heartrate of %2$d.' ),
								$this->format_duration( $post->moving_time ),
								$post->max_heartrate
							);
						} else {
							$post_content = sprintf(
								// Translators: Worked out for [duration].
								__( 'Worked out for %1$s.' ),
								$this->format_duration( $post->moving_time )
							);
						}
						break;
				}
			}

			// Set post author from the import options.
			$post_author = $this->get_option( 'author' );

			// Set post status from import options, default to published unless set to private on Strava.
			// @todo Currently this won't work because you need a token with scope=activity:read_all to get private activities. Will need to modify or filter the Strava Service file for that.
			$private = $post->private;
			$post_status = $this->get_option( 'status', 'publish' );
			if ( $private ) {
				$post_status = 'private'; // Force private posts
			}

			$strava_id        = $post->id;
			$strava_permalink = 'https://www.strava.com/activities/' . $post->id;
			$strava_type      = $post->type;

			// Grab an encoded/compressed polyline of the GPS data if available.
			$geo = '';
			if ( ! empty( $post->map ) && ! empty( $post->map->summary_polyline ) ) {
				$geo = $post->map->summary_polyline;
			}

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
				'strava_raw',
				'strava_permalink',
				'strava_type',
				'strava_id',
				'geo',
				'private'
			);
		}
	}

	/**
	 * This function inserts WP post objects into the database
	 * The first time this is run, there might be years of activities to import...
	 * On subsequent runs, we should only import net-new activities
	 */
	function insert_posts() {
		global $wpdb;
		$imported = 0;
		$skipped  = 0;

		foreach ( $this->posts as $post ) {
			extract( $post );
			// Avoid inserting duplicate activities.
			if (
				$post_id = post_exists( $post_title, $post_content, $post_date )
			) {
				// Looks like a duplicate.
				$skipped++;
			} else {
				// Insert the post into the DB.
				$post_id = wp_insert_post( $post, $wp_error = TRUE );

				if ( is_wp_error( $post_id ) ) {
					return $post_id;
				}

				if ( ! $post_id ) {
					continue;
				}

				// Track which Keyring service was used.
				wp_set_object_terms( $post_id, self::LABEL, 'keyring_services' );

				// Set the post format.
				set_post_format( $post_id, 'status' );

				// Update Category.
				wp_set_post_categories( $post_id, $post_category );

				// Update tags.
				if ( count( $tags ) ) {
					wp_set_post_terms( $post_id, implode( ',', $tags ) );
				}

				add_post_meta( $post_id, 'strava_id', $strava_id );
				add_post_meta( $post_id, 'strava_permalink', $strava_permalink );
				add_post_meta( $post_id, 'strava_type', $strava_type );

				// Store the encoded polyline; will require decoding to map it
				if ( $geo ) {
					add_post_meta( $post_id, 'geo_polyline_encoded', wp_slash( $geo ) );
					add_post_meta( $post_id, 'geo_public', ( $private ? '0' : '1') ); // Hide geo if it's a private activity
				}

				// Save the raw JSON in post-meta.
				add_post_meta( $post_id, 'raw_import_data', wp_slash( wp_json_encode( $strava_raw ) ) );
				$imported++;

				// A potentially useful action to hook into for further processing.
				do_action( 'keyring_post_imported', $post_id, static::SLUG, $post );
			}
		}
		$this->posts = array();

		// Return, so that the handler can output info (or update DB, or whatever).
		return array( 'imported' => $imported, 'skipped' => $skipped );
	} // end insert_posts function

	/**
	 * Reprocess a $post and fix polylines. We have one correctly stored in the raw field
	 * so let's just extract it into its own meta field and save with a correct escaping.
	 */
	function reprocess_polylines( $post ) {
		// Get raw data
		$raw = get_post_meta( $post->ID, 'raw_import_data', true );
		if ( ! $raw ) {
			return Keyring_Importer_Reprocessor::PROCESS_SKIPPED;
		}

		// Decode it, and bail if that fails for some reason
		$raw = json_decode( $raw );
		if ( null === $raw ) {
			return Keyring_Importer_Reprocessor::PROCESS_FAILED;
		}

		// Grab an encoded/compressed polyline of the GPS data if available.
		$geo = '';
		if ( ! empty( $raw->map ) && ! empty( $raw->map->summary_polyline ) ) {
			$geo = $raw->map->summary_polyline;
		}
		
		if ( empty( $geo ) ) {
			return Keyring_Importer_Reprocessor::PROCESS_SKIPPED;
		}
		
		update_post_meta( $post->ID, 'geo_polyline_encoded', wp_slash( $geo ) );

		return Keyring_Importer_Reprocessor::PROCESS_SUCCESS;
	}
} // end class Keyring_Strava_Importer
} // end function Keyring_Strava_Importer

add_action( 'init', function() {
	Keyring_Strava_Importer();
	keyring_register_importer(
		'strava',
		'Keyring_Strava_Importer',
		plugin_basename( __FILE__ ),
		__( '<strong>[Under Development!]</strong> Import your Strava activities, each as a single Post, marked with the "status" format. The Post title is set to the Activity name and a basic summary of the activity including the distance and the duration goes in the Post body.', 'keyring' )
	);
} );
