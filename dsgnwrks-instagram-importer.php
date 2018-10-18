<?php
/*
Plugin Name: DsgnWrks Instagram Importer
Plugin URI: http://dsgnwrks.pro/plugins/dsgnwrks-instagram-importer
Description: Allows you to backup your instagram photos while allowing you to have a site to display your instagram archive. Allows you to import to custom post types and attach custom taxonomies.
Author URI: http://dsgnwrks.pro
Author: DsgnWrks
Donate link: http://dsgnwrks.pro/give/
Version: 1.4.1
*/

class DsgnWrksInstagram extends DsgnWrksInstagram_Debug {

	/**
	 * Plugin version string
	 *
	 * @var string
	 */
	public $plugin_version = '1.4.1';

	/**
	 * Plugin name
	 *
	 * @var string
	 */
	public $plugin_name = '';

	/**
	 * URL to plugin directory
	 *
	 * @var string
	 */
	public $plugin_url = '';

	/**
	 * Path to plugin directory
	 *
	 * @var string
	 */
	public $plugin_path = '';

	/**
	 * Plugin settings page slug
	 *
	 * @var string
	 */
	public $settings_slug = 'dsgnwrks-instagram-importer-settings';

	/**
	 * URL to plugin settings page
	 *
	 * @var string
	 */
	public $plugin_page = '';

	/**
	 * Whether a manual import has been initiated.
	 *
	 * @var boolean
	 */
	public $manual_import  = false;

	/**
	 * Plugin prefix
	 *
	 * @var string
	 */
	public $pre = 'dsgnwrks_instagram_';

	/**
	 * Instagram API URL
	 *
	 * @var string
	 */
	public $instagram_api  = 'https://api.instagram.com/v1/users/';

	/**
	 * Type of media being imported.
	 *
	 * @var string
	 */
	public $type           = 'image';

	/**
	 * wp_insert_post import array
	 *
	 * @var array
	 */
	public $import         = array();

	/**
	 * Whether current request is a cron request
	 *
	 * @var boolean
	 */
	public $doing_cron     = false;

	/**
	 * Whether to reimport from the beginning.
	 *
	 * @var boolean
	 */
	public $reimport     = false;

	/**
	 * The instagram image markup
	 *
	 * @var string
	 */
	public $insta_image    = '';

	/**
	 * The instagram image URL src
	 *
	 * @var string
	 */
	public $img_src        = '';

	/**
	 * Default setting values
	 *
	 * @var array
	 */
	public $defaults = array();

	/**
	 * @var DsgnWrksInstagram_Embed object
	 */
	public $embed;

	/**
	 * @var DsgnWrksInstagram_Settings object
	 */
	public $settings;

	protected static $single_instance = null;

	/**
	 * Creates or returns an instance of this class.
	 * @since  0.1.0
	 * @return DsgnWrksInstagram A single instance of this class.
	 */
	public static function get_instance() {
		if ( null === self::$single_instance ) {
			self::$single_instance = new self();
		}

		return self::$single_instance;
	}

	/**
	 * Sets up our plugin
	 * @since  1.1.0
	 */
	protected function __construct() {

		// user option defaults
		$this->defaults = array(
			'tag-filter'   => false,
			'feat_image'   => 'yes',
			'auto_import'  => false,
			'date-filter'  => 0,
			'mm'           => date( 'm', strtotime( '-1 month' ) ),
			'dd'           => date( 'd', strtotime( '-1 month' ) ),
			'yy'           => date( 'Y', strtotime( '-1 month' ) ),
			'post-title'   => '**insta-text**',
			'post_content' => '<p><a href="**insta-link**" target="_blank">**insta-image**</a></p>'."\n".'<p>'. __( 'Instagram filter used:', 'dsgnwrks' ) .' **insta-filter**</p>'."\n".'[if-insta-location]<p>'. __( 'Photo taken at:', 'dsgnwrks' ) .' **insta-location**</p>[/if-insta-location]'."\n".'<p><a href="**insta-link**" target="_blank">'. __( 'View in Instagram', 'dsgnwrks' ) .' &rArr;</a></p>',
			'post-type'    => 'post',
			'draft'        => 'draft',
		);

		// i18n
		$this->plugin_name   = 'DsgnWrks '. __( 'Instagram Importer', 'dsgnwrks' );
		$this->plugin_url    = plugins_url( '/', __FILE__ );
		$this->plugin_path   = trailingslashit( plugin_dir_path( __FILE__ ) );
		// Get the url for the plugin admin page
		$this->plugin_page   = add_query_arg( 'page', $this->settings_slug, admin_url( '/tools.php' ) );
		$this->manual_import = isset( $_GET['instaimport'] ) ? sanitize_title( urldecode( $_GET['instaimport'] ) ) : false;

		require_once( $this->plugin_path . 'lib/DsgnWrksInstagram_Embed.php' );
		$this->embed = new DsgnWrksInstagram_Embed( $this );
		require_once( $this->plugin_path . 'lib/DsgnWrksInstagram_Settings.php' );
		$this->settings = new DsgnWrksInstagram_Settings( $this );

		$this->hooks();
	}

