<?php
/**
 * Handler of plan checkout.
 *
 * @package TutorPro\Subscription
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.5.0
 */

namespace TutorPro\Subscription\Controllers;

use TUTOR\Course;
use Tutor\Ecommerce\OrderController;
use TUTOR\Input;
use Tutor\Models\CouponModel;
use Tutor\Models\OrderMetaModel;
use Tutor\Models\OrderModel;
use Tutor\Traits\JsonResponse;
use TutorPro\Subscription\Models\PlanModel;
use TutorPro\Subscription\Models\SubscriptionModel;
use TutorPro\Subscription\Utils;

/**
 * PlanCheckoutController Class.
 *
 * @since 3.5.0
 */
class PlanCheckoutController {
	use JsonResponse;

	/**
	 * Subscription model.
	 *
	 * @var SubscriptionModel
	 */
	private $subscription_model;

	/**
	 * Order model.
	 *
	 * @var OrderModel
	 */
	private $order_model;

	/**
	 * Order controller instance.
	 *
	 * @var OrderController
	 */
	private $order_ctrl;

	/**
	 * Plan model
	 *
	 * @var PlanModel
	 */
	private $plan_model;

	/**
	 * Coupon model
	 *
	 * @since 3.5.0
	 *
	 * @var CouponModel
	 */
	private $coupon_model;

	/**
	 * Register hooks and dependencies
	 *
	 * @since 3.5.0
	 *
	 * @param bool $register_hooks whether to register hooks or not.
	 */
	public function __construct( $register_hooks = true ) {
		$this->order_ctrl         = new OrderController( false );
		$this->subscription_model = new SubscriptionModel();
		$this->order_model        = new OrderModel();
		$this->plan_model         = new PlanModel();
		$this->coupon_model       = new CouponModel();

		if ( ! $register_hooks ) {
			return;
		}

		add_filter( 'tutor_should_load_checkout_page', array( $this, 'should_load_checkout_page' ) );
		add_filter( 'tutor_checkout_plan_item_template', fn() => Utils::template_path( 'checkout/plan-checkout.php' ) );
		add_filter( 'tutor_checkout_subscription_item', array( $this, 'prepare_checkout_subscription_item' ), 10, 3 );
		add_filter( 'tutor_checkout_subscription_payment_items', array( $this, 'prepare_checkout_subscription_payment_items' ), 10, 3 );
		add_filter( 'tutor_checkout_pay_now_btn_text', array( $this, 'pay_now_btn_text_for_trial_checkout' ) );
		add_filter( 'tutor_coupon_is_applicable', array( $this, 'filter_is_coupon_applicable' ), 10, 5 );
		add_filter( 'tutor_automatic_coupon_args_for_checkout', array( $this, 'filter_automatic_coupon_args_for_membership_plan_checkout' ) );
		add_filter( 'tutor_checkout_coupon_code', array( $this, 'prevent_coupon_apply_on_trial_checkout' ), 10, 3 );

		add_action( 'tutor_before_checkout_order_details', array( $this, 'trial_already_used_warning_alert' ) );
	}

	/**
	 * Should load checkout page or not.
	 *
	 * @since 3.8.0
	 *
	 * @param bool $should_load should load or not.
	 *
	 * @return boolean
	 */
	public function should_load_checkout_page( $should_load ) {
		$plan_id = Input::get( 'plan', 0, Input::TYPE_INT );
		if ( $plan_id ) {
			$plan = $this->plan_model->get_plan( $plan_id );
			if ( ! $plan || ! $plan->is_enabled ) {
				?>
				<div class="tutor-container">
					<div class="tutor-mt-24"><?php tutor_alert( __( 'Invalid plan selected', 'tutor-pro' ) ); ?></div>
				</div>
				<?php
				return false;
			}
		}

		return $should_load;
	}

	/**
	 * Prepare additional checkout item like enrollment fee, trial fee etc
	 *
	 * @since 3.5.0
	 *
	 * @param string|int     $item_id item id.
	 * @param string         $item_name item name.
	 * @param int|float      $regular_price regular price.
	 * @param integer        $quantity quantity.
	 * @param int|float|null $discounted_price discounted price.
	 *
	 * @return array
	 */
	public static function prepare_additional_item( $item_id, $item_name, $regular_price, $quantity = 1, $discounted_price = null ) {
		return array(
			'item_id'          => $item_id,
			'item_name'        => $item_name,
			'regular_price'    => floatval( $regular_price ),
			'quantity'         => $quantity,
			'discounted_price' => $discounted_price,
		);
	}

