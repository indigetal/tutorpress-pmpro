<?php
/**
 * Template for plan list
 *
 * @package TutorPro\Subscription
 * @subpackage Templates
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.3.0
 */

use TutorPro\Subscription\Models\PlanModel;

$plan_model    = new PlanModel();
$interval_list = $plan_model->get_interval_list();

extract( $data );//phpcs:ignore
?>

<div class="tutor-subscription-choose-plan"><?php echo esc_html__( 'Choose plan', 'tutor-pro' ); ?></div>
<?php
foreach ( $active_course_plans as $plan ) :
	$in_sale_price = $plan_model->in_sale_price( $plan );
	$sale_price    = $in_sale_price ? $plan->sale_price : null;

	$tax_collection = $plan_model->is_tax_enabled_for_plan( $plan->id );
	$price_info     = tutor_utils()->get_prices_with_tax_info( $plan->regular_price, $sale_price, $tax_collection );
	$display_price  = $price_info->display_price;

	$features      = $plan_model->prepare_plan_features( $plan );
	$plan_buy_link = add_query_arg( array( 'plan' => $plan->id ), $checkout_link );
	?>

		<label class="tutor-course-subscription-plan <?php echo esc_attr( $plan->is_featured ? 'featured' : '' ); ?>"
			data-features="<?php echo esc_attr( wp_json_encode( $features ) ); ?>"
			data-plan-id="<?php echo esc_attr( $plan->id ); ?>"
			data-checkout-link="<?php echo esc_url( $plan_buy_link ); ?>"
		>
			<div class="tutor-subscription-header">
				<div class="tutor-d-flex tutor-align-center tutor-gap-1">
					<input type="radio" name="plan_id" value="<?php echo esc_attr( $plan->id ); ?>" class="tutor-form-check-input" autocomplete="off">
					<span class="tutor-fs-6 tutor-fw-medium tutor-color-black">
					<?php echo esc_html( $plan->plan_name ); ?>

					<?php
					if ( $plan->is_featured ) :
						?>
							<span class="tutor-subscription-featured-badge">
								<i class="tutor-icon-star-bold"></i>
							</span>
						<?php
						endif;
					?>
					</span>
				</div>

				<div class="tutor-ml-32 tutor-mt-4">
					<div>
						<strong class="tutor-subscription-price"><?php echo esc_html( tutor_get_formatted_price( $display_price ) ); ?></strong>
						<?php if ( $in_sale_price ) : ?>
							<span class="tutor-subscription-discount-price"><?php echo esc_html( tutor_get_formatted_price( $plan->regular_price ) ); ?></span>
						<?php endif; ?>
						<?php if ( PlanModel::PAYMENT_RECURRING === $plan->payment_type ) { ?>
						<span class="tutor-fs-6 tutor-color-subdued">
							<?php
							echo esc_html(
								$plan->recurring_value > 1
								? sprintf(
									/* translators: %s: value, %s: name */
									__( '/ %1$s %2$s', 'tutor-pro' ),
									$plan->recurring_value,
									$interval_list[ $plan->recurring_interval ] ?? $plan->recurring_interval,
								)
								:
								sprintf(
									/* translators: %s: recurring interval */
									__( '/ %1$s', 'tutor-pro' ),
									$interval_list[ $plan->recurring_interval ] ?? $plan->recurring_interval,
								)
							);
							?>
						</span>
						<?php } else { ?>
							<span class="tutor-fs-6 tutor-color-subdued">/ <?php esc_html_e( 'lifetime', 'tutor-pro' ); ?></span>
							<?php } ?>
					</div>
					<?php if ( $price_info->show_incl_tax_label ) : ?>
					<div class="tutor-fs-7 tutor-color-subdued"><?php esc_html_e( 'Incl. tax', 'tutor-pro' ); ?></div>
					<?php endif; ?>
				</div>
			</div>
		</label>
<?php endforeach; ?>
