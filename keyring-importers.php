<?php
/*
Plugin Name: Keyring Social Importers
Description: Take back your content from different social media websites like Twitter, Flickr, Instagram, Delicious and Foursquare. Store everything in your own WordPress so that you can use it however you like.
Plugin URL: http://dentedreality.com.au/projects/wp-keyring-importers/
Version: 2.0
Author: Beau Lebens
Author URI: http://dentedreality.com.au
*/


/**
 * This is a base class for Keyring importers. It handles some basic interactions with Keyring,
 * and provides a framework for both one-off and automatic importing of content from a remote
 * web service.
 *
 * You'll want a standard plugin header at the top of yours -- http://codex.wordpress.org/Writing_a_Plugin
 */

/*

Extend this class to write an importer, using Keyring for authentication/requests.

 - Set all of the constants within the class to appropriate versions!
 - ::handle_request_options() should be used to validate/save/etc options
 - ::full_custom_greet() can be used for an entirely custom "Greet" page [optional]
 - keyring_importer_SLUG_greet (action) if you want to just output a custom string greeting [optional]
 - ::full_custom_options() for a completely custom options page [optional]
 - keyring_importer_SLUG_custom_options_info (action) to add a message to the core options page [optional]
 - keyring_importer_SLUG_custom_options (action) to add some additional tr>td>option rows to the options page [optional]
 - ::extract_posts_from_data() parse a chunk of data and extract post information
 - ::insert_posts() take $this->posts[] and convert them all into WP posts (handle meta, tags, etc)
 - ::build_request_url() to set up the URL, based on current DB state

*/

// Load Importer API
if ( ! function_exists( 'register_importer ' ) ) {
	require_once ABSPATH . 'wp-admin/includes/import.php';
}

// Load up the reprocessor as well
require_once dirname( __FILE__ ) . '/reprocessor.php';

abstract class Keyring_Importer_Base {
	// Make sure you set all of these in your importer class
	const SLUG              = '';    // e.g. 'twitter' (should match a service in Keyring)
	const LABEL             = '';    // e.g. 'Twitter'
	const KEYRING_SERVICE   = '';    // Full class name of the Keyring_Service this importer requires
	const REQUESTS_PER_LOAD = 3;     // How many remote requests should be made before reloading the page?
	const KEYRING_VERSION   = '1.4'; // Minimum version of Keyring required

	// You shouldn't need to edit (or override) these ones
	var $step               = 'greet';
	var $service            = false;
	var $token              = false;
	var $finished           = false;
	var $options            = array();
	var $posts              = array();
	var $errors             = array();
	var $request_method     = 'GET';
	var $auto_import        = false;

	function __construct() {
		// Can't do anything if Keyring is not available.
		// Prompt user to install Keyring (if they can), and bail
		if ( ! defined( 'KEYRING__VERSION' ) || version_compare( KEYRING__VERSION, static::KEYRING_VERSION, '<' ) ) {
			if ( current_user_can( 'install_plugins' ) ) {
				add_thickbox();
				wp_enqueue_script( 'plugin-install' );
				add_filter( 'admin_notices', array( $this, 'require_keyring' ) );
			}
			return false;
		}

		// Populate options for this importer
		$this->options = get_option( 'keyring_' . static::SLUG . '_importer' );

		// Add a Keyring handler to push us to the next step of the importer once connected
		add_action( 'keyring_connection_verified', array( $this, 'verified_connection' ), 10, 2 );

		// If a request is made for a new connection, pass it off to Keyring
		if (
			( isset( $_REQUEST['import'] ) && static::SLUG == $_REQUEST['import'] )
		&&
			(
				( isset( $_POST[ static::SLUG . '_token' ] ) && 'new' == $_POST[ static::SLUG . '_token' ] )
			||
				isset( $_POST['create_new'] )
			)
		) {
			$this->reset();
			Keyring_Util::connect_to( static::SLUG, 'keyring-' . static::SLUG . '-importer' );
			exit;
		}

		// If we have a token set already, then load some details for it
		if ( $this->get_option( 'token' ) && $token = Keyring::get_token_store()->get_token( array( 'service' => static::SLUG, 'id' => $this->get_option( 'token' ) ) ) ) {
			$this->service = call_user_func( array( static::KEYRING_SERVICE, 'init' ) );
			$this->service->set_token( $token );
		}

		// Make sure the taxonomy entry is ready to go
		$this->taxonomy = null;
		$terms = get_terms( 'keyring_services', array( 'hide_empty' => false ) );
		foreach ( (array) $terms as $term ) {
			if ( static::LABEL == $term->name ) {
				$this->taxonomy = $term;
				break;
			}
		}
		if ( is_null( $this->taxonomy ) ) {
			$term = wp_insert_term(
				static::LABEL,
				'keyring_services',
				array(
					'description' => sprintf( __( 'Posts imported from %s', 'keyring' ), static::LABEL ),
					'slug'        => static::SLUG,
				)
			);
			$this->taxonomy = get_term( $term['term_id'], 'keyring_services' );
		}

		// Make sure we have a scheduled job to handle auto-imports if enabled
		if ( $this->get_option( 'auto_import' ) && !wp_get_schedule( 'keyring_' . static::SLUG . '_import_auto' ) ) {
			wp_schedule_event( time(), 'hourly', 'keyring_' . static::SLUG . '_import_auto' );
		}

		// Form handling here, pre-output (in case we need to redirect etc)
		$this->handle_request();
	}