	/**
	 * Prepare checkout plan item.
	 *
	 * @since 3.5.0
	 *
	 * @param array       $item checkout item.
	 * @param int         $item_id item id.
	 * @param object|null $coupon coupon.
	 *
	 * @return array
	 */
	public function prepare_checkout_subscription_item( $item, $item_id, $coupon ) {

		$plan_info = $this->plan_model->get_plan( $item_id );
		if ( ! $plan_info ) {
			return $item;
		}

		$item_name     = $plan_info->plan_name;
		$regular_price = $plan_info->has_trial_period ? 0 : $plan_info->regular_price;
		$sale_price    = $plan_info->has_trial_period
							? null
							: ( $plan_info->in_sale_price ? $plan_info->sale_price : null );

		/**
		 * User will charged regular price if already subscribed.
		 *
		 * @since 3.5.0
		 */
		$user_subscription = apply_filters( 'tutor_get_user_plan_subscription', null, $plan_info->id, get_current_user_id() );
		if ( $user_subscription ) {
			$regular_price = $plan_info->regular_price;
			$sale_price    = $plan_info->in_sale_price ? $plan_info->sale_price : null;
		}

		$tax_collection = $this->plan_model->is_tax_enabled_for_plan( $plan_info->id );

		$item = array(
			'item_id'           => (int) $item_id,
			'item_name'         => $item_name,
			'regular_price'     => $regular_price,
			'sale_price'        => is_numeric( $sale_price ) ? $sale_price : null,
			'is_coupon_applied' => false,
			'coupon_code'       => null,
			'tax_collection'    => $tax_collection,
		);

		$additional_items = array();

		/**
		 * Add enrollment fee as additional item.
		 */
		if ( $this->subscription_model->should_apply_enrollment_fee( $plan_info, $user_subscription ) ) {
			$additional_items[ OrderModel::META_ENROLLMENT_FEE ] = self::prepare_additional_item(
				OrderModel::META_ENROLLMENT_FEE,
				'Enrollment Fee',
				$plan_info->enrollment_fee
			);
		}

		/**
		 * Add trial fee as additional item.
		 */
		if ( $plan_info->trial_value > 0 && $plan_info->trial_fee > 0 && ! ( $user_subscription && $user_subscription->is_trial_used ) ) {
			$additional_items[ OrderModel::META_TRIAL_FEE ] = self::prepare_additional_item(
				OrderModel::META_TRIAL_FEE,
				'Trial Fee',
				$plan_info->trial_fee
			);
		}

		$item['additional_items'] = $additional_items;

		return $item;
	}

	/**
	 * Prepare checkout payment items for subscription.
	 *
	 * @since 3.5.0
	 *
	 * @param array  $items items.
	 * @param object $item item object.
	 * @param int    $order_id order id.
	 *
	 * @return array
	 */
	public function prepare_checkout_subscription_payment_items( $items, $item, $order_id ) {
		$item_id   = $item->item_id ?? $item->id;
		$plan_info = $this->plan_model->get_plan( $item_id );
		$item_name = $plan_info->plan_name ?? '';

		$items[] = array(
			'item_id'          => $item_id,
			'item_name'        => $item_name,
			'regular_price'    => is_numeric( $item->sale_price ) ? $item->sale_price : $item->regular_price,
			'quantity'         => 1,
			'discounted_price' => is_numeric( $item->discount_price ) ? $item->discount_price : null,
		);

		$enrollment_fee = OrderMetaModel::get_meta_value( $order_id, OrderModel::META_ENROLLMENT_FEE, true );
		$trial_fee      = OrderMetaModel::get_meta_value( $order_id, OrderModel::META_TRIAL_FEE, true );

		if ( $enrollment_fee ) {
			$items[] = self::prepare_additional_item( OrderModel::META_ENROLLMENT_FEE, 'Enrollment Fee', $enrollment_fee );
		}

		if ( $trial_fee ) {
			$items[] = self::prepare_additional_item( OrderModel::META_ENROLLMENT_FEE, 'Trial Fee', $trial_fee );
		}

		return $items;
	}

	/**
	 * Pay now button text for trial checkout.
	 *
	 * @since 3.5.0
	 *
	 * @param string $pay_now_btn_text pay now button text.
	 *
	 * @return string
	 */
	public function pay_now_btn_text_for_trial_checkout( $pay_now_btn_text ) {
		$plan_id = (int) Input::sanitize_request_data( 'plan' );
		if ( ! $plan_id ) {
			return $pay_now_btn_text;
		}

		$plan_info = $this->plan_model->get_plan( $plan_id );
		if ( ! $plan_info ) {
			return $pay_now_btn_text;
		}

		if ( $plan_info ) {
			$user_subscription = apply_filters( 'tutor_get_user_plan_subscription', null, $plan_info->id, get_current_user_id() );
			$has_trial_period  = $plan_info ? $plan_info->has_trial_period : false;

			if ( $has_trial_period && ! $user_subscription ) {
				$label_interval = $plan_info->trial_value > 1 ? $plan_info->trial_interval . 's' : $plan_info->trial_interval;
				$label_interval = ucwords( $label_interval );

				/* translators: %d: trial value, %s: trial interval */
				$pay_now_btn_text = sprintf( __( 'Start %1$d-%2$s Trial', 'tutor-pro' ), $plan_info->trial_value, $label_interval );
			}
		}

		return $pay_now_btn_text;
	}

