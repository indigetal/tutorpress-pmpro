<?php
/**
 * Handler of manual subscription enrollment.
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
use Tutor\Models\OrderModel;
use Tutor\Traits\JsonResponse;
use TUTOR_ENROLLMENTS\Enrollments;
use TutorPro\Subscription\Models\PlanModel;
use TutorPro\Subscription\Models\SubscriptionModel;
use TutorPro\Subscription\Settings;
use TutorPro\Subscription\Subscription;

/**
 * ManualSubscriptionController Class.
 *
 * @since 3.5.0
 */
class ManualSubscriptionController {
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
	 * Register hooks and dependencies
	 *
	 * @since 3.5.0
	 *
	 * @param bool $register_hooks whether to register hooks or not.
	 */
	public function __construct( $register_hooks = true ) {
		$this->subscription_model = new SubscriptionModel();
		$this->order_model        = new OrderModel();
		$this->order_ctrl         = new OrderController( false );
		$this->plan_model         = new PlanModel();

		if ( ! $register_hooks ) {
			return;
		}

		add_filter( 'tutor_manual_enrollment', array( $this, 'manual_subscription_enrollment' ), 10, 4 );
		add_filter( 'tutor_unenrolled_users_response', array( $this, 'unenrolled_users_response_for_subscription' ), 10, 2 );
		add_filter( 'tutor_get_course_list_filter_args', array( $this, 'filter_manual_enrollment_course_bundle_list' ) );
	}

	/**
	 * Manual subscription enrollment
	 *
	 * @since 3.5.0
	 *
	 * @param array  $enrollment_data enrollment data.
	 * @param array  $object_ids object ids.
	 * @param array  $student_ids student ids.
	 * @param string $payment_status payment status.
	 *
	 * @return array
	 */
	public function manual_subscription_enrollment( $enrollment_data, $object_ids, $student_ids, $payment_status ) {
		if ( OrderModel::TYPE_SUBSCRIPTION === Input::post( 'order_type' ) && tutor_utils()->is_monetize_by_tutor() ) {
			$plan_id = $object_ids[0] ?? 0;
			$plan    = apply_filters( 'tutor_get_plan_info', null, $plan_id );
			if ( ! $plan ) {
				return $enrollment_data;
			}

			$enrollment_class   = new Enrollments( false );
			$subscription_model = new SubscriptionModel();
			$plan_model         = new PlanModel();
			$total_enrollments  = 0;
			$failed_enrollments = array();

			foreach ( $student_ids as $student_id ) {

				if ( $subscription_model->is_subscribed( $plan_id, $student_id ) ) {
					$failed_enrollments[] = $enrollment_class->get_failed_user_data( $student_id );
					continue;
				}

				$item = array(
					'item_id'        => $plan_id,
					'regular_price'  => $plan->regular_price,
					'sale_price'     => $plan_model->in_sale_price( $plan ) ? $plan->sale_price : null,
					'discount_price' => null,
				);

				try {
					add_filter( 'tutor_apply_plan_trial', fn( $bool ) => false );

					$args = array(
						'payment_method' => OrderModel::PAYMENT_METHOD_MANUAL,
						'note'           => __( 'Order created for manual subscription', 'tutor-pro' ),
					);

					$sale_discount_amount = isset( $item['sale_price'] ) ? $item['regular_price'] - $item['sale_price'] : 0;
					if ( $sale_discount_amount ) {
						$args['discount_amount'] = $sale_discount_amount;
					}

					$order_id = $this->order_ctrl->create_order(
						$student_id,
						$item,
						$payment_status,
						OrderModel::TYPE_SUBSCRIPTION,
						null,
						$args
					);

					if ( ! $order_id ) {
						$failed_enrollments[] = $enrollment_class->get_failed_user_data( $student_id );
						continue;
					}

					if ( OrderModel::PAYMENT_PAID === $payment_status ) {
						do_action( 'tutor_order_payment_status_changed', $order_id, OrderModel::PAYMENT_UNPAID, $payment_status );
					}

					$total_enrollments++;

				} catch ( \Throwable $th ) {
					$this->response_bad_request( $th->getMessage() );
				}
			}

			$enrollment_data = array(
				'message'                    => __( 'Subscription added for selected students', 'tutor-pro' ),
				'is_subscription_enrollment' => true,
				'failed_enrollment_list'     => $failed_enrollments,
				'total_enrolled_students'    => $total_enrollments,

			);
		}

		return $enrollment_data;
	}

	/**
	 * Filter unenrolled users response for subscription
	 *
	 * @since 3.5.0
	 *
	 * @param array $response response.
	 * @param int   $object_id object id.
	 *
	 * @return array
	 */
	public function unenrolled_users_response_for_subscription( $response, $object_id ) {
		if ( OrderModel::TYPE_SUBSCRIPTION === Input::post( 'order_type' ) ) {
			$response['results'] = array_map(
				function ( $user ) use ( $object_id ) {
					if ( $this->subscription_model->is_subscribed( $object_id, $user->ID ) ) {
						$user->is_enrolled       = 1;
						$user->enrollment_status = __( 'Already Subscribed', 'tutor-pro' );
					}
					return $user;
				},
				$response['results']
			);
		}

		return $response;
	}

	/**
	 * Filter manual enrollment course bundle list.
	 *
	 * @since 3.8.0
	 *
	 * @param array $args args.
	 *
	 * @return array
	 */
	public function filter_manual_enrollment_course_bundle_list( $args ) {
		if ( 'tutor_course_bundle_list' !== Input::post( 'action' ) ) {
			return $args;
		}

		// Filter to exclude membership only selling option courses.
		$args['meta_query'] = array(
			'relation' => 'OR',
			array(
				'key'     => Course::COURSE_SELLING_OPTION_META,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => Course::COURSE_SELLING_OPTION_META,
				'value'   => array( Course::SELLING_OPTION_MEMBERSHIP ),
				'compare' => 'NOT IN',
			),
		);

		return $args;
	}
}
