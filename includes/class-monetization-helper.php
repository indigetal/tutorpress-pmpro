<?php
/**
 * Monetization Helper
 *
 * Centralizes safe reads of the Tutor `monetize_by` option and related helpers
 * to avoid filter recursion and improve testability.
 *
 * @package TutorPress\PMPro
 */

namespace TUTORPRESS_PMPRO;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Monetization_Helper {
    /**
     * Recursion guard to avoid re-entrancy when reading options.
     *
     * @var bool
     */
    private $guard = false;

    /**
     * Get the current monetize_by value safely.
     *
     * Reads the raw `tutor_option` DB option while the guard is set to avoid
     * triggering filters that could re-enter into monetization checks.
     *
     * @return string
     */
    public function get_monetize_by() {
        if ( $this->guard ) {
            return '';
        }

        $this->guard = true;
        $opts = get_option( 'tutor_option', array() );
        $this->guard = false;

        if ( ! is_array( $opts ) ) {
            return '';
        }

        return isset( $opts['monetize_by'] ) ? $opts['monetize_by'] : '';
    }

    /**
     * Convenience: is monetize_by === 'pmpro'
     *
     * @return bool
     */
    public function is_pmpro() {
        return 'pmpro' === $this->get_monetize_by();
    }

    /**
     * Convenience: run a callable while the internal guard is set.
     * Useful to perform raw reads/writes without triggering filters.
     *
     * @param callable $callable
     * @return mixed
     */
    public function with_silent_guard( $callable ) {
        if ( $this->guard ) {
            return call_user_func( $callable );
        }

        $this->guard = true;
        try {
            $result = call_user_func( $callable );
        } catch ( \Throwable $e ) {
            $this->guard = false;
            throw $e;
        }
        $this->guard = false;
        return $result;
    }
}


