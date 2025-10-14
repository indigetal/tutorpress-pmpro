<?php
/**
 * Handle Subscription Plans
 *
 * @package TutorPro\Subscription
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.0.0
 */

namespace TutorPro\Subscription\Controllers;

use TUTOR\Course;
use Tutor\Helpers\HttpHelper;
use Tutor\Helpers\ValidationHelper;
use TUTOR\Input;
use Tutor\Traits\JsonResponse;
use TutorPro\Subscription\Models\PlanModel;
use TutorPro\Subscription\Models\SubscriptionModel;

/**
 * SubscriptionPlanController Class.
 *
 * @since 3.0.0
 */
class SubscriptionPlanController {
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
	 * Course class instance
	 *
	 * @var Course
	 */
	private $course_cls;

	/**
	 * Register hooks and dependencies
	 */
	public function __construct() {
		$this->course_cls         = new Course( false );
		$this->plan_model         = new PlanModel();
		$this->subscription_model = new SubscriptionModel();

		add_action( 'save_post_' . tutor()->course_post_type, array( $this, 'save_course_meta' ), 10, 2 );
		add_action( 'save_post_' . tutor()->bundle_post_type, array( $this, 'save_course_meta' ), 10, 2 );
		add_filter( 'tutor_course_details_response', array( $this, 'extend_course_details_response' ) );

		add_action( 'wp_ajax_tutor_subscription_plans', array( $this, 'ajax_get_subscription_plans' ) );
		add_action( 'wp_ajax_tutor_subscription_plan_save', array( $this, 'ajax_subscription_plan_save' ) );
		add_action( 'wp_ajax_tutor_subscription_plan_duplicate', array( $this, 'ajax_subscription_plan_duplicate' ) );
		add_action( 'wp_ajax_tutor_subscription_plan_sort', array( $this, 'ajax_subscription_plan_sort' ) );
		add_action( 'wp_ajax_tutor_subscription_plan_delete', array( $this, 'ajax_subscription_plan_delete' ) );

		add_filter( 'tutor_course_mini_info', array( $this, 'add_course_plan_data' ), 10, 2 );
	}

	/**
	 * Save course meta
	 *
	 * @since 3.0.0
	 *
	 * @param integer $post_id post ID.
	 * @param object  $post post object.
	 *
	 * @return void
	 */
	public function save_course_meta( $post_id, $post ) {
		$selling_option = Input::post( 'course_selling_option' );
		if ( ! empty( $selling_option ) && in_array( $selling_option, Course::get_selling_options(), true ) ) {
			update_post_meta( $post_id, Course::COURSE_SELLING_OPTION_META, $selling_option );
		}
	}

	/**
	 * Extend course details response
	 *
	 * @since 3.0.0
	 *
	 * @param array $data response data.
	 *
	 * @return array
	 */
	public function extend_course_details_response( array $data ) {
		$course_id = $data['ID'] ?? 0;
		if ( ! $course_id ) {
			return $data;
		}

		$data['course_selling_option'] = Course::get_selling_option( $course_id );

		return $data;
	}

	/**
	 * Get subscription plans.
	 *
	 * @return void JSON response.
	 */
	public function ajax_get_subscription_plans() {
		tutor_utils()->check_nonce();

		$object_id = Input::post( 'object_id', 0, Input::TYPE_INT );
		$this->course_cls->check_access( $object_id );

		$course_plans = $this->plan_model->get_subscription_plans( $object_id );

		$this->json_response(
			__( 'Subscription plans fetched successfully', 'tutor-pro' ),
			$course_plans
		);
	}

