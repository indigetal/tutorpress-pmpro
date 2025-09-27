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

        return array(
            'name'            => sanitize_text_field( $ui['plan_name'] ?? $ui['name'] ?? '' ),
            'description'     => sanitize_textarea_field( $ui['description'] ?? $ui['short_description'] ?? '' ),
            'initial_payment' => isset( $ui['enrollment_fee'] ) ? floatval( $ui['enrollment_fee'] ) : $regular_price,
            'billing_amount'  => isset( $ui['recurring_price'] ) ? floatval( $ui['recurring_price'] ) : ( isset( $ui['recurring_value'] ) ? floatval( $ui['recurring_value'] ) : 0.0 ),
            'cycle_number'    => $recurring_value,
            'cycle_period'    => $recurring_interval,
            'billing_limit'   => $recurring_limit,
            'trial_limit'     => isset( $ui['trial_value'] ) ? intval( $ui['trial_value'] ) : 0,
            'trial_amount'    => isset( $ui['trial_fee'] ) ? floatval( $ui['trial_fee'] ) : 0.0,
            'meta'            => array(
                'sale_price' => isset( $ui['sale_price'] ) ? $ui['sale_price'] : null,
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
        $meta_sale = null;
        if ( function_exists( 'get_pmpro_membership_level_meta' ) ) {
            $meta_sale = get_pmpro_membership_level_meta( $l['id'] ?? 0, 'sale_price', true );
        }
        return array(
            'id'                => (int) ( $l['id'] ?? 0 ),
            'plan_name'         => $l['name'] ?? '',
            'description'       => $l['description'] ?? '',
            'regular_price'     => isset( $l['initial_payment'] ) ? floatval( $l['initial_payment'] ) : 0.0,
            'recurring_price'   => isset( $l['billing_amount'] ) ? floatval( $l['billing_amount'] ) : 0.0,
            'recurring_interval'=> $l['cycle_period'] ?? '',
            'recurring_limit'   => isset( $l['cycle_number'] ) ? intval( $l['cycle_number'] ) : 0,
            'trial_value'       => isset( $l['trial_limit'] ) ? intval( $l['trial_limit'] ) : 0,
            'trial_fee'         => isset( $l['trial_amount'] ) ? floatval( $l['trial_amount'] ) : 0.0,
            'sale_price'        => $meta_sale ?? null,
            'status'            => 'active',
        );
    }

}


