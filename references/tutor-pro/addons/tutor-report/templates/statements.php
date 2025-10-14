<?php
/**
 * Statements Template
 *
 * @package TutorPro\Addon
 * @subpackage TutorReport\Templates
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 1.9.9
 */

use TUTOR\Input;
use Tutor\Ecommerce\Tax;
use TUTOR_REPORT\Analytics;
use Tutor\Models\CourseModel;

//phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited
global $wp_query, $wp;

$user     = wp_get_current_user();
$url      = home_url( $wp->request );
$url_path = parse_url( $url, PHP_URL_PATH );
$paged    = max( 1, Input::get( 'current_page', 0, Input::TYPE_INT ) );
$per_page = tutor_utils()->get_option( 'pagination_per_page' );
$offset   = ( $per_page * $paged ) - $per_page;

$course_id   = Input::get( 'course-id', '' );
$date_filter = Input::get( 'date', '' );
if ( '' !== $date_filter ) {
	$date_filter = tutor_get_formated_date( 'Y-m-d', $date_filter );
}

//phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited

$statements            = Analytics::get_statements_by_user( $user->ID, $offset, $per_page, $course_id, $date_filter );
$courses               = CourseModel::get_courses_by_instructor();
$enable_fees_deducting = tutor_utils()->get_option( 'enable_fees_deducting' );
?>
<div class="tutor-analytics-statements">
	<div class="tutor-row tutor-gx-xl-5 tutor-mb-24">
		<div class="tutor-col-lg-8 tutor-mb-16 tutor-mb-lg-0">
			<label class="tutor-form-label">
				<?php esc_html_e( 'Courses', 'tutor-pro' ); ?>
			</label>
			<select class="tutor-form-select tutor-report-category tutor-announcement-course-sorting" data-searchable>
				<option value=""><?php esc_html_e( 'All', 'tutor-pro' ); ?></option>
				<?php if ( $courses ) : ?>
					<?php foreach ( $courses as $course ) : ?>
						<option value="<?php echo esc_attr( $course->ID ); ?>" <?php selected( $course_id, $course->ID, 'selected' ); ?>>
							<?php echo esc_html( $course->post_title ); ?>
						</option>
					<?php endforeach; ?>
				<?php else : ?>
					<option value=""><?php esc_html_e( 'No course found', 'tutor-pro' ); ?></option>
				<?php endif; ?>
			</select>
		</div>

		<div class="tutor-col-lg-4">
			<label class="tutor-form-label"><?php esc_html_e( 'Date', 'tutor-pro' ); ?></label>
			<div class="tutor-v2-date-picker">
				<div class="tutor-form-wrap">
					<span class="tutor-form-icon tutor-form-icon-reverse">
						<span class="tutor-icon-calender-line" aria-hidden="true"></span>
					</span>
					<input class="tutor-form-control" placeholder="<?php esc_attr_e( 'Loading...', 'tutor-pro' ); ?>">
				</div>
			</div>
		</div>
	</div>

	<?php if ( count( $statements['statements'] ) ) : ?>
		<div class="tutor-table-responsive">
			<table class="tutor-table tutor-table-analytics-statement">
				<thead>
					<th>
						<?php esc_html_e( 'Statement Info', 'tutor-pro' ); ?>
					</th>
					<th>
						<?php esc_html_e( 'Breakdown', 'tutor-pro' ); ?>
					</th>
					<th>
						<?php esc_html_e( 'My Earnings', 'tutor-pro' ); ?>
					</th>
					<th>
						<?php esc_html_e( 'Admin Gets', 'tutor-pro' ); ?>
					</th>
				</thead>

				<tbody>
					<?php foreach ( $statements['statements'] as $statement ) : ?>
						<?php
							$wc_order                 = function_exists( 'wc_get_order' ) ? wc_get_order( $statement->order_id ) : false;
							$customer                 = $wc_order ? $wc_order->get_user() : null;
							$is_inclusive_tax         = Tax::TYPE_INCLUSIVE === $statement->order_tax_type;
							$course_price_grand_total = $is_inclusive_tax ? max( $statement->course_price_grand_total - $statement->order_tax_amount, 0 ) : $statement->course_price_grand_total;
							$instructor_amount        = $is_inclusive_tax ? ( $course_price_grand_total * ( $statement->instructor_rate / 100 ) ) : $statement->instructor_amount;
							$admin_amount             = $is_inclusive_tax ? ( $course_price_grand_total * ( $statement->admin_rate / 100 ) ) : $statement->admin_amount;
						?>
						<tr>
							<td>
								<div class="td-statement-info">
									<div class="tutor-d-flex tutor-align-center">
										<span class="tutor-badge-label label-<?php echo esc_attr( 'completed' === $statement->order_status ? 'success' : $statement->order_status ); ?>">
											<?php echo esc_html( ucfirst( $statement->order_status ) ); ?>
										</span>
										<span class="tutor-fs-7 tutor-color-secondary tutor-ml-16">
											<?php echo esc_html( tutor_get_formated_date( get_option( 'date_format' ), $statement->created_at ) ); ?>
										</span>
									</div>

									<div class="tutor-mt-8">
										<?php echo esc_html( $statement->course_title ); ?>
									</div>

									<div class="tutor-meta tutor-mt-8">
										<span>
											<span class="tutor-meta-key"><?php esc_html_e( 'Order ID: #', 'tutor-pro' ); ?></span>
											<span class="tutor-meta-value"><?php echo esc_html( $statement->order_id ); ?></span>
										</span>

										<?php if ( is_a( $customer, 'WP_User' ) ) : ?>
										<span>
											<span class="tutor-meta-key"><?php esc_html_e( 'Purchaser:', 'tutor-pro' ); ?></span>
											<span class="tutor-meta-value"><?php echo esc_html( tutils()->get_user_name( $customer ) ); ?></span>
										</span>
										<?php endif; ?>
									</div>
								</div>
							</td>
							
							<td>
								<!-- Order Amount -->
								<div class="tutor-meta tutor-mt-8">
									<span >
										<span class="tutor-meta-key">
											<?php esc_html_e( 'Order Amount: ', 'tutor-pro' ); ?>
										</span>
										<span class="tutor-meta-value">
											<?php echo wp_kses_post( tutor_utils()->tutor_price( $statement->order_total_price ) ); ?>
										</span>
									</span>
								</div>
								<!-- Tax Amount -->
								<?php
								if ( tutor_utils()->is_monetize_by_tutor() ) :
									?>
								<div class="tutor-meta tutor-mt-8">
									<span >
										<span class="tutor-meta-key">											
											<?php
											$tax_type = ucfirst( $statement->order_tax_type );
											esc_html_e( "Tax Amount ($tax_type): ", 'tutor-pro' ); //phpcs:ignore
											?>
										</span>
										<span class="tutor-meta-value">
											<?php echo wp_kses_post( tutor_utils()->tutor_price( $statement->order_tax_amount ) ); ?>
										</span>
									</span>
								</div>
								<?php endif; ?>
								<!-- Deducted Fees -->
								<div class="tutor-meta tutor-mt-8">
									<span>
										<span class="tutor-meta-key">
											<?php

											if ( empty( $statement->deduct_fees_name ) ) {
												echo esc_html( 'Maintenance Fees: ' );
											} else {
												esc_html_e( $statement->deduct_fees_name, 'tutor-pro' ); //phpcs:ignore
											}

											echo ! empty( $statement->deduct_fees_type ) ? ' (' . esc_html( ucfirst( $statement->deduct_fees_type ) ) . '): ' : '';
											?>
										</span>
										<span class="tutor-meta-value">
											<?php echo wp_kses_post( tutor_utils()->tutor_price( $statement->deduct_fees_amount ) ); ?>
										</span>
									</span>
								</div>
								
								<!-- Net Amount -->
								<div class="tutor-meta tutor-mt-8">
									<span>
										<span class="tutor-meta-key">
											<?php esc_html_e( 'Net Amount: ', 'tutor-pro' ); ?>
										</span>
										<span class="tutor-meta-value">
											<?php echo wp_kses_post( tutor_utils()->tutor_price( $course_price_grand_total ) ); ?>
										</span>
									</span>
								</div>
							</td>

							<td>
								<?php $instructor_commission_type = 'percent' === $statement->commission_type ? '%' : ''; ?>
								<div class="tutor-fs-7 tutor-fw-medium tutor-color-black">
									<?php echo wp_kses_post( tutor_utils()->tutor_price( $instructor_amount ) ); ?> <br />
									<span class="tutor-fs-7 tutor-color-muted">
										<?php
											echo wp_kses_post( $statement->instructor_rate . $instructor_commission_type . __( ' of ', 'tutor-pro' ) . tutor_utils()->tutor_price( $course_price_grand_total ) );
										?>
									</span>
								</div>
							</td>

							<td>
								<?php $admin_rate_type = 'percent' === $statement->commission_type ? '%' : ''; ?>
								<div class="tutor-fs-7 tutor-fw-medium tutor-color-black">
									<?php echo wp_kses_post( tutor_utils()->tutor_price( $admin_amount ) ); ?> <br />
									<span class="tutor-fs-7 tutor-color-muted">
										<?php
											/* translators: 1: rate 2: rate type */
											echo esc_html( sprintf( __( 'As per %1$d%2$s', 'tutor-pro' ), $statement->admin_rate, $admin_rate_type ) );
										?>
									</span>
								</div>
							</td>						
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<?php
		if ( $statements['total_statements'] > $per_page ) {
			$pagination_data = array(
				'total_items' => $statements['total_statements'],
				'per_page'    => $per_page,
				'paged'       => $paged,
			);
			tutor_load_template_from_custom_path(
				tutor()->path . 'templates/dashboard/elements/pagination.php',
				$pagination_data
			);
		}
		?>
	<?php else : ?>
		<?php tutor_utils()->tutor_empty_state( tutor_utils()->not_found_text() ); ?>
	<?php endif; ?>
</div>
