<?php

class Keyring_Importer_Reprocessor {
	const POSTS_PER_BATCH  = 50;
	const BATCHES_PER_PAGE = 3;

	// Used as return codes from the processor
	const PROCESS_SKIPPED = 0;
	const PROCESS_SUCCESS = 1;
	const PROCESS_FAILED  = 2;

	var $reprocessor = false;

	function __construct() {
		add_action( 'init', array( $this, 'register' ) );

		// Bundled reprocessor for fixing old JSON encoding problem
		add_filter( 'keyring_importer_reprocessors', function( $reprocessors ) {
			$reprocessors[ 'json-encoding' ] = array(
				'label'       => __( 'Old JSON Encoding Problem', 'keyring' ),
				'description' => __( 'In an early version of the Keyring Social Importers, <code>raw_import_data</code> was saved without the correct escaping, so it became useless. This attempts to read and decode that data, then re-save it with correct escaping. Try running this before running any other reprocessing. You only ever need to run it once.', 'keyring' ),
				'callback'    => array( $this, 'json_escaping' ),
				'service'     => false, // all services
			);
			return $reprocessors;
		}, 1 ); // Top of the list
	}

	/**
	 * Register the reprocessor as an importer, which gives us an easy UI into it.
	 */
	function register() {
		register_importer(
			'keyring-importer-reprocess',
			__( 'Reprocess Keyring Data', 'keyring' ),
			__( 'Reprocess the locally-stored copy of raw import data for posts created using the Keyring Social Importers.', 'keyring' ),
			array( $this, 'dispatch' )
		);
	}

	/**
	 * A default, basic header for the importer UI
	 */
	function header() {
		?>
		<style type="text/css">
			.keyring-importer ul,
			.keyring-importer ol { margin: 1em 2em; }
			.keyring-importer li { list-style-type: square; }
			#auto-message { margin-left: 10px; }
		</style>
		<div class="wrap keyring-importer">
		<h2><?php _e( 'Reprocess Keyring Data', 'keyring' ); ?></h2><?php
	}

	function dispatch() {
		if ( isset( $_REQUEST['step'] ) && in_array( $_REQUEST['step'], array( 'reprocessor', 'reprocess' ) ) ) {
			$step = $_REQUEST['step'];
		} else {
			$step = 'reprocessor';
		}

		switch ( $step ) {
		case 'reprocessor';
			$this->cleanup();
			$this->select_reprocessor();
			break;

		case 'reprocess';
			$reprocessors = $this->get_list_of_reprocessors();
			if ( empty( $_REQUEST['reprocessor'] ) || ! in_array( $_REQUEST['reprocessor'], array_keys( $reprocessors ) ) ) {
				// Try again
				$this->select_reprocessor();
				break;
			}
			$this->reprocessor = $reprocessors[ $_REQUEST['reprocessor'] ];
			update_option( 'keyring_batch_processing_reprocessor', $_REQUEST['reprocessor'] );
			$this->reprocess_posts();
			break;
		}
	}

	/**
	 * Default, basic footer for importer UI
	 */
	function footer() {
		do_action( 'keyring_importer_reprocess_footer' );
		echo '</div>';
	}

	/**
	 * Developers can hook into this filter to add their own custom reprocessors
	 * to the available list.
	 * @return Array of arrays containing information for each reprocessor.
	 *                  The key of each array is used as a slug in links etc.
	 *                  Each array should include:
	 *                  - label: A user-facing label/title
	 *                  - description: Helpful description that explains what this does
	 *                  - callback: Executable method to call for each posts. Will be passed a $post object only
	 *                  - service: Slug for the term which identifies the service this applies to, or `false` to indicate all available posts
	 */
	function get_list_of_reprocessors() {
		return apply_filters( 'keyring_importer_reprocessors', array() );
	}

	/**
	 * Main output of the first screen.
	 */
	function select_reprocessor() {
		$this->header();

		echo '<h3>' . esc_html__( 'Available Reprocessors', 'keyring' ) . '</h3>';
		echo '<p>' . esc_html__( 'Select from the list below to reprocess your previously-imported posts in some way.', 'keyring' ) . '</p>';
		echo '<p><em>' . esc_html__( 'Processing will begin immediately, and will continue until all relevant posts have been processed automatically.', 'keyring' ) . '</em></p>';

		// List out available reprocessors, with a link on each to select it.
		echo '<ul>';
		foreach ( $this->get_list_of_reprocessors() as $repro => $details ) {
			echo '<li>';
			echo '<strong><a href="' . $this->reprocessor_url( $repro ) . '">' . esc_html( $details['label'] ) . '</a></strong>';
			echo '<p class="description">' . wp_kses_post( $details['description'] ) . '</p>';
			echo '</li>';
		}
		echo '</ul>';

		$this->footer();
	}

