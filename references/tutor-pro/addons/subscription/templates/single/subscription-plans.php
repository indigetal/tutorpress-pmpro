<?php
/**
 * Template for showing course specific plans.
 *
 * @package TutorPro\Subscription
 * @subpackage Templates
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.0.0
 * @since 3.2.0 membership plan logic added.
 */

use TUTOR\Course;
use Tutor\Ecommerce\Tax;
use Tutor\Models\CartModel;
use TutorPro\Subscription\Utils;
use Tutor\Ecommerce\CartController;
use TutorPro\Subscription\Settings;
use Tutor\Ecommerce\CheckoutController;
use Tutor\Ecommerce\Settings as EcommerceSettings;
use TutorPro\Subscription\Models\PlanModel;
use TutorPro\Subscription\Models\SubscriptionModel;

$course_id          = get_the_ID();
$is_logged_in       = is_user_logged_in();
$user_id            = get_current_user_id();
$plan_model         = new PlanModel();
$subscription_model = new SubscriptionModel();

$required_loggedin_class = Course::SELLING_OPTION_SUBSCRIPTION !== $selling_option && ! EcommerceSettings::is_buy_now_enabled() ? 'tutor-native-add-to-cart' : '';
if ( ! $is_logged_in ) {
	$required_loggedin_class = apply_filters( 'tutor_enroll_required_login_class', 'tutor-open-login-modal' );
}

$active_membership_plans     = $plan_model->get_membership_plans( PlanModel::STATUS_ACTIVE );
$has_active_membership_plans = count( $active_membership_plans ) > 0;

$checkout_link       = CheckoutController::get_page_url();
$can_cancel_anytime  = (bool) tutor_utils()->get_option( 'subscription_cancel_anytime', true );
$active_course_plans = $plan_model->get_subscription_plans( $course_id, PlanModel::STATUS_ACTIVE );
$has_course_plans    = count( $active_course_plans );
$lowest_plan_price   = $plan_model->get_lowest_plan_price( $active_course_plans );

$lowest_membership_plan_price = $plan_model->get_lowest_plan_price( $active_membership_plans );

$show_price_with_tax = Tax::show_price_with_tax();
$user_logged_in      = is_user_logged_in();
$tax_rate            = $user_logged_in ? Tax::get_user_tax_rate() : 0;

$buy_now      = EcommerceSettings::is_buy_now_enabled();
$buy_now_link = add_query_arg( array( 'course_id' => $course_id ), $checkout_link );

if ( Course::SELLING_OPTION_MEMBERSHIP === $selling_option ) {
	if ( $has_active_membership_plans ) {
		?>
	<div class="tutor-subscription-plans membership-only">
		<div class="tutor-d-flex tutor-justify-between tutor-align-center tutor-mb-16">
			<h3 class="tutor-fs-4 tutor-fw-medium tutor-color-primary tutor-mb-0"><?php esc_html_e( 'Membership', 'tutor-pro' ); ?></h3>
			<div id="tutor-subscription-start-from" class="tutor-d-flex tutor-align-center tutor-gap-4px">
				<div class="tutor-fs-7 tutor-color-hints"><?php esc_html_e( 'Start from', 'tutor-pro' ); ?></div>
				<div class="tutor-fs-6 tutor-fw-bold tutor-color-black"><?php tutor_print_formatted_price( $lowest_membership_plan_price ); ?></div>
			</div>
		</div>
		<a href="<?php echo esc_url( Settings::get_pricing_page_url() ); ?>" class="tutor-btn tutor-btn-primary tutor-btn-lg tutor-btn-block">
			<?php esc_html_e( 'View Pricing', 'tutor-pro' ); ?>
		</a>
	</div>
		<?php
	} else {
		tutor_alert( __( 'No active membership plan found!', 'tutor-pro' ) );
	}
	return;
}

