<?php
/**
 * Order Importer
 *
 * @package TutorPro\Tools
 * @author  Themeum<support@themeum.com>
 * @link    https://themeum.com
 * @since   3.8.1
 */

namespace TutorPro\Tools;

use Tutor\Helpers\QueryHelper;
use Tutor\Models\OrderModel;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OrderImporter class
 */
class OrderImporter {

	/**
	 * Order Table
	 *
	 * @var string
	 */
	private $order_table = 'tutor_orders';

	/**
	 * Order meta table
	 *
	 * @var string
	 */
	private $order_meta_table = 'tutor_ordermeta';

	/**
	 * Order Items table
	 *
	 * @var string
	 */
	private $order_items_table = 'tutor_order_items';

	/**
	 * Earnings table
	 *
	 * @var string
	 */
	private $earnings_table = 'tutor_earnings';

	/**
	 * Customer table
	 *
	 * @var string
	 */
	private $customer_table = 'tutor_customers';

	/**
	 * Subscription table
	 *
	 * @var string
	 */
	private $subscription_table = 'tutor_subscriptions';

	/**
	 * Subscription meta table.
	 *
	 * @var string
	 */
	private $subscription_meta_table = 'tutor_subscriptionmeta';

	/**
	 * Plant table
	 *
	 * @var string
	 */
	private $plan_table = 'tutor_subscription_plans';

	/**
	 * Plan items table
	 *
	 * @var string
	 */
	private $plan_item_table = 'tutor_subscription_plan_items';

	/**
	 * Order item meta table
	 *
	 * @var string
	 */
	private $order_item_meta_table = 'tutor_order_itemmeta';


	/**
	 * Prepare order item meta.
	 *
	 * @since 3.8.1
	 *
	 * @param array $order_item_meta the order item meta array.
	 * @param int   $order_id the order id.
	 *
	 * @return array
	 */
	public function prepare_order_item_meta( $order_item_meta, $order_id ) {
		$meta = array();

		foreach ( $order_item_meta as $order_item ) {
			unset( $order_item['id'] );
			$order_item['item_id']    = $order_id;
			$order_item['meta_value'] = maybe_serialize( $order_item['meta_value'] );

			$meta[] = $order_item;
		}

		return $meta;
	}


