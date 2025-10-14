<?php
/**
 * Membership pricing shortcode template.
 *
 * @package TutorPro\Addons
 * @subpackage Subscriptions\Templates
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.2.0
 */

use Tutor\Ecommerce\CheckoutController;
use Tutor\Ecommerce\Tax;
use TutorPro\Subscription\Models\PlanModel;
use TutorPro\Subscription\Models\SubscriptionModel;
use TutorPro\Subscription\Utils;

$user_id       = get_current_user_id();
$plan_model    = new PlanModel();
$active_plans  = $plan_model->get_membership_plans( PlanModel::STATUS_ACTIVE );
$interval_list = $plan_model->get_interval_list();
$checkout_link = CheckoutController::get_page_url();

$active_icons   = array( 'plus_circle_fill', 'plus_circle', 'plus_square_fill', 'plus_square', 'tick_circle_fill', 'tick_circle', 'tick' );
$inactive_icons = array( 'cross_circle_fill', 'cross_circle', 'cross', 'minus_circle_fill', 'minus_circle', 'minus_circle_square_fill', 'minus_circle_square' );

$is_tax_included_in_price = Tax::is_tax_included_in_price();
$tax_rate                 = Tax::get_user_tax_rate( get_current_user_id() );

$subscription_model     = new SubscriptionModel();
$user_latest_membership = $subscription_model->get_user_latest_membership( $user_id );

$is_logged_in            = is_user_logged_in();
$required_loggedin_class = '';

if ( ! $is_logged_in ) {
	$required_loggedin_class = apply_filters( 'tutor_enroll_required_login_class', ' tutor-open-login-modal' );
}
?>