if ( Course::SELLING_OPTION_SUBSCRIPTION === $selling_option ) {
	if ( $has_course_plans ) {
		?>
	<div class="tutor-subscription-plans subscriptions-only">
		<div class="tutor-d-flex tutor-justify-between tutor-align-center tutor-mb-16">
			<h3 class="tutor-fs-4 tutor-fw-medium tutor-color-primary"><?php esc_html_e( 'Subscriptions', 'tutor-pro' ); ?></h3>
			<div id="tutor-subscription-start-from" class="tutor-d-flex tutor-align-center tutor-gap-4px">
				<div class="tutor-fs-7 tutor-color-hints"><?php esc_html_e( 'Start from', 'tutor-pro' ); ?></div>
				<div class="tutor-fs-6 tutor-fw-bold tutor-color-black"><?php tutor_print_formatted_price( $lowest_plan_price ); ?></div>
			</div>
		</div>

		<div class="tutor-subscription-plan-wrapper tutor-mb-16">
			<?php
				tutor_load_template_from_custom_path(
					Utils::template_path( 'single/plan-list.php' ),
					array(
						'active_course_plans' => $active_course_plans,
						'plan_model'          => $plan_model,
						'show_price_with_tax' => $show_price_with_tax,
						'tax_rate'            => $tax_rate,
						'checkout_link'       => $checkout_link,
					)
				);
			?>
		</div>
		<?php
			ob_start();
		?>
		<a href="#" class="tutor-btn tutor-btn-primary tutor-btn-lg tutor-btn-block tutor-subscription-buy-now <?php echo esc_attr( $required_loggedin_class ); ?>">
			<?php esc_html_e( 'Buy Now', 'tutor-pro' ); ?>
		</a>
		<?php echo apply_filters( 'tutor_course_restrict_new_entry', ob_get_clean(), $course_id ); //phpcs:ignore ?>
		<div class="tutor-plan-feature-list"></div>
	</div>
		<?php
	} else {
		tutor_alert( __( 'No subscription plan found!', 'tutor-pro' ) );
	}

	return;
}

?>

