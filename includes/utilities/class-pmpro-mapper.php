<?php
/**
 * PMPro Mapper
 *
 * Small mapping helpers to translate between TutorPress UI subscription
 * shape and PMPro membership level arrays/objects.
 *
 * @package TutorPress-PMPro
 */

defined( 'ABSPATH' ) || exit;

class TutorPress_PMPro_Mapper {

    /**
     * Map TutorPress UI payload to PMPro membership level array suitable for DB/API.
     *
     * @param array $ui
     * @return array
     */
    public function map_ui_to_pmpro( array $ui ): array {
        $regular_price      = isset( $ui['regular_price'] ) ? floatval( $ui['regular_price'] ) : 0.0;
        $recurring_value    = isset( $ui['recurring_value'] ) ? intval( $ui['recurring_value'] ) : 0;
        $recurring_interval = isset( $ui['recurring_interval'] ) ? ucfirst( strtolower( $ui['recurring_interval'] ) ) : '';
        $recurring_limit    = isset( $ui['recurring_limit'] ) ? intval( $ui['recurring_limit'] ) : 0;

        $payment_type = isset( $ui['payment_type'] ) ? $ui['payment_type'] : null;

        // One-time purchase mapping: initial payment is the one-time price, no recurring billing
        if ( 'one_time' === $payment_type ) {
            return array(
                'name'            => sanitize_text_field( $ui['plan_name'] ?? $ui['name'] ?? '' ),
                'description'     => sanitize_textarea_field( $ui['description'] ?? $ui['short_description'] ?? '' ),
                'initial_payment' => isset( $ui['regular_price'] ) ? floatval( $ui['regular_price'] ) : $regular_price,
                'billing_amount'  => 0,
                'cycle_number'    => 0,
                'cycle_period'    => '',
                'billing_limit'   => 0,
                'trial_limit'     => isset( $ui['trial_value'] ) ? intval( $ui['trial_value'] ) : 0,
                'trial_amount'    => isset( $ui['trial_fee'] ) ? floatval( $ui['trial_fee'] ) : 0.0,
                'meta'            => array(
                    'sale_price' => isset( $ui['sale_price'] ) ? $ui['sale_price'] : null,
                    'sale_price_from' => isset( $ui['sale_price_from'] ) ? $ui['sale_price_from'] : null,
                    'sale_price_to' => isset( $ui['sale_price_to'] ) ? $ui['sale_price_to'] : null,
                    'provide_certificate' => isset( $ui['provide_certificate'] ) ? (bool) $ui['provide_certificate'] : null,
                    'is_featured' => isset( $ui['is_featured'] ) ? (bool) $ui['is_featured'] : null,
                ),
            );
        }

        // Recurring/default mapping
        return array(
            'name'            => sanitize_text_field( $ui['plan_name'] ?? $ui['name'] ?? '' ),
            'description'     => sanitize_textarea_field( $ui['description'] ?? $ui['short_description'] ?? '' ),
            // initial_payment is enrollment_fee when provided, otherwise 0 (recurring plans often have no initial payment)
            'initial_payment' => isset( $ui['enrollment_fee'] ) ? floatval( $ui['enrollment_fee'] ) : 0.0,
            // Prefer explicit recurring monetary amount, fall back to regular price as last resort
            'billing_amount'  => isset( $ui['recurring_price'] ) ? floatval( $ui['recurring_price'] ) : ( isset( $ui['regular_price'] ) ? floatval( $ui['regular_price'] ) : 0.0 ),
            'cycle_number'    => $recurring_value,
            'cycle_period'    => $recurring_interval,
            'billing_limit'   => $recurring_limit,
            'trial_limit'     => isset( $ui['trial_value'] ) ? intval( $ui['trial_value'] ) : 0,
            'trial_amount'    => isset( $ui['trial_fee'] ) ? floatval( $ui['trial_fee'] ) : 0.0,
            'meta'            => array(
                'sale_price' => isset( $ui['sale_price'] ) ? $ui['sale_price'] : null,
                'sale_price_from' => isset( $ui['sale_price_from'] ) ? $ui['sale_price_from'] : null,
                'sale_price_to' => isset( $ui['sale_price_to'] ) ? $ui['sale_price_to'] : null,
                'provide_certificate' => isset( $ui['provide_certificate'] ) ? (bool) $ui['provide_certificate'] : null,
                'is_featured' => isset( $ui['is_featured'] ) ? (bool) $ui['is_featured'] : null,
            ),
        );
    }

