<?php
/**
 * Subscription & membership report controller
 *
 * @package TutorPro\Subscription
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.3.0
 */

namespace TutorPro\Subscription\Controllers;

use TUTOR\Input;
use Tutor\Models\OrderModel;
use Tutor\Helpers\QueryHelper;
use TutorPro\Subscription\Models\PlanModel;
use TutorPro\Subscription\Models\SubscriptionModel;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Report controller class
 *
 * @since 3.3.0
 */
class ReportController {

	const PAGE_SLUG         = 'subscriptions';
	const SUBSCRIPTION_TYPE = 'subscription';
	const MEMBERSHIP_TYPE   = 'membership';
	const TUTOR_ENROLLED    = 'tutor_enrolled';
	const STATUS_PUBLISH    = 'publish';
	const STATUS_COMPLETE   = 'completed';
	const MAX_LIMIT         = 999999999999999999;

	/**
	 * Static property that holds the plan model.
	 *
	 * @var PlanModel
	 */
	private static $plan;

	/**
	 * Static property that holds the subscription model.
	 *
	 * @var SubscriptionModel
	 */
	private static $subscription;

	/**
	 * Static property that holds the types of subscription plans.
	 *
	 * @var array
	 */
	private static $subscription_plan_types;

	/**
	 * Static property that holds the types of membership plans.
	 *
	 * @var array
	 */
	private static $membership_plan_types;

	/**
	 * Order table name
	 *
	 * @since 3.3.0
	 *
	 * @var string
	 */
	private $order_table;

	/**
	 * Subscription plan table name
	 *
	 * @since 3.3.0
	 *
	 * @var string
	 */
	private $plan_table;

	/**
	 * Subscription table name
	 *
	 * @since 3.3.0
	 *
	 * @var string
	 */
	private $subscription_table;

	/**
	 * Static property that holds the order model.
	 *
	 * @var OrderModel
	 */
	private static $order;

	/**
	 * Constructor function
	 *
	 * @param bool $register_hooks Whether to register hooks or not.
	 *
	 * @since 3.3.0
	 */
	public function __construct( $register_hooks = true ) {

		if ( $register_hooks ) {
			add_filter( 'tutor_report_sub_pages', array( $this, 'add_report_page' ) );
			add_filter( 'tutor_report_view_file_path', array( $this, 'alter_the_path' ), 10, 2 );
		}

		self::$plan                    = new PlanModel();
		self::$subscription            = new SubscriptionModel();
		self::$subscription_plan_types = self::$plan->get_subscription_plan_types();
		self::$membership_plan_types   = self::$plan->get_membership_plan_types();
		self::$order                   = new OrderModel();

		// Tables.
		$this->order_table        = self::$order->get_table_name();
		$this->subscription_table = self::$subscription->get_table_name();
		$this->plan_table         = self::$plan->get_table_name();
	}

	/**
	 * Add subscription page to report page
	 *
	 * @since 3.3.0
	 *
	 * @param array $sub_pages Report page sub pages.
	 *
	 * @return array
	 */
	public function add_report_page( array $sub_pages ): array {
		// Define subscription page.
		$subscription_page = array(
			self::PAGE_SLUG => __( 'Subscriptions', 'tutor-pro' ),
		);

		// Insert subscription page after first element while preserving array keys.
		$first_element      = array_slice( $sub_pages, 0, 1, true );
		$remaining_elements = array_slice( $sub_pages, 1, null, true );

		return array_merge(
			$first_element,
			$subscription_page,
			$remaining_elements
		);
	}

	/**
	 * Alter the path of the report page
	 *
	 * @since 3.3.0
	 *
	 * @param string $view_file View file path.
	 * @param string $subpage Report page sub page.
	 *
	 * @return string
	 */
	public function alter_the_path( string $view_file, string $subpage ): string {
		if ( self::PAGE_SLUG === $subpage ) {
			$view_file = TUTOR_SUBSCRIPTION_DIR . 'views/pages/report.php';
		}

		return $view_file;
	}

	/**
	 * Get plan types
	 *
	 * @since 3.3.0
	 *
	 * @return array
	 */
	public static function get_subscription_sorting_dropdown_list(): array {
		return array(
			'all'                 => __( 'All', 'tutor-pro' ),
			'course_subscription' => __( 'Single Course Subscription', 'tutor-pro' ),
			'bundle_subscription' => __( 'Bundle Subscriptions', 'tutor-pro' ),
			'membership'          => __( 'Only Memberships', 'tutor-pro' ),
		);
	}

