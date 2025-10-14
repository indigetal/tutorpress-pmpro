<?php
/**
 * Plan checkout template.
 *
 * @package TutorPro\Subscription
 * @subpackage Template
 * @since 3.5.0
 */

use Tutor\Helpers\DateTimeHelper;
use Tutor\Models\OrderModel;
use TutorPro\Subscription\Models\PlanModel;

$plan_model    = new PlanModel();
$interval_list = $plan_model->get_interval_list();

$has_trial_period  = $plan_info ? $plan_info->has_trial_period : false;
$show_coupon_box   = $show_coupon_box && ! $has_trial_period;
$is_trial_used     = false;
$user_subscription = null;
if ( $plan_info ) {
	$user_subscription = apply_filters( 'tutor_get_user_plan_subscription', null, $plan_info->id, $user_id );
	$is_trial_used     = $user_subscription && $user_subscription->is_trial_used;
}

$enrollment_fee = floatval( $plan_info->enrollment_fee );
$plan_course_id = apply_filters( 'tutor_subscription_course_by_plan', $plan_id );
$plan_course    = get_post( $plan_course_id );

$can_skip_payment_for_trial = (bool) tutor_utils()->get_option( 'allow_trial_checkout_without_payment' )
								&& 0 === $checkout_data->total_price
								&& $has_trial_period && ! $is_trial_used;

add_filter( 'tutor_checkout_show_payment_methods', fn( $bool ) => $can_skip_payment_for_trial ? false : true );

/**
* Plan item details.
* User can purchase only one plan at a time.
*/
$item = $checkout_data->items[0];

array_push( $object_ids, $plan_info->id );

$plan_url = get_the_permalink( $plan_course );
if ( $plan_info->is_membership_plan ) {
	$plan_url = $plan_info->pricing_page_url;
}

$thumbnail_url = get_tutor_course_thumbnail_src( 'post-thumbnail', $plan_course_id );
$plan_title    = $plan_info->is_membership_plan ? $plan_info->plan_name : $plan_course->post_title;
$badge_label   = $item->item_name;

$show_trial_info    = $has_trial_period && ! $user_subscription && ! $is_trial_used;
$trial_end_date_gmt = $show_trial_info ? DateTimeHelper::now()->add( $plan_info->trial_value, $plan_info->trial_interval )->to_date_time_string() : null;
$trial_classes      = $show_trial_info ? 'tutor-bg-white tutor-p-12 tutor-radius-7 tutor-divider' : '';
?>
<div class="tutor-checkout-course-item <?php echo esc_attr( $trial_classes ); ?>">
<?php if ( ! $plan_info->is_membership_plan ) { ?>
<div class="tutor-checkout-course-plan-badge">
	<?php echo esc_html( $badge_label ); ?>
</div>
<?php } ?>
<div class="tutor-checkout-course-content">
	<div class="tutor-d-flex tutor-flex-column tutor-gap-1">
		<div class="<?php echo esc_attr( $plan_info->is_membership_plan ? '' : 'tutor-checkout-course-thumb-title' ); ?>">
			<?php if ( ! $plan_info->is_membership_plan ) { ?>
			<img src="<?php echo esc_url( $thumbnail_url ); ?>" alt="<?php echo esc_attr( $plan_title ); ?>" />
			<?php } ?>

			<?php if ( $plan_info->is_membership_plan ) : ?>
				<svg style="float:left; margin-right:4px" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
					<path d="M5.85714 20H17.8571M5.85714 16H17.8571L18.7143 7L15.2857 10L11.8571 5L8.42857 10L5 7L5.85714 16Z" stroke="#0049F8" stroke-width="1.28571" stroke-linecap="round" stroke-linejoin="round"/>
				</svg>
			<?php endif; ?>
			<h6 class="tutor-checkout-course-title tutor-d-flex">
				<a href="<?php echo esc_url( $plan_url ); ?>"> <?php echo esc_html( $plan_title ); ?></a>
			</h6>
		</div>
		<?php if ( $item->is_coupon_applied ) : ?>
		<div class="tutor-checkout-coupon-badge">
			<i class="tutor-icon-tag" area-hidden="true"></i>
			<span><?php echo esc_html( $checkout_data->coupon_title ); ?></span>
		</div>
		<?php endif; ?>
	</div>

	<?php if ( ! $show_trial_info ) : ?>
	<div class="tutor-text-right tutor-d-flex tutor-align-center">
		<?php if ( $item->sale_price || $item->discount_price || ( $has_trial_period && ! $user_subscription && ! $is_trial_used ) ) : ?>
		<div class="tutor-checkout-discount-price tutor-mr-4">
			<?php tutor_print_formatted_price( $item->regular_price ); ?>
		</div>
		<?php endif; ?>
		<div class="tutor-fw-bold">
			<?php tutor_print_formatted_price( $item->display_price ); ?>
		</div>
		<div class="tutor-fs-7 tutor-color-hints">
			<?php
			echo esc_html(
				$plan_info->recurring_value > 1
				? sprintf(
					/* translators: %s: value, %s: name */
					__( '/%1$s %2$s', 'tutor-pro' ),
					$plan_info->recurring_value,
					$interval_list[ $plan_info->recurring_interval ] ?? $plan_info->recurring_interval
				)
				:
				sprintf(
					/* translators: %s: recurring interval */
					__( '/%1$s', 'tutor-pro' ),
					$interval_list[ $plan_info->recurring_interval ] ?? $plan_info->recurring_interval
				)
			);
			?>
		</div>
	</div>
	<?php endif; ?>