<div class="tutor-membership-pricing-page">
	<div class="tutor-container">
		<?php if ( $user_latest_membership ) { ?>
		<div class="tutor-row tutor-p-12 tutor-mb-16">
			<?php
			tutor_load_template_from_custom_path(
				Utils::template_path( 'subscription-exist-alert.php' ),
				array(
					'plan_name'           => $user_latest_membership->plan_name,
					'subscription_status' => $user_latest_membership->status,
					'subscription_url'    => $subscription_model->get_subscription_details_url( $user_latest_membership->id ),
				)
			);
			?>
		</div>
		<?php } ?>

		<div class="tutor-row tutor-g-3">
			<?php
			foreach ( $active_plans as $plan ) {
				$features       = json_decode( $plan->description );
				$plan_buy_link  = add_query_arg( array( 'plan' => $plan->id ), $checkout_link );
				$has_sale_offer = $plan_model->in_sale_price( $plan );
				$display_price  = $has_sale_offer ? $plan->sale_price : $plan->regular_price;
				$saved_amount   = $has_sale_offer ? ( $plan->regular_price - $plan->sale_price ) : 0;
				$interval       = $interval_list[ $plan->recurring_interval ] ?? $plan->recurring_interval;
				$plan_duration  = ( $plan->recurring_value > 1 )
								/* translators: %s: recurring count, %s: recurring interval */
								? sprintf( __( '/%1$s %2$s', 'tutor-pro' ), $plan->recurring_value, $interval )
								/* translators: %s: recurring interval */
								: sprintf( __( '/%s', 'tutor-pro' ), $interval );

				$tax_amount = 0;
				if ( Tax::is_tax_configured() && Tax::show_price_with_tax() && $tax_rate > 0 && ! $is_tax_included_in_price ) {
					$tax_amount    = Tax::calculate_tax( $display_price, $tax_rate );
					$display_price = $display_price + $tax_amount;
				}
				?>
			<div class="tutor-col-md-4">
				<div class="tutor-membership-pricing-item <?php echo esc_attr( $plan->is_featured ? 'is-featured' : '' ); ?>">
					<?php if ( $plan->is_featured ) : ?>
						<div class="tutor-membership-pricing-featured-badge">
							<svg width="8" height="9" viewBox="0 0 8 9" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M0.440476 5.14471C-0.146827 5.0186 -0.146825 4.18062 0.440478 4.0545L2.56964 3.59729C2.7841 3.55124 2.95163 3.38371 2.99769 3.16924L3.4549 1.04008C3.58101 0.452783 4.41899 0.452785 4.54511 1.04009L5.00231 3.16925C5.04837 3.38371 5.2159 3.55124 5.43036 3.59729L7.55952 4.0545C8.14683 4.18062 8.14682 5.0186 7.55952 5.14471L5.43036 5.60192C5.2159 5.64798 5.04837 5.81551 5.00231 6.02997L4.5451 8.15913C4.41899 8.74644 3.58101 8.74643 3.45489 8.15913L2.99769 6.02997C2.95163 5.81551 2.7841 5.64798 2.56964 5.60192L0.440476 5.14471Z" fill="white"/>
							</svg>
							<?php echo esc_html( empty( $plan->featured_text ) ? __( 'Most Popular', 'tutor-pro' ) : $plan->featured_text ); ?>
							<svg width="8" height="9" viewBox="0 0 8 9" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M0.440476 5.14471C-0.146827 5.0186 -0.146825 4.18062 0.440478 4.0545L2.56964 3.59729C2.7841 3.55124 2.95163 3.38371 2.99769 3.16924L3.4549 1.04008C3.58101 0.452783 4.41899 0.452785 4.54511 1.04009L5.00231 3.16925C5.04837 3.38371 5.2159 3.55124 5.43036 3.59729L7.55952 4.0545C8.14683 4.18062 8.14682 5.0186 7.55952 5.14471L5.43036 5.60192C5.2159 5.64798 5.04837 5.81551 5.00231 6.02997L4.5451 8.15913C4.41899 8.74644 3.58101 8.74643 3.45489 8.15913L2.99769 6.02997C2.95163 5.81551 2.7841 5.64798 2.56964 5.60192L0.440476 5.14471Z" fill="white"/>
							</svg>
						</div>
					<?php endif; ?>
					<div class="tutor-membership-pricing-item-inner">
						<h5 class="tutor-membership-pricing-title"><?php echo esc_html( $plan->plan_name ); ?></h5>
						<div class="tutor-pricing-price">
							<h3 class="tutor-pricing-price-amount">
								<?php tutor_print_formatted_price( $display_price ); ?>
								<?php if ( Tax::show_price_with_tax() ) : ?>
								<small class="tutor-d-block tutor-fs-8 tutor-fw-normal"><?php esc_html_e( 'Incl. Tax', 'tutor-pro' ); ?></small>
								<?php endif; ?>
							</h3>
							<div class="tutor-d-flex tutor-flex-column">
								<?php
								if ( $has_sale_offer ) :
									$sale_label_tag = $plan->is_featured ? 'span' : 'del';
									$sale_label     = $plan->is_featured
														/* translators: %s: saved amount */
														? sprintf( __( 'Save %s', 'tutor-pro' ), tutor_get_formatted_price( $saved_amount ) )
														: tutor_get_formatted_price( $plan->regular_price );
									?>
								<<?php echo esc_attr( $sale_label_tag ); ?> 
									class="tutor-pricing-price-discount">
									<?php echo esc_html( $sale_label ); ?>
								</<?php echo esc_attr( $sale_label_tag ); ?>>
								<?php endif; ?>
								<span class="tutor-pricing-price-duration"><?php echo esc_html( $plan_duration ); ?></span>
							</div>
						</div>
						<p class="tutor-short-description"><?php echo esc_html( $plan->short_description ?? '' ); ?></p>
						<div class="tutor-action tutor-text-center tutor-mb-32">
							<a href="<?php echo esc_url( $plan_buy_link ); ?>" class="tutor-btn tutor-btn-lg tutor-btn-block <?php echo esc_attr( ( $plan->is_featured ? 'tutor-btn-primary' : 'tutor-btn-outline-primary' ) . $required_loggedin_class ); ?> ">
								<?php esc_html_e( 'Buy Now', 'tutor-pro' ); ?>
							</a>
						</div>
						<ul class="tutor-pricing-features">
							<?php
							foreach ( $features as $feature ) {
								$icon_class = in_array( $feature->icon, $active_icons, true )
												? 'tutor-feature-icon-active'
												: 'tutor-feature-icon-inactive';
								?>
							<li class="tutor-d-flex tutor-gap-1 tutor-align-center">
								<span class="<?php echo esc_attr( $icon_class ); ?> tutor-d-inline-flex">
									<?php
									$icon_name = sanitize_file_name( $feature->icon );
									$icon_file = TUTOR_PRO()->path . "addons/subscription/assets/images/icons/{$icon_name}.svg";
									if ( file_exists( $icon_file ) ) {
										require $icon_file;
									}
									?>
								</span>
								<?php echo esc_html( $feature->content ?? '' ); ?>
							</li>
							<?php } ?>
						</ul>
					</div>
				</div>
			</div>
				<?php
			}
			?>
		</div>
	</div>
</div>

<?php
if ( ! is_user_logged_in() ) {
	tutor_load_template_from_custom_path( tutor()->path . '/views/modal/login.php' );
}
?>
