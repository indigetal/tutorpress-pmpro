<?php
/**
 * Concrete class for adding item in to cart when
 * monetization is woocommerce
 *
 * @package Tutor\Ecommerce
 * @author Themeum
 * @link https://themeum.com
 * @since 3.8.0
 */

namespace TutorPro\GiftCourse;

use WC_Order;
use WC_Order_Item;
use WC_Order_Item_Product;

/**
 * Handle proceed to checkout logics
 */
class WooGiftProceeder implements GiftProceeder {

	/**
	 * Create gift order
	 *
	 * @since 3.8.0
	 *
	 * @throws \Exception If product not found for this course.
	 *
	 * @param object $gift_data Gift data object.
	 *
	 * @return string Order checkout url
	 */
	public function proceed_to_checkout( object $gift_data ):string {
		$product_id = tutor_utils()->get_course_product_id( $gift_data->course_id );
		if ( ! $product_id ) {
			throw new \Exception( 'Product not found for this course' );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			throw new \Exception( 'Invalid product ID: ' . $product_id );
		}

		// Ensure WooCommerce cart is loaded.
		if ( ! WC()->cart ) {
			wc_load_cart();
		}

		// Add product to cart with gift meta.
		$cart  = WC()->cart;
		$items = $cart->get_cart();

		foreach ( $items as $item ) {
			$cart_product_id = $item['product_id'];
			$is_gift_in_cart = $item[ GiftCourse::GIFT_DATA_META ] ?? null;
			if ( $product_id === $cart_product_id && $is_gift_in_cart ) {
				throw new \Exception( 'Gift course already in cart' );
			}
		}

		$cart->add_to_cart(
			$product_id,
			1,
			0,
			array(),
			array( GiftCourse::GIFT_DATA_META => $gift_data )
		);

		return wc_get_checkout_url();
	}

	/**
	 * Check if the item is gift item
	 *
	 * @since 3.8.0
	 *
	 * @param object|int $item Order item id or item object.
	 *
	 * @return object|bool
	 */
	public function is_gift_item( $item ) {
		if ( ! $item ) {
			return false;
		}

		if ( is_int( $item ) ) {
			$item = new WC_Order_Item( $item );
		}

		$meta = $item->get_meta( GiftCourse::GIFT_FLAG_META, true );
		if ( $meta ) {
			return $item;
		} else {
			return false;
		}
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
		if ( is_int( $item ) ) {
			$item = new WC_Order_Item_Product( $item );
		}

		if ( ! $item ) {
			return false;
		}

		if ( ! $this->is_gift_item( $item ) ) {
			return false;
		}

		return $item->get_meta( GiftCourse::GIFT_DATA_META, true );
	}

	/**
	 * Get gift data
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

		if ( is_int( $item ) ) {
			$item = new WC_Order_Item_Product( $item );
		}

		if ( ! $item ) {
			return false;
		}

		$item->update_meta_data( $meta_key, $meta_value );
		$item->save();

		return true;
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
		$order = new WC_Order( $order_id );
		if ( ! $order ) {
			return false;
		}

		$status = $order->get_status( '' );

		$order->order_status = $status;

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
		$order = new WC_Order( $order_id );
		if ( ! $order ) {
			return false;
		}

		$items = $order->get_items();
		if ( ! $items ) {
			return;
		}

		return $items;
	}

	/**
	 * Restrict refund for the gift course
	 *
	 * @since 3.8.0
	 *
	 * @throws \Exception If user try to refund gift item.
	 *
	 * @param WC_Order_Refund $refund Refund object.
	 * @param array           $args Refund args.
	 *
	 * @return void
	 */
	public function restrict_refund( $refund, $args ) {
		$order_id = $args['order_id'];
		if ( ! $order_id ) {
			return;
		}

		$order = $this->get_order( $order_id );
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
		$status = $item->get_meta( GiftCourse::GIFT_STATUS_META, true );
		return GiftCourse::GIFT_STATUS_RECEIVED === $status;
	}

	/**
	 * Check if the cart item already added for gift.
	 *
	 * @since 3.8.0
	 *
	 * @param array $cart_data Cart data.
	 *
	 * @return bool
	 */
	public function is_gift_course_in_cart( array $cart_data ) {
		return is_array( $cart_data ) && isset( $cart_data[ GiftCourse::GIFT_DATA_META ] );
	}

}
