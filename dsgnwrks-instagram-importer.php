<?php
/*
Plugin Name: DsgnWrks Instagram Importer
Plugin URI: http://dsgnwrks.pro/plugins/dsgnwrks-instagram-importer
Description: Allows you to backup your instagram photos while allowing you to have a site to display your instagram archive. Allows you to import to custom post types and attach custom taxonomies.
Author URI: http://dsgnwrks.pro
Author: DsgnWrks
Donate link: http://dsgnwrks.pro/give/
Version: 1.1.4
*/

class DsgnWrksInstagram {

	protected $plugin_name = 'DsgnWrks Instagram Importer';
	protected $plugin_id = 'dsgnwrks-instagram-importer-settings';
	protected $pre = 'dsgnwrks_instagram_';
	protected $plugin_page;
	protected $defaults;
	protected $import = array();

	function __construct() {

		$this->defaults = array(
			'tag-filter' => false,
			'feat_image' => 'yes',
			'date-filter' => 0,
			'mm' => date( 'm', strtotime( '-1 month' ) ),
			'dd' => date( 'd', strtotime( '-1 month' ) ),
			'yy' => date( 'Y', strtotime( '-1 month' ) ),
			'post-title' => '**insta-text**',
			'post_content' => '<p><a href="**insta-link**" target="_blank">**insta-image**</a></p>'."\n".'<p>Instagram filter used: **insta-filter**</p>'."\n".'[if-insta-location]<p>Photo taken at: **insta-location**</p>[/if-insta-location]'."\n".'<p><a href="**insta-link**" target="_blank">View in Instagram &rArr;</a></p>',
			'post-type' => 'post',
			'draft' => 'draft',
		);

		add_action( 'admin_init', array( $this, 'init' ) );
		add_action( $this->pre.'cron', array( $this, 'cron_callback' ) );
		add_action( 'admin_menu', array( $this, 'settings' ) );
		add_action( 'before_delete_post', array( $this, 'save_id_on_delete' ), 10, 1 );
		add_action( 'current_screen', array( $this, 'redirects' ) );
		add_filter( 'wp_default_editor', array( $this, 'html_default' ) );
	}

	public function init() {

		$this->plugin_page = add_query_arg( 'page', $this->plugin_id, admin_url( '/tools.php' ) );

		if ( isset( $_GET['instaimport'] ) ) {
			set_transient( sanitize_title( urldecode( $_GET['instaimport'] ) ) .'-instaimportdone', date_i18n( 'l F jS, Y @ h:i:s A', strtotime( current_time('mysql') ) ), 14400 );
		}

		// delete_option( 'dsgnwrks_insta_options' );
		register_setting(
			$this->pre .'importer_users',
			'dsgnwrks_insta_registration',
			array( $this, 'users_validate' )
		);
		register_setting(
			$this->pre .'importer_settings',
			'dsgnwrks_insta_options',
			array( $this, 'settings_validate' )
		);

		// TODO
		// schedule an hourly cron to pull updates from instagram
		// if ( !wp_next_scheduled( $this->pre.'cron' ) ) {
		// 	wp_schedule_event( time(), 'hourly', $this->pre.'cron' );
		// }
	}

	// Add import function to cron
	public function cron_callback() {
		$opts = get_option( 'dsgnwrks_insta_options' );
		foreach ( $opts as $key => $opt ) {
			# code...
		}
	}

	public function users_validate( $opts ) {

		$dsgnwrks = 'http://dsgnwrks.pro/insta_oauth/';
		$instagram = 'https://api.instagram.com/oauth/authorize/';
		$return = add_query_arg( array( 'page' => $this->plugin_id ), admin_url('/tools.php') );

		$uri = add_query_arg( 'return_uri', urlencode( $return ), $dsgnwrks );

		wp_redirect( $uri, 307 );
		exit;

		// wp_die( '<pre>'. print_r( $uri, true ) .'</pre>' );
		return $opts;
	}

