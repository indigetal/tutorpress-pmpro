<?php
/**
 * Order Exporter.
 *
 * @package TutorPro\Tools
 * @author  Themeum<support@themeum.com>
 * @link    https://themeum.com
 * @since   3.8.1
 */

namespace TutorPro\Tools;

use Tutor\Models\OrderModel;
use Tutor\Helpers\QueryHelper;
use Tutor\Models\OrderItemMetaModel;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handle order exporting
 *
 * @since 3.8.1
 */
class OrderExporter {

	/**
	 * Export orders and related details for given IDs.
	 *
	 * @since 3.8.1
	 *
	 * @param int[] $ids       Array of IDs (bundle_id/course_id, plan_ids) to export.
	 * @param int   $course_id Optional. Course ID to calculate earnings. Default is 0.
	 *
	 * @return array Returns an array of structured order data, including:
	 *               - 'orders'      => Order details.
	 *               - 'order_items' => Order item details.
	 *               - 'order_meta'  => Meta data for the order.
	 *               - 'earnings'    => Earnings for the order and course.
	 */
	public static function export( array $ids, $course_id = 0 ): array {

		global $wpdb;

		if ( empty( $ids ) ) {
			return array();
		}

		$order_model           = new OrderModel();
		$order_item_meta_model = new OrderItemMetaModel();
		$order_items           = $order_model->get_order_details( $ids );
		$user_ids              = array();
		$result                = array();

		$total_order_column = count( QueryHelper::get_table_schema( $wpdb->tutor_orders ) );

		// Separate order data, order item data, order_meta and earnings.
		if ( ! empty( $order_items ) ) {
			$result = array_map(
				function ( $item ) use ( $total_order_column, $course_id, &$user_ids, $order_model, $order_item_meta_model ) {

					if ( ! empty( $course_id ) ) {
						array_push( $user_ids, $item->user_id );
					}

					$orders      = array_slice( (array) $item, 0, $total_order_column );
					$order_items = array_slice( (array) $item, $total_order_column );

					// Rename 'item_coupon_code' to 'coupon_code' for consistency with `wp_tutor_order_items` table.
					$order_items['coupon_code'] = $order_items['item_coupon_code'];
					unset( $order_items['item_coupon_code'] );

					return array(
						'orders'          => $orders,
						'order_meta'      => $order_model->get_order_meta_by_order_id( $orders['id'] ),
						'order_items'     => $order_items,
						'order_item_meta' => $order_item_meta_model->get_meta( $order_items['order_items_id'], null, false ),
						'earnings'        => $order_model->get_earnings_by_order_and_course( $orders['id'], $course_id ),
					);
				},
				$order_items
			);
		}

		if ( ! empty( $course_id ) ) {
			update_option( Exporter::OPTION_USER_IDS . $course_id, array_unique( $user_ids ) );
		}

		return $result;
	}
}
