<?php
// Disable PMPro redirect to levels page when user tries to register if the setting is enabled, see https://www.paidmembershipspro.com/hook/pmpro_login_redirect/
function my_pmpro_login_redirect($redirect_to) {
    $disable_redirect = get_option('pmpro_disable_redirect');
    if ($disable_redirect) {
        return false;
    }
    return $redirect_to;
}
add_filter('pmpro_login_redirect', 'my_pmpro_login_redirect');

// Assign a default membership level to newly registered users, see https://www.paidmembershipspro.com/give-users-a-default-membership-level-at-registration/
function my_pmpro_default_registration_level($user_id) {
    $default_level_id = get_option('pmpro_default_membership_level');
    if ($default_level_id) {
        pmpro_changeMembershipLevel($default_level_id, $user_id);
    }
}
add_action('user_register', 'my_pmpro_default_registration_level');

// Set a Member Author’s Posts to Draft When Membership is Cancelled, see https://www.paidmembershipspro.com/set-member-authors-posts-draft-membership-cancelled/
function pmpro_set_posts_to_draft($level_id, $user_id) {
    $options = get_option('pmpro_snippet_manager_options');
    
    // Check if the feature is enabled in the settings
    if (isset($options['pmpro_set_posts_to_draft']) && $options['pmpro_set_posts_to_draft']) {
        $user_roles = get_userdata($user_id)->roles;
        
        // Check if the user is an author and is cancelling
        if (array_intersect(array('subscriber', 'contributor', 'author'), $user_roles) && ($level_id == 0 || $level_id == 1)) {
            // Get the user's posts
            $args = array('author' => $user_id, 'post_type' => 'post');
            $user_posts = get_posts($args);
            foreach ($user_posts as $user_post) {
                wp_update_post(array('ID' => $user_post->ID, 'post_status' => 'draft'));
            }
        }
    }
}
add_action('pmpro_after_change_membership_level', 'pmpro_set_posts_to_draft', 10, 2);

// Custom redirects based on membership levels
function my_pmpro_custom_redirects() {
    $redirect_rules = get_option('pmpro_redirect_rules');
    if ($redirect_rules) {
        $rules = is_array($redirect_rules) ? $redirect_rules : json_decode($redirect_rules, true);
        if (is_array($rules)) {
            foreach ($rules as $rule) {
                $level_ids = array_filter(array_map('trim', explode(',', $rule['level_ids'])));
                $restricted_urls = array_map('trim', explode("\n", $rule['restricted_urls']));
                $redirect_url = trim($rule['redirect_url']);

                foreach ($restricted_urls as $restricted_url) {
                    // Ensure the restricted URL and redirect URL are absolute
                    $restricted_url = home_url($restricted_url);
                    $redirect_url = home_url($redirect_url);

                    if (strpos($_SERVER['REQUEST_URI'], parse_url($restricted_url, PHP_URL_PATH)) !== false) {
                        // Allow administrators to bypass the redirect rules
                        if (current_user_can('administrator')) {
                            continue;
                        }

                        // If level_ids is empty, it means we want to capture users with no membership level
                        if (empty($level_ids) && !pmpro_hasMembershipLevel()) {
                            wp_safe_redirect($redirect_url);
                            exit;
                        }

                        // Redirect users who do not have any of the specified levels
                        if (!pmpro_hasMembershipLevel($level_ids)) {
                            wp_safe_redirect($redirect_url);
                            exit;
                        }
                    }
                }
            }
        }
    }
}
add_action('template_redirect', 'my_pmpro_custom_redirects', 20); // Set priority to 20 to ensure it runs after the SpacesEngine redirect

/**
 * Add a setting to the edit level settings to show or hide a membership level on the level select page. 
 * See https://www.paidmembershipspro.com/memberships-levels-page-order-hide-display-skip-mega-post/#h-option-2-add-a-setting-to-hide-levels-from-display-on-the-memberships-edit-level-admin
 * 
 */
 
//Save the pmpro_show_level_ID field
function pmpro_hide_level_from_levels_page_save( $level_id ) {
	if ( $level_id <= 0 ) {
		return;
	}
	$limit = $_REQUEST['pmpro_show_level'];
	update_option( 'pmpro_show_level_'.$level_id, $limit );
}
add_action( 'pmpro_save_membership_level','pmpro_hide_level_from_levels_page_save' );
 
//Display the setting for the pmpro_show_level_ID field on the Edit Membership Level page
function pmpro_hide_level_from_levels_page_settings() {
	?>
	<h3 class='topborder'><?php esc_html_e( 'Membership Level Visibility', 'pmpro' ); ?></h3>
	<table class='form-table'>
		<tbody>
			<tr>
				<th scope='row' valign='top'><label for='pmpro_show_level'><?php esc_html_e( 'Show Level', 'pmpro' );?>:</label></th>
				<td>
					<?php		
						if ( isset( $_REQUEST['edit'] ) ) {
							$edit = $_REQUEST['edit'];
							$pmpro_show_level = get_option( 'pmpro_show_level_' . $edit );
							if ( $pmpro_show_level === false ) {
								$pmpro_show_level = 1;
							}
						} else {
							$limit = '';
						}
					?>
					<select id='pmpro_show_level' name='pmpro_show_level'>
						<option value='1' <?php if ( $pmpro_show_level == 1 ) { ?>selected='selected'<?php } ?>><?php esc_html_e( 'Yes, show this level in the [pmpro_levels] display.', 'pmpro' );?></option>
 
						<option value='0' <?php if ( ! $pmpro_show_level ) { ?>selected='selected'<?php } ?>><?php _e( 'No, hide this level in the [pmpro_levels] display.', 'pmpro' );?></option>
					</select>
				</td>
			</tr>
		</tbody>
	</table>
	<?php 
}
add_action( 'pmpro_membership_level_after_other_settings', 'pmpro_hide_level_from_levels_page_settings' );

?>