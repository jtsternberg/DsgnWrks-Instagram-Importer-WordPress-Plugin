<?php
/*
Plugin Name: DsgnWrks Instagram Importer
Plugin URI: http://dsgnwrks.pro/plugins/dsgnwrks-instagram-importer
Description: Allows you to backup your instagram photos while allowing you to have a site to display your instagram archive. Allows you to import to custom post types and attach custom taxonomies.
Author URI: http://dsgnwrks.pro
Author: DsgnWrks
Donate link: http://dsgnwrks.pro/give/
Version: 1.2.8
*/

class DsgnWrksInstagram {

	public $plugin_name      = 'DsgnWrks Instagram Importer';
	public $plugin_version   = '1.2.7';
	public $plugin_id        = 'dsgnwrks-instagram-importer-settings';
	protected $pre           = 'dsgnwrks_instagram_';
	protected $instagram_api = 'https://api.instagram.com/v1/users/';
	protected $import        = array();
	protected $plugin_page   = false;
	protected $defaults;

	/**
	 * Sets up our plugin
	 * @since  1.1.0
	 */
	function __construct() {

		// i18n
		load_plugin_textdomain( 'dsgnwrks', false, dirname( plugin_basename( __FILE__ ) ) );

		// user option defaults
		$this->defaults = array(
			'tag-filter'   => false,
			'feat_image'   => 'yes',
			'auto_import'  => 'yes',
			'date-filter'  => 0,
			'mm'           => date( 'm', strtotime( '-1 month' ) ),
			'dd'           => date( 'd', strtotime( '-1 month' ) ),
			'yy'           => date( 'Y', strtotime( '-1 month' ) ),
			'post-title'   => '**insta-text**',
			'post_content' => '<p><a href="**insta-link**" target="_blank">**insta-image**</a></p>'."\n".'<p>'. __( 'Instagram filter used:', 'dsgnwrks' ) .' **insta-filter**</p>'."\n".'[if-insta-location]<p>'. __( 'Photo taken at:', 'dsgnwrks' ) .' **insta-location**</p>[/if-insta-location]'."\n".'<p><a href="**insta-link**" target="_blank">'. __( 'View in Instagram', 'dsgnwrks' ) .' &rArr;</a></p>',
			'post-type'    => 'post',
			'draft'        => 'draft',
		);

		add_action( 'admin_init', array( $this, 'init' ) );
		add_action( 'init', array( $this, 'hook_shortcode' ) );
		add_action( $this->pre.'cron', array( $this, 'cron_callback' ) );
		add_action( 'admin_menu', array( $this, 'settings' ) );
		add_action( 'wp_ajax_dsgnwrks_instagram_import', array( $this, 'ajax_import' ) );
		register_uninstall_hook( __FILE__, array( 'DsgnWrksInstagram', 'uninstall' ) );
		// Load the plugin settings link shortcut.
		add_filter( 'plugin_action_links_' . plugin_basename( plugin_dir_path( __FILE__ ) . 'dsgnwrks-instagram-importer.php' ), array( $this, 'settings_link' ) );
		// @TODO
		// add_action( 'before_delete_post', array( $this, 'save_id_on_delete' ), 10, 1 );
		add_action( 'current_screen', array( $this, 'redirects' ) );
		add_filter( 'wp_default_editor', array( $this, 'html_default' ) );
		// @DEV adds a minutely schedule for testing cron
		// add_filter( 'cron_schedules', array( $this, 'minutely' ) );
		add_action( 'all_admin_notices', array( $this, 'show_cron_notice' ) );
	}

	/**
	 * Internationalize the plugin name
	 * @since  1.2.1
	 */
	function pluginName() {
		// i18n
		$this->plugin_name = 'DsgnWrks '. __( 'Instagram Importer', 'dsgnwrks' );
		return $this->plugin_name;
	}

	/**
	 * Hooks to 'all_admin_notices' and displays auto-imported photo messages
	 * @since  1.2.0
	 */
	function show_cron_notice() {

		// check if we have any saved notices from our cron auto-import
		$notices = get_option( 'dsgnwrks_imported_photo_notices' );
		if ( !$notices )
			return;

		// if so, loop through and display them
		echo '<div id="message" class="updated instagram-import-message">';
		foreach ( $notices as $userid => $notice ) {
			echo '<h3>'. $userid .' &mdash; '. __( 'imported:', 'dsgnwrks' ) .' '. $notice['time'] .'</h3><ol>';
			echo $notice['notice'];
			echo '</ol><div style="clear: both; padding-top: 10px;"></div>';
			echo '<hr/>';
		}
		echo '<br><a href="'. add_query_arg( array() ) .'">'. __( 'Hide', 'dsgnwrks' ) .'</a></div>';
		?>
		<style type="text/css">
		.updated.instagram-import-message {
			overflow: hidden;
			background: #F1F1F1;
			border-color: #ccc;
			padding: 0 0 10px 10px;
			margin: 0;
		}
		.updated.instagram-import-message ol {
			padding: 0;
			margin: 0;
			list-style-position: inside;
		}
		.updated.instagram-import-message li, .updated.instagram-import-message p {
			margin: 10px 10px 0 0;
			padding: 8px;
			background: #fff;
			width: 350px;
			float: left;
		}
		.updated.instagram-import-message li {
			min-height: 60px;
		}
		.updated.instagram-import-message strong {
			display: block;
		}
		.updated.instagram-import-message img {
			width: 50px;
			height: 50px;
			margin: 0 8px 0 0;
			vertical-align: middle;
			float: left;
		}
		.updated.instagram-import-message hr {
			display: block;
			width: 100%;
			clear: both;
		}
		</style>
		<?php
		// reset notices
		update_option( 'dsgnwrks_imported_photo_notices', '' );
	}

	/**
	 * Add import function to cron
	 * @since  1.2.0
	 */
	public function cron_callback() {
		$opts = get_option( 'dsgnwrks_insta_options' );

		if ( !empty( $opts ) && is_array( $opts ) ) : foreach ( $opts as $user => $useropts ) {
			if ( isset( $useropts['auto_import'] ) && $useropts['auto_import'] == 'yes' )
				$this->import( $user );
		} endif;
	}

	/**
	 * Import via ajax
	 * @since  1.2.5
	 */
	public function ajax_import() {

		// instagram user id for pinging instagram
		if ( !isset( $_REQUEST['instagram_user'] ) )
			wp_send_json_error( '<div id="message" class="error"><p>'. __( 'No Instagram username found.', 'dsgnwrks' ) .'</p></div>' );

		// check user capability
		if ( !current_user_can( 'publish_posts' ) )
			wp_send_json_error( '<div id="message" class="error"><p>'. __( 'Sorry, you do not have the right priveleges.', 'dsgnwrks' ) .'</p></div>' );

		if ( isset( $_REQUEST['next_url'] ) && $trans = get_option( 'dsgnwrks_next_url' ) ) {
			$this->next_url = $trans;
			trigger_error('transient used');
		} elseif ( isset( $_REQUEST['next_url'] ) && $_REQUEST['next_url'] ) {
			$this->next_url = $_REQUEST['next_url'];
			trigger_error('$_REQUEST[\'next_url\'] used');
		}

		// Do not publicize these posts (Jetpack)
		add_filter( 'wpas_submit_post?', '__return_false' );
		$notices = $this->import( $_REQUEST['instagram_user'] );
		remove_filter( 'wpas_submit_post?', '__return_false' );

		if ( !$notices )
			wp_send_json_error( '<div id="message" class="updated"><p>'. __( 'No new Instagram shots to import', 'dsgnwrks' ) .'</p></div>' );

		$next_url = false;
		// if so, loop through and display them
		$messages = '';
		foreach ( $notices as $userid => $notice ) {
			if ( $userid == 'next_url' ) {
				$next_url = $notice;
				continue;
			}
			$messages .= $notice['notice'];
		}
		// send back the messages
		wp_send_json_success( array( 'messages' => $messages, 'next_url' => $next_url, 'userid' => $_REQUEST['instagram_user'] ) );
	}

