<?php
/**
 * Subscription Report Page
 *
 * @package TutorPro\Addons
 * @subpackage Subscriptions\Views
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.3.0
 */

use TUTOR\Input;
use TutorPro\Subscription\Settings;
use TutorPro\Subscription\Controllers\ReportController;
use TutorPro\Subscription\Utils;

$report_controller    = new ReportController( false );
$frequencies          = tutor_utils()->report_frequencies();
$period               = Input::get( 'period', 'last30days' );
$start_date           = Input::has( 'start_date' ) ? tutor_get_formated_date( 'Y-m-d', Input::get( 'start_date' ) ) : '';
$end_date             = Input::has( 'end_date' ) ? tutor_get_formated_date( 'Y-m-d', Input::get( 'end_date' ) ) : '';
$subscription_type    = Input::get( 'subscription_type', 'all' );
$membership_only_mode = Settings::membership_only_mode_enabled();

// Active Subscriptions.
$active_subscriptions             = ReportController::get_active_subscriptions_count();
$total_active_subscriptions_count = $active_subscriptions['active_subscriptions_count'];
$total_active_memberships_count   = $active_subscriptions['active_memberships_count'];

// Total Revenue.
$total_subscription_revenue = ReportController::get_total_subscription_revenue_by_type( 'subscription' );
$total_membership_revenue   = ReportController::get_total_subscription_revenue_by_type( 'membership' );

// Expired Subscriptions.
$expired_subscriptions             = ReportController::get_expired_subscription_count();
$total_expired_subscriptions_count = $expired_subscriptions['expired_subscriptions_count'];
$total_expired_memberships_count   = $expired_subscriptions['expired_memberships_count'];


// Top Subscriptions.
$top_subscriptions = ReportController::get_top_subscriptions_by_types();
$top_memberships   = $top_subscriptions['top_memberships'];
$top_subscriptions = $top_subscriptions['top_subscriptions'];


list( $refunds, $total_refund ) = array_values( $report_controller->get_refunds( $period, $start_date, $end_date, $subscription_type ) );
?>

<style>
	.tutor-admin-report-frequency-wrapper .tutor-dropdown-select-selected > div {
		display: flex;
		align-items: center;
		gap: 8px;
		padding-right: 16px;
	}

	.tutor-wp-dashboard-filter-item .tutor-form-select {
		padding: 12px 16px !important;
	}
</style>

