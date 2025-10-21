<?php
/**
 * PM PRO content settings view
 *
 * @package TutorPro\Addons
 * @subpackage PmPro\Views
 * @author Indigetal WebCraft<support@indigetal.com>
 * @link https://indigetal.com
 * @since 1.3.5
 */

?>
<div id="tutorpress-pmpro-setting-wrapper" style="background: white; padding: 10px 20px;">
    <h3><?php esc_html_e( 'Tutor LMS Content Settings', 'tutorpress-pmpro' ); ?></h3>

	<?php
	/**
	 * Generate category for PM Pro
	 *
	 * @param array $cats cats.
	 * @param array $level_categories level categories.
	 *
	 * @return void
	 */
	function generate_categories_for_pmpro( $cats, $level_categories = array() ) {

		if ( ! tutor_utils()->count( $cats ) ) {
			return;
		}

		echo '<ul>';
		foreach ( $cats as $cat ) {
			$name = 'membershipcategory_' . $cat->term_id;
			if ( ! empty( $level_categories ) ) {
				$checked = checked( in_array( $cat->term_id, $level_categories ), true, false );
			} else {
				$checked = '';
			}

			//phpcs:ignore
			echo "<li class=membershipcategory>
						<label><input type=checkbox name='{$name}' value='yes' {$checked}/> {$cat->name}</label>";
				generate_categories_for_pmpro( $cat->children, $level_categories );
			echo '</li>';
		}
			echo '</ul>';
	}
	?>


	<input type="hidden" value="pmpro_settings" name="tutor_action"/>

	<table class="form-table">
		<tbody>
			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<!-- Admin: Full interactive form -->
				<tr class="membership_model">
					<th width="200"><label for="TUTORPRESS_PMPRO_membership_model_select"><?php esc_html_e( 'Membership Model', 'tutorpress-pmpro' ); ?>:</label></th>
					<td>
						<?php
						$membership_model = get_pmpro_membership_level_meta( $level->id, 'TUTORPRESS_PMPRO_membership_model', true );
						?>
						<select name="TUTORPRESS_PMPRO_membership_model" id="TUTORPRESS_PMPRO_membership_model_select" class="tutor_select2">
							<option value=""><?php esc_html_e( 'Select a membership model', 'tutorpress-pmpro' ); ?></option>
							<option value="full_website_membership" <?php selected( 'full_website_membership', $membership_model ); ?> ><?php esc_html_e( 'Full website membership', 'tutorpress-pmpro' ); ?></option>
							<option value="category_wise_membership" <?php selected( 'category_wise_membership', $membership_model ); ?>><?php esc_html_e( 'Category wise membership', 'tutorpress-pmpro' ); ?></option>
						</select>
					</td>
				</tr>

				<tr class="membership_categories membership_course_categories" style="display: <?php echo esc_attr( 'category_wise_membership' === $membership_model ? '' : 'none' ); ?>;">
					<th width="200"><label><?php esc_html_e( 'Course Categories', 'tutorpress-pmpro' ); ?>:</label></th>
					<td>
						<?php generate_categories_for_pmpro( tutor_utils()->get_course_categories(), $level_categories ); ?>
					</td>
				</tr>

				<tr class="">
					<th width="200"><label><?php esc_html_e( 'Add Recommend badge', 'tutorpress-pmpro' ); ?>:</label></th>
					<td>
						<label class="tutor-switch">
							<input type="checkbox"  value="1" name="TUTORPRESS_PMPRO_level_highlight" <?php echo $highlight ? 'checked="checked"' : ''; ?>/>
							<span class="slider round tutor-switch-blue"></span>
						</label>
					</td>
				</tr>
			<?php else : ?>
				<!-- Non-Admin: Read-only display -->
				<tr class="membership_model">
					<th width="200"><label><?php esc_html_e( 'Membership Model', 'tutorpress-pmpro' ); ?>:</label></th>
					<td>
						<?php
						$membership_model = get_pmpro_membership_level_meta( $level->id, 'TUTORPRESS_PMPRO_membership_model', true );
						if ( 'full_website_membership' === $membership_model ) {
							esc_html_e( 'Full website membership', 'tutorpress-pmpro' );
						} elseif ( 'category_wise_membership' === $membership_model ) {
							esc_html_e( 'Category wise membership', 'tutorpress-pmpro' );
						} else {
							esc_html_e( 'Not set', 'tutorpress-pmpro' );
						}
						?>
						<p style="margin: 5px 0 0 0; font-size: 12px; color: #666;">
							<?php esc_html_e( '(Only site administrators can change the membership model)', 'tutorpress-pmpro' ); ?>
						</p>
					</td>
				</tr>

				<?php if ( 'category_wise_membership' === $membership_model ) : ?>
					<tr class="membership_categories membership_course_categories">
						<th width="200"><label><?php esc_html_e( 'Course Categories', 'tutorpress-pmpro' ); ?>:</label></th>
						<td>
							<?php
							$assigned_cats = $wpdb->get_col(
								$wpdb->prepare(
									"SELECT c.category_id
									FROM {$wpdb->pmpro_memberships_categories} c
									WHERE c.membership_id = %d",
									$level->id
								)
							);
							if ( ! empty( $assigned_cats ) ) {
								$term_links = array();
								foreach ( $assigned_cats as $cat_id ) {
									$term = get_term( $cat_id, 'course-category' );
									if ( $term && ! is_wp_error( $term ) ) {
										$term_links[] = esc_html( $term->name );
									}
								}
								echo wp_kses_post( implode( ', ', $term_links ) );
							} else {
								esc_html_e( 'No categories assigned', 'tutorpress-pmpro' );
							}
							?>
							<p style="margin: 5px 0 0 0; font-size: 12px; color: #666;">
								<?php esc_html_e( '(Only site administrators can modify categories)', 'tutorpress-pmpro' ); ?>
							</p>
						</td>
					</tr>
				<?php endif; ?>

				<tr class="">
					<th width="200"><label><?php esc_html_e( 'Recommend badge', 'tutorpress-pmpro' ); ?>:</label></th>
					<td>
						<?php echo $highlight ? '✓ ' . esc_html__( 'Yes', 'tutorpress-pmpro' ) : '✗ ' . esc_html__( 'No', 'tutorpress-pmpro' ); ?>
						<p style="margin: 5px 0 0 0; font-size: 12px; color: #666;">
							<?php esc_html_e( '(Only site administrators can change this setting)', 'tutorpress-pmpro' ); ?>
						</p>
					</td>
				</tr>
			<?php endif; ?>
		</tbody>
	</table>
</div>