	/**
	 * Accept the form submission of the Options page and handle all of the values there.
	 * You'll need to validate/santize things, and probably store options in the DB. When you're
	 * done, set $this->step = 'import' to continue, or 'options' to show the options form again.
	 */
	abstract function handle_request_options();

	/**
	 * Based on whatever you need, create the URL for the next request to the remote Service.
	 * Most likely this will need to grab options from the DB.
	 * @return String containing the URL
	 */
	abstract function build_request_url();

	/**
	 * Given a block of data to import, extract the details of the contained posts and format
	 * them ready for importing. Stick them all in $this->posts[]
	 *
	 * @param string $raw Raw import data
	 */
	abstract function extract_posts_from_data( $raw );

	/**
	 * Import the compiled post data into WP as real Posts. Set up categories, tags etc
	 * per the user's definition and attach all available data we received during import.
	 * This is the guts of the import process.
	 *
	 * Increment option='imported' with the number of posts created
	 * Set $this->finished=true if there are no more posts to import
	 *
	 * @return Array containing 'imported' = (number of posts imported) and 'skipped' = (number of skipped duplicates)
	 */
	abstract function insert_posts();

	/**
	 * Create an instance of this importer. Only ever create one.
	 */
	static function &init() {
		static $instance = false;

		if ( ! $instance ) {
			$class = get_called_class();
			$instance = new $class;
		}

		return $instance;
	}

	/**
	 * Warn the user that they need Keyring installed and activated.
	 */
	function require_keyring() {
		global $keyring_required; // So that we only send the message once

		if ( 'update.php' == basename( $_SERVER['REQUEST_URI'] ) || $keyring_required ) {
			return;
		}

		$keyring_required = true;

		echo '<div class="updated">';
		echo '<p>';
		printf(
			__( 'The <strong>Keyring Social Importers</strong> plugin package requires the %1$s plugin to handle authentication. Please install it by clicking the button below, or activate it if you have already installed it, then you will be able to use the importers.', 'keyring' ),
			'<a href="http://wordpress.org/extend/plugins/keyring/" target="_blank">Keyring</a>'
		);
		echo '</p>';
		echo '<p><a href="plugin-install.php?tab=plugin-information&plugin=keyring&from=import&TB_iframe=true&width=640&height=666" class="button-primary thickbox">' . __( 'Install Keyring', 'keyring' ) . '</a></p>';
		echo '</div>';
	}

	/**
	 * Get one of the options specific to this plugin from the array in which we retain them.
	 *
	 * @param string $name The name of the option you'd like to get
	 * @param mixed $default What to return if the option requested isn't available, defaults to false
	 * @return mixed
	 */
	function get_option( $name, $default = false ) {
		if ( isset( $this->options[ $name ] ) ) {
			return $this->options[ $name ];
		}
		return $default;
	}

	/**
	 * Set an option within the array maintained for this plugin. Optionally set multiple options
	 * by passing an array of named pairs in. Passing null as the name will reset all options.
	 * If you want to store something big, then use core's update_option() or similar so that it's
	 * outside of this array.
	 *
	 * @param mixed $name String for a name/value pair, array for a collection of options, or null to reset everything
	 * @param mixed $val The value to set this option to
	 */
	function set_option( $name, $val = null ) {
		if ( is_array( $name ) ) {
			$this->options = array_merge( (array) $this->options, $name );
		} else if ( is_null( $name ) && is_null( $val ) ) { // $name = null to reset all options
			$this->options = array();
		} else if ( is_null( $val ) && isset( $this->options[ $name ] ) ) {
			unset( $this->options[ $name ] );
		} else {
			$this->options[ $name ] = $val;
		}

		return update_option( 'keyring_' . static::SLUG . '_importer', $this->options );
	}

