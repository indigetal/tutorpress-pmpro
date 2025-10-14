<?php
/**
 * Handle Membership Plans
 *
 * @package TutorPro\Subscription
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.2.0
 */

namespace TutorPro\Subscription\Controllers;

use Tutor\Helpers\HttpHelper;
use Tutor\Helpers\QueryHelper;
use Tutor\Helpers\ValidationHelper;
use TUTOR\Input;
use Tutor\Traits\JsonResponse;
use TutorPro\Subscription\Models\PlanModel;
use TutorPro\Subscription\Models\SubscriptionModel;
use TutorPro\Subscription\Settings;

/**
 * MembershipController Class.
 *
 * @since 3.2.0
 */
class MembershipController {
	use JsonResponse;

	/**
	 * Plan model instance.
	 *
	 * @var PlanModel
	 */
	private $plan_model;

	/**
	 * Subscription model.
	 *
	 * @var SubscriptionModel
	 */
	private $subscription_model;

	/**
	 * Register hooks and dependencies
	 */
	public function __construct() {
		$this->plan_model         = new PlanModel();
		$this->subscription_model = new SubscriptionModel();

		add_action( 'wp_ajax_tutor_membership_plans', array( $this, 'ajax_get_membership_plans' ) );
		add_action( 'wp_ajax_tutor_membership_plan_save', array( $this, 'ajax_membership_plan_save' ) );
		add_action( 'wp_ajax_tutor_membership_plan_delete', array( $this, 'ajax_membership_plan_delete' ) );
		add_action( 'wp_ajax_tutor_membership_plan_duplicate', array( $this, 'ajax_membership_plan_duplicate' ) );
		add_action( 'tutor_option_save_before', array( $this, 'save_membership_settings' ) );
		add_action( 'admin_init', array( $this, 'create_pricing_page' ) );
	}

	/**
	 * Check nonce and user capability.
	 *
	 * @since 3.2.0
	 *
	 * @return void
	 */
	private function check_nonce_and_capability() {
		tutor_utils()->check_nonce();
		tutor_utils()->check_current_user_capability();
	}

	/**
	 * Get course subscription plans.
	 *
	 * @since 3.2.0
	 *
	 * @return void JSON response.
	 */
	public function ajax_get_membership_plans() {
		$this->check_nonce_and_capability();

		$membership_plans = $this->plan_model->get_membership_plans();

		$this->json_response(
			__( 'Membership plans fetched successfully', 'tutor-pro' ),
			$membership_plans
		);
	}

	/**
	 * Create and update membership plans.
	 *
	 * @since 3.2.0
	 *
	 * @return void
	 */
	public function ajax_membership_plan_save() {
		$this->check_nonce_and_capability();

		$plan_id       = 0;
		$is_update     = false;
		$interval_str  = implode( ',', array_keys( $this->plan_model->get_interval_list() ) );
		$plan_type_str = implode( ',', array( PlanModel::TYPE_FULL_SITE, PlanModel::TYPE_CATEGORY ) );

		$rules = array(
			'id'                 => 'if_input|numeric',
			'plan_type'          => 'required|match_string:' . $plan_type_str,
			'plan_name'          => 'required',
			'regular_price'      => 'required|numeric',

			'recurring_value'    => 'required|numeric',
			'recurring_interval' => 'required|match_string:' . $interval_str,

			'sale_price'         => 'if_input|numeric',
			'sale_price_from'    => 'if_input|date_format:Y-m-d H:i:s',
			'sale_price_to'      => 'if_input|date_format:Y-m-d H:i:s',
			'trial_value'        => 'if_input|numeric',
			'trial_interval'     => 'if_input|match_string:' . $interval_str,
			'trial_fee'          => 'if_input|numeric',
			'recurring_limit'    => 'if_input|numeric',
			'enrollment_fee'     => 'if_input|numeric',
			'is_featured'        => 'if_input|numeric',
			'tax_collection'     => 'if_input|numeric',
		);

		if ( PlanModel::TYPE_CATEGORY === Input::post( 'plan_type' ) ) {
			$rules['cat_ids'] = 'required|is_array';
		}

		$inputs     = Input::sanitize_array( $_POST );//phpcs:ignore
		$validation = ValidationHelper::validate( $rules, $inputs );

		$errors = array();
		if ( ! $validation->success ) {
			$errors = $validation->errors;
		}

		$inputs['payment_type'] = $this->plan_model::PAYMENT_RECURRING;

		// Update mode.
		if ( isset( $inputs['id'] ) ) {
			$is_update = true;
			$plan_id   = (int) $inputs['id'];

			if ( ! Input::has( 'is_featured' ) ) {
				$inputs['is_featured']   = 0;
				$inputs['featured_text'] = '';
			}

			if ( ! Input::has( 'sale_price' ) ) {
				$inputs['sale_price'] = 0;
			}

			if ( ! Input::has( 'sale_price_from' ) ) {
				$inputs['sale_price_from'] = null;
			}

			if ( ! Input::has( 'sale_price_to' ) ) {
				$inputs['sale_price_to'] = null;
			}

			if ( ! Input::has( 'enrollment_fee' ) ) {
				$inputs['enrollment_fee'] = 0;
			}

			if ( ! Input::has( 'trial_value' ) ) {
				$inputs['trial_value']    = 0;
				$inputs['trial_interval'] = null;
				$inputs['trial_fee']      = 0;
			}
		}

		if ( count( $errors ) > 0 ) {
			$this->json_response( __( 'Invalid inputs', 'tutor-pro' ), $errors, HttpHelper::STATUS_UNPROCESSABLE_ENTITY );
		}

		if ( ! $is_update ) {
			$plan_id = $this->plan_model->create( $inputs );
		} else {
			$plan_id = $this->plan_model->update( $plan_id, $inputs );
		}

		if ( $plan_id && PlanModel::TYPE_CATEGORY === $inputs['plan_type'] ) {
			$cat_ids = array_map( 'intval', $inputs['cat_ids'] ?? array() );
			$cat_ids = array_unique( $cat_ids );
			$this->plan_model->attach_categories_to_plan( $plan_id, $cat_ids );
		}

		if ( 1 === Input::post( 'is_featured', 0, Input::TYPE_INT ) ) {
			QueryHelper::update(
				$this->plan_model->get_table_name(),
				array(
					'is_featured'   => 0,
					'featured_text' => '',
				),
				array( 'plan_type' => $this->plan_model::get_membership_plan_types() ),
			);

			// Add plan as featured.
			$this->plan_model->update(
				$plan_id,
				array(
					'is_featured'   => 1,
					'featured_text' => Input::post( 'featured_text', '' ),
				)
			);
		}

		if ( ! $is_update ) {
			$this->json_response(
				__( 'Plan created successfully', 'tutor-pro' ),
				$plan_id,
				HttpHelper::STATUS_CREATED
			);
		} else {
			$this->json_response(
				__( 'Plan updated successfully', 'tutor-pro' ),
				$plan_id
			);
		}
	}

