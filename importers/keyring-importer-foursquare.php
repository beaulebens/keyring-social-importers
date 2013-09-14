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

	var $auto_import = false;

	function handle_request_options() {
		// Validate options and store them so they can be used in auto-imports
		if ( empty( $_POST['category'] ) || !ctype_digit( $_POST['category'] ) )
			$this->error( __( "Make sure you select a valid category to import your checkins into." ) );

		if ( empty( $_POST['author'] ) || !ctype_digit( $_POST['author'] ) )
			$this->error( __( "You must select an author to assign to all checkins." ) );

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
		$url = "https://api.foursquare.com/v2/users/" . $this->get_option( 'user_id', 'self' ) . "/checkins?limit=200";

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
			$url = add_query_arg( 'offset', $this->get_option( 'page', 0 ) * 200, $url );
		}

		return $url;
	}

	function extract_posts_from_data( $raw ) {
		global $wpdb;

		$importdata = $raw;

		if ( null === $importdata ) {
			$this->finished = true;
			return new Keyring_Error( 'keyring-foursquare-importer-failed-download', __( 'Failed to download your checkins from foursquare. Please wait a few minutes and try again.' ) );
		}

		// Make sure we have some checkins to parse
		if ( !is_object( $importdata ) || !count( $importdata->response->checkins->items ) ) {
			$this->finished = true;
			return;
		}

		// Parse/convert everything to WP post structs
		foreach ( $importdata->response->checkins->items as $post ) {
			// Sometimes the venue has no name. There's not much we can do with these.
			if ( empty( $post->venue->name ) )
				continue;

			// Post title can be empty for Status, but it makes them easier to manage if they have *something*
			$post_title = sprintf( __( 'Checked in at %s', 'keyring' ), $post->venue->name );

			// Parse/adjust dates
			$post_date_gmt = $post->createdAt;
			$post_date_gmt = gmdate( 'Y-m-d H:i:s', $post_date_gmt );
			$post_date = get_date_from_gmt( $post_date_gmt );

			// Apply selected category
			$post_category = array( $this->get_option( 'category' ) );

			// Construct a post body
			if ( !empty( $post->venue->id ) )
				$venue_link = '<a href="' . esc_url( 'http://foursquare.com/v/' . $post->venue->id ) . '" class="foursquare-link">' . esc_html( $post->venue->name ) . '</a>';
			else
				$venue_link = $post->venue->name;
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
				if ( preg_match_all( '/(^|[(\[\s])#(\w+)/', $post->shout, $tag ) )
					$tags = array_merge( $tags, $tag[2] );

				$post_content .= "\n\n<blockquote class='foursquare-note'>" . $post->shout . "</blockquote>";
			}

			// Include geo Data
			$geo = array(
				'lat'  => $post->venue->location->lat,
				'long' => $post->venue->location->lng,
			);

			$photos = array();

			if ( $post->photos->count > 0 ) {
				foreach ( $post->photos->items as $photo ) {
					$photos[] = $photo->prefix . "original" . $photo->suffix;
				}
			}

			// Other bits
			$post_author    = $this->get_option( 'author' );
			$post_status    = 'publish';
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
				'photos'
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
				!$foursquare_id
			||
				$wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = 'foursquare_id' AND meta_value = %s", $foursquare_id ) )
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

				$post['ID'] = $post_id;

				// Track which Keyring service was used
				wp_set_object_terms( $post_id, self::LABEL, 'keyring_services' );

				// Mark it as an aside
				set_post_format( $post_id, 'status' );

				// Update Category
				wp_set_post_categories( $post_id, $post_category );

				add_post_meta( $post_id, 'foursquare_id', $foursquare_id );

				if ( count( $tags ) )
					wp_set_post_terms( $post_id, implode( ',', $tags ) );

				// Store geodata if it's available
				if ( !empty( $geo ) ) {
					add_post_meta( $post_id, 'geo_latitude', $geo['lat'] );
					add_post_meta( $post_id, 'geo_longitude', $geo['long'] );
					add_post_meta( $post_id, 'geo_public', 1 );
				}

				add_post_meta( $post_id, 'raw_import_data', json_encode( $foursquare_raw ) );

				if ( ! empty( $photos ) ) {
					foreach ( $photos as $photo ) {
						$this->sideload_media( $photo, $post_id, $post, apply_filters( 'keyring_foursquare_importer_image_embed_size', 'full' ) );
					}
				}

				$imported++;

				do_action( 'keyring_post_imported', $post_id, static::SLUG, $post );
			}
		}
		$this->posts = array();

		// Return, so that the handler can output info (or update DB, or whatever)
		return array( 'imported' => $imported, 'skipped' => $skipped );
	}
}

} // end function Keyring_Foursquare_Importer


add_action( 'init', function() {
	Keyring_Foursquare_Importer(); // Load the class code from above
	keyring_register_importer(
		'foursquare',
		'Keyring_Foursquare_Importer',
		plugin_basename( __FILE__ ),
		__( 'Download all of your Foursquare checkins as individual Posts (with a "status" post format).', 'keyring' )
	);
} );