	/**
	 * Early handling/validation etc of requests within the importer. This is hooked in early
	 * enough to allow for redirecting the user if necessary.
	 */
	function handle_request() {
		// Only interested in POST requests and specific GETs
		if ( empty( $_GET['import'] ) || static::SLUG != $_GET['import'] ) {
			return;
		}

		// Heading to a specific step of the importer
		if ( !empty( $_REQUEST['step'] ) && ctype_alpha( $_REQUEST['step'] ) ) {
			$this->step = (string) $_REQUEST['step'];
		}

		switch ( $this->step ) {
		case 'greet':
			if ( !empty( $_REQUEST[ static::SLUG . '_token' ] ) ) {
				// Coming into the greet screen with a token specified.
				// Set it internally as our access token and then initiate the Service for it
				$this->set_option( 'token', (int) $_REQUEST[ static::SLUG . '_token' ] );
				$this->service = call_user_func( array( static::KEYRING_SERVICE, 'init' ) );
				$token = Keyring::get_token_store()->get_token( array( 'service' => static::SLUG, 'id' => (int) $_REQUEST[ static::SLUG . '_token' ] ) );
				$this->service->set_token( $token );
			}

			if ( $this->service && $this->service->get_token() ) {
				// If a token has been selected (and is available), then jump to the next setp
				$this->step = 'options';
			} else {
				// Otherwise reset all default/built-ins
				$this->set_option( array(
					'category'    => null,
					'tags'        => null,
					'author'      => null,
					'auto_import' => null,
				) );
			}

			break;

		case 'options':
			// Clear token and start again if a reset was requested
			if ( isset( $_POST['reset'] ) ) {
				$this->reset();
				$this->step = 'greet';
				break;
			}

			// If we're "refreshing", then just act like it's an auto import
			if ( isset( $_POST['refresh'] ) ) {
				$this->auto_import = true;
			}

			// Write a custom request handler in the extending class here
			// to handle processing/storing options for import. Make sure to
			// end it with $this->step = 'import' (if you're ready to continue)
			$this->handle_request_options();

			break;
		}
	}

	/**
	 * Decide which UI to display to the user, kind of a second-stage of handle_request().
	 */
	function dispatch() {
		// Don't allow access to ::options() unless a service/token are set
		if ( !$this->service || !$this->service->get_token() ) {
			$this->step = 'greet';
		}

		switch ( $this->step ) {
		case 'greet':
			$this->greet();
			break;

		case 'options':
			$this->options();
			break;

		case 'import':
			$this->do_import();
			break;

		case 'done':
			$this->done();
			break;
		}
	}

	/**
	 * Raise an error to display to the user. Multiple errors per request may be triggered.
	 * Should be called before ::header() to ensure that the errors are displayed to the user.
	 *
	 * @param string $str The error message to display to the user
	 */
	function error( $str ) {
		$this->errors[] = $str;
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
			<?php echo do_action( 'keyring_importer_header_css' ); ?>
		</style>
		<div class="wrap keyring-importer">
		<h2><?php printf( __( '%s Importer', 'keyring' ), esc_html( static::LABEL ) ); ?></h2>
		<?php
		if ( count( $this->errors ) ) {
			echo '<div class="error"><ol>';
			foreach ( $this->errors as $error ) {
				echo '<li>' . esc_html( $error ) . '</li>';
			}
			echo '</ol></div>';
		}
	}

	/**
	 * Default, basic footer for importer UI
	 */
	function footer() {
		do_action( 'keyring_importer_' . static::SLUG . '_footer' );
		echo '</div>';
	}

	/**
	 * The first screen the user sees in the import process. Summarizes the process and allows
	 * them to either select an existing Keyring token or start the process of creating a new one.
	 * Also makes sure they have the correct service available, and that it's configured correctly.
	 */
	function greet() {
		if ( method_exists( $this, 'full_custom_greet' ) ) {
			$this->full_custom_greet();
			return;
		}

		$this->header();

		// If this service is not configured, then we can't continue
		if ( ! $service = Keyring::get_service_by_name( static::SLUG ) ) : ?>
			<p class="error"><?php echo esc_html( sprintf( __( "It looks like you don't have the %s service for Keyring installed.", 'keyring' ), static::LABEL ) ); ?></p>
			<?php
			$this->footer();
			return;
			?>
		<?php elseif ( ! $service->is_configured() ) : ?>
			<p class="error"><?php echo esc_html( sprintf( __( "Before you can use this importer, you need to configure the %s service within Keyring.", 'keyring' ), static::LABEL ) ); ?></p>
			<?php
			if (
				current_user_can( 'read' ) // @todo this capability should match whatever the UI requires in Keyring
			&&
				! KEYRING__HEADLESS_MODE // In headless mode, there's nowhere (known) to link to
			&&
				has_action( 'keyring_' . static::SLUG . '_manage_ui' ) // Does this service have a UI to link to?
			) {
				$manage_kr_nonce = wp_create_nonce( 'keyring-manage' );
				$manage_nonce = wp_create_nonce( 'keyring-manage-' . static::SLUG );
				echo '<p><a href="' . esc_url( Keyring_Util::admin_url( static::SLUG, array( 'action' => 'manage', 'kr_nonce' => $manage_kr_nonce, 'nonce' => $manage_nonce ) ) ) . '" class="button-primary">' . sprintf( __( 'Configure %s Service', 'keyring' ), static::LABEL ) . '</a></p>';
			}
			$this->footer();
			return;
			?>
		<?php endif; ?>
		<div class="narrow">
			<form action="admin.php?import=<?php echo static::SLUG; ?>&amp;step=greet" method="post">
				<p><?php printf( __( "Howdy! This importer requires you to connect to %s before you can continue.", 'keyring' ), static::LABEL ); ?></p>
				<?php do_action( 'keyring_importer_' . static::SLUG . '_greet' ); ?>
				<?php if ( $service->is_connected() ) : ?>
					<p><?php echo sprintf( esc_html( __( 'It looks like you\'re already connected to %1$s via %2$s. You may use an existing connection, or create a new one:', 'keyring' ) ), static::LABEL, '<a href="' . esc_attr( Keyring_Util::admin_url() ) . '">Keyring</a>' ); ?></p>
					<?php $service->token_select_box( static::SLUG . '_token', true ); ?>
					<input type="submit" name="connect_existing" value="<?php echo esc_attr( __( 'Continue&hellip;', 'keyring' ) ); ?>" id="connect_existing" class="button-primary" />
				<?php else : ?>
					<p><?php echo esc_html( sprintf( __( "To get started, we'll need to connect to your %s account so that we can access your content.", 'keyring' ), static::LABEL ) ); ?></p>
					<input type="submit" name="create_new" value="<?php echo esc_attr( sprintf( __( 'Connect to %s&#0133;', 'keyring' ), static::LABEL ) ); ?>" id="create_new" class="button-primary" />
				<?php endif; ?>
			</form>
		</div>
		<?php
		$this->footer();
	}