<div class="tutor-subscription-plans">
	<h3 class="tutor-fs-4 tutor-fw-medium tutor-mb-16 tutor-color-primary"><?php esc_html_e( 'Price', 'tutor-pro' ); ?></h3>

	<div class="tutor-course-subscription-options tutor-card">
		<?php
		$course_price_data = tutor_utils()->get_raw_course_price( $course_id );
		?>

		<label class="tutor-p-16 tutor-d-flex tutor-items-center tutor-justify-between">
			<div class="tutor-d-flex tutor-align-start tutor-gap-1">
				<input type="radio" name="selling_option" value="one-time" checked class="tutor-form-check-input" autocomplete="off">
				<span class="tutor-fs-6 tutor-fw-medium tutor-color-black"><?php esc_html_e( 'One-time purchase', 'tutor-pro' ); ?></span>
			</div>

			<div>
				<div class="tutor-d-flex tutor-align-center tutor-gap-1">
					<div class="tutor-fs-6 tutor-fw-bold tutor-color-black"><?php tutor_print_formatted_price( $course_price_data->display_price ); ?></div>
					<?php if ( $course_price_data->sale_price ) : ?>
					<del class="tutor-fs-7 tutor-color-hints"><?php tutor_print_formatted_price( $course_price_data->regular_price ); ?></del>
					<?php endif; ?>
				</div>
				<?php if ( $course_price_data->show_incl_tax_label ) : ?>
				<div class="tutor-fs-7 tutor-color-subdued"><?php esc_html_e( 'Incl. tax', 'tutor-pro' ); ?></div>
				<?php endif; ?>
			</div>
		</label>

		<?php
		if ( tutor_utils()->count( $active_course_plans ) && ( in_array( $selling_option, array( Course::SELLING_OPTION_ALL, Course::SELLING_OPTION_BOTH ), true ) ) ) {
			?>
			<label class="tutor-p-16 tutor-d-flex tutor-items-center tutor-justify-between">
				<div class="tutor-d-flex tutor-align-center tutor-gap-1">
					<input type="radio" name="selling_option" value="subscription" class="tutor-form-check-input" autocomplete="off">
					<span class="tutor-fs-6 tutor-fw-medium tutor-color-black"><?php esc_html_e( 'Subscriptions', 'tutor-pro' ); ?></span>
				</div>
				<div id="tutor-subscription-start-from" class="tutor-d-flex tutor-align-center tutor-gap-4px">
					<div class="tutor-fs-7 tutor-color-hints"><?php esc_html_e( 'Start from', 'tutor-pro' ); ?></div>
					<div class="tutor-fs-6 tutor-fw-bold tutor-color-black"><?php tutor_print_formatted_price( $lowest_plan_price ); ?></div>
				</div>
			</label>
			<?php
		}
		?>

		<div class="tutor-subscription-plan-wrapper tutor-p-16 tutor-pt-0 tutor-d-none">
			<?php
				tutor_load_template_from_custom_path(
					Utils::template_path( 'single/plan-list.php' ),
					array(
						'active_course_plans' => $active_course_plans,
						'plan_model'          => $plan_model,
						'show_price_with_tax' => $show_price_with_tax,
						'tax_rate'            => $tax_rate,
						'checkout_link'       => $checkout_link,
					)
				);
				?>
		</div>

		<?php if ( Course::SELLING_OPTION_ALL === $selling_option && $has_active_membership_plans ) : ?>
			<label class="tutor-p-16 tutor-d-flex tutor-items-center tutor-justify-between">
				<div class="tutor-d-flex tutor-align-center tutor-gap-1">
					<input type="radio" name="selling_option" value="membership" class="tutor-form-check-input" autocomplete="off">
					<span class="tutor-fs-6 tutor-fw-medium tutor-color-black"><?php esc_html_e( 'Memberships', 'tutor-pro' ); ?></span>
				</div>
				<div id="tutor-membership-start-from" class="tutor-d-flex tutor-align-center tutor-gap-4px">
					<div class="tutor-fs-7 tutor-color-hints"><?php esc_html_e( 'Start from', 'tutor-pro' ); ?></div>
					<div class="tutor-fs-6 tutor-fw-bold tutor-color-black"><?php tutor_print_formatted_price( $lowest_membership_plan_price ); ?></div>
				</div>
			</label>
		<?php endif; ?>
	</div>

	<div class="tutor-mt-20">
		<div class="tutor-course-subscription-buttons">
			<?php if ( Course::SELLING_OPTION_ALL === $selling_option && $has_active_membership_plans ) : ?>
			<a href="<?php echo esc_url( Settings::get_pricing_page_url() ); ?>" id="tutor-membership-view-pricing" class="tutor-btn tutor-btn-primary tutor-btn-lg tutor-btn-block tutor-d-none">
				<?php esc_html_e( 'View Plans', 'tutor-pro' ); ?>
			</a>
				<?php
			endif;
			ob_start();
			?>

			<a href="#" class="tutor-btn tutor-btn-primary tutor-btn-lg tutor-btn-block tutor-subscription-buy-now tutor-d-none <?php echo esc_attr( $required_loggedin_class ); ?>">
				<?php esc_html_e( 'Buy Now', 'tutor-pro' ); ?>
			</a>

			<?php
				echo apply_filters( 'tutor_course_restrict_new_entry', ob_get_clean(), $course_id ); //phpcs:ignore
				$is_course_in_user_cart = CartModel::is_course_in_user_cart( $user_id, $course_id );
				$cart_page_url          = CartController::get_page_url();
				ob_start();
			?>
				<div class="tutor-subscription-add-to-cart-wrap">
				<?php if ( $is_course_in_user_cart && ! $buy_now ) { ?>
					<a href="<?php echo esc_url( $cart_page_url ? $cart_page_url : '#' ); ?>" class="tutor-btn tutor-btn-outline-primary tutor-btn-lg tutor-btn-block <?php echo esc_attr( $cart_page_url ? '' : 'tutor-cart-page-not-configured' ); ?>">
						<?php esc_html_e( 'View Cart', 'tutor-pro' ); ?>
					</a>
					<?php } elseif ( $buy_now ) { ?>
					<a data-cy="tutor-buy-now" href="<?php echo esc_url( $buy_now_link ); ?>" class="tutor-btn tutor-btn-primary tutor-btn-lg tutor-btn-block <?php echo esc_attr( $required_loggedin_class ); ?>">
						<?php esc_html_e( 'Buy Now', 'tutor-pro' ); ?>
					</a>
					<?php } else { ?>
					<button data-cy="tutor-native-add-to-cart" type="button" class="tutor-btn tutor-btn-primary tutor-btn-lg tutor-btn-block <?php echo esc_attr( $required_loggedin_class ); ?>" data-course-id="<?php echo esc_attr( $course_id ); ?>" data-course-single>
						<span class="tutor-icon-cart-line tutor-mr-8"></span>
						<span><?php esc_html_e( 'Add to Cart', 'tutor-pro' ); ?></span>
					</button>
					<?php } ?>
				</div>
				<?php
				echo apply_filters( 'tutor_course_restrict_new_entry', ob_get_clean(), $course_id ); //phpcs:ignore
				?>
		</div>
		<div class="tutor-plan-feature-list tutor-d-none"></div>
	</div>
</div>
