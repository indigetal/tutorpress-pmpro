<?php
/**
 * Paid Membership Pro Integration Init
 *
 * @package TutorPress
 * @subpackage PMPro
 * @author Indigetal WebCraft <support@indigetal.com>
 * @link https://indigetal.com
 * @since 0.1.0
 */

namespace TUTORPRESS_PMPRO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Init
 */
class Init {
	//phpcs:disable
	public $version = TUTORPRESS_PMPRO_VERSION;
	public $path;
	public $url;
	public $basename;
	private $paid_memberships_pro;
	//phpcs:enable

	/**
	 * Constructor
	 */
	public function __construct() {
		// Ensure Tutor LMS is active.
		if ( ! function_exists( 'tutor' ) ) {
			add_action( 'admin_notices', array( $this, 'notice_tutor_missing' ) );
			return;
		}

		// Ensure PMPro is active.
		if ( ! function_exists( 'pmpro_getAllLevels' ) ) {
			add_action( 'admin_notices', array( $this, 'notice_pmpro_missing' ) );
			return;
		}

		// Ensure TutorPress is active.
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! is_plugin_active( 'tutorpress/tutorpress.php' ) ) {
			add_action( 'admin_notices', array( $this, 'notice_tutorpress_missing' ) );
			return;
		}

        // Adding monetization options to core.
        add_filter( 'tutor_monetization_options', array( $this, 'tutor_monetization_options' ) );

        $has_pmpro   = tutor_utils()->has_pmpro();

        // Only load the PMPro integration when the Paid Memberships Pro plugin exists.
        // Previously this was gated on the Tutor monetization option (monetize_by === 'pmpro'),
        // which prevented the PMPro level UI from appearing unless the admin had already
        // selected PMPro as the site's eCommerce engine. Load whenever PMPro is present so
        // admins can configure levels even before switching the monetization option.
        if ( ! $has_pmpro ) {
            return;
        }

		$this->path     = plugin_dir_path( TUTORPRESS_PMPRO_FILE );
		$this->url      = plugin_dir_url( TUTORPRESS_PMPRO_FILE );
		$this->basename = plugin_basename( TUTORPRESS_PMPRO_FILE );

		$this->load_TUTORPRESS_PMPRO();
	}

	public function notice_tutor_missing() {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Tutor LMS is required for the Tutor PMPro integration to work.', 'tutorpress-pmpro' ) . '</p></div>';
	}

	public function notice_pmpro_missing() {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Paid Memberships Pro is required for the Tutor PMPro integration to work.', 'tutorpress-pmpro' ) . '</p></div>';
	}

	public function notice_tutorpress_missing() {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'TutorPress (Gutenberg addon) is required for the Tutor PMPro integration to work.', 'tutorpress-pmpro' ) . '</p></div>';
	}

	/**
	 * Load tutor pmpro
	 *
	 * @return void
	 */
	public function load_TUTORPRESS_PMPRO() {
		spl_autoload_register( array( $this, 'loader' ) );
		$this->paid_memberships_pro = new PaidMembershipsPro();
	}

	/**
	 * Auto Load class and the files
	 *
	 * @param string $class_name class name.
	 *
	 * @return void
	 */
	private function loader( $class_name ) {
		if ( ! class_exists( $class_name ) ) {
			$class_name = preg_replace(
				array( '/([a-z])([A-Z])/', '/\\\/' ),
				array( '$1$2', DIRECTORY_SEPARATOR ),
				$class_name
			);

			$class_name = str_replace( 'TUTORPRESS_PMPRO' . DIRECTORY_SEPARATOR, 'classes' . DIRECTORY_SEPARATOR, $class_name );
			$file_name  = $this->path . $class_name . '.php';

			if ( file_exists( $file_name ) ) {
				require_once $file_name;
			}
		}
	}

	/**
	 * Paid membership pro label
	 *
	 * Check if main pmpro and Tutor's pmpro addons is activated or not
	 *
	 * @since 1.3.6
	 *
	 * @param array $arr attributes.
	 *
	 * @return mixed
	 */
	public function tutor_monetization_options( $arr ) {
        $has_pmpro = tutor_utils()->has_pmpro();
        if ( $has_pmpro ) {
            $arr['pmpro'] = __( 'Paid Memberships Pro', 'tutorpress-pmpro' );
        }
		return $arr;
	}

}