	/**
	 * If the user created a new Keyring connection, then this method handles intercepting
	 * when the user returns back to WP/Keyring, passing the details of the created token back to
	 * the importer.
	 *
	 * @param array $request The $_REQUEST made after completing the Keyring connection process
	 */
	function verified_connection( $service, $id ) {
		// Only handle connections that were for us
		global $keyring_request_token;
		if ( ! $keyring_request_token || 'keyring-' . static::SLUG . '-importer' != $keyring_request_token->get_meta( 'for' ) ) {
			return;
		}

		// Only handle requests that were successful, and for our specific service
		if ( static::SLUG == $service && $id ) {
			// Redirect to ::greet() of our importer, which handles keeping track of the token in use, then proceeds
			wp_safe_redirect(
				add_query_arg(
					static::SLUG . '_token',
					(int) $id,
					admin_url( 'admin.php?import=' . static::SLUG . '&step=greet' )
				)
			);
			exit;
		}
	}

	/**
	 * Once a connection is selected/created, this UI allows the user to select
	 * the details of their imported tweets.
	 */
	function options() {
		if ( method_exists( $this, 'full_custom_options' ) ) {
			$this->full_custom_options();
			return;
		}

		$this->header();

		?>
		<form name="import-<?php echo esc_attr( static::SLUG ); ?>" method="post" action="admin.php?import=<?php esc_attr_e( static::SLUG ); ?>&amp;step=options">
		<?php
		if ( $this->get_option( 'auto_import' ) ) :
			$auto_import_button_label = __( 'Save Changes', 'keyring' );
			?>
			<div class="updated inline">
				<p><?php _e( "You are currently auto-importing new content using the settings below.", 'keyring' ); ?></p>
				<p><input type="submit" name="refresh" class="button" id="options-refresh" value="<?php esc_attr_e( 'Check for new content now', 'keyring' ); ?>" /></p>
			</div><?php
		else :
			$auto_import_button_label = __( 'Start auto-importing', 'keyring' );
			?><p><?php _e( "Now that we're connected, we can go ahead and download your content, importing it all as posts.", 'keyring' ); ?></p><?php
		endif;
		?>
			<p><?php _e( "You can optionally choose to 'Import new content automatically', which will continually import any new posts you make, using the settings below.", 'keyring' ); ?></p>
			<?php do_action( 'keyring_importer_' . static::SLUG . '_options_info' ); ?>
			<table class="form-table">
				<tr valign="top">
					<th scope="row">
						<label><?php _e( 'Connected as', 'keyring' ) ?></label>
					</th>
					<td>
						<strong><?php echo $this->service->get_token()->get_display(); ?></strong>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row">
						<label for="category"><?php _e( 'Create posts with status', 'keyring' ) ?></label>
					</th>
					<td>
						<select name="status" id="status">
						<?php
							$statuses = array(
								'publish' => __( 'Publish' ),
								'pending' => __( 'Pending' ),
								'draft'   => __( 'Draft' ),
								'private' => __( 'Private' ),
							);
							foreach ( $statuses as $status => $label ) {
								printf( '<option value="%s"' . selected( $this->get_option( 'status', 'publish' ) == $status ) . '>%s</option>', $status, $label );
							}
						?>
						</select>
						<p class="description"><?php _e( 'When posts are created, they will be set to this status. Publish will publish them publicly, immediately.', 'keyring' ); ?></p>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row">
						<label for="category"><?php _e( 'Import posts into Category', 'keyring' ) ?></label>
					</th>
					<td>
						<select name="category" id="category">
						<?php
							$prev_cat = $this->get_option( 'category' );
							$categories = get_categories( array( 'hide_empty' => 0 ) );
							foreach ( $categories as $cat ) {
								printf( '<option value="%s"' . selected( $prev_cat == $cat->term_id ) . '>%s</option>', $cat->term_id, $cat->name );
							}
						?>
						</select> (<a href="edit-tags.php?taxonomy=category"><?php _e( 'Add New Category', 'keyring' ); ?></a>)
						<p class="description"><?php _e( 'Select a category to assign to all imported posts.', 'keyring' ); ?></p>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row">
						<label for="tags"><?php _e( 'Add tags to all posts', 'keyring' ) ?></label>
					</th>
					<td>
						<?php
						if ( $tags = $this->get_option( 'tags' ) )
							$tags = implode( ', ', array_map( 'trim', $tags ) );
						else
							$tags = '';
						?>
						<input type="text" class="regular-text" name="tags" id="tags" value="<?php echo esc_html( $tags ); ?>" />
						<p class="description"><?php _e( 'Comma-separated list of tags to add to all imported posts.', 'keyring' ); ?></p>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row">
						<label for="author"><?php _e( 'Author of imported posts', 'keyring' ) ?></label>
					</th>
					<td>
						<select name="author" id="author">
							<?php
								$prev_author = $this->get_option( 'author' );
								if ( ! $prev_author ) {
									$prev_author = wp_get_current_user()->ID;
								}
								// Need to get specific lists to avoid problems on sites with subscriber lists and whatnot
								$authors = array_merge(
									get_users( array( 'role' => 'author' ) ),
									get_users( array( 'role' => 'editor' ) ),
									get_users( array( 'role' => 'administrator' ) )
								);
								foreach ( $authors as $user ) {
									$author = new WP_User( $user->ID );
									printf( '<option value="%s"' . selected( $prev_author == $user->ID ) . '>%s</option>', $author->ID, $author->display_name ? $author->display_name : $author->user_nicename );
								}
							?>
						</select>
						<p class="description"><?php _e( 'All imported posts will be attributed to this author.', 'keyring' ); ?></p>
					</td>
				</tr>

				<?php
				// This is a perfect place to hook in if you'd like to output some additional options
				do_action( 'keyring_importer_' . static::SLUG . '_custom_options' );
				?>

				<tr valign="top">
					<th scope="row">
						<label for="auto_import"><?php _e( 'Auto-import new content', 'keyring' ) ?></label>
					</th>
					<td>
						<input type="checkbox" value="1" name="auto_import" id="auto_import"<?php echo checked( 'true' == $this->get_option( 'auto_import', 'true' ) ); ?> />
					</td>
				</tr>
			</table>

			<p class="submit">
				<input type="submit" name="submit" class="button-primary" id="options-submit" value="<?php _e( 'Import', 'keyring' ); ?>" />
				<input type="submit" name="reset" value="<?php _e( 'Reset Importer', 'keyring' ); ?>" id="reset" class="button" />
			</p>
		</form>

		<script type="text/javascript" charset="utf-8">
			jQuery( document ).ready( function() {
				jQuery( '#auto_import' ).on( 'change', function() {
					if ( jQuery( this ).attr( 'checked' ) ) {
						jQuery( '#options-submit' ).val( '<?php echo esc_js( $auto_import_button_label ); ?>' );
					} else {
						jQuery( '#options-submit' ).val( '<?php echo esc_js( __( 'Import all posts (once-off)', 'keyring' ) ); ?>' );
					}
				} ).change();
			} );
		</script>
		<?php
		$this->footer();
	}