	/**
	 * Get the count of active subscriptions and memberships.
	 *
	 * @since 3.3.0
	 *
	 * @return array The count of active subscriptions and memberships.
	 */
	public static function get_active_subscriptions_count(): array {

		$active_subscriptions = self::$subscription->get_subscriptions( array( 'status' => self::$subscription::STATUS_ACTIVE ), '', self::MAX_LIMIT );

		$grouped_subscriptions = self::$subscription->group_subscriptions_by_plan_type( $active_subscriptions );
		$subscriptions_counts  = self::aggregate_subscription_counts( $grouped_subscriptions );

		return array(
			'active_subscriptions_count' => $subscriptions_counts['subscriptions_count'],
			'active_memberships_count'   => $subscriptions_counts['memberships_count'],
		);
	}

	/**
	 * Get the total revenue from subscriptions or memberships by type.
	 *
	 * @since 3.3.0
	 *
	 * @param string $type The type of revenue to calculate.
	 *
	 * @return string The total revenue for the specified type.
	 */
	public static function get_total_subscription_revenue_by_type( $type ): string {

		if ( self::MEMBERSHIP_TYPE === $type ) {
			$total_revenue = self::get_total_full_site_memberships_earnings( self::$membership_plan_types );
		} elseif ( self::SUBSCRIPTION_TYPE === $type ) {
			$total_revenue = self::get_total_earnings_by_plan_types( self::$subscription_plan_types );
		}

		return $total_revenue;
	}

	/**
	 * Get the count of expired subscriptions and memberships.
	 *
	 * @since 3.3.0
	 *
	 * @return array The count of expired subscriptions and memberships
	 */
	public static function get_expired_subscription_count(): array {

		$expired_subscriptions = self::$subscription->get_subscriptions( array( 'status' => self::$subscription::STATUS_EXPIRED ), '', self::MAX_LIMIT );
		$grouped_subscriptions = self::$subscription->group_subscriptions_by_plan_type( $expired_subscriptions );
		$subscriptions_counts  = self::aggregate_subscription_counts( $grouped_subscriptions );

		return array(
			'expired_subscriptions_count' => $subscriptions_counts['subscriptions_count'],
			'expired_memberships_count'   => $subscriptions_counts['memberships_count'],
		);
	}

	/**
	 * Aggregate the count of subscriptions and memberships from grouped data.
	 *
	 * @since 3.3.0
	 *
	 * @param array $subscriptions The grouped subscriptions data.
	 *
	 * @return array The count of memberships and subscriptions.
	 */
	private static function aggregate_subscription_counts( $subscriptions ): array {

		$memberships_count   = 0;
		$subscriptions_count = 0;

		foreach ( $subscriptions as $plan_type => $subscription ) {

			if ( in_array( $plan_type, self::$membership_plan_types, true ) ) {
				$memberships_count += count( $subscription );
			}

			if ( in_array( $plan_type, self::$subscription_plan_types, true ) ) {
				$subscriptions_count += count( $subscription );
			}
		}

		return array(
			'memberships_count'   => $memberships_count,
			'subscriptions_count' => $subscriptions_count,
		);
	}


	/**
	 * Get the top subscriptions and memberships by their respective types.
	 *
	 * @since 3.3.0
	 *
	 * @return array The details of top subscriptions and top memberships.
	 */
	public static function get_top_subscriptions_by_types(): array {

		$top_subscriptions = self::get_top_subscription_details( self::$subscription_plan_types ) ?? array();
		$top_memberships   = self::get_top_full_site_memberships( 3 ) ?? array();

		return array(
			'top_subscriptions' => $top_subscriptions,
			'top_memberships'   => $top_memberships,
		);
	}

