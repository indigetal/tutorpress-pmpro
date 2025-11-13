<?php
/**
 * Pricing Manager
 *
 * Handles price formatting and display for courses with PMPro integration.
 * Provides static utility methods for resolving and formatting course prices.
 *
 * @package TutorPress_PMPro
 * @subpackage Pricing
 * @since 1.0.0
 */

namespace TUTORPRESS_PMPRO\Pricing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pricing Manager class.
 *
 * Static utility class for price formatting and display.
 * Resolves course prices from associated PMPro membership levels.
 *
 * @since 1.0.0
 */
class Pricing_Manager {
	/**
	 * Return formatted price string for a course based on associated PMPro levels.
	 * Returns null when PMPro is not the active monetization engine or when
	 * course is public/free or no associated levels found.
	 *
	 * @since 1.0.0
	 *
	 * @param int $course_id Course post ID.
	 * @return string|null Formatted price string or null.
	 */
	public static function get_formatted_price( $course_id ) {
		$course_id = absint( $course_id );
		if ( ! $course_id ) {
			return null;
		}

		// Debug: trace pricing resolution for this course
		self::log( '[TP-PMPRO] pricing: course_id=' . $course_id );

		if ( ! self::is_pmpro_enabled() ) {
			return null;
		}

		if ( self::is_course_public_or_free( $course_id ) ) {
			return __( 'Free', 'tutorpress-pmpro' );
		}

		$level_ids = self::get_associated_level_ids( $course_id );
		self::log( '[TP-PMPRO] pricing: assoc_level_ids=' . json_encode( $level_ids ) );
		if ( empty( $level_ids ) ) {
			return null;
		}

		$prices = array();
		$recurring = array();
		$one_time = array();

		foreach ( $level_ids as $lid ) {
			$level = function_exists( 'pmpro_getLevel' ) ? \pmpro_getLevel( $lid ) : null;
			if ( ! $level ) {
				self::log( '[TP-PMPRO] pricing: level_missing id=' . $lid );
				continue;
			}

			$initial_payment = isset( $level->initial_payment ) ? (float) $level->initial_payment : 0.0;
			$billing_amount  = isset( $level->billing_amount ) ? (float) $level->billing_amount : 0.0;
			$cycle_number    = isset( $level->cycle_number ) ? (int) $level->cycle_number : 0;
			$cycle_period    = isset( $level->cycle_period ) ? strtolower( (string) $level->cycle_period ) : '';

			if ( $billing_amount > 0 && $cycle_number > 0 && $cycle_period ) {
				$recurring[] = array( 'amount' => $billing_amount, 'period' => $cycle_period );
				$prices[]    = $billing_amount;
			} else {
				$one_time[] = $initial_payment;
				$prices[]   = $initial_payment;
			}

			self::log( '[TP-PMPRO] pricing: level=' . $lid . ' init=' . $initial_payment . ' bill_amt=' . $billing_amount . ' cycle=' . $cycle_number . ' period=' . $cycle_period );
		}

		if ( empty( $prices ) ) {
			self::log( '[TP-PMPRO] pricing: no_prices_resolved' );
			return null;
		}

		list( $currency_symbol, $currency_position ) = self::get_currency();

		if ( count( $prices ) === 1 ) {
			if ( ! empty( $recurring ) ) {
				$entry = $recurring[0];
				$val = sprintf( '%s %s/%s', self::format_amount( $entry['amount'], $currency_symbol, $currency_position ), self::per_label(), self::period_label( $entry['period'] ) );
				self::log( '[TP-PMPRO] pricing: resolved_recurring=' . $val );
				return $val;
			}
			$val = sprintf( '%s %s', self::format_amount( $prices[0], $currency_symbol, $currency_position ), __( 'one-time', 'tutorpress-pmpro' ) );
			self::log( '[TP-PMPRO] pricing: resolved_one_time=' . $val );
			return $val;
		}

		$min = min( $prices );
		$val = sprintf( '%s %s', __( 'Starts from', 'tutorpress-pmpro' ), self::format_amount( $min, $currency_symbol, $currency_position ) );
		self::log( '[TP-PMPRO] pricing: resolved_min=' . $val );
		return $val;
	}

	/**
	 * Get PMPro level IDs associated with a course via pmpro_memberships_pages.
	 *
	 * @since 1.0.0
	 *
	 * @param int $course_id Course post ID.
	 * @return int[] Array of level IDs.
	 */
	private static function get_associated_level_ids( $course_id ) {
		global $wpdb;
		$level_ids = array();
		if ( isset( $wpdb->pmpro_memberships_pages ) ) {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT membership_id FROM {$wpdb->pmpro_memberships_pages} WHERE page_id = %d", $course_id ) );
			if ( is_array( $ids ) ) {
				$level_ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
			}
		}
		return $level_ids;
	}