	/**
	 * To allow users to customize what their content looks like within WP, we use a template
	 * system for the imported posts. If you want to customize them, then add a folder called
	 * 'importer-templates' to your theme, and put files in there named 'template-{$name}.php'
	 * where $name is the slug for one of the importers (twitter, instagram etc). If a template
	 * isn't found, then the ones included with the importers will be used.
	 *
	 * An output buffer will be used to capture the output created by include()ing this template
	 * once a bunch of variables have been set up.
	 */
	function locate_template() {
		$name = 'template-' . static::SLUG . '.php';
		$template = locate_template( array( "importer-templates/$name" ) );
		if ( ! $template ) {
			$template = dirname( __FILE__ ) . "/templates/$name";
		}
		return $template;
	}

	/**
	 * Hooked into ::dispatch(), this just handles triggering the import and then dealing with
	 * any value returned from it.
	 */
	function do_import() {
		set_time_limit( 0 );
		$res = $this->import();
		if ( true !== $res ) {
			echo '<div class="error"><p>';
			if ( Keyring_Util::is_error( $res ) ) {
				$http = $res->get_error_message(); // The entire HTTP object is passed back if it's an error
				if ( 400 == wp_remote_retrieve_response_code( $http ) ) {
					printf( __( "Received an error from %s. Please wait for a while then try again.", 'keyring' ), static::LABEL );
				} else if ( in_array( wp_remote_retrieve_response_code( $http ), array( 502, 503 ) ) ) {
					printf( __( "%s is currently experiencing problems. Please wait for a while then try again.", 'keyring' ), static::LABEL );
				} else {
					// Raw dump, sorry
					echo '<p>' . sprintf( __( "We got an unknown error back from %s. This is what they said.", 'keyring' ), static::LABEL ) . '</p>';
					$body = wp_remote_retrieve_body( $http );
					echo '<pre>';
					print_r( $body );
					echo '</pre>';
				}
			} else {
				_e( 'Something went wrong. Please try importing again in a few minutes (your details have been saved and the import will continue from where it left off).', 'keyring' );
			}
			echo '</p></div>';
			$this->footer(); // header was already done in import()
		}
	}

