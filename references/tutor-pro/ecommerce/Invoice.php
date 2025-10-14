<?php
/**
 * Invoice class
 *
 * @package TutorPro\Invoice
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.0.0
 */

namespace TutorPro\Ecommerce;

use Tutor\Ecommerce\OrderController;
use TUTOR\Input;
use Tutor\Models\OrderModel;
use TutorPro\Subscription\Menu;
/**
 * Invoice class
 */
class Invoice {

	/**
	 * Register hooks
	 *
	 * @since 3.0.0
	 */
	public function __construct() {
		add_action( 'tutor_dashboard_invoice_button', array( $this, 'render_invoice_button' ) );
		add_filter( 'load_dashboard_template_part_from_other_location', array( $this, 'load_invoice_template' ) );
		add_action( 'tutor_after_order_edit_link', array( $this, 'render_admin_order_invoice_button' ) );
		add_action( 'tutor_after_subscription_action_view', array( $this, 'render_admin_order_invoice_button' ) );
		add_filter( 'tutor_order_list_page_template', array( $this, 'load_admin_order_invoice_template' ) );
	}

	/**
	 * Render invoice button.
	 *
	 * @since 3.0.0
	 *
	 * @param object $order order.
	 *
	 * @return void
	 */
	public function render_invoice_button( $order ) {
		if ( self::should_show_invoice( $order ) ) {
			$invoice_url = add_query_arg( 'invoice', $order->id, tutor_utils()->get_tutor_dashboard_page_permalink( 'purchase_history' ) );

			$is_subscriptions_page = tutor_utils()->is_tutor_frontend_dashboard( 'subscriptions' );
			if ( $is_subscriptions_page ) {
				$invoice_url = add_query_arg( 'subscription', Input::get( 'id' ), $invoice_url );
			}

			echo '<a href="' . esc_url( $invoice_url ) . '" class="tutor-btn tutor-btn-outline-primary tutor-btn-sm" target="_blank">' .
				esc_html__( 'Invoice', 'tutor-pro' ) .
				'</a>';
		}
	}

	/**
	 * Load invoice templates.
	 *
	 * @since 3.0.0
	 *
	 * @param string $template template path.
	 *
	 * @return string
	 */
	public function load_invoice_template( $template ) {
		$invoice_id = Input::get( 'invoice', 0, Input::TYPE_INT );
		if ( get_query_var( 'tutor_dashboard_page' ) === 'purchase_history' && $invoice_id ) {
			$template = tutor_pro()->path . 'templates/invoice.php';
			if ( file_exists( $template ) ) {
				return $template;
			}
		}
		return $template;
	}

	/**
	 * Render admin invoice button.
	 *
	 * @since 3.5.0
	 *
	 * @param object $order order.
	 *
	 * @return void
	 */
	public function render_admin_order_invoice_button( $order ) {
		if ( self::should_show_invoice( $order ) ) {
			$invoice_url = add_query_arg( 'invoice', $order->id, OrderController::get_order_page_url() );

			if ( Menu::PAGE_SLUG === Input::get( 'page' ) ) {
				$invoice_url = add_query_arg( 'subscription', Input::get( 'id' ), $invoice_url );
			}

			echo '<a href="' . esc_url( $invoice_url ) . '" class="tutor-btn tutor-btn-outline-primary tutor-btn-sm" target="_blank">' .
			esc_html__( 'Invoice', 'tutor-pro' ) .
			'</a>';
		}
	}

	/**
	 * Load admin invoice templates.
	 *
	 * @since 3.0.0
	 *
	 * @param string $path template path.
	 *
	 * @return string
	 */
	public function load_admin_order_invoice_template( $path ) {
		$invoice_id   = Input::get( 'invoice', 0, Input::TYPE_INT );
		$current_page = Input::get( 'page' );

		if ( OrderController::PAGE_SLUG === $current_page && $invoice_id ) {
			$template = tutor_pro()->path . 'templates/invoice.php';
			if ( file_exists( $template ) ) {
				return $template;
			}
		}

		return $path;
	}

	/**
	 * Determine whether to show invoice button.
	 *
	 * @since 3.0.0
	 *
	 * @param object $order Order object.
	 *
	 * @return boolean
	 */
	public static function should_show_invoice( $order ) {
		$status = array( OrderModel::ORDER_COMPLETED );
		return is_object( $order ) && in_array( $order->order_status, $status );
	}
}