	public function settings_validate( $opts ) {
		if ( !empty( $opts ) && is_array( $opts ) ) : foreach ( $opts as $user => $useropts ) {
			if ( !empty( $useropts ) && is_array( $useropts ) ) : foreach ( $useropts as $key => $opt ) {
				if ( $key === 'date-filter' ) {
					if ( empty( $opts[$user]['mm'] ) && empty( $opts[$user]['dd'] ) && empty( $opts[$user]['yy'] ) || !empty( $opts[$user]['remove-date-filter'] ) ) {
						$opts[$user][$key] = 0;
					}
					else {
						$opts[$user][$key] = strtotime( $opts[$user]['mm'] .'/'. $opts[$user]['dd'] .'/'. $opts[$user]['yy'] );
					}
				} elseif ( $key === 'pw' ) {
					continue;
				} elseif ( $key === 'post-type' ) {
					$opts[$user][$key] = $this->filter( $opt, '', 'post' );
				} elseif ( $key === 'draft' ) {
					$opts[$user][$key] = $this->filter( $opt, '', 'draft' );
				} elseif ( $key === 'yy' || $key === 'mm' || $key === 'dd' ) {
					if ( empty( $opts[$user]['mm'] ) && empty( $opts[$user]['dd'] ) && empty( $opts[$user]['yy'] ) || !empty( $opts[$user]['remove-date-filter'] ) ) {
						$opts[$user][$key] = '';
					}
					else {
						$opts[$user][$key] = $this->filter( $opt, 'absint', '' );
					}
				} elseif ( $key === 'post_content' ) {
					$opts[$user][$key] = $this->filter( $opt, 'wp_kses_post' );
				} elseif ( $key === 'feat_image' ) {
					$opts[$user][$key] = $opts[$user][$key] == 'yes' ? 'yes' : false;
				} else {
					$opts[$user][$key] = $this->filter( $opt );
				}

			} endif;
		} endif;
		return $opts;
	}

	public function settings() {

		$plugin_page = add_submenu_page( 'tools.php', $this->plugin_name, 'Instagram Importer', 'manage_options', $this->plugin_id, array( $this, 'settings_page' ) );
		add_action( 'admin_print_styles-' . $plugin_page, array( $this, 'styles' ) );
		add_action( 'admin_print_scripts-' . $plugin_page, array( $this, 'scripts' ) );
		add_action( 'admin_head-'. $plugin_page, array( $this, 'fire_importer' ) );
	}

	public function settings_page() { require_once('settings.php'); }

	public function styles() {
		wp_enqueue_style( 'dsgnwrks-instagram-importer-admin', plugins_url( 'css/admin.css', __FILE__ ), false, '1.1' );
	}

	public function scripts() {
		wp_enqueue_script( 'dsgnwrks-instagram-importer-admin', plugins_url( 'js/admin.js', __FILE__ ), array( 'jquery' ) );

		$args = array(
		  'public'   => true,
		);
		$cpts = get_post_types( $args );
		foreach ($cpts as $key => $cpt) {
			$taxes = get_object_taxonomies( $cpt );
			if ( !empty( $taxes ) ) $data['cpts'][$cpt][] = $taxes;
		}
		if ( !empty( $data ) ) wp_localize_script( 'dsgnwrks-instagram-importer-admin', 'dwinstagram', $data );

	}

	public function fire_importer() {

		if ( isset( $_GET['instaimport'] ) ) {
			add_action( 'all_admin_notices', array( $this, 'import' ) );
		}
	}

	public function import() {

		$opts = get_option( 'dsgnwrks_insta_options' );
		$id = sanitize_title( urldecode( $_GET['instaimport'] ) );

		if ( isset( $opts[$id]['id'] ) && isset( $opts[$id]['access_token'] ) ) {
			echo '<div id="message" class="updated">';

			if ( $tz_string = get_option('timezone_string') ) {
				$pre = date('e');
				date_default_timezone_set( get_option('timezone_string') );
			}

			$messages = $this->import_messages( 'https://api.instagram.com/v1/users/'. $opts[$id]['id'] .'/media/recent?access_token='. $opts[$id]['access_token'] .'&count=80', $opts[$id] );

			while ( !empty( $messages['next_url'] ) ) {
				$messages = $this->import_messages( $messages['next_url'], $opts[$id], $messages['message'] );
			}

			foreach ( $messages['message'] as $key => $message ) {
				echo $message;
			}

			if ( $tz_string )
				date_default_timezone_set( $pre );

			echo '</div>';

		}

	}

