<?php

// This is a horrible hack, because WordPress doesn't support dependencies/load-order.
// We wrap our entire class definition in a function, and then only call that on a hook
// where we know that the class we're extending is available. *hangs head in shame*
function Keyring_Jetpack_Importer() {

class Keyring_Jetpack_Importer extends Keyring_Importer_Base {
	const SLUG              = 'jetpack'; // e.g. 'twitter' (should match a service in Keyring)
	const LABEL             = 'Jetpack'; // e.g. 'Twitter'
	const KEYRING_SERVICE   = 'Keyring_Service_Jetpack'; // Full class name of the Keyring_Service this importer requires
	const REQUESTS_PER_LOAD = 3; // How many remote requests should be made before reloading the page when interactively importing?
	const POSTS_PER_REQUEST = 25; // How many posts should we ask for in each request?

	function __construct() {
		parent::__construct();

		add_filter( 'keyring_importer_reprocessors', function( $reprocessors ) {
			$reprocessors[ 'jetpack-featured-meta' ] = array(
				'label'       => __( 'Jetpack postmeta and Featured Images', 'keyring' ),
				'description' => __( 'Download and attach featured images, properly import postmeta, and sideload all the images in posts. WARNING: Will likely end up downloading a lot of media/images.', 'keyring' ),
				'callback'    => array( $this, 'reprocess_featured_meta' ),
				'service'     => $this->taxonomy->slug,
			);
			return $reprocessors;
		} );

		add_action( 'keyring_importer_jetpack_custom_options', array( $this, 'custom_options' ) );
		add_filter( 'wp_head', array( $this, 'wp_head' ), 1 );
	}

	// Don't index single-post pages for imported posts, to avoid duplicate content issues
	function wp_head() {
		if ( ! is_single() ) {
			return;
		}

		// Force noindex on pages that will contain imported content to avoid dupes in search engines
		if ( has_term( self::SLUG, 'keyring_services', get_queried_object() ) ) {
			echo '<meta name="robots" content="noindex,follow" >';
		}
	}

	function custom_options() {
		?><tr valign="top">
			<th scope="row">
				<label for="site"><?php _e( 'Import posts from', 'keyring' ); ?></label>
			</th>
			<td>
				<select name="site">
					<?php
					// Get the site list for this user, and display it with the selected one marked
					$response = $this->service->request(
						'https://public-api.wordpress.com/rest/v1.1/me/sites',
						array(
							'timeout' => 60 // Required for accounts with a ton of sites
						)
					);
					if ( Keyring_Util::is_error( $response ) ) {
						$meta = array();
					} else {
						foreach ( $response->sites as $site ) {
							echo '<option value="' . esc_attr( $site->ID ) . '"' . selected( $site->ID, $this->get_option( 'site' ), false ) . '>' . esc_attr( $site->name ) . ' (' . esc_attr( $site->URL ) . ')</option>';
						}
					}
					?>
				</select>
			</td>
		</tr><?php
	}

	function handle_request_options() {
		// Validate options and store them so they can be used in auto-imports
		if ( empty( $_POST['status'] ) || ! in_array( $_POST['status'], array( 'publish', 'pending', 'draft', 'private' ) ) ) {
			$this->error( __( "Make sure you select a valid post status to assign to your imported posts.", 'keyring' ) );
		}

		if ( empty( $_POST['site'] ) || ! ctype_digit( $_POST['site'] )  ) {
			$this->error( __( "You must select a site from which to import posts.", 'keyring' ) );
		}

		if ( empty( $_POST['category'] ) || ! ctype_digit( $_POST['category'] ) ) {
			$this->error( __( "Make sure you select a valid category to import your links into.", 'keyring' ) );
		}

		if ( empty( $_POST['author'] ) || ! ctype_digit( $_POST['author'] ) ) {
			$this->error( __( "You must select an author to assign to all imported links.", 'keyring' ) );
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
				'site'        => (int) $_POST['site'],
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
		// Get posts for the site we're importing from
		$url = sprintf( "https://public-api.wordpress.com/rest/v1.1/sites/%s/posts/", $this->get_option( 'site' ) );
		$url = add_query_arg(
			array(
				'number' => self::POSTS_PER_REQUEST,
			),
			$url
		);

		if ( $this->auto_import ) {
			// Get most recent post we've imported (if any), and its date so that we can get new ones since then
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
				$url = add_query_arg(
					array(
						'after'    => urlencode( date( 'Y-m-d H:i:s', strtotime( $latest[0]->post_date_gmt ) + 1 ) ),
						'order_by' => 'date',
						'order'    => 'ASC',
					),
					$url
				);
			}
		} else {
			// Handle page offsets (only for non-auto-import requests)
			$url = add_query_arg(
				array(
					'page'     => $this->get_option( 'page', 1 ),
					'order_by' => 'date',
					'order'    => 'DESC',
				),
				$url
			);
		}

		return $url;
	}

	function extract_posts_from_data( $raw ) {
		$importdata = $raw;

		if ( null === $importdata ) {
			$this->finished = true;
			return new Keyring_Error( 'keyring-jetpack-importer-failed-download', __( 'Failed to download or parse posts from WordPress.com. Please wait a few minutes and try again.', 'keyring' ) );
		}

		// .found should be there
		if ( ! property_exists( $importdata, 'found' ) || ! property_exists( $importdata, 'posts' ) ) {
			$this->finished = true;
			return new Keyring_Error( 'keyring-jetpack-importer-no-posts', __( 'No posts were found for the site.', 'keyring' ) );
		}

		// if .found is 0, then we have no posts, we're done
		if ( 0 == $importdata->found || empty( $importdata->posts ) ) {
			$this->finished = true;
			return;
		}

		// Parse/convert everything to WP post structs
		foreach ( $importdata->posts as $post ) {

			$post_title = $post->title;

			// Parse/adjust dates
			$post_date_gmt = date( 'Y-m-d H:i:s', strtotime( $post->date ) );
			$post_date     = get_date_from_gmt( $post_date_gmt );

			// Apply selected category (intentionally ignores the categories from the original post)
			$post_category = array( $this->get_option( 'category' ) );

			// Get the default/forced ones, and merge with tags from the post
			$tags = array_merge( $this->get_option( 'tags' ), array_keys( (array) $post->tags ) );

			// Postmeta
			$meta = array();
			foreach ( (array) $post->metadata as $postmeta ) {
				if ( '_' == substr( $postmeta->key, 0, 1 ) ) {
					continue; // skip private meta
				}
				$meta[ $postmeta->key ] = $postmeta->value;
			}

			// Featured Image
			$featured_image = false;
			if ( !empty( $post->post_thumbnail ) ) {
				$featured_image = $post->post_thumbnail->URL;
			}

			// Basic post details
			$href = $post->URL;
			$post_content = $post->content;
			$post_excerpt = trim( $post->excerpt );

			// Other bits
			$post_author = $this->get_option( 'author' );
			$post_status = $this->get_option( 'status', 'publish' );
			$post_format = $post->format;
			$jetpack_id  = $post->global_ID;
			$jetpack_raw = $post;

			// Build the post array, and hang onto it along with the others
			$this->posts[] = compact(
				'post_author',
				'post_date',
				'post_date_gmt',
				'post_content',
				'post_excerpt',
				'post_title',
				'post_status',
				'post_format',
				'post_category',
				'meta',
				'featured_image',
				'tags',
				'href',
				'jetpack_id',
				'jetpack_raw'
			);
		}
	}

	function insert_posts() {
		global $wpdb;
		$imported = 0;
		$skipped  = 0;
		foreach ( $this->posts as $post ) {
			extract( $post );
			if (
				! $jetpack_id
			||
				$wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = 'jetpack_id' AND meta_value = %s", $jetpack_id ) )
			||
				$post_id = post_exists( $post_title, $post_content, $post_date )
			) {
				// Looks like a duplicate, which means we've already processed it
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

				// Assign post format based on what it was originally
				set_post_format( $post_id, $post_format );

				// Update Category
				wp_set_post_categories( $post_id, $post_category );

				add_post_meta( $post_id, 'jetpack_id', $jetpack_id );
				add_post_meta( $post_id, 'href', $href );

				// Apply default + imported tags
				if ( count( $tags ) ) {
					wp_set_post_terms( $post_id, implode( ',', $tags ) );
				}

				// Add any custom postmeta found in the import
				if ( count( $meta ) ) {
					foreach ( $meta as $key => $val ) {
						add_post_meta( $post_id, $key, $val );
					}
				}

				// Parse out and sideload any media contained in the post itself
				preg_match_all( '!<img\s[^>]*src=[\'"]([^\'"]+)[\'"][^>]*>!', $post_content, $matches );
				if ( ! empty( $matches[1] ) ) {
					$this->sideload_media( array_values( $matches[1] ), $post_id, $post, apply_filters( 'keyring_jetpack_importer_image_embed_size', 'full' ), 'inline' );
				}

				// If there's a featured image, then sideload and apply it.
				// Do this after the previous media check to make sure Featured Image is set correctly.
				if ( $featured_image ) {
					$this->sideload_media( $featured_image, $post_id, $post, apply_filters( 'keyring_jetpack_importer_image_embed_size', 'full' ), 'featured' );
				}

				add_post_meta( $post_id, 'raw_import_data', wp_slash( json_encode( $jetpack_raw ) ) );

				$imported++;

				do_action( 'keyring_post_imported', $post_id, static::SLUG, $post );
			}
		}
		$this->posts = array();

		// Return, so that the handler can output info (or update DB, or whatever)
		return array( 'imported' => $imported, 'skipped' => $skipped );
	}

	/**
	 * Download and attach Featured images, add postmeta, and sideload all
	 * images in posts.
	 */
	function reprocess_featured_meta( $post ) {
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

		// Pull out and apply postmeta
		if ( ! empty( $raw->metadata ) ) {
			foreach ( $raw->metadata as $meta ) {
				update_post_meta( $post->ID, $meta->key, $meta->value );
			}
		}

		// Parse out and sideload any media contained in the post itself
		preg_match_all( '!<img\s[^>]*src=[\'"]([^\'"]+)[\'"][^>]*>!', $raw->content, $matches );
		if ( count( $matches ) && ! empty( $matches[1] ) ) {
			$this->sideload_media( array_values( $matches[1] ), $post->ID, (array) $post, apply_filters( 'keyring_jetpack_importer_image_embed_size', 'full' ), 'inline' );
		}

		// If there's a featured image, then sideload and apply it.
		// Do this after the previous media check to make sure Featured Image is set correctly.
		if ( $raw->post_thumbnail ) {
			$this->sideload_media( $raw->post_thumbnail->URL, $post->ID, (array) $post, apply_filters( 'keyring_jetpack_importer_image_embed_size', 'full' ), 'featured' );
		}

		return Keyring_Importer_Reprocessor::PROCESS_SUCCESS;
	}
}

} // end function Keyring_Jetpack_Importer


add_action( 'init', function() {
	Keyring_Jetpack_Importer(); // Load the class code from above
	keyring_register_importer(
		'jetpack',
		'Keyring_Jetpack_Importer',
		plugin_basename( __FILE__ ),
		__( 'Import posts from a Jetpack-powered WordPress site (WordPress.com or self-hosted), using the Jetpack REST API.', 'keyring' )
	);
} );