	/**
	 * @DEV Adds once minutely to the existing schedules for easier cron testing.
	 * @since  1.2.0
	 */
	function minutely( $schedules ) {
		$schedules['minutely'] = array(
			'interval' => 60,
			'display'  => 'Once Every Minute'
		);
		return $schedules;
	}


	/**
	 * Get's the url for the plugin admin page
	 * @since  1.2.6
	 * @return string plugin admin page url
	 */
	public function plugin_page() {
		// Set our plugin page parameter
		$this->plugin_page = $this->plugin_page ? $this->plugin_page : add_query_arg( 'page', $this->plugin_id, admin_url( '/tools.php' ) );
		return $this->plugin_page;
	}

	/**
	 * Get the party started
	 * @since  1.1.0
	 */
	public function init() {

		// A pseudo setting. redirects to instagram oauth
		register_setting(
			$this->pre .'importer_users',
			'dsgnwrks_insta_registration',
			array( $this, 'users_validate' )
		);
		// validate user options settings
		register_setting(
			$this->pre .'importer_settings',
			'dsgnwrks_insta_options',
			array( $this, 'settings_validate' )
		);

		$opts = get_option( 'dsgnwrks_insta_options' );
		if ( empty( $opts['frequency'] ) || $opts['frequency'] == 'never' )
			return;

		// if a auto-import frequency interval was saved,
		if ( !wp_next_scheduled( $this->pre.'cron' ) ) {
			// schedule a cron to pull updates from instagram
			wp_schedule_event( time(), $opts['frequency'], $this->pre.'cron' );
		}
	}

	/**
	 * Hook our shotcode in
	 * @since  1.2.6
	 */
	public function hook_shortcode() {
		add_shortcode( 'dsgnwrks_instagram_embed', array( $this, 'embed_shortcode' ) );
	}

	/**
	 * A pseudo setting validation. Sends user on to instagram to be authenticated.
	 * @since  1.1.0
	 */
	public function users_validate( $opts ) {
		$return = add_query_arg( array( 'page' => $this->plugin_id ), admin_url('/tools.php') );
		$uri    = add_query_arg( 'return_uri', urlencode( $return ), 'http://dsgnwrks.pro/insta_oauth/' );
		// Send them on with our redirect uri set.
		wp_redirect( $uri, 307 );
		exit;
	}

	/**
	 * Validate each of our user options with an appropriate filter
	 * @since  1.1.0
	 * @param  array  $opts   array of options to be saved
	 * @return array          sanitized options array
	 */
	public function settings_validate( $opts ) {

		// get existing saved options to check against
		$old_opts = get_option( 'dsgnwrks_insta_options' );

		// loop through options (users)
		if ( !empty( $opts ) && is_array( $opts ) ) :
			foreach ( $opts as $user => $useropts ) {

				// loop through options (user's options)
				if ( !empty( $useropts ) && is_array( $useropts ) ) : foreach ( $useropts as $key => $opt ) {

					switch ( $key ) {
						case 'date-filter':
							if ( empty( $opts[$user]['mm'] ) && empty( $opts[$user]['dd'] ) && empty( $opts[$user]['yy'] ) || !empty( $opts[$user]['remove-date-filter'] ) ) {
								$opts[$user][$key] = 0;
							}
							else {
								$opts[$user][$key] = strtotime( $opts[$user]['mm'] .'/'. $opts[$user]['dd'] .'/'. $opts[$user]['yy'] );
							}
							break;

						case 'pw':
							continue;
							break;

						case 'post-type':
							$opts[$user][$key] = $this->filter( $opt, '', 'post' );
							break;

						case 'draft':
							$opts[$user][$key] = $this->filter( $opt, '', 'draft' );
							break;

						case 'yy':
						case 'mm':
						case 'dd':
							if ( empty( $opts[$user]['mm'] ) && empty( $opts[$user]['dd'] ) && empty( $opts[$user]['yy'] ) || !empty( $opts[$user]['remove-date-filter'] ) ) {
								$opts[$user][$key] = '';
							}
							else {
								$opts[$user][$key] = $this->filter( $opt, 'absint', '' );
							}
							break;

						case 'post_content':
							$opts[$user][$key] = $this->filter( $opt, 'wp_kses_post' );
							break;

						case 'feat_image':
						case 'auto_import':
							// checkboxes
							$opts[$user][$key] = $opts[$user][$key] == 'yes' ? 'yes' : false;
							break;

						default:
							// defaults to esc_attr() validation
							$opts[$user][$key] = $this->filter( $opt );
							break;
					}


				} endif;

				// if our 'frequency' interval was set
				if ( $user === 'frequency' ) {
					$opts[$user] = $this->filter( $useropts );
					// and if our newly saved 'frequency' is different
					// clear the previously scheduled hook
					if ( $opts[$user] != $old_opts['frequency'] )
						wp_clear_scheduled_hook( $this->pre.'cron' );
				}
				// if our 'remove_hashtags' was set
				if ( $user === 'remove_hashtags' ) {
					// checkboxes
					foreach ( $useropts as $filter => $value ) {
						$opts[$user][$filter] = $opts[$user][$filter] == 'yes' ? 'yes' : false;
					}
				}
			}
		// allow plugins to add options to save
		$opts = apply_filters( 'dsgnwrks_instagram_option_save', $opts, $old_opts );
		endif;

		// ok, we're done validating the options, so give them back
		return $opts;
	}

	/**
	 * hooks to 'admin_menu', adds our submenu page and corresponding scripts/styles
	 * @since  1.1.0
	 */
	public function settings() {
		// create admin page
		$plugin_page = add_submenu_page( 'tools.php', $this->pluginName(), __( 'Instagram Importer', 'dsgnwrks' ), 'manage_options', $this->plugin_id, array( $this, 'settings_page' ) );
		// enqueue styles
		add_action( 'admin_print_styles-' . $plugin_page, array( $this, 'styles' ) );
		// enqueue scripts
		add_action( 'admin_print_scripts-' . $plugin_page, array( $this, 'scripts' ) );
		// run our importer only on our admin page when clicking "import"
		add_action( 'admin_head-'. $plugin_page, array( $this, 'fire_importer' ) );
	}

	/**
	 * Runs when plugin is uninstalled. deletes users and options
	 * @since 1.2.5
	 */
	static function uninstall() {
		if ( ! current_user_can( 'activate_plugins' ) )
			return;
		check_admin_referer( 'bulk-plugins' );

		// Important: Check if the file is the one
		// that was registered during the uninstall hook.
		if ( __FILE__ != WP_UNINSTALL_PLUGIN )
			return;
		self::delete_options();
	}

	/**
	 * Add Settings page to plugin action links in the Plugins table.
	 *
	 * @since 1.2.6
	 * @param  array $links Default plugin action links.
	 * @return array $links Amended plugin action links.
	 */
	public function settings_link( $links ) {

		$setting_link = sprintf( '<a href="%s">%s</a>', $this->plugin_page(), __( 'Settings', 'dsgnwrks' ) );
		array_unshift( $links, $setting_link );

		return $links;

	}

	/**
	 * Deletes all plugin options
	 * @since 1.2.5
	 */
	static function delete_options() {
		// delete options
		delete_option( 'dsgnwrks_insta_options' );
		delete_option( 'dsgnwrks_insta_users' );
		delete_option( 'dsgnwrks-import-debug-sent' );
	}

