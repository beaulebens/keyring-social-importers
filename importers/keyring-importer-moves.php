<?php

// This is a horrible hack, because WordPress doesn't support dependencies/load-order.
// We wrap our entire class definition in a function, and then only call that on a hook
// where we know that the class we're extending is available. *hangs head in shame*
function Keyring_Moves_Importer() {

class Keyring_Moves_Importer extends Keyring_Importer_Base {
	const SLUG              = 'moves';    // e.g. 'twitter' (should match a service in Keyring)
	const LABEL             = 'Moves';    // e.g. 'Twitter'
	const KEYRING_SERVICE   = 'Keyring_Service_Moves';    // Full class name of the Keyring_Service this importer requires
	const REQUESTS_PER_LOAD = 1; // How many remote requests should be made before reloading the page?

	function handle_request_options() {
		// Validate options and store them so they can be used in auto-imports
		if ( empty( $_POST['status'] ) || ! in_array( $_POST['status'], array( 'publish', 'pending', 'draft', 'private' ) ) ) {
			$this->error( __( "Make sure you select a valid post status to assign to your imported posts.", 'keyring' ) );
		}

		if ( empty( $_POST['category'] ) || ! ctype_digit( $_POST['category'] ) ) {
			$this->error( __( "Make sure you select a valid category to import your storyline posts into.", 'keyring' ) );
		}

		if ( empty( $_POST['author'] ) || ! ctype_digit( $_POST['author'] ) ) {
			$this->error( __( "You must select an author to assign to all storylines.", 'keyring' ) );
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
		$url = "https://api.moves-app.com/api/v1/user/storyline/daily/";

		// First import starts from first_date (per token), and collects up to now
		// Auto imports start from the most recently imported and goes up to "now"
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

		if ( $latest ) {
			// Found existing posts. Get the *next* day after the last one.
			$last = strtotime( $latest[0]->post_date_gmt );
			$date = date( 'Ymd', $last + DAY_IN_SECONDS );
		} else {
			$date = $this->service->token->get_meta( 'first_date' );
		}

		$url .= $date . '?trackPoints=true';

		return $url;
	}

	// Currently only supports kilometers
	function format_distance( $num ) {
		if ( $num < 1000 ) {
			return sprintf( __( '%s meters', 'keyring' ), $num );
		} else {
			return sprintf( __( '%s kilometers', 'keyring' ), round( $num / 1000, 1 ) );
		}
	}

	// Anything less than 1 hour is shown in minutes, otherwise hours + minutes
	function format_duration( $num ) {
		if ( $num < 3600 ) {
			return sprintf( __( '%s minutes', 'keyring' ), round( $num / 60 ) );
		} else {
			$hours = floor( $num / 60 / 60 );
			$num = $num - ( $hours * 60 * 60 );
			return sprintf( __( '%1$s hours, %2$s minutes', 'keyring' ), $hours, round( $num / 60 ) );
		}
	}

	function extract_posts_from_data( $raw ) {
		$importdata = $raw;

		if ( null === $importdata ) {
			$this->finished = true;
			return new Keyring_Error( 'keyring-moves-importer-failed-download', __( 'Failed to download your storylines from Moves. Please wait a few minutes and try again.', 'keyring' ) );
		}

		// Make sure we have some data to parse
		if ( !is_array( $importdata ) || !is_object( $importdata[0] ) || is_null( $importdata[0]->caloriesIdle ) ) {
			$this->finished = true;
			return;
		}

		$importdata = $importdata[0];
		$post_date = substr( $importdata->date, 0, 4 ) . '-' . substr( $importdata->date, 4, 2 ) . '-' . substr( $importdata->date, 6, 2 );

		// Apply selected category and tags
		$post_category = array( $this->get_option( 'category' ) );
		$tags          = $this->get_option( 'tags' );

		// Collect some summary data from the segments available for today
		$summary = array(
			'wlk' => array( 'distance' => 0, 'duration' => 0, 'calories' => 0, 'steps' => 0 ),
			'run' => array( 'distance' => 0, 'duration' => 0, 'calories' => 0, 'steps' => 0 ),
			'cyc' => array( 'distance' => 0, 'duration' => 0, 'calories' => 0 ),
			'trp' => array( 'distance' => 0, 'duration' => 0 ),
		);

		foreach ( (array) $importdata->segments as $segment ) {
			if ( property_exists( $segment, 'activities' ) ) {
				foreach ( (array) $segment->activities as $activity ) {
					foreach ( array( 'distance', 'duration', 'calories', 'steps' ) as $datum ) {
						if ( !empty( $activity->$datum ) ) {
							$summary[ $activity->activity ][ $datum ] += $activity->$datum;
						}
					}
				}
			}
		}

		$post_strings = array();
		foreach ( $summary as $type => $data ) {
			if ( !empty( $data['distance'] ) ) {
				switch ( $type ) {
				case 'wlk':
					$post_strings[] = sprintf(
						__( 'Walked %1$s (%2$s steps) in %3$s, burning %4$s calories.', 'keyring' ),
						$this->format_distance( $summary['wlk']['distance'] ),
						number_format_i18n( $summary['wlk']['steps'] ),
						$this->format_duration( $summary['wlk']['duration'] ),
						number_format_i18n( $summary['wlk']['calories'] )
					);
					break;

				case 'run':
					$post_strings[] = sprintf(
						__( 'Ran %1$s (%2$s steps) in %3$s, burning %4$s calories.', 'keyring' ),
						$this->format_distance( $summary['run']['distance'] ),
						number_format_i18n( $summary['run']['steps'] ),
						$this->format_duration( $summary['run']['duration'] ),
						number_format_i18n( $summary['run']['calories'] )
					);
					break;

				case 'cyc':
					$post_strings[] = sprintf(
						__( 'Cycled %1$s in %2$s, burning %3$s calories.', 'keyring' ),
						$this->format_distance( $summary['cyc']['distance'] ),
						$this->format_duration( $summary['cyc']['duration'] ),
						number_format_i18n( $summary['cyc']['calories'] )
					);
					break;

				case 'trp':
					$post_strings[] = sprintf(
						__( 'Took transit for %1$s, covering %2$s.', 'keyring' ),
						$this->format_duration( $summary['trp']['duration'] ),
						$this->format_distance( $summary['trp']['distance'] )
					);
					break;
				}
			}
		}

		$summary['tot'] = array(
			'distance' => $summary['wlk']['distance'] + $summary['run']['distance'] + $summary['cyc']['distance'] + $summary['trp']['distance'],
			'calories' => $summary['wlk']['calories'] + $summary['run']['calories'] + $summary['cyc']['calories'] + ( !empty( $importdata->caloriesIdle ) ? $importdata->caloriesIdle : 0 ),
			'steps'    => $summary['wlk']['steps'] + $summary['run']['steps'],
		);

		// Construct a post body containing a text-based summary of the data.
		$post_title    = __( 'Moves Summary', 'keyring' );

		$post_content  = '<ul><li>' . implode( '</li><li>', $post_strings ) . '</li></ul>';

		// Other bits
		$post_author = $this->get_option( 'author' );
		$post_status = $this->get_option( 'status', 'publish' );
		$moves_raw   = $importdata; // Keep all of the things

		// Build the post array, and hang onto it along with the others
		$this->posts[] = compact(
			'post_author',
			'post_date',
			'post_content',
			'post_title',
			'post_status',
			'post_category',
			'tags',
			'summary',
			'moves_raw'
		);
	}

	function insert_posts() {
		global $wpdb;
		$imported = 0;
		$skipped  = 0;
		foreach ( $this->posts as $post ) {
			// See the end of extract_posts_from_data() for what is in here
			extract( $post );

			// Don't import "today", because by definition, it's not finished.
			if ( date( 'Y-m-d', strtotime( $post_date ) ) == date( 'Y-m-d' ) ) {
				$this->finished = true;
				break;
			}

			if (
				$post_id = post_exists( $post_title, $post_content, $post_date )
			) {
				// Looks like a duplicate/today
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

				set_post_format( $post_id, 'status' );

				// Update Category
				wp_set_post_categories( $post_id, $post_category );

				if ( count( $tags ) ) {
					wp_set_post_terms( $post_id, implode( ',', $tags ) );
				}

				add_post_meta( $post_id, 'raw_import_data', wp_slash( json_encode( $moves_raw ) ) );
				add_post_meta( $post_id, 'moves_summary', wp_slash( json_encode( $summary ) ) );

				$imported++;

				do_action( 'keyring_post_imported', $post_id, static::SLUG, $post );
			}
		}
		$this->posts = array();

		// Return, so that the handler can output info (or update DB, or whatever)
		return array( 'imported' => $imported, 'skipped' => $skipped );
	}
}

} // end function Keyring_Moves_Importer


add_action( 'init', function() {
	Keyring_Moves_Importer(); // Load the class code from above
	keyring_register_importer(
		'moves',
		'Keyring_Moves_Importer',
		plugin_basename( __FILE__ ),
		__( '<strong>[Under Development!]</strong> Import your daily storylines as single Posts, marked with the "status" format. You can also use the data as part of other maps or whatever else you\'d like to do with it.', 'keyring' )
	);
} );
