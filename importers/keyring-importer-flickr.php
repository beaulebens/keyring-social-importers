<?php

// This is a horrible hack, because WordPress doesn't support dependencies/load-order.
// We wrap our entire class definition in a function, and then only call that on a hook
// where we know that the class we're extending is available. *hangs head in shame*
function Keyring_Flickr_Importer() {

class Keyring_Flickr_Importer extends Keyring_Importer_Base {
	const SLUG              = 'flickr';    // e.g. 'twitter' (should match a service in Keyring)
	const LABEL             = 'Flickr';    // e.g. 'Twitter'
	const KEYRING_SERVICE   = 'Keyring_Service_Flickr';    // Full class name of the Keyring_Service this importer requires
	const REQUESTS_PER_LOAD = 3;     // How many remote requests should be made before reloading the page?
	const NUM_PER_REQUEST   = 25;     // Number of images per request to ask for

	function __construct() {
		parent::__construct();
		add_action( 'keyring_importer_flickr_custom_options', array( $this, 'custom_options' ) );
		add_action( 'keyring_importer_flickr_options_info', array( $this, 'options_info' ) );
	}

	function options_info() {
		?>
		<p><?php _e( 'Some notes before we start:', 'keyring' ); ?></p>
		<ul>
			<li><strong><?php _e( 'Each image will be downloaded and inserted as an individual Post within WordPress. Sets and Galleries will not be re-created.', 'keyring' ); ?></strong></li>
			<li><?php _e( 'By default, all images are downloaded (even private ones). If you do not want that to happen, make sure you read the options below carefully.', 'keyring' ); ?></li>
			<li><?php _e( 'It can take a long time to download everything. You should leave this window open/uninterrupted while processing.', 'keyring' ); ?></li>
		</ul>
		<?php
	}

	function custom_options() {
		?>
		<tr valign="top">
			<th scope="row">
				<label for="download_all"><?php _e( 'Download all images, including private', 'keyring' ); ?></label>
			</th>
			<td>
				<input type="checkbox" value="1" name="download_all" id="download_all"<?php echo checked( $this->get_option( 'download_all', true ) ); ?> />
			</td>
		</tr>
		<tr valign="top">
			<th scope="row">
				<label for="post_password"><?php _e( 'Apply password to non-public images', 'keyring' ); ?></label>
			</th>
			<td>
				<input type="text" value="<?php echo esc_attr( $this->get_option( 'post_password', '' ) ); ?>" name="post_password" id="post_password" />
				<p class="description"><?php _e( 'Any photos not available publicly on Flickr will be added to a password-protected post in WordPress. Leave empty to make them public.', 'keyring' ); ?></p>
			</td>
		</tr>
		<?php
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
			$this->step = 'greet';
		} else {
			$this->set_option( array(
				'status'          => (string) $_POST['status'],
				'category'        => (int) $_POST['category'],
				'tags'            => explode( ',', $_POST['tags'] ),
				'author'          => (int) $_POST['author'],
				'auto_import'     => (bool) $_POST['auto_import'],
				'post_password'   => (string) $_POST['post_password'],
			) );

			$this->step = 'import';
		}
	}

	function build_request_url() {
		// Base request URL -- http://www.flickr.com/services/api/flickr.people.getPhotos.html
		$extras = array( // get me all of the things
			'description',
			'owner_name',
			'date_upload',
			'date_taken',
			'original_format',
			'last_update',
			'geo',
			'tags',
			'machine_tags',
			'media',
			'url_o'
		);
		$url = "https://api.flickr.com/services/rest/?";
		$params = array(
			'method'         => 'flickr.people.getPhotos',
			'api_key'        => $this->service->key,
			'user_id'        => 'me',
			'per_page'       => self::NUM_PER_REQUEST,
			'safe_search'    => 3, // get everything
			'content_type'   => 7, // all types (will include videos)
			'extras'         => implode( ',', $extras ),
		);

		$url .= http_build_query( $params );


		if ( $this->auto_import ) {
			// Locate our most recently imported image, and get ones since then
			$latest = get_posts( array(
				'numberposts' => 1,
				'orderby'     => 'modified', // This is where we store date UPLOADED (not taken)
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
				$url = add_query_arg( 'min_upload_date', strtotime( $latest[0]->post_modified_gmt ) + 1, $url );
			}
			$url = add_query_arg( 'page', $this->get_option( 'auto_page', 1 ), $url );
		} else {
			// Handle page offsets (only for non-auto-import requests)
			$url = add_query_arg( 'page', $this->get_option( 'page', 0 ), $url );
		}

		return $url;
	}

	function extract_posts_from_data( $raw ) {
		$importdata = $raw;

		if ( null === $importdata ) {
			$this->finished = true;
			return new Keyring_Error( 'keyring-flickr-importer-failed-download', __( 'Failed to download your photos from Flickr. Please wait a few minutes and try again.', 'keyring' ) );
		}

		// Check for API overage/errors
		if ( 'ok' != $importdata->stat ) {
			$this->finished = true;
			return new Keyring_Error( 'keyring-flickr-importer-throttled', __( 'Something went wrong with your request. Maybe try again soon.', 'keyring' ) );
		}

		// Make sure we have something to parse
		if ( !is_array( $importdata->photos->photo ) || !count( $importdata->photos->photo ) ) {
			$this->finished = true;
			return;
		}

		// Get the total number of tweets we're importing
		if ( ! empty( $importdata->photos->total ) ) {
			$this->set_option( 'total', $importdata->photos->total );
		}

		// Parse/convert everything to WP post structs
		foreach ( $importdata->photos->photo as $post ) {
			// Title is easy
			$post_title = $post->title;

			// Parse/adjust dates
			// Date *UPLOADED* stored as post_modified_gmt
			$post_date_gmt     = $post->datetaken;
			$post_date         = get_date_from_gmt( $post_date_gmt );
			// Date *TAKEN* stored as post_date_gmt
			$post_modified_gmt = gmdate( 'Y-m-d H:i:s', $post->dateupload );
			$post_modified     = get_date_from_gmt( $post_modified_gmt );

			// Apply selected category
			$post_category = array( $this->get_option( 'category' ) );

			// Get a GUID from Flickr, plus other important IDs to store in postmeta later
			$flickr_id   = $post->id;
			$flickr_img  = $post->url_o;
			$flickr_url  = "http://www.flickr.com/photos/{$post->owner}/{$post->id}/"; // Use 'owner' (user-id) because it always works
			$post_author = $this->get_option( 'author' );
			$post_status = $this->get_option( 'status', 'publish' );

			// Lay out the post content, similar to Instagram importer
			$post_content = '<p class="flickr-image">';
			$post_content .= '<a href="' . esc_url( $flickr_url ) . '" class="flickr-link">';
			$post_content .= '<img src="' . esc_url( $flickr_img ) . '" alt="' . esc_attr( $post_title ) . '" class="flickr-img" />';
			$post_content .= '</a></p>';
			if ( ! empty( $post->description->_content ) ) {
				$post_content .= "\n<p class='flickr-caption'>" . $post->description->_content . '</p>';
			}

			// Tags are space-separated on Flickr. Throw any machine tags in with manual ones.
			$tags         = array_merge( $this->get_option( 'tags' ), explode( ' ', $post->tags ) );
			$machine_tags = explode( ' ', $post->machine_tags );
			$tags         = array_filter( array_merge( $tags, $machine_tags ) );

			// Password protect some photos
			if ( 1 == $post->ispublic ) {
				$post_password = '';
			} else {
				// Any kind of non-public photos get the password applied
				$post_password = $this->get_option( 'post_password', '' );
			}

			// Include geo data (if provided by Flickr)
			if ( ! empty( $post->latitude ) && !empty( $post->longitude ) ) {
				$geo = array(
					'lat'  => $post->latitude,
					'long' => $post->longitude,
				);
			} else {
				$geo = array();
			}

			// Keep a full copy of the raw data
			$flickr_raw = $post;

			// Build the post array, and hang onto it along with the others
			$this->posts[] = compact(
				'post_title',
				'post_date',
				'post_date_gmt',
				'post_modified',
				'post_modified_gmt',
				'post_category',
				'post_content',
				'post_author',
				'post_status',
				'post_password',
				'tags',
				'geo',
				'flickr_id',
				'flickr_img',
				'flickr_url',
				'flickr_raw'
			);
		}

		// For auto imports, handle paging
		if ( $this->auto_import ) {
			$this->set_option( 'auto_page', (int) $this->get_option( 'auto_page' ) + 1 );
		}
	}

	function insert_posts() {
		global $wpdb;
		$imported = 0;
		$skipped  = 0;
		foreach ( $this->posts as $post ) {
			extract( $post );
			if (
				$wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = 'flickr_id' AND meta_value = %s", $flickr_id ) )
			||
				$post_id = post_exists( $post_title, $post_content, $post_date )
			) {
				// Looks like a duplicate
				$skipped++;
			} else {
				$post_id = wp_insert_post( $post );

				if ( is_wp_error( $post_id ) ) {
					return $post_id;
				}

				if ( ! $post_id ) {
					continue;
				}

				// Mark it as an image
				set_post_format( $post_id, 'image' );

				// Track which Keyring service was used
				wp_set_object_terms( $post_id, self::LABEL, 'keyring_services' );

				// Store the flickr id
				add_post_meta( $post_id, 'flickr_id', $flickr_id );
				add_post_meta( $post_id, 'flickr_url', $flickr_url );

				// Update Category and Tags
				wp_set_post_categories( $post_id, $post_category );
				if ( count( $tags ) ) {
					wp_set_post_terms( $post_id, implode( ',', $tags ) );
				}

				// Store geodata if it's available
				if ( ! empty( $geo ) ) {
					add_post_meta( $post_id, 'geo_latitude', $geo['lat'] );
					add_post_meta( $post_id, 'geo_longitude', $geo['long'] );
					add_post_meta( $post_id, 'geo_public', 1 );
				}

				add_post_meta( $post_id, 'raw_import_data', wp_slash( json_encode( $flickr_raw ) ) );

				$this->sideload_media( $flickr_img, $post_id, $post, apply_filters( 'keyring_flickr_importer_image_embed_size', 'large' )  );

				$imported++;

				do_action( 'keyring_post_imported', $post_id, static::SLUG, $post );
			}
		}
		$this->posts = array();

		// Return, so that the handler can output info (or update DB, or whatever)
		return array( 'imported' => $imported, 'skipped' => $skipped );
	}

	function do_auto_import() {
		$this->set_option( 'auto_page', 1 );
		parent::do_auto_import();
	}
}

} // end function Keyring_Twitter_Importer


add_action( 'init', function() {
	Keyring_Flickr_Importer(); // Load the class code from above
	keyring_register_importer(
		'flickr',
		'Keyring_Flickr_Importer',
		plugin_basename( __FILE__ ),
		__( 'Download copies of your Flickr photos into your Media Library and save them all as individual Posts, marked with the "image" post format.', 'keyring' )
	);
} );