</div>
<?php if ( isset( $item->additional_items[ OrderModel::META_ENROLLMENT_FEE ] ) ) : ?>
	<div class="tutor-checkout-enrollment-fee">
		<div class="tutor-fs-6 tutor-color-black">
			<?php echo esc_html_e( 'Enrollment Fee', 'tutor-pro' ); ?>
		</div>
		<div class="tutor-text-right">
			<div class="tutor-fw-bold">
				<?php tutor_print_formatted_price( $enrollment_fee ); ?>
			</div>
		</div>
	</div>
<?php endif; ?>
<?php
if ( $show_trial_info ) :
	add_filter( 'tutor_checkout_sale_discount_label', fn( $label ) => __( 'Trial discount', 'tutor-pro' ) );
	?>
	<div class="tutor-mt-12">
		<div class="tutor-plan-trial-price">
			<div>
				<div class="tutor-plan-trial-icon-wrapper tutor-fs-7 tutor-color-title tutor-fw-medium">
					<i class="tutor-icon-circle-mark tutor-color-success tutor-fs-6"></i>
					<?php
						$trial_label = sprintf(
							/* translators: %d: trial value, %s: trial interval, %s: free or not */
							__( '%1$d-%2$s %3$sTrial', 'tutor-pro' ),
							$plan_info->trial_value,
							ucwords( $plan_info->trial_value > 1 ? $plan_info->trial_interval . 's' : $plan_info->trial_interval ),
							$plan_info->trial_fee > 0 ? '' : 'Free '
						);
						echo esc_html( $trial_label );
					?>
				</div>
				<div class="tutor-ml-20 tutor-mt-4 tutor-color-hints tutor-fs-7">
					<i class="tutor-icon-calender-line"></i>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: trial end date */
							__( 'Trial ends on %s', 'tutor-pro' ),
							DateTimeHelper::get_gmt_to_user_timezone_date( $trial_end_date_gmt, get_option( 'date_format' ) )
						)
					);
					?>
				</div>
			</div>
			<?php if ( $plan_info->trial_fee > 0 ) : ?>
			<div class="tutor-fs-7 tutor-fw-medium">
				<?php tutor_print_formatted_price( $plan_info->trial_fee ); ?>
			</div>
			<?php endif; ?>
		</div>
	</div>

	<?php if ( $has_trial_period && ! $is_trial_used ) : ?>
		<ul class="tutor-fs-8 tutor-color-muted tutor-pl-20 tutor-mt-8">
			<li>
			<?php
				echo wp_kses(
					sprintf(
						/* translators: %s: tag start, %s: plan price, %s: tag close */
						__( 'After trial, regular plan price %1$s%2$s%3$s will be charged.', 'tutor-pro' ),
						'<strong>',
						tutor_get_formatted_price( $plan_info->regular_price ),
						'</strong>'
					),
					array(
						'strong' => array(),
					)
				);
			?>
			</li>
			<?php
			if ( $enrollment_fee > 0 ) {
				?>
				<li>
				<?php
					echo wp_kses(
						sprintf(
							/* translators: %s: tag start, %s: enrollment fee, %s: tag close */
							__( 'An enrollment fee of %1$s%2$s%3$s will also be charged.', 'tutor-pro' ),
							'<strong>',
							tutor_get_formatted_price( $enrollment_fee ),
							'</strong>'
						),
						array(
							'strong' => array(),
						)
					);
				?>
				</li>
				<?php
			}
			?>
		</ul>
	<?php endif; ?>
<?php endif; ?>
</div>
