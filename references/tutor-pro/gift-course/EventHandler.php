<?php
/**
 * Handle Gift order events
 *
 * @package TutorPro\GiftCourse
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.8.0
 */

namespace TutorPro\GiftCourse;

use AllowDynamicProperties;
use Tutor\Ecommerce\Ecommerce;
use Tutor\Helpers\DateTimeHelper;
use Tutor\Models\CartItemModel;

/**
 * Handle events for Gift Course
 *
 * @since 3.8.0
 */
#[AllowDynamicProperties]
class EventHandler {

	/**
	 * Register hooks
	 *
	 * @since 3.8.0
	 */
	public function __construct() {
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'woo_save_gift_data_to_order_items' ), 10, 4 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'handle_order_status_update' ), 10, 3 );
		add_filter( 'tutor_is_gift_item', array( $this, 'check_gift_item' ), 10, 2 );
		add_filter( 'tutor_before_order_create', array( $this, 'native_save_gift_data_to_order_items' ) );
		add_action( 'tutor_order_payment_status_changed', array( $this, 'handle_order_status_update' ), 10, 3 );
		add_filter( 'woocommerce_order_item_get_formatted_meta_data', array( $this, 'hide_tutor_gift_meta_on_thankyou' ), 10, 2 );
		add_action( 'woocommerce_create_refund', array( $this, 'woo_restrict_refund' ), 10, 2 );
		add_action( 'tutor_before_initiate_refund', array( $this, 'native_restrict_refund' ) );
		add_filter( 'tutor_order_item_meta', array( $this, 'filter_meta_value_for_display' ) );
	}

	/**
	 * Save gift data with order items
	 *
	 * @since 3.8.0
	 *
	 * @param object $item WC product item.
	 * @param string $cart_item_key Cart key.
	 * @param array  $values Cart data.
	 * @param object $order WC Order.
	 *
	 * @return void
	 */
	public function woo_save_gift_data_to_order_items( $item, $cart_item_key, $values, $order ) {
		$monetize_by = $this->get_monetize_by();
		if ( 'wc' !== $monetize_by ) {
			return;
		}

		$is_gift_course_in_cart = ( new WooGiftProceeder() )->is_gift_course_in_cart( $values );
		if ( $is_gift_course_in_cart ) {
			$gift_data = $values[ GiftCourse::GIFT_DATA_META ];
			if ( $gift_data ) {
				$gift_data->order_id    = $order->get_id();
				$gift_data->monetize_by = $monetize_by;

				$item->add_meta_data( GiftCourse::GIFT_FLAG_META, true );
				$item->add_meta_data( GiftCourse::GIFT_DATA_META, $gift_data, true );
				$item->add_meta_data( GiftCourse::GIFT_STATUS_META, GiftCourse::GIFT_STATUS_PROCESSING, true );
			}
		}
	}

	/**
	 * Save gift data with order items
	 *
	 * @since 3.8.0
	 *
	 * @param array $order_data Native order data.
	 *
	 * @return array
	 */
	public function native_save_gift_data_to_order_items( $order_data ) {
		if ( tutor_utils()->count( $order_data ) ) {
			$items = $order_data['items'];
			if ( tutor_utils()->count( $items ) ) {
				foreach ( $items as $key => $item ) {
					$is_gift_item = ( new NativeGiftProceeder() )->is_gift_course_in_cart( $item['item_id'] );
					if ( $is_gift_item ) {
						$cart_item = ( new CartItemModel() )->get_row( array( 'course_id' => $item['item_id'] ) );
						if ( $cart_item ) {
							$gift_data = json_decode( $cart_item->item_details );
							if ( ! empty( $gift_data ) ) {
								$gift_data->order_id    = $order_data['order_id'] ?? 0;
								$gift_data->monetize_by = Ecommerce::MONETIZE_BY;

								// Add gift meta data.
								$item['meta_data'] = array(
									array(
										'meta_key'   => GiftCourse::GIFT_FLAG_META,
										'meta_value' => true,
									),
									array(
										'meta_key'   => GiftCourse::GIFT_DATA_META,
										'meta_value' => $gift_data,
									),
									array(
										'meta_key'   => GiftCourse::GIFT_STATUS_META,
										'meta_value' => GiftCourse::GIFT_STATUS_PROCESSING,
									),
								);

								$items[ $key ] = $item;
							}
						}
					}
				}

				$order_data['items'] = $items;
			}
		}

		return $order_data;
	}

	/**
	 * Send emails & schedule gift data on order status update
	 *
	 * @since 3.8.0
	 *
	 * @param int    $order_id Order id.
	 * @param string $status_from Order from status.
	 * @param string $status_to Order to status.
	 *
	 * @return void
	 */
	public function handle_order_status_update( $order_id, $status_from, $status_to ) {
		// For native status_to is payment status.
		$is_tutor_monetization = tutor_utils()->is_monetize_by_tutor();
		if ( $is_tutor_monetization && 'paid' !== $status_to ) {
			return;
		}

		try {
			$proceeder = GiftCourse::get_gift_proceeder();
			$order     = $proceeder->get_order( $order_id );

			if ( ! $order || 'completed' !== $order->order_status ) {
				return;
			}

			$order_items = $order->items;
			// If order is not gift course order.
			if ( ! $order_items ) {
				return;
			}

			foreach ( $order_items as $item ) {
				try {
					$gift_data = $proceeder->get_gift_data( $item );
					if ( $gift_data ) {
						// Set the order & item id.
						$gift_data->order_id = $order_id;

						// Primary is for native & id is for wc.
						$gift_data->order_item_id = $is_tutor_monetization ? $item->primary_id : $item->get_id();
						( new GiftMailer( $gift_data, $proceeder ) )->send_emails();

						$can_process = time() >= strtotime( $gift_data->scheduled_at_gmt );
						try {
							( new GiftScheduler() )->schedule( $gift_data, $can_process ? GiftScheduler::STATUS_PROCESSED : GiftScheduler::STATUS_SCHEDULED );
						} catch ( \Throwable $th ) {
							tutor_log( $th );
						}
					}
				} catch ( \Throwable $th ) {
					tutor_log( $th );
				}
			}
		} catch ( \Throwable $th ) {
			tutor_log( $th );
		}
	}

	/**
	 * Check if the item is gift course item
	 *
	 * @since 3.8.0
	 *
	 * @param bool       $is_gift_item Default value.
	 * @param object|int $item Item id or item object.
	 *
	 * @return bool
	 */
	public function check_gift_item( bool $is_gift_item, $item ) {
		return GiftCourse::is_gift_item( $item );
	}

	/**
	 * Refund restriction added for the WC order when item is gift
	 *
	 * @since 3.8.0
	 *
	 * @param WC_Order_Refund $refund Refund object.
	 * @param array           $args Refund args.
	 *
	 * @return mixed
	 */
	public function woo_restrict_refund( $refund, $args ) {
		if ( 'wc' === $this->get_monetize_by() ) {
			return GiftCourse::get_gift_proceeder()->restrict_refund( $refund, $args );
		}
	}

	/**
	 * Restrict refund if gift received
	 *
	 * @since 3.8.0
	 *
	 * @param object $order Order data.
	 *
	 * @throws \Exception If gift already received.
	 *
	 * @return mixed
	 */
	public function native_restrict_refund( $order ) {
		return GiftCourse::get_gift_proceeder()->restrict_refund( $order );
	}

	/**
	 * Get monetize by
	 *
	 * @since 3.8.0
	 *
	 * @return mixed
	 */
	private function get_monetize_by() {
		return tutor_utils()->get_option( 'monetize_by' );
	}

	/**
	 * Remove gift course meta keys from the thank you on
	 * frontend
	 *
	 * @since 3.8.0
	 *
	 * @param array $formatted_meta Array of meta.
	 * @param array $item Order item.
	 *
	 * @return array
	 */
	public function hide_tutor_gift_meta_on_thankyou( $formatted_meta, $item ) {
		$meta_keys_to_hide = array(
			'tutor_gift',
			'tutor_gift_status',
			'tutor_gift_sent_at',
			'tutor_gift_received_at',
		);

		foreach ( $formatted_meta as $key => $meta ) {
			if ( in_array( $meta->key, $meta_keys_to_hide ) ) {
				if ( is_admin() ) {
					if ( 'tutor_gift' === $meta->key ) {
						unset( $formatted_meta[ $key ] );
					} elseif ( in_array( $meta->key, array( 'tutor_gift_sent_at', 'tutor_gift_received_at' ) ) ) {
						$meta->display_key   = ucfirst( str_replace( '_', ' ', $meta->key ) );
						$meta->display_value = tutor_i18n_get_formated_date( DateTimeHelper::get_gmt_to_user_timezone_date( $meta->value ) );

						$formatted_meta[ $key ] = $meta;
					} else {
						$meta->display_key = ucfirst( str_replace( '_', ' ', $meta->key ) );

						$formatted_meta[ $key ] = $meta;
					}
				} else {
					unset( $formatted_meta[ $key ] );
				}
			}
		}

		return $formatted_meta;
	}

	/**
	 * Update the gift item meta to display on the admin side
	 *
	 * @since 3.8.0
	 *
	 * @param mixed $item_meta Order item meta.
	 *
	 * @return array
	 */
	public function filter_meta_value_for_display( $item_meta ) {
		if ( tutor_utils()->count( $item_meta ) ) {
			$hide_keys = array(
				GiftCourse::GIFT_FLAG_META,
				GiftCourse::GIFT_DATA_META,
			);

			$gift_time_array = array(
				GiftCourse::GIFT_SENT_AT_META,
				GiftCourse::GIFT_RECEIVED_AT_META,
			);

			foreach ( $item_meta as $key => $meta ) {
				if ( in_array( $meta->meta_key, $hide_keys ) ) {
					unset( $item_meta[ $key ] );
				} else {
					if ( in_array( $meta->meta_key, $gift_time_array ) ) {
						if ( $meta->meta_value ) {
							$meta->meta_value = tutor_i18n_get_formated_date( DateTimeHelper::get_gmt_to_user_timezone_date( $meta->meta_value ) );
						}
					} else {
						$meta->meta_value = trim( ucfirst( str_replace( '_', ' ', $meta->meta_value ) ) );
					}

					$update_key     = trim( str_replace( array( '_', 'tutor' ), array( ' ', ' ' ), $meta->meta_key ) );
					$meta->meta_key = ucfirst( $update_key );

					// Update the value.
					$item_meta[ $key ] = $meta;
				}
			}
		}

		$reset = array_values( $item_meta );
		return $reset;
	}
}
