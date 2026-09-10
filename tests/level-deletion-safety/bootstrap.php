<?php
function tutorpress_pmpro_lds_fail( $message ) { fwrite( STDERR, 'FAIL ' . $message . "\n" ); throw new RuntimeException( $message ); }
function tutorpress_pmpro_lds_assert( $condition, $message ) { $condition || tutorpress_pmpro_lds_fail( $message ); }
function tutorpress_pmpro_lds_pass( $message ) { echo 'PASS ' . $message . "\n"; }
function tutorpress_pmpro_lds_require_local_site() {
	$root = realpath( '/www/kinsta/public/tutorpress' ); $abs = defined( 'ABSPATH' ) ? realpath( ABSPATH ) : false;
	tutorpress_pmpro_lds_assert( 8 === PHP_MAJOR_VERSION && 2 === PHP_MINOR_VERSION && false !== $root && false !== $abs && ( $abs === $root || 0 === strpos( $abs, $root . '/' ) ), 'unexpected local site' );
}
$GLOBALS['tutorpress_pmpro_lds_reg'] = array( 'posts' => array(), 'hooks' => array(), 'transients' => array(), 'locks' => array(), 'cache' => array(), 'wpdb' => array() );
function tutorpress_pmpro_lds_cleanup() {
	global $wpdb; $r = &$GLOBALS['tutorpress_pmpro_lds_reg'];
	foreach ( $r['hooks'] as $h ) { $p = isset( $h[2] ) ? $h[2] : 10; remove_action( $h[0], $h[1], $p ); remove_filter( $h[0], $h[1], $p ); }
	foreach ( $r['transients'] as $k ) { delete_transient( $k ); }
	foreach ( $r['locks'] as $n ) { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $n ) ); }
	foreach ( $r['cache'] as $c ) { wp_cache_delete( $c[0], isset( $c[1] ) ? $c[1] : '' ); }
	foreach ( $r['wpdb'] as $p => $v ) { $wpdb->$p = $v; }
	foreach ( $r['posts'] as $id ) { wp_delete_post( (int) $id, true ); }
	$r = array( 'posts' => array(), 'hooks' => array(), 'transients' => array(), 'locks' => array(), 'cache' => array(), 'wpdb' => array() );
}
