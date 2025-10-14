<?php
/**
 * Menu handler.
 *
 * @package TutorPro\ContentBank
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.7.0
 */

namespace TutorPro\ContentBank;

/**
 * Menu Class.
 *
 * @since 3.7.0
 */
class Menu {

	const PAGE_SLUG = 'tutor-content-bank';

	/**
	 * Register hooks and dependencies
	 *
	 * @since 3.7.0
	 *
	 * @param bool $register_hooks whether to register hooks or not.
	 */
	public function __construct( $register_hooks = true ) {
		if ( ! $register_hooks ) {
			return;
		}

		add_filter( 'tutor_admin_menu', array( $this, 'register_admin_menu' ) );
	}

	/**
	 * Register admin menu.
	 *
	 * @since 3.7.0
	 * @since 3.8.0 param $menu added.
	 *
	 * @param array $menu menu.
	 *
	 * @return array
	 */
	public function register_admin_menu( $menu ) {
		$menu['group_one']['content_bank'] = array(
			'parent_slug' => 'tutor',
			'page_title'  => __( 'Content Bank', 'tutor-pro' ),
			'menu_title'  => __( 'Content Bank', 'tutor-pro' ) . sprintf( ' <span class="tutor-new-menu-badge">%s</span>', __( 'New', 'tutor-pro' ) ),
			'capability'  => 'manage_tutor_instructor',
			'menu_slug'   => self::PAGE_SLUG,
			'callback'    => array( $this, 'admin_content_bank_view' ),
		);

		return $menu;
	}

	/**
	 * Show admin content bank list page.
	 *
	 * @since 3.7.0
	 *
	 * @return void
	 */
	public function admin_content_bank_view() {
		include_once Helper::view_path( 'collection-list.php' );
	}
}