	/**
	 * Holds the WordPress hooks
	 * @since  1.3.0
	 */
	protected function hooks() {
		// i18n
		load_plugin_textdomain( 'dsgnwrks', false, dirname( plugin_basename( __FILE__ ) ) );

		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'schedule_frequency' ) );
		add_action( 'wp_ajax_dsgnwrks_instagram_import', array( $this, 'ajax_import' ) );
		add_action( $this->pre .'cron', array( $this, 'cron_import' ) );
		add_action( 'init', array( $this->embed, 'hook_shortcode' ) );
		add_action( 'admin_menu', array( $this->settings, 'settings' ) );
		register_uninstall_hook( __FILE__, array( __CLASS__, 'uninstall' ) );
		register_deactivation_hook( __FILE__, array( __CLASS__, 'deactivate' ) );
		// Load the plugin settings link shortcut.
		add_filter( 'plugin_action_links_' . plugin_basename( plugin_dir_path( __FILE__ ) . 'dsgnwrks-instagram-importer.php' ), array( $this, 'settings_link' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'maybe_add_to_deleted_ids' ) );
		add_action( 'wp_ajax_dw_insta_blacklist', array( __CLASS__, 'ajax_remove_from_deleted_ids' ) );
		add_action( 'wp_ajax_dw_insta_blacklist_remove_many', array( __CLASS__, 'ajax_remove_many_from_deleted_ids' ) );
		add_action( 'current_screen', array( $this, 'redirects' ) );
		add_filter( 'wp_default_editor', array( $this, 'html_default' ) );
		add_action( 'all_admin_notices', array( $this, 'show_cron_notice' ) );
		// @DEV adds a minutely schedule for testing cron
		// add_filter( 'cron_schedules', function( $schedules ) {
		// 	$schedules['minutely'] = array(
		// 		'interval' => 60,
		// 		'display'  => 'Once Every Minute'
		// 	);
		// 	return $schedules;
		// } );
	}

	/**
	 * Get the party started
	 * @since  1.1.0
	 */
	public function register_settings() {

		// A pseudo setting. redirects to instagram oauth
		register_setting(
			$this->pre .'importer_users',
			'dsgnwrks_insta_registration',
			array( $this, 'instagram_oauth_redirect' )
		);
		// validate user options settings
		register_setting(
			$this->pre .'importer_settings',
			'dsgnwrks_insta_options',
			array( $this, 'settings_validate' )
		);

	}

	/**
	 * If a frequency has been set, schedule the import
	 * @since  1.3.0
	 */
	public function schedule_frequency() {

		$frequency = $this->get_option( 'frequency' );

		// if a auto-import frequency interval was saved,
		if ( $frequency && 'never' !== $frequency && ! wp_next_scheduled( $this->pre .'cron' ) ) {
			// schedule a cron to pull updates from instagram
			wp_schedule_event( time(), $frequency, $this->pre .'cron' );
		}
	}

	/**
	 * Add import function to cron
	 * @since  1.2.0
	 */
	public function cron_import() {
		foreach ( $this->get_options() as $user => $useropts ) {
			if ( isset( $useropts['auto_import'] ) && $useropts['auto_import'] == 'yes' ) {
				$this->import( $user );
			}
		}
	}

	/**
	 * Import via ajax
	 * @since  1.2.5
	 */
	public function ajax_import() {

		// instagram user id for pinging instagram
		if ( ! isset( $_REQUEST['instagram_user'] ) ) {
			wp_send_json_error( '<div id="message" class="error"><p>'. __( 'No Instagram username found.', 'dsgnwrks' ) .'</p></div>' );
		}

		// check user capability
		if ( ! current_user_can( 'publish_posts' ) ) {
			wp_send_json_error( '<div id="message" class="error"><p>'. __( 'Sorry, you do not have the right priveleges.', 'dsgnwrks' ) .'</p></div>' );
		}

		if ( isset( $_REQUEST['next_url'] ) && $saved_next_url = get_option( 'dsgnwrks_next_url' ) ) {
			$this->next_url = $saved_next_url;
		} elseif ( isset( $_REQUEST['next_url'] ) && $_REQUEST['next_url'] ) {
			$this->next_url = $_REQUEST['next_url'];
		}

		$notices = $this->import( $_REQUEST['instagram_user'], ! empty( $_REQUEST['reimport'] ) );

		if ( ! $notices ) {
			wp_send_json_error();
		}

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

		$data = array(
			'messages' => false === stripos( $messages, __( 'No new Instagram shots to import', 'dsgnwrks' ) ) ? $messages : '',
			'next_url' => $next_url,
			'userid'   => $_REQUEST['instagram_user'],
			'reimport' => $this->reimport,
		);

		// send back the messages
		wp_send_json_success( $data );
	}

	/**
	 * Hooks to 'all_admin_notices' and displays auto-imported photo messages
	 * @since  1.2.0
	 */
	function show_cron_notice() {

		// check if we have any saved notices from our cron auto-import
		if ( ! ( $notices = get_option( 'dsgnwrks_imported_photo_notices' ) ) ) {
			return;
		}

		wp_enqueue_style( 'dsgnwrks-instagram-messages', $this->plugin_url . 'css/message-css.css', null, $this->plugin_version );
		// if so, loop through and display them
		echo '<div id="message" class="updated instagram-import-message">';
		foreach ( $notices as $userid => $notice ) {
			echo '<h3>'. $userid .' &mdash; '. __( 'imported:', 'dsgnwrks' ) .' '. $notice['time'] .'</h3><ol>';
			echo $notice['notice'];
			echo '</ol><div style="clear: both; padding-top: 10px;"></div>';
			echo '<hr/>';
		}
		echo '<br><a href="'. esc_url( add_query_arg( array() ) ) .'">'. __( 'Hide', 'dsgnwrks' ) .'</a></div>';
		// reset notices
		update_option( 'dsgnwrks_imported_photo_notices', '' );
	}

	/**
	 * A pseudo setting validation. Sends user on to instagram to be authenticated.
	 * @since  1.1.0
	 */
	public function instagram_oauth_redirect( $opts ) {
		$return = add_query_arg( array( 'page' => $this->settings_slug ), admin_url( '/tools.php' ) );
		$uri    = add_query_arg( 'return_uri', urlencode( $return ), 'http://dsgnwrks.pro/insta_oauth' );
		// Send them on with our redirect uri set.
		wp_redirect( $uri );
		exit;
	}

	/**
	 * Validate each of our user options with an appropriate filter
	 * @since  1.1.0
	 * @param  array  $opts   array of options to be saved
	 * @return array          sanitized options array
	 */
	public function settings_validate( $opts ) {
		require_once( $this->plugin_path . 'lib/DsgnWrksInstagram_Settings_Validation.php' );
		$validate = new DsgnWrksInstagram_Settings_Validation( $this, $opts );
		return $validate->clean();
	}

	/**
	 * Runs when plugin is deactivated.
	 * @since 1.2.9
	 */
	public static function deactivate() {
		$instagram = DsgnWrksInstagram::get_instance();

		if ( ! current_user_can( 'activate_plugins' ) || ! isset( $instagram->pre ) ) {
			return;
		}

		if ( $timestamp = wp_next_scheduled( $instagram->pre .'cron' ) ) {
			$frequency = $instagram->settings->get_option( 'frequency' );
			wp_unschedule_event( $timestamp, $frequency, $instagram->pre .'cron' );
		}
	}

	/**
	 * Runs when plugin is uninstalled. deletes users and options
	 * @since 1.2.5
	 */
	public static function uninstall() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		check_admin_referer( 'bulk-plugins' );

		// Important: Check if the file is the one
		// that was registered during the uninstall hook.
		if ( __FILE__ != WP_UNINSTALL_PLUGIN ) {
			return;
		}
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

		$setting_link = sprintf( '<a href="%s">%s</a>', $this->plugin_page, __( 'Settings', 'dsgnwrks' ) );
		array_unshift( $links, $setting_link );

		return $links;

	}

	/**
	 * Deletes all plugin options
	 * @since 1.2.5
	 */
	public static function delete_options() {
		$deleted = false;
		// delete options
		$deleted = delete_option( 'dsgnwrks_insta_options' ) ? $deleted : false;
		$deleted = delete_option( 'dsgnwrks_insta_users' ) ? $deleted : false;
		$deleted = delete_option( 'dsgnwrks-import-debug-sent' ) ? $deleted : false;

		return $deleted;
	}

	/**
	 * Do not publicize imported posts
	 * @since  1.1.0
	 * @param  string $userid   Instagram user id
	 * @param  bool   $reimport Whether to loop through all photos to import.
	 */
	public function import( $userid = false, $reimport = false ) {

		// If filtered to be true, do not bypass publicize
		if ( apply_filters( 'dsgnwrks_instagram_jetpack_publicize', false ) ) {
			return $this->_import( $userid, $reimport );
		}

		// Do not publicize these posts (Jetpack)
		add_filter( 'wpas_submit_post?', '__return_false' );

		$return = $this->_import( $userid, $reimport );

		remove_filter( 'wpas_submit_post?', '__return_false' );

		return $return;
	}

	/**
	 * Start the engine. begins our import and generates feedback messages
	 * @since  1.1.0
	 * @param  string $userid Instagram user id
	 * @param  bool   $reimport Whether to loop through all photos to import.
	 */
	public function _import( $userid = false, $reimport = false ) {

		// Only import if the correct flags have been set
		if ( ! $userid && ! $this->manual_import ) {
			return;
		}

		// instagram user id for pinging instagram
		$this->settings->userid     = $id = $userid ? $userid : $this->manual_import;
		// get our options for use in the import
		$user_opts        = $this->get_option( $id );
		$this->doing_ajax = isset( $_REQUEST['instagram_user'] );
		// if a $userid was passed in, & no ajax $_REQUEST data we know we're doing a cron scheduled event
		$this->doing_cron = $userid && ! $this->doing_ajax ? true : false;
		$this->reimport   = ! empty( $reimport );

		// We need an id and access token to keep going
		if ( ! ( isset( $user_opts['id'] ) && isset( $user_opts['access_token'] ) ) ) {
			return;
		}

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
		if ( ! $userid ) {
			if ( stripos( $notice, __( 'No new Instagram shots to import', 'dsgnwrks' ) ) === false ) {
				$message_class .= ' instagram-import-message';
			}

			echo '<div id="message" class="'. $message_class .'"><ol>'. $notice .'</ol></div>';
		}
		// otherwise...
		elseif ( false === stripos( $notice, __( 'No new Instagram shots to import', 'dsgnwrks' ) ) || $this->reimport ) {

			// if we're doing ajax, we'll send the messages back now
			if ( $this->doing_ajax ) {

				$notices = array( $userid => array( 'notice' => $notice, 'time' => $time ) );

				if ( !empty( $messages['next_url'] ) ) {
					$notices['next_url'] = $messages['next_url'];
					// set_transient( 'get_option', $messages['next_url'] );
				}

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
					$notices[ $userid ] = array( 'notice' => $notice, 'time' => $time );
				// if not, create a new one
				else
					$notices = array( $userid => array( 'notice' => $notice, 'time' => $time ) );

				// save our option
				update_option( 'dsgnwrks_imported_photo_notices', $notices );
			}
		}
		// Get older items
		// http://wordpress.org/support/topic/imports-timing-out?replies=7
		// else {
		// 	// set next url to continue importing
		// 	if ( ! empty( $messages['next_url'] ) ) {
		// 		set_transient( 'get_option', $messages['next_url'] );
		// 		$notices['next_url'] = $messages['next_url'];
		// 		return $notices;
		// 	}
		// }

		// Save the date/time to notify users of last import time
		set_transient( sanitize_title( urldecode( $this->settings->userid ) ) .'-instaimportdone', $time, 14400 );

	}

	/**
	 * Actually fires the import and returns the messages
	 * @param  boolean $loop whether to loop the instagram pages
	 * @return array         success/error messages
	 */
	public function do_import( $loop = false ) {

		if ( ( $remove = $this->get_option( 'remove_hashtags' ) ) && is_array( $remove ) ) {
			foreach ( $remove as $filter => $value ) {
				if ( $value ) {
					add_filter( 'dsgnwrks_instagram_'.$filter, array( $this, 'remove_hashtags' ) );
				}
			}
		}

		// if a timezone string was saved
		if ( $tz_string = get_option( 'timezone_string' ) ) {
			// save our current date to a var
			$pre = date('e');
		 	// and tell php to use WP's timezone string
			date_default_timezone_set( get_option( 'timezone_string' ) );
		}


		$user = $this->get_option( $this->settings->userid );

		$this->api_url = isset( $this->next_url ) && $this->next_url ? $this->next_url : $this->instagram_api . $this->settings->user_option( 'id' ) .'/media/recent?access_token='. $this->settings->user_option( 'access_token' ) .'&count=2';

		// ok, let's access instagram's api
		$messages = $this->import_messages( $this->api_url );

		// if the api gave us a "next" url, let's loop through till we've hit all pages
		// while ( !empty( $messages['next_url'] ) && $loop ) {
		if ( ! empty( $messages['next_url'] ) ) {
			update_option( 'dsgnwrks_next_url', $messages['next_url'] );
		}
		// 	$messages = $this->import_messages( $messages['next_url'], $messages['message'] );
		// }

		// debug sent?
		$this->importDebugSet();

		// return php's timezone to its previously set value
		if ( $tz_string ) {
			date_default_timezone_set( $pre );
		}

		return $messages;

	}

	/**
	 * pings instagram with our user's feed url to retrieve photos
	 * @since  1.1.0
	 * @param  string $api_url      Instagram's api url
	 * @param  array  $prevmessages Previous messages from the api pagination loop
	 * @return array                messages array
	 */
	protected function import_messages( $api_url, $prevmessages = array() ) {

		// get instagram feed
		$response = wp_remote_get( $api_url, array( 'sslverify' => false ) );

		// if feed causes a wp_error object, send the error back
		if ( is_wp_error( $response ) ) {
			return $this->wp_error_message( $response );
		}

		// otherwise, let's get our api and format our data to be useable
		$data = json_decode( wp_remote_retrieve_body( $response ) );

		// wp_send_json_error( '<div id="message" class="error"><p><pre>$data'. htmlentities( print_r( $data, true ) ) .'</pre></p></div>' );

		if ( ! $this->importDebugCheck() ) {
			$this->debugsend( 'import_messages', $this->settings->userid .' - $data', array(
				'$api_url' => $api_url,
				'$this->settings->userid' => $this->settings->userid,
				'$response[headers]' => $response['headers'],
				'json_decode( wp_remote_retrieve_body( $response ) )' => $data,
				'$prevmessages' => $prevmessages,
				'$settings' => $this->get_option( $this->settings->userid )
			) );
		}

		// load WP files to use functions in them
		require_once( ABSPATH .'wp-admin/includes/file.php' );
		require_once( ABSPATH .'wp-admin/includes/media.php' );
		set_time_limit( 300 );

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
		$this->settings->all_opts[ $this->settings->userid ]['types'] = (array) apply_filters( 'dsgnwrks_instagram_import_types', array( 'video', 'image', 'carousel' ), $this->settings->userid );

		// if we have invalid data, bail here
		if ( !isset( $data->data ) || !is_array( $data->data ) )
			return array();

		// loop!
		foreach ( $data->data as $this->pic ) {

			if ( ! in_array( $this->pic->type, $this->settings->user_option( 'types', array() ) ) )
				continue;

			// if user has a date filter set, check it
			if ( $this->settings->user_option( 'date-filter' ) && $this->settings->user_option( 'date-filter' ) > $this->pic->created_time ) {
				// and stop if we've passed the date filter time
				$messages['nexturl'] = 'halt';
				break;
			}

			// If we have tags to filter, and image does not contain the right tags, move on
			if ( ! $this->has_tags( $this->pic->tags ) )
				continue;

			// if the photo is already saved, move on
			if ( $this->image_exists( $this->pic->created_time ) ) {
				continue;
			}

			if ( self::is_in_deleted_blacklist( $this->pic->id ) ) {
				continue;
			}

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

		$p = &$this->pic;

		// reset and init our $import settings array var
		$this->import = array();
		$import = &$this->import;

		// check for a location saved
		$this->loc = ( isset( $p->location->name ) ) ? $p->location->name : '';

		// Update post title
		$this->formatTitle();

		// save photo as featured?
		$import['featured']      = $this->settings->user_option( 'feat_image' );
		// save instagram photo caption as post excerpt
		$import['post_excerpt']  = !empty( $p->caption->text ) ? apply_filters( 'dsgnwrks_instagram_post_excerpt', $p->caption->text ) : '';

		$this->formatContent();

		// post author, default to current user
		$import['post_author']   = $this->settings->user_option( 'author', $user_ID );
		// post date, default to photo's created time
		$import['post_date']     = date( 'Y-m-d H:i:s', $p->created_time );
		$import['post_date_gmt'] = $import['post_date'];
		// post status, default to 'draft'
		$import['post_status']   = $this->settings->user_option( 'draft', 'draft' );
		// post type, default to 'post'
		$import['post_type']     = $this->settings->user_option( 'post-type', 'post' );

		// A filter so filter-savvy devs can modify the data before the post is created
		$import                  = apply_filters( 'dsgnwrks_instagram_pre_save', $import, $p, $this->get_option( $this->settings->userid ) );

		// and insert our new post
		$import['post_id']       = $this->insertPost();

		// if a wp_error object, send the error back
		if ( is_wp_error( $import['post_id'] ) )
			return $this->wp_error_message( $import['post_id'], false );

		// an action to fire after each post is created.
		do_action( 'dsgnwrks_instagram_post_save', $import['post_id'], $p, $import, $this->get_option( $this->settings->userid ) );

		// Save terms from settings
		$this->saveTerms();

		// save instagram api data as postmeta
		$this->savePostmeta();

		// our post is properly saved, now let's bring the image/videos over to WordPress

		$this->type = 'image';
		// sideload image
		$message = $this->upload_media( array( $p->images->thumbnail->url, $p->images->standard_resolution->url ), $p->created_time, '', '', true );
		

		// sideload videos
		if ( $this->pic->type == 'video' ) {
			$this->type = 'video';
			// grab both video sizes and upload them
			foreach ( array( 'low_resolution', 'standard_resolution' ) as $size ) {
				$vid_size = (int) $p->videos->$size->width;
				$title = sprintf( __( '%s Video', 'dsgnwrks' ), $vid_size .'x'. $vid_size );
				$message .= $this->upload_media(
					$p->videos->$size->url,
					$title . ' ' . $p->created_time,
					$title,
					$size
				);
			}
		}
		
		// sideload content from carousels
		
		if ( $this->pic->type == 'carousel' ) {
			$this->type = 'carousel';
			$is_primary = false;
			// loop through the carousel and add all images as non-primary images
			foreach ( $p->carousel_media as $item ) {
				// upload images unless they were already uploaded 
				// (if the carousel starts with an image, it will have been captured above)
				if ($item->type == 'image' && $item->images->thumbnail->url !== $p->images->thumbnail->url) {
					$message .= $this->upload_media( array( $item->images->thumbnail->url, $item->images->standard_resolution->url ), $p->created_time, '', '', $is_primary );
				}
				if ($item->type == 'video') {
					// grab both video sizes and upload them
					foreach ( array( 'low_resolution', 'standard_resolution' ) as $size ) {
						$vid_size = (int) $item->videos->$size->width;
						$title = sprintf( __( '%s Video', 'dsgnwrks' ), $vid_size .'x'. $vid_size );
						$message .= $this->upload_media(
							$item->videos->$size->url,
							$title . ' ' . $item->created_time,
							$title,
							$size,
							$is_primary
						);
					}
				}
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
		if ( ! $this->settings->user_option( 'tag-filter' ) ) {
			return false;
		}

		// get all tags saved for filtering
		$tags = explode( ',', $this->settings->user_option( 'tag-filter' ) );
		$this->settings_tags = array();
		// if we have tags...
		if ( $tags ) {
			// loop through them
			foreach ( $tags as $tag ) {
				// Remove hash symbol
				$this->settings_tags[] = str_replace( '#', '', trim( $tag ) );
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
		$pt = $this->settings->user_option( 'post-type', 'post' );
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
		// get date and time format options (used for title fallback)
		$date_format = get_option( 'date_format' );
		$time_format = get_option( 'time_format' );

		// format insta-pic-created time (per user prefrence as title) as a title fallback
		$date_fallback = date( $date_format .' ('. $time_format .')', $this->pic->created_time );

		// Check for a title, or use human readable date format
		$this->insta_title = ! empty( $this->pic->caption->text ) ? $this->pic->caption->text : $date_fallback;
		// Set post title to caption by default
		$this->import['post_title'] = $this->insta_title;

		// if our user's post-title option is saved
		if ( ! $this->settings->user_option( 'post-title' ) ) {
			return;
		}
		// check for insta-text conditionals
		$t = $this->conditional( 'insta-text', $this->settings->user_option( 'post-title' ), $this->import['post_title'] );
		// check for insta-location conditionals
		$t = $this->conditional( 'insta-location', $t, $this->loc );
		// Add the instagram filter name if requested
		$t = str_replace( '**insta-filter**', $this->pic->filter, $t );

		$this->import['post_title'] = apply_filters( 'dsgnwrks_instagram_post_title', $t, $this->pic );
	}

	/**
	 * Formats new post content
	 * @since 1.2.2
	 */
	protected function formatContent() {
		// if our user's post-content option is NOT saved
		if ( ! $this->settings->user_option( 'post_content' ) ) {

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
			$c = $this->settings->user_option( 'post_content' );
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
			$taxnames = esc_attr( $this->settings->user_option( $tax->name, '' ) );
			$terms = explode( ', ', $taxnames );
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

		if ( $this->settings->user_option( 'hashtags_as_tax' ) && $taxonomy_name == $this->settings->user_option( 'hashtags_as_tax' ) ) {
			return (array) $this->pic->tags;
		}

		return array();
	}

	/**
	 * Save instagram api data pieces to post_meta
	 * @since 1.2.2
	 */
	protected function savePostmeta() {
		$meta = apply_filters( 'dsgnwrks_instagram_post_meta_pre_save', array(
			'dsgnwrks_instagram_likes'    => $this->pic->likes,
			'dsgnwrks_instagram_comments' => $this->pic->comments,
			'dsgnwrks_instagram_hashtags' => $this->pic->tags,
			'instagram_created_time'      => $this->pic->created_time,
			'dsgnwrks_instagram_id'       => $this->pic->id,
			'instagram_filter_used'       => $this->pic->filter,
			'instagram_attribution'       => $this->pic->attribution,
			'instagram_location'          => $this->pic->location,
			'instagram_location_lat'      => isset( $this->pic->location->latitude ) ? $this->pic->location->latitude : '',
			'instagram_location_long'     => isset( $this->pic->location->longitude ) ? $this->pic->location->longitude : '',
			'instagram_location_name'     => isset( $this->pic->location->name ) ? $this->pic->location->name : '',
			'instagram_users_in_photo'    => $this->pic->users_in_photo,
			'instagram_link'              => esc_url_raw( $this->pic->link ),
			'instagram_embed_code'        => $this->embed->instagram_embed( array( 'src' => $this->pic->link, 'type' => $this->type ) ),
			'instagram_type'              => $this->pic->type,
			'instagram_user'              => $this->pic->user,
			'instagram_username'          => isset( $this->pic->user->username ) ? $this->pic->user->username : '',
		), $this->pic );

		foreach ( $meta as $key => $value ) {
			update_post_meta( $this->import['post_id'], $key, $value );
		}

	}

	/**
	 * Sideloads an image/video to the currrent WordPress post
	 * @since  1.1.0
	 * @param  string|array $media_url    URL(s) of media to be sideloaded
	 * @param  string       $filename     What to name the file. File extension will be added automatically.
	 * @param  string       $attach_title Optional title for uploaded media attachement
	 * @param  string       $size         Optional size of media
	 * @param  boolean      $is_primary   Is this the primary image or part of a carousel?
	 * @return string                     Error|Success message
	 */
	protected function upload_media( $media_url = '', $filename = '', $attach_title = '', $size = '', $is_primary = true ) {
		// get our import data
		$import = &$this->import;
		// image or video?
		$is_image = is_array( $media_url );
		// bail here if we don't have a media url
		if ( empty( $media_url ) ) {
			return $this->upload_error(__LINE__);
		}
		if ( $this->doing_cron ) {
			require_once( ABSPATH .'/wp-admin/includes/file.php' );
			require_once( ABSPATH .'/wp-admin/includes/media.php' );
			require_once( ABSPATH .'/wp-admin/includes/image.php' );
		}
		$tmp = $this->download_media_url( $media_url );
		$media_url = is_array( $media_url ) ? $media_url[1] : $media_url;
		// check for file extensions
		$pattern = $is_image
			? '/[^\?]+\.(jpe?g|jpe|gif|png)\b/i'
			: '/[^\?]+\.(mp4|MP4)/';
		preg_match( $pattern, $media_url, $matches );
		$file_array['tmp_name'] = $tmp;
		$file_array['name']     = $filename
			? sanitize_title_with_dashes( $filename ) . '.' . $matches[1]
			: basename( $matches[0] );
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
			add_post_meta( $import['post_id'], 'instagram_video_id_'. $size, $attach_id );
			add_post_meta( $import['post_id'], 'instagram_video_url_'. $size, wp_get_attachment_url( $attach_id ) );
			return '<em> '. sprintf( __( '%s imported.', 'dsgnwrks' ), '<b>'. $attach_title .'</b>' ) .'</em>';
		} else {
			// Save our photo attachement ID as post-meta
			add_post_meta( $import['post_id'], 'instagram_image_id', $attach_id );
		}

		// Get image markup
		$img_element = $this->get_image_el( $attach_id );
		
		if ( ! $img_element ) {
			return $this->upload_error( __LINE__, $media_url );
		}
		
		if ( $is_primary ) {
		
			if ( $import['featured'] ) {
				set_post_thumbnail( $import['post_id'], $attach_id );
			}
		
			// Replace URLs in post with uploaded image
			
			/**
			 * Filters the image element.
			 *
			 * @param string|array $img_element The Image html markup.
			 * @param int          $attach_id   The attachment ID.
			 * @param int          $post_id     The attachment's parent ID.
			 */
			$this->insta_image = (string) apply_filters( 'dsgnwrks_instagram_insta_image', $img_element, $attach_id, $import['post_id'] );
			
			$excerpted_title = wp_trim_words( $import['post_title'], 22 );
			$edit_link       = get_edit_post_link( $import['post_id'] );
			$trash_link      = get_delete_post_link( $import['post_id'] );

			// return a success message
			return '<li id="imported-'. $import['post_id'] .'" title="'. esc_attr( sprintf( __( '&ldquo;%s&rdquo;imported and created successfully.', 'dsgnwrks' ), $import['post_title'] ) ) .'">' . dw_get_instagram_image( $import['post_id'], array( 50, 50 ) ) .'<strong><a target="_blank" href="'. esc_url( $edit_link ) .'">&ldquo;'. $excerpted_title .'&rdquo;</a></strong><a title="'. esc_attr__( 'Move to Trash' ) .'" href="'. esc_url( $trash_link ) .'" class="dashicons dashicons-trash"><span class="screen-reader-text">'. esc_html__( 'Move to Trash' ) .'</span></a></li>';
			
		} else {
			return $img_element;
		}
	}

	/**
	 * Attempts to fetch the largest resolution image available.
	 *
	 * Uses some hacks based on known FB/Instagram cdn URL structures.
	 *
	 * @since  1.3.2
	 *
	 * @param  string $instagram_urls The instagram media thumbnail and standard URLs.
	 *
	 * @return mixed                  Results of download_url
	 */
	public function download_media_url( $instagram_urls ) {
		if ( ! is_array( $instagram_urls ) ) {
			return download_url( $instagram_urls );
		}

		$tmp = false;
		list( $thumb_url, $instagram_url ) = $instagram_urls;

		// Attempt to get full-resolution, non-square images.. this is a hack as it's not in the API
		$parts = parse_url( $thumb_url );
		if ( isset( $parts['scheme'], $parts['host'], $parts['path'] ) ) {

			$full_url = trailingslashit( $parts['scheme'] . '://' . $parts['host'] );
			$parts = array_filter( explode( '/', $parts['path'] ) );
			$full_url .= trailingslashit( array_shift( $parts ) );
			$full_url .= array_pop( $parts );

			if ( $instagram_url != $full_url ) {
				$tmp = download_url( $full_url );
			}
		}

		if ( ! $tmp || is_wp_error( $tmp ) ) {
			// Attempt to get full-resolution square images
			$tmp = download_url( str_replace( '640x640', '1080x1080', $instagram_url ) );
		}

		if ( is_wp_error( $tmp ) ) {
			$tmp = download_url( $instagram_url );
		}

		return $tmp;
	}

	/**
	 * Retrieves the image markup
	 *
	 * @since  1.3.1
	 *
	 * @uses   wp_get_attachment_image
	 * @param  int  $attach_id Attachment ID
	 *
	 * @return string          Image html markup
	 */
	public function get_image_el( $attach_id ) {

		/**
		 * The WordPress image size of the **insta-image** image (used in wp_get_attachment_image).
		 *
		 * @param string|array $imgsize The default size for the imported **insta-image** images.
		 */
		$imgsize = apply_filters( 'dsgnwrks_instagram_image_size', 'full' );
		$imgsize = is_array( $imgsize ) || is_string( $imgsize ) ? $imgsize : 'full';

		add_filter( 'wp_get_attachment_image_attributes', array( $this, 'cb_store_img_src' ) );
		$img_element = wp_get_attachment_image( $attach_id, $imgsize, false, array(
			'class' => 'insta-image',
			'alt'   => esc_attr( get_the_title( $attach_id ) ),
		) );
		remove_filter( 'wp_get_attachment_image_attributes', array( $this, 'cb_store_img_src' ) );

		return $img_element;
	}

	/**
	 * Stores the image source attribute to a class property for future use
	 *
	 * @since  1.3.1
	 *
	 * @param  array  $attr Array of attributes for the image element
	 *
	 * @return array        Return same array (simply caching a property)
	 */
	public function cb_store_img_src( $attr ) {
		$this->img_src = $attr['src'];
		return $attr;
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
		elseif ( $this->embed->has_embed() )
			$replace = array( '', $media_url );
		else
			$replace = array( '<img src="'. $media_url .'"/>', $media_url );

		$import['post_content'] = str_replace( array(
			// Add the instagram image element if requested
			'**insta-image**',
			// Add the instagram image source if requested
			'**insta-image-link**',
		), $replace, $import['post_content'] );


		$excerpted_title = wp_trim_words( $import['post_title'], 22 );
		$edit_link       = get_edit_post_link( $import['post_id'] );
		$trash_link      = get_delete_post_link( $import['post_id'] );

		// return an image upload error message
		return '<li id="imported-'. $import['post_id'] .'" title="'. esc_attr( sprintf( __( '&ldquo;%s&rdquo;imported but encountered an image upload error.', 'dsgnwrks' ), $import['post_title'] ) ) .'"><strong><a target="_blank" href="'. esc_url( $edit_link ) .'">&ldquo;'. $excerpted_title .'&rdquo;</a></strong> <em class="warning">'. sprintf( __( 'created successfully but there was an error with the image upload. Line: %d', 'dsgnwrks' ), $line ) .'</em><a title="'. esc_attr__( 'Move to Trash' ) .'" href="'. esc_url( $trash_link ) .'" class="dashicons dashicons-trash"><span class="screen-reader-text">'. esc_html__( 'Move to Trash' ) .'</span></a></li>';
	}

	/**
	 * Replaces the embed tags with the appropriate type embed and updates post.
	 * @since  1.2.6
	 */
	protected function update_post_content() {

		$insta_img = ( $this->embed->has_embed( 'image' ) ? '' : $this->insta_image );
		$insta_img = ( $this->embed->has_embed( 'video' ) && $this->type == 'video' ? '' : $this->insta_image );

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
			( $this->img_src ? $this->img_src : '' ),
			$this->embed->instagram_embed_src(),
			'',
		), $this->import['post_content'] );

		// Update the post with updated image URLs or errors
		wp_update_post( array(
			'ID'           => $this->import['post_id'],
			'post_content' => $this->import['post_content'],
		) );
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
	 * Returns message in html wrap
	 * @since  1.2.6
	 * @param  string $message
	 * @return string          Message text wrapped in li markup
	 */
	protected function message_wrap( $message_text ) {
		return 0 !== strpos( $message_text, '<li' ) ? '<li>'. $message_text .'</li>' : $message_text;
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
		$pos1  = strpos( $content, $open );
		$pos2  = strpos( $content, $close );

		// if we have conditional markup
		if ( false !== $pos1 && false !== $pos2 ) {

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
		// Only handle updates when on our settings page.
		if ( ! is_admin() || ! isset( $_GET['page'] ) || $this->settings_slug !== $_GET['page'] ) {
			return;
		}

		// if we have an error or access token
		if ( isset( $_GET['error'] ) || isset( $_GET['access_token'] ) )  {

			$opts   = $this->get_options();
			$users  = get_option( 'dsgnwrks_insta_users' );
			$users  = ( ! empty( $users ) ) ? $users : array();
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
					$sanitized_user                           = sanitize_text_field( $_GET['username'] );
					$users[]                                  = $sanitized_user;
					$opts[ $sanitized_user ]['access_token']  = $_GET['access_token'];
					$opts[ $sanitized_user ]['id']            = isset( $_GET['id'] ) ? sanitize_text_field( $_GET['id'] ) : '';
					$opts[ $sanitized_user ]['full_username'] = $_GET['username'];

					foreach ( $this->defaults as $key => $default ) {
						$opts[ $sanitized_user ][ $key ] = $default;
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
			wp_redirect( add_query_arg( $notice, $this->plugin_page ), 307 );
			exit;
		}

		if ( ! isset( $_GET['delete-insta-user'] ) ) {
			return;
		}

		// if we're requesting to delete a user

		// delete the user
		$users = get_option( 'dsgnwrks_insta_users' );
		$user_to_delete = urldecode( $_GET['delete-insta-user'] );

		$delete = false;
		foreach ( $users as $key => $user ) {
			if ( $user == $user_to_delete ) {
				$delete = $key;
			}
		}

		if ( false !== $delete ) {
			unset( $users[ $delete ] );
			update_option( 'dsgnwrks_insta_users', $users );

			// delete the user's data
			$opts = $this->get_options();
			delete_transient( 'dw_instauser_'. $opts[ $user_to_delete ]['id'] );
			unset( $opts[ $user_to_delete ] );
			if ( isset( $opts['username'] ) && $opts['username'] == sanitize_title( $user_to_delete ) ) {
				unset( $opts['username'] );
			}
			update_option( 'dsgnwrks_insta_options', $opts );
		}

		// redirect to remove the query arg (to keep from repeat-deleting)
		wp_redirect( remove_query_arg( 'delete-insta-user' ), 307 );
		exit;
	}

	/**
	 * Sets maximum quality for WP image saving
	 * @since  1.1.0
	 * @param  int $arg WordPress image quality setting
	 * @return int      modified quality setting
	 */
	public function max_quality($arg) { return 100; }

	public static function ajax_remove_from_deleted_ids() {
		if ( empty( $_REQUEST['id'] ) || empty( $_REQUEST['nonce'] ) || ! wp_verify_nonce( $_REQUEST['nonce'], $_REQUEST['id'] ) ) {
			wp_send_json_error();
		}

		if ( self::remove_from_deleted_ids( sanitize_text_field( $_REQUEST['id'] ) ) ) {
			wp_send_json_success();
		}

		wp_send_json_error();
	}

	public static function ajax_remove_many_from_deleted_ids() {
		if ( empty( $_REQUEST['ids'] ) || ! is_array( $_REQUEST['ids'] ) ) {
			wp_send_json_error();
		}

		$deleted = self::get_deleted_ids();
		$ids = $_REQUEST['ids'];
		$not_removed = $removed =array();

		foreach ( $ids as $id => $nonce ) {
			if ( isset( $deleted[ $id ] ) && wp_verify_nonce( $nonce, $id ) ) {
				unset( $deleted[ $id ] );
				$removed[] = $id;
			} else {
				$not_removed[] = $id;
			}
		}

		$updated = update_option( 'dw_instagram_deleted_ids', $deleted, false );
		if ( $updated ) {
			wp_send_json_success( compact( 'removed', 'not_removed' ) );
		} else {
			wp_send_json_error( compact( 'removed', 'not_removed' ) );
		}
	}

	/**
	 * When deleteing a post, importer should not import them again, so store in blacklist.
	 */
	public static function maybe_add_to_deleted_ids( $post_id ) {
		$insta_id = get_post_meta( $post_id, 'dsgnwrks_instagram_id', 1 );
		if ( ! empty( $insta_id ) ) {
			self::add_to_deleted_ids( $insta_id, array(
				'title' => wp_trim_words( get_the_title( $post_id ), 8 ),
				'url'   => get_post_meta( $post_id, 'instagram_link', 1 ),
			) );
		}
	}

	public static function get_deleted_ids() {
		$deleted = get_option( 'dw_instagram_deleted_ids' );
		if ( ! is_array( $deleted ) ) {
			$deleted = array();
		}

		return $deleted;
	}

	public static function is_in_deleted_blacklist( $insta_id ) {
		$deleted = self::get_deleted_ids();
		return isset( $deleted[ $insta_id ] );
	}

	public static function add_to_deleted_ids( $insta_id, $args ) {
		$deleted = self::get_deleted_ids();
		$args = wp_parse_args( $args, array(
			'title' => $insta_id,
			'url'   => '',
			'time'  => time(),
		) );
		$deleted[ $insta_id ] = $args;
		return update_option( 'dw_instagram_deleted_ids', $deleted, false );
	}

	public static function remove_from_deleted_ids( $insta_id ) {
		$deleted = self::get_deleted_ids();
		if ( ! isset( $deleted[ $insta_id ] ) ) {
			return false;
		}
		unset( $deleted[ $insta_id ] );

		return update_option( 'dw_instagram_deleted_ids', $deleted, false );
	}

	public function getter( $property ) {
		if ( isset( $this->$property ) ) {
			return $this->$property;
		}
	}

	public function get_users() {
		if ( isset( $this->users ) ) {
			return $this->users;
		}
		$this->users = get_option( 'dsgnwrks_insta_users', array() );
		$this->users = empty( $this->users ) || ! is_array( $this->users ) ? array() : (array) $this->users;

		return $this->users;
	}

	/**
	 * Set wp_editor default to 'html' on our admin page
	 * @since  1.1.0
	 * @param  string $default WordPress editor instance default view
	 * @return string      		modified view
	 */
	public function html_default( $default ) {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return $default;
		}

		$screen = get_current_screen();

		if ( isset( $screen->id ) && 'tools_page_dsgnwrks-instagram-importer-settings' === $screen->id ) {
			$default = 'html';
		}

		return $default;
	}

	public function get_option( $key ) {
		return $this->settings->get_option( $key );
	}

	public function get_options() {
		return $this->settings->get_options();
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
DsgnWrksInstagram::get_instance();

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

class DsgnWrksInstagram_Debug {

	/**
	 * Checks if user has enabled Debug Mode
	 * Requires DsgnWrks Instagram Debug plugin.
	 * @since  1.2.1
	 * @return bool debug on or off
	 */
	protected function debugEnabled() {
		return 'on' === $this->get_option( 'debugmode' );
	}

	/**
	 * Sets option stating user just sent an import debug (only want to send once!)
	 * Requires DsgnWrks Instagram Debug plugin.
	 * @since  1.2.1
	 */
	protected function importDebugSet() {
		if ( ! $this->debugEnabled() ) {
			return true;
		}

		update_option( 'dsgnwrks-import-debug-sent', 'sent' );
	}

	/**
	 * Checks if user sent an import debug already (only want to send once!)
	 * Requires DsgnWrks Instagram Debug plugin.
	 * @since  1.2.1
	 * @return bool send debug
	 */
	protected function importDebugCheck() {
		if ( ! $this->debugEnabled() ) {
			return true;
		}

		return get_option( 'dsgnwrks-import-debug-sent' ) ? true : false;
	}

	/**
	 * Sends me a debug report if Debug Mode is enabled
	 * Requires DsgnWrks Instagram Debug plugin.
	 * @since  1.2.1
	 */
	public function debugsend( $line, $title = false, $data = false ) {
		if ( ! $this->debugEnabled() ) {
			return true;
		}

		// default $data is options and users
		$data  = ! $data ? print_r( array( 'opts' => $this->get_options(), 'users' => $this->get_users() ), true ) : print_r( $data, true );
		// default title
		$title = ! $title ? 'no $opts[ $id ] - $opts & $users' : esc_attr( $title );
		wp_mail( 'justin@dsgnwrks.pro', 'Instagram Debug - '. $title .' - line '. $line, $data );
	}
}