	/**
	 * Creates our admin page
	 * @since  1.1.0
	 */
	public function settings_page() { require_once('settings.php'); }

	/**
	 * Enqueue our admin page's CSS
	 * @since  1.1.0
	 */
	public function styles() {
		wp_enqueue_style( 'dsgnwrks-instagram-importer-admin', plugins_url( 'css/admin.css', __FILE__ ), false, $this->plugin_version );
	}

	/**
	 * Enqueue our admin page's JS
	 * @since  1.1.0
	 */
	public function scripts() {
		wp_enqueue_script( 'dsgnwrks-instagram-importer-admin', plugins_url( 'js/admin.js', __FILE__ ), array( 'jquery' ), $this->plugin_version );

		$data = array(
			'delete_text' => __( 'Are you sure you want to delete user', 'dsgnwrks' ),
			'logout_text' => __( 'Logging out of Instagram', 'dsgnwrks' )
		);
		// get registered post-types
		$cpts = get_post_types( array( 'public' => true ) );
		foreach ($cpts as $key => $cpt) {
			// get registered taxonomies
			$taxes = get_object_taxonomies( $cpt );
			if ( !empty( $taxes ) )
				$data['cpts'][$cpt][] = $taxes;
		}
		// and save that data for use in our script
		if ( !empty( $data ) )
			wp_localize_script( 'dsgnwrks-instagram-importer-admin', 'dwinstagram', $data );
	}

	/**
	 * hooks into our admin page's head and fires the importer if requested
	 * @since  1.1.0
	 */
	public function fire_importer() {
		if ( isset( $_GET['instaimport'] ) )
			add_action( 'all_admin_notices', array( $this, 'import' ) );
	}

	/**
	 * Start the engine. begins our import and generates feedback messages
	 * @since  1.1.0
	 * @param  string $userid Instagram user id
	 */
	public function import( $userid = false ) {

		// Only import if the correct flags have been set
		if ( !$userid && !isset( $_GET['instaimport'] ) )
			return;
		// get our options for use in the import
		$opts             = get_option( 'dsgnwrks_insta_options' );
		$this->opts       = &$opts;
		// instagram user id for pinging instagram
		$this->userid     = $id = $userid ? $userid : sanitize_title( urldecode( $_GET['instaimport'] ) );
		$this->doing_ajax = isset( $_REQUEST['instagram_user'] );
		// if a $userid was passed in, & no ajax $_REQUEST data we know we're doing a cron scheduled event
		$this->doing_cron = $userid && !$this->doing_ajax ? true : false;

		// We need an id and access token to keep going
		if ( !( isset( $opts[$id]['id'] ) && isset( $opts[$id]['access_token'] ) ) )
			return;

		// Get our import report
		$messages = $this->do_import( $this->doing_cron );

		// message class
		$message_class = 'updated';

		// init our variable
		$notice = '';
		$notices = false;
		if ( is_array( $messages['message'] ) ) {
			foreach ( $messages['message'] as $key => $message ) {
				// build our $notice variable
				$notice .= $message;
			}
		} elseif ( is_string( $messages['message'] ) ) {
			// something went wrong
			$message_class = 'error';
			$notice .= $messages['message'];
		}

		// get our current time
		$time = date_i18n( 'l F jS, Y @ h:i:s A', strtotime( current_time('mysql') ) );

		// if we're not doing cron or ajax, show our notice now
		if ( !$userid ) {
			if ( stripos( $notice, __( 'No new Instagram shots to import', 'dsgnwrks' ) ) === false )
				$message_class .= ' instagram-import-message';

			echo '<div id="message" class="'. $message_class .'"><ol>'. $notice .'</ol></div>';
		}
		// otherwise...
		elseif ( stripos( $notice, __( 'No new Instagram shots to import', 'dsgnwrks' ) ) === false ) {

			// if we're doing ajax, we'll send the messages back now
			if ( $this->doing_ajax ) {

				$notices = array( $userid => array( 'notice' => $notice, 'time' => $time ) );

				if ( !empty( $messages['next_url'] ) ) {
					$notices['next_url'] = $messages['next_url'];
					set_transient( 'get_option', $messages['next_url'] );
				}

				if ( $this->doing_ajax )
					return $notices;

			}
			// otherwise we're doing a cron job,
			// so save our imported photo notices to an option
			// to be displayed later
			else {
				// check if we already have some notices saved
				$notices = get_option( 'dsgnwrks_imported_photo_notices' );
				// if so, add to them
				if ( is_array( $notices ) )
					$notices[$userid] = array( 'notice' => $notice, 'time' => $time );
				// if not, create a new one
				else
					$notices = array( $userid => array( 'notice' => $notice, 'time' => $time ) );

				// save our option
				update_option( 'dsgnwrks_imported_photo_notices', $notices );
			}
		}

		// Save the date/time to notify users of last import time
		set_transient( sanitize_title( urldecode( $this->userid ) ) .'-instaimportdone', $time, 14400 );

	}

	/**
	 * Actually fires the import and returns the messages
	 * @param  boolean $loop whether to loop the instagram pages
	 * @return array         success/error messages
	 */
	public function do_import( $loop = false ) {

		$opts = $this->opts;

		if ( isset( $opts['remove_hashtags'] ) && is_array( $opts['remove_hashtags'] ) ) {
			foreach ( $opts['remove_hashtags'] as $filter => $value ) {
				if ( $value )
					add_filter( 'dsgnwrks_instagram_'.$filter, array( $this, 'remove_hashtags' ) );
			}
		}

		// if a timezone string was saved
		if ( $tz_string = get_option(' timezone_string ') ) {
			// save our current date to a var
			$pre = date('e');
		 	// and tell php to use WP's timezone string
			date_default_timezone_set( get_option( 'timezone_string' ) );
		}

		// if ( $this->next_url = get_transient( 'dsgnwrks_next_url' ) )
		// 	wp_send_json_error( '<div id="message" class="updated"><pre>'. htmlentities( print_r( $this->next_url, true ) ) .'</pre></div>' );

		$this->api_url = isset( $this->next_url ) && $this->next_url ? $this->next_url : $this->instagram_api . $opts[$this->userid]['id'] .'/media/recent?access_token='. $opts[$this->userid]['access_token'] .'&count=2';
		// trigger_error( $this->api_url );

		// ok, let's access instagram's api
		$messages = $this->import_messages( $this->api_url, $opts[$this->userid] );
		// if the api gave us a "next" url, let's loop through till we've hit all pages
		// while ( !empty( $messages['next_url'] ) && $loop ) {
		if ( !empty( $messages['next_url'] ) )
			update_option( 'dsgnwrks_next_url', $messages['next_url'] );
		// 	$messages = $this->import_messages( $messages['next_url'], $opts[$this->userid], $messages['message'] );
		// }


		// wp_send_json_error( '<div id="message" class="updated"><pre>'. htmlentities( print_r( $messages, true ) ) .'</pre></div>' );


		// debug sent?
		$this->importDebugSet();

		// return php's timezone to its previously set value
		if ( $tz_string )
			date_default_timezone_set( $pre );

		return $messages;

	}

