<?php
/**
 * Hook Handler for Subscriptions.
 *
 * @package TutorPro\Subscription
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.5.0
 */

namespace TutorPro\Subscription;

use Tutor\Models\CouponModel;
use TutorPro\Subscription\Models\PlanModel;

/**
 * HookHandler Class.
 *
 * @since 3.5.0
 */
class HookHandler {
	/**
	 * Register hooks and dependency
	 *
	 * @since 3.5.0
	 *
	 * @param bool $register_hooks register hooks if true.
	 */
	public function __construct( $register_hooks = true ) {
		if ( ! $register_hooks ) {
			return;
		}

		add_filter( 'tutor_coupon_details_applies_to_items_response', array( $this, 'filter_applies_to_items_response' ), 10, 2 );
		add_filter( 'tutor_coupon_applies_to', array( $this, 'filter_coupon_applies_to' ) );

	}

	/**
	 * Filter applies to items response for specific membership plans.
	 *
	 * @since 3.5.0
	 *
	 * @param array  $response response.
	 * @param object $coupon coupon object.
	 *
	 * @return array
	 */
	public function filter_applies_to_items_response( $response, $coupon ) {
		if ( ! in_array( $coupon->applies_to, array( CouponModel::APPLIES_TO_SPECIFIC_MEMBERSHIP_PLANS ), true ) ) {
			return $response;
		}

		$coupon_model    = new CouponModel();
		$plan_model      = new PlanModel();
		$application_ids = $coupon_model->get_coupon_applications( $coupon->coupon_code );

		$response = $plan_model->get_all( array( 'id' => $application_ids ) );

		return $response;
	}

	/**
	 * Filter coupon applies to.
	 *
	 * @since 3.5.0
	 *
	 * @param array $list list of applies to items.
	 *
	 * @return array
	 */
	public function filter_coupon_applies_to( $list ) {
		$list[ CouponModel::APPLIES_TO_ALL_MEMBERSHIP_PLANS ]      = __( 'All Membership Plans', 'tutor-pro' );
		$list[ CouponModel::APPLIES_TO_SPECIFIC_MEMBERSHIP_PLANS ] = __( 'Specific Membership Plans', 'tutor-pro' );

		return $list;
	}


}
