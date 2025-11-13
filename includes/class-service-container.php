<?php
/**
 * Service Container
 *
 * Minimal dependency injection container for managing service instantiation.
 * Centralizes service creation and dependency wiring.
 *
 * @package TutorPress_PMPro
 * @since 1.0.0
 */

namespace TUTORPRESS_PMPRO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Service_Container
 *
 * Simple service container that manages service instantiation and dependencies.
 * Services are created once and reused (singleton pattern per service).
 *
 * @since 1.0.0
 */
class Service_Container {

	/**
	 * Service instances cache.
	 *
	 * @var array
	 */
	private static $instances = array();

	/**
	 * Get a service instance.
	 *
	 * Services are created on first access and cached for subsequent calls.
	 *
	 * @param string $service_name The service identifier.
	 * @return mixed The service instance.
	 * @throws \Exception If service is not found.
	 */
	public static function get( $service_name ) {
		if ( ! isset( self::$instances[ $service_name ] ) ) {
			self::$instances[ $service_name ] = self::create( $service_name );
		}
		return self::$instances[ $service_name ];
	}

	/**
	 * Check if a service has been instantiated.
	 *
	 * @param string $service_name The service identifier.
	 * @return bool True if service exists.
	 */
	public static function has( $service_name ) {
		return isset( self::$instances[ $service_name ] );
	}

	/**
	 * Clear all service instances (useful for testing).
	 *
	 * @return void
	 */
	public static function clear() {
		self::$instances = array();
	}

	/**
	 * Create a service instance with its dependencies.
	 *
	 * This method defines how each service is constructed and what dependencies it needs.
	 * Dependencies are automatically resolved by recursively calling get().
	 *
	 * Note: Services with circular dependencies (pricing_display, enrollment_ui) are
	 * instantiated directly in PaidMembershipsPro rather than through this container.
	 *
	 * @param string $service_name The service identifier.
	 * @return mixed The created service instance.
	 * @throws \Exception If service is not defined.
	 */
	private static function create( $service_name ) {
		switch ( $service_name ) {
			// Core services (no dependencies)
			case 'access_checker':
				return new Access\Access_Checker();

			case 'sale_price_handler':
				return new Pricing\Sale_Price_Handler();

			case 'level_settings':
				return new Admin\Level_Settings();

			case 'admin_notices':
				return new Admin\Admin_Notices();

			// Services with dependencies
			case 'enrollment_handler':
				return new Enrollment\Enrollment_Handler(
					self::get( 'access_checker' )
				);

			default:
				throw new \Exception( "Service '{$service_name}' not found in container." );
		}
	}
}

