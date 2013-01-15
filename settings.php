<?php
if ( !current_user_can( 'manage_options' ) )  {
	wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
}
add_thickbox();

$opts = get_option( 'dsgnwrks_insta_options' );
$users = get_option( 'dsgnwrks_insta_users' );
$users = ( !empty( $users ) ) ? $users : array();

$has_notice = get_transient( 'instagram_notification' );
$notice = '';
if ( isset( $_GET['notice'] ) && $_GET['notice'] == 'success' && $has_notice ){
	$notice = 'You\'ve successfully connected to Instagram!';
} elseif ( isset( $_GET['class'] ) && $_GET['class'] == 'error' && $has_notice ) {
	$notice = 'There was an authorization error. Try again?';
}

$class = isset( $_GET['class'] ) ? $_GET['class'] : 'updated';
$nogo = false;
$nofeed = ( $class == 'error' ) ? true : false;

if ( !empty( $users ) && is_array( $users ) ) {
	foreach ( $users as $key => $user ) {

		if ( isset( $opts[$user]['remove-date-filter'] ) && $opts[$user]['remove-date-filter'] == 'yes' ) {
			$opts[$user]['mm'] = '';
			$opts[$user]['dd'] = '';
			$opts[$user]['yy'] = '';
			$opts[$user]['date-filter'] = 0;
			$opts[$user]['remove-date-filter'] = '';
			update_option( 'dsgnwrks_insta_options', $opts );
		}

		if ( !empty( $opts[$user]['remove-tag-filter'] ) ) {
			$opts[$user]['tag-filter'] = '';
			$opts[$user]['remove-tag-filter'] = '';
			update_option( 'dsgnwrks_insta_options', $opts );
		}

		if ( !empty( $opts[$user]['tag-filter'] ) ) {
			$opts[$user]['remove-tag-filter'] = '';
		}

		$complete[$user] = ( !empty( $opts[$user]['mm'] ) && !empty( $opts[$user]['dd'] ) && !empty( $opts[$user]['yy'] ) ) ? true : false;
	}

} elseif ( empty( $users ) ) {
	$nogo = true;
}

?>