	function reprocessor_url( $repro = false ) {
		$args = array(
			'import' => 'keyring-importer-reprocess',
		);

		if ( $repro ) {
			$args['reprocessor'] = $repro;
			$args['step']        = 'reprocess';
		}

		return add_query_arg(
			$args,
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Return a list of all available Keyring Social Importers.
	 * @return Array containing the slugs of available importers.
	 */
	function get_list_of_importers() {
		global $_keyring_importers;
		return apply_filters( 'keyring_importer_reprocess_importers', array_keys( $_keyring_importers ) );
	}

	/**
	 * Execute the reprocessor selected, and reprocess whatever posts are relevant.
	 */
	function reprocess_posts() {
		$this->header();

		$results = get_option( 'keyring_batch_processing_results', array( 'skipped' => 0, 'success' => 0, 'failed' => 0 ) );

		echo '<p>' . sprintf( __( 'Reprocessing Posts using %s' ), $this->reprocessor['label'] ) . '</p>';
		echo '<ol>';

		$num = 0;
		while ( $num < static::BATCHES_PER_PAGE ) {
			$batch = 0;
			// Should get (at most) POSTS_PER_BATCH posts to process
			$posts = $this->get_next_batch_of_posts( $this->reprocessor['service'] );
			foreach ( (array) $posts as $post ) {
				// Call the reprocessor itself with this post
				$result = call_user_func( $this->reprocessor['callback'], $post );

				// Keep track of how we're doing
				switch ( $result ) {
				case static::PROCESS_SKIPPED:
					$results['skipped']++;
					break;

				case static::PROCESS_SUCCESS:
					$results['success']++;
					break;

				case static::PROCESS_FAILED:
					$results['failed']++;
					break;
				}

				$batch++;
			}

			// One-line summary to show progress in each batch.
			echo '<li>';
			printf(
				__( 'We have successfully re-processed %1$s, skipped %2$s and failed on %3$s posts overall.' ),
				number_format( $results['success'] ),
				number_format( $results['skipped'] ),
				number_format( $results['failed'] )
			);
			echo '</li>';

			// Local (per-page-load) counter
			$num++;
		}

		update_option( 'keyring_batch_processing_results', $results );

		echo '</ol>';
		$this->footer();

		// If we got less than a complete batch, then we're done.
		if ( $batch < static::POSTS_PER_BATCH ) {
			$this->cleanup();

			// @todo better summary/ending point
			echo '<h3>' . __( 'All done, thanks.', 'keyring' ) . '</h3>';
			echo '<p><a href="';
			echo esc_url( $this->reprocessor_url() );
			echo '">' . __( '← Back to Reprocessors', 'keyring' ) . '</a></p>';
		} else {
			$this->next();
		}
	}

	/**
	 * Just reloads the reprocessing screen, which relies on options in the DB
	 * to process the next batch(es)
	 */
	function next() {
		echo '<form action="' . $this->reprocessor_url( get_option( 'keyring_batch_processing_reprocessor' ) ) . '" method="post" id="keyring-reprocessor">';
		echo wp_nonce_field( 'keyring-reprocessor', '_wpnonce', true, false );
		echo wp_referer_field( false );
		echo '<p><input type="submit" class="button-primary" value="' . __( 'Continue with next batch', 'keyring' ) . '" /> <span id="auto-message"></span></p>';
		echo '<p><input type="button" value="' . __( 'Cancel', 'keyring' ) . '" class="button" onclick="document.location.href=\'' . esc_url( $this->reprocessor_url() ) . '\'" /></p>';
		echo '</form>';

		?><script type="text/javascript">
			next_counter = 3;
			jQuery(document).ready(function(){
				reprocessor_msg();
			});

			function reprocessor_msg() {
				str = '<?php _e( "Continuing in #num#", 'keyring' ) ?>';
				jQuery( '#auto-message' ).text( str.replace( /#num#/, next_counter ) );
				if ( next_counter <= 0 ) {
					if ( jQuery( '#keyring-reprocessor' ).length ) {
						jQuery( "#keyring-reprocessor input[type='submit']" ).hide();
						jQuery( "#keyring-reprocessor input[type='button']" ).hide();
						var str = '<?php _e( 'Continuing', 'keyring' ); ?> <img src="<?php echo esc_url( admin_url( '/images/loading.gif' ) ); ?>" alt="" id="processing" align="top" width="16" height="16" />';
						jQuery( '#auto-message' ).html( str );
						jQuery( '#keyring-reprocessor' ).submit();
						return;
					}
				}
				next_counter = next_counter - 1;
				setTimeout( 'reprocessor_msg()', 1000 );
			}
		</script><?php
	}

	/**
	 * Clean up any options we use
	 */
	function cleanup() {
		delete_option( 'keyring_batch_processing_offset' );
		delete_option( 'keyring_batch_processing_reprocessor' );
		delete_option( 'keyring_batch_processing_results' );
	}

	/**
	 * Helper for re-processing/working with existing posts. You can keep calling
	 * this and it will keep giving you batches of POSTS_PER_BATCH posts that were imported by
	 * the current importer. It'll eventually give you an empty array.
	 * @return Array of posts objects
	 */
	function get_next_batch_of_posts( $tax = false ) {
		if ( ! $offset = get_option( 'keyring_batch_processing_offset' ) ) {
			$offset = 0;
		}

		if ( false === $tax ) {
			// Process ALL raw_import_data
			$found = get_posts(  array(
				'posts_per_page' => static::POSTS_PER_BATCH,
				'offset'         => $offset,
				'orderby'        => 'date',
				'order'          => 'ASC',
				'meta_key'       => 'raw_import_data',
			) );
		} else {
			// Query posts from specific import services
			$found = get_posts( array(
				'posts_per_page' => static::POSTS_PER_BATCH,
				'offset'         => $offset,
				'orderby'        => 'date',
				'order'          => 'ASC', // Start on the oldest, and work up, to try to catch them all if more are coming in live.
				'tax_query'      => array( array(
					'taxonomy'     => 'keyring_services',
					'field'        => 'slug',
					'terms'        => array( $tax ),
					'operator'     => 'IN',
				) ),
			) );
		}

		update_option( 'keyring_batch_processing_offset', $offset += count( $found ) );

		return $found;
	}

	/**
	 * Bundled reprocessor that attempts to fix an old JSON encoding/escaping problem.
	 * WordPress is awesome, so sometimes it handles escaping things "properly" for you,
	 * and sometimes it just garbles your data and makes it irretrievable, thanks brah!
	 * This attempts to patch back together the JSON data saved for previously-imported
	 * posts so that you could possibly use that for other reprocessing.
	 */
	function json_escaping( $post ) {
		$raw = get_post_meta( $post->ID, 'raw_import_data', true );
		if ( empty( $raw ) || ! is_string( $raw ) ) {
			return static::PROCESS_SKIPPED; // no data available for this one, or it's not a string
		}

		// Attempt to decode it to see if we can read this JSON
		$try = json_decode( $raw );
		if ( null == $try ) {
			// Failed to decode! Probably bad JSON because of WordPress, attempt some known fixes.
			// Add slashes to the contents of specific properties
			// "source":"<a href="http://foursquare.com" rel="nofollow">foursquare</a>",
			$raw = preg_replace_callback( '/"(?:source|text|description|title|extended|shout|_content)":"(.*)"(,(?=")|})/Ui', function( $matches ) {
				return str_replace( $matches[1], str_replace( array( '\\', '"' ), array( '\\\\', '\"' ), $matches[1] ), $matches[0] );
			}, $raw );

			// Try decoding our modified version
			$try = json_decode( $raw );
			if ( null !== $try ) {
				// Looks like that worked, let's save this new version "safely"
				update_post_meta( $post->ID, 'raw_import_data', wp_slash( json_encode( $try ) ) );
				return static::PROCESS_SUCCESS; // we can now work with this JSON, :thumbsup:
			} else {
				return static::PROCESS_FAILED; // our proessing resulted in an unparseable JSON object :(
			}
		}
		return static::PROCESS_SKIPPED; // data looks OK
	}
}

new Keyring_Importer_Reprocessor();