	protected function import_messages( $api_url, $settings, $prevmessages = array() ) {

		$this->settings = $settings;
		
		$response = wp_remote_get( $api_url, array('sslverify' => !(bool)$settings['ssl_verify_off']) );
		
		if (is_wp_error($response)) {
			echo $response->get_error_message();
			exit;
		}
		
		$api = wp_remote_retrieve_body( $response );
		
		$data = json_decode( $api );

		require_once(ABSPATH . 'wp-admin/includes/file.php');
		require_once(ABSPATH . 'wp-admin/includes/media.php');
		set_time_limit(300);

		add_filter( 'wp_editor_set_quality', array( $this, 'max_quality' ) );
		add_filter( 'jpeg_quality', array( $this, 'max_quality' ) );
		$messages = $this->pic_loop( $data );

		$next_url = ( !isset( $data->pagination->next_url ) || isset( $messages['nexturl'] ) && $messages['nexturl'] == 'halt' ) ? '' : $data->pagination->next_url;

		$messages = ( isset( $messages['messages'] ) ) ? array_merge( $prevmessages, $messages['messages'] ) : $prevmessages;

		remove_filter( 'wp_editor_set_quality', array( $this, 'max_quality' ) );
		remove_filter( 'jpeg_quality', array( $this, 'max_quality' ) );
		if ( empty( $messages ) && empty( $prevmessages ) ) {
			return array(
				'message' => array( '<p>No new Instagram shots to import</p>' ),
				'next_url' => $next_url,
			);
		} else {
			return array(
				'message' => $messages,
				'next_url' => $next_url,
			);
		}
	}

	protected function pic_loop( $data = array() ) {

		$settings = &$this->settings;
		// avoid http://wordpress.org/support/topic/error-warning-invalid-argument-supplied-for-foreach
		if ( !isset( $data->data ) )
			return array();

		foreach ( $data->data as $this->pic ) {

			$pic = &$this->pic;

			if ( isset( $settings['date-filter'] ) && $settings['date-filter'] > $pic->created_time ) {
				$messages['nexturl'] = 'halt';
				break;
			}

			if ( !empty( $settings['tag-filter'] ) ) {
				$tags = explode( ', ', $settings['tag-filter'] );
				$in_title = false;
				if ( $tags ) {
					foreach ($tags as $tag) {
						if ( strpos( $pic->caption->text, $tag ) ) $in_title = true;
					}
				}

				if ( !$in_title ) continue;
			}

			$pt = isset( $settings['post-type'] ) ? $settings['post-type'] : 'post';
			$alreadyInSystem = new WP_Query(
				array(
					'post_type' => $pt,
					'meta_query' => array(
						array(
							'key' => 'instagram_created_time',
							'value' => $pic->created_time
						)
					)
				)
			);
			if ( $alreadyInSystem->have_posts() ) {
				continue;
			}

			$messages['messages'][] = $this->save_img_post();
		}
		return !empty( $messages ) ? $messages : array();
	}