	/**
	 * Get refund list with total count
	 *
	 * @since 3.3.0
	 *
	 * @param string $period Time period.
	 * @param string $start_date Start date.
	 * @param string $end_date End date.
	 * @param string $subscription_type Course|Bundle|Membership.
	 *
	 * @return array [refunds => [obj, obj], total_refund => 100]
	 */
	public function get_refunds( string $period = '', $start_date = '', string $end_date = '', string $subscription_type = '' ): array {
		global $wpdb;

		$period     = sanitize_text_field( $period );
		$start_date = sanitize_text_field( $start_date );
		$end_date   = sanitize_text_field( $end_date );

		$period_query = '';
		$group_query  = ' GROUP BY DATE(o.created_at_gmt) ';

		// set additional query for period or date range.
		if ( '' !== $start_date && '' !== $end_date ) {
			$period_query = " AND  DATE(o.created_at_gmt) BETWEEN CAST('$start_date' AS DATE) AND CAST('$end_date' AS DATE) ";
			$group_query  = ' GROUP BY DATE(o.created_at_gmt) ';
		} elseif ( ! empty( $period ) ) {
			$period_query = QueryHelper::get_period_clause( 'o.created_at_gmt', $period );
		}

		// period query.
		if ( 'today' !== $period ) {
			$group_query = ' GROUP BY MONTH(o.created_at_gmt) ';
		}

		$subscription_type_query = '';
		$types                   = self::get_subscription_sorting_dropdown_list();

		if ( isset( $types[ $subscription_type ] ) ) {
			$subscription_type_query = self::get_subscription_clause( $subscription_type, 'p.plan_type' );
		}

		//phpcs:disable -- variables are sanitized.
		$refunds = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					COALESCE(SUM(o.refund_amount), 0) AS total,
					DATE(o.created_at_gmt) AS date_format
				FROM {$this->order_table} AS o
				INNER JOIN {$this->subscription_table} AS s
					ON o.id = s.active_order_id
				INNER JOIN {$this->plan_table} AS p
					ON p.id = s.plan_id
				WHERE 1 = %d
				AND o.refund_amount > 0
				{$period_query}
				{$subscription_type_query}
				{$group_query}
				ORDER BY o.created_at_gmt DESC",
				1
			)
		);
		//phpcs:enable

		$total_refunds = 0;

		foreach ( $refunds as $refund ) {
			$total_refunds += $refund->total;
		}

		$response = array(
			'refunds'      => $refunds,
			'total_refund' => $total_refunds,
		);

		return apply_filters( 'tutor_subscription_refund_data', $response, $period, $start_date, $end_date );
	}

	/**
	 * Get the total earnings for subscriptions within a specified period and subscription type.
	 *
	 * @since 3.3.0
	 *
	 * @param string $period            The period for the earnings calculation.
	 * @param string $start_date        The start date for the date range.
	 * @param string $end_date          The end date for the date range.
	 * @param string $subscription_type The subscription type to filter by.
	 *
	 * @throws \Exception If there is an error with the database query.
	 *
	 * @return array The total earnings for the subscriptions.
	 */
	public static function get_total_earnings_for_subscriptions( string $period = '', string $start_date = '', string $end_date = '', $subscription_type = '' ): array {

		global $wpdb;

		$period             = sanitize_text_field( $period );
		$start_date         = sanitize_text_field( $start_date );
		$end_date           = sanitize_text_field( $end_date );
		$subscription_type  = sanitize_text_field( $subscription_type );
		$order_type         = QueryHelper::prepare_in_clause( array( self::$order::TYPE_SUBSCRIPTION, self::$order::TYPE_RENEWAL ) );
		$period_query       = '';
		$subscription_query = '';
		$group_query        = ' GROUP BY DATE(date_format) ';

		// set additional query for period or date range if condition not meet then get all time data.
		if ( '' !== $period ) {
			$period_query = QueryHelper::get_period_clause( 'earnings.created_at', $period );
		}

		if ( 'today' !== $period ) {
			$group_query = ' GROUP BY MONTH(date_format) ';
		}

		if ( '' !== $start_date && '' !== $end_date ) {
			$period_query = " AND DATE(earnings.created_at) BETWEEN CAST('$start_date' AS DATE) AND CAST('$end_date' AS DATE) ";
			$group_query  = ' GROUP BY DATE(date_format) ';
		}

		// Subscription Query.
		$subscription_type  = empty( $subscription_type ) ? 'all' : $subscription_type;
		$subscription_query = self::get_subscription_clause( $subscription_type, 'plans.plan_type' );

		// Get statuses.
		$complete_status = QueryHelper::prepare_in_clause( tutor_utils()->get_earnings_completed_statuses() );

		$amount_type = is_admin() ? 'earnings.admin_amount' : 'earnings.instructor_amount';
		$amount_rate = is_admin() ? 'earnings.admin_rate' : 'earnings.instructor_rate';

		$amount_condition = "CASE
			WHEN orders.tax_type = 'inclusive' AND earnings.course_price_grand_total > 0
				THEN ( earnings.course_price_grand_total - orders.tax_amount ) * ( $amount_rate / 100 )
			ELSE $amount_type
			END";

		//phpcs:disable -- variables are sanitized.
		$earnings = $wpdb->get_results(
			"SELECT
				COALESCE( SUM( $amount_condition ), 0 ) AS total,
				DATE(earnings.created_at) AS date_format
			FROM {$wpdb->prefix}tutor_order_items AS order_items
			LEFT JOIN {$wpdb->prefix}tutor_orders AS orders
				ON orders.id = order_items.order_id
				AND orders.order_type IN ( $order_type)
			LEFT JOIN {$wpdb->prefix}tutor_subscription_plans AS plans
				ON order_items.item_id = plans.id
			LEFT JOIN {$wpdb->prefix}tutor_earnings AS earnings
				ON order_items.order_id = earnings.order_id
				AND earnings.order_status IN ($complete_status)
				{$subscription_query}
			{$period_query}
			{$group_query}
			HAVING total > 0
			ORDER BY earnings.created_at ASC"
		);
		//phpcs:enable

		// If error occurred then throw new exception.
		if ( $wpdb->last_error ) {
			throw new \Exception( $wpdb->last_error );
		}

		$total_earnings = ! empty( $earnings ) ? array_sum( array_column( $earnings, 'total' ) ) : 0;
		return array(
			'earnings'       => $earnings,
			'total_earnings' => $total_earnings ?? 0,
		);
	}

	/**
	 * Get the total number of enrollments for subscriptions within a specified period and subscription type.
	 *
	 * @since 3.3.0
	 *
	 * @param string $period            The period for the enrollment calculation.
	 * @param string $start_date        The start date for the date range.
	 * @param string $end_date          The end date for the date range.
	 * @param string $subscription_type The subscription type to filter by.
	 *
	 * @throws \Exception If there is an error with the database query.
	 *
	 * @return array The total number of enrollments for the subscriptions.
	 */
	public static function get_total_enrollments_for_subscriptions( string $period = '', $start_date = '', string $end_date = '', string $subscription_type = '' ): array {
		global $wpdb;

		$period            = sanitize_text_field( $period );
		$start_date        = sanitize_text_field( $start_date );
		$end_date          = sanitize_text_field( $end_date );
		$subscription_type = sanitize_text_field( $subscription_type );

		empty( $period ) ? $period = 'last30days' : 0;
		$period_query              = '';
		$group_query               = ' GROUP BY DATE(date_format) ';

		// set additional query for period or date range.
		if ( '' !== $period ) {
			$period_query = QueryHelper::get_period_clause( 'subscriptions.created_at_gmt', $period );
		}
		// Period query.
		if ( 'today' !== $period ) {
			$group_query = ' GROUP BY MONTH(date_format) ';
		}

		if ( '' !== $start_date && '' !== $end_date ) {
			$period_query = " AND  DATE(subscriptions.created_at_gmt) BETWEEN CAST('$start_date' AS DATE) AND CAST('$end_date' AS DATE) ";
			$group_query  = ' GROUP BY DATE(date_format) ';
		}

		// Subscription Query.
		$subscription_query = '';
		if ( '' !== $subscription_type ) {
			$subscription_query = self::get_subscription_clause( $subscription_type, 'plans.plan_type' );
		}

		//phpcs:disable -- variables are sanitized.
		$enrollments = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					COALESCE(COUNT(subscriptions.id), 0) AS total, 
					DATE(subscriptions.created_at_gmt) AS date_format
				FROM {$wpdb->prefix}tutor_subscriptions AS subscriptions
				INNER JOIN {$wpdb->prefix}tutor_subscription_plans AS plans
					ON subscriptions.plan_id = plans.id	
				WHERE subscriptions.status = %s	
				{$period_query}
				{$subscription_query}
				{$group_query}",
				self::$subscription::STATUS_ACTIVE
			)
		);
		//phpcs:enable

		// If error occurred then throw new exception.
		if ( $wpdb->last_error ) {
			throw new \Exception( $wpdb->last_error );
		}

		$total_enrollments = ! empty( $enrollments ) ? array_sum( array_column( $enrollments, 'total' ) ) : 0;

		return array(
			'enrollments'       => $enrollments,
			'total_enrollments' => $total_enrollments,
		);
	}

	/**
	 * Get the total earnings for full site membership subscriptions.
	 *
	 * @since 3.3.0
	 *
	 * @param array $plan_type The plan type to filter by.
	 *
	 * @throws \Exception If there is an error with the database query.
	 *
	 * @return string The total earnings for full site memberships.
	 */
	private static function get_total_full_site_memberships_earnings( $plan_type ): string {
		global $wpdb;

		$plan_type   = QueryHelper::prepare_in_clause( $plan_type );
		$amount_type = is_admin() ? 'earnings.admin_amount' : 'earnings.instructor_amount';

		//phpcs:disable -- variables are sanitized.
		$total = $wpdb->get_var(
			"SELECT
				COALESCE( SUM( $amount_type ), 0 ) AS total_revenue,
				plans.id AS plan_id
			FROM {$wpdb->prefix}tutor_order_items AS order_items
			LEFT JOIN {$wpdb->prefix}tutor_subscription_plans AS plans
				ON order_items.item_id = plans.id
			LEFT JOIN {$wpdb->prefix}tutor_earnings AS earnings
				ON order_items.order_id = earnings.order_id
			WHERE plans.plan_type IN ( $plan_type )"
		);
		//phpcs:enable

		// If error occurred then throw new exception.
		if ( $wpdb->last_error ) {
			throw new \Exception( $wpdb->last_error );
		}

		return $total ?? '0';
	}

	/**
	 * Get the total earnings for the specified subscription plan types.
	 *
	 * @since 3.3.0
	 *
	 * @param array|string $plan_types An array or a comma-separated string of subscription plan types.
	 *
	 * @throws \Exception If there is an error with the database query.
	 *
	 * @return string The total earnings for the specified plan types.
	 */
	private static function get_total_earnings_by_plan_types( $plan_types ) {
		global $wpdb;

		$plan_types      = is_array( $plan_types ) ? QueryHelper::prepare_in_clause( $plan_types ) : $plan_types;
		$complete_status = QueryHelper::prepare_in_clause( tutor_utils()->get_earnings_completed_statuses() );
		$order_type      = QueryHelper::prepare_in_clause( array( self::$order::TYPE_SUBSCRIPTION, self::$order::TYPE_RENEWAL ) );
		$amount_type     = is_admin() ? 'earnings.admin_amount' : 'earnings.instructor_amount';

		//phpcs:disable -- variables are sanitized.
		$total = $wpdb->get_var(
			"SELECT
				COALESCE( SUM( $amount_type ), 0 ) AS total
			FROM {$wpdb->prefix}tutor_earnings as earnings
			WHERE EXISTS(
				SELECT 1
				FROM {$wpdb->prefix}tutor_orders AS orders
				WHERE orders.id = earnings.order_id
				and orders.order_type IN( $order_type )
				and orders.order_status IN( {$complete_status} )
			)
			and EXISTS(
				SELECT 1
				FROM {$wpdb->prefix}tutor_subscription_plan_items AS items
				WHERE items.object_id = earnings.course_id
				and items.object_name IN( $plan_types )
			)"
		);
		//phpcs:enable

		// If error occurred then throw new exception.
		if ( $wpdb->last_error ) {
			throw new \Exception( $wpdb->last_error );
		}

		return $total ?? '0';
	}

	/**
	 * Get the top subscription details for the specified plan types.
	 *
	 * @since 3.3.0
	 *
	 * @param array $plan_types An array of subscription plan types.
	 *
	 * @return array An array of top subscription details.
	 */
	private static function get_top_subscription_details( $plan_types ) {

		$top_subscriptions         = array();
		$top_earning_subscriptions = self::get_course_revenue_by_subscription_type( $plan_types, 3 );

		if ( ! empty( $top_earning_subscriptions ) ) {

			foreach ( $top_earning_subscriptions as $subscription ) {

				$details = self::get_course_active_users_by_course_id( $subscription->course_id );

				$top_subscriptions[] = array(
					'course_name'   => $details->course_name ?? '',
					'active_users'  => $details->active_users ?? 0,
					'total_revenue' => $subscription->total_revenue,
				);
			}
		}

		return $top_subscriptions;
	}

	/**
	 * Get the total revenue for courses filtered by subscription plan types.
	 *
	 * @since 3.3.0
	 *
	 * @param array $plan_types An array of subscription plan types.
	 * @param int   $limit      The number of top results to return.
	 *
	 * @throws \Exception If there is an error with the database query.
	 *
	 * @return array An array of top earning courses with details.
	 */
	private static function get_course_revenue_by_subscription_type( $plan_types, int $limit ) {

		global $wpdb;

		$plan_types = is_array( $plan_types ) ? QueryHelper::prepare_in_clause( $plan_types ) : $plan_types;
		$order_type = QueryHelper::prepare_in_clause( array( self::$order::TYPE_SUBSCRIPTION, self::$order::TYPE_RENEWAL ) );

		// Query Variables.
		$amount_type    = is_admin() ? 'earnings.admin_amount' : 'earnings.instructor_amount';
		$group_by_query = ' GROUP BY earnings.course_id ';
		$order_by_query = ' ORDER BY total_revenue DESC ';
		$limit_query    = " LIMIT $limit ";

		//phpcs:disable -- variables are sanitized.
		$result = $wpdb->get_results(
			"SELECT
				COALESCE( SUM( $amount_type ), 0 ) AS total_revenue,
				earnings.course_id AS course_id
			FROM {$wpdb->prefix}tutor_earnings AS earnings
			WHERE EXISTS(
				SELECT 1
				FROM {$wpdb->prefix}tutor_orders AS orders
				WHERE orders.id = earnings.order_id
				and orders.order_type IN( $order_type )
			)
			AND EXISTS(
				SELECT 1
				FROM {$wpdb->prefix}tutor_subscription_plan_items AS items
				WHERE items.object_id = earnings.course_id
				and items.object_name IN( $plan_types )
			)
			{$group_by_query}
			{$order_by_query}
			{$limit_query}",
		);
		//phpcs:enable

		// If error occurred then throw new exception.
		if ( $wpdb->last_error ) {
			throw new \Exception( $wpdb->last_error );
		}

		return $result;
	}

	/**
	 * Get active uses of a course by its ID.
	 *
	 * @since 3.3.0
	 *
	 * @param int $id   The course ID to retrieve details for.
	 *
	 * @throws \Exception If there is an error with the database query.
	 *
	 * @return object|null The course details, including course title and enrollment count, or null if no data is found.
	 */
	private static function get_course_active_users_by_course_id( $id ) {
		global $wpdb;

		$active_user_query = " CASE WHEN sub.status = 'active' THEN sub.plan_id END ";

		//phpcs:disable -- variables are sanitized.
		$result = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					post.post_title AS course_name,
					COUNT( $active_user_query ) AS active_users
				FROM {$wpdb->posts} AS post
				LEFT JOIN {$wpdb->prefix}tutor_subscription_plan_items AS plan_items
					ON plan_items.object_id = post.ID
				LEFT JOIN {$wpdb->prefix}tutor_subscriptions AS sub
					ON plan_items.plan_id = sub.plan_id
				WHERE post.ID = %s
				AND post.post_status = %s",
				$id,
				self::STATUS_PUBLISH
			)
		);
		//phpcs:enable

		// If error occurred then throw new exception.
		if ( $wpdb->last_error ) {
			throw new \Exception( $wpdb->last_error );
		}

		return $result;
	}

	/**
	 * Get the top membership details for the specified plan types.
	 *
	 * @since 3.3.0
	 *
	 * @return array An array of top membership details, including their earnings and other relevant information.
	 */
	private static function get_top_membership_details(): array {

		$top_memberships                  = array();
		$top_earning_category_membership  = self::get_top_category_membership( 3 );
		$top_earning_full_site_membership = self::get_top_full_site_memberships( 3 );

		$merged_array = array_merge( $top_earning_category_membership, $top_earning_full_site_membership );

		usort(
			$merged_array,
			function ( $a, $b ) {
				return $b['total_revenue'] <=> $a['total_revenue'];
			}
		);

		$top_memberships = array_slice( $merged_array, 0, 3 );
		return $top_memberships;
	}

	/**
	 * Get the top earning full-site memberships.
	 *
	 * @since 3.3.0
	 *
	 * @param int $limit The number of top memberships to return.
	 *
	 * @throws \Exception If there is an error with the database query.
	 *
	 * @return array An array of the top full-site memberships.
	 */
	private static function get_top_full_site_memberships( int $limit ) {
		global $wpdb;

		$plan_type                 = QueryHelper::prepare_in_clause( self::$membership_plan_types );
		$top_full_site_memberships = array();
		$amount_type               = is_admin() ? 'earnings.admin_amount' : 'earnings.instructor_amount';

		//phpcs:disable -- variables are sanitized.
		$top_memberships           = $wpdb->get_results(
			"SELECT
				COALESCE( SUM( $amount_type ), 0 ) AS total_revenue,
				plans.plan_name AS membership_name,
				plans.regular_price AS regular_price,
				plans.sale_price AS sale_price,
				plans.id AS plan_id,
				plans.plan_type AS plan_type
			FROM {$wpdb->prefix}tutor_order_items AS order_items
			LEFT JOIN {$wpdb->prefix}tutor_subscription_plans AS plans
				ON order_items.item_id = plans.id
			LEFT JOIN {$wpdb->prefix}tutor_earnings AS earnings
				ON order_items.order_id = earnings.order_id
			WHERE plans.plan_type IN ($plan_type)
			GROUP BY plans.id
			ORDER BY total_revenue DESC
			LIMIT {$limit}"
		);
		//phpcs:enable

		if ( ! empty( $top_memberships ) ) {

			$top_full_site_memberships = array_map(
				function ( $membership ) {
					$active_users = self::get_active_memberships_by_plan_ids( $membership->plan_id );

					return array(
						'membership_name' => $membership->membership_name ?? '',
						'regular_price'   => $membership->regular_price ?? 0,
						'sale_price'      => $membership->sale_price ?? 0,
						'active_users'    => $active_users->active_users ?? 0,
						'total_revenue'   => $membership->total_revenue,
						'type'            => self::$plan->get_type_list()[ $membership->plan_type ],
					);
				},
				$top_memberships
			);
		}

		// If error occurred then throw new exception.
		if ( $wpdb->last_error ) {
			throw new \Exception( $wpdb->last_error );
		}

		return $top_full_site_memberships ?? array();
	}

	/**
	 * Retrieves the top category memberships based on total revenue and active users.
	 *
	 * @since 3.3.0
	 *
	 * @param int $limit The number of top memberships to return.
	 *
	 * @throws \Exception If there is a database error during the query.
	 *
	 * @return array An array of the top category memberships.
	 */
	private static function get_top_category_membership( $limit ): array {
		global $wpdb;

		$amount_type               = is_admin() ? 'earnings.admin_amount' : 'earnings.instructor_amount';
		$top_membership_categories = array();

		//phpcs:disable -- variables are sanitized.
		$top_category_membership_details = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					COALESCE(SUM($amount_type), 0) AS total_revenue, 
					earnings.course_id AS course_id, 
					plan.regular_price AS regular_price,
					plan.sale_price AS sale_price,
					subscription_plan_items.object_name AS subscription_type, 
					subscription_plan_items.plan_id AS plan_id,
					plan.plan_name as membership_name
				FROM {$wpdb->prefix}tutor_earnings AS earnings
				LEFT JOIN {$wpdb->prefix}tutor_subscription_plan_items AS subscription_plan_items
					ON earnings.course_id = subscription_plan_items.object_id
				LEFT JOIN {$wpdb->prefix}tutor_subscription_plans AS plan
					ON subscription_plan_items.plan_id = plan.id
				WHERE subscription_plan_items.object_name = %s
					AND plan.plan_type = %s
				GROUP BY earnings.course_id
				ORDER BY total_revenue DESC
				LIMIT {$limit}",
				self::$plan::TYPE_CATEGORY,
				self::$plan::TYPE_CATEGORY
			)
		);
		//phpcs:enable

		if ( ! empty( $top_category_membership_details ) ) {

			$top_membership_categories = array_map(
				function ( $membership ) {

					$active_users = self::get_active_memberships_by_plan_ids( $membership->plan_id );

					return array(
						'membership_name' => $membership->membership_name ?? '',
						'regular_price'   => $membership->regular_price ?? 0,
						'sale_price'      => $membership->sale_price ?? 0,
						'active_users'    => $active_users->active_users ?? 0,
						'total_revenue'   => $membership->total_revenue,
						'type'            => self::$plan->get_type_list()[ self::$plan::TYPE_CATEGORY ],
					);
				},
				$top_category_membership_details
			);
		}

		// If error occurred then throw new exception.
		if ( $wpdb->last_error ) {
			throw new \Exception( $wpdb->last_error );
		}

		return $top_membership_categories ?? array();
	}

	/**
	 * Retrieves the number of active users for a given membership plan.
	 *
	 * @since 3.3.0
	 *
	 * @param int $plan_id The ID of the membership plan.
	 *
	 * @throws \Exception If there is a database error during the query.
	 *
	 * @return object|null The result object containing the count of active users for the given plan.
	 */
	private static function get_active_memberships_by_plan_ids( $plan_id ) {

		global $wpdb;

		$result = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT( plan_id ) AS active_users
				FROM {$wpdb->prefix}tutor_subscriptions
				WHERE plan_id = %d
					AND status = %s
				GROUP BY plan_id
				ORDER BY active_users DESC",
				$plan_id,
				self::$subscription::STATUS_ACTIVE
			)
		);

		// If error occurred then throw new exception.
		if ( $wpdb->last_error ) {
			throw new \Exception( $wpdb->last_error );
		}

		return $result;
	}


	/**
	 * Retrieves data for the subscription-related chart based on the provided time period and date range.
	 *
	 * @since 3.3.0
	 *
	 * @return array An array of chart data.
	 */
	public function chart_dependent_data() {

		$time_period       = Input::get( 'period', 'last30days' );
		$start_date        = Input::get( 'start_date', '' );
		$end_date          = Input::get( 'end_date', '' );
		$subscription_type = Input::get( 'subscription_type', 'all' );

		if ( '' !== $start_date ) {
			$start_date = tutor_get_formated_date( 'Y-m-d', $start_date );
		}

		if ( '' !== $end_date ) {
			$end_date = tutor_get_formated_date( 'Y-m-d', $end_date );
		}

		$subscription_graph = array(
			array(
				'id'    => 'ta_total_earnings',
				'label' => __( 'Earning', 'tutor-pro' ),
				'data'  => self::get_total_earnings_for_subscriptions( $time_period, $start_date, $end_date, $subscription_type )['earnings'] ?? 0,
			),
			array(
				'id'    => 'ta_total_course_enrolled',
				'label' => __( 'Enrolled', 'tutor-pro' ),
				'data'  => self::get_total_enrollments_for_subscriptions( $time_period, $start_date, $end_date, $subscription_type )['enrollments'] ?? 0,
			),
			array(
				'id'    => 'ta_total_refund',
				'label' => __( 'Refund', 'tutor-pro' ),
				'data'  => $this->get_refunds( $time_period, $start_date, $end_date, $subscription_type )['refunds'] ?? 0,
			),
		);

		return $subscription_graph;
	}

	/**
	 * Generates a SQL subscription clause based on the subscription type.
	 *
	 * @since 3.3.0
	 *
	 * @param string $subscription_type The type of subscription.
	 * @param string $column The column name to be used in the SQL query.
	 *
	 * @return string The generated SQL condition to filter the subscription type.
	 */
	private static function get_subscription_clause( $subscription_type, $column ) {
		$subscription_query = '';

		if ( ! empty( $subscription_type ) ) {

			$valid_subscription_types = array(
				'course_subscription' => " AND $column = 'course' ",
				'bundle_subscription' => " AND $column = 'bundle' ",
				'membership'          => " AND $column IN( 'full_site', 'category') ",
				'all'                 => " AND $column IN( 'full_site', 'category', 'course', 'bundle') ",
			);

			if ( isset( $valid_subscription_types[ $subscription_type ] ) ) {
				$subscription_query = $valid_subscription_types[ $subscription_type ];
			}
		}

		return $subscription_query;
	}
}