<div class="wrap">
	<div id="icon-tools" class="icon32"><br></div>
	<h2>DsgnWrks Instagram Importer Options</h2>
	<div id="screen-meta" style="display: block; ">
	<?php
	if ( !empty( $notice ) ) {
		echo '<div id="message" class="'.$class.'"><p>'.$notice.'</p></div>';
	}
	?>
	<div class="clear"></div>

		<div id="contextual-help-wrap" class="hidden" style="display: block; ">
			<div id="contextual-help-back"></div>
			<div id="contextual-help-columns">
				<div class="contextual-help-tabs">
					<?php if ( empty( $opts ) ) { ?>
						<h2>Get Started</h2>
					<?php } else { ?>
						<h2>Users</h2>
					<?php } ?>

					<ul>
						<?php
						if ( !empty( $users ) && is_array( $users ) ) {
							foreach ( $users as $key => $user ) {
								$id = str_replace( ' ', '', strtolower( $user ) );
								$class = ( !empty( $class ) || $nofeed == true ) ? '' : ' active';
								if ( isset( $opts['username'] ) ) {
									$class = ( $opts['username'] == $id ) ? ' active' : '';
								}

								?>
								<li class="tab-instagram-user<?php echo $class; ?>" id="tab-instagram-user-<?php echo $id; ?>">
									<a href="#instagram-user-<?php echo $id; ?>"><?php echo $opts[$id]['full_username']; ?></a>
								</li>
								<?php
							}
						} else {
							$user = 'Create User';
							$class = str_replace( ' ', '', strtolower( $user ) );
							?>
							<li class="tab-instagram-user active" id="tab-instagram-user-<?php echo $class; ?>">
								<a href="#instagram-user-<?php echo $class; ?>"><?php echo $user; ?></a>
							</li>
							<?php
						}

						if ( !$nogo ) { ?>
							<li id="tab-add-another-user" <?php echo ( $nofeed == true ) ? 'class="active"' : ''; ?>>
								<a href="#add-another-user">Add Another User</a>
							</li>
						<?php } ?>
					</ul>
				</div>

				<div class="contextual-help-tabs-wrap">

				<?php
				if ( !empty( $users ) && is_array( $users ) ) {
					?>
					<form class="instagram-importer user-options" method="post" action="options.php">
					<?php settings_fields('dsgnwrks_instagram_importer_settings');

					foreach ( $users as $key => $user ) {
						$id = str_replace( ' ', '', strtolower( $user ) );
						$active = ( !empty( $active ) || $nofeed == true ) ? '' : ' active';
						if ( isset( $opts['username'] ) ) {
							$active = ( $opts['username'] == $id ) ? ' active' : '';
						}
						$o = &$opts[$id];
						?>
						<div id="instagram-user-<?php echo $id; ?>" class="help-tab-content<?php echo $active; ?>">

							<?php
							if ( isset( $_GET['settings-updated'] ) && $_GET['settings-updated'] == 'true' ) {
								if ( !empty( $o['mm'] ) || !empty( $o['dd'] ) || !empty( $o['yy'] ) ) {
									if ( !$complete[$id] ) echo '<div id="message" class="error"><p>Please select full date.</p></div>';
								}
							}

							?>

							<table class="form-table">

								<tr valign="top" class="info">
								<th colspan="2">
									<p><img class="alignleft" src="<?php echo esc_url( $o['profile_picture'] ); ?>" width="66" height="66"/>Successfully connected to Instagram &mdash; <span><a id="delete-<?php echo $id; ?>" class="delete-instagram-user" href="<?php echo add_query_arg( array( 'page' => $this->plugin_id, 'delete-insta-user' => urlencode( $id ) ), admin_url( $GLOBALS['pagenow'] ) ); ?>">Delete User?</a></span></p>
									<p>Please select the import filter options below. If none of the options are selected, all photos for <strong id="full-username-<?php echo $id; ?>"><?php echo $o['full_username']; ?></strong> will be imported. <em>(This could take a long time if you have a lot of shots)</em></p>
								</th>
								</tr>

								<tr valign="top">
								<th scope="row"><strong>Filter import by hashtag:</strong><br/>Will only import instagram shots with these hashtags.<br/>Please separate tags with commas.</th>
								<?php $tag_filter = isset( $o['tag-filter'] ) ? $o['tag-filter'] : ''; ?>
								<td><input type="text" placeholder="e.g. keeper, fortheblog" name="dsgnwrks_insta_options[<?php echo $id; ?>][tag-filter]" value="<?php echo $tag_filter; ?>" />
								<?php
									if ( !empty( $o['tag-filter'] ) ) {
										echo '<p><label><input type="checkbox" name="dsgnwrks_insta_options['.$id.'][remove-tag-filter]" value="yes" /> <em> Remove filter</em></label></p>';
									}
								?>
								</td>
								</tr>

								<tr valign="top">
								<th scope="row"><strong>Import from this date:</strong><br/>Select a date to begin importing your photos.</th>

								<td class="curtime">


									<?php
									global $wp_locale;

									$date_filter = 0;
									if ( !empty( $o['mm'] ) || !empty( $o['dd'] ) || !empty( $o['yy'] ) ) {
										if ( $complete[$id] ) {
											$date = '<strong>'. $wp_locale->get_month( $o['mm'] ) .' '. $o['dd'] .', '. $o['yy'] .'</strong>';
												$o['remove-date-filter'] = 'false';
												$date_filter = strtotime( $o['mm'] .'/'. $o['dd'] .'/'. $o['yy'] );
										} else {
											$date = '<span class="warning">Please select full date</span>';
										}
									}
									else { $date = 'No date selected'; }
									$date = '<p style="padding-bottom: 2px; margin-bottom: 2px;" id="timestamp"> '. $date .'</p>';
									$date .= '<input type="hidden" name="dsgnwrks_insta_options['.$id.'][date-filter]" value="'. $date_filter .'" />';

									$month = '<select id="instagram-mm" name="dsgnwrks_insta_options['.$id.'][mm]">\n';
									$month .= '<option value="">Month</option>';
									for ( $i = 1; $i < 13; $i = $i +1 ) {
										$monthnum = zeroise($i, 2);
										$month .= "\t\t\t" . '<option value="' . $monthnum . '"';
										if ( isset( $o['mm'] ) && $i == $o['mm'] )
											$month .= ' selected="selected"';
										$month .= '>' . $monthnum . '-' . $wp_locale->get_month_abbrev( $wp_locale->get_month( $i ) ) . "</option>\n";
									}
									$month .= '</select>';

									$day = '<select style="width: 5em;" id="instagram-dd" name="dsgnwrks_insta_options['.$id.'][dd]">\n';
									$day .= '<option value="">Day</option>';
									for ( $i = 1; $i < 32; $i = $i +1 ) {
										$daynum = zeroise($i, 2);
										$day .= "\t\t\t" . '<option value="' . $daynum . '"';
										if ( isset( $o['dd'] ) && $i == $o['dd'] )
											$day .= ' selected="selected"';
										$day .= '>' . $daynum;
									}
									$day .= '</select>';

									$year = '<select style="width: 5em;" id="instagram-yy" name="dsgnwrks_insta_options['.$id.'][yy]">\n';
									$year .= '<option value="">Year</option>';
									for ( $i = date( 'Y' ); $i >= 2010; $i = $i -1 ) {
										$yearnum = zeroise($i, 4);
										$year .= "\t\t\t" . '<option value="' . $yearnum . '"';
										if ( isset( $o['yy'] ) && $i == $o['yy'] )
											$year .= ' selected="selected"';
										$year .= '>' . $yearnum;
									}
									$year .= '</select>';


									echo '<div class="timestamp-wrap">';
									/* translators: 1: month input, 2: day input, 3: year input, 4: hour input, 5: minute input */
									printf(__('%1$s %2$s %3$s %4$s'), $date, $month, $day, $year );

									if ( $complete[$id] == true ) {
										echo '<p><label><input type="checkbox" name="dsgnwrks_insta_options['.$id.'][remove-date-filter]" value="yes" /> <em> Remove filter</em></label></p>';
									}
									?>

								</td>
								</tr>

								<tr valign="top" class="info">
								<th colspan="2">
									<p>Please select the post options for the imported instagram shots below.</em></p>
								</th>
								</tr>


								<!-- <tr valign="top">
								<th scope="row"><strong>Check for and auto-import posts:</strong></th>
								<td>
									<?php
									$cur_schedule = isset( $o['auto-import'] ) ? $o['auto-import'] : 'never';
									?>

									<select id="instagram-auto-import-<?php echo $id; ?>" name="dsgnwrks_insta_options[<?php echo $id; ?>][auto-import]">
										<option value="never" <?php selected( $cur_schedule, 'never' ); ?>>Never</option>
										<?php
										$schedules = wp_get_schedules();
										foreach ( $schedules  as $sched_key => $schedule ) {
											?>
											<option value="<?php echo $sched_key; ?>" <?php selected( $cur_schedule, $sched_key ); ?>><?php echo $schedule['display']; ?></option>
											<?php
										}
										?>
									</select>
								</td>
								</tr> -->

								<tr valign="top">
								<th scope="row"><strong>Save Instagram photo as post's featured image:</strong></th>
								<td>
									<input type="checkbox" name="dsgnwrks_insta_options[<?php echo $id; ?>][feat_image]" <?php checked( isset( $o['feat_image'] ) ); ?> value="yes"/>
								</td>
								</tr>

								<tr valign="top">
								<th scope="row">
									<strong>Post Title:</strong><br/>Add the imported Instagram data using these custom tags:<br/><code>**insta-text**</code>, <code>**insta-location**</code>, <code>**insta-filter**</code>
								</th>
								<?php $post_title = isset( $o['post-title'] ) ? $o['post-title'] : '**insta-text**'; ?>
								<td><input type="text" name="dsgnwrks_insta_options[<?php echo $id; ?>][post-title]" value="<?php echo $post_title; ?>" />
								</td>
								</tr>

								<tr valign="top">
								<td colspan="2">
									<p><strong>Post Content:</strong><br/>Add the imported Instagram data using these custom tags:<br/><code>**insta-text**</code>, <code>**insta-image**</code>, <code>**insta-image-link**</code>, <code>**insta-link**</code>, <code>**insta-location**</code>, <code>**insta-filter**</code></p>
									<p>Or use these conditional tags:<br/><code>[if-insta-text]Photo Caption: **insta-text**[/if-insta-text]</code><br/><code>[if-insta-location]Photo taken at: **insta-location**[/if-insta-location]</code></p>
									<?php
									$post_text = isset( $o['post_content'] ) ? $o['post_content'] : '';
									$args = array(
										'textarea_name' => 'dsgnwrks_insta_options['.$id.'][post_content]',
										'editor_class' => 'post_text',
										'textarea_rows' => 6,
										'wpautop' => false
									);
									wp_editor( $post_text, 'dsgnwrks_insta_options_'.$id.'_post_content', $args );
									?>

								</td>
								</tr>

								<tr valign="top">
								<th scope="row"><strong>Import to Post-Type:</strong></th>
								<td>
									<select class="instagram-post-type" id="instagram-post-type-<?php echo $id; ?>" name="dsgnwrks_insta_options[<?php echo $id; ?>][post-type]">
										<?php
										$args = array(
										  'public' => true,
										);
										$post_types = get_post_types( $args );
										$cur_post_type = isset( $o['post-type'] ) ? $o['post-type'] : '';
										foreach ($post_types  as $post_type ) {
											?>
											<option value="<?php echo $post_type; ?>" <?php selected( $cur_post_type, $post_type ); ?>><?php echo $post_type; ?></option>
											<?php
										}
										?>
									</select>
								</td>
								</tr>


								<tr valign="top">
								<th scope="row"><strong>Imported posts status:</strong></th>
								<td>
									<select id="instagram-draft-<?php echo $id; ?>" name="dsgnwrks_insta_options[<?php echo $id; ?>][draft]">
										<?php
										$draft_status = isset( $o['draft'] ) ? $o['draft'] : '';
										?>
										<option value="draft" <?php selected( $draft_status, 'draft' ); ?>>Draft</option>
										<option value="publish" <?php selected( $draft_status, 'publish' ); ?>>Published</option>
										<option value="pending" <?php selected( $draft_status, 'pending' ); ?>>Pending</option>
										<option value="private" <?php selected( $draft_status, 'private' ); ?>>Private</option>
									</select>

								</td>
								</tr>

								<tr valign="top">
								<th scope="row"><strong>Assign posts to an existing user:</strong></th>
								<td>
									<?php
									$author = isset( $o['author'] ) ? $o['author'] : '';
									wp_dropdown_users( array( 'name' => 'dsgnwrks_insta_options['.$id.'][author]', 'selected' => $author ) );
									?>

								</td>
								</tr>

								<?php
								if ( current_theme_supports( 'post-formats' ) && post_type_supports( 'post', 'post-formats' ) ) {
									$post_formats = get_theme_support( 'post-formats' );

									if ( is_array( $post_formats[0] ) ) {
										$o['post_format'] = !empty( $o['post_format'] ) ? esc_attr( $o['post_format'] ) : '';

										// Add in the current one if it isn't there yet, in case the current theme doesn't support it
										if ( $o['post_format'] && !in_array( $o['post_format'], $post_formats[0] ) )
											$post_formats[0][] = $o['post_format'];
										?>
										<tr valign="top" class="taxonomies-add">
										<th scope="row"><strong>Select Imported Posts Format:</strong></th>
										<td>

											<select id="dsgnwrks_insta_options[<?php echo $id; ?>][post_format]" name="dsgnwrks_insta_options[<?php echo $id; ?>][post_format]">
												<option value="0" <?php selected( $o['post_format'], '' ); ?>>Standard</option>
												<?php foreach ( $post_formats[0] as $format ) : ?>
												<option value="<?php echo esc_attr( $format ); ?>" <?php selected( $o['post_format'], $format ); ?>><?php echo esc_html( get_post_format_string( $format ) ); ?></option>

												<?php endforeach; ?><br />

											</select>

										</td>
										</tr>
										<?php
									}
								}

								$args = array(
									'public' => true,
									);
								$taxs = get_taxonomies( $args, 'objects' );

								foreach ( $taxs as $key => $tax ) {

									if ( $tax->label == 'Format' ) continue;

									$o[$tax->name] = !empty( $o[$tax->name] ) ? esc_attr( $o[$tax->name] ) : '';

									$placeholder = 'e.g. Instagram, Life, dog, etc';

									if ( $tax->name == 'post_tag' )  $placeholder = 'e.g. beach, sunrise';

									$tax_section_label = '<strong>'.$tax->label.' to apply to imported posts.</strong><br/>Please separate '.strtolower( $tax->label ).' with commas'."\n";
									$tax_section_input = '<input type="text" placeholder="'.$placeholder.'" name="dsgnwrks_insta_options['.$id.']['.$tax->name.']" value="'.$o[$tax->name].'" />'."\n";

									?>
									<tr valign="top" class="taxonomies-add taxonomy-<?php echo $tax->name; ?>">
									<th scope="row">
										<?php echo apply_filters( 'dsgnwrks_instagram_tax_section_label', $tax_section_label, $tax ); ?>
									</th>
									<td>
										<?php echo apply_filters( 'dsgnwrks_instagram_tax_section_input', $tax_section_input, $tax, $id, $opts ); ?>
									</td>
									</tr>
									<?php

								}

								?><tr valign="top">
										<th scope="row">
											<label for="dsgnwrks_insta_options[<?php echo $id; ?>][taxonomy_tag]"><strong>Taxonomy for tags</strong></label>
										</th>
										<td>
											<?php 
											$taxonomies = get_object_taxonomies(isset( $o['post-type'] ) ? $o['post-type'] : 'post','objects');
											?>
											<select id="dsgnwrks_insta_options[<?php echo $id; ?>][taxonomy_tag]" name="dsgnwrks_insta_options[<?php echo $id; ?>][taxonomy_tag]">
												<option value="none">None</option>
											<?php foreach ($taxonomies as $key => $tax):
												
												if($tax->hierarchical != 0 || $key == 'post_format' )
													continue;
											?>
												<option value="<?php echo $key ?>" <?php selected($key, $o['taxonomy_tag']) ?>><?php echo $tax->labels->name ?></option>
											<?php endforeach ?>
											</select>
										</td>
									</tr>
									<tr valign="top">
										<th scope="row">
											<label><strong>Auto set tag:</strong><br/>Uses the tags from instagram</label>
										</th>
										<td>
											<input type="checkbox" name="dsgnwrks_insta_options[<?php echo $id; ?>][autotag]" <?php checked( isset( $o['autotag'] ) ); ?> value="yes"/>
										</td>
									</tr>
									<tr valign="top">
										<th scope="row">
											<label><strong>Auto set Geolocation</strong><br/></label>
										</th>
										<td>
											<input type="checkbox" name="dsgnwrks_insta_options[<?php echo $id; ?>][geolocation]" <?php checked( isset( $o['geolocation'] ) ); ?> value="yes"/>
										</td>
									</tr>
									<tr valign="top">
										<th scope="row">
											<label><strong>Disable SSL-Verification</strong><br/>Only for testing purposes</label>
										</th>
										<td>
											<input type="checkbox" name="dsgnwrks_insta_options[<?php echo $id; ?>][ssl_verify_off]" <?php checked( isset( $o['ssl_verify_off'] ) ); ?> value="yes"/>
										</td>
									</tr>
								<?php
								echo '<input type="hidden" name="dsgnwrks_insta_options[username]" value="replaceme" />';
								$userdata = array( 'access_token', 'bio', 'website', 'profile_picture', 'full_name', 'id', 'full_username' ) ;
								foreach ( $userdata as $data ) {
									echo '<input type="hidden" name="dsgnwrks_insta_options['.$id.']['.$data.']" value="'. $o[$data] .'" />';
								}
								$trans = get_transient( $id .'-instaimportdone' );

								if ( $trans ) { ?>
									<tr valign="top" class="info">
									<th colspan="2">
										<?php echo '<p>Last updated: '. $trans .'</p>'; ?>

									</th>
									</tr>
								<?php } ?>
							</table>
							<p class="save-warning warning user-<?php echo $id; ?>">You've changed settings. <strong>please "Save" them before importing.</strong></p>
							<p class="submit">
								<input type="submit" id="save-<?php echo sanitize_title( $id ); ?>" name="save" class="button-primary save" value="<?php _e( 'Save' ) ?>" />
								<?php
								$importlink = $this->instimport_link( $o['full_username'] );
								?>
								<a href="<?php echo $importlink; ?>" class="button-secondary import-button" id="import-<?php echo $id; ?>">Import</a>
							</p>
						</div>
						<?php
					}
					?>
					</form>
					<?php
				} else {
					$message = '<p>Welcome to the Instagram Importer! Click to be taken to Instagram\'s site to securely authorize this plugin for use with your account.</p>';
					$this->settings_user_form( $users, $message );
				}

				if ( !$nogo ) { ?>
					<div id="add-another-user" class="help-tab-content <?php echo ( $nofeed == true ) ? ' active' : ''; ?>">
						<?php $this->settings_user_form( $users ); ?>
					</div>
				<?php } ?>
				</div>

				<div class="contextual-help-sidebar">
					<p class="jtsocial"><a class="jtpaypal" href="http://j.ustin.co/rYL89n" target="_blank">Contribute<span></span></a>
						<a class="jttwitter" href="http://j.ustin.co/wUfBD3" target="_blank">Follow me on Twitter<span></span></a>
						<a class="jtemail" href="http://j.ustin.co/scbo43" target="blank">Contact Me<span></span></a>
					</p>
				</div>

			</div>
		</div>
	</div>
</div>