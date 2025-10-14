<?php
/**
 * Concrete class for creating gift order when
 * monetization is native
 *
 * @package Tutor\Ecommerce
 * @author Themeum
 * @link https://themeum.com
 * @since 3.8.0
 */

namespace TutorPro\GiftCourse;

use AllowDynamicProperties;
use Tutor\Ecommerce\CheckoutController;
use Tutor\Helpers\QueryHelper;
use Tutor\Models\CartModel;
use Tutor\Models\OrderItemMetaModel;
use Tutor\Models\OrderItemModel;
use Tutor\Models\OrderModel;

/**
 * Handle order creating logics
 */
#[AllowDynamicProperties]
class NativeGiftProceeder implements GiftProceeder {

	/**
	 * Resolve props
	 *
	 * @since 3.8.0
	 */
	public function __construct() {
		$this->user_id        = get_current_user_id();
		$this->cart_model     = new CartModel();
		$this->order_model    = new OrderModel();
		$this->item_model     = new OrderItemModel();
		$this->itemmeta_model = new OrderItemMetaModel();
	}

	/**
	 * Create gift order
	 *
	 * @since 3.8.0
	 *
	 * @throws \Exception If course not found or failed to add to cart.
	 * @throws \Throwable If failed to add to cart.
	 *
	 * @param object $gift_data Gift data object.
	 *
	 * @return string Order checkout url
	 */
	public function proceed_to_checkout( object $gift_data ):string {
		if ( ! $gift_data->course_id ) {
			throw new \Exception( 'Course not found' );
		}

		$cart_items = $this->cart_model->get_cart_items( $this->user_id, false );
		if ( tutor_utils()->count( $cart_items ) ) {
			foreach ( $cart_items as $item ) {
				if ( $this->is_gift_course_in_cart( $item->ID ) ) {
					throw new \Exception( 'Gift course already in cart' );
				}
			}
		}

		try {
			$this->cart_model->add_course_to_cart( $this->user_id, $gift_data->course_id, 'gift', $gift_data );
			return CheckoutController::get_page_url();
		} catch ( \Throwable $th ) {
			throw $th;
		}
	}

	/**
	 * Check if the item is gift item
	 *
	 * @since 3.8.0
	 *
	 * @param object|int $item Order item primary id or item object.
	 *
	 * @return object|bool
	 */
	public function is_gift_item( $item ) {
		if ( ! $item ) {
			return false;
		}

		$id = $item;
		if ( is_object( $item ) ) {
			$id = isset( $item->primary_id ) ? $item->primary_id : $item->id;
		}

		$meta = $this->itemmeta_model->get_meta( $id, GiftCourse::GIFT_FLAG_META, true );
		if ( $meta ) {
			return is_object( $item ) ? $item : $this->item_model->get_row( array( 'id' => $id ) );
		} else {
			return false;
		}
	}

	/**
	 * Check if the cart item already added for gift.
	 *
	 * @since 3.8.0
	 *
	 * @param int $course_id Course id.
	 *
	 * @return bool
	 */
	public function is_gift_course_in_cart( $course_id ) {
		if ( ! $course_id ) {
			return false;
		}

		$cart_item = QueryHelper::get_row(
			'tutor_cart_items',
			array( 'course_id' => $course_id ),
			'id'
		);

		if ( ! $cart_item ) {
			return false;
		}

		if ( 'gift' !== $cart_item->item_type ) {
			return false;
		}

		$item_details = $cart_item ? json_decode( $cart_item->item_details ) : null;
		if ( ! $item_details ) {
			return false;
		}

		return $item_details->purchaser_id && $this->user_id === $item_details->purchaser_id;
	}

	/**
	 * Get gift data
	 *
	 * @since 3.8.0
	 *
	 * @param int|object $item Order item object or id.
	 *
	 * @return object|bool
	 */
	public function get_gift_data( $item ) {
		if ( ! $item ) {
			return false;
		}

		if ( ! $this->is_gift_item( $item ) ) {
			return false;
		}

		$item_id = $item;
		if ( is_object( $item ) ) {
			$item_id = isset( $item->primary_id ) ? $item->primary_id : $item->id;
		}

		$item_meta = $this->itemmeta_model->get_meta( $item_id, GiftCourse::GIFT_DATA_META );
		$gift_data = $item_meta ? maybe_unserialize( $item_meta->meta_value ) : false;
		return $gift_data;
	}

	/**
	 * Update item meta
	 *
	 * @since 3.8.0
	 *
	 * @param int|object $item Item id or item object.
	 * @param string     $meta_key Item meta key.
	 * @param string     $meta_value Item meta value.
	 *
	 * @return object|bool
	 */
	public function update_item_meta( $item, $meta_key, $meta_value ) {
		if ( ! $item ) {
			return false;
		}

		$item_id = $item;
		if ( is_object( $item ) ) {
			$item_id = $item->id;
		}

		return $this->itemmeta_model->update_meta( $item_id, $meta_key, $meta_value );
	}

	/**
	 * Get order
	 *
	 * @since 3.8.0
	 *
	 * @param int $order_id Order id.
	 *
	 * @return object|bool
	 */
	public function get_order( int $order_id ) {
		$order = $this->order_model->get_order_by_id( $order_id );
		if ( ! $order ) {
			return false;
		}

		return $order;
	}


	/**
	 * Get order items
	 *
	 * @since 3.8.0
	 *
	 * @param int $order_id Order id.
	 *
	 * @return object|bool
	 */
	public function get_order_items( int $order_id ) {
		$order = $this->get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		$items = $order->items;
		if ( ! $items ) {
			return false;
		}

		return $items;
	}

	/**
	 * Restrict refund for the gift course if it is received
	 *
	 * @since 3.8.0
	 *
	 * @throws \Exception If user try to refund gifted item.
	 *
	 * @param object $order Order data.
	 *
	 * @return void
	 */
	public function restrict_refund( $order ) {
		if ( ! $order ) {
			return;
		}

		$items = $order->items;
		if ( ! $items ) {
			return;
		}

		foreach ( $items as $item ) {
			if ( $this->is_gift_item( $item ) && $this->is_gift_received( $item ) ) {
				throw new \Exception( __( 'Gift course cannot be refunded.', 'tutor-pro' ) );
			}
		}
	}

	/**
	 * Is gift received
	 *
	 * @since 3.8.0
	 *
	 * @param object $item WC_Order_Item_Product object.
	 *
	 * @return boolean
	 */
	public function is_gift_received( object $item ): bool {
		$status_meta = $this->itemmeta_model->get_meta( $item->primary_id, GiftCourse::GIFT_STATUS_META );
		return $status_meta && GiftCourse::GIFT_STATUS_RECEIVED === $status_meta->meta_value;
	}

}