	/**
	 * Grab a chunk of data from the remote service and process it into posts, and handle actually importing as well.
	 * Keeps track of 'state' in the DB.
	 */
	function import() {
		defined( 'WP_IMPORTING' ) or define( 'WP_IMPORTING', true );
		do_action( 'import_start' );
		$num = 0;
		$this->header();
		$stop_after_import_requests = apply_filters( 'keyring_importer_stop_after_import_requests', false );

		echo '<p>' . __( 'Importing Posts...', 'keyring' ) . '</p>';
		echo '<ol>';
		while ( ! $this->finished && $num < static::REQUESTS_PER_LOAD ) {
			$data = $this->make_request();
			if ( Keyring_Util::is_error( $data ) ) {
				return $data;
			}

			$result = $this->extract_posts_from_data( $data );
			if ( Keyring_Util::is_error( $result ) ) {
				return $result;
			}

			// Use this filter to modify any/all posts before they are actually inserted as posts
			$this->posts = apply_filters( 'keyring_importer_posts_pre_insert', $this->posts, $this->service->get_name() );

			$result = $this->insert_posts();
			if ( Keyring_Util::is_error( $result ) ) {
				return $result;
			} else {
				echo '<li>' . sprintf( __( 'Imported %d posts in this batch', 'keyring' ), $result['imported'] ) . ( $result['skipped'] ? sprintf( __( ' (skipped %d that looked like duplicates).', 'keyring' ), $result['skipped'] ) : '.' ) . '</li>';
				flush();
				$this->set_option( 'imported', ( $this->get_option( 'imported' ) + $result['imported'] ) );
			}

			if ( $stop_after_import_requests && ( $this->get_option( 'imported' ) >= $stop_after_import_requests ) ) {
				$this->finished = true;
				break; // Break to avoid incrementing `page`
			}

			// Keep track of which "page" we're up to
			$this->set_option( 'page', $this->get_option( 'page' ) + 1 );

			// Local (per-page-load) counter
			$num++;
		}
		echo '</ol>';
		$this->footer();

		if ( $this->finished ) {
			$this->importer_goto( 'done', 1 );
		} else {
			$this->importer_goto( 'import' );
		}

		do_action( 'import_end' );

		return true;
	}

	/**
	 * Super-basic implementation of making the (probably) authorized request. You can (should)
	 * override this with something more substantial and suitable for the service you're working with.
	 * @return Keyring request response -- either a Keyring_Error or a Service-specific data structure (probably object or array)
	 */
	function make_request() {
		$url = $this->build_request_url();
		return $this->service->request( $url, array( 'method' => $this->request_method, 'timeout' => 10 ) );
	}

