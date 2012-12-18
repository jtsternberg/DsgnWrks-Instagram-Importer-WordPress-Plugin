<?php
/*
Plugin Name: DsgnWrks Instagram Importer
Plugin URI: http://dsgnwrks.pro/plugins/dsgnwrks-instagram-importer
Description: Allows you to backup your instagram photos while allowing you to have a site to display your instagram archive. Allows you to import to custom post types and attach custom taxonomies.
Author URI: http://dsgnwrks.pro
Author: DsgnWrks
Donate link: http://dsgnwrks.pro/give/
Version: 1.0.2
*/

define( 'DSGNWRKSINSTA_ID', 'dsgnwrks-instagram-importer-settings');
define( 'DSGNWRKSINSTA_PAGE', add_query_arg( 'page', DSGNWRKSINSTA_ID, admin_url( '/tools.php' ) ) );

add_action('admin_init','dsgnwrks_instagram_init');
function dsgnwrks_instagram_init() {

	if ( isset( $_GET['instaimport'] ) ) {
		set_transient( sanitize_title( urldecode( $_GET['instaimport'] ) ) .'-instaimportdone', date_i18n( 'l F jS, Y @ h:i:s A', strtotime( current_time('mysql') ) ), 14400 );
	}

	// delete_option( 'dsgnwrks_insta_options' );
	register_setting(
		'dsgnwrks_instagram_importer_users',
		'dsgnwrks_insta_registration',
		'dsgnwrks_instagram_users_validate'
	);
	register_setting(
		'dsgnwrks_instagram_importer_settings',
		'dsgnwrks_insta_options',
		'dsgnwrks_instagram_settings_validate'
	);

}

function dsgnwrks_instagram_users_validate( $opts ) {

	$dsgnwrks = 'http://dsgnwrks.pro/insta_oauth/';
	$instagram = 'https://api.instagram.com/oauth/authorize/';
	$return = add_query_arg( array( 'page' => DSGNWRKSINSTA_ID ), admin_url('/tools.php') );

	$uri = add_query_arg( 'return_uri', urlencode( $return ), $dsgnwrks );

	wp_redirect( $uri, 307 );
	exit;

	// wp_die( '<pre>'. print_r( $uri, true ) .'</pre>' );
	return $opts;
}

function dsgnwrks_instagram_settings_validate( $opts ) {
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
				$opts[$user][$key] = dsgnwrks_filter( $opt, '', 'post' );
			} elseif ( $key === 'draft' ) {
				$opts[$user][$key] = dsgnwrks_filter( $opt, '', 'draft' );
			} elseif ( $key === 'yy' || $key === 'mm' || $key === 'dd' ) {
				if ( empty( $opts[$user]['mm'] ) && empty( $opts[$user]['dd'] ) && empty( $opts[$user]['yy'] ) || !empty( $opts[$user]['remove-date-filter'] ) ) {
					$opts[$user][$key] = '';
				}
				else {
					$opts[$user][$key] = dsgnwrks_filter( $opt, 'absint', '' );
				}
			} elseif ( $key === 'post_content' ) {
				$opts[$user][$key] = dsgnwrks_filter( $opt, 'wp_kses_post' );
			} elseif ( $key === 'feat_image' ) {
				$opts[$user][$key] = (bool)$opts[$user][$key];
			} else {
				$opts[$user][$key] = dsgnwrks_filter( $opt );
			}

		} endif;
	} endif;
	return $opts;
}

add_action('admin_menu', 'dsgnwrks_instagram_settings');
function dsgnwrks_instagram_settings() {

	$plugin_page = add_submenu_page( 'tools.php', 'DsgnWrks Instagram Import Settings', 'Instagram Importer', 'manage_options', DSGNWRKSINSTA_ID, 'dsgnwrks_instagram_importer_settings' );
	add_action('admin_print_styles-' . $plugin_page, 'dsgnwrks_instagram_importer_styles');
	add_action('admin_print_scripts-' . $plugin_page, 'dsgnwrks_instagram_importer_scripts');
	add_action( 'admin_head-'. $plugin_page, 'dsgnwrks_instagram_fire_importer' );
}