	/**
	 * Insert tutor orders.
	 *
	 * @since 3.8.1
	 *
	 * @throws \Throwable If data cannot be inserted.
	 *
	 * @param array $orders_data the array of order data.
	 *
	 * @return void
	 */
	public function insert_order( array $orders_data ) {
		$content_map = ContentMapHandler::get_content_map();
		$course_map  = $content_map['courses'] ?? null;
		$user_map    = $content_map['users'] ?? null;
		$orders_map  = $content_map['orders'] ?? null;
		$plans_map   = $content_map['plans'] ?? null;

		unset( $content_map );

		$orders          = $orders_data['orders'];
		$order_item      = $orders_data['order_items'];
		$order_meta      = $orders_data['order_meta'];
		$earnings        = $orders_data['earnings'];
		$order_item_meta = $orders_data['order_item_meta'];

		unset( $orders_data );

		$user_id = (int) $orders['user_id'] ?? 0;

		$orders['parent_id'] = $orders_map[ $orders['parent_id'] ] ?? $orders['parent_id'];

		$orders['created_by'] = $user_map[ $orders['created_by'] ] ?? get_current_user_id();
		$orders['updated_by'] = $user_map[ $orders['updated_by'] ] ?? get_current_user_id();

		$previous_order_id = $orders['id'];

		if ( ! isset( $user_map[ $user_id ] ) && get_current_user_id() !== $user_id ) {
			ErrorHandler::set_error( __( 'Order', 'tutor-pro' ), __( 'User not found for order ID : ', 'tutor-pro' ) . $previous_order_id );
			return;
		}

		$orders['user_id'] = $user_map[ $user_id ];

		unset( $orders['id'] );

		try {
			$order_id = QueryHelper::insert( $this->order_table, $orders );
		} catch ( \Throwable $th ) {
			throw $th;
		}

		if ( $orders_map ) {
			$enrollment_id = $orders_map['enrollments'][ $previous_order_id ] ?? 0;

			if ( $enrollment_id ) {
				update_post_meta( $enrollment_id, '_tutor_enrolled_by_order_id', $order_id );
			}

			$orders_map[ $previous_order_id ] = $order_id;

			ContentMapHandler::update_content_map( 'orders', $orders_map );
		} else {
			$orders_map = array( $previous_order_id => $order_id );
			ContentMapHandler::set_content_map( 'orders', $orders_map );
		}

		if ( isset( $orders_map[ $orders['parent_id'] ] ) ) {
			// Update parent id.
			try {
				$result = QueryHelper::update( $this->order_table, array( 'parent_id' => $orders_map[ $orders['parent_id'] ] ), array( 'id' => $order_id ) );
			} catch ( \Throwable $th ) {
				ErrorHandler::set_error( __( 'Order', 'tutor-pro' ), $th->getMessage() );
			}
		}

		if ( OrderModel::TYPE_SINGLE_ORDER === $orders['order_type'] ) {
			$order_item['item_id'] = $course_map[ $order_item['item_id'] ]['course_id'] ?? $order_item['item_id'];
		}

		if ( OrderModel::TYPE_SUBSCRIPTION === $orders['order_type'] || OrderModel::TYPE_RENEWAL === $orders['order_type'] ) {
			$order_item['item_id'] = $plans_map[ $order_item['item_id'] ] ?? $order_item['item_id'];
		}

		$order_item['order_id'] = $orders_map[ $previous_order_id ];

		unset( $order_item['order_items_id'] );

		try {
			$order_item_id = QueryHelper::insert( $this->order_items_table, $order_item );
		} catch ( \Throwable $th ) {
			throw $th;
		}

		unset( $order_item );
		unset( $orders );

		// Prepare order meta.
		if ( $order_meta ) {
			foreach ( $order_meta as $key => $meta ) {

				unset( $meta['id'] );

				// Handle tutor customer data.
				if ( 'billing_address' === $meta['meta_key'] ) {
					$billing_address = json_decode( $meta['meta_value'], true );

					unset( $billing_address['id'] );

					$billing_address['user_id'] = $user_map[ $billing_address['user_id'] ] ?? get_current_user_id();

					try {
						$billing_id = QueryHelper::insert( $this->customer_table, $billing_address );
					} catch ( \Throwable $th ) {
						ErrorHandler::set_error( __( 'Order Meta', 'tutor-pro' ), $th->getMessage() );
					}

					$billing_address['id'] = $billing_id;

					$meta['meta_value'] = addslashes( json_encode( $billing_address ) );
				}

				$meta['order_id'] = $orders_map[ $previous_order_id ];

				$meta['created_by'] = $user_map[ $meta['created_by'] ] ?? get_current_user_id();
				$meta['updated_by'] = $user_map[ $meta['updated_by'] ] ?? get_current_user_id();

				$order_meta[ $key ] = $meta;
			}
		}

		try {
			$result = QueryHelper::insert_multiple_rows( $this->order_meta_table, $order_meta, false, false );
		} catch ( \Throwable $th ) {
			ErrorHandler::set_error( __( 'Order Meta', 'tutor-pro' ), $th->getMessage() );
		}

		unset( $order_meta );

		// Prepare order item meta.
		$order_item_meta = $this->prepare_order_item_meta( $order_item_meta, $order_item_id );

		try {
			$result = QueryHelper::insert_multiple_rows( $this->order_item_meta_table, $order_item_meta, false, false );
		} catch ( \Throwable $th ) {
			ErrorHandler::set_error( __( 'Order Item Meta', 'tutor-pro' ), $th->getMessage() );
		}

		unset( $order_item_meta );

		// Prepare order earnings.
		if ( $earnings ) {
			foreach ( $earnings as $key => $earning ) {
				unset( $earning['earning_id'] );

				$earning['user_id']  = $user_map[ $earning['user_id'] ] ?? get_current_user_id();
				$earning['order_id'] = $orders_map[ $previous_order_id ];
				$earning_course_id   = $earning['course_id'] ?? 0;

				$earning['course_id'] = $course_map[ $earning_course_id ]['course_id'] ?? $earning_course_id;

				$earnings[ $key ] = $earning;
			}
		}

		try {
			$result = QueryHelper::insert_multiple_rows( $this->earnings_table, $earnings, false, false );
		} catch ( \Throwable $th ) {
			ErrorHandler::set_error( __( 'Earnings', 'tutor-pro' ), $th->getMessage() );
		}

		unset( $earnings );
	}

	/**
	 * Insert tutor subscriptions plans.
	 *
	 * @since 3.8.1
	 *
	 * @throws \Throwable If cannot be inserted.
	 *
	 * @param array $plan_data the plan data.
	 *
	 * @return int
	 */
	public function insert_plan( array $plan_data ) {
		$content_map = ContentMapHandler::get_content_map();

		$plan_map     = $content_map['plans'];
		$course_map   = $content_map['courses'];
		$category_map = $content_map['categories'];

		unset( $content_map );

		$old_plan_id = $plan_data['id'];
		$items       = $plan_data['items'];
		unset( $plan_data['id'] );
		unset( $plan_data['items'] );

		try {
			$plan_id = QueryHelper::insert( $this->plan_table, $plan_data );
			if ( ! $plan_map ) {
				ContentMapHandler::set_content_map( 'plans', array( $old_plan_id => $plan_id ) );
			} else {
				$plan_map[ $old_plan_id ] = $plan_id;
				ContentMapHandler::update_content_map( 'plans', $plan_map );
			}

			if ( $items ) {
				foreach ( $items as $key => $item ) {
					$item['plan_id'] = $plan_id;

					if ( 'category' === $item['object_name'] ) {
						$item['object_id'] = $category_map[ $item['object_id'] ] ?? $item['object_id'];
					} else {
						$item['object_id'] = $course_map[ $item['object_id'] ]['course_id'] ?? $item['object_id'];
					}
					$items[ $key ] = $item;
				}

				try {
					$result = QueryHelper::insert_multiple_rows( $this->plan_item_table, $items, false, false );
				} catch ( \Throwable $th ) {
					ErrorHandler::set_error( __( 'Subscription Plan', 'tutor-pro' ), $th->getMessage() );
				}
			}
		} catch ( \Throwable $th ) {
			throw $th;
		}

		return $plan_id;
	}

