<?php
/**
 * PMPro Orders Integration
 *
 * Integrates PMPro's Members Orders page into Tutor LMS frontend dashboard.
 * Replaces Tutor's purchase_history template with PMPro orders when PMPro is enabled,
 * while keeping the dashboard header and navigation tabs visible.
 *
 * @package TutorPress_PMPro
 * @subpackage Frontend
 * @since 1.0.0
 */

namespace TUTORPRESS_PMPRO\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PMPro Orders Integration class.
 *
 * Service class responsible for integrating PMPro's orders display
 * into Tutor LMS's frontend dashboard purchase_history page.
 *
 * @since 1.0.0
 */
class Pmpro_Orders_Integration {

	/**
	 * Register WordPress hooks for PMPro orders integration.
	 *
	 * Hooks into Tutor's template loading system to intercept the purchase_history
	 * template and replace it with PMPro orders when PMPro is the active monetization engine.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_hooks() {
		// Hook into Tutor's template loading system to intercept purchase_history
		// We use the filter to both skip the template AND render our content
		add_filter( 'should_tutor_load_template', array( $this, 'maybe_skip_and_render' ), 10, 3 );
	}

	/**
	 * Skip Tutor's default template and render PMPro orders for purchase_history.
	 *
	 * This filter determines whether Tutor should load its default template.
	 * When PMPro is enabled and we're on the purchase_history page, we render
	 * our PMPro orders content and return false to prevent Tutor's template from loading.
	 *
	 * IMPORTANT: We must render our content HERE in the filter, not in a separate action,
	 * because returning false causes Tutor to skip the tutor_load_template_before action entirely.
	 *
	 * Filter: should_tutor_load_template
	 *
	 * @since 1.0.0
	 *
	 * @param bool   $should_load   Whether to load the template (default: true).
	 * @param string $template_name Template name identifier (e.g., 'dashboard.purchase_history').
	 * @param array  $variables     Template variables passed to the template.
	 * @return bool False to skip default template, true to load it normally.
	 */
	public function maybe_skip_and_render( $should_load, $template_name, $variables ) {
		// Only intercept the purchase_history dashboard template
		if ( 'dashboard.purchase_history' !== $template_name ) {
			return $should_load;
		}

		// If PMPro is not enabled, load Tutor's default template
		if ( ! $this->is_pmpro_enabled() ) {
			return $should_load;
		}

		// Render PMPro orders HTML directly
		echo $this->get_pmpro_orders_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		
		// Skip Tutor's default template - we've already rendered our content
		return false;
	}

	/**
	 * Render PMPro orders using shortcode with output buffering.
	 *
	 * Uses output buffering to capture the rendered HTML from PMPro's account shortcode
	 * with the invoices section only. If the user has no orders, displays a Tutor-styled
	 * empty state message.
	 *
	 * @since 1.0.0
	 * @return string Rendered PMPro orders HTML from shortcode or empty state.
	 */
	private function get_pmpro_orders_html() {
		// Check if user has any orders first
		if ( ! $this->user_has_orders() ) {
			return $this->get_empty_state_html();
		}

		ob_start();

		// Render PMPro account shortcode with invoices section only
		// title="" hides the default section title so Tutor's "Order History" heading displays
		echo do_shortcode( '[pmpro_account sections="invoices" title=""]' );

		$html = ob_get_clean();

		return $html;
	}

	/**
	 * Check if the current user has any PMPro orders.
	 *
	 * @since 1.0.0
	 * @return bool True if user has orders, false otherwise.
	 */
	private function user_has_orders() {
		// Ensure PMPro classes are available
		if ( ! class_exists( 'MemberOrder' ) ) {
			return false;
		}

		$current_user = wp_get_current_user();
		if ( ! $current_user || ! $current_user->ID ) {
			return false;
		}

		// Get orders using PMPro's method (same as the shortcode uses)
		$orders = \MemberOrder::get_orders(
			array(
				'limit'   => 1, // We only need to know if ANY exist
				'status'  => array( 'pending', 'refunded', 'success' ),
				'user_id' => $current_user->ID,
			)
		);

		return ! empty( $orders );
	}

	/**
	 * Get HTML for empty state when user has no orders.
	 *
	 * Displays a Tutor-styled empty state message matching Tutor's default UI.
	 *
	 * @since 1.0.0
	 * @return string HTML for empty state.
	 */
	private function get_empty_state_html() {
		$account_url = function_exists( 'pmpro_url' ) ? pmpro_url( 'account' ) : '';

		ob_start();
		?>
		<div class="tutor-empty-state td-empty-state tutor-p-32 tutor-text-center">
			<img src="<?php echo esc_url( tutor()->url . 'assets/images/emptystate.svg' ); ?>" alt="<?php esc_attr_e( 'No Orders Available', 'tutorpress-pmpro' ); ?>" />
			<div class="tutor-fs-5 tutor-fw-medium tutor-color-black tutor-mt-24">
				<?php esc_html_e( 'No orders found', 'tutorpress-pmpro' ); ?>
			</div>
			<div class="tutor-fs-6 tutor-color-muted tutor-mt-12">
				<?php esc_html_e( 'You haven\'t made any purchases yet.', 'tutorpress-pmpro' ); ?>
			</div>
			<?php if ( ! empty( $account_url ) ) : ?>
				<div class="tutor-mt-20">
					<a href="<?php echo esc_url( $account_url ); ?>" class="tutor-btn tutor-btn-outline-primary">
						<?php esc_html_e( 'View Your Membership Account', 'tutorpress-pmpro' ); ?> &rarr;
					</a>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Determine if PMPro is the active monetization engine.
	 *
	 * Checks if PMPro is the selected monetization option in Tutor LMS settings.
	 * This ensures we only integrate PMPro orders when it's actively being used.
	 *
	 * @since 1.0.0
	 * @return bool True if PMPro is the active monetization engine, false otherwise.
	 */
	private function is_pmpro_enabled() {
		if ( function_exists( 'get_tutor_option' ) ) {
			return 'pmpro' === get_tutor_option( 'monetize_by' );
		}
		return false;
	}
}