	/**
	 * Create and update subscription plans.
	 *
	 * @since 3.0.0
	 *
	 * @return void
	 */
	public function ajax_subscription_plan_save() {
		tutor_utils()->check_nonce();

		$object_id = Input::post( 'object_id', 0, Input::TYPE_INT );
		$this->course_cls->check_access( $object_id );

		$plan_id      = 0;
		$is_update    = false;
		$interval_str = implode( ',', array_keys( $this->plan_model->get_interval_list() ) );

		$rules = array(
			'id'              => 'if_input|numeric',
			'payment_type'    => 'required|match_string:' . $this->plan_model::PAYMENT_RECURRING,
			'plan_name'       => 'required',
			'regular_price'   => 'required|numeric',

			'sale_price'      => 'if_input|numeric',
			'sale_price_from' => 'if_input|date_format:Y-m-d H:i:s',
			'sale_price_to'   => 'if_input|date_format:Y-m-d H:i:s',
			'trial_value'     => 'if_input|numeric',
			'trial_interval'  => 'if_input|match_string:' . $interval_str,
			'recurring_limit' => 'if_input|numeric',
			'enrollment_fee'  => 'if_input|numeric',
			'is_featured'     => 'if_input|numeric',
		);

		$payment_type = Input::post( 'payment_type' );
		if ( $this->plan_model::PAYMENT_RECURRING === $payment_type ) {
			$rules['recurring_value']    = 'required|numeric';
			$rules['recurring_interval'] = 'required|match_string:' . $interval_str;
		}

		$inputs     = Input::sanitize_array( $_POST );//phpcs:ignore
		$validation = ValidationHelper::validate( $rules, $inputs );

		$errors = array();
		if ( ! $validation->success ) {
			$errors = $validation->errors;
		}

		// Update mode.
		if ( isset( $inputs['id'] ) ) {
			$is_update = true;
			$plan_id   = (int) $inputs['id'];
			if ( ! $this->plan_model->has_subscription_plan( $plan_id, $object_id ) ) {
				$errors['id'] = __( 'Invalid plan', 'tutor-pro' );
			}

			if ( ! Input::has( 'sale_price' ) ) {
				$inputs['sale_price'] = null;
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
		}

		if ( count( $errors ) > 0 ) {
			$this->json_response(
				__( 'Invalid inputs', 'tutor-pro' ),
				$errors,
				HttpHelper::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		if ( ! $is_update ) {
			$plan_id = $this->plan_model->create_subscription_plan( $object_id, $inputs );
		} else {
			$plan_id = $this->plan_model->update( $plan_id, $inputs );
		}

		if ( 1 === Input::post( 'is_featured', 0, Input::TYPE_INT ) ) {
			$featured_text = Input::post( 'featured_text', __( 'Featured', 'tutor-pro' ) );
			$this->plan_model->set_subscription_plan_as_featured( $object_id, $plan_id, $featured_text );
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
	 * Duplicate a subscription plan.
	 *
	 * @since 3.0.0
	 *
	 * @return void
	 */
	public function ajax_subscription_plan_duplicate() {
		tutor_utils()->check_nonce();

		$object_id = Input::post( 'object_id', 0, Input::TYPE_INT );
		$this->course_cls->check_access( $object_id );

		$plan_id = Input::post( 'id', 0, Input::TYPE_INT );
		if ( ! $this->plan_model->has_subscription_plan( $plan_id, $object_id ) ) {
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
	 * Plan sorting
	 *
	 * @since 3.0.0
	 *
	 * @return void JSON response
	 */
	public function ajax_subscription_plan_sort() {
		tutor_utils()->check_nonce();

		$object_id = Input::post( 'object_id', 0, Input::TYPE_INT );
		$this->course_cls->check_access( $object_id );

		$plan_ids = Input::post( 'plan_ids', array(), Input::TYPE_ARRAY );
		$plan_ids = array_filter( $plan_ids, 'is_numeric' );

		if ( 0 === count( $plan_ids ) ) {
			$this->response_bad_request( __( 'Invalid plan ids', 'tutor-pro' ) );
		}

		foreach ( $plan_ids as $i => $plan_id ) {
			$this->plan_model->update( $plan_id, array( 'plan_order' => $i + 1 ) );
		}

		$this->json_response( __( 'Plan order updated successfully', 'tutor-pro' ) );
	}

	/**
	 * Delete a subscription plan.
	 *
	 * @since 3.0.0
	 *
	 * @return void
	 */
	public function ajax_subscription_plan_delete() {
		tutor_utils()->check_nonce();

		$object_id = Input::post( 'object_id', 0, Input::TYPE_INT );
		$this->course_cls->check_access( $object_id );

		$plan_id = Input::post( 'id', 0, Input::TYPE_INT );
		if ( ! $this->plan_model->has_subscription_plan( $plan_id, $object_id ) ) {
			$this->response_bad_request( __( 'Invalid plan', 'tutor-pro' ) );
		}

		$has_plan_user = $this->subscription_model->is_plan_subscribed_by_any_user( $plan_id );
		if ( $has_plan_user ) {
			$this->response_bad_request( __( 'This plan is already subscribed by some users', 'tutor-pro' ) );
		}

		$deleted = $this->plan_model->delete( $plan_id );
		if ( $deleted ) {
			$this->json_response( __( 'Plan deleted successfully', 'tutor-pro' ) );
		} else {
			$this->response_bad_request( __( 'Something went wrong', 'tutor-pro' ) );
		}
	}

	/**
	 * Filter to add course plan data.
	 *
	 * @since 3.0.0
	 *
	 * @param array  $info info.
	 * @param object $post post data.
	 *
	 * @return array
	 */
	public function add_course_plan_data( $info, $post ) {
		if ( Course::PRICE_TYPE_PAID !== tutor_utils()->price_type( $post->ID ) ) {
			return $info;
		}

		$course_plans = $this->plan_model->get_subscription_plans( $post->ID, PlanModel::STATUS_ACTIVE );
		if ( is_array( $course_plans ) && count( $course_plans ) > 0 ) {
			// Get lowest price from plans array.
			$start_price = array_reduce(
				$course_plans,
				function ( $lowest, $item ) {
					if ( isset( $item->regular_price ) && ( null === $lowest || $item->regular_price < $lowest ) ) {
						$lowest = $item->regular_price;
					}
					return $lowest;
				},
				null
			);

			if ( $start_price ) {
				$info['plan_start_price'] = tutor_get_formatted_price( $start_price );
			}

			$info['plans'] = $course_plans;
		}

		return $info;
	}
}
