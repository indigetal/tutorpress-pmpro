<?php
/**
 * Sale Price Handler
 *
 * Manages sale pricing for PMPro membership levels, including:
 * - Real-time sale validation and schedule checking
 * - Dynamic price calculation at checkout
 * - Sale price display with strikethrough formatting
 * - Sale price application in emails and invoices
 *
 * @package TutorPress_PMPro
 * @subpackage Pricing
 * @since 1.0.0
 */

namespace TUTORPRESS_PMPRO\Pricing;

/**
 * Sale Price Handler class.
 *
 * Service class responsible for managing and displaying sale prices
 * for PMPro membership levels. Handles real-time sale validation,
 * checkout price modification, and sale display formatting.
 *
 * @since 1.0.0
 */
class Sale_Price_Handler {

	/**
	 * Whether the sale price was applied at checkout this request.
	 *
	 * @since 1.0.0
	 * @var bool
	 */
	private $sale_applied_at_checkout = false;

	/**
	 * Whether a discount code was present on the checkout level this request.
	 *
	 * @since 1.0.0
	 * @var bool
	 */
	private $discount_code_used_at_checkout = false;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->register_hooks();
	}

	/**
	 * Register WordPress hooks for sale price handling.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_hooks() {
		// PMPro Critical Filter Hooks for Dynamic Pricing (Zero-Delay Architecture)
		add_filter( 'pmpro_checkout_level', array( $this, 'filter_checkout_level_sale_price' ), 10, 1 );
		add_filter( 'pmpro_level_cost_text', array( $this, 'filter_level_cost_text_sale_price' ), 999, 4 );
		add_filter( 'pmpro_email_data', array( $this, 'filter_email_data_sale_price' ), 10, 2 );
		add_action( 'pmpro_invoice_bullets_bottom', array( $this, 'filter_invoice_sale_note' ), 10, 1 );
	}

	/**
	 * Get active price for a PMPro membership level.
	 *
	 * Reads sale meta and calculates in real-time whether sale is active.
	 * Returns array with active price, regular price, and sale status.
	 *
	 * @since 1.0.0
	 *
	 * @param int $level_id PMPro level ID.
	 * @return array {
	 *     Active price data
	 *
	 *     @type float      $price         Active price (sale or regular)
	 *     @type float|null $regular_price Regular price (if on sale) or null
	 *     @type bool       $on_sale       Whether sale is currently active
	 * }
	 */
	public function get_active_price_for_level( $level_id ) {
		global $wpdb;

		// Get level data
		$level = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->pmpro_membership_levels} WHERE id = %d",
			$level_id
		) );

		if ( ! $level ) {
			return array(
				'price'         => 0.0,
				'regular_price' => null,
				'on_sale'       => false,
			);
		}

		// Get sale meta (stored by REST controller and reconciliation logic)
		$sale_price = get_pmpro_membership_level_meta( $level_id, 'tutorpress_sale_price', true );
		$regular_price_meta = get_pmpro_membership_level_meta( $level_id, 'tutorpress_regular_price', true );

		// Determine regular price (priority: regular_price_meta > level.initial_payment)
		$regular = ! empty( $regular_price_meta )
			? floatval( $regular_price_meta )
			: floatval( $level->initial_payment );

		// Check if sale is active (uses helper method below)
		if ( $this->is_sale_active( $level_id, $sale_price, $regular ) ) {
			return array(
				'price'         => floatval( $sale_price ),
				'regular_price' => $regular,
				'on_sale'       => true,
			);
		}

		// No active sale
		return array(
			'price'         => $regular,
			'regular_price' => null,
			'on_sale'       => false,
		);
	}

	/**
	 * Check if a sale is currently active for a level.
	 *
	 * Validates sale price and checks if current time is within sale schedule.
	 * Uses Tutor LMS DateTimeHelper for timezone consistency.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $level_id      PMPro level ID.
	 * @param mixed $sale_price    Sale price from meta.
	 * @param float $regular_price Regular price for comparison.
	 * @return bool True if sale is active, false otherwise.
	 */
	private function is_sale_active( $level_id, $sale_price, $regular_price ) {
		// Validate sale price exists and is less than regular
		if ( empty( $sale_price ) || floatval( $sale_price ) <= 0 || floatval( $sale_price ) >= $regular_price ) {
			return false;
		}

		// Get sale schedule
		$sale_from = get_pmpro_membership_level_meta( $level_id, 'tutorpress_sale_price_from', true );
		$sale_to = get_pmpro_membership_level_meta( $level_id, 'tutorpress_sale_price_to', true );

		// If no schedule, sale is always active (open-ended sale)
		if ( empty( $sale_from ) || empty( $sale_to ) ) {
			return true;
		}

		// Check date range using Tutor LMS timezone helper (aligns with TutorPress)
		if ( class_exists( '\Tutor\Helpers\DateTimeHelper' ) ) {
			$now = \Tutor\Helpers\DateTimeHelper::now()->format( 'U' );
			$from_timestamp = \Tutor\Helpers\DateTimeHelper::create( $sale_from )->format( 'U' );
			$to_timestamp = \Tutor\Helpers\DateTimeHelper::create( $sale_to )->format( 'U' );
		} else {
			// Fallback to WordPress core (GMT/UTC)
			$now = current_time( 'timestamp', true );
			$from_timestamp = strtotime( $sale_from );
			$to_timestamp = strtotime( $sale_to );
		}

		return ( $now >= $from_timestamp && $now <= $to_timestamp );
	}

	/**
	 * Filter PMPro checkout level to apply sale price at checkout time.
	 *
	 * This ensures customers are charged the correct price based on
	 * real-time sale schedule, regardless of database initial_payment value.
	 *
	 * @since 1.0.0
	 *
	 * @param object $level PMPro level object.
	 * @return object Modified level object.
	 */
	public function filter_checkout_level_sale_price( $level ) {
		if ( empty( $level->id ) ) {
			return $level;
		}

		if ( ! empty( $level->code_id ) || ! empty( $level->discount_code ) ) {
			$this->discount_code_used_at_checkout = true;
			return $level;
		}

		$active_price = $this->get_active_price_for_level( $level->id );

		if ( $active_price['on_sale'] ) {
			$level->tutorpress_sale_applied   = true;
			$level->tutorpress_regular_price = $active_price['regular_price'];
			$this->sale_applied_at_checkout  = true;
			$level->initial_payment          = $active_price['price'];

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( sprintf(
					'[TP-PMPRO] checkout_sale_applied level_id=%d sale_price=%s regular_price=%s',
					$level->id,
					$active_price['price'],
					$active_price['regular_price']
				) );
			}
		}

		return $level;
	}

	/**
	 * Filter PMPro level cost text to show sale price with strikethrough.
	 *
	 * Applies to PMPro's membership levels page, account page, and other
	 * locations where PMPro renders pricing.
	 *
	 * IMPORTANT: Sale only applies to initial payment, never to recurring price.
	 * This filter runs at priority 999 to ensure it runs AFTER checkout_level filter.
	 *
	 * @since 1.0.0
	 *
	 * @param string $text  Cost text HTML.
	 * @param object $level PMPro level object.
	 * @param array  $tags  Template tags.
	 * @param bool   $short Whether to show short version.
	 * @return string Modified cost text HTML.
	 */
	public function filter_level_cost_text_sale_price( $text, $level, $tags, $short ) {
		if ( empty( $level->id ) ) {
			return $text;
		}

		if ( ! empty( $level->code_id ) || ! empty( $level->discount_code ) || $this->discount_code_used_at_checkout ) {
			return $text;
		}

		$active_price = $this->get_active_price_for_level( $level->id );

		if ( ! $active_price['on_sale'] || ! function_exists( 'pmpro_formatPrice' ) ) {
			return $text;
		}

		$sale_formatted    = pmpro_formatPrice( $active_price['price'] );
		$is_checkout       = ! empty( $level->tutorpress_sale_applied );
		$is_subscription   = ! empty( $level->billing_amount ) && floatval( $level->billing_amount ) > 0
		                   && ! empty( $level->cycle_number ) && intval( $level->cycle_number ) > 0;
		$is_short_format   = $is_subscription && strpos( $text, ' now ' ) === false;
		$billing_formatted = $is_subscription ? pmpro_formatPrice( $level->billing_amount ) : '';

		if ( $is_checkout ) {
			$search            = $sale_formatted;
			$regular_formatted = pmpro_formatPrice( $level->tutorpress_regular_price );
		} else {
			$regular_formatted = pmpro_formatPrice( $active_price['regular_price'] );
			$search            = $regular_formatted;
		}

		$strikethrough = '<span style="text-decoration: line-through; opacity: 0.6;">' . $regular_formatted . '</span>';

		if ( $is_short_format ) {
			$replace_with = $strikethrough . ' ' . $sale_formatted . ' now and then ' . $billing_formatted;
		} else {
			$replace_with = $strikethrough . ' ' . $sale_formatted;
		}

		$text = preg_replace_callback(
			'/' . preg_quote( $search, '/' ) . '/',
			function () use ( $replace_with ) {
				return $replace_with;
			},
			$text,
			1
		);

		return $text;
	}

	/**
	 * Filter PMPro email data to apply sale price in confirmation emails.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $data  Email template data.
	 * @param object $email PMPro email object.
	 * @return array Modified email data.
	 */
	public function filter_email_data_sale_price( $data, $email ) {
		// PMPro emails include membership level data
		if ( ! empty( $data['membership_level'] ) && ! empty( $data['membership_level']->id ) ) {
			$level_id = $data['membership_level']->id;
			$active_price = $this->get_active_price_for_level( $level_id );

			if ( $active_price['on_sale'] ) {
				$data['membership_level']->initial_payment = $active_price['price'];

				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( sprintf(
						'[TP-PMPRO] email_sale_applied level_id=%d sale_price=%s',
						$level_id,
						$active_price['price']
					) );
				}
			}
		}

		return $data;
	}

	/**
	 * Add promotional price note to PMPro invoices when sale is active.
	 *
	 * @since 1.0.0
	 *
	 * @param object $order PMPro order object.
	 * @return void
	 */
	public function filter_invoice_sale_note( $order ) {
		if ( empty( $order->membership_id ) ) {
			return;
		}

		$active_price = $this->get_active_price_for_level( $order->membership_id );

		if ( $active_price['on_sale'] && function_exists( 'pmpro_formatPrice' ) ) {
			$regular_formatted = pmpro_formatPrice( $active_price['regular_price'] );
			echo '<li><strong>' . esc_html__( 'Promotional Price Applied', 'tutorpress-pmpro' ) . '</strong> (' . esc_html__( 'Regular', 'tutorpress-pmpro' ) . ': ' . esc_html( $regular_formatted ) . ')</li>';
		}
	}
}