<div class="tutor-report-overview-wrap">
	<div class="tutor-row tutor-gx-4">

		<?php if ( ! $membership_only_mode ) : ?>
		<div class="tutor-col-md-6 tutor-col-xl-3 tutor-my-8 tutor-my-md-16">
			<div class="tutor-card tutor-card-secondary tutor-p-24">
				<div class="tutor-d-flex">
					<div class="tutor-round-box">
						<img src="<?php echo esc_attr( Utils::asset_url( 'images/icons/subscriptions_active.svg' ) ); ?>" alt="Active Subscription">
					</div>
					<div class="tutor-ml-20">
						<div class="tutor-fs-4 tutor-fw-bold tutor-color-black">
							<?php echo esc_html( $total_active_subscriptions_count ); ?>
						</div>
						<div class="tutor-fs-7 tutor-color-secondary">
							<?php esc_html_e( 'Active Subscriptions', 'tutor-pro' ); ?>
						</div>
					</div>							
				</div>
			</div>
		</div>
		<?php endif; ?>			

		<div class="tutor-col-md-6 tutor-col-xl-3 tutor-my-8 tutor-my-md-16">
			<div class="tutor-card tutor-card-secondary tutor-p-24">
				<div class="tutor-d-flex">
					<div class="tutor-round-box">
						<img src="<?php echo esc_attr( Utils::asset_url( 'images/icons/memberships_active.svg' ) ); ?>" alt="Active Memberships">
					</div>

					<div class="tutor-ml-20">
						<div class="tutor-fs-4 tutor-fw-bold tutor-color-black">
							<?php echo esc_html( $total_active_memberships_count ); ?>
						</div>
						<div class="tutor-fs-7 tutor-color-secondary"><?php esc_html_e( 'Active Memberships', 'tutor-pro' ); ?></div>
					</div>
				</div>
			</div>
		</div>

		<?php if ( ! $membership_only_mode ) : ?>
		<div class="tutor-col-md-6 tutor-col-xl-3 tutor-my-8 tutor-my-md-16">
			<div class="tutor-card tutor-card-secondary tutor-p-24">
				<div class="tutor-d-flex">
					<div class="tutor-round-box">
						<img src="<?php echo esc_attr( Utils::asset_url( 'images/icons/subscriptions_revenue.svg' ) ); ?>" alt="Subscriptions Revenue">
					</div>

					<div class="tutor-ml-20">
						<div class="tutor-fs-4 tutor-fw-bold tutor-color-black">
							<?php tutor_print_formatted_price( $total_subscription_revenue ); ?>
						</div>
						<div class="tutor-fs-7 tutor-color-secondary"><?php esc_html_e( 'Subscription Revenue', 'tutor-pro' ); ?></div>
					</div>
				</div>
			</div>
		</div>
		<?php endif; ?>

		<div class="tutor-col-md-6 tutor-col-xl-3 tutor-my-8 tutor-my-md-16">
			<div class="tutor-card tutor-card-secondary tutor-p-24">
				<div class="tutor-d-flex">
					<div class="tutor-round-box">
						<img src="<?php echo esc_attr( Utils::asset_url( 'images/icons/memberships_revenue.svg' ) ); ?>" alt="Memberships Revenue">
					</div>

					<div class="tutor-ml-20">
						<div class="tutor-fs-4 tutor-fw-bold tutor-color-black">
							<?php tutor_print_formatted_price( $total_membership_revenue ); ?>
						</div>
						<div class="tutor-fs-7 tutor-color-secondary"><?php esc_html_e( 'Membership Revenue', 'tutor-pro' ); ?></div>
					</div>
				</div>
			</div>
		</div>

		<?php if ( ! $membership_only_mode ) : ?>
		<div class="tutor-col-md-6 tutor-col-xl-3 tutor-my-8 tutor-my-md-16">
			<div class="tutor-card tutor-card-secondary tutor-p-24">
				<div class="tutor-d-flex">
					<div class="tutor-round-box">
						<img src="<?php echo esc_attr( Utils::asset_url( 'images/icons/subscriptions_expired.svg' ) ); ?>" alt="Subscriptions Expired">
					</div>

					<div class="tutor-ml-20">
						<div class="tutor-fs-4 tutor-fw-bold tutor-color-black">
							<?php echo esc_html( $total_expired_subscriptions_count ); ?>
						</div>
						<div class="tutor-fs-7 tutor-color-secondary"><?php esc_html_e( 'Expired Subscriptions', 'tutor-pro' ); ?></div>
					</div>
				</div>
			</div>
		</div>
		<?php endif; ?>

		<div class="tutor-col-md-6 tutor-col-xl-3 tutor-my-8 tutor-my-md-16">
			<div class="tutor-card tutor-card-secondary tutor-p-24">
				<div class="tutor-d-flex">
					<div class="tutor-round-box">
						<img src="<?php echo esc_attr( Utils::asset_url( 'images/icons/memberships_expired.svg' ) ); ?>" alt="Memberships Expired">
					</div>

					<div class="tutor-ml-20">
						<div class="tutor-fs-4 tutor-fw-bold tutor-color-black">
							<?php echo esc_html( $total_expired_memberships_count ); ?>
						</div>
						<div class="tutor-fs-7 tutor-color-secondary"><?php esc_html_e( 'Expired Memberships', 'tutor-pro' ); ?></div>
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="tutor-analytics-wrapper tutor-analytics-graph tutor-mt-12">

		<div class="tutor-fs-5 tutor-fw-medium tutor-color-black tutor-d-flex tutor-align-center tutor-justify-between tutor-mb-16">
			<div>
				<?php esc_html_e( 'Earning graph', 'tutor-pro' ); ?>
			</div>
			<div class="tutor-d-flex tutor-align-right tutor-justify-between tutor-gap-2 tutor-flex-column tutor-flex-md-row">
				<div class="tutor-admin-report-plans-wrapper" style="min-width: 260px;">
					<div class="tutor-wp-dashboard-filter-item">
						<select class="tutor-form-select" tutor-filter-query-param="subscription_type" tutor-filter-event-type="change" tutor-data-filterable>
							<?php foreach ( ReportController::get_subscription_sorting_dropdown_list() as $k => $sorting_type ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $subscription_type, $k ); ?>>
									<?php echo esc_html( $sorting_type ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>
				<div class="tutor-admin-report-frequency-wrapper" style="min-width: 260px;">
					<?php tutor_load_template_from_custom_path( TUTOR_REPORT()->path . 'templates/elements/frequency.php' ); ?>
					<div class="tutor-v2-date-range-picker inactive"></div>
				</div>
			</div>
		</div>
		<div class="tutor-overview-month-graph">
			<!--analytics graph -->
			<?php
				/**
				 * Get analytics data
				 *
				 * @since 3.3.0
				 */
				$user_id     = get_current_user_id();
				$earnings    = ReportController::get_total_earnings_for_subscriptions( $period, $start_date, $end_date, $subscription_type );
				$enrollments = ReportController::get_total_enrollments_for_subscriptions( $period, $start_date, $end_date, $subscription_type );

				/* translators: %s: frequencies */
				$content_title = sprintf( __( 'for %s', 'tutor-pro' ), $frequencies[ $period ] );
				$graph_tabs    = array(
					array(
						'tab_title'     => __( 'Total Earning', 'tutor-pro' ),
						'tab_value'     => $earnings['total_earnings'],
						'data_attr'     => 'ta_total_earnings',
						'active'        => ' is-active',
						'price'         => true,
						/* translators: %s: content title */
						'content_title' => sprintf( __( 'Earnings Chart %s', 'tutor-pro' ), $content_title ),
					),
					array(
						'tab_title'     => __( 'Course Enrolled', 'tutor-pro' ),
						'tab_value'     => $enrollments['total_enrollments'],
						'data_attr'     => 'ta_total_course_enrolled',
						'active'        => '',
						'price'         => false,
						/* translators: %s: content title */
						'content_title' => sprintf( __( 'Course Enrolled Chart %s', 'tutor-pro' ), $content_title ),
					),
					array(
						'tab_title'     => __( 'Total Refund', 'tutor-pro' ),
						'tab_value'     => $total_refund,
						'data_attr'     => 'ta_total_refund',
						'active'        => '',
						'price'         => true,
						/* translators: %s: content title */
						'content_title' => sprintf( __( 'Refund Chart %s', 'tutor-pro' ), $content_title ),
					),
				);

				$graph_template = TUTOR_REPORT()->path . 'templates/elements/graph.php';
				tutor_load_template_from_custom_path( $graph_template, $graph_tabs );
				?>
			<!--analytics graph end -->
		</div>
	</div>

	<div class="tutor-mb-48" id="tutor-courses-overview-section">
		<div class="single-overview-section tutor-most-popular-courses">
			<div class="tutor-fs-5 tutor-fw-medium tutor-color-black tutor-mb-24">
				<?php esc_html_e( 'Top Course & Bundle Subscription Insights', 'tutor-pro' ); ?>
			</div>
			<div class="tutor-table-responsive">
				<table class="tutor-table table-popular-courses">
					<thead>
						<tr>
							<th>
								<?php esc_html_e( 'Course Name', 'tutor-pro' ); ?>
							</th>
							<th>
								<?php esc_html_e( 'Active Users', 'tutor-pro' ); ?>
							</th>
							<th>
								<?php esc_html_e( 'Total Revenue', 'tutor-pro' ); ?>
							</th>
						</tr>
					</thead>

					<tbody>
						<?php if ( is_array( $top_subscriptions ) && count( $top_subscriptions ) ) : ?>
							<?php foreach ( $top_subscriptions as $subscription ) : ?>
								<tr>
									<td>
										<?php echo esc_html( $subscription['course_name'] ); ?>
									</td>							
									<td>
										<?php echo esc_html( $subscription['active_users'] ); ?>
									</td>
									<td>
										<?php tutor_print_formatted_price( $subscription['total_revenue'] ); ?>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php else : ?>
							<tr>
								<td colspan="100%" class="column-empty-state">
									<?php tutor_utils()->tutor_empty_state( tutor_utils()->not_found_text() ); ?>
								</td>
							</tr>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>

		<div class="single-overview-section tutor-last-enrolled-courses">
			<div class="tutor-fs-5 tutor-fw-medium tutor-color-black tutor-mb-24 tutor-mt-48">
				<?php esc_attr_e( 'Membership Plan Insights', 'tutor-pro' ); ?>
			</div>
				<div class="tutor-table-responsive">
					<table class="tutor-table table-popular-courses">
						<thead>
							<tr>
								<th>
									<?php esc_html_e( 'Membership Name', 'tutor-pro' ); ?>
								</th>
								<th>
									<?php esc_html_e( 'Price', 'tutor-pro' ); ?>
								</th>
								<th>
									<?php esc_html_e( 'Active Users', 'tutor-pro' ); ?>
								</th>
								<th>
									<?php esc_html_e( 'Total Revenue', 'tutor-pro' ); ?>
								</th>
								<th>
									<?php esc_html_e( 'Course Access', 'tutor-pro' ); ?>
								</th>
							</tr>
							</tr>
						</thead>

						<tbody>
							<?php if ( is_array( $top_memberships ) && count( $top_memberships ) ) : ?>			
								<?php
								foreach ( $top_memberships as $membership ) :
									$has_sale_price = $membership['sale_price'] > 0;
									?>
								<tr>
									<td>
										<?php echo esc_html( $membership['membership_name'] ); ?>
									</td>
									<td>
										<div class="tutor-fs-7">		

											<?php if ( $has_sale_price ) : ?>

												<del class="tutor-fs-7 tutor-color-hints">
													<?php tutor_print_formatted_price( $membership['regular_price'] ); ?>
												</del>
												<div class="tutor-fw-normal">
													<?php tutor_print_formatted_price( $membership['sale_price'] ); ?>
												</div>

											<?php else : ?>
												<?php tutor_print_formatted_price( $membership['regular_price'] ); ?>
											<?php endif; ?>															
										</div>
									</td>
									<td>
										<?php echo esc_html( $membership['active_users'] ); ?>
									</td>
									<td>
										<?php tutor_print_formatted_price( $membership['total_revenue'] ); ?>
									</td>
									<td>
										<?php echo esc_html( $membership['type'] ); ?>
									</td>
								</tr>
							<?php endforeach; ?>
							<?php else : ?>
								<tr>
									<td colspan="100%" class="column-empty-state">
										<?php tutor_utils()->tutor_empty_state( tutor_utils()->not_found_text() ); ?>
									</td>
								</tr>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
		</div>
	</div>
</div>
