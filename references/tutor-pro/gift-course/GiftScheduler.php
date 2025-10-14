<?php
/**
 * Handle Gift Schedule
 *
 * @package TutorPro\GiftCourse
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.8.0
 */

namespace TutorPro\GiftCourse;

use AllowDynamicProperties;
use Tutor\Helpers\QueryHelper;

defined( 'ABSPATH' ) || exit;

/**
 * Manage gift scheduling
 */
#[AllowDynamicProperties]
class GiftScheduler {

	/**
	 * Schedule type
	 *
	 * @since 3.8.0
	 *
	 * @var string
	 */
	const TYPE = 'gift_course';

	const STATUS_SCHEDULED = 'scheduled';
	const STATUS_PROCESSED = 'processed';
	const STATUS_COMPLETED = 'completed';

	const CRON_ACTION = 'tutor_gift_course';

	/**
	 * Database instance
	 *
	 * @var \wpdb
	 */
	private $db;

	/**
	 * Resolve props
	 */
	public function __construct() {
		global $wpdb;
		$this->db    = $wpdb;
		$this->table = $this->db->prefix . 'tutor_scheduler';

		add_filter( 'cron_schedules', array( $this, 'filter_schedules' ) );
		$this->handle_cron();
	}

	/**
	 * Schedule a gift
	 *
	 * @since 3.8.0
	 *
	 * @throws \Throwable If database error occur.
	 *
	 * @param object $payload Gift payload.
	 * @param string $status Schedule status.
	 *
	 * @return int
	 */
	public function schedule( object $payload, string $status = self::STATUS_SCHEDULED ) {
		$recipient_email = $payload->recipient_email;
		$user            = get_user_by( 'email', $recipient_email );

		try {
			$insert = QueryHelper::insert(
				$this->table,
				array(
					'type'             => self::TYPE,
					'payload'          => wp_json_encode( $payload ),
					'reference_id'     => $payload->reference_id,
					'status'           => $status,
					'scheduled_at_gmt' => $payload->scheduled_at_gmt,
					'scheduled_by'     => get_current_user_id(),
					'scheduled_for'    => $user ? $user->ID : 0,
				)
			);

			return $insert;
		} catch ( \Throwable $th ) {
			throw $th;
		}
	}

	/**
	 * Get schedule data
	 *
	 * @since 3.8.0
	 *
	 * @param mixed $where Reference id or array of where condition.
	 *
	 * @return mixed
	 */
	public function get( $where ) {
		if ( ! is_array( $where ) ) {
			$where = array(
				'reference_id' => $where,
			);
		}

		return QueryHelper::get_row(
			$this->table,
			$where,
			'id'
		);
	}

	/**
	 * Get all schedule data based on where condition
	 *
	 * @since 3.8.0
	 *
	 * @param array $where Reference id or array of where condition.
	 * @param int   $limit Limit of fetching data.
	 *
	 * @return mixed
	 */
	public function get_all( array $where, $limit = 399 ) {
		return QueryHelper::get_all(
			$this->table,
			$where,
			'id',
			$limit
		);
	}

	/**
	 * Update schedule data
	 *
	 * @since 3.8.0
	 *
	 * @param array $data Data to update.
	 * @param array $where Where condition.
	 *
	 * @return bool
	 */
	public function update( array $data, array $where ) {
		$update = QueryHelper::update(
			$this->table,
			$data,
			$where
		);
		return $update;
	}

	/**
	 * Delete schedule data
	 *
	 * @since 3.8.0
	 *
	 * @param array $where Where condition.
	 *
	 * @return bool
	 */
	public function delete( array $where ) {
		return QueryHelper::delete(
			$this->table,
			$where
		);
	}

	/**
	 * Handle cron job
	 *
	 * Register cron job if not exist
	 *
	 * @since 3.8.0
	 *
	 * @return void
	 */
	public function handle_cron() {
		add_action( self::CRON_ACTION, array( $this, 'process_scheduled' ) );

		if ( ! wp_next_scheduled( self::CRON_ACTION ) ) {
			wp_schedule_event( time(), '10min', self::CRON_ACTION );
		}
	}

	/**
	 * Process scheduled gifts
	 *
	 * @since 3.8.0
	 *
	 * @return void
	 */
	public function process_scheduled() {
		$this->delete_completed_schedules();

		$scheduled_gifts = $this->get_all( array( 'status' => self::STATUS_SCHEDULED ) );
		if ( tutor_utils()->count( $scheduled_gifts ) ) {
			foreach ( $scheduled_gifts as $gift ) {
				$payload = json_decode( $gift->payload );
				if ( $payload && time() >= strtotime( $payload->scheduled_at_gmt ) ) {
					$this->update(
						array(
							'status' => self::STATUS_PROCESSED,
						),
						array(
							'id' => $gift->id,
						)
					);

					// Notify the receiver.
					( new GiftMailer( $payload ) )->send_notification_email_to_receiver();
				}
			}
		}
	}

	/**
	 * Add new interval to the cron schedule
	 *
	 * @since 3.8.0
	 *
	 * @param array $schedules Array of schedules.
	 *
	 * @return array
	 */
	public function filter_schedules( array $schedules ): array {
		$schedules['10min'] = array(
			'interval' => 60 * 10, // 10 mins in seconds.
			'display'  => __( '10 Minutes', 'tutor-pro' ),
		);

		return $schedules;
	}

	/**
	 * Delete completed schedules
	 *
	 * @since 3.8.0
	 *
	 * @return void
	 */
	public function delete_completed_schedules() {
		$where = array(
			'type'   => self::TYPE,
			'status' => self::STATUS_COMPLETED,
		);

		$this->delete( $where );
	}
}

