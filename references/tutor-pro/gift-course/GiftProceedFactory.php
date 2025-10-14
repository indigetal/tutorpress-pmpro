<?php
/**
 * Gift order factory
 *
 * @package Tutor\Ecommerce
 * @author Themeum
 * @link https://themeum.com
 * @since 3.8.0
 */

namespace TutorPro\GiftCourse;

/**
 * Class for creating order instances
 *
 * @since 3.8.0
 */
class GiftProceedFactory {

	/**
	 * Get order instance
	 *
	 * @since 3.8.0
	 *
	 * @throws \Exception If monetization is not wc or tutor.
	 *
	 * @param string $monetization_type Monetization type.
	 *
	 * @return GiftProceeder
	 */
	public static function get_proceeder( string $monetization_type ): GiftProceeder {
		switch ( $monetization_type ) {
			case 'wc':
				return new WooGiftProceeder();
			case 'tutor':
				return new NativeGiftProceeder();
			default:
				throw new \Exception( 'Invalid monetization type' );
		}
	}
}
