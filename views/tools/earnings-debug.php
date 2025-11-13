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

	<?php
	// Show cleanup success message.
	if ( isset( $_GET['result'] ) && 'cleanup_success' === $_GET['result'] ) {
		$deleted = isset( $_GET['deleted'] ) ? intval( $_GET['deleted'] ) : 0;
		?>
		<div class="tutor-alert tutor-success tutor-mb-24">
			<div class="tutor-alert-text">
				<span class="tutor-icon-circle-mark tutor-mr-12" area-hidden="true"></span>
				<span>
					<strong><?php esc_html_e( 'Cleanup Complete!', 'tutorpress-pmpro' ); ?></strong>
					<?php
					if ( $deleted > 0 ) {
						/* translators: %d: number of deleted records */
						printf( esc_html__( ' Successfully deleted %d orphaned earning record(s).', 'tutorpress-pmpro' ), $deleted );
					} else {
						esc_html_e( ' No orphaned earnings found.', 'tutorpress-pmpro' );
					}
					?>
				</span>
			</div>
		</div>
		<?php
	}
	?>

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
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
					onsubmit="return confirm('<?php echo esc_js( sprintf( __( 'Are you sure you want to delete %d orphaned earning record(s)? This cannot be undone.', 'tutorpress-pmpro' ), $stats['orphaned'] ) ); ?>');">
					<?php wp_nonce_field( 'tpp_cleanup_orphaned' ); ?>
					<input type="hidden" name="action" value="tpp_cleanup_orphaned">
					<button type="submit" class="tutor-btn tutor-btn-primary">
						<span class="tutor-icon-times tutor-mr-8" style="margin-top: -2px;"></span>
						<?php esc_html_e( 'Cleanup Orphaned Earnings', 'tutorpress-pmpro' ); ?>
					</button>
				</form>
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