	protected function save_img_post() {

		$settings = &$this->settings;
		$p = &$this->pic;
		$import = &$this->import;

		global $user_ID;

		$settings = ( empty( $settings ) ) ? get_option( 'dsgnwrks_insta_options' ) : $settings;

		$loc = ( isset( $p->location->name ) ) ? $p->location->name : '';
		$loc_longitude = ( isset( $p->location->longitude ) ) ? $p->location->longitude : '';
		$loc_latitude = ( isset( $p->location->latitude ) ) ? $p->location->latitude : '';
		

		$insta_title = !empty( $p->caption->text ) ? $p->caption->text : 'Untitled';
		$import['post_title'] = $insta_title;

		if ( !empty( $settings['post-title'] ) ) {
			$import['post_title'] = $this->conditional( 'insta-text', $settings['post-title'], $import['post_title'] );
			$import['post_title'] = $this->conditional( 'insta-location', $import['post_title'], $loc );
			$import['post_title'] = str_replace( '**insta-filter**', $p->filter, $import['post_title'] );
		}

		$imgurl = $p->images->standard_resolution->url;
		$insta_url = esc_url( $p->link );
		$import['featured'] = ( isset( $settings['feat_image'] ) && $settings['feat_image'] == true ) ? true : false;

		$import['post_excerpt'] = !empty( $p->caption->text ) ? $p->caption->text : '';

		if ( empty( $settings['post_content'] ) ) {
			$content  = '<p><a href="'. $imgurl .'" target="_blank"><img src="'. $imgurl .'"/></a></p>'."\n";
			$content .= '<p>'. $import['post_excerpt'];
			if ( !empty( $loc ) )
				$content .= ' (Taken with Instagram at '. $loc .')';
			$content .= '</p>'."\n";
			$content .= '<p>Instagram filter used: '. $p->filter .'</p>'."\n";
			$content .= '<p><a href="'. $insta_url .'" target="_blank">View in Instagram &rArr;</a></p>'."\n";
		} else {
			$content = $settings['post_content'];
			$content = str_replace( '**insta-link**', $insta_url, $content );
			$content = $this->conditional( 'insta-text', $content, $insta_title );
			$content = $this->conditional( 'insta-location', $content, $loc );
			$content = str_replace( '**insta-filter**', $p->filter, $content );
		}

		$import['post_author'] = isset( $settings['author'] ) ? $settings['author'] : $user_ID;
		$import['post_content'] = $content;
		$import['post_date'] = date( 'Y-m-d H:i:s', $p->created_time );
		$import['post_date_gmt'] = $import['post_date'];
		$import['post_status'] = isset( $settings['draft'] ) ? $settings['draft'] : 'draft';
		$import['post_type'] = isset( $settings['post-type'] ) ? $settings['post-type'] : 'post';

		apply_filters( 'dsgnwrks_instagram_pre_save', $import, $p, $settings );

		$post = array(
		  'post_author' => $import['post_author'],
		  'post_content' => $import['post_content'],
		  'post_date' => $import['post_date'],
		  'post_date_gmt' => $import['post_date_gmt'],
		  'post_excerpt' => $import['post_excerpt'],
		  'post_status' => $import['post_status'],
		  'post_title' => $import['post_title'],
		  'post_type' => $import['post_type'],
		);
		$new_post_id = wp_insert_post( $post, true );
		$import['post_id'] = $new_post_id;

		apply_filters( 'dsgnwrks_instagram_post_save', $new_post_id, $p );

		$args = array(
			'public' => true,
			);
		$taxs = get_taxonomies( $args, 'objects' );

		foreach ( $taxs as $tax ) {

			if ( $tax->label == 'Format' && !current_theme_supports( 'post-formats' ) ) continue;

			$settings[$tax->name] = !empty( $settings[$tax->name] ) ? esc_attr( $settings[$tax->name] ) : '';

			$taxonomies = explode( ', ', $settings[$tax->name] );

			if ( !empty( $taxonomies ) )
			wp_set_object_terms( $new_post_id, $taxonomies, $tax->name );

		}

		$insta_data = array( 'count' => $p->likes->count );
		if ( !empty( $p->likes->data ) ) {
			foreach ( $p->likes->data as $key => $user ) {
				$insta_data['data'][$key] = $user;
			}
		}

		update_post_meta( $new_post_id, 'dsgnwrks_instagram_likes', $insta_data );
		update_post_meta( $new_post_id, 'instagram_created_time', $p->created_time );
		update_post_meta( $new_post_id, 'dsgnwrks_instagram_id', $p->id );
		update_post_meta( $new_post_id, 'instagram_filter_used', $p->filter );
		update_post_meta( $new_post_id, 'instagram_location', $p->location );
		update_post_meta( $new_post_id, 'instagram_link', esc_url( $p->link ) );
		
		// Geolocation
		if (!empty($settings['geolocation'])) {
			update_post_meta( $new_post_id, 'geo_latitude', $loc_latitude );
			update_post_meta( $new_post_id, 'geo_longitude', $loc_longitude );
			update_post_meta( $new_post_id, 'geo_address', $loc );
			update_post_meta( $new_post_id, 'geo_public', true );
		}

		// Tags
		if (!empty($settings['autotag']) && $settings['taxonomy_tag'] !== 'none') {
			$tags = $p->tags;
			
			$tag_string = '';
	
			if (!empty($tags)) {
				$tag_string .= implode(', ', $tags);
			}
					
			if (!empty($tag_string) || $tag_string != ''){
				wp_set_post_terms($new_post_id, $tag_string, $settings['taxonomy_tag'], true);
			}
		}

		return $this->upload_img( $imgurl );
	}

