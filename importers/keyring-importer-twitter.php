<?php

// This is a horrible hack, because WordPress doesn't support dependencies/load-order.
// We wrap our entire class definition in a function, and then only call that on a hook
// where we know that the class we're extending is available. *hangs head in shame*
function Keyring_Twitter_Importer() {

class Keyring_Twitter_Importer extends Keyring_Importer_Base {
	const SLUG              = 'twitter';    // e.g. 'twitter' (should match a service in Keyring)
	const LABEL             = 'Twitter';    // e.g. 'Twitter'
	const KEYRING_SERVICE   = 'Keyring_Service_Twitter';    // Full class name of the Keyring_Service this importer requires
	const REQUESTS_PER_LOAD = 3;     // How many remote requests should be made before reloading the page?

	function __construct() {
		parent::__construct();
		add_action( 'keyring_importer_twitter_custom_options', array( $this, 'custom_options' ) );

		// Fix old newline problem with Twitter importer
		add_filter( 'keyring_importer_reprocessors', function( $reprocessors ) {
			$reprocessors[ 'twitter-newlines' ] = array(
				'label'       => __( 'Correct newline handling in Tweets', 'keyring' ),
				'description' => __( 'Old tweets were imported with newlines being handled incorrectly. The importer handles them correctly now, and this will reprocess old data to handle them on already-imported tweets.', 'keyring' ),
				'callback'    => array( $this, 'reprocess_newlines' ),
				'service'     => $this->taxonomy->slug,
			);
			return $reprocessors;
		} );

		// If we have People & Places available, then allow re-processing old posts as well
		if ( class_exists( 'People_Places' ) ) {
			add_filter( 'keyring_importer_reprocessors', function( $reprocessors ) {
				$reprocessors[ 'twitter-people' ] = array(
					'label'       => __( 'People mentioned in Tweets, or retweeted', 'keyring' ),
					'description' => __( 'Identify People mentioned/retweeted in your tweets, and assign them via taxonomy.', 'keyring' ),
					'callback'    => array( $this, 'reprocess_people' ),
					'service'     => $this->taxonomy->slug,
				);
				return $reprocessors;
			} );
		}

		// Fix shortened links in Twitter importer
		add_filter( 'keyring_importer_reprocessors', function( $reprocessors ) {
			$reprocessors[ 'twitter-shortlinks' ] = array(
				'label'       => __( 'Expand shortened links in Tweets', 'keyring' ),
				'description' => __( 'Old tweets preserved the Twitter link shortener version of URLs. The importer handles them correctly now, and this will reprocess old data to use the full URL.', 'keyring' ),
				'callback'    => array( $this, 'reprocess_shortened_links' ),
				'service'     => $this->taxonomy->slug,
			);
			return $reprocessors;
		} );
	}

	function custom_options() {
		?><tr valign="top">
			<th scope="row">
				<label for="include_rts"><?php _e( 'Import retweets', 'keyring' ); ?></label>
			</th>
			<td>
				<input type="checkbox" value="1" name="include_rts" id="include_rts"<?php echo checked( $this->get_option( 'include_rts', true ) ); ?> />
			</td>
		</tr>
		<tr valign="top">
			<th scope="row">
				<label for="include_replies"><?php _e( 'Import @replies', 'keyring' ); ?></label>
			</th>
			<td>
				<input type="checkbox" value="1" name="include_replies" id="include_replies"<?php echo checked( $this->get_option( 'include_replies', true ) ); ?> />
			</td>
		</tr><?php
	}

	function handle_request_options() {
		// Advanced Tools
		if ( isset( $_REQUEST['repro-people'] ) ) {
			$this->reprocess_people();
			return;
		}

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

		if ( ! empty( $_POST['auto_import'] ) ) {
			$_POST['auto_import'] = true;
		} else {
			$_POST['auto_import'] = false;
		}

		if ( ! empty( $_POST['include_rts'] ) ) {
			$_POST['include_rts'] = true;
		} else {
			$_POST['include_rts'] = false;
		}

		if ( ! empty( $_POST['include_replies'] ) ) {
			$_POST['include_replies'] = true;
		} else {
			$_POST['include_replies'] = false;
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
				'include_replies' => (bool) $_POST['include_replies'],
				'include_rts'     => (bool) $_POST['include_rts'],
				'auto_import'     => (bool) $_POST['auto_import'],
				'user_id'         => $this->service->get_token()->get_meta( 'user_id' ),
			) );

			$this->step = 'import';
		}
	}

	function build_request_url() {
		// Base request URL
		$url = "https://api.twitter.com/1.1/statuses/user_timeline.json?";
		$params = array(
			'user_id'             => $this->get_option( 'user_id' ),
			'trim_user'           => 'false',
			'count'               => 75, // More than this and Twitter seems to get flaky
			'include_entities'    => 'true',
			'contributor_details' => 'true',
		);

		// Replies?
		if ( false == $this->get_option( 'include_replies' ) ) {
			$params['exclude_replies'] = 'true';
		}

		// Retweets?
		if ( true == $this->get_option( 'include_rts' ) ) {
			$params['include_rts'] = 'true';
		} else {
			$params['include_rts'] = 'false';
		}

		$url .= http_build_query( $params );


		if ( $this->auto_import ) {
			// Locate our most recently imported Tweet, and get ones since then
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
				$max = get_post_meta( $latest[0]->ID, 'twitter_id', true );
				$url = add_query_arg( 'since_id', $max, $url );
			}
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
			return new Keyring_Error( 'keyring-twitter-importer-failed-download', __( 'Failed to download your tweets from Twitter. Please wait a few minutes and try again.', 'keyring' ) );
		}

		// Check for API overage/errors
		if ( ! empty( $importdata->error ) ) {
			$this->finished = true;
			return new Keyring_Error( 'keyring-twitter-importer-throttled', __( 'You have made too many requests to Twitter and have been temporarily blocked. Please try again in 1 hour (duplicate tweets will be skipped).', 'keyring' ) );
		}

		// Make sure we have some tweets to parse
		if ( ! is_array( $importdata ) || ! count( $importdata ) ) {
			$this->finished = true;
			return;
		}

		// Get the total number of tweets we're importing
		if ( ! empty( $importdata[0]->user->statuses_count ) ) {
			$this->set_option( 'total', $importdata[0]->user->statuses_count );
		}

		// Parse/convert everything to WP post structs
		foreach ( $importdata as $post ) {
			// Double-check for @replies, which shouldn't be included at all if we chose to skip them
			if ( true == $this->get_option( 'exclude_replies' ) && null != $post->in_reply_to_screen_name ) {
				continue;
			}

			// Post title can be empty for Asides, but it makes them easier to manage if they have *something*
			$title_words = explode( ' ', strip_tags( $post->text ) );
			$post_title  = implode( ' ', array_slice( $title_words, 0, 5 ) ); // Use the first 5 words
			if ( count( $title_words ) > 5 ) {
				$post_title .= '&hellip;';
			}

			// Parse/adjust dates
			$post_date_gmt = strtotime( $post->created_at );
			$post_date_gmt = gmdate( 'Y-m-d H:i:s', $post_date_gmt );
			$post_date     = get_date_from_gmt( $post_date_gmt );

			// Apply selected category
			$post_category = array( $this->get_option( 'category' ) );

			$post_content = $post->text;

			// Better content for retweets
			if ( ! empty( $post->retweeted_status ) ) {
				$post_content = $post->retweeted_status->text;

				// Replace any RETWEETED shortened URLs with the real thing
				if ( ! empty( $post->retweeted_status->entities->urls ) ) {
					foreach ( $post->retweeted_status->entities->urls as $url ) {
						$post_content = str_replace( $url->url, esc_url( $url->expanded_url ), $post_content );
					}
				}

				// Include any RETWEETED media URLs
				if ( ! empty( $post->retweeted_status->extended_entities->media ) ) {
					foreach ( $post->retweeted_status->extended_entities->media as $image ) {
						$post_content = str_replace( $image->url, esc_url( $image->expanded_url ), $post_content );
					}
				}
			}

			// Replace any shortened URLs with the real thing
			if ( ! empty( $post->entities->urls ) ) {
				foreach ( $post->entities->urls as $url ) {
					$post_content = str_replace( $url->url, esc_url( $url->expanded_url ), $post_content );
				}
			}

			// Include any media URLs
			if ( ! empty( $post->extended_entities->media ) ) {
				foreach ( $post->extended_entities->media as $image ) {
					$post_content = str_replace( $image->url, esc_url( $image->expanded_url ), $post_content );
				}
			}

			// Clean up post content for insertion
			$post_content = esc_sql( html_entity_decode( trim( $post_content ) ) );

			// Add HTML links to URLs, usernames and hashtags
			$post_content = make_clickable( esc_html( $post_content ) );

			// Grab any images associated with this tweet
			$images = false;
			$extended_media = array();
			if ( ! empty( $post->extended_entities->media ) ) {
				$images = array();
				foreach ( $post->extended_entities->media as $image ) {
					$img_url = $image->media_url_https;
					if ( ! empty( $image->sizes->large ) ) {
						$img_url .= ':large'; // Use biggest available
					}
					$images[] = $img_url;
				}
			}

			// Any hashtags used in a tweet will be applied to the Post as tags in WP
			$tags = $this->get_option( 'tags' );
			if ( preg_match_all( '/(^|[(\[\s])#(\w+)/', $post_content, $tag ) ) {
				$tags = array_merge( $tags, $tag[2] );
			}

			// Include geo Data (if provided by Twitter)
			if ( ! empty( $post->geo ) && 'point' == strtolower( $post->geo->type ) ) {
				$geo = array(
					'lat' => $post->geo->coordinates[0],
					'long' => $post->geo->coordinates[1]
				);
			} else {
				$geo = array();
			}

			// Any people mentioned in this tweet
			// Relies on the People & Places plugin to store (later)
			$people = array();
			if ( ! empty( $post->entities->user_mentions ) ) {
				foreach ( $post->entities->user_mentions as $user ) {
					$people[ $user->screen_name ] = array(
						'name' => trim( $user->name )
					);
				}
			}

			// Get a GUID from Twitter, plus other important IDs to store in postmeta later
			$user = $this->service->get_token()->get_meta( 'username' );
			$twitter_id              = $post->id_str;
			$twitter_permalink       = "https://twitter.com/{$user}/status/{$twitter_id}";
			$in_reply_to_user_id     = $post->in_reply_to_user_id;
			$in_reply_to_screen_name = $post->in_reply_to_screen_name;
			$in_reply_to_status_id   = $post->in_reply_to_status_id;
			$post_author             = $this->get_option( 'author' );
			$post_status             = $this->get_option( 'status', 'publish' );
			$twitter_raw             = $post;

			// Build the post array, and hang onto it along with the others
			$this->posts[] = compact(
				'post_author',
				'post_date',
				'post_date_gmt',
				'post_content',
				'post_title',
				'post_status',
				'post_category',
				'tags',
				'twitter_id',
				'twitter_permalink',
				'geo',
				'in_reply_to_user_id',
				'in_reply_to_screen_name',
				'in_reply_to_status_id',
				'twitter_raw',
				'images',
				'people'
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
				$wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = 'twitter_id' AND meta_value = %s", $twitter_id ) )
			||
				$post_id = post_exists( $post_title, $post_content, $post_date )
			) {
				// Looks like a duplicate
				$skipped++;
			} else {
				$post['post_content'] = wpautop( str_replace( '\n', "\n", $post['post_content'] ) );

				$post_id = wp_insert_post( $post );

				if ( is_wp_error( $post_id ) ) {
					return $post_id;
				}

				if ( ! $post_id ) {
					continue;
				}

				// Mark it as an aside
				set_post_format( $post_id, 'aside' );

				// Track which Keyring service was used
				wp_set_object_terms( $post_id, self::LABEL, 'keyring_services' );

				// Store the twitter id, reply ids etc
				add_post_meta( $post_id, 'twitter_id', $twitter_id );
				add_post_meta( $post_id, 'twitter_permalink', $twitter_permalink );
				if ( ! empty( $in_reply_to_user_id ) ) {
					add_post_meta( $post_id, 'twitter_in_reply_to_user_id', $in_reply_to_user_id );
				}
				if ( ! empty( $in_reply_to_screen_name ) ) {
					add_post_meta( $post_id, 'twitter_in_reply_to_screen_name', $in_reply_to_screen_name );
				}
				if ( ! empty( $in_reply_to_status_id ) ) {
					add_post_meta( $post_id, 'twitter_in_reply_to_status_id', $in_reply_to_status_id );
				}

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

				add_post_meta( $post_id, 'raw_import_data', wp_slash( json_encode( $twitter_raw ) ) );

				if ( ! empty( $images ) ) {
					$images = array_reverse( $images ); // Reverse so they stay in order when prepended
					foreach ( $images as $image ) {
						$this->sideload_media( $image, $post_id, $post, apply_filters( 'keyring_twitter_importer_image_embed_size', 'full' ) );
					}
				}

				// If we found people, and have the People & Places plugin available
				// to handle processing/storing, then store references to people against
				// this tweet as well.
				if ( ! empty( $people ) && class_exists( 'People_Places' ) ) {
					foreach ( $people as $value => $person ) {
						People_Places::add_person_to_post( static::SLUG, $value, $person, $post_id );
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

	/**
	 * Reprocess a $post and identify/link up People.
	 */
	function reprocess_people( $post ) {
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

		// Mentions
		if ( ! empty( $raw->entities->user_mentions ) ) {
			foreach ( $raw->entities->user_mentions as $user ) {
				People_Places::add_person_to_post(
					static::SLUG,
					$user->screen_name,
					array(
						'name' => trim( $user->name )
					),
					$post->ID
				);
			}
		}

		return Keyring_Importer_Reprocessor::PROCESS_SUCCESS;
	}

	/**
	 * Don't botch the escaping on newlines.
	 */
	function reprocess_newlines( $post ) {
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

		// Don't bother doing anything if there are no newlines in the raw content
		if ( ! stristr( $raw->text, "\n" ) ) {
			return Keyring_Importer_Reprocessor::PROCESS_SKIPPED;
		}

		$post_content = $raw->text;

		// Better content for retweets
		if ( !empty( $raw->retweeted_status ) ) {
			$post_content = $raw->retweeted_status->text;
		}

		// Clean up post content for insertion
		$post_content = esc_sql( html_entity_decode( trim( $post_content ) ) );

		// Add HTML links to URLs, usernames and hashtags
		$post_content = make_clickable( esc_html( $post_content ) );

		$post_content = wpautop( str_replace( '\n', "\n", $post_content ) );

		$post_data = get_post( $post->ID );
		$post_data->post_content = $post_content;
		wp_update_post( $post_data );

		return Keyring_Importer_Reprocessor::PROCESS_SUCCESS;
	}

	/*
	 * Fix shortened links
	 */
	function reprocess_shortened_links( $post ) {
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

		// Don't bother if there are no URLs to replace
		if ( empty( $raw->entities->urls ) && empty( $raw->extended_entities->media ) ) {
			return Keyring_Importer_Reprocessor::PROCESS_SKIPPED;
		}

		// We start with the current post content instead of the original raw tweet,
		// since the import does some adjusting and polishing we don't want to lose.
		$post_content = $post->post_content;

		// Replace any URLs
		if ( ! empty( $raw->entities->urls ) ) {
			foreach ( $raw->entities->urls as $url ) {
				$post_content = str_replace( $url->url, esc_url( $url->expanded_url ), $post_content );
			}
		}

		// Include any media URLs
		if ( ! empty( $raw->extended_entities->media ) ) {
			foreach ( $raw->extended_entities->media as $image ) {
				$post_content = str_replace( $image->url, esc_url( $image->expanded_url ), $post_content );
			}
		}

		// Replace any RETWEET URLs
		if ( ! empty( $raw->retweeted_status->entities->urls ) ) {
			foreach ( $raw->retweeted_status->entities->urls as $url ) {
				$post_content = str_replace( $url->url, esc_url( $url->expanded_url ), $post_content );
			}
		}

		// Include any RETWEET media URLs
		if ( ! empty( $raw->retweeted_status->extended_entities->media ) ) {
			foreach ( $raw->retweeted_status->extended_entities->media as $image ) {
				$post_content = str_replace( $image->url, esc_url( $image->expanded_url ), $post_content );
			}
		}

		// Update the post with the fixed content
		$post_data = get_post( $post->ID );
		$post_data->post_content = $post_content;
		wp_update_post( $post_data );
		return Keyring_Importer_Reprocessor::PROCESS_SUCCESS;
	}
}

} // end function Keyring_Twitter_Importer

// Register Importer
add_action( 'init', function() {
	Keyring_Twitter_Importer(); // Load the class code from above
	keyring_register_importer(
		'twitter',
		'Keyring_Twitter_Importer',
		plugin_basename( __FILE__ ),
		__( 'Import all of your tweets from Twitter as Posts (marked as "asides") in WordPress.', 'keyring' )
	);
} );

// Add importer-specific integration for People & Places (if installed)
add_action( 'init', function() {
	if ( class_exists( 'People_Places') ) {
		Taxonomy_Meta::add( 'people', array(
			'key'   => 'twitter',
			'label' => __( 'Twitter screen name', 'keyring' ),
			'type'  => 'text',
			'help'  => __( "This person's Twitter screen/user name (without the '@').", 'keyring' ),
			'table' => true,
		) );

		Taxonomy_Meta::add( 'places', array(
			'key'   => 'twitter',
			'label' => __( 'Twitter Location id', 'keyring' ),
			'type'  => 'text',
			'help'  => __( "Unique identifier from Twitter, for this location.", 'keyring' ),
			'table' => false,
		) );

		/**
		 * Get the full URL to the Twitter profile page for someone, based on their term_id
		 * @param  Int $term_id The id for this person's term entry
		 * @return String URL to their Twitter profile, or empty string if none.
		 */
		function ksi_get_twitter_url( $term_id ) {
			if ( $user = get_term_meta( $term_id, 'people-twitter', true ) ) {
				$user = 'https://twitter.com/' . $user;
			}
			return $user;
		}
	}
}, 100 );