	/**
	 * pings instagram with our user's feed url to retrieve photos
	 * @since  1.1.0
	 * @param  string $api_url      Instagram's api url
	 * @param  array  $settings     our user's saved settings
	 * @param  array  $prevmessages Previous messages from the api pagination loop
	 * @return array                messages array
	 */
	protected function import_messages( $api_url, $settings, $prevmessages = array() ) {

		// our individual user's settings
		$this->settings = $settings;
		// get instagram feed
		$response       = wp_remote_get( $api_url, array( 'sslverify' => false ) );
		// if feed causes a wp_error object, send the error back
		if ( is_wp_error( $response ) )
			return $this->wp_error_message( $response );

		// otherwise, let's get our api and format our data to be useable
		$data = json_decode( wp_remote_retrieve_body( $response ) );

		// wp_send_json_error( '<div id="message" class="error"><p><pre>$data'. htmlentities( print_r( $data, true ) ) .'</pre></p></div>' );

		if ( !$this->importDebugCheck() )
			$this->debugsend( 'import_messages', $this->userid .' - $data', array(
				'$api_url' => $api_url,
				'$this->userid' => $this->userid,
				'$response[headers]' => $response['headers'],
				'json_decode( wp_remote_retrieve_body( $response ) )' => $data,
				'$prevmessages' => $prevmessages,
				'$settings' => $settings
			) );

		// load WP files to use functions in them
		require_once(ABSPATH . 'wp-admin/includes/file.php');
		require_once(ABSPATH . 'wp-admin/includes/media.php');
		set_time_limit(300);

		// let's leave our instagram images as full-quality. You'll thank me later ;)
		add_filter( 'wp_editor_set_quality', array( $this, 'max_quality' ) );
		add_filter( 'jpeg_quality', array( $this, 'max_quality' ) );

		// now that we have our feed data, let's loop it
		$messages = $this->pic_loop( $data );
		// if the api gave us a "next" url add it, if not, 'halt' our progress
		$next_url = ( !isset( $data->pagination->next_url ) || isset( $messages['nexturl'] ) && $messages['nexturl'] == 'halt' ) ? '' : $data->pagination->next_url;

		// merge previous messages
		$messages = ( isset( $messages['messages'] ) ) ? array_merge( $prevmessages, $messages['messages'] ) : $prevmessages;

		// Remove our max quality filter
		remove_filter( 'wp_editor_set_quality', array( $this, 'max_quality' ) );
		remove_filter( 'jpeg_quality', array( $this, 'max_quality' ) );

		// return an array of messages and our "next" url
		if ( empty( $messages ) && empty( $prevmessages ) )
			return array(
				'message'  => array( $this->message_wrap( __( 'No new Instagram shots to import', 'dsgnwrks' ) ) ),
				'next_url' => $next_url,
			);

		return array(
			'message'  => $messages,
			'next_url' => $next_url,
		);
	}

	/**
	 * Loops through instagram api data
	 * @since  1.1.0
	 * @param  array $data instagram photo data
	 * @return array       post created/error messages
	 */
	protected function pic_loop( $data = array() ) {

		// 'Type' to be imported (images/video)
		$this->settings['types'] = apply_filters( 'dsgnwrks_instagram_import_types', array( 'video', 'image' ), $this->userid );

		// if we have invalid data, bail here
		if ( !isset( $data->data ) || !is_array( $data->data ) )
			return array();

		// loop!
		foreach ( $data->data as $this->pic ) {

			if ( ! in_array( $this->pic->type, $this->settings['types'] ) )
				continue;

			// if user has a date filter set, check it
			if ( isset( $this->settings['date-filter'] ) && $this->settings['date-filter'] > $this->pic->created_time ) {
				// and stop if we've passed the date filter time
				$messages['nexturl'] = 'halt';
				break;
			}

			// If we have tags to filter, and image does not contain the right tags, move on
			if ( ! $this->has_tags( $this->pic->tags ) )
				continue;

			// if the photo is already saved, move on
			if ( $this->image_exists( $this->pic->created_time ) )
				continue;

			// if we've made it this far, let's save our post
			$messages['messages'][] = $this->save_img_post();
		}

		// return our $messages array
		return !empty( $messages ) ? $messages : array();
	}

	/**
	 * Saves a WP post with our instagram photo data
	 * @since  1.1.0
	 */
	protected function save_img_post() {

		global $user_ID;

		$settings                = &$this->settings;
		$p                       = &$this->pic;
		// init our $import settings array var
		$import                  = &$this->import;
		// in case we haven't gotten our settings yet. (unlikely)
		$settings                = ( empty( $settings ) ) ? get_option( 'dsgnwrks_insta_options' ) : $settings;
		// check for a location saved
		$this->loc               = ( isset( $p->location->name ) ) ? $p->location->name : '';

		// Update post title
		$this->formatTitle();

		// save photo as featured?
		$import['featured']      = ( isset( $settings['feat_image'] ) && $settings['feat_image'] == true ) ? true : false;
		// save instagram photo caption as post excerpt
		$import['post_excerpt']  = !empty( $p->caption->text ) ? apply_filters( 'dsgnwrks_instagram_post_excerpt', $p->caption->text ) : '';

		$this->formatContent();

		// post author, default to current user
		$import['post_author']   = isset( $settings['author'] ) ? $settings['author'] : $user_ID;
		// post date, default to photo's created time
		$import['post_date']     = date( 'Y-m-d H:i:s', $p->created_time );
		$import['post_date_gmt'] = $import['post_date'];
		// post status, default to 'draft'
		$import['post_status']   = isset( $settings['draft'] ) ? $settings['draft'] : 'draft';
		// post type, default to 'post'
		$import['post_type']     = isset( $settings['post-type'] ) ? $settings['post-type'] : 'post';

		// A filter so filter-savvy devs can modify the data before the post is created
		$import                  = apply_filters( 'dsgnwrks_instagram_pre_save', $import, $p, $settings );

		// and insert our new post
		$import['post_id']       = $this->insertPost();

		// if a wp_error object, send the error back
		if ( is_wp_error( $import['post_id'] ) )
			return $this->wp_error_message( $import['post_id'], false );

		// an action to fire after each post is created.
		do_action( 'dsgnwrks_instagram_post_save', $import['post_id'], $p, $import, $settings );

		// Save terms from settings
		$this->saveTerms();

		// save instagram api data as postmeta
		$this->savePostmeta();

		// our post is properly saved, now let's bring the image/videos over to WordPress

		$this->type = 'image';
		// sideload image
		$message = $this->upload_media( $p->images->standard_resolution->url );

		// sideload videos
		if ( $this->pic->type == 'video' ) {
			$this->type = 'video';
			// grab both video sizes and upload them
			foreach ( array( 'low_resolution', 'standard_resolution' ) as $size ) {
				$vid_size = (int) $p->videos->$size->width;
				$message .= $this->upload_media(
					$p->videos->$size->url,
					sprintf( __( '%s Video', 'dsgnwrks' ), $vid_size .'x'. $vid_size ),
					$size
				);
			}
		}

		// Update post content with our modified post content that replaces the custom tags.
		$this->update_post_content();

		return $this->message_wrap( $message );
	}

	/**
	 * Gets hashtag filter setting and make an array
	 * @since  1.2.8
	 * @return array  Array of hashtags to filter by
	 */
	protected function get_settings_tags() {
		if ( isset( $this->settings_tags ) ) {
			return $this->settings_tags;
		}
		// if user doesn't have a tag filter set, bail
		if ( empty( $this->settings['tag-filter'] ) )
			return false;

		// get all tags saved for filtering
		$tags = explode( ', ', $this->settings['tag-filter'] );
		$this->settings_tags = array();
		// if we have tags...
		if ( $tags ) {
			// loop through them
			foreach ( $tags as $tag ) {
				// Remove hash symbol
				$this->settings_tags[] = str_replace( '#', '', $tag );
			}
		}
		return $this->settings_tags;
	}

	/**
	 * Determines if list of tags on image lines up with any settings tags
	 * @since  1.2.8
	 * @param  array  $tags Array of tags to check against
	 * @return boolean      Whether tags exist in settings
	 */
	protected function has_tags( $tags ) {
		// If we have no saved tag filters, automatically passes
		if ( ! $this->get_settings_tags() )
			return true;

		// If image has no tags, return false
		if ( ! is_array( $tags ) || empty( $tags ) )
			return false;

		// Check if any tags in settings align with any tags in image
		$tag_exists = array_intersect( $this->get_settings_tags(), $tags );

		// if no tags match, return false
		if ( empty( $tag_exists ) )
			return false;

		// Ok, tag exists
		return true;
	}