	protected function upload_img( $imgurl = '' ) {

		$import = &$this->import;

		if ( empty( $imgurl ) )
			return $this->upload_error();

		$content = &$import['post_content'];

		$tmp = download_url( $imgurl );

		preg_match('/[^\?]+\.(jpg|JPG|jpe|JPE|jpeg|JPEG|gif|GIF|png|PNG)/', $imgurl, $matches);
		$file_array['name'] = basename( $matches[0] );
		$file_array['tmp_name'] = $tmp;

		if ( is_wp_error( $tmp ) ) {
			@unlink( $file_array['tmp_name'] );
			$file_array['tmp_name'] = '';
		}

		$img_id = media_handle_sideload( $file_array, $import['post_id'], $import['post_title'] );

		if ( is_wp_error( $img_id ) ) {
			@unlink( $file_array['tmp_name'] );
			return $this->upload_error( $imgurl );
		}

		if ( $import['featured'] )
			set_post_thumbnail( $import['post_id'], $img_id );

		// Replace URLs in post with uploaded image
		$thumburl = wp_get_attachment_url( $img_id );
		$imgurl = wp_get_attachment_thumb_url( $img_id );

		$content = str_replace( '**insta-image**', '<img src="'. $thumburl .'"/>', $content );
		$content = str_replace( '**insta-image-link**', $imgurl, $content );

		// Update the post with image URLs
		wp_update_post( array(
			'ID' => $import['post_id'],
			'post_content' => $content,
		) );

		return '<p><strong><em>&ldquo;'. $import['post_title'] .'&rdquo; </em> imported and created successfully.</strong></p>';
	}

	protected function upload_error( $imgurl = false ) {

		$import = &$this->import;

		if ( !$imgurl ) {
			$import['post_content'] = str_replace( '**insta-image**', 'image error', $import['post_content'] );
			$import['post_content'] = str_replace( '**insta-image-link**', 'image error', $import['post_content'] );
		} else {
			$content = str_replace( '**insta-image**', '<img src="'. $imgurl .'"/>', $content );
			$content = str_replace( '**insta-image-link**', $imgurl, $content );
		}

		wp_update_post( array(
			'ID' => $import['post_id'],
			'post_content' => $import['post_content'],
		) );

		return '<p><strong><em>&ldquo;'. $import['post_title'] .'&rdquo; </em> created successfully but there was an error with the image upload.</strong></p>';

	}

	protected function conditional( $tag, $content, $data ) {

		$open = '[if-'.$tag.']';
		$close = '[/if-'.$tag.']';
		$tag = '**'.$tag.'**';

		if ( ( $pos1 = strpos( $content, $open ) ) && ( $pos2 = strpos( $content, $close ) ) ) {

			if ( !empty( $data ) ) {
				$content = str_replace( $tag, $data, $content );
				$content = str_replace( $open, '', $content );
				$content = str_replace( $close, '', $content );
			} else {
				$length = ( $pos2 + strlen( $close ) ) - $pos1;
				$content = substr_replace( $content, '', $pos1, $length );
			}

		} else {
			$content = str_replace( $tag, $data, $content );
		}

		return $content;
	}

