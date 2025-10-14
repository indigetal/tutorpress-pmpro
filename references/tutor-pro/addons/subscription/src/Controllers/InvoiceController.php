<?php
/**
 * Invoice controller for plan order.
 *
 * @package TutorPro\Subscription
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.5.0
 */

namespace TutorPro\Subscription\Controllers;

use Tutor\Models\OrderModel;
use Tutor\Models\OrderMetaModel;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Class InvoiceController
 *
 * @since 3.5.0
 */
class InvoiceController {

	/**
	 * Constructor
	 *
	 * @param bool $register_hooks Whether to register hooks or not.
	 *
	 * @since 3.5.0
	 */
	public function __construct( $register_hooks = true ) {
		if ( ! $register_hooks ) {
			return;
		}

		add_action( 'tutor_after_order_invoice_item_name', array( $this, 'render_trial_badge_after_invoice_item' ) );
	}

	/**
	 * Render trial badge label after item name.
	 *
	 * @since 3.5.0
	 *
	 * @param object $order_data order data.
	 *
	 * @return void
	 */
	public function render_trial_badge_after_invoice_item( $order_data ) {
		if ( OrderModel::TYPE_SUBSCRIPTION === $order_data->order_type ) {
			$is_trial_order = OrderMetaModel::get_meta_value( $order_data->id, OrderModel::META_IS_PLAN_TRIAL_ORDER, true );
			$plan_info      = OrderMetaModel::get_meta_value( $order_data->id, OrderModel::META_PLAN_INFO, true );
			if ( $is_trial_order && $plan_info && $plan_info->trial_value > 0 ) {
				$trial_badge_label = sprintf(
					/* translators: %d: trial value, %s: trial interval, %s: free or not */
					__( '%1$d-%2$s %3$sTrial', 'tutor-pro' ),
					$plan_info->trial_value,
					ucwords( $plan_info->trial_value > 1 ? $plan_info->trial_interval . 's' : $plan_info->trial_interval ),
					$plan_info->trial_fee > 0 ? '' : 'Free '
				);
				?>
				<div class="tutor-fw-normal tutor-fs-8 tutor-color-hints"><?php echo esc_html( $trial_badge_label ); ?></div>
				<?php
			}
		}
	}
}
