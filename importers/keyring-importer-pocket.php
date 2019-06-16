<?php

// This is a horrible hack, because WordPress doesn't support dependencies/load-order.
// We wrap our entire class definition in a function, and then only call that on a hook
// where we know that the class we're extending is available. *hangs head in shame*
function Keyring_Pocket_Importer() {

class Keyring_Pocket_Importer extends Keyring_Importer_Base {
	const SLUG              = 'pocket'; // e.g. 'twitter' (should match a service in Keyring)
	const LABEL             = 'Pocket'; // e.g. 'Twitter'
	const KEYRING_SERVICE   = 'Keyring_Service_Pocket'; // Full class name of the Keyring_Service this importer requires
	const REQUESTS_PER_LOAD = 3; // How many remote requests should be made before reloading the page?
	const NUM_PER_REQUEST   = 50; // Number of pins per request to ask for

	var $request_method     = 'POST';

	function handle_request_options() {
		// Validate options and store them so they can be used in auto-imports
		if ( empty( $_POST['category'] ) || ! ctype_digit( $_POST['category'] ) ) {
			$this->error( __( "Make sure you select a valid category to import your Pocket links into." ) );
		}

		if ( empty( $_POST['author'] ) || ! ctype_digit( $_POST['author'] ) ) {
			$this->error( __( "You must select an author to assign to all Pocket links." ) );
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
				'category'    => (int) $_POST['category'],
				'tags'        => explode( ',', $_POST['tags'] ),
				'author'      => (int) $_POST['author'],
				'auto_import' => $_POST['auto_import'],
			) );

			$this->step = 'import';
		}
	}

	function get_base_url() {
		$args = array(
			'consumer_key' => $this->service->key,
			'access_token' => $this->service->get_token()->token,
			'count'        => self::NUM_PER_REQUEST,
			'sort'         => 'oldest',
			'state'        => 'archive',
			'detailType'   => 'complete'
		);
		return add_query_arg( $args, 'https://getpocket.com/v3/get' );
	}

	function build_request_url() {
		$url      = $this->get_base_url();
		$next_url = $this->get_option( 'next_request_url' );
		if ( $next_url ) {
			$url = $next_url;
			$this->set_option( 'next_request_url', null );
		} else if ( $this->auto_import ) {
			$url = add_query_arg( 'since', $this->get_latest_pocket_ts(), $url );
		}

		return $url;
	}

	function get_latest_pocket_ts() {
		$latest_ts = time();
		$latest    = get_posts( array(
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
			$latest_ts = strtotime( $latest[0]->post_modified_gmt );
		}
		return $latest_ts;
	}

	function parse_pocket_title( $link ) {
		// By default, Pocket pulls the title from the link's given HTML markup ( given_title )
		// Pocket may be able to parse the title ( resolved_title )
		if ( isset( $link->resolved_title ) && ! empty( $link->resolved_title ) ) {
			return $link->resolved_title;
		}
		if ( isset( $link->given_title ) && ! empty( $link->given_title ) ) {
			return $link->given_title;
		}
		return false;
	}

	function parse_pocket_url( $link ) {
		if ( isset( $link->resolved_url ) && ! empty( $link->resolved_url ) ) {
			return $link->resolved_url;
		}
		if ( isset( $link->given_url ) && ! empty( $link->given_url ) ) {
			return $link->given_url;
		}
		return false;
	}

	function mark_as_finished() {
		$this->finished = true;
		$this->set_option( 'next_request_url', null );
		$this->set_option( 'current_offset', 0 );
	}

	function extract_posts_from_data( $importdata ) {
		global $wpdb;

		if ( null === $importdata || ! isset( $importdata->list ) ) {
			$this->finished = true;
			return new Keyring_Error( 'keyring-pocket-importer-failed-download', __( 'Failed to download or parse your links from Pocket.' ) );
		}

		$list = (array) $importdata->list;

		// Make sure we have some bookmarks to parse
		if ( empty( $list )  ) {
			$this->mark_as_finished();
			return;
		}

		$current_offset = $this->get_option( 'current_offset' );
		$this->set_option( 'current_offset', $current_offset + count( $list ) );

		// Parse/convert everything to WP post structs
		foreach ( $list as $link ) {

			// skip over unusable data
			if ( ! isset( $link->item_id ) ) {
				continue;
			}
			$post_title = $this->parse_pocket_title( $link );
			$href = $this->parse_pocket_url( $link );

			if ( ! isset( $link->time_added ) || ! isset( $link->time_updated ) || ! $href || ! $post_title ) {
				$this->posts[] = array( 'pocket_id' => $link->item_id );
				continue;
			}

			$post_date_gmt_ts = $link->time_added;
			$post_date_gmt    = gmdate( 'Y-m-d H:i:s', $post_date_gmt_ts );
			$post_date        = get_date_from_gmt( $post_date_gmt );

			// Pocket orders by modification date, so we need to store that
			$post_modified_gmt_ts = $link->time_updated;
			$post_modified_gmt    = gmdate( 'Y-m-d H:i:s', $post_modified_gmt_ts );
			$post_modified        = get_date_from_gmt( $post_modified_gmt );

			// Apply selected category
			$post_category = array( $this->get_option( 'category' ) );

			// Merge link's tags with with selected tags
			$tags = isset( $link->tags ) ?
				array_keys( (array) $link->tags ) :
				array();

			if ( isset( $link->tags ) ) {
				$tags = array_merge( array_keys( (array) $link->tags ), $tags );
			}

			if ( ! empty( $this->get_option( 'tags' ) ) ) {
				$tags = array_unique( array_merge( $this->get_option( 'tags' ), $tags ) );
			}

			$post_content = sprintf(
				'<p><a href="%s" class="pocket-title">%s</a></p>',
				esc_url( $href, null, 'href' ),
				esc_html( $post_title )
			);

			if ( $link->has_image ) {
				$img = false;
				if ( isset( $link->image ) ) {
					$img = $link->image;
				} else if ( isset( $link->images ) ) {
					$images = (array) $link->images;
					$img = array_shift( $images );
				}

				if ( $img ) {
					$post_content .= sprintf(
						'<p><a href="%s"><img src="%s" alt="%s" /></a></p>',
						esc_url( $href, null, 'href' ),
						esc_url( $img->src, null, 'src' ),
						esc_attr( $post_title )
					);
				}
			}

			// Other bits
			$post_author = $this->get_option( 'author' );
			$post_status = 'publish';
			$pocket_id   = $link->item_id;
			$pocket_raw  = $link;

			// Build the post array, and hang onto it along with the others
			$this->posts[] = compact(
				'post_author',
				'post_date',
				'post_date_gmt',
				'post_content',
				'post_title',
				'post_status',
				'post_category',
				'pocket_id',
				'tags',
				'href',
				'pocket_raw',
				'post_modified_gmt',
				'post_modified'
			);
		}
	}

	function insert_posts() {
		global $wpdb;
		$imported = 0;
		$skipped  = 0;

		foreach ( $this->posts as $post ) {
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = 'pocket_id' AND meta_value = %s", $post['pocket_id'] ) ) ) {
				$skipped++;
			} else {
				$post_id = wp_insert_post( $post );

				if ( is_wp_error( $post_id ) ) {
					continue;
				}

				if ( ! $post_id ) {
					continue;
				}

				// Track which Keyring service was used
				wp_set_object_terms( $post_id, self::LABEL, 'keyring_services' );

				// Mark it as a link
				set_post_format( $post_id, 'link' );

				// Update Category
				wp_set_post_categories( $post_id, $post['post_category'] );

				add_post_meta( $post_id, 'pocket_id', $post['pocket_id'] );
				add_post_meta( $post_id, 'href', $post['href'] );

				if ( count( $post['tags'] ) ) {
					wp_set_post_terms( $post_id, implode( ',', $post['tags'] ) );
				}

				add_post_meta( $post_id, 'raw_import_data', wp_slash( json_encode( $post['pocket_raw'] ) ) );

				$imported++;

				do_action( 'keyring_post_imported', $post_id, static::SLUG, $post );
			}
		}

		if ( count( $this->posts ) === $skipped ) {
			$this->mark_as_finished();
		} else {
			$this->set_option(
				'next_request_url',
				add_query_arg(
					array(
						'offset' => $this->get_option( 'current_offset' ),
						'since'  => $this->get_latest_pocket_ts()
					),
					$this->get_base_url()
				)
			);
		}

		$this->posts = array();

		// Return, so that the handler can output info (or update DB, or whatever)
		return array( 'imported' => $imported, 'skipped' => $skipped );
	}
}

} // end function Keyring_Pinterest_Importer


add_action( 'init', function() {
	Keyring_Pocket_Importer(); // Load the class code from above
	keyring_register_importer(
		'pocket',
		'Keyring_Pocket_Importer',
		plugin_basename( __FILE__ ),
		__( 'Import your links from Pocket (getpocket.com), and create a new Post for each, with the "Link" format.', 'keyring' )
	);
} );
