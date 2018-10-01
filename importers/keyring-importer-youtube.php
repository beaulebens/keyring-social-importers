<?php

// This is a horrible hack, because WordPress doesn't support dependencies/load-order.
// We wrap our entire class definition in a function, and then only call that on a hook
// where we know that the class we're extending is available. *hangs head in shame*
function Keyring_YouTube_Importer() {

class Keyring_YouTube_Importer extends Keyring_Importer_Base {
	const SLUG              = 'youtube';    // e.g. 'twitter' (should match a service in Keyring)
	const LABEL             = 'YouTube';    // e.g. 'Twitter'
	const KEYRING_SERVICE   = 'Keyring_Service_YouTube';    // Full class name of the Keyring_Service this importer requires
	const REQUESTS_PER_LOAD = 3;     // How many remote requests should be made before reloading the page?
	const NUM_PER_REQUEST   = 1;     // Number of videos per request to ask for

	function handle_request_options() {
		// Validate options and store them so they can be used in auto-imports
		if ( empty( $_POST['status'] ) || ! in_array( $_POST['status'], array( 'publish', 'pending', 'draft', 'private' ) ) ) {
			$this->error( __( "Make sure you select a valid post status to assign to your imported posts.", 'keyring' ) );
		}

		if ( empty( $_POST['category'] ) || ! ctype_digit( $_POST['category'] ) ) {
			$this->error( __( "Make sure you select a valid category to import your videos into.", 'keyring' ) );
		}

		if ( empty( $_POST['author'] ) || ! ctype_digit( $_POST['author'] ) ) {
			$this->error( __( "You must select an author to assign to all videos.", 'keyring' ) );
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
		// https://developers.google.com/youtube/v3/docs/search/list
		$url = "https://www.googleapis.com/youtube/v3/search/?type=video&forMine=true&part=snippet&order=date&maxResults=" . self::NUM_PER_REQUEST;

		// Paging is handled via unique tokens in the YouTube API. If we've got one, then get the next page.
		if ( $token = $this->get_option( 'nextPageToken' ) ) {
			$this->set_option( 'nextPageToken', null );
			$url .= '&pageToken=' . $token;
		}

		return $url;
	}

	function extract_posts_from_data( $raw ) {
		$importdata = $raw;

		if ( null === $importdata ) {
			$this->finished = true;
			return new Keyring_Error( 'keyring-youtube-importer-failed-download', __( 'Failed to download your videos from YouTube. Please wait a few minutes and try again.', 'keyring' ) );
		}

		// Make sure we have some pictures to parse
		if ( ! is_object( $importdata ) || ! count( $importdata->items ) ) {
			$this->finished = true;
			return;
		}

		// Parse/convert everything to WP post structs
		foreach ( $importdata->items as $post ) {
			if ( ! empty( $post->snippet->title ) ) {
				$post_title = strip_tags( $post->snippet->title );
			}

			// Parse/adjust dates
			$post_date_gmt = strtotime( $post->snippet->publishedAt );
			$post_date_gmt = gmdate( 'Y-m-d H:i:s', $post_date_gmt );
			$post_date     = get_date_from_gmt( $post_date_gmt );

			// Apply selected category
			$post_category = array( $this->get_option( 'category' ) );

			// Construct a post body.
			$post_content = 'https://www.youtube.com/watch?v=' . $post->id->videoId; // Just rely on WP embeds for now

			// Tags
			$tags = $this->get_option( 'tags' );
			if ( is_array( $tags ) && ! empty( $post->tags ) ) {
				$tags = array_merge( $tags, $post->tags );
			}

			// Other bits
			$post_author = $this->get_option( 'author' );
			$post_status = $this->get_option( 'status', 'publish' );
			$youtube_id  = $post->id->videoId;
			$youtube_url = 'https://www.youtube.com/watch?v=' . $post->id->videoId; // Is this the canonical?
			$youtube_raw = $post;

			$youtube_img = $post->snippet->thumbnails->high->url;
			if ( isset( $post->snippet->thumbnails->maxres ) ) {
				$youtube_img = $post->snippet->thumbnails->maxres->url;
			}

			// Build the post array, and hang onto it along with the others
			$this->posts[] = compact(
				'post_title',
				'post_date_gmt',
				'post_date',
				'post_category',
				'post_content',
				'tags',
				'post_author',
				'post_status',
				'youtube_id',
				'youtube_url',
				'youtube_img',
				'youtube_raw'
			);
		}

		// Grab the cursor for the next URL to request
		if ( ! empty( $importdata->nextPageToken ) ) {
			$this->set_option( 'nextPageToken', $importdata->nextPageToken );
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
				! $youtube_id
			||
				$wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = 'youtube_id' AND meta_value = %s", $youtube_id ) )
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

				// Track which Keyring service was used, and mark it as a video
				wp_set_object_terms( $post_id, self::LABEL, 'keyring_services' );
				set_post_format( $post_id, 'video' );

				// Update Category
				wp_set_post_categories( $post_id, $post_category );

				add_post_meta( $post_id, 'youtube_id', $youtube_id );
				add_post_meta( $post_id, 'youtube_url', $youtube_url );

				if ( count( $tags ) ) {
					wp_set_post_terms( $post_id, implode( ',', $tags ) );
				}

				// Must wp_slash to avoid decoding issues later
				add_post_meta( $post_id, 'raw_import_data', wp_slash( json_encode( $youtube_raw ) ) );

				// Sideload thumbnail image just for "completeness"
				// Will also set it as the Featured Image
				$this->sideload_media( $youtube_img, $post_id, $post, apply_filters( 'keyring_youtube_importer_image_embed_size', 'full' ), 'none' );

				// Sideload the video itself
				// $this->sideload_video( $youtube_video, $post_id );

				$imported++;

				do_action( 'keyring_post_imported', $post_id, static::SLUG, $post );
			}
		}
		$this->posts = array();

		// If we're doing a normal import and the last request was all skipped, then we're at "now"
		if ( ! $this->auto_import && self::NUM_PER_REQUEST == $skipped ) {
			$this->finished = true;
		}

		// Return, so that the handler can output info (or update DB, or whatever)
		return array( 'imported' => $imported, 'skipped' => $skipped );
	}
}

} // end function Keyring_YouTube_Importer

// Register Importer
add_action( 'init', function() {
	Keyring_YouTube_Importer(); // Load the class code from above
	keyring_register_importer(
		'youtube',
		'Keyring_YouTube_Importer',
		plugin_basename( __FILE__ ),
		__( 'Automatically create new posts based on the videos you upload to YouTube. Posts will use the post format "video", and a thumbnail will be downloaded and set as the Featured Image.', 'keyring' )
	);
} );