	/**
	 * Filter is coupon is applicable for a membership plan.
	 *
	 * @since 3.5.0
	 *
	 * @param bool   $is_applicable is applicable.
	 * @param object $coupon coupon object.
	 * @param int    $object_id object id.
	 * @param array  $applications coupon application ref ids.
	 * @param bool   $is_membership_plan is membership plan.
	 *
	 * @return boolean
	 */
	public function filter_is_coupon_applicable( $is_applicable, $coupon, $object_id, $applications, $is_membership_plan ) {
		if ( ! $is_membership_plan ) {
			return $is_applicable;
		}

		switch ( $coupon->applies_to ) {
			case CouponModel::APPLIES_TO_ALL_MEMBERSHIP_PLANS:
				$is_applicable = true;
				break;

			case CouponModel::APPLIES_TO_SPECIFIC_MEMBERSHIP_PLANS:
				$is_applicable = tutor_utils()->count( $applications ) && in_array( $object_id, $applications );
				break;

			case CouponModel::APPLIES_TO_SPECIFIC_CATEGORY:
				$plan_category_ids = $this->plan_model->get_plan_category_ids( $object_id );
				$is_applicable     = tutor_utils()->count( $applications ) && tutor_utils()->count( array_intersect( $plan_category_ids, $applications ) );
				break;
		}

		return $is_applicable;
	}

	/**
	 * Filter automatic coupon args for membership plan checkout.
	 *
	 * @since 3.5.0
	 *
	 * @param array $args args.
	 *
	 * @return array
	 */
	public function filter_automatic_coupon_args_for_membership_plan_checkout( $args ) {
		$plan_id = (int) Input::sanitize_request_data( 'plan' );
		if ( $plan_id ) {
			$plan_info = $this->plan_model->get_plan( $plan_id );
			if ( $plan_info && isset( $plan_info->is_membership_plan ) && $plan_info->is_membership_plan ) {
				$args['applies_to'] = array(
					CouponModel::APPLIES_TO_ALL_MEMBERSHIP_PLANS,
					CouponModel::APPLIES_TO_SPECIFIC_MEMBERSHIP_PLANS,
					CouponModel::APPLIES_TO_SPECIFIC_CATEGORY,
				);
			}
		}

		return $args;
	}

	/**
	 * Prevent coupon apply on trial checkout.
	 *
	 * @since 3.6.0
	 *
	 * @param string $coupon_code coupon code.
	 * @param string $order_type order type.
	 * @param array  $item_ids item ids.
	 *
	 * @return string
	 */
	public function prevent_coupon_apply_on_trial_checkout( $coupon_code, $order_type, $item_ids ) {
		if ( OrderModel::TYPE_SUBSCRIPTION === $order_type && count( $item_ids ) ) {
			$plan_info = $this->plan_model->get_plan( $item_ids[0] );
			if ( ! $plan_info ) {
				return $coupon_code;
			}

			if ( $plan_info->has_trial_period ) {
				$user_subscription = $this->subscription_model->get_user_subscription_by_plan( $plan_info->id );
				if ( ! $user_subscription ) {
					return '-1';
				}
			}
		}

		return $coupon_code;
	}

	/**
	 * Add a warning message `trial already used` during resubscribe.
	 *
	 * @since 3.5.0
	 *
	 * @return void
	 */
	public function trial_already_used_warning_alert() {
		$plan_id = (int) Input::sanitize_request_data( 'plan' );
		if ( ! $plan_id ) {
			return;
		}

		$plan_info = $this->plan_model->get_plan( $plan_id );
		if ( ! $plan_info ) {
			return;
		}

		$user_id = get_current_user_id();

		$user_subscription = apply_filters( 'tutor_get_user_plan_subscription', null, $plan_info->id, $user_id );
		$is_trial_used     = $user_subscription && $user_subscription->is_trial_used;
		$has_trial_period  = $plan_info ? $plan_info->has_trial_period : false;

		$message = null;
		if ( $has_trial_period && $is_trial_used ) {
			$message = __( "You've already claimed your trial. Purchase the plan now to continue your eLearning journey!", 'tutor-pro' );
		} elseif ( $has_trial_period && $user_subscription && ! $is_trial_used ) {
			$message = __( "You've previously subscribed. Trial is for new users only. Purchase the plan to continue eLearning journey!", 'tutor-pro' );
		}
		?>
		<?php if ( ! empty( $message ) ) : ?>
			<div class="tutor-alert tutor-warning">
				<div class="tutor-alert-text">
					<span class="tutor-alert-icon tutor-fs-4 tutor-icon-circle-info tutor-mr-12"></span>
					<span><?php echo esc_html( $message ); ?></span>
				</div>
			</div>
			<?php endif; ?>
		<?php
	}
}
