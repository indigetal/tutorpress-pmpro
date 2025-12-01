<?php
/**
 * Admin Notices
 *
 * Handles informational notices displayed on PMPro membership level edit pages:
 * - Course association notice (shows which TutorPress course a level is linked to)
 * - Sale price notice (displays active/scheduled sale information)
 *
 * @package TutorPress_PMPro
 * @subpackage Admin
 * @since 1.0.0
 */

namespace TUTORPRESS_PMPRO\Admin;

use TUTOR\Input;

/**
 * Admin Notices class.
 *
 * Displays contextual informational notices on PMPro level edit pages
 * to help admins understand TutorPress-managed levels and pricing.
 *
 * @since 1.0.0
 */
class Admin_Notices {

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->register_hooks();
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_hooks() {
		// Conditional hooks (only when PMPro is installed)
		if ( tutor_utils()->has_pmpro( true ) ) {
			// Display notices on level edit page
			add_action( 'pmpro_membership_level_after_general_information', array( $this, 'display_course_association_notice' ), 10 );
			add_action( 'pmpro_membership_level_after_billing_details_settings', array( $this, 'display_sale_price_notice_on_level_edit' ), 10 );
		}
	}

	/**
	 * Display course or bundle association notice on PMPro level edit page.
	 *
	 * Shows a notice when editing a membership level that is managed by TutorPress
	 * for a specific course or bundle. Includes a link to edit the associated item.
	 *
	 * Displays after the "General Information" section.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function display_course_association_notice() {
		// Only run on level edit page
		if ( ! Input::has( 'edit' ) ) {
			return;
		}

		$level_id = intval( Input::sanitize_request_data( 'edit' ) );
		if ( $level_id <= 0 ) {
			return;
		}

		// Check if this level is associated with a TutorPress course or bundle
		$course_id = get_pmpro_membership_level_meta( $level_id, 'tutorpress_course_id', true );
		$bundle_id = get_pmpro_membership_level_meta( $level_id, 'tutorpress_bundle_id', true );

		if ( empty( $course_id ) && empty( $bundle_id ) ) {
			return; // Not a TutorPress-managed level
		}

		// Determine object type (course or bundle) and get appropriate data
		if ( ! empty( $bundle_id ) ) {
			$object_id = $bundle_id;
			$object_title = get_the_title( $bundle_id );
			$object_edit_url = admin_url( 'post.php?post=' . $bundle_id . '&action=edit' );
			$object_type = 'bundle';
			$heading_text = __( 'TutorPress Bundle Level', 'tutorpress-pmpro' );
		} else {
			$object_id = $course_id;
			$object_title = get_the_title( $course_id );
			$object_edit_url = admin_url( 'post.php?post=' . $course_id . '&action=edit' );
			$object_type = 'course';
			$heading_text = __( 'TutorPress Course Level', 'tutorpress-pmpro' );
		}
		
		?>
		<tr>
			<td colspan="2">
				<div class="pmpro_course_association_notice" style="background: #f7f7f7; border-left: 4px solid #72aee6; padding: 12px 15px; margin: 10px 0;">
					<p style="margin: 0; font-size: 13px; color: #333;">
						<span class="dashicons dashicons-welcome-learn-more" style="font-size: 16px; vertical-align: middle; color: #72aee6;"></span>
						<strong><?php echo esc_html( $heading_text ); ?></strong>
						<br>
						<span style="margin-left: 22px; color: #666;">
							<?php 
							printf(
								__( 'This membership level is managed by TutorPress for: %s', 'tutorpress-pmpro' ),
								'<a href="' . esc_url( $object_edit_url ) . '" target="_blank" style="text-decoration: none;">' . esc_html( $object_title ) . ' <span class="dashicons dashicons-external" style="font-size: 14px; text-decoration: none;"></span></a>'
							);
							?>
						</span>
					</p>
				</div>
			</td>
		</tr>
		<?php
	}

	/**
	 * Display sale price notice on PMPro level edit page.
	 *
	 * Shows in Billing Details section when a sale exists (active or scheduled),
	 * including sale price details and schedule.
	 *
	 * Features:
	 * - Displays regular price vs sale price
	 * - Shows sale schedule (active dates or open-ended)
	 * - Different styling for scheduled vs active sales
	 * - Links to associated course/bundle for editing
	 * - Hides if sale has expired
	 *
	 * Note: One-time bundles do NOT show this notice because:
	 * - For one-time bundles, the bundle price = initial_payment (not a sale price in PMPro)
	 * - The sale_price meta is for frontend display only (auto-calculated regular vs instructor-set price)
	 * Subscription bundles DO show this notice because:
	 * - Subscription bundles support full sale pricing logic (same as courses)
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public function display_sale_price_notice_on_level_edit() {
		// Only run on level edit page
		if ( ! Input::has( 'edit' ) ) {
			return;
		}

		$level_id = intval( Input::sanitize_request_data( 'edit' ) );
		if ( $level_id <= 0 ) {
			return;
		}

		// Check if this level is associated with a TutorPress course or bundle
		$course_id = get_pmpro_membership_level_meta( $level_id, 'tutorpress_course_id', true );
		$bundle_id = get_pmpro_membership_level_meta( $level_id, 'tutorpress_bundle_id', true );

		if ( empty( $course_id ) && empty( $bundle_id ) ) {
			return; // Not a TutorPress-managed level
		}

		// IMPORTANT: Bundle pricing differs by type
		// - One-time bundles: bundle_price = initial_payment (NOT a sale price in PMPro)
		//   → Skip sale notice (sale price meta is for frontend display only)
		// - Subscription bundles: Full sale pricing support (like courses)
		//   → Show sale notice (when applicable)
		if ( ! empty( $bundle_id ) ) {
			// For bundles, check if this is a one-time or subscription level
			// One-time levels: initial_payment > 0, billing_amount = 0 (no recurring payments)
			$level = pmpro_getLevel( $level_id );
			
			if ( $level && empty( $level->billing_amount ) ) {
				// One-time bundle level - skip sale notice (sale price meta is display-only)
				return;
			}
			// Subscription bundle level - continue to show sale notice (if configured)
		}

		// Determine object type for display purposes
		if ( ! empty( $bundle_id ) ) {
			$object_id = $bundle_id;
			$object_type = 'bundle';
		} else {
			$object_id = $course_id;
			$object_type = 'course';
		}

		// Check if sale price exists in meta
		$sale_price = get_pmpro_membership_level_meta( $level_id, 'tutorpress_sale_price', true );
		$regular_price_meta = get_pmpro_membership_level_meta( $level_id, 'tutorpress_regular_price', true );
		
		if ( empty( $sale_price ) || empty( $regular_price_meta ) || floatval( $regular_price_meta ) <= 0 ) {
			return; // No sale configured
		}
		
		$regular_price = floatval( $regular_price_meta );

		// Get sale schedule
		$sale_from = get_pmpro_membership_level_meta( $level_id, 'tutorpress_sale_price_from', true );
		$sale_to = get_pmpro_membership_level_meta( $level_id, 'tutorpress_sale_price_to', true );

		// Determine sale status (active, scheduled, or expired)
		$sale_status = 'active'; // Default for open-ended sales
		$schedule_text = '';
		
		if ( ! empty( $sale_from ) && ! empty( $sale_to ) ) {
			// Get current time in GMT (matching storage format)
			if ( class_exists( '\Tutor\Helpers\DateTimeHelper' ) ) {
				$now = \Tutor\Helpers\DateTimeHelper::now()->format( 'U' );
				$from_timestamp = \Tutor\Helpers\DateTimeHelper::create( $sale_from )->format( 'U' );
				$to_timestamp = \Tutor\Helpers\DateTimeHelper::create( $sale_to )->format( 'U' );
			} else {
				$now = current_time( 'timestamp', true );
				$from_timestamp = strtotime( $sale_from );
				$to_timestamp = strtotime( $sale_to );
			}

			// Determine status
			if ( $now < $from_timestamp ) {
				$sale_status = 'scheduled';
			} elseif ( $now > $to_timestamp ) {
				// Sale expired - don't show notice
				return;
			} else {
				$sale_status = 'active';
			}

			// Format dates for display in local timezone
			if ( class_exists( '\Tutor\Helpers\DateTimeHelper' ) && method_exists( '\Tutor\Helpers\DateTimeHelper', 'get_gmt_to_user_timezone_date' ) ) {
				$from_formatted = \Tutor\Helpers\DateTimeHelper::get_gmt_to_user_timezone_date( $sale_from, 'M j, Y g:i A' );
				$to_formatted = \Tutor\Helpers\DateTimeHelper::get_gmt_to_user_timezone_date( $sale_to, 'M j, Y g:i A' );
			} else {
				// Fallback: Display as-is
				$from_formatted = date( 'M j, Y g:i A', $from_timestamp );
				$to_formatted = date( 'M j, Y g:i A', $to_timestamp );
			}

			if ( $sale_status === 'scheduled' ) {
				$schedule_text = sprintf( 
					__( 'Scheduled: %s to %s', 'tutorpress-pmpro' ), 
					$from_formatted, 
					$to_formatted 
				);
			} else {
				$schedule_text = sprintf( 
					__( 'Active from %s to %s', 'tutorpress-pmpro' ), 
					$from_formatted, 
					$to_formatted 
				);
			}
		} else {
			$schedule_text = __( 'Open-ended (no expiration)', 'tutorpress-pmpro' );
		}

		$sale_price_formatted = function_exists( 'pmpro_formatPrice' ) ? pmpro_formatPrice( floatval( $sale_price ) ) : '$' . number_format( floatval( $sale_price ), 2 );
		$regular_price_formatted = function_exists( 'pmpro_formatPrice' ) ? pmpro_formatPrice( $regular_price ) : '$' . number_format( $regular_price, 2 );

		$object_title = get_the_title( $object_id );
		$object_edit_url = admin_url( 'post.php?post=' . $object_id . '&action=edit' );
		
		// Different styling for scheduled vs active sales
		$bg_color = ( $sale_status === 'scheduled' ) ? '#fff3cd' : '#d7f1ff';
		$border_color = ( $sale_status === 'scheduled' ) ? '#ffc107' : '#0073aa';
		$heading_color = ( $sale_status === 'scheduled' ) ? '#856404' : '#0073aa';
		$heading_text = ( $sale_status === 'scheduled' ) ? __( 'Scheduled Sale', 'tutorpress-pmpro' ) : __( 'Sale Price Active', 'tutorpress-pmpro' );
		$icon = ( $sale_status === 'scheduled' ) ? 'clock' : 'tag';
		
		?>
		<div class="pmpro_sale_price_notice" style="background: <?php echo esc_attr( $bg_color ); ?>; border-left: 4px solid <?php echo esc_attr( $border_color ); ?>; padding: 12px 15px; margin-top: 20px;">
			<h3 style="margin-top: 0; color: <?php echo esc_attr( $heading_color ); ?>;">
				<span class="dashicons dashicons-<?php echo esc_attr( $icon ); ?>" style="font-size: 20px; vertical-align: middle;"></span>
				<?php echo esc_html( $heading_text ); ?>
			</h3>
			<p style="margin: 8px 0;">
				<strong><?php esc_html_e( 'Regular Price:', 'tutorpress-pmpro' ); ?></strong> 
				<span style="text-decoration: line-through; opacity: 0.6;"><?php echo esc_html( $regular_price_formatted ); ?></span>
				&nbsp;&nbsp;
				<strong><?php esc_html_e( 'Sale Price:', 'tutorpress-pmpro' ); ?></strong> 
				<span style="color: <?php echo esc_attr( $heading_color ); ?>; font-weight: bold;"><?php echo esc_html( $sale_price_formatted ); ?></span>
			</p>
			<?php if ( $schedule_text ) : ?>
				<p style="margin: 8px 0;">
					<strong><?php esc_html_e( 'Schedule:', 'tutorpress-pmpro' ); ?></strong> 
					<?php echo esc_html( $schedule_text ); ?>
				</p>
			<?php endif; ?>
			<p style="margin: 8px 0; font-style: italic; color: #666;">
				<?php 
				$associated_label = ( 'bundle' === $object_type ) ? __( 'bundle', 'tutorpress-pmpro' ) : __( 'course', 'tutorpress-pmpro' );
				printf(
					__( 'This sale is managed in the associated %s: %s', 'tutorpress-pmpro' ),
					$associated_label,
					'<a href="' . esc_url( $object_edit_url ) . '" target="_blank">' . esc_html( $object_title ) . '</a>'
				);
				?>
			</p>
			<p style="margin: 8px 0 0 0; font-size: 12px; color: #666;">
				<strong><?php esc_html_e( 'Note:', 'tutorpress-pmpro' ); ?></strong>
				<?php 
				if ( $sale_status === 'scheduled' ) {
					esc_html_e( 'The sale price will automatically apply when the scheduled time begins. Until then, customers will be charged the regular price.', 'tutorpress-pmpro' );
				} else {
					esc_html_e( 'The Initial Payment field above shows the regular price. Customers will automatically be charged the sale price at checkout.', 'tutorpress-pmpro' );
				}
				?>
			</p>
		</div>
		<?php
	}
}

