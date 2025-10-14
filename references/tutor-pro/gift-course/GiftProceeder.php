<?php
/**
 * Gift order interface
 *
 * @package Tutor\Ecommerce
 * @author Themeum
 * @link https://themeum.com
 * @since 3.8.0
 */

namespace TutorPro\GiftCourse;

/**
 * Gift order interface
 *
 * @since 3.8.0
 */
interface GiftProceeder {

	/**
	 * Create gift order
	 *
	 * @since 3.8.0
	 *
	 * @param object $gift_info Gift information object.
	 *
	 * @return string
	 */
	public function proceed_to_checkout( object $gift_info ): string;

	/**
	 * Check if the item is gift item
	 *
	 * @since 3.8.0
	 *
	 * @param object|int $item Order item id or item object.
	 *
	 * @return object|bool
	 */
	public function is_gift_item( $item );

	/**
	 * Get gift data
	 *
	 * @since 3.8.0
	 *
	 * @param int|object $item Order item object or id.
	 *
	 * @return object|bool
	 */
	public function get_gift_data( $item );

	/**
	 * Get order
	 *
	 * @since 3.8.0
	 *
	 * @param int $order_id Order id.
	 *
	 * @return object|bool
	 */
	public function get_order( int $order_id );

	/**
	 * Get order items
	 *
	 * @since 3.8.0
	 *
	 * @param int $order_id Order id.
	 *
	 * @return object|bool
	 */
	public function get_order_items( int $order_id );
}
