<?php

// This is a horrible hack, because WordPress doesn't support dependencies/load-order.
// We wrap our entire class definition in a function, and then only call that on a hook
// where we know that the class we're extending is available. *hangs head in shame*
function Keyring_NestCam_Importer() {

class Keyring_NestCam_Importer extends Keyring_Importer_Base {
	const SLUG              = 'nest'; // e.g. 'twitter' (should match a service in Keyring)
	const LABEL             = 'Nest Camera'; // e.g. 'Twitter'
	const KEYRING_SERVICE   = 'Keyring_Service_Nest'; // Full class name of the Keyring_Service this importer requires

	function __construct() {
		parent::__construct();

		add_action( 'keyring_importer_header_css', array( $this, 'header_css' ) );
		add_action( 'keyring_importer_nest_custom_options', array( $this, 'custom_options' ) );
	}

	function header_css() {
		?>ul.cameras {
			margin: 0;
			padding: 0 0 0 1em;
		}
		ul.cameras li {
			margin-bottom: 1em;
		}
		<?php
	}

	function custom_options() {
		$response = $this->service->request( 'https://developer-api.nest.com/' );
		if ( Keyring_Util::is_error( $response ) ) {
			$meta = array();
		} else {
			if ( ! empty( $response->devices->cameras ) ) {
				echo '<tr><th>' . __( 'Auto-tag with cameras/structures', 'keyring' ) . '</th>';
				echo '<td><input type="checkbox" name="auto_tag" value="1" ' . checked( $this->get_option( 'auto_tag', true ), true, false ) . '/></td>';
				echo '</tr>';

				$schedule = $this->get_option( 'schedule' );
				$label    = array();
				echo '<tr><th>' . __( 'Cameras' ) . '</th><td>';
				echo '<p class="description">' . __( 'Ctrl/Cmd-click, or drag, to select multiple times to take a snapshot from each camera.', 'keyring' ) . '</p>';
				echo '<ul class="cameras">';
				foreach ( $response->devices->cameras as $id => $camera ) {
					if ( empty( $schedule[$id] ) ) {
						$times = array();
					} else {
						$times = $schedule[$id];
					}

					echo '<li>';

						// Which camera?
						echo '<p>' . $camera->name . '<br />';

						// On what schedule?
						echo '<select multiple="multiple" class="time" size="5" name="schedule[' . esc_attr( $id ) . '][]">';
						echo '<option ' . ( in_array( 0, $times ) ? 'selected="selected"' : '' ) . '>' . esc_attr( 'None' ) . '</option>';
						echo '<option ' . ( in_array( 1, $times ) ? 'selected="selected"' : '' ) . ' value="1">1:00am</option>';
						echo '<option ' . ( in_array( 2, $times ) ? 'selected="selected"' : '' ) . ' value="2">2:00am</option>';
						echo '<option ' . ( in_array( 3, $times ) ? 'selected="selected"' : '' ) . ' value="3">3:00am</option>';
						echo '<option ' . ( in_array( 4, $times ) ? 'selected="selected"' : '' ) . ' value="4">4:00am</option>';
						echo '<option ' . ( in_array( 5, $times ) ? 'selected="selected"' : '' ) . ' value="5">5:00am</option>';
						echo '<option ' . ( in_array( 6, $times ) ? 'selected="selected"' : '' ) . ' value="6">6:00am</option>';
						echo '<option ' . ( in_array( 7, $times ) ? 'selected="selected"' : '' ) . ' value="7">7:00am</option>';
						echo '<option ' . ( in_array( 8, $times ) ? 'selected="selected"' : '' ) . ' value="8">8:00am</option>';
						echo '<option ' . ( in_array( 9, $times ) ? 'selected="selected"' : '' ) . ' value="9">9:00am</option>';
						echo '<option ' . ( in_array( 10, $times ) ? 'selected="selected"' : '' ) . ' value="10">10:00am</option>';
						echo '<option ' . ( in_array( 11, $times ) ? 'selected="selected"' : '' ) . ' value="11">11:00am</option>';
						echo '<option ' . ( in_array( 12, $times ) ? 'selected="selected"' : '' ) . ' value="12">12:00pm</option>';
						echo '<option ' . ( in_array( 13, $times ) ? 'selected="selected"' : '' ) . ' value="13">1:00pm</option>';
						echo '<option ' . ( in_array( 14, $times ) ? 'selected="selected"' : '' ) . ' value="14">2:00pm</option>';
						echo '<option ' . ( in_array( 15, $times ) ? 'selected="selected"' : '' ) . ' value="15">3:00pm</option>';
						echo '<option ' . ( in_array( 16, $times ) ? 'selected="selected"' : '' ) . ' value="16">4:00pm</option>';
						echo '<option ' . ( in_array( 17, $times ) ? 'selected="selected"' : '' ) . ' value="17">5:00pm</option>';
						echo '<option ' . ( in_array( 18, $times ) ? 'selected="selected"' : '' ) . ' value="18">6:00pm</option>';
						echo '<option ' . ( in_array( 19, $times ) ? 'selected="selected"' : '' ) . ' value="19">7:00pm</option>';
						echo '<option ' . ( in_array( 20, $times ) ? 'selected="selected"' : '' ) . ' value="20">8:00pm</option>';
						echo '<option ' . ( in_array( 21, $times ) ? 'selected="selected"' : '' ) . ' value="21">9:00pm</option>';
						echo '<option ' . ( in_array( 22, $times ) ? 'selected="selected"' : '' ) . ' value="22">10:00pm</option>';
						echo '<option ' . ( in_array( 23, $times ) ? 'selected="selected"' : '' ) . ' value="23">11:00pm</option>';
						echo '<option ' . ( in_array( 24, $times ) ? 'selected="selected"' : '' ) . ' value="24">12:00am</option>';
						echo '</select></p>';

					echo '</li>';
				}
				echo "</ul></td></tr>";
			}
		}
	}

	function handle_request_options() {
		// Validate options and store them so they can be used in auto-imports
		if ( empty( $_POST['status'] ) || ! in_array( $_POST['status'], array( 'publish', 'pending', 'draft', 'private' ) ) ) {
			$this->error( __( "Make sure you select a valid post status to assign to your imported posts.", 'keyring' ) );
		}

		if ( empty( $_POST['category'] ) || ! ctype_digit( $_POST['category'] ) ) {
			$this->error( __( "Make sure you select a valid category to import your snapshots into.", 'keyring' ) );
		}

		if ( empty( $_POST['author'] ) || ! ctype_digit( $_POST['author'] ) ) {
			$this->error( __( "You must select an author to assign to all snapshots.", 'keyring' ) );
		}

		if ( isset( $_POST['auto_import'] ) ) {
			$_POST['auto_import'] = true;
		} else {
			$_POST['auto_import'] = false;
		}

		if ( isset( $_POST['auto_tag'] ) ) {
			$_POST['auto_tag'] = true;
		} else {
			$_POST['auto_tag'] = false;
		}

		if ( ! isset( $_POST['schedule'] ) ) {
			$_POST['schedule'] = array();
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
				'auto_tag'    => $_POST['auto_tag'],
				'schedule'    => $_POST['schedule'], // array of camera_id=>hours
			) );

			$this->step = 'import';
		}
	}

	function build_request_url() {
		// Everything in Nest is part of a single "state" document.
		// There is only one URL we care about.
		return 'https://developer-api.nest.com';
	}

	function extract_posts_from_data( $importdata ) {
		if ( null === $importdata ) {
			$this->finished = true;
			return new Keyring_Error( 'keyring-nest-importer-failed-download', __( 'Failed to download data from Nest. Please wait a few minutes and try again.', 'keyring' ) );
		}

		// Make sure we have some data
		if ( ! is_object( $importdata ) || ! count( $importdata->devices ) || ! count( $importdata->devices->cameras ) ) {
			$this->finished = true;
			return;
		}

		// Get the entire schedule; if nothing is scheduled then bail now
		$schedule = $this->get_option( 'schedule' );
		if ( ! is_array( $schedule ) || ! count( $schedule ) ) {
			$this->finished = true;
			return;
		}

		// Parse/convert everything to WP post structs
		foreach ( $schedule as $camera => $times ) {
			if ( empty( $importdata->devices->cameras->{$camera} ) ) {
				continue;
			} else {
				$camera = $importdata->devices->cameras->{$camera};
			}

			// Always skip this camera if it has no scheduled times
			if ( ! count( $times ) ) {
				continue;
			}

			// If we're not doing a manual regresh, and this one is not scheduled for this hour, skip
			if ( ! isset( $_POST['refresh'] ) && ! in_array( current_time( 'G' ), $times ) ) {
				continue;
			}

			// Post title can be empty for Images, but it makes them easier to manage if they have *something*
			$post_title = $camera->name;

			// Parse/adjust dates
			$post_date_gmt = gmdate( 'Y-m-d H:i:s' );
			$post_date     = get_date_from_gmt( $post_date_gmt );

			// Apply selected category
			$post_category = array( $this->get_option( 'category' ) );

			// Pass the URL so we can grab it later
			$nest_img = $camera->snapshot_url;

			// Use the camera/structure names as tags?
			$tags = array();
			if ( $this->get_option( 'auto_tag' ) ) {
				// Camera name
				$tags[] = $camera->name;

				if (
					! empty( $camera->structure_id )
				&&
					! empty( $importdata->structures->{$camera->structure_id}->name )
				) {
					// Structure name
					$tags[] = $importdata->structures->{$camera->structure_id}->name;
				}
			}

			// Set up the data for Places support
			$places = array();

			// Structure
			$places[] = array(
				'id'   => $camera->structure_id,
				'name' => $importdata->structures->{$camera->structure_id}->name,
			);

			// Camera
			$places[] = array(
				'id'   => $camera->device_id,
				'name' => $camera->name,
			);

			// Construct a post body. By default we'll just link to the external image.
			// In insert_posts() we'll attempt to download/replace that with a local version.
			$post_content = '<p class="nest-cam">';
			$post_content .= '<img src="###" width="640" height="360" class="nest-cam-img" />';
			$post_content .= '</a></p>';

			// Other bits
			$post_author = $this->get_option( 'author' );
			$post_status = $this->get_option( 'status', 'publish' );
			$nest_raw    = $camera;

			// Build the post array, and hang onto it along with the others
			$this->posts[] = compact(
				'post_author',
				'post_date',
				'post_date_gmt',
				'post_content',
				'post_title',
				'post_status',
				'post_category',
				'nest_img',
				'tags',
				'places',
				'nest_raw'
			);
		}
	}

	function insert_posts() {
		// These are needed to handle directly downloading images from Nest
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'wp_read_image_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		global $wpdb;
		$imported = 0;
		$skipped  = 0;
		foreach ( $this->posts as $post ) {
			// See the end of extract_posts_from_data() for what is in here
			extract( $post );

			if ( $post_id = post_exists( $post_title, $post_content, $post_date ) ) {
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

				// Track which Keyring service was used
				wp_set_object_terms( $post_id, self::LABEL, 'keyring_services' );

				// Mark it as an image
				set_post_format( $post_id, 'image' );

				// Update Category
				wp_set_post_categories( $post_id, $post_category );

				// Raw data
				add_post_meta( $post_id, 'raw_import_data', wp_slash( json_encode( $nest_raw ) ) );

				// Tags
				if ( count( $tags ) ) {
					wp_set_post_terms( $post_id, implode( ',', $tags ) );
				}

				// Handle linking to a global location, if People & Places is available
				if ( ! empty( $places ) && class_exists( 'People_Places' ) ) {
					foreach ( (array) $places as $place ) {
						People_Places::add_place_to_post( static::SLUG, $place['id'], $place, $post_id );
					}
				}

				// Download and handle the image. We have to do this pretty manually
				// because Nest has weird URLs, so they trip up all of WordPress' security
				// protections against unknown mimetypes. They're just jpgs :(
				$response = wp_safe_remote_get( $nest_img );
				if ( 200 != wp_remote_retrieve_response_code( $response ) ) {
					// LOL the internet is a terrible place, full of transient problems.
					// If the download didn't work the first time, just try again after a pause.
					sleep( 10 );
					set_time_limit( 60 );
					$response = wp_safe_remote_get( $nest_img );
				}
				$bits = wp_remote_retrieve_body( $response );
				if ( ! empty( $bits ) ) {
					unset( $response ); // we don't need 2 copies in memory
					$upload = wp_upload_bits( 'nest-' . sanitize_title( $post_title ) . '.jpg', null, $bits );
					if ( empty( $upload['error'] ) ) {
						// Insert attachment, and create thumbnails/metadata
						$attach = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg' ), $upload['file'], $post_id, true );
						if ( ! is_wp_error( $attach ) ) {
							$data = wp_generate_attachment_metadata( $attach, $upload['file'] );
							wp_update_attachment_metadata( $attach, $data );
							set_post_thumbnail( $post_id, $attach );
						} else {
							Keyring_Util::debug( 'NEST: wp_insert_attachment failed' );
							Keyring_Util::debug( print_r( $attach, true ) );
						}

						// Update the post with the new, local URL to the image
						$post_data = get_post( $post_id );
						$post_data->post_content = str_replace( '###', $upload['url'], $post_data->post_content );
						wp_update_post( $post_data );
					} else {
						Keyring_Util::debug( 'NEST: wp_upload_bits failed' );
						Keyring_Util::debug( print_r( $upload, true ) );
					}

					$imported++;

					do_action( 'keyring_post_imported', $post_id, static::SLUG, $post );
				} else {
					Keyring_Util::debug( 'NEST: no bits in response' );
					Keyring_Util::debug( print_r( $response, true ) );
				}
			}
		}
		$this->posts = array();

		$this->finished = true;

		// Return, so that the handler can output info (or update DB, or whatever)
		return array( 'imported' => $imported, 'skipped' => $skipped );
	}
}

} // end function Keyring_NestCam_Importer


add_action( 'init', function() {
	Keyring_NestCam_Importer(); // Load the class code from above
	keyring_register_importer(
		'nest',
		'Keyring_NestCam_Importer',
		plugin_basename( __FILE__ ),
		__( 'Periodically take a snapshot from your Nest Cameras. Creates a post for each snapshot, and saves the image in your Media Library.', 'keyring' )
	);
} );

// Add importer-specific integration for People & Places (if installed)
add_action( 'init', function() {
	if ( class_exists( 'People_Places') ) {
		Taxonomy_Meta::add( 'places', array(
			'key'   => 'nest',
			'label' => __( 'Nest Location id', 'keyring' ),
			'type'  => 'text',
			'help'  => __( "Unique identifier from Nest (unique-looking hash).", 'keyring' ),
			'table' => false,
		) );
	}
}, 102 );