function dsgnwrks_instagram_importer_settings() { require_once('settings.php'); }

function dsgnwrks_instagram_importer_styles() {
	wp_enqueue_style( 'dsgnwrks-instagram-importer-admin', plugins_url( 'css/admin.css', __FILE__ ), false, '1.1' );
}

function dsgnwrks_instagram_importer_scripts() {
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

function dsgnwrks_instagram_fire_importer() {

	if ( isset( $_GET['instaimport'] ) ) {
		add_action('all_admin_notices','dsgnwrks_instagram_import');
	}
}

function dsgnwrks_instagram_import() {

	$opts = get_option( 'dsgnwrks_insta_options' );
	$id = sanitize_title( urldecode( $_GET['instaimport'] ) );

	if ( isset( $opts[$id]['id'] ) && isset( $opts[$id]['access_token'] ) ) {
		echo '<div id="message" class="updated">';

		$pre = date('e');
		date_default_timezone_set( get_option('timezone_string') );

		$messages = dsgnwrks_import_messages( 'https://api.instagram.com/v1/users/'. $opts[$id]['id'] .'/media/recent?access_token='. $opts[$id]['access_token'] .'&count=80', $opts[$id] );

		while ( !empty( $messages['next_url'] ) ) {
			$messages = dsgnwrks_import_messages( $messages['next_url'], $opts[$id], $messages['message'] );
		}

		foreach ( $messages['message'] as $key => $message ) {
			echo $message;
		}

		date_default_timezone_set( $pre );

		echo '</div>';

	}

}

function dsgnwrks_import_messages( $api_url, $settings, $prevmessages = array() ) {

	$api = wp_remote_retrieve_body( wp_remote_get( $api_url ) );
	$data = json_decode( $api );

	require_once(ABSPATH . 'wp-admin/includes/file.php');
	require_once(ABSPATH . 'wp-admin/includes/media.php');
	set_time_limit(300);

	add_filter( 'jpeg_quality', 'dsgnwrks_max_quality' );
	$messages = dsgnwrks_pic_loop( $data, $settings );

	$next_url = ( !isset( $data->pagination->next_url ) || isset( $messages['nexturl'] ) && $messages['nexturl'] == 'halt' ) ? '' : $data->pagination->next_url;

	$messages = ( isset( $messages['messages'] ) ) ? array_merge( $prevmessages, $messages['messages'] ) : $prevmessages;

	remove_filter( 'jpeg_quality', 'dsgnwrks_max_quality' );
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

function dsgnwrks_pic_loop( $data = array(), $settings = array() ) {

	foreach ($data->data as $pics) {

		if ( isset( $settings['date-filter'] ) && $settings['date-filter'] > $pics->created_time ) {
			$messages['nexturl'] = 'halt';
			break;
		}

		if ( !empty( $settings['tag-filter'] ) ) {
			$tags = explode( ', ', $settings['tag-filter'] );
			$in_title = false;
			if ( $tags ) {
				foreach ($tags as $tag) {
					if ( strpos( $pics->caption->text, $tag ) ) $in_title = true;
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
						'value' => $pics->created_time
					)
				)
			)
		);
		if ( $alreadyInSystem->have_posts() ) {
			continue;
		}

		$messages['messages'][] = jts_instagram_img( $pics, $settings );
	}
	return !empty( $messages ) ? $messages : array();
}

function jts_instagram_img( $pics, $settings = array(), $tags='' ) {

	global $user_ID;

	$settings = ( empty( $settings ) ) ? get_option( 'dsgnwrks_insta_options' ) : $settings;

	$loc = ( isset( $pics->location->name ) ) ? $pics->location->name : '';

	// if ( $loc ) $loc = ' at '. $loc;
	$insta_title = !empty( $pics->caption->text ) ? $pics->caption->text : 'Untitled';
	// if ( $tags ) {
	// 	$tags = '#'. $tags;
	// 	$title = str_replace( $tags, '', $title );
	// }
	// $title = ($title) ? $title : 'Untitled';

	if ( !empty( $settings['post-title'] ) ) {
		$import['post_title'] = $settings['post-title'];
		$import['post_title'] = str_replace( '**insta-text**', $insta_title, $import['post_title'] );
		$import['post_title'] = str_replace( '**insta-location**', $loc, $import['post_title'] );
		$import['post_title'] = str_replace( '**insta-filter**', $pics->filter, $import['post_title'] );
	} else {
		$import['post_title'] = $insta_title;
	}

	$imgurl = $pics->images->standard_resolution->url;
	$insta_url = esc_url( $pics->link );
	$import['featured'] = isset( $settings['feat_image'] ) ? $settings['feat_image'] : true;

	$import['post_excerpt'] = !empty( $pics->caption->text ) ? $pics->caption->text : '';
	// if ( $tags ) {
	// 	$tags = '#'. $tags;
	// 	$import['post_excerpt'] = str_replace( $tags, '', $import['post_excerpt'] );
	// }
	// $import['post_excerpt'] .= ' (Taken with Instagram'. $loc .')';

	// $content = '';
	// $image_setting = isset( $settings['image'] ) ? $settings['image'] : '';
	// if ( !empty( $image_setting ) && $image_setting == 'content' || $image_setting == 'both' )
	// 	$content .= '<a href="'. $imgurl .'" ><img src="'. $imgurl .'"/></a>';
	// $content .= '<p>'. $import['post_excerpt'] .'</p>';
	// $content .= '<p>Instagram filter used: '. $pics->filter .'</p>';
	// $content .= '<p><a href="'. esc_url( $pics->link ) .'" target="_blank">View in Instagram &rArr;</a></p>';

	if ( empty( $settings['post_content'] ) ) {
		$content  = '<p><a href="'. $imgurl .'" target="_blank"><img src="'. $imgurl .'"/></a></p>'."\n";
		$content .= '<p>'. $import['post_excerpt'] .' (Taken with Instagram at '. $loc .')</p>'."\n";
		$content .= '<p>Instagram filter used: '. $pics->filter .'</p>'."\n";
		$content .= '<p><a href="'. $insta_url .'" target="_blank">View in Instagram &rArr;</a></p>'."\n";
	} else {
		$content = $settings['post_content'];
		$content = str_replace( '**insta-text**', $import['post_excerpt'], $content );
		$content = str_replace( '**insta-link**', $insta_url, $content );
		$content = str_replace( '**insta-location**', $loc, $content );
		$content = str_replace( '**insta-filter**', $pics->filter, $content );
	}

	$import['post_author'] = isset( $settings['author'] ) ? $settings['author'] : $user_ID;
	$import['post_content'] = $content;
	$import['post_date'] = date( 'Y-m-d H:i:s', $pics->created_time );
	$import['post_date_gmt'] = $import['post_date'];
	$import['post_status'] = isset( $settings['draft'] ) ? $settings['draft'] : 'draft';
	$import['post_type'] = isset( $settings['post-type'] ) ? $settings['post-type'] : 'post';

	apply_filters( 'dsgnwrks_instagram_pre_save', $import, $pics, $settings );

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

	apply_filters( 'dsgnwrks_instagram_post_save', $new_post_id, $pics );

	// Attach comments, if any
	foreach ($pics->comments->data as $instagram_comment) {
		$comment = array(
			'comment_post_ID' => $new_post_id,
			'comment_author' => $instagram_comment->from->username,
			'comment_author_url' => 'http://instagram.com/'. $instagram_comment->from->username,
			'comment_content' => $instagram_comment->text,
			'comment_date' => date( 'Y-m-d H:i:s', $instagram_comment->created_time ),
			'comment_approved' => 1,
			'comment_type' => '',
		);
		wp_insert_comment( $comment );
	}

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

	$insta_data = array( 'count' => $pics->likes->count );
	if ( !empty( $pics->likes->data ) ) {
		foreach ( $pics->likes->data as $key => $user ) {
			$insta_data['data'][$key] = $user;
		}
	}

	update_post_meta( $new_post_id, 'dsgnwrks_instagram_likes', $insta_data );
	update_post_meta( $new_post_id, 'instagram_created_time', $pics->created_time );
	update_post_meta( $new_post_id, 'dsgnwrks_instagram_id', $pics->id );
	update_post_meta( $new_post_id, 'instagram_filter_used', $pics->filter );
	update_post_meta( $new_post_id, 'instagram_location', $pics->location );
	update_post_meta( $new_post_id, 'instagram_link', esc_url( $pics->link ) );

	return dsgnwrks_instagram_upload_img( $imgurl, $new_post_id, $import['post_title'], $import['featured'] );
}

function dsgnwrks_instagram_upload_img( $imgurl='', $post_id='', $title='', $featured = false ) {

	$content = get_post_field('post_content', $post_id);

	if ( !empty( $imgurl ) && $featured ) {
		$tmp = download_url( $imgurl );

		preg_match('/[^\?]+\.(jpg|JPG|jpe|JPE|jpeg|JPEG|gif|GIF|png|PNG)/', $imgurl, $matches);
		$file_array['name'] = basename( $matches[0] );
		$file_array['tmp_name'] = $tmp;

		if ( is_wp_error( $tmp ) ) {
			@unlink( $file_array['tmp_name'] );
			$file_array['tmp_name'] = '';
		}

		$img_id = media_handle_sideload( $file_array, $post_id, $title );

		if ( is_wp_error( $img_id ) ) {
			@unlink( $file_array['tmp_name'] );
			return $img_id;
		}

		set_post_thumbnail( $post_id, $img_id );

		// Uploaded image, replace URLs in post
		$uploaded_imgurl = wp_get_attachment_url( $img_id );
		$uploaded_thumburl = wp_get_attachment_thumb_url( $img_id );
		$content = str_replace( '**insta-image**', '<img src="'. $uploaded_thumburl .'"/>', $content );
		$content = str_replace( '**insta-image-link**', $uploaded_imgurl, $content );
	} else {
		// No uploaded image, just hotlink
		$content = str_replace( '**insta-image**', '<img src="'. $imgurl .'"/>', $content );
		$content = str_replace( '**insta-image-link**', $imgurl, $content );
	}

	// Update the post with image URLs
	$updated_post = array(
		'ID' => $post_id,
		'post_content' => $content,
	);
	wp_update_post( $updated_post );

	return '<p><strong><em>&ldquo;'. $title .'&rdquo; </em> imported and created successfully.</strong></p>';
}

add_action('current_screen','redirect_on_deleteuser');
function redirect_on_deleteuser() {

	if ( isset( $_GET['error'] ) || ( isset( $_GET['access_token'] ) ) )  {

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
				$opts['username'] = $sanitized_user;

				update_option( 'dsgnwrks_insta_users', $users );
				update_option( 'dsgnwrks_insta_options', $opts );
				delete_option( 'dsgnwrks_insta_registration' );
				// unset( $reg );
			}

		}
		set_transient( 'instagram_notification', true, 60 );
		wp_redirect( add_query_arg( $notice, DSGNWRKSINSTA_PAGE ), 307 );
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

function dsgnwrks_wp_trim_words( $text, $num_words = 55, $more = null ) {
	if ( null === $more )
		$more = __( '...' );
	$original_text = $text;
	$text = wp_strip_all_tags( $text );
	$words_array = preg_split( "/[\n\r\t ]+/", $text, $num_words + 1, PREG_SPLIT_NO_EMPTY );
	if ( count( $words_array ) > $num_words ) {
		array_pop( $words_array );
		$text = implode( ' ', $words_array );
		$text = $text . $more;
	} else {
		$text = implode( ' ', $words_array );
	}
	return apply_filters( 'wp_trim_words', $text, $num_words, $more, $original_text );
}

function dsgnwrks_filter( $opt = '', $filter = '', $else = '' ) {

	if ( empty( $opt ) ) return $else;

	if ( $filter == 'absint' ) return absint( $opt );
	if ( $filter == 'esc_textarea' ) return esc_textarea( $opt );
	if ( $filter == 'wp_kses_post' ) return wp_kses_post( $opt );
	else return esc_attr( $opt );
}

function dsgnwrks_max_quality($arg) {
	return (int) 100;
}