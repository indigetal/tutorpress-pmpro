<?php
/**
 * Subscription Exporter.
 *
 * @package TutorPro\Tools
 * @author  Themeum<support@themeum.com>
 * @link    https://themeum.com
 * @since   3.8.0
 */

namespace TutorPro\Tools;

use Tutor\Helpers\QueryHelper;
use TutorPro\Subscription\Models\SubscriptionModel;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handle subscription exporting
 *
 * @since 3.8.0
 */
class SubscriptionExporter {

	/**
	 * List of sub-files relevant for membership plans.
	 *
	 * @since 3.8.1
	 *
	 * @var array
	 */
	private static array $membership_sub_files = array(
		Exporter::PLANS,
		Exporter::ORDERS,
		Exporter::SUBSCRIPTIONS,
	);

	/**
	 * Export subscription data for the given plan IDs.
	 *
	 * @since 3.8.1
	 *
	 * @param array $plan_ids Array of plan IDs.
	 *
	 * @return array Exported subscription data.
	 */
	public static function export( $plan_ids ) {
		return self::get_subscription_data( $plan_ids );
	}

	/**
	 * Retrieve subscription data for the given plan IDs.
	 *
	 * @since 3.8.1
	 *
	 * @param array $plan_ids Array of plan IDs.
	 *
	 * @return array Array of subscription data.
	 */
	private static function get_subscription_data( array $plan_ids ): array {
		global $wpdb;

		$subscription_schema = QueryHelper::get_table_schema( $wpdb->tutor_subscriptions );
		$total_columns       = count( $subscription_schema );

		$result = array();

		foreach ( $plan_ids as $plan_id ) {

			$subscriptions    = SubscriptionModel::get_subscriptions_by_plan_id( $plan_id );
			$subscribed_users = array();
			$last_key         = 0;

			foreach ( $subscriptions as $sub ) {
				$sub_id = $sub['id'];

				if ( empty( $subscribed_users ) || ! in_array( $sub_id, $subscribed_users[ $last_key ] ) ) {
					$subscribed_users[] = array_slice( (array) $sub, 0, $total_columns );
				}

				$last_key = array_key_last( $subscribed_users );

				if ( ! empty( $sub['subscription_id'] ) ) {
					$subscribed_users[ $last_key ]['meta'][] = array_slice( (array) $sub, $total_columns );
				}
			}

			if ( ! empty( $subscribed_users ) ) {
				$result = array_merge( $result, $subscribed_users );
			}
		}

		return $result;
	}

	/**
	 * Get the list of membership sub-files.
	 *
	 * @since 3.8.1
	 *
	 * @return array
	 */
	public function get_sub_files(): array {

		return self::$membership_sub_files;
	}

	/**
	 * Check if a given sub-file belongs to membership plans.
	 *
	 * @since 3.8.1
	 *
	 * @param string $content_type The type of content.
	 * @param string $sub_file The sub-file key.
	 * @return bool True if the sub-file is for membership plans, false otherwise.
	 */
	public static function is_sub_file( string $content_type, string $sub_file ) {

		return Exporter::TYPE_MEMBERSHIP_PLANS === $content_type && in_array( $sub_file, self::$membership_sub_files );
	}
}