	/**
	 * Delete a membership plan.
	 *
	 * @since 3.2.0
	 *
	 * @return void
	 */
	public function ajax_membership_plan_delete() {
		$this->check_nonce_and_capability();

		$plan_id = Input::post( 'id', 0, Input::TYPE_INT );

		$has_any_subscriber = $this->subscription_model->is_plan_subscribed_by_any_user( $plan_id );
		if ( $has_any_subscriber ) {
			$this->response_bad_request( __( 'This plan has subscribers and cannot be deleted.', 'tutor-pro' ) );
		}

		$deleted = $this->plan_model->delete( $plan_id );
		if ( $deleted ) {
			$this->json_response( __( 'Plan deleted successfully', 'tutor-pro' ) );
		} else {
			$this->response_bad_request( __( 'Something went wrong', 'tutor-pro' ) );
		}
	}

	/**
	 * Duplicate a membership plan.
	 *
	 * @since 3.2.0
	 *
	 * @return void
	 */
	public function ajax_membership_plan_duplicate() {
		tutor_utils()->check_nonce_and_capability();

		$plan_id = Input::post( 'id', 0, Input::TYPE_INT );
		if ( ! $this->plan_model->get_plan( $plan_id ) ) {
			$this->response_bad_request( __( 'Invalid plan', 'tutor-pro' ) );
		}

		$new_id = $this->plan_model->duplicate( $plan_id );
		if ( $new_id ) {
			$this->json_response(
				__( 'Plan duplicated successfully', 'tutor-pro' ),
				$new_id,
				HttpHelper::STATUS_CREATED
			);
		} else {
			$this->response_bad_request( __( 'Something went wrong', 'tutor-pro' ) );
		}
	}

	/**
	 * Save membership settings.
	 *
	 * @since 3.2.0
	 *
	 * @param array $inputs inputs.
	 *
	 * @return void
	 */
	public function save_membership_settings( $inputs ) {
		if ( ! isset( $inputs['membership_settings'] ) ) {
			return;
		}

		$payload = json_decode( wp_unslash( $inputs['membership_settings'] ?? '' ), true );
		$plans   = $payload['plans'] ?? array();

		if ( 0 === count( $plans ) ) {
			return;
		}

		$i = 0;
		foreach ( $plans as $plan ) {
			$plan_id    = isset( $plan['id'] ) ? (int) $plan['id'] : 0;
			$is_enabled = isset( $plan['is_enabled'] ) ? (bool) $plan['is_enabled'] : false;

			$this->plan_model->update(
				$plan_id,
				array(
					'is_enabled' => $is_enabled,
					'plan_order' => $i,
				)
			);

			$i++;
		}
	}

	/**
	 * Create membership pricing page
	 *
	 * @since 3.2.0
	 *
	 * @return void
	 */
	public function create_pricing_page() {
		$page_id = Settings::get_pricing_page_id();
		if ( ! $page_id ) {
			$args = array(
				'post_title'   => __( 'Pricing', 'tutor-pro' ),
				'post_name'    => 'membership-pricing',
				'post_content' => '[tutor_membership_pricing]',
				'post_type'    => 'page',
				'post_status'  => 'publish',
			);

			$page_id = wp_insert_post( $args );

			tutor_utils()->update_option( Settings::PRICING_PAGE_OPTION_NAME, $page_id );
		}
	}
}