	/**
	 * Checks if intended image is already in WP
	 * @since  1.2.8
	 * @param  string $timestamp Image created time
	 * @return bool              Whether image exists or not
	 */
	protected function image_exists( $timestamp ) {
		// get user's post-type setting or default to 'post'
		$pt = isset( $this->settings['post-type'] ) ? $this->settings['post-type'] : 'post';
		$alreadyInSystem = new WP_Query(
			array(
				'post_type'      => $pt,
				'post_status'    => 'any',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'   => 'instagram_created_time',
						'value' => $timestamp
					)
				)
			)
		);
		// Returns whether photo already exists in WP
		return $alreadyInSystem->have_posts();
	}

	/**
	 * Formats new post title
	 * @since 1.2.2
	 */
	protected function formatTitle() {
		// Check for a title, or use 'Untitled'
		$this->insta_title          = !empty( $this->pic->caption->text ) ? $this->pic->caption->text : __( 'Untitled', 'dsgnwrks' );
		// Set post title to caption by default
		$this->import['post_title'] = $this->insta_title;

		// if our user's post-title option is saved
		if ( empty( $this->settings['post-title'] ) )
			return;
		// check for insta-text conditionals
		$t = $this->conditional( 'insta-text', $this->settings['post-title'], $this->import['post_title'] );
		// check for insta-location conditionals
		$t = $this->conditional( 'insta-location', $t, $this->loc );
		// Add the instagram filter name if requested
		$t = str_replace( '**insta-filter**', $this->pic->filter, $t );

		$this->import['post_title'] = apply_filters( 'dsgnwrks_instagram_post_title', $t );
	}

	/**
	 * Formats new post content
	 * @since 1.2.2
	 */
	protected function formatContent() {
		// if our user's post-content option is NOT saved
		if ( empty( $this->settings['post_content'] ) ) {

			$imgurl = $this->pic->images->standard_resolution->url;
			// we'll add some default content
			$c  = '<p><a href="'. $imgurl .'" target="_blank"><img src="'. $imgurl .'"/></a></p>'."\n";
			$c .= '<p>'. $this->import['post_excerpt'];
			if ( !empty( $this->loc ) )
				$c .= sprintf( __( ' (Taken with Instagram at %s)', 'dsgnwrks' ), $this->loc );
			$c .= '</p>'."\n";
			$c .= '<p>'. __( 'Instagram filter used:', 'dsgnwrks' ) .' '. $this->pic->filter .'</p>'."\n";
			$c .= '<p><a href="'. $this->pic->link .'" target="_blank">'. __( 'View in Instagram', 'dsgnwrks' ) .' &rArr;</a></p>'."\n";
		}
		// if our user's post-content option is saved
		else {
			$c = $this->settings['post_content'];
			// Add the instagram photo url if requested
			$c = str_replace( '**insta-link**', $this->pic->link, $c );
			// check for insta-text conditionals
			$c = $this->conditional( 'insta-text', $c, $this->insta_title );
			// check for insta-location conditionals
			$c = $this->conditional( 'insta-location', $c, $this->loc );
			// Add the instagram filter name if requested
			$c = str_replace( '**insta-filter**', $this->pic->filter, $c );
		}
		$this->import['post_content'] = apply_filters( 'dsgnwrks_instagram_post_content', $c );
	}

	/**
	 * Remove hashtags from imported instagram title/excerpt/content
	 * @since  1.2.6
	 * @param  string $content Instagram photo content
	 * @return string          Modified content
	 */
	public function remove_hashtags( $content ) {

		// hashtag pattern match
		$pattern = '/(^|[^0-9A-Z&\/\?]+)([#＃]+)([0-9A-Z_]*[A-Z_]+[a-z0-9_üÀ-ÖØ-öø-ÿ]*)/iu';
		// replace them
		$clean_content = trim( preg_replace( $pattern, '', $content ) );

		// if the result is empty (only hashtags), remove only the hash symbol instead
		$content = empty( $clean_content ) ? trim( str_replace( '#', '', $content ) ) : $clean_content;

		return $content;
	}

	/**
	 * Creates new post from instagram data and saved plugin settings
	 * @since 1.2.2
	 */
	protected function insertPost() {
		// insert our new post with its data
		return wp_insert_post( array(
			'post_author'   => $this->import['post_author'],
			'post_content'  => $this->import['post_content'],
			'post_date'     => $this->import['post_date'],
			'post_date_gmt' => $this->import['post_date_gmt'],
			'post_excerpt'  => $this->import['post_excerpt'],
			'post_status'   => $this->import['post_status'],
			'post_title'    => $this->import['post_title'],
			'post_type'     => $this->import['post_type'],
		), true );
	}

	/**
	 * Saves terms to post from settings fields
	 * @since 1.2.2
	 */
	protected function saveTerms() {
		// loop through our taxonomies
		$taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
		foreach ( $taxonomies as $tax ) {
			// only save post-formats on themes which support them
			if ( $tax->label == __( 'Format' ) && !current_theme_supports( 'post-formats' ) )
				continue;
			// get user saved taxonomy terms
			$this->settings[$tax->name] = !empty( $this->settings[$tax->name] ) ? esc_attr( $this->settings[$tax->name] ) : '';
			$terms = explode( ', ', $this->settings[$tax->name] );
			// If requested, set photo hashtags as taxonomy terms
			$terms = array_merge( $terms, $this->saveHashtags( $tax->name ) );

			// if user set taxonomy terms to be saved...
			if ( empty( $terms ) )
				continue;

			// Clean up terms
			$clean_terms = array();
			foreach ( $terms as $term ) {
				$clean_terms[] = sanitize_text_field( trim( $term ) );
			}

			// Save our terms to the post
			if ( !empty( $clean_terms ) )
				wp_set_object_terms( $this->import['post_id'], $clean_terms, $tax->name );
		}
	}

	/**
	 * If option is set, will save each photo hashtag to a taxonomy term
	 * @since 1.2.2
	 */
	protected function saveHashtags( $taxonomy_name ) {

		if ( isset( $this->settings['hashtags_as_tax'] ) && $this->settings['hashtags_as_tax'] == $taxonomy_name )
			return (array) $this->pic->tags;

		return array();
	}

	/**
	 * Save instagram api data pieces to post_meta
	 * @since 1.2.2
	 */
	protected function savePostmeta() {

		foreach ( array(
			'dsgnwrks_instagram_likes'    => $this->pic->likes,
			'dsgnwrks_instagram_comments' => $this->pic->comments,
			'dsgnwrks_instagram_hashtags' => $this->pic->tags,
			'instagram_created_time'      => $this->pic->created_time,
			'dsgnwrks_instagram_id'       => $this->pic->id,
			'instagram_filter_used'       => $this->pic->filter,
			'instagram_attribution'       => $this->pic->attribution,
			'instagram_location'          => $this->pic->location,
			'instagram_users_in_photo'    => $this->pic->users_in_photo,
			'instagram_link'              => esc_url( $this->pic->link ),
			'instagram_embed_code'        => $this->instagram_embed(),
			'instagram_type'              => esc_url( $this->pic->type ),
			'instagram_user'              => $this->userid,
		) as $key => $value )
			update_post_meta( $this->import['post_id'], $key, $value );

	}

	/**
	 * Wraps Instagram url in the iframe that Instagram provides for embedding
	 * @since  1.2.6
	 * @param  string $url Instagram media URL
	 * @return string      Instagram embed iframe code
	 */
	protected function instagram_embed( $url = '' ) {

		if ( !$url && !isset( $this->pic->link ) )
			return false;
		if ( !$url && isset( $this->pic->link ) )
			$url = $this->pic->link;

		$url = str_replace( 'embed/', '', str_replace( 'http://', '//', esc_url( $url ) ) );

		return "\r\n".'<iframe src="'. $url .'embed/" width="612" height="710" frameborder="0" scrolling="no" allowtransparency="true"></iframe>'."\r\n";
	}

	/**
	 * Returns Instagram embed shortcode
	 * @since  1.2.6
	 * @return string Instagram embed shortcode
	 */
	protected function instagram_embed_src( $type = 'video' ) {
		return '[dsgnwrks_instagram_embed src="'. esc_url( $this->pic->link ) .'" type="'. $type .'"]';
	}

	/**
	 * Shortcode that displays Instagram embed iframe
	 * @since  1.2.6
	 * @param  array  $atts Attributes passed from shortcode
	 * @return string       Concatenated shortcode output (Iframe embed code)
	 */
	public function embed_shortcode( $atts ) {
		if ( !isset( $atts['src'] ) )
			return '';
		return $this->instagram_embed( $atts['src'] );
	}

	/**
	 * Sideloads an image/video to the currrent WordPress post
	 * @since  1.1.0
	 * @param  string $media_url    URL of media to be sideloaded
	 * @param  string $attach_title Optional title for uploaded media attachement
	 * @param  string $size         Optional size of media
	 * @return string               Error|Success message
	 */
	protected function upload_media( $media_url = '', $attach_title = '', $size = '' ) {

		// get our import data
		$import = &$this->import;
		// image or video?
		$is_image = ( $this->type == 'image' );

		// bail here if we don't have a media url
		if ( empty( $media_url ) )
			return $this->upload_error(__LINE__);

		if ( $this->doing_cron ) {
			require_once (ABSPATH.'/wp-admin/includes/file.php');
			require_once (ABSPATH.'/wp-admin/includes/media.php');
			require_once (ABSPATH.'/wp-admin/includes/image.php');
		}

		$tmp = download_url( $media_url );

		// check for file extensions
		$pattern = $is_image
			? '/[^\?]+\.(jpe?g|jpe|gif|png)\b/i'
			: '/[^\?]+\.(mp4|MP4)/';

		preg_match( $pattern, $media_url, $matches );
		$file_array['name']     = basename( $matches[0] );
		$file_array['tmp_name'] = $tmp;

		if ( is_wp_error( $tmp ) ) {
			@unlink( $file_array['tmp_name'] );
			$file_array['tmp_name'] = '';
		}

		// post title or custom title
		$attach_title = $attach_title ? $attach_title : $import['post_title'];

		$attach_id = media_handle_sideload( $file_array, $import['post_id'], $attach_title );

		if ( is_wp_error( $attach_id ) ) {
			@unlink( $file_array['tmp_name'] );
			// may return an error if they're on multisite and don't have mp4 enabled
			return $this->upload_error( __LINE__, $media_url, $attach_id->get_error_message() );
		}

		if ( ! $is_image ) {

			// Save our video attachement ID's and their urls as post-meta
			update_post_meta( $import['post_id'], 'instagram_video_id_'. $size, $attach_id );
			update_post_meta( $import['post_id'], 'instagram_video_url_'. $size, wp_get_attachment_url( $attach_id ) );

			return '<em> '. sprintf( __( '%s imported.', 'dsgnwrks' ), '<b>'. $attach_title .'</b>' ) .'</em>';
		} else {
			// Save our photo attachement ID as post-meta
			update_post_meta( $import['post_id'], 'instagram_image_id', $attach_id );
		}

		if ( $import['featured'] )
			set_post_thumbnail( $import['post_id'], $attach_id );

		$imgsize   = apply_filters( 'dsgnwrks_instagram_image_size', 'full' );
		$imgsize   = is_array( $imgsize ) || is_string( $imgsize ) ? $imgsize : 'full';
		$this->img = wp_get_attachment_image_src( $attach_id, $imgsize );

		// Replace URLs in post with uploaded image
		if ( is_array( $this->img ) ) {

			// filter the image element
			$this->insta_image = (string) apply_filters( 'dsgnwrks_instagram_insta_image', sprintf( '<img class="insta-image" width="%d" height="%d" src="%s"/>', $this->img[1], $this->img[2], $this->img[0] ), $attach_id, $import['post_id'] );

		} else {
			return $this->upload_error( __LINE__, $media_url );
		}

		// return a success message
		return get_the_post_thumbnail( $import['post_id'], array( 50, 50 ) ) .'<strong>&ldquo;'. $import['post_title'] .'&rdquo;</strong> <em> '. __( 'imported and created successfully.', 'dsgnwrks' ) .'</em>';

	}

	/**
	 * Returns an upload error message
	 * @since  1.2.0
	 * @param  int    $line      Line number where error occurred.
	 * @param  string $media_url (optional) url for image to be uploaded
	 * @return string         Upload error message
	 */
	protected function upload_error( $line, $media_url = false, $error = '' ) {

		$import = &$this->import;

		// Hanlde a video error a bit differently
		if ( $this->type == 'video' ) {
			$error = $error ? $error : __( 'There was an error with the video upload.', 'dsgnwrks' );
			return '<div>'. $error .'</div>';
		}


		if ( ! $media_url )
			$replace = array( __( 'image error', 'dsgnwrks' ), __( 'image error', 'dsgnwrks' ) );
		elseif ( $this->has_embed() )
			$replace = array( '', $media_url );
		else
			$replace = array( '<img src="'. $media_url .'"/>', $media_url );

		$import['post_content'] = str_replace( array(
			// Add the instagram image element if requested
			'**insta-image**',
			// Add the instagram image source if requested
			'**insta-image-link**',
		), $replace, $import['post_content'] );


		// return an image upload error message
		return '<div><strong>&ldquo;'. $import['post_title'] .'&rdquo;</strong> <em class="warning">'. sprintf( __( 'created successfully but there was an error with the image upload. Line: %d', 'dsgnwrks' ), $line ) .'</em></div>';
	}

	/**
	 * Replaces the embed tags with the appropriate type embed and updates post.
	 * @since  1.2.6
	 */
	protected function update_post_content() {

		$insta_img = ( $this->has_embed( 'image' ) ? '' : $this->insta_image );
		$insta_img = ( $this->has_embed( 'video' ) && $this->type == 'video' ? '' : $this->insta_image );

		error_log( print_r( array( __LINE__ .' $this->type' => $this->type, '$this->has_embed( image )' => $this->has_embed( 'image' ), '$this->has_embed( video )' => $this->has_embed( 'video' ), '$insta_img' => $insta_img ), true ) );

		$this->import['post_content'] = str_replace( array(
			// Add the instagram image element if requested
			'**insta-image**',
			// Add the instagram image source if requested
			'**insta-image-link**',
			// Add the instagram embed shortcode if requested
			'**insta-embed-'. $this->type .'**',
			'**insta-embed-'. ( $this->type == 'image' ? 'video' : 'image' ) .'**',
		), array(
			$insta_img,
			( is_array( $this->img ) ? $this->img[0] : '' ),
			$this->instagram_embed_src(),
			'',
		), $this->import['post_content'] );

		// Update the post with updated image URLs or errors
		wp_update_post( array(
			'ID'           => $this->import['post_id'],
			'post_content' => $this->import['post_content'],
		) );
	}

	/**
	 * Checks if post content template contains the embed tags.
	 * @since  1.2.6
	 */
	protected function has_embed( $type = '' ) {
		return false !== stripos( $this->import['post_content'], '**insta-embed-'. $type );
	}

	/**
	 * Returns error message contained within wp_error
	 * @since  1.2.2
	 * @return array Error message
	 */
	protected function wp_error_message( $error, $array = true ) {

		$message = $this->message_wrap( '<strong>ERROR:</strong> '. $error->get_error_message() );
		// return the wp_error message
		return $array ? array( 'message' => $message ) : $message;

	}

	/**
	 * Returns error message contained within wp_error
	 * @since  1.2.6
	 * @param  string $message_text
	 * @return string               Message text wrapped in li markup
	 */
	protected function message_wrap( $message_text ) {
		return '<li>'. $message_text .'</li>';

	}

	/**
	 * Checks for conditionals, runs them, and removes the conditional markup
	 * @since  1.1.4
	 * @param  string $tag     tag to check for
	 * @param  string $content content to search for tags
	 * @param  string $replace content to replace tags with
	 * @return string          modified content
	 */
	protected function conditional( $tag, $content, $replace ) {

		$open  = '[if-'.$tag.']';
		$close = '[/if-'.$tag.']';
		$tag   = '**'.$tag.'**';

		// if we have conditional markup
		if ( ( $pos1 = strpos( $content, $open ) ) && ( $pos2 = strpos( $content, $close ) ) ) {

			if ( !empty( $replace ) ) {
				// replace tag with our photo data
				$content = str_replace( $tag, $replace, $content );
				// remove shortcode markup
				$content = str_replace( $open, '', $content );
				$content = str_replace( $close, '', $content );
			} else {
				// if no replace data is provided, just remove our shortcode markup
				$length  = ( $pos2 + strlen( $close ) ) - $pos1;
				$content = substr_replace( $content, '', $pos1, $length );
			}

		}
		// Otherwise disregard conditional and just replace tag
		else {
			$content = str_replace( $tag, $replace, $content );
		}
		// return our modified data
		return apply_filters( 'dsgnwrks_instagram_'.$tag, $content, $replace );
	}

	/**
	 * Checks for query parameters and does subsequent redirects
	 * @since  1.1.0
	 */
	public function redirects() {

		// if we have an error or access token
		if ( isset( $_GET['error'] ) || isset( $_GET['access_token'] ) )  {

			$opts   = get_option( 'dsgnwrks_insta_options' );
			$users  = get_option( 'dsgnwrks_insta_users' );
			$users  = ( !empty( $users ) ) ? $users : array();
			$notice = array(
				'notice' => false,
				'class'  => 'updated',
			);

			if ( isset( $_GET['error'] ) || isset( $_GET['error_reason'] ) || isset( $_GET['error_description'] ) ) {
				$notice['class'] = 'error';
			} else {
				$notice['notice'] = 'success';

				// setup our user data and save it
				if ( isset( $_GET['username'] ) && !in_array( $_GET['username'], $users ) ) {
					$sanitized_user                           = sanitize_title( $_GET['username'] );
					$users[]                                  = $sanitized_user;
					$opts[$sanitized_user]['access_token']    = $_GET['access_token'];
					// $opts[$sanitized_user]['bio']             = isset( $_GET['bio'] ) ? $_GET['bio'] : ''; // more trouble than it's worth.
					$opts[$sanitized_user]['website']         = isset( $_GET['website'] ) ? esc_url_raw( $_GET['website'] ) : '';
					$opts[$sanitized_user]['profile_picture'] = isset( $_GET['profile_picture'] ) ? esc_url_raw( $_GET['profile_picture'] ) : '';
					$opts[$sanitized_user]['full_name']       = isset( $_GET['full_name'] ) ? sanitize_text_field( $_GET['full_name'] ) : '';
					$opts[$sanitized_user]['id']              = isset( $_GET['id'] ) ? sanitize_text_field( $_GET['id'] ) : '';
					$opts[$sanitized_user]['full_username']   = $_GET['username'];

					foreach ( $this->defaults as $key => $default ) {
						$opts[$sanitized_user][$key] = $default;
					}

					$opts['username']  = $sanitized_user;
					$opts['frequency'] = isset( $opts['frequency'] ) ? $opts['frequency'] : 'never';

					update_option( 'dsgnwrks_insta_users', $users );
					update_option( 'dsgnwrks_insta_options', $opts );
					delete_option( 'dsgnwrks_insta_registration' );
				}

			}
			// So notice isn't persistent past 60 seconds
			set_transient( 'instagram_notification', true, 60 );
			// redirect with notices
			wp_redirect( add_query_arg( 'query_arg', 'updated', $this->plugin_page() ), 307 );
			exit;
		}

		if ( !isset( $_GET['delete-insta-user'] ) )
			return;

		// if we're requesting to delete a user

		// delete the user
		$users = get_option( 'dsgnwrks_insta_users' );
		foreach ( $users as $key => $user ) {
			if ( $user == urldecode( $_GET['delete-insta-user'] ) ) $delete = $key;
		}
		unset( $users[$delete] );
		update_option( 'dsgnwrks_insta_users', $users );

		// delete the user's data
		$opts = get_option( 'dsgnwrks_insta_options' );
		unset( $opts[urldecode( $_GET['delete-insta-user'] )] );
		if ( isset( $opts['username'] ) && $opts['username'] == sanitize_title( urldecode( $_GET['delete-insta-user'] ) ) )
		unset( $opts['username'] );
		update_option( 'dsgnwrks_insta_options', $opts );

		// redirect to remove the query arg (to keep from repeat-deleting)
		wp_redirect( remove_query_arg( 'delete-insta-user' ), 307 );
		exit;
	}

	/**
	 * Helper function to sanitized variables using whitelisted filters and set a default
	 * @since  1.1.0
	 * @param  mixed  $opt    option value to be saved
	 * @param  string $filter filter to run option through
	 * @param  mixed  $else   default value if no option value
	 * @return mixed          sanitized option value
	 */
	protected function filter( $opt = '', $filter = '', $else = '' ) {
		// if $opt is empty, return our default if set, or nothing
		if ( empty( $opt ) )
			return $else;

		// do our filters
		switch ( $filter ) {
			case 'absint':
				return absint( $opt );
			case 'esc_textarea':
				return esc_textarea( $opt );
			case 'wp_kses_post':
				return wp_kses_post( $opt );
			case 'bool' :
				return !empty( $opt );
			default:
				return esc_attr( $opt );
		}
	}

	/**
	 * Sets maximum quality for WP image saving
	 * @since  1.1.0
	 * @param  int $arg WordPress image quality setting
	 * @return int      modified quality setting
	 */
	public function max_quality($arg) {
		return (int) 100;
	}

	/**
	 * @TODO When deleteing a post, importer should not import them again
	 */
	public function save_id_on_delete( $post_id ) {
		// get_post_meta( $post_id, 'instagram_created_time', true );
	}

	/**
	 * Form element for an "add a user" admin section with a user authentication button
	 */
	protected function settings_user_form( $users = array(), $message = '' ) {

		$message = $message ? $message : '<p>'. __( 'Click to be taken to Instagram\'s site to securely authorize this plugin for use with your account.', 'dsgnwrks' ) .'</p><p><em>'. __( '(If you have already authorized an account, You will first be logged out of Instagram.)', 'dsgnwrks' ) .'</em></p>'; ?>
		<form class="instagram-importer user-authenticate" method="post" action="options.php">
			<?php
			settings_fields('dsgnwrks_instagram_importer_users');
			echo $message;
			$class = !empty( $users ) ? 'logout' : '';
			?>
			<p class="submit">
				<input type="submit" name="save" class="button-primary authenticate <?php echo $class; ?>" value="<?php _e( 'Secure Authentication with Instagram', 'dsgnwrks' ); ?>" />
			</p>
		</form>
		<?php
	}

	/**
	 * Form element for setting universal plugin options (auto-import frequency, debug mode)
	 */
	protected function universal_options_form() {
		?>
		<table class="form-table">
			<tbody>
				<tr valign="top" class="info">
					<th colspan="2">
						<h3><img class="alignleft" src="<?php echo plugins_url( 'images/merge.png', __FILE__ ); ?>" width="83" height="66"><?php _e( 'Universal Import Options', 'dsgnwrks' ); ?></h3>
						<p><?php _e( 'Please select the general import options below.', 'dsgnwrks' ); ?></p>
					</th>
				</tr>
				<tr valign="top">
					<th scope="row"><strong><?php _e( 'Set Auto-import Frequency:', 'dsgnwrks' ); ?></strong></th>
					<td>
						<select name="dsgnwrks_insta_options[frequency]">
							<option value="never" <?php echo selected( $this->opts['frequency'], 'never' ); ?>><?php _e( 'Manual', 'dsgnwrks' ); ?></option>
							<?php
							foreach ( $this->schedules as $key => $value ) {
								echo '<option value="'. $key .'"'. selected( $this->opts['frequency'], $key, false ) .'>'. $value['display'] .'</option>';
							}
							?>
						</select>
					</td>
				</tr>
				<tr valign="top" class="remove-hashtags">
					<th scope="row"><strong><?php _e( 'Remove #hashtags when saving post:', 'dsgnwrks' ); ?></strong></th>
					<td>
						<label><input type="checkbox" name="dsgnwrks_insta_options[remove_hashtags][post_title]" <?php checked( isset( $this->opts['remove_hashtags']['post_title'] ) && $this->opts['remove_hashtags']['post_title'] ); ?> value="yes"/>&nbsp;Title</label>
						<label><input type="checkbox" name="dsgnwrks_insta_options[remove_hashtags][post_content]" <?php checked( isset( $this->opts['remove_hashtags']['post_content'] ) && $this->opts['remove_hashtags']['post_content'] ); ?> value="yes"/>&nbsp;Content</label>
						<label><input type="checkbox" name="dsgnwrks_insta_options[remove_hashtags][post_excerpt]" <?php checked( isset( $this->opts['remove_hashtags']['post_excerpt'] ) && $this->opts['remove_hashtags']['post_excerpt'] ); ?> value="yes"/>&nbsp;Excerpt</label>

					</td>
				</tr>
				<?php do_action( 'dsgnwrks_instagram_univeral_options', $this->opts ); ?>
			</tbody>
		</table>
		<p class="submit">
			<input type="submit" name="save" class="button-primary save" value="<?php _e( 'Save', 'dsgnwrks' ) ?>" />
		</p>
		<?php
	}

	/**
	 * a link to instagram import admin page with user pre-selected
	 * @since  1.1.0
	 * @param  string $id Instagram user id
	 * @return string     Instagram importer options page with selected user
	 */
	protected function instimport_link( $id ) {
		return add_query_arg( array( 'page' => $this->plugin_id, 'instaimport' => urlencode( $id ) ), admin_url( $GLOBALS['pagenow'] ) );
	}

	/**
	 * Set wp_editor default to 'html' on our admin page
	 * @since  1.1.0
	 * @param  string $default WordPress editor instance default view
	 * @return string      		modified view
	 */
	public function html_default( $default ) {
		if ( get_current_screen()->id == 'tools_page_dsgnwrks-instagram-importer-settings' )
			$default = 'html';
		return $default;
	}

	/**
	 * Checks if user has enabled Debug Mode
	 * Requires DsgnWrks Instagram Debug plugin.
	 * @since  1.2.1
	 * @return bool debug on or off
	 */
	public function debugEnabled() {
		return isset( $this->opts['debugmode'] ) && $this->opts['debugmode'] == 'on';
	}

	/**
	 * Sets option stating user just sent an import debug (only want to send once!)
	 * Requires DsgnWrks Instagram Debug plugin.
	 * @since  1.2.1
	 */
	public function importDebugSet() {
		if ( !$this->debugEnabled() )
			return;
		update_option( 'dsgnwrks-import-debug-sent', 'sent' );
	}

	/**
	 * Checks if user sent an import debug already (only want to send once!)
	 * Requires DsgnWrks Instagram Debug plugin.
	 * @since  1.2.1
	 * @return bool send debug
	 */
	public function importDebugCheck() {
		if ( !$this->debugEnabled() )
			return true;
		return get_option( 'dsgnwrks-import-debug-sent' ) ? true : false;
	}

	/**
	 * Sends me a debug report if Debug Mode is enabled
	 * Requires DsgnWrks Instagram Debug plugin.
	 * @since  1.2.1
	 */
	public function debugsend( $line, $title = false, $data = false ) {
		if ( !$this->debugEnabled() )
			return;
		// default $data is options and users
		$data  = !$data ? print_r( array( 'opts' => $this->opts, 'users' => $this->users ), true ) : print_r( $data, true );
		// default title
		$title = !$title ? 'no $opts[$id] - $opts & $users' : esc_attr( $title );
		wp_mail( 'justin@dsgnwrks.pro', 'Instagram Debug - '. $title .' - line '. $line, $data );
	}

	/**
	 * Sets the 'dsgnwrks_instagram_id' post-meta on posts imported before 1.2.7
	 * @since  1.2.7
	 * @param  int   $post_id Post id
	 * @return mixed          Image id on success
	 */
	public static function maybe_set_instagram_image_id( $post_id ) {
		$is_instagram = get_post_meta( $post_id, 'dsgnwrks_instagram_id', 1 );

		if ( ! $is_instagram )
			return false;

		$image_ids = array_keys(
			get_children(
				array(
					'post_parent'    => $post_id,
					'post_type'      => 'attachment',
					'post_mime_type' => 'image',
					'orderby'        => 'menu_order',
					'order'          => 'ASC',
					'numberposts'    => 1,
				)
			)
		);

		if ( isset( $image_ids[0] ) ) {
			update_post_meta( $post_id, 'instagram_image_id', $image_ids[0] );
			return $image_ids[0];
		}
		return false;

	}

}