	/**
	 * Determine if PMPro is the active monetization engine.
	 *
	 * @since 1.0.0
	 * @return bool True if PMPro is enabled, false otherwise.
	 */
	private static function is_pmpro_enabled() {
		$forced = apply_filters( 'tutorpress_pmpro_enabled', null );
		if ( is_bool( $forced ) ) {
			return $forced;
		}
		if ( function_exists( 'tutorpress_monetization' ) ) {
			return (bool) \tutorpress_monetization()->is_pmpro();
		}
		if ( function_exists( 'get_tutor_option' ) ) {
			return 'pmpro' === \get_tutor_option( 'monetize_by' );
		}
		return false;
	}

	/**
	 * Check if course is public or marked free in Tutor.
	 *
	 * @since 1.0.0
	 *
	 * @param int $course_id Course post ID.
	 * @return bool True if public or free, false otherwise.
	 */
	private static function is_course_public_or_free( $course_id ) {
		$is_public = get_post_meta( $course_id, '_tutor_is_public_course', true ) === 'yes';
		$is_free   = get_post_meta( $course_id, '_tutor_course_price_type', true ) === 'free';
		return $is_public || $is_free;
	}

	/**
	 * Get PMPro currency settings.
	 *
	 * Public utility method for retrieving currency symbol and position
	 * from PMPro's global configuration. Used by frontend pricing display
	 * and admin UI components.
	 *
	 * @since 1.0.0
	 * @return array Associative array with 'currency_symbol' and 'currency_position' keys.
	 */
	public static function get_pmpro_currency() {
		$symbol = '';
		$position = 'left';
		if ( function_exists( 'pmpro_getOption' ) ) {
			global $pmpro_currencies, $pmpro_currency;
			$curr = $pmpro_currency ? $pmpro_currency : '';
			$data = ( $curr === 'USD' ) ? array( 'symbol' => '$', 'position' => 'left' ) : ( isset( $pmpro_currencies[ $curr ] ) ? $pmpro_currencies[ $curr ] : null );
			$symbol   = ( is_array( $data ) && isset( $data['symbol'] ) ) ? $data['symbol'] : '';
			$position = ( is_array( $data ) && isset( $data['position'] ) ) ? strtolower( $data['position'] ) : 'left';
		}
		return array( 
			'currency_symbol'   => $symbol,
			'currency_position' => $position
		);
	}

	/**
	 * Internal helper for get_currency() (backward compatibility).
	 *
	 * @since 1.0.0
	 * @return array [symbol, position]
	 */
	private static function get_currency() {
		$result = self::get_pmpro_currency();
		return array( $result['currency_symbol'], $result['currency_position'] );
	}

	/**
	 * Format price amount with currency symbol.
	 *
	 * @since 1.0.0
	 *
	 * @param float  $amount   Price amount.
	 * @param string $symbol   Currency symbol.
	 * @param string $position Currency position (left, left_space, right, right_space).
	 * @return string Formatted price.
	 */
	private static function format_amount( $amount, $symbol, $position ) {
		$amount = number_format_i18n( (float) $amount, 2 );
		if ( $symbol ) {
			if ( 'left_space' === $position ) {
				return $symbol . ' ' . $amount;
			} elseif ( 'right' === $position ) {
				return $amount . $symbol;
			} elseif ( 'right_space' === $position ) {
				return $amount . ' ' . $symbol;
			}
		}
		return $symbol . $amount;
	}

	/**
	 * Get "per" label for recurring pricing.
	 *
	 * @since 1.0.0
	 * @return string Translated "per" label.
	 */
	private static function per_label() {
		return __( 'per', 'tutorpress-pmpro' );
	}

	/**
	 * Get translated period label.
	 *
	 * @since 1.0.0
	 *
	 * @param string $period Period string (day, week, month, year).
	 * @return string Translated period label.
	 */
	private static function period_label( $period ) {
		$period = strtolower( (string) $period );
		switch ( $period ) {
			case 'day':
				return __( 'day', 'tutorpress-pmpro' );
			case 'week':
				return __( 'week', 'tutorpress-pmpro' );
			case 'month':
				return __( 'month', 'tutorpress-pmpro' );
			case 'year':
				return __( 'year', 'tutorpress-pmpro' );
			default:
				return esc_html( $period );
		}
	}

	/**
	 * Log debug message if TP_PMPRO_LOG is enabled.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message Log message.
	 * @return void
	 */
	private static function log( $message ) {
		if ( defined( 'TP_PMPRO_LOG' ) && TP_PMPRO_LOG ) {
			error_log( $message );
		}
	}
}