	/**
	 * Insert tutor subscription and subscription meta data.
	 *
	 * @since 3.8.1
	 *
	 * @throws \Throwable If cannot insert data.
	 *
	 * @param array $subscription array of subscription data.
	 *
	 * @return void
	 */
	public function insert_subscription( array $subscription ) {

		$content_map = ContentMapHandler::get_content_map();

		$user_map         = $content_map['users'];
		$orders_map       = $content_map['orders'];
		$plans_map        = $content_map['plans'];
		$subscription_map = $content_map['subscriptions'];

		unset( $content_map );

		$meta = $subscription['meta'] ?? array();

		$previous_subscription_id = $subscription['id'] ?? 0;

		$user_id = (int) $subscription['user_id'] ?? 0;

		if ( ! isset( $user_map[ $user_id ] ) && get_current_user_id() !== $user_id ) {
			ErrorHandler::set_error( __( 'Subscription', 'tutor-pro' ), __( 'User not found for subscription ID : ', 'tutor-pro' ) . $previous_subscription_id );
			return;
		}

		$subscription['user_id']         = $user_map[ $subscription['user_id'] ];
		$subscription['plan_id']         = $plans_map[ $subscription['plan_id'] ] ?? $subscription['plan_id'];
		$subscription['first_order_id']  = $orders_map[ $subscription['first_order_id'] ] ?? $subscription['first_order_id'];
		$subscription['active_order_id'] = $orders_map[ $subscription['active_order_id'] ] ?? $subscription['active_order_id'];

		unset( $subscription['meta'] );
		unset( $subscription['id'] );

		try {
			$subscription_id = QueryHelper::insert( $this->subscription_table, $subscription );
		} catch ( \Throwable $th ) {
			throw $th;
		}

		if ( $subscription_map ) {
			$enrollment_id = $subscription_map['enrollments'][ $previous_subscription_id ] ?? 0;

			if ( $enrollment_id ) {
				update_post_meta( $enrollment_id, '_tutor_subscription_id', $subscription_id );
			}

			$subscription_map[ $previous_subscription_id ] = $subscription_id;

			ContentMapHandler::update_content_map( 'subscriptions', $subscription_map );
		}

		unset( $subscription_map );

		if ( $meta ) {
			// Prepare meta.
			foreach ( $meta as $key => $sub_meta ) {
				if ( ! $sub_meta ) {
					return;
				}
				$sub_meta['subscription_id'] = $subscription_id;

				if ( 'plan_info' === $sub_meta['meta_key'] ) {
					$plan_info              = $sub_meta['meta_value'];
					$plan_info['id']        = $plan_map[ $plan_info['id'] ] ?? $plan_info['id'];
					$sub_meta['meta_value'] = $plan_info;
				}

				if ( 'history' === $sub_meta['meta_key'] ) {
					$history = json_decode( $sub_meta['meta_value'], true );

					$history['id']              = $subscription_id;
					$history['user_id']         = $user_map[ $history['user_id'] ] ?? $history['user_id'];
					$history['plan_id']         = $plan_map[ $history['plan_id'] ] ?? $history['plan_id'];
					$history['first_order_id']  = $orders_map[ $history['first_order_id'] ] ?? $history['first_order_id'];
					$history['active_order_id'] = $orders_map[ $history['active_order_id'] ] ?? $history['active_order_id'];

					$history_plan = $history['plan'] ?? null;

					if ( $history_plan ) {
						$history_plan['id'] = $plan_map[ $history_plan['id'] ] ?? $history_plan['id'];
					}

					$history['plan'] = $history_plan;

					$sub_meta['meta_value'] = addslashes( json_encode( $history ) );
				}

				$meta[ $key ] = $sub_meta;
			}

			// Insert meta.
			try {
				$result = QueryHelper::insert_multiple_rows( $this->subscription_meta_table, $meta, false, false );
			} catch ( \Throwable $th ) {
				ErrorHandler::set_error( __( 'Subscription Meta', 'tutor-pro' ), $th->getMessage() );
			}

			unset( $meta );
		}
	}
}