    /**
     * Map a PMPro level (object|array) to TutorPress UI shape.
     *
     * @param object|array $level
     * @return array
     */
    public function map_pmpro_to_ui( $level ): array {
        $l = is_object( $level ) ? (array) $level : (array) $level;
        // Read level meta values (UI-only fields)
        $meta_sale = null;
        $meta_provide_certificate = null;
        $meta_is_featured = null;
        $meta_regular_initial_payment = null;
        $meta_regular_price = null;
        $meta_sale_from = null;
        $meta_sale_to = null;
        
        if ( function_exists( 'get_pmpro_membership_level_meta' ) ) {
            $meta_sale = get_pmpro_membership_level_meta( $l['id'] ?? 0, 'sale_price', true );
            $meta_provide_certificate = get_pmpro_membership_level_meta( $l['id'] ?? 0, 'provide_certificate', true );
            $meta_is_featured = get_pmpro_membership_level_meta( $l['id'] ?? 0, 'is_featured', true );
            
            // Check for stored regular price (used when sale is active)
            $meta_regular_price = get_pmpro_membership_level_meta( $l['id'] ?? 0, 'tutorpress_regular_price', true );
            
            // Check for sale schedule dates (Step 3.3)
            $meta_sale_from = get_pmpro_membership_level_meta( $l['id'] ?? 0, 'tutorpress_sale_price_from', true );
            $meta_sale_to = get_pmpro_membership_level_meta( $l['id'] ?? 0, 'tutorpress_sale_price_to', true );
        }

        // Derive payment_type from PMPro level data
        // If billing_amount is 0 and cycle_number is 0, it's a one-time purchase
        $billing_amount = isset( $l['billing_amount'] ) ? floatval( $l['billing_amount'] ) : 0.0;
        $cycle_number = isset( $l['cycle_number'] ) ? intval( $l['cycle_number'] ) : 0;
        $payment_type = ( $billing_amount <= 0 && $cycle_number === 0 ) ? 'one_time' : 'recurring';

        // Determine enrollment_fee: if sale is active, use regular from meta; otherwise use current initial_payment
        $enrollment_fee = isset( $l['initial_payment'] ) ? floatval( $l['initial_payment'] ) : 0.0;
        
        // If regular price meta exists (sale is active), use it for the UI's enrollment_fee field
        if ( ! empty( $meta_regular_price ) ) {
            $enrollment_fee = floatval( $meta_regular_price );
        }

        return array(
            'id'                => (int) ( $l['id'] ?? 0 ),
            'plan_name'         => $l['name'] ?? '',
            'description'       => $l['description'] ?? '',
            // regular_price here represents the recurring/renewal price (billing_amount)
            'regular_price'     => $billing_amount,
            // enrollment_fee is the initial payment (regular, not sale price)
            'enrollment_fee'    => $enrollment_fee,
            'payment_type'      => $payment_type,
            'recurring_value'   => $cycle_number,
            // Normalize interval to lowercase token expected by the UI (e.g. 'month', 'week')
            'recurring_interval'=> isset( $l['cycle_period'] ) ? strtolower( trim( $l['cycle_period'] ) ) : '',
            'recurring_limit'   => isset( $l['billing_limit'] ) ? intval( $l['billing_limit'] ) : 0,
            'trial_value'       => isset( $l['trial_limit'] ) ? intval( $l['trial_limit'] ) : 0,
            'trial_fee'         => isset( $l['trial_amount'] ) ? floatval( $l['trial_amount'] ) : 0.0,
            'sale_price'        => ( $meta_sale !== '' && $meta_sale !== null && $meta_sale !== false ) ? $meta_sale : null,
            'sale_price_from'   => ( $meta_sale_from !== '' && $meta_sale_from !== null && $meta_sale_from !== false ) ? $meta_sale_from : null,
            'sale_price_to'     => ( $meta_sale_to !== '' && $meta_sale_to !== null && $meta_sale_to !== false ) ? $meta_sale_to : null,
            'provide_certificate'=> is_null( $meta_provide_certificate ) ? null : (bool) $meta_provide_certificate,
            'is_featured'       => is_null( $meta_is_featured ) ? null : (bool) $meta_is_featured,
            'status'            => 'active',
        );
    }

}


