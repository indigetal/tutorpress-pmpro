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
		private $monetization_helper;
		private $recursion_guard = false;
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

		// Also register our addon/option shims early so they exist when Tutor Pro
		// builds its addons list and performs runtime monetization checks.
		add_filter( 'tutor_addons_lists_config', array( $this, 'allow_course_bundle_for_pmpro' ), 20 );
		add_filter( 'tutor_get_option', array( $this, 'intercept_monetize_by_for_course_bundle' ), 10, 2 );

		// Ensure bundles appear in the Tutor admin course list even if our
		// Course Bundle addon filters didn't register in time. This mirrors
		// Tutor Pro's BundleList::add_bundle_list behaviour.
		add_filter( 'tutor_admin_course_list', array( $this, 'allow_bundles_in_admin_course_list' ), 10, 4 );

		// Force load Course Bundle addon classes when PMPro is selected so the addon
		// functionality works even though the monetization check would normally block it.
		add_action( 'init', array( $this, 'force_load_course_bundle_for_pmpro' ), 20 );

		// Ensure course-bundle post type is recognized as valid by Tutor.
		add_filter( 'tutor_check_course_post_type', array( $this, 'allow_bundle_post_type' ), 10, 2 );
		
		// Ensure tutor_bundle_post_type filter returns course-bundle when PMPro is selected
		add_filter( 'tutor_bundle_post_type', array( $this, 'ensure_bundle_post_type_for_pmpro' ), 10, 1 );

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
		// Instantiate monetization helper for safe option reads
		if ( file_exists( $this->path . 'includes/class-monetization-helper.php' ) ) {
			require_once $this->path . 'includes/class-monetization-helper.php';
			$this->monetization_helper = new Monetization_Helper();
		}

		// Defer loading of REST controllers until REST API initialization so TutorPress core
		// classes (e.g. TutorPress_REST_Controller) are loaded first. This prevents fatal
		// errors when the controller class extends core controller classes.
		$rest_controller = $this->path . 'includes/rest/class-pmpro-subscriptions-controller.php';
		add_action( 'rest_api_init', function() use ( $rest_controller ) {
			if ( ! file_exists( $rest_controller ) ) {
				return;
			}
			require_once $rest_controller;
			// Only register routes if TutorPress base controller is available
			if ( class_exists( 'TutorPress_REST_Controller' ) && class_exists( '\\TutorPress_PMPro_Subscriptions_Controller' ) ) {
				$controller = new \TutorPress_PMPro_Subscriptions_Controller();
				$controller->register_routes();
			}
		} );
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

			// Map our project namespace root to the includes/ directory
			$class_name = str_replace( 'TUTORPRESS_PMPRO' . DIRECTORY_SEPARATOR, 'includes' . DIRECTORY_SEPARATOR, $class_name );
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

		// Ensure Course Bundle in Tutor Pro is allowed when PMPro is selected.
		// We hook into the addons list config and make a best-effort shim so the
		// Course Bundle addon won't be blocked when `monetize_by` is `pmpro`.
		add_filter( 'tutor_addons_lists_config', array( $this, 'allow_course_bundle_for_pmpro' ), 20 );
		
		// Also intercept the runtime monetization check so Course Bundle classes load.
		add_filter( 'tutor_get_option', array( $this, 'intercept_monetize_by_for_course_bundle' ), 10, 2 );
		
		// Override is_monetize_by_tutor() for Course Bundle contexts when PMPro is selected
		add_filter( 'pre_option_tutor_option', array( $this, 'intercept_tutor_utils_for_course_bundle' ), 10, 2 );
		return $arr;
	}

	/**
	 * Adjust addon config so Course Bundle is allowed when PMPro is selected.
	 *
	 * @param array $addons
	 * @return array
	 */
	public function allow_course_bundle_for_pmpro( $addons ) {
		if ( ! function_exists( 'tutor_utils' ) ) {
			return $addons;
		}

		// Only proceed when PMPro plugin is present.
		if ( ! tutor_utils()->has_pmpro() ) {
			return $addons;
		}

		// Prefer helper to avoid duplicating guard logic
		if ( isset( $this->monetization_helper ) ) {
			if ( ! $this->monetization_helper->is_pmpro() ) {
				return $addons;
			}
		} else {
			// Fallback: raw DB read under guard
			$this->recursion_guard = true;
			$options = get_option( 'tutor_option', array() );
			$monetize_by = isset( $options['monetize_by'] ) ? $options['monetize_by'] : '';
			$this->recursion_guard = false;
			if ( 'pmpro' !== $monetize_by ) {
				return $addons;
			}
		}

		foreach ( $addons as $key => $addon ) {
			$is_course_bundle = ( isset( $addon['name'] ) && 'Course Bundle' === $addon['name'] ) || ( isset( $addon['path'] ) && false !== strpos( $addon['path'], 'course-bundle' ) );
			if ( $is_course_bundle ) {
				$addons[ $key ]['required_settings'] = false;
				$addons[ $key ]['required_title']    = '';
				$addons[ $key ]['required_message']  = '';
			}
		}

		return $addons;
	}

	/**
	 * Ensure bundles are included in the admin course list query args.
	 * This filter mirrors TutorPro\CourseBundle\Backend\BundleList::add_bundle_list
	 * and adds the `course-bundle` post type into the admin listing when needed.
	 *
	 * @param array  $args
	 * @param int    $user_id
	 * @param string $status
	 * @param bool   $all_post_types
	 * @return array
	 */
	public function allow_bundles_in_admin_course_list( $args, $user_id, $status, $all_post_types ) {
		// Only run on Tutor admin page
		if ( ! function_exists( 'Input' ) ) {
			return $args;
		}

		$post_type = \TUTOR\Input::get( 'post-type', '' );

		// If the current post_type is tutor course post type, leave args alone.
		if ( function_exists( 'tutor' ) && tutor()->course_post_type === $post_type ) {
			return $args;
		}

		// Add bundle post type into the query args
		if ( isset( $args['post_type'] ) ) {
			if ( ! $all_post_types && 'course-bundle' === $post_type ) {
				$args['post_type'] = 'course-bundle';
			} else {
				$args['post_type'] = array( $args['post_type'], 'course-bundle' );
			}
		}

		return $args;
	}

	/**
	 * Intercept monetize_by option to allow Course Bundle when PMPro is selected.
	 *
	 * @param mixed  $value  Option value.
	 * @param string $key    Option key.
	 * @return mixed
	 */
	public function intercept_monetize_by_for_course_bundle( $value, $key ) {
		// Prevent infinite recursion
		if ( $this->recursion_guard ) {
			return $value;
		}

		// Only intercept the monetize_by option.
		if ( 'monetize_by' !== $key ) {
			return $value;
		}

		// Only proceed if PMPro is present and selected.
		if ( ! function_exists( 'tutor_utils' ) || ! tutor_utils()->has_pmpro() ) {
			return $value;
		}

		if ( 'pmpro' !== $value ) {
			return $value;
		}

		// Check if we're in a Course Bundle context by looking at the call stack.
		$backtrace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 15 );
		foreach ( $backtrace as $frame ) {
			if ( isset( $frame['class'] ) && false !== strpos( $frame['class'], 'CourseBundle' ) ) {
				// Return 'tutor' so Course Bundle sees an allowed monetization type.
				return 'tutor';
			}
			if ( isset( $frame['file'] ) && false !== strpos( $frame['file'], 'course-bundle' ) ) {
				// Also catch file-based calls within Course Bundle addon
				return 'tutor';
			}
		}

		return $value;
	}


	/**
	 * Force load Course Bundle addon functionality when PMPro is selected.
	 * 
	 * The Course Bundle addon has monetization checks that prevent it from loading
	 * when PMPro is selected. This method bypasses those checks by manually
	 * instantiating the Course Bundle classes that are needed.
	 *
	 * @return void
	 */
	public function force_load_course_bundle_for_pmpro() {
		// Only proceed if PMPro is present and selected.
		if ( ! function_exists( 'tutor_utils' ) || ! tutor_utils()->has_pmpro() ) {
			return;
		}

		// Set recursion guard and get monetize_by directly from database to avoid filter loops
		$this->recursion_guard = true;
		$options = get_option( 'tutor_option', array() );
		$monetize_by = isset( $options['monetize_by'] ) ? $options['monetize_by'] : '';
		$this->recursion_guard = false;

		if ( 'pmpro' !== $monetize_by ) {
			return;
		}

		// Check if Course Bundle is enabled in addon settings.
		$course_bundle_basename = 'tutor-pro/addons/course-bundle/course-bundle.php';
		if ( ! tutor_utils()->is_addon_enabled( $course_bundle_basename ) ) {
			return;
		}

		// Path to Course Bundle files - adjust based on actual structure.
		$bundle_path = ABSPATH . 'wp-content/plugins/tutor-pro/addons/course-bundle/';
		
		// Check if Course Bundle files exist.
		if ( ! file_exists( $bundle_path . 'src/Backend/BundleList.php' ) ) {
			return;
		}

		// Manually load and instantiate the Course Bundle classes we need.
		$this->load_course_bundle_classes( $bundle_path );
	}

	/**
	 * Load Course Bundle classes manually.
	 *
	 * @param string $bundle_path Path to Course Bundle addon.
	 * @return void
	 */
	private function load_course_bundle_classes( $bundle_path ) {
		// Include required files for full Course Bundle functionality.
		$required_files = array(
			'src/CustomPosts/PostInterface.php',
			'src/CustomPosts/CourseBundle.php',
			'src/CustomPosts/RegisterPosts.php',
			'src/CustomPosts/ManagePostMeta.php',
			'src/Models/BundleModel.php',
			'src/Utils.php',
			'src/Backend/BundleList.php',
			'src/Backend/Menu.php',
			'src/Frontend/Dashboard.php',
			'src/Frontend/DashboardMenu.php',
			'src/Frontend/MyBundleList.php',
			'src/Frontend/BundleDetails.php',
			'src/Frontend/BundleBuilder.php',
			'src/Frontend/BundleArchive.php',
			'src/Frontend/Enrollments.php',
			'src/Assets.php',
			'src/Ajax.php',
		);

		foreach ( $required_files as $file ) {
			$file_path = $bundle_path . $file;
			if ( file_exists( $file_path ) ) {
				require_once $file_path;
			}
		}

		// Instantiate the essential Course Bundle classes.
		if ( class_exists( 'TutorPro\CourseBundle\CustomPosts\RegisterPosts' ) ) {
			$register_posts = new \TutorPro\CourseBundle\CustomPosts\RegisterPosts();
			// Also register the post types immediately to avoid timing issues
			\TutorPro\CourseBundle\CustomPosts\RegisterPosts::register_post_types();
		}
		if ( class_exists( 'TutorPro\CourseBundle\Backend\BundleList' ) ) {
			new \TutorPro\CourseBundle\Backend\BundleList();
		}
		if ( class_exists( 'TutorPro\CourseBundle\Backend\Menu' ) ) {
			new \TutorPro\CourseBundle\Backend\Menu();
		}
		if ( class_exists( 'TutorPro\CourseBundle\Frontend\Dashboard' ) ) {
			new \TutorPro\CourseBundle\Frontend\Dashboard();
		}
		if ( class_exists( 'TutorPro\CourseBundle\Frontend\MyBundleList' ) ) {
			new \TutorPro\CourseBundle\Frontend\MyBundleList();
		}
		if ( class_exists( 'TutorPro\CourseBundle\Frontend\BundleDetails' ) ) {
			new \TutorPro\CourseBundle\Frontend\BundleDetails();
		}
		if ( class_exists( 'TutorPro\CourseBundle\Frontend\BundleBuilder' ) ) {
			new \TutorPro\CourseBundle\Frontend\BundleBuilder();
		}
		if ( class_exists( 'TutorPro\CourseBundle\CustomPosts\ManagePostMeta' ) ) {
			new \TutorPro\CourseBundle\CustomPosts\ManagePostMeta();
		}
		if ( class_exists( 'TutorPro\CourseBundle\Assets' ) ) {
			new \TutorPro\CourseBundle\Assets();
		}
		if ( class_exists( 'TutorPro\CourseBundle\Ajax' ) ) {
			new \TutorPro\CourseBundle\Ajax();
		}
	}

	/**
	 * Allow course-bundle post type to be recognized as valid by Tutor.
	 *
	 * @param bool   $is_valid_type Whether the post type is valid.
	 * @param string $post_type     The post type being checked.
	 * @return bool
	 */
	public function allow_bundle_post_type( $is_valid_type, $post_type ) {
		// Only proceed if PMPro is present and selected.
		if ( ! function_exists( 'tutor_utils' ) || ! tutor_utils()->has_pmpro() ) {
			return $is_valid_type;
		}

		// Use monetization helper if available to avoid duplicated guard logic
		if ( isset( $this->monetization_helper ) ) {
			if ( ! $this->monetization_helper->is_pmpro() ) {
				return $is_valid_type;
			}
		} else {
			// Fallback: read raw option under guard to avoid filter recursion
			$this->recursion_guard = true;
			$options = get_option( 'tutor_option', array() );
			$monetize_by = isset( $options['monetize_by'] ) ? $options['monetize_by'] : '';
			$this->recursion_guard = false;
			if ( 'pmpro' !== $monetize_by ) {
				return $is_valid_type;
			}
		}

		// Allow course-bundle post type.
		if ( 'course-bundle' === $post_type ) {
			return true;
		}

		return $is_valid_type;
	}

	/**
	 * Ensure bundle post type is properly set when PMPro is selected.
	 *
	 * @param string $post_type The bundle post type.
	 * @return string
	 */
	public function ensure_bundle_post_type_for_pmpro( $post_type ) {
		// Only proceed if PMPro is present and selected.
		if ( ! function_exists( 'tutor_utils' ) || ! tutor_utils()->has_pmpro() ) {
			return $post_type;
		}

		if ( isset( $this->monetization_helper ) ) {
			if ( ! $this->monetization_helper->is_pmpro() ) {
				return $post_type;
			}
		} else {
			$this->recursion_guard = true;
			$options = get_option( 'tutor_option', array() );
			$monetize_by = isset( $options['monetize_by'] ) ? $options['monetize_by'] : '';
			$this->recursion_guard = false;
			if ( 'pmpro' !== $monetize_by ) {
				return $post_type;
			}
		}

		// Ensure it's always 'course-bundle' when PMPro is active
		return 'course-bundle';
	}

	/**
	 * Intercept Tutor utils and options to make Course Bundle work with PMPro.
	 * This catches calls to is_monetize_by_tutor() and related option reads.
	 *
	 * @param mixed  $pre_option The value to return instead of the option value.
	 * @param string $option     Option name.
	 * @return mixed
	 */
	public function intercept_tutor_utils_for_course_bundle( $pre_option, $option ) {
		// Prevent infinite recursion
		if ( $this->recursion_guard ) {
			return $pre_option;
		}

		// Only intercept when PMPro is selected and we're in Course Bundle context
		if ( 'tutor_option' !== $option ) {
			return $pre_option;
		}

		if ( ! function_exists( 'tutor_utils' ) || ! tutor_utils()->has_pmpro() ) {
			return $pre_option;
		}

		// Set recursion guard and get monetize_by directly from database to avoid filter loops
		$this->recursion_guard = true;
		$options = get_option( 'tutor_option', array() );
		$monetize_by = isset( $options['monetize_by'] ) ? $options['monetize_by'] : '';
		$this->recursion_guard = false;

		if ( 'pmpro' !== $monetize_by ) {
			return $pre_option;
		}

		// Check if we're in Course Bundle context
		$backtrace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 15 );
		$in_bundle_context = false;
		foreach ( $backtrace as $frame ) {
			if ( isset( $frame['class'] ) && false !== strpos( $frame['class'], 'CourseBundle' ) ) {
				$in_bundle_context = true;
				break;
			}
			if ( isset( $frame['file'] ) && false !== strpos( $frame['file'], 'course-bundle' ) ) {
				$in_bundle_context = true;
				break;
			}
		}

		if ( $in_bundle_context ) {
			// Return modified options where monetize_by is 'tutor' for Course Bundle
			if ( is_array( $options ) ) {
				$options['monetize_by'] = 'tutor'; // Make Course Bundle think it's native
				return $options;
			}
		}

		return $pre_option;
	}

}