	public function redirects() {

		if ( isset( $_GET['error'] ) || isset( $_GET['access_token'] ) )  {

			$opts = get_option( 'dsgnwrks_insta_options' );

			$users = get_option( 'dsgnwrks_insta_users' );
			$users = ( !empty( $users ) ) ? $users : array();

			$notice = array(
				'notice' => false,
				'class' => 'updated',
			);

			if ( isset( $_GET['error'] ) || isset( $_GET['error_reason'] ) || isset( $_GET['error_description'] ) ) {

				$notice['class'] = 'error';

			} else {
				$notice['notice'] = 'success';


				if ( isset( $_GET['username'] ) && !in_array( $_GET['username'], $users ) ) {
					$sanitized_user = sanitize_title( $_GET['username'] );
					$users[] = $sanitized_user;
					$opts[$sanitized_user]['access_token'] = $_GET['access_token'];
					$opts[$sanitized_user]['bio'] = isset( $_GET['bio'] ) ? $_GET['bio'] : '';
					$opts[$sanitized_user]['website'] = isset( $_GET['website'] ) ? $_GET['website'] : '';
					$opts[$sanitized_user]['profile_picture'] = isset( $_GET['profile_picture'] ) ? $_GET['profile_picture'] : '';
					$opts[$sanitized_user]['full_name'] = isset( $_GET['full_name'] ) ? $_GET['full_name'] : '';
					$opts[$sanitized_user]['id'] = isset( $_GET['id'] ) ? $_GET['id'] : '';
					$opts[$sanitized_user]['full_username'] = $_GET['username'];

					foreach ( $this->defaults as $key => $default ) {
						$opts[$sanitized_user][$key] = $default;
					}

					$opts['username'] = $sanitized_user;

					update_option( 'dsgnwrks_insta_users', $users );
					update_option( 'dsgnwrks_insta_options', $opts );
					delete_option( 'dsgnwrks_insta_registration' );
					// unset( $reg );
				}

			}
			set_transient( 'instagram_notification', true, 60 );
			wp_redirect( add_query_arg( 'query_arg', 'updated', $this->plugin_page ), 307 );
			exit;
		}

		if ( isset( $_GET['delete-insta-user'] ) ) {
			$users = get_option( 'dsgnwrks_insta_users' );
			foreach ( $users as $key => $user ) {
				if ( $user == urldecode( $_GET['delete-insta-user'] ) ) $delete = $key;
			}
			unset( $users[$delete] );
			update_option( 'dsgnwrks_insta_users', $users );

			$opts = get_option( 'dsgnwrks_insta_options' );
			unset( $opts[urldecode( $_GET['delete-insta-user'] )] );
			if ( isset( $opts['username'] ) && $opts['username'] == sanitize_title( urldecode( $_GET['delete-insta-user'] ) ) )
			unset( $opts['username'] );
			update_option( 'dsgnwrks_insta_options', $opts );

			wp_redirect( remove_query_arg( 'delete-insta-user' ), 307 );
			exit;
		}
	}

	protected function filter( $opt = '', $filter = '', $else = '' ) {

		if ( empty( $opt ) ) return $else;

		if ( $filter == 'absint' ) return absint( $opt );
		if ( $filter == 'esc_textarea' ) return esc_textarea( $opt );
		if ( $filter == 'wp_kses_post' ) return wp_kses_post( $opt );
		else return esc_attr( $opt );
	}

	public function max_quality($arg) {
		return (int) 100;
	}

	public function save_id_on_delete( $post_id ) {
		get_post_meta( $post_id, 'instagram_created_time', true );

	}

	protected function settings_user_form( $users = array(), $message = '' ) {

		$message = $message ? $message : '<p>Click to be taken to Instagram\'s site to securely authorize this plugin for use with your account.</p><p><em>(If you have already authorized an account, You will first be logged out of Instagram.)</em></p>'; ?>
		<form class="instagram-importer user-authenticate" method="post" action="options.php">
			<?php
			settings_fields('dsgnwrks_instagram_importer_users');
			echo $message;
			$class = !empty( $users ) ? 'logout' : '';
			?>
			<p class="submit">
				<input type="submit" name="save" class="button-primary authenticate <?php echo $class; ?>" value="<?php _e( 'Secure Authentication with Instagram' ) ?>" />
			</p>
		</form>

		<?php
	}

	protected function instimport_link( $id ) {
		return add_query_arg( array( 'page' => $this->plugin_id, 'instaimport' => urlencode( $id ) ), admin_url( $GLOBALS['pagenow'] ) );
	}

	public function html_default( $default ) {
		if ( get_current_screen()->id == 'tools_page_dsgnwrks-instagram-importer-settings' )
			$default = 'html';
		return $default;
	}

}

new DsgnWrksInstagram;
