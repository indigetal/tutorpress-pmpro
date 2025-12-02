<?php
/**
 * Earnings Debug Tool Template
 *
 * Displayed as a tab in Tutor LMS > Tools
 *
 * @package TutorPress_PMPro
 * @since 1.6.0
 */

use TUTORPRESS_PMPRO\Admin\Earnings_Debug_Page;
use TUTORPRESS_PMPRO\PMPro_Earnings_Handler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Get statistics.
$stats = Earnings_Debug_Page::get_earnings_stats();
$handler = PMPro_Earnings_Handler::get_instance();
?>

<div class="tutor-option-main-title">
	<h2><?php esc_html_e( 'Earnings Debug Tool', 'tutorpress-pmpro' ); ?></h2>
	<p class="desc">
		<?php esc_html_e( 'Debug and maintain revenue sharing earnings data for PMPro integration.', 'tutorpress-pmpro' ); ?>
	</p>
</div>

<div class="tutor-option-single-item" style="padding: 30px;">
	<!-- Statistics Summary -->
	<div class="tutor-mb-32">
		<h3 class="tutor-fs-5 tutor-fw-medium tutor-color-black tutor-mb-16">
			<?php esc_html_e( 'Current Statistics', 'tutorpress-pmpro' ); ?>
		</h3>
		<div class="tutor-d-flex tutor-gap-4">
			<div class="tutor-bg-gray-10 tutor-p-16 tutor-rounded-6" style="flex: 1;">
				<div class="tutor-fs-6 tutor-color-secondary tutor-mb-4">
					<?php esc_html_e( 'Total Earnings Records', 'tutorpress-pmpro' ); ?>
				</div>
				<div class="tutor-fs-3 tutor-fw-bold tutor-color-black">
					<?php echo esc_html( $stats['total'] ); ?>
				</div>
			</div>
			<div class="tutor-bg-gray-10 tutor-p-16 tutor-rounded-6" style="flex: 1;">
				<div class="tutor-fs-6 tutor-color-secondary tutor-mb-4">
					<?php esc_html_e( 'Valid Earnings', 'tutorpress-pmpro' ); ?>
				</div>
				<div class="tutor-fs-3 tutor-fw-bold tutor-color-success">
					<?php echo esc_html( $stats['valid'] ); ?>
				</div>
			</div>
			<div class="tutor-bg-gray-10 tutor-p-16 tutor-rounded-6" style="flex: 1;">
				<div class="tutor-fs-6 tutor-color-secondary tutor-mb-4">
					<?php esc_html_e( 'Orphaned Earnings', 'tutorpress-pmpro' ); ?>
				</div>
				<div class="tutor-fs-3 tutor-fw-bold <?php echo $stats['orphaned'] > 0 ? 'tutor-color-danger' : 'tutor-color-success'; ?>">
					<?php echo esc_html( $stats['orphaned'] ); ?>
				</div>
			</div>
		</div>
	</div>

	<!-- Alert container for AJAX responses -->
	<div id="tpp-cleanup-alert" style="display:none;" class="tutor-alert tutor-mb-24">
		<div class="tutor-alert-text">
			<span class="tutor-icon-circle-mark tutor-mr-12"></span>
			<span id="tpp-cleanup-message"></span>
		</div>
	</div>

	<!-- Cleanup Action -->
	<div class="tutor-mb-32">
		<h3 class="tutor-fs-5 tutor-fw-medium tutor-color-black tutor-mb-16">
			<?php esc_html_e( 'Cleanup Orphaned Earnings', 'tutorpress-pmpro' ); ?>
		</h3>

		<div class="tutor-bg-white tutor-p-24 tutor-rounded-6" style="border: 1px solid #e5e7eb;">
			<p class="tutor-fs-7 tutor-color-secondary tutor-mb-16">
				<?php esc_html_e( 'Orphaned earnings occur when PMPro orders are manually deleted from the database. This tool permanently removes earnings records that reference non-existent PMPro orders.', 'tutorpress-pmpro' ); ?>
			</p>

			<?php if ( $stats['orphaned'] > 0 ) : ?>
				<div class="tutor-alert tutor-warning tutor-mb-16">
					<div class="tutor-alert-text">
						<span class="tutor-icon-warning tutor-mr-12"></span>
						<span>
							<?php
							/* translators: %d: number of orphaned records */
							printf(
								esc_html__( 'Warning: This will permanently delete %d orphaned earning record(s). This action cannot be undone.', 'tutorpress-pmpro' ),
								$stats['orphaned']
							);
							?>
						</span>
					</div>
				</div>
				<button type="button" id="tpp-cleanup-btn" class="tutor-btn tutor-btn-primary" data-orphaned-count="<?php echo esc_attr( $stats['orphaned'] ); ?>">
					<span class="tutor-icon-times tutor-mr-8" style="margin-top: -2px;"></span>
					<?php esc_html_e( 'Cleanup Orphaned Earnings', 'tutorpress-pmpro' ); ?>
				</button>
			<?php else : ?>
				<div class="tutor-alert tutor-success tutor-mb-16">
					<div class="tutor-alert-text">
						<span class="tutor-icon-circle-mark tutor-mr-12"></span>
						<span>
							<?php esc_html_e( 'No orphaned earnings found. Database is clean!', 'tutorpress-pmpro' ); ?>
						</span>
					</div>
				</div>
			<?php endif; ?>
		</div>
	</div>

	<!-- AJAX Script -->
	<script>
	jQuery(document).ready(function($) {
		$('#tpp-cleanup-btn').on('click', function(e) {
			e.preventDefault();
			
			var btn = $(this);
			var orphanedCount = btn.data('orphaned-count');
			
			// Confirm action
			if (!confirm('<?php echo esc_js( __( 'Are you sure you want to delete orphaned earning records? This cannot be undone.', 'tutorpress-pmpro' ) ); ?>')) {
				return;
			}
			
			// Disable button and show loading
			btn.prop('disabled', true).html('<span class="tutor-icon-spinner"></span> <?php echo esc_js( __( 'Cleaning...', 'tutorpress-pmpro' ) ); ?>');
			
			// Send AJAX request
			$.ajax({
				url: ajaxurl,
				type: 'POST',
				data: {
					action: 'tpp_cleanup_orphaned_earnings',
					nonce: '<?php echo esc_js( wp_create_nonce( 'tpp_cleanup_orphaned' ) ); ?>'
				},
				success: function(response) {
					if (response.success) {
						// Show success message
						$('#tpp-cleanup-alert')
							.removeClass('tutor-warning tutor-danger')
							.addClass('tutor-success')
							.find('#tpp-cleanup-message')
							.html('<strong><?php esc_html_e( 'Cleanup Complete!', 'tutorpress-pmpro' ); ?></strong> ' + response.data.message);
						$('#tpp-cleanup-alert').show();
						
						// Reload page after 2 seconds to update stats
						setTimeout(function() {
							window.location.reload();
						}, 2000);
					} else {
						// Show error message
						$('#tpp-cleanup-alert')
							.removeClass('tutor-success tutor-warning')
							.addClass('tutor-danger')
							.find('#tpp-cleanup-message')
							.html('<strong><?php esc_html_e( 'Error:', 'tutorpress-pmpro' ); ?></strong> ' + response.data.message);
						$('#tpp-cleanup-alert').show();
						
						// Re-enable button
						btn.prop('disabled', false).html('<span class="tutor-icon-times tutor-mr-8"></span><?php esc_html_e( 'Cleanup Orphaned Earnings', 'tutorpress-pmpro' ); ?>');
					}
				},
				error: function() {
					// Show error message
					$('#tpp-cleanup-alert')
						.removeClass('tutor-success tutor-warning')
						.addClass('tutor-danger')
						.find('#tpp-cleanup-message')
						.html('<strong><?php esc_html_e( 'Error:', 'tutorpress-pmpro' ); ?></strong> <?php esc_html_e( 'An unexpected error occurred.', 'tutorpress-pmpro' ); ?>');
					$('#tpp-cleanup-alert').show();
					
					// Re-enable button
					btn.prop('disabled', false).html('<span class="tutor-icon-times tutor-mr-8"></span><?php esc_html_e( 'Cleanup Orphaned Earnings', 'tutorpress-pmpro' ); ?>');
				}
			});
		});
	});
	</script>

	<!-- User Withdrawal Summary Section -->
	<div class="tutor-mb-32">
		<h3 class="tutor-fs-5 tutor-fw-medium tutor-color-black tutor-mb-16">
			<?php esc_html_e( 'User Withdrawal Summary', 'tutorpress-pmpro' ); ?>
		</h3>

		<div class="tutor-bg-white tutor-p-24 tutor-rounded-6" style="border: 1px solid #e5e7eb; margin-bottom: 16px;">
			<p class="tutor-fs-7 tutor-color-secondary tutor-mb-16">
				<?php esc_html_e( 'Look up an instructor to view their withdrawal summary, earnings records, and withdrawal history.', 'tutorpress-pmpro' ); ?>
			</p>

			<!-- Instructor Lookup Form -->
			<div style="display: flex; gap: 8px; margin-bottom: 24px;">
				<input 
					type="number" 
					id="tpp_instructor_id_input"
					placeholder="<?php esc_attr_e( 'Enter Instructor User ID', 'tutorpress-pmpro' ); ?>"
					value="<?php echo esc_attr( isset( $_GET['tpp_instructor_id'] ) ? (int) $_GET['tpp_instructor_id'] : '' ); ?>"
					style="flex: 1; padding: 8px 12px; border: 1px solid #d4d4d8; border-radius: 4px;"
				>
				<button type="button" id="tpp_load_instructor_btn" class="tutor-btn tutor-btn-primary" style="white-space: nowrap;">
					<?php esc_html_e( 'Load Data', 'tutorpress-pmpro' ); ?>
				</button>
			</div>

			<!-- Script to handle lookup without page reload -->
			<script>
			jQuery(document).ready(function($) {
				$('#tpp_load_instructor_btn').on('click', function() {
					var instructorId = $('#tpp_instructor_id_input').val();
					
					if (!instructorId || instructorId <= 0) {
						alert('<?php esc_js( __( 'Please enter a valid Instructor User ID', 'tutorpress-pmpro' ) ); ?>');
						return;
					}
					
					// Update URL with the instructor ID parameter while staying on the page
					var currentUrl = window.location.href;
					var separator = currentUrl.indexOf('?') > -1 ? '&' : '?';
					
					// Remove existing tpp_instructor_id param if present, then add new one
					currentUrl = currentUrl.split('&tpp_instructor_id')[0].split('?tpp_instructor_id')[0];
					window.location.href = currentUrl + separator + 'tpp_instructor_id=' + instructorId;
				});
				
				// Allow Enter key to trigger lookup
				$('#tpp_instructor_id_input').on('keypress', function(e) {
					if (e.which == 13) {
						$('#tpp_load_instructor_btn').click();
						return false;
					}
				});
			});
			</script>

			<?php 
			if ( isset( $_GET['tpp_instructor_id'] ) && ! empty( $_GET['tpp_instructor_id'] ) ) {
				$instructor_id = (int) $_GET['tpp_instructor_id'];
				$user = get_userdata( $instructor_id );
				
				if ( $user ) {
					?>
					<!-- Instructor Info -->
					<div style="background: #f3f4f6; padding: 16px; border-radius: 4px; margin-bottom: 24px;">
						<p><strong><?php esc_html_e( 'Instructor:', 'tutorpress-pmpro' ); ?></strong> <?php echo esc_html( $user->display_name ); ?> (ID: <?php echo esc_html( $instructor_id ); ?>)</p>
						<p><strong><?php esc_html_e( 'Email:', 'tutorpress-pmpro' ); ?></strong> <?php echo esc_html( $user->user_email ); ?></p>
						<p><strong><?php esc_html_e( 'Roles:', 'tutorpress-pmpro' ); ?></strong> <?php echo esc_html( implode( ', ', $user->roles ) ); ?></p>
						<p><strong><?php esc_html_e( 'Blog ID:', 'tutorpress-pmpro' ); ?></strong> <?php echo get_current_blog_id(); ?></p>
					</div>

					<?php 
					// Get withdrawal summary from Tutor
					$summary = Earnings_Debug_Page::get_instructor_withdrawal_summary( $instructor_id );
					
					if ( $summary ) {
						?>
						<!-- Withdraw Summary (from Tutor) -->
						<h4 class="tutor-fs-6 tutor-fw-medium tutor-color-black tutor-mb-12" style="margin-top: 20px;">
							<?php esc_html_e( 'Withdrawal Summary (from Tutor)', 'tutorpress-pmpro' ); ?>
						</h4>
						<table class="wp-list-table widefat" style="margin-bottom: 24px;">
							<tr>
								<th style="width: 40%;"><strong><?php esc_html_e( 'Property', 'tutorpress-pmpro' ); ?></strong></th>
								<th><strong><?php esc_html_e( 'Value', 'tutorpress-pmpro' ); ?></strong></th>
							</tr>
							<?php foreach ( $summary as $key => $value ) : ?>
								<tr>
									<td><strong><?php echo esc_html( $key ); ?></strong></td>
									<td><?php echo is_numeric( $value ) ? '$' . number_format( (float) $value, 2 ) : esc_html( $value ); ?></td>
								</tr>
							<?php endforeach; ?>
						</table>
						<?php
					}
					?>

					<?php 
					// Get manual calculation
					$manual_calc = Earnings_Debug_Page::get_manual_withdrawal_calculation( $instructor_id );
					
					if ( $manual_calc ) {
						?>
						<!-- Manual Calculation -->
						<h4 class="tutor-fs-6 tutor-fw-medium tutor-color-black tutor-mb-12" style="margin-top: 20px;">
							<?php esc_html_e( 'Manual Calculation Breakdown', 'tutorpress-pmpro' ); ?>
						</h4>
						<table class="wp-list-table widefat" style="margin-bottom: 24px;">
							<tr>
								<th style="width: 40%;"><strong><?php esc_html_e( 'Property', 'tutorpress-pmpro' ); ?></strong></th>
								<th><strong><?php esc_html_e( 'Value', 'tutorpress-pmpro' ); ?></strong></th>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'Total Income (completed orders)', 'tutorpress-pmpro' ); ?></strong></td>
								<td>$<?php echo number_format( $manual_calc['total_income'], 2 ); ?></td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'Total Withdrawn (approved)', 'tutorpress-pmpro' ); ?></strong></td>
								<td>$<?php echo number_format( $manual_calc['total_withdrawn'], 2 ); ?></td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'Total Pending Withdrawals', 'tutorpress-pmpro' ); ?></strong></td>
								<td>$<?php echo number_format( $manual_calc['total_pending'], 2 ); ?></td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'Total Matured (≥', 'tutorpress-pmpro' ); ?> <?php echo esc_html( $manual_calc['maturity_days'] ); ?> <?php esc_html_e( 'days)', 'tutorpress-pmpro' ); ?></strong></td>
								<td>$<?php echo number_format( $manual_calc['total_matured'], 2 ); ?></td>
							</tr>
							<tr style="background: #f0fdf4;">
								<td><strong><?php esc_html_e( 'Current Balance', 'tutorpress-pmpro' ); ?></strong></td>
								<td><strong>$<?php echo number_format( $manual_calc['current_balance'], 2 ); ?></strong></td>
							</tr>
							<tr style="background: #f0fdf4;">
								<td><strong><?php esc_html_e( 'Available for Withdrawal', 'tutorpress-pmpro' ); ?></strong></td>
								<td><strong>$<?php echo number_format( $manual_calc['available_for_withdraw'], 2 ); ?></strong></td>
							</tr>
						</table>
						<?php
						if ( $manual_calc['current_balance'] < 0 ) {
							?>
							<div class="tutor-alert tutor-warning tutor-mb-24">
								<div class="tutor-alert-text">
									<span class="tutor-icon-warning tutor-mr-12"></span>
									<span><?php esc_html_e( 'Note: Negative balance indicates the instructor has withdrawn more than they earned (likely due to refunds/cancellations after withdrawal).', 'tutorpress-pmpro' ); ?></span>
								</div>
							</div>
							<?php
						}

						if ( $manual_calc['available_for_withdraw'] == 0 && $manual_calc['total_income'] > 0 ) {
							?>
							<div class="tutor-alert tutor-info tutor-mb-24">
								<div class="tutor-alert-text">
									<span class="tutor-icon-info tutor-mr-12"></span>
									<span><?php printf( esc_html__( 'Note: Earnings exist but are not yet mature (must wait %d days) or have already been withdrawn.', 'tutorpress-pmpro' ), $manual_calc['maturity_days'] ); ?></span>
								</div>
							</div>
							<?php
						}
					}
					?>

					<?php 
					// Get raw earnings records
					$earnings = Earnings_Debug_Page::get_instructor_earnings( $instructor_id );
					
					if ( ! empty( $earnings ) ) {
						?>
						<!-- Raw Earnings Records -->
						<h4 class="tutor-fs-6 tutor-fw-medium tutor-color-black tutor-mb-12" style="margin-top: 20px;">
							<?php esc_html_e( 'Earnings Records (Last 20)', 'tutorpress-pmpro' ); ?>
						</h4>
						<div style="overflow-x: auto; margin-bottom: 24px;">
							<table class="wp-list-table widefat fixed striped">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Earning ID', 'tutorpress-pmpro' ); ?></th>
										<th><?php esc_html_e( 'Order ID', 'tutorpress-pmpro' ); ?></th>
										<th><?php esc_html_e( 'Course ID', 'tutorpress-pmpro' ); ?></th>
										<th><?php esc_html_e( 'Status', 'tutorpress-pmpro' ); ?></th>
										<th><?php esc_html_e( 'Instructor', 'tutorpress-pmpro' ); ?></th>
										<th><?php esc_html_e( 'Total', 'tutorpress-pmpro' ); ?></th>
										<th><?php esc_html_e( 'Created', 'tutorpress-pmpro' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $earnings as $earning ) : ?>
										<tr>
											<td><?php echo esc_html( $earning->earning_id ); ?></td>
											<td><?php echo esc_html( $earning->order_id ); ?></td>
											<td><?php echo esc_html( $earning->course_id ); ?></td>
											<td><strong><?php echo esc_html( $earning->order_status ); ?></strong></td>
											<td>$<?php echo esc_html( number_format( $earning->instructor_amount, 2 ) ); ?></td>
											<td>$<?php echo esc_html( number_format( $earning->course_price_total, 2 ) ); ?></td>
											<td><?php echo esc_html( $earning->created_at ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
						<?php
					} else {
						?>
						<p class="tutor-fs-7 tutor-color-secondary" style="margin-top: 20px; margin-bottom: 24px;">
							<?php esc_html_e( 'No earnings records found for this instructor.', 'tutorpress-pmpro' ); ?>
						</p>
						<?php
					}
					?>

					<?php 
					// Get raw withdrawal records
					$withdrawals = Earnings_Debug_Page::get_instructor_withdrawals( $instructor_id );
					
					if ( ! empty( $withdrawals ) ) {
						?>
						<!-- Raw Withdrawal Records -->
						<h4 class="tutor-fs-6 tutor-fw-medium tutor-color-black tutor-mb-12" style="margin-top: 20px;">
							<?php esc_html_e( 'Withdrawal Records (Last 20)', 'tutorpress-pmpro' ); ?>
						</h4>
						<div style="overflow-x: auto; margin-bottom: 24px;">
							<table class="wp-list-table widefat fixed striped">
								<thead>
									<tr>
										<th><?php esc_html_e( 'ID', 'tutorpress-pmpro' ); ?></th>
										<th><?php esc_html_e( 'Amount', 'tutorpress-pmpro' ); ?></th>
										<th><?php esc_html_e( 'Status', 'tutorpress-pmpro' ); ?></th>
										<th><?php esc_html_e( 'Created', 'tutorpress-pmpro' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $withdrawals as $withdrawal ) : ?>
										<tr>
											<td><?php echo esc_html( $withdrawal->id ); ?></td>
											<td>$<?php echo esc_html( number_format( $withdrawal->amount, 2 ) ); ?></td>
											<td><strong><?php echo esc_html( $withdrawal->status ); ?></strong></td>
											<td><?php echo esc_html( $withdrawal->created_at ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
						<?php
					} else {
						?>
						<p class="tutor-fs-7 tutor-color-secondary" style="margin-top: 20px;">
							<?php esc_html_e( 'No withdrawal records found for this instructor.', 'tutorpress-pmpro' ); ?>
						</p>
						<?php
					}
					?>

					<?php
				} else {
					?>
					<div class="tutor-alert tutor-danger">
						<div class="tutor-alert-text">
							<span class="tutor-icon-dismiss tutor-mr-12"></span>
							<span><?php esc_html_e( 'Instructor not found. Please check the user ID.', 'tutorpress-pmpro' ); ?></span>
						</div>
					</div>
					<?php
				}
			}
			?>
		</div>
	</div>

	<!-- Detailed Report -->
	<div class="tutor-mb-32">
		<h3 class="tutor-fs-5 tutor-fw-medium tutor-color-black tutor-mb-16">
			<?php esc_html_e( 'Detailed Earnings Report', 'tutorpress-pmpro' ); ?>
		</h3>
		<div class="tutor-bg-white tutor-p-24 tutor-rounded-6" style="border: 1px solid #e5e7eb;">
			<?php Earnings_Debug_Page::render_earnings_report(); ?>
		</div>
	</div>
</div>