	/**
	 * To keep the process moving while avoiding memory issues, it's easier to just
	 * end a response (handling a set chunk size) and then start another one. Since
	 * we don't want to have the user sit there hitting "next", we have this helper
	 * which includes some JS to keep things bouncing on to the next step (while
	 * there is a next step).
	 *
	 * @param string $step Which step should we direct the user to next?
	 * @param int $seconds How many seconds should we wait before auto-redirecting them? Set to null for no auto-redirect.
	 */
	function importer_goto( $step, $seconds = 3 ) {
		echo '<form action="admin.php?import=' . esc_attr( static::SLUG ) . '&amp;step=' . esc_attr( $step ) . '" method="post" id="' . esc_attr( static::SLUG ) . '-import">';
		echo wp_nonce_field( static::SLUG . '-import', '_wpnonce', true, false );
		echo wp_referer_field( false );
		echo '<p><input type="submit" class="button-primary" value="' . __( 'Continue with next batch', 'keyring' ) . '" /> <span id="auto-message"></span></p>';
		echo '</form>';

		if ( null !== $seconds ) :
		?><script type="text/javascript">
			next_counter = <?php echo (int) $seconds ?>;
			jQuery(document).ready(function(){
				<?php echo esc_js( static::SLUG ); ?>_msg();
			});

			function <?php echo esc_js( static::SLUG ); ?>_msg() {
				str = '<?php _e( "Continuing in #num#", 'keyring' ) ?>';
				jQuery( '#auto-message' ).text( str.replace( /#num#/, next_counter ) );
				if ( next_counter <= 0 ) {
					if ( jQuery( '#<?php echo esc_js( static::SLUG ); ?>-import' ).length ) {
						jQuery( "#<?php echo esc_js( static::SLUG ); ?>-import input[type='submit']" ).hide();
						var str = '<?php _e( 'Continuing', 'keyring' ); ?> <img src="<?php echo esc_url( admin_url( '/images/loading.gif' ) ); ?>" alt="" id="processing" align="top" width="16" height="16" />';
						jQuery( '#auto-message' ).html( str );
						jQuery( '#<?php echo esc_js( static::SLUG ); ?>-import' ).submit();
						return;
					}
				}
				next_counter = next_counter - 1;
				setTimeout( '<?php echo esc_js( static::SLUG ); ?>_msg()', 1000 );
			}
		</script><?php endif;
	}

	/**
	 * When they're complete, give them a quick summary and a link back to their website.
	 */
	function done() {
		$this->header();
		echo '<p>' . sprintf( __( 'Imported a total of %s posts.', 'keyring' ), number_format( $this->get_option( 'imported' ) ) ) . '</p>';
		echo '<h3>' . sprintf( __( 'All done. <a href="%1$s">View your site</a>, or <a href="%2$s">check out all your new posts</a>.', 'keyring' ), home_url(), add_query_arg( 'keyring_services', $this->taxonomy->slug, admin_url( 'edit.php' ) ) ) . '</h3>';
		echo '<p><a href="';
		echo esc_url( add_query_arg( array(
			'import' => static::SLUG,
		), self_admin_url( 'admin.php' ) ) );
		echo '">' . sprintf( __( '← Back to %s Importer', 'keyring' ), esc_html( static::LABEL ) ) . '</a></p>';
		$this->footer();
		do_action( 'import_done', 'keyring_' . static::SLUG );
		do_action( 'keyring_import_done', 'keyring_' . static::SLUG );
		$this->set_option( 'imported', 0 );
	}

	/**
	 * Handle a cron request to import "the latest" content for this importer. Should
	 * rely solely on database state of some sort, since nothing is passed in. Make
	 * sure to also update anything in the DB required for the next run. If you set up your
	 * other methods "discretely" enough, you might not need to override this.
	 */
	function do_auto_import() {
		defined( 'WP_IMPORTING' ) or define( 'WP_IMPORTING', true );
		do_action( 'import_start' );
		set_time_limit( 0 );
		// In case auto-import has been disabled, clear all jobs and bail
		if ( ! $this->get_option( 'auto_import' ) ) {
			wp_clear_scheduled_hook( 'keyring_' . static::SLUG . '_import_auto' );
			return;
		}

		// Need a token to do anything with this
		if ( ! $this->service->get_token() ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/import.php';
		require_once ABSPATH . 'wp-admin/includes/post.php';

		$this->auto_import = true;

		$num = 0;
		while ( ! $this->finished && $num < static::REQUESTS_PER_LOAD ) {
			$data = $this->make_request();
			if ( Keyring_Util::is_error( $data ) ) {
				return;
			}

			$result = $this->extract_posts_from_data( $data );
			if ( Keyring_Util::is_error( $result ) ) {
				return;
			}

			$result = $this->insert_posts();
			if ( Keyring_Util::is_error( $result ) ) {
				return;
			}

			// Keep track of which "page" we're up to, in case an auto importer cares
			$this->set_option( 'page', $this->get_option( 'page' ) + 1 );

			$num++;
		}

		do_action( 'import_end' );
	}

	/**
	 * Reset all options for this importer
	 */
	function reset() {
		$this->set_option( null );
	}

	/**
	 * This is a helper for downloading/attaching/inserting media into a post when it's
	 * being imported. See Flickr/Instagram for examples.
	 * @param Array of $urls
	 * @param Int post ID
	 * @param Array post object
	 * @param String size of images (large, medium, small)
	 * @param String what to do with the images. Always updated inline. Optionally append/prepend if not found in content
	 *
	 */
	function sideload_media( $urls, $post_id, $post, $size = 'large', $where = 'prepend' ) {
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'wp_read_image_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		if ( ! is_array( $urls ) ) {
			$urls = array( $urls );
		}

		// Get the base uploads directory so that we can skip things in there
		$dir = wp_get_upload_dir();
		$dir = $dir['baseurl'];

		$orig_content = $post['post_content'];
		foreach( $urls as $num => $url ) {
			// Skip completely if this URL appears to already be local
			if ( false !== stristr( $url, $dir ) ) {
				continue;
			}

			// Attempt to download/attach the media to this post
			$id = media_sideload_image( $url, $post_id, $post['post_title'], 'id' );
			if ( ! is_wp_error( $id ) ) {
				if ( 0 === $num ) {
					// Set the first successfully processed image as Featured
					set_post_thumbnail( $post_id, $id );
				}

				// Update the post to reference the new local image
				$data = wp_get_attachment_image_src( $id, $size );
				if ( $data ) {
					$img = '<img src="' . esc_url( $data[0] ) . '" width="' . esc_attr( $data[1] ) . '" height="' . esc_attr( $data[2] ) . '" alt="' . esc_attr( $post['post_title'] ) . '" class="keyring-img" />';
				}

				// Regex out the previous img tag, put this one in there instead, or prepend it to the top/bottom, depending on $append
				if ( stristr( $post['post_content'], $url ) ) { // always do this if the image is in there already
					$post['post_content'] = preg_replace( '!<img\s[^>]*src=[\'"]' . preg_quote( $url ) . '[\'"][^>]*>!', $img, $post['post_content'] ) . "\n";
				} else if ( 'append' == $where ) {
					$post['post_content'] = $post['post_content'] . "\n\n" .  $img;
				} else if ( 'prepend' == $where ) {
					$post['post_content'] = $img . "\n\n" . $post['post_content'];
				}
			}
		}

		// Update and we're out
		if ( $post['post_content'] !== $orig_content ) {
			$post['ID'] = $post_id;
			return wp_update_post( $post );
		}

		return true;
	}

	/**
	 * Similar to sideload_media, but a little simpler. This will download a video
	 * from a URL, and then embed it into a post by replacing the same URL
	 */
	function sideload_video( $url, $post_id ) {
		$file = array();
		$file['tmp_name'] = download_url( $url );
		if ( is_wp_error( $file['tmp_name'] ) ) {
			// Download failed, leave the post alone
			@unlink( $file_array['tmp_name'] );
		} else {
			// Download worked, now import into Media Library
			$file['name'] = basename( $url );
			$id = media_handle_sideload( $file, $post_id );
			@unlink( $file_array['tmp_name'] );
			if ( ! is_wp_error( $id ) ) {
				// Update URL in post to point to the local copy
				$post_data = get_post( $post_id );
				$post_data->post_content = str_replace( $url, wp_get_attachment_url( $id ), $post_data->post_content );
				wp_update_post( $post_data );
			}
		}
	}
}

/**
 * Creates a taxonomy where we will reference which service (if any) each post is imported from.
 * We intentionally create it so that it's not public (no UI) and it uses a custom capability that
 * is not assigned to any role/user, so end-users cannot modify the taxonomy. Individual importers
 * create an assign their own terms as required.
 */
add_action( 'init', function() {
	// Only create the taxonomy if it's not there already
	if ( ! taxonomy_exists( 'keyring_services' ) ) {
		register_taxonomy(
			'keyring_services',
			'post',
			array(
				'label'             => __( 'Imported From', 'keyring' ),
				'public'            => true, // Allows you to use them in Custom Menus
				'hierarchical'      => false,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => array(
					'slug' => 'service',
				),
				'capabilities'      => array( // we intentionally provide these because then noone will have the ability to mess with them
											'manage_terms' => 'edit_posts',
											'edit_terms'   => 'edit_posts',
											'delete_terms' => 'edit_posts',
											'assign_terms' => 'edit_posts',
										),
			)
		);
	}
}, 5 );

/**
 * Since we're introducing a new taxonomy, and we're likely to end up with a lot
 * of posts using it, let's make it a little easier to filter the admin to view
 * posts just for each service.
 */
add_action( 'restrict_manage_posts', function() {
	$terms = get_terms( 'keyring_services' );
	if ( count( $terms ) ) :
	?><select name="keyring_services" id="keyring_services">
	<option value=""><?php esc_html_e( 'All Services', 'keyring' ); ?></option>
	<?php foreach ( $terms as $term ): ?>
	<option<?php selected( isset( $_REQUEST['keyring_services'] ) && $_REQUEST['keyring_services'] == $term->slug ); ?> value="<?php echo esc_attr( $term->slug ); ?>"><?php echo esc_html( $term->name ); ?></option>
	<?php endforeach; ?>
	</select><?php
	endif;
} );

/**
 * Load the importer and register it with WordPress.
 * You should call this on init so that Keyring has already loaded.
 *
 * @param string $slug The slug name of your importer
 * @param string $class The full classname your importer uses
 * @param string $plugin Just put plugin_basename( __FILE__ ) for this
 * @param string $info (Optional) string describing the importer, used on Tools > Import UI.
 */
function keyring_register_importer( $slug, $class, $plugin, $info = false ) {
	global $_keyring_importers;
	$slug = preg_replace( '/[^a-z_]/', '', $slug );
	$_keyring_importers[ $slug ] = call_user_func( array( $class, 'init' ) );
	if ( ! $info ) {
		$info = __( 'Import content from %s and save it as Posts within WordPress.', 'keyring' );
	}

	$name = $class::LABEL;

	// Check if this importer is already configured to auto-import
	$options = get_option( 'keyring_' . $slug . '_importer' );
	if ( ! empty( $options['auto_import'] ) && ! empty( $options['token'] ) ) {
		$name = '&#10003; ' . $name; // Add a checkmark to indicate it's on auto
	}

	register_importer(
		$slug,
		$name,
		sprintf(
			$info,
			$class::LABEL
		),
		array( $_keyring_importers[$slug], 'dispatch' )
	);

	// Handle auto-import requests
	add_action( 'keyring_' . $class::SLUG . '_import_auto', array( $_keyring_importers[$slug], 'do_auto_import' ) );
}

// Auto-load all importers available (unless filtered)
$keyring_importers = glob( dirname( __FILE__ ) . "/importers/*.php" );
$keyring_importers = apply_filters( 'keyring_importers', $keyring_importers );
foreach ( $keyring_importers as $keyring_importer ) {
	require_once $keyring_importer;
}
unset( $keyring_importers, $keyring_importer );