// init our class
$DsgnWrksInstagram = new DsgnWrksInstagram;

/**
 * Template tag that returns html markup for instagram imported image. Works like `get_the_post_thumbnail`
 * @since  1.2.7
 * @param  int         $post_id Post ID
 * @param string|array $size    Optional. Image size. Defaults to 'post-thumbnail', which theme sets using set_post_thumbnail_size( $width, $height, $crop_flag );.
 * @param string|array $attr    Optional. Query string or array of attributes.
 * @return string               Image html markup
 */
function dw_get_instagram_image( $post_id = null, $size = 'post-thumbnail', $attr = array() ) {

	$post_id = null === $post_id
		? get_the_ID()
		: $post_id;

	$img_id = get_post_meta( $post_id, 'instagram_image_id', 1 );
	if ( ! $img_id ) {
		$img_id = DsgnWrksInstagram::maybe_set_instagram_image_id( $post_id );
	}

	$html = $img_id
		? wp_get_attachment_image( $img_id, $size, false, $attr )
		: '';

	return apply_filters( 'dw_get_instagram_image', $html, $post_id, $img_id, $size, $attr );
}

/**
 * Template tag that displays instagram imported image. Works like `the_post_thumbnail`
 * @since  1.2.7
 * @param string|array $size Optional. Image size. Defaults to 'post-thumbnail', which theme sets using set_post_thumbnail_size( $width, $height, $crop_flag );.
 * @param string|array $attr Optional. Query string or array of attributes.
 */
function dw_instagram_image( $size = 'post-thumbnail', $attr = '' ) {
	echo dw_get_instagram_image( null, $size, $attr );
}
