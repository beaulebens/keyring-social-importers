<?php

// This is a horrible hack, because WordPress doesn't support dependencies/load-order.
// We wrap our entire class definition in a function, and then only call that on a hook
// where we know that the class we're extending is available. *hangs head in shame*
function Keyring_Fitbit_Importer() {

class Keyring_Fitbit_Importer extends Keyring_Importer_Base {
	const SLUG              = 'fitbit';    // e.g. 'twitter' (should match a service in Keyring)
	const LABEL             = 'Fitbit';    // e.g. 'Twitter'
	const KEYRING_SERVICE   = 'Keyring_Service_Fitbit';    // Full class name of the Keyring_Service this importer requires
	const REQUESTS_PER_LOAD = 10; // How many remote requests should be made before reloading the page?

	var $date = false; // Data doesn't include the date, so we need to keep track of it

	function handle_request_options() {
		// Validate options and store them so they can be used in auto-imports
		if ( empty( $_POST['status'] ) || ! in_array( $_POST['status'], array( 'publish', 'pending', 'draft', 'private' ) ) ) {
			$this->error( __( "Make sure you select a valid post status to assign to your imported posts.", 'keyring' ) );
		}

		if ( empty( $_POST['category'] ) || ! ctype_digit( $_POST['category'] ) ) {
			$this->error( __( "Make sure you select a valid category to import your data into.", 'keyring' ) );
		}

		if ( empty( $_POST['author'] ) || ! ctype_digit( $_POST['author'] ) ) {
			$this->error( __( "You must select an author to assign to all data.", 'keyring' ) );
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
		$url = "https://api.fitbit.com/1/user/-/activities/date/";

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
			$date = date( 'Y-m-d', $last + DAY_IN_SECONDS );
		} else {
			$date = $this->service->token->get_meta( 'first_date' );
		}

		$this->date = $date;
		$url .= $date . '.json';

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
		if ( $num < 60 ) {
			return sprintf( __( '%s minutes', 'keyring' ), $num );
		} else {
			$hours = floor( $num / 60 );
			$min = $num - ( $hours * 60 );
			return sprintf( __( '%1$s hours, %2$s minutes', 'keyring' ), $hours, $min );
		}
	}

	function extract_posts_from_data( $raw ) {
		$importdata = $raw;

		if ( null === $importdata ) {
			$this->finished = true;
			return new Keyring_Error( 'keyring-fitbit-importer-failed-download', __( 'Failed to download your data from Fitbit. Please wait a few minutes and try again.', 'keyring' ) );
		}

		// Make sure we have some data to parse
		if ( !is_object( $importdata ) || is_null( $importdata->summary ) ) {
			$this->finished = true;
			return;
		}

		$post_date = $this->date;

		// Apply selected category and tags
		$post_category = array( $this->get_option( 'category' ) );
		$tags          = $this->get_option( 'tags' );

		// Construct a post body containing a text-based summary of the data.
		$post_title    = __( 'Fitbit Summary', 'keyring' );

		// Using %s because the formatted number has commas/periods in it
		$post_content  = sprintf( __( 'Walked %s steps.' ), number_format_i18n( $importdata->summary->steps ) );

		// Other bits
		$post_author = $this->get_option( 'author' );
		$post_status = $this->get_option( 'status', 'publish' );
		$fitbit_raw  = $importdata; // Keep all of the things

		// Build the post array, and hang onto it along with the others
		$this->posts[] = compact(
			'post_author',
			'post_date',
			'post_content',
			'post_title',
			'post_status',
			'post_category',
			'tags',
			'fitbit_raw'
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

				add_post_meta( $post_id, 'raw_import_data', wp_slash( json_encode( $fitbit_raw ) ) );

				$imported++;

				do_action( 'keyring_post_imported', $post_id, static::SLUG, $post );
			}
		}
		$this->posts = array();

		// Return, so that the handler can output info (or update DB, or whatever)
		return array( 'imported' => $imported, 'skipped' => $skipped );
	}
}

} // end function Keyring_Fitbit_Importer


add_action( 'init', function() {
	Keyring_Fitbit_Importer(); // Load the class code from above
	keyring_register_importer(
		'fitbit',
		'Keyring_Fitbit_Importer',
		plugin_basename( __FILE__ ),
		__( 'Import your daily activities as single Posts, marked with the "status" format. You can also use the data as part of other maps or whatever else you\'d like to do with it.', 'keyring' )
	);
} );
