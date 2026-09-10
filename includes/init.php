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
		private $recursion_guard = false;
		//phpcs:enable

	/**
	 * Track courses that have reconciliation scheduled to prevent duplicates.
	 *
	 * @var array
	 */
	private $reconcile_scheduled = array();
	private $permanent_delete_freeze = array();
	private $detectable_rest_intent = null;

	/**
	 * Constructor
	 */
	public function __construct() {
		// Provide WooCommerce stub functions early to prevent fatal errors in Tutor LMS bundle code
		// This must happen before Tutor LMS tries to use wc_get_product()
		$this->provide_woocommerce_stubs();
		
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

		// Override is_monetize_by_tutor() for Course Bundle contexts on every page load.
		add_filter( 'pre_option_tutor_option', array( $this, 'intercept_tutor_utils_for_course_bundle' ), 10, 2 );

        // Prefer centralized core monetization helper when available; fall back to tutor_utils
        if ( function_exists( 'tutorpress_monetization' ) ) {
            $has_pmpro = tutorpress_monetization()->is_pmpro();
        } else {
            $has_pmpro = function_exists( 'tutor_utils' ) ? tutor_utils()->has_pmpro() : false;
        }

        // Only load the PMPro integration when PMPro is available.
        if ( ! $has_pmpro ) {
            return;
        }

		$this->path     = plugin_dir_path( TUTORPRESS_PMPRO_FILE );
		$this->url      = plugin_dir_url( TUTORPRESS_PMPRO_FILE );
		$this->basename = plugin_basename( TUTORPRESS_PMPRO_FILE );

		$this->load_TUTORPRESS_PMPRO();

		// Auto-create one-time PMPro levels when selling_option is set to one_time
		add_filter( 'rest_pre_insert_courses', array( $this, 'veto_detectable_rest_pmpro_deletion' ), 10, 2 );
		add_action( 'rest_after_insert_courses', array( $this, 'auto_create_one_time_level_for_course' ), 10, 3 );
		add_action( 'rest_after_insert_course-bundle', array( $this, 'auto_create_one_time_level_for_bundle' ), 10, 3 );

		// Reconcile hooks (scaffolding): REST, classic save, status transition, and deletion
		add_action( 'rest_after_insert_courses', array( $this, 'reconcile_course_levels_rest' ), 20, 3 );
		add_action( 'save_post_courses', array( $this, 'schedule_reconcile_course_levels' ), 999, 3 );
		add_action( 'transition_post_status', array( $this, 'maybe_reconcile_on_status' ), 20, 3 );
		add_filter( 'pre_delete_post', array( $this, 'veto_permanent_pmpro_delete' ), 10, 3 );
		add_action( 'before_delete_post', array( $this, 'delete_course_levels_on_delete' ), 10, 1 );

		// Admin on-demand action for manual reconciliation
		add_filter( 'bulk_actions-edit-courses', array( $this, 'add_reconcile_bulk_action' ) );
		add_action( 'handle_bulk_actions-edit-courses', array( $this, 'handle_reconcile_bulk_action' ), 10, 3 );

		// Bundle reconcile hooks (mirror course hooks)
		add_filter( 'rest_pre_insert_course-bundle', array( $this, 'veto_detectable_rest_pmpro_deletion' ), 10, 2 );
		add_action( 'rest_after_insert_course-bundle', array( $this, 'reconcile_bundle_levels_rest' ), 20, 3 );
		add_action( 'save_post_course-bundle', array( $this, 'schedule_reconcile_bundle_levels' ), 999, 3 );
		add_action( 'transition_post_status', array( $this, 'maybe_reconcile_bundle_on_status' ), 20, 3 );
		add_action( 'before_delete_post', array( $this, 'delete_bundle_levels_on_delete' ), 10, 1 );

		// Admin on-demand action for manual bundle reconciliation
		add_filter( 'bulk_actions-edit-course-bundle', array( $this, 'add_reconcile_bulk_action' ) );
		add_action( 'handle_bulk_actions-edit-course-bundle', array( $this, 'handle_reconcile_bulk_action' ), 10, 3 );

		// PMPro admin direct-delete cleanup
		add_action( 'pmpro_delete_membership_level', array( $this, 'cleanup_deleted_pmpro_level_meta' ), 10, 1 );
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
		require_once $this->path . 'includes/utilities/class-pmpro-level-deletion-guard.php';
		require_once $this->path . 'includes/utilities/class-pmpro-level-deletion-coordinator.php';
		$this->paid_memberships_pro = new PaidMembershipsPro();


		// Defer loading of REST controllers until REST API initialization so TutorPress core
		// classes (e.g. TutorPress_REST_Controller) are loaded first. This prevents fatal
		// errors when the controller class extends core controller classes.
		$rest_controller = $this->path . 'includes/rest/class-pmpro-subscriptions-controller.php';
		// Register PMPro subscription controller via TutorPress extension hook
		add_action( 'tutorpress_register_rest_controllers', function() use ( $rest_controller ) {
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

		// Prefer centralized core monetization helper when available; fall back to tutor_utils
		if ( function_exists( 'tutorpress_monetization' ) ) {
			if ( ! tutorpress_monetization()->is_pmpro() ) {
				return $addons;
			}
		} else {
			// Only proceed when PMPro plugin is present.
			if ( ! tutor_utils()->has_pmpro() ) {
				return $addons;
			}
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
		if ( class_exists( 'TutorPro\CourseBundle\Frontend\BundleArchive' ) ) {
			new \TutorPro\CourseBundle\Frontend\BundleArchive();
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
		// Prefer core monetization helper, fall back to tutor_utils() and raw DB read
		if ( function_exists( 'tutorpress_monetization' ) ) {
			if ( ! tutorpress_monetization()->is_pmpro() ) {
				return $is_valid_type;
			}
		} else {
			if ( ! function_exists( 'tutor_utils' ) || ! tutor_utils()->has_pmpro() ) {
				return $is_valid_type;
			}
			// Fallback: raw option read under guard to avoid filter recursion
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
		// Prefer core monetization helper, fall back to tutor_utils() and raw DB read
		if ( function_exists( 'tutorpress_monetization' ) ) {
			if ( ! tutorpress_monetization()->is_pmpro() ) {
				return $post_type;
			}
		} else {
			if ( ! function_exists( 'tutor_utils' ) || ! tutor_utils()->has_pmpro() ) {
				return $post_type;
			}
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

		if ( doing_action( 'save_post' ) || doing_action( 'save_post_course-bundle' ) ) {
			return $pre_option;
		}

		// Prefer core monetization helper, fall back to tutor_utils() and raw DB read
		if ( function_exists( 'tutorpress_monetization' ) ) {
			if ( ! tutorpress_monetization()->is_pmpro() ) {
				return $pre_option;
			}
		} else {
			if ( ! function_exists( 'tutor_utils' ) || ! tutor_utils()->has_pmpro() ) {
				return $pre_option;
			}
			// Fallback: raw option read under guard
			$this->recursion_guard = true;
			$options = get_option( 'tutor_option', array() );
			$monetize_by = isset( $options['monetize_by'] ) ? $options['monetize_by'] : '';
			$this->recursion_guard = false;
			if ( 'pmpro' !== $monetize_by ) {
				return $pre_option;
			}
		}

		if ( ! isset( $options ) ) {
			$this->recursion_guard = true;
			$options = get_option( 'tutor_option', array() );
			$this->recursion_guard = false;
		}

		// Check if we're in Course Bundle context
		$backtrace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 15 );
		$in_bundle_context = false;
		foreach ( $backtrace as $frame ) {
			if ( isset( $frame['class'] ) && false !== strpos( $frame['class'], 'CourseBundle' ) ) {
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

	/**
	 * Auto-create a one-time PMPro level for courses when selling_option is one_time.
	 *
	 * @param WP_Post         $post     Inserted or updated post object.
	 * @param WP_REST_Request $request  Request object.
	 * @param bool            $creating True when creating a post, false when updating.
	 * @return void
	 */
	public function auto_create_one_time_level_for_course( $post, $request, $creating ) {
		$this->auto_create_one_time_level( $post->ID, 'course' );
	}

	/**
	 * Auto-create a one-time PMPro level for bundles when selling_option is one_time.
	 *
	 * @param WP_Post         $post     Inserted or updated post object.
	 * @param WP_REST_Request $request  Request object.
	 * @param bool            $creating True when creating a post, false when updating.
	 * @return void
	 */
	public function auto_create_one_time_level_for_bundle( $post, $request, $creating ) {
		$this->auto_create_one_time_level( $post->ID, 'bundle' );
	}

	/**
	 * REST entrypoint for course-level reconcile (with context extraction).
	 *
	 * @param WP_Post         $post
	 * @param WP_REST_Request $request
	 * @param bool            $creating
	 * @return void
	 */
	public function reconcile_course_levels_rest( $post, $request, $creating ) {
		$course_id = is_object( $post ) ? (int) $post->ID : (int) $post;
		$error_prefix = '[TP-PMPRO] reconcile_course_levels_rest';

		// Extract context from REST request (check both top-level and meta object)
		$so_keys = array( 'selling_option', 'tutor_course_selling_option' );
		$pt_keys = array( 'price_type', 'tutor_course_price_type', '_tutor_course_price_type' );
		$price_keys = array( 'price', 'tutor_course_price' );
		$so = null; $pt = null; $price = null;
		
		if ( method_exists( $request, 'get_param' ) ) {
			// First, try to get from top-level params (for direct API calls)
			foreach ( $so_keys as $k ) { if ( null === $so ) { $so = $request->get_param( $k ); } }
			foreach ( $pt_keys as $k ) { if ( null === $pt ) { $pt = $request->get_param( $k ); } }
			foreach ( $price_keys as $k ) { if ( null === $price ) { $price = $request->get_param( $k ); } }
			
			// If not found, try to get from meta object (for Gutenberg saves)
			$meta = $request->get_param( 'meta' );
			if ( is_array( $meta ) ) {
				if ( null === $so ) {
					foreach ( $so_keys as $k ) {
						if ( isset( $meta[ $k ] ) ) {
							$so = $meta[ $k ];
							break;
						}
					}
				}
				if ( null === $pt ) {
					foreach ( $pt_keys as $k ) {
						if ( isset( $meta[ $k ] ) ) {
							$pt = $meta[ $k ];
							break;
						}
					}
				}
				if ( null === $price ) {
					foreach ( $price_keys as $k ) {
						if ( isset( $meta[ $k ] ) ) {
							$price = $meta[ $k ];
							break;
						}
					}
				}
			}
		}
		
		// Fallback to post meta if not in request (use standard Tutor Core meta key)
		if ( null === $so ) {
			$so = get_post_meta( $course_id, 'tutor_course_selling_option', true );
		}
		if ( null === $pt ) {
			$pt = get_post_meta( $course_id, '_tutor_course_price_type', true );
		}
		if ( null === $price ) {
			$price = get_post_meta( $course_id, 'tutor_course_price', true );
		}

		// Schedule reconciliation on shutdown (prevent duplicates with tracking array)
		if ( ! isset( $this->reconcile_scheduled[ $course_id ] ) ) {
			$this->reconcile_scheduled[ $course_id ] = true;
			
			add_action( 'shutdown', function() use ( $course_id ) {
				$this->reconcile_course_levels( $course_id, array( 'source' => 'shutdown' ) );
			}, 999 );
		}
	}

	/**
	 * Classic save entrypoint schedules reconcile (scaffold w/ logs).
	 *
	 * @param int     $post_id
	 * @param WP_Post $post
	 * @param bool    $update
	 * @return void
	 */
	public function schedule_reconcile_course_levels( $post_id, $post, $update ) {
		if ( 'courses' !== get_post_type( $post_id ) ) {
			return;
		}
		// Schedule reconciliation on shutdown (prevent duplicates with tracking array)
		if ( ! isset( $this->reconcile_scheduled[ $post_id ] ) ) {
			$this->reconcile_scheduled[ $post_id ] = true;
			
			add_action( 'shutdown', function() use ( $post_id ) {
				$this->reconcile_course_levels( (int) $post_id, array( 'source' => 'shutdown' ) );
			}, 999 );
		}
	}

	/**
	 * Toggle allow_signups for all PMPro levels associated with a post.
	 *
	 * Discovers levels via pmpro_memberships_pages associations and
	 * _tutorpress_pmpro_levels post meta (same pattern as delete handler).
	 *
	 * @param int $post_id  The course or bundle post ID.
	 * @param int $value    1 to enable signups, 0 to disable.
	 * @return void
	 */
	private function toggle_allow_signups( $post_id, $value ) {
		global $wpdb;

		$post_id   = (int) $post_id;
		$value     = (int) $value;
		$post_type = get_post_type( $post_id );

		// Discover all PMPro level IDs associated with this post
		$level_ids = array();
		if ( isset( $wpdb->pmpro_memberships_pages ) ) {
			$level_ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT membership_id FROM {$wpdb->pmpro_memberships_pages} WHERE page_id = %d",
				$post_id
			) );
		}
		$meta_ids = get_post_meta( $post_id, '_tutorpress_pmpro_levels', true );
		if ( is_array( $meta_ids ) ) {
			$level_ids = array_merge( $level_ids, $meta_ids );
		}
		// Reverse meta lookup: check level meta for tutorpress_course_id or tutorpress_bundle_id
		if ( isset( $wpdb->pmpro_membership_levelmeta ) ) {
			$meta_key = ( 'course-bundle' === $post_type ) ? 'tutorpress_bundle_id' : 'tutorpress_course_id';
			$reverse_ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT pmpro_membership_level_id FROM {$wpdb->pmpro_membership_levelmeta} WHERE meta_key = %s AND meta_value = %s",
				$meta_key,
				(string) $post_id
			) );
			if ( ! empty( $reverse_ids ) ) {
				$level_ids = array_merge( $level_ids, $reverse_ids );
			}
		}
		$level_ids = array_values( array_unique( array_map( 'intval', (array) $level_ids ) ) );

		if ( empty( $level_ids ) ) {
			return;
		}

		// Update allow_signups for each level
		if ( isset( $wpdb->pmpro_membership_levels ) ) {
			foreach ( $level_ids as $lid ) {
				$wpdb->update(
					$wpdb->pmpro_membership_levels,
					array( 'allow_signups' => $value ),
					array( 'id' => $lid ),
					array( '%d' ),
					array( '%d' )
				);
			}
		}

		$this->log( '[TP-PMPRO] toggle_allow_signups: post=' . $post_id . ' value=' . $value . ' levels=' . implode( ',', $level_ids ) );
	}

	/**
	 * Status transition entrypoint (scaffold w/ logs).
	 *
	 * @param string  $new_status
	 * @param string  $old_status
	 * @param WP_Post $post
	 * @return void
	 */
	public function maybe_reconcile_on_status( $new_status, $old_status, $post ) {
		if ( ! $post || 'courses' !== get_post_type( $post ) ) {
			return;
		}
		if ( 'publish' === $new_status && 'publish' !== $old_status ) {
			// Enable signups for all associated levels
			$this->toggle_allow_signups( $post->ID, 1 );

			// Schedule reconciliation on shutdown (prevent duplicates with tracking array)
			if ( ! isset( $this->reconcile_scheduled[ $post->ID ] ) ) {
				$this->reconcile_scheduled[ $post->ID ] = true;
				
				add_action( 'shutdown', function() use ( $post ) {
					$this->reconcile_course_levels( (int) $post->ID, array( 'source' => 'shutdown' ) );
				}, 999 );
			}
		} elseif ( 'publish' === $old_status && 'publish' !== $new_status ) {
			// Unpublished: disable signups for all associated levels
			$this->toggle_allow_signups( $post->ID, 0 );
		}
	}

	/**
	 * REST entrypoint for bundle-level reconcile (with context extraction).
	 *
	 * @param WP_Post         $post
	 * @param WP_REST_Request $request
	 * @param bool            $creating
	 * @return void
	 */
	public function reconcile_bundle_levels_rest( $post, $request, $creating ) {
		$bundle_id = is_object( $post ) ? (int) $post->ID : (int) $post;
		$error_prefix = '[TP-PMPRO] reconcile_bundle_levels_rest';

		// Extract context from REST request (check both top-level and meta object)
		$so_keys = array( 'selling_option', 'tutor_course_selling_option' );
		$pt_keys = array( 'price_type', 'tutor_course_price_type', '_tutor_course_price_type' );
		$price_keys = array( 'price', 'tutor_course_price', 'sale_price', 'tutor_course_sale_price' );
		$so = null; $pt = null; $price = null;
		
		if ( method_exists( $request, 'get_param' ) ) {
			// First, try to get from top-level params (for direct API calls)
			foreach ( $so_keys as $k ) { if ( null === $so ) { $so = $request->get_param( $k ); } }
			foreach ( $pt_keys as $k ) { if ( null === $pt ) { $pt = $request->get_param( $k ); } }
			foreach ( $price_keys as $k ) { if ( null === $price ) { $price = $request->get_param( $k ); } }
			
			// If not found, try to get from meta object (for Gutenberg saves)
			$meta = $request->get_param( 'meta' );
			if ( is_array( $meta ) ) {
				if ( null === $so ) {
					foreach ( $so_keys as $k ) {
						if ( isset( $meta[ $k ] ) ) {
							$so = $meta[ $k ];
							break;
						}
					}
				}
				if ( null === $pt ) {
					foreach ( $pt_keys as $k ) {
						if ( isset( $meta[ $k ] ) ) {
							$pt = $meta[ $k ];
							break;
						}
					}
				}
				if ( null === $price ) {
					foreach ( $price_keys as $k ) {
						if ( isset( $meta[ $k ] ) ) {
							$price = $meta[ $k ];
							break;
						}
					}
				}
			}
		}
		
		// Fallback to post meta if not in request (use standard Tutor Core meta keys)
		if ( null === $so ) {
			$so = get_post_meta( $bundle_id, 'tutor_course_selling_option', true );
		}
		if ( null === $pt ) {
			$pt = get_post_meta( $bundle_id, '_tutor_course_price_type', true );
		}
		if ( null === $price ) {
			$price = get_post_meta( $bundle_id, 'tutor_course_price', true );
		}
		$this->reconcile_scheduled[ $bundle_id ] = true;
		
		// Call reconciliation DIRECTLY instead of scheduling on shutdown
		// This ensures we use the fresh context from the REST request, not stale DB values
		$ctx = array( 'source' => 'rest_direct' );
		// Pass extracted values through context
		if ( null !== $so ) { $ctx['selling_option'] = $so; }
		if ( null !== $pt ) { $ctx['price_type'] = $pt; }
		if ( null !== $price ) { $ctx['price'] = $price; }
		
		$this->reconcile_bundle_levels( $bundle_id, $ctx );
	}

	/**
	 * Classic save entrypoint schedules bundle reconcile (scaffold w/ logs).
	 *
	 * @param int     $post_id
	 * @param WP_Post $post
	 * @param bool    $update
	 * @return void
	 */
	public function schedule_reconcile_bundle_levels( $post_id, $post, $update ) {
		if ( 'course-bundle' !== get_post_type( $post_id ) ) {
			return;
		}
		if ( isset( $this->reconcile_scheduled[ $post_id ] ) ) {
			return;
		}
		$this->reconcile_scheduled[ $post_id ] = true;
		add_action( 'shutdown', function() use ( $post_id ) {
			$this->reconcile_bundle_levels( (int) $post_id, array( 'source' => 'save_post' ) );
		}, 999 );
	}

	/**
	 * Bundle status transition entrypoint (scaffold w/ logs).
	 *
	 * @param string  $new_status
	 * @param string  $old_status
	 * @param WP_Post $post
	 * @return void
	 */
	public function maybe_reconcile_bundle_on_status( $new_status, $old_status, $post ) {
		if ( ! $post || 'course-bundle' !== get_post_type( $post ) ) {
			return;
		}
		if ( 'publish' === $new_status && 'publish' !== $old_status ) {
			// Enable signups for all associated levels
			$this->toggle_allow_signups( $post->ID, 1 );

			// Skip reconciliation if this is a REST request (REST hook will handle it)
			if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
				return;
			}
			// Schedule reconciliation on shutdown (prevent duplicates with tracking array)
			if ( ! isset( $this->reconcile_scheduled[ $post->ID ] ) ) {
				$this->reconcile_scheduled[ $post->ID ] = true;
				add_action( 'shutdown', function() use ( $post ) {
					$this->reconcile_bundle_levels( (int) $post->ID, array( 'source' => 'status_transition' ) );
				}, 999 );
			}
		} elseif ( 'publish' === $old_status && 'publish' !== $new_status ) {
			// Unpublished: disable signups for all associated levels
			$this->toggle_allow_signups( $post->ID, 0 );
		}
	}

	/**
	 * Delete bundle levels when bundle is deleted.
	 *
	 * @param int $post_id
	 * @return void
	 */
	public function delete_bundle_levels_on_delete( $post_id ) {
		$post_id = (int) $post_id;
		$post_type = get_post_type( $post_id );
		if ( 'course-bundle' !== $post_type ) {
			return;
		}
		$this->apply_permanent_pmpro_delete( (int) $post_id );
		return;

		global $wpdb;
		$level_ids = array();
		if ( isset( $wpdb->pmpro_memberships_pages ) ) {
			$level_ids = $wpdb->get_col( $wpdb->prepare( "SELECT membership_id FROM {$wpdb->pmpro_memberships_pages} WHERE page_id = %d", $post_id ) );
		}
		$meta_ids = get_post_meta( $post_id, '_tutorpress_pmpro_levels', true );
		if ( is_array( $meta_ids ) ) {
			$level_ids = array_merge( $level_ids, $meta_ids );
		}
		// Also check reverse meta for bundle_id
		if ( isset( $wpdb->pmpro_membership_levelmeta ) ) {
			$reverse_meta_ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT pmpro_membership_level_id FROM {$wpdb->pmpro_membership_levelmeta} WHERE meta_key = %s AND meta_value = %s",
				'tutorpress_bundle_id',
				(string) $post_id
			) );
			$level_ids = array_merge( $level_ids, $reverse_meta_ids );
		}
		$level_ids = array_values( array_unique( array_map( 'intval', (array) $level_ids ) ) );
		if ( empty( $level_ids ) ) {
			return;
		}

		// Ensure cleanup class is available
		if ( ! class_exists( '\\TUTORPRESS_PMPRO\\PMPro_Level_Cleanup' ) ) {
			require_once $this->path . 'includes/utilities/class-pmpro-level-cleanup.php';
		}

		foreach ( $level_ids as $lid ) {
			$owned_by_bundle = false;
			if ( function_exists( 'get_pmpro_membership_level_meta' ) ) {
				$owner = (int) get_pmpro_membership_level_meta( $lid, 'tutorpress_bundle_id', true );
				$owned_by_bundle = ( $owner === $post_id );
			}
			if ( $owned_by_bundle ) {
				\TUTORPRESS_PMPRO\PMPro_Level_Cleanup::full_delete_level( (int) $lid, true );
				$this->log( '[TP-PMPRO] delete_bundle_levels_on_delete deleted_level_id=' . (int) $lid . ' bundle=' . $post_id );
			}
		}
		delete_post_meta( $post_id, '_tutorpress_pmpro_levels' );
		
		// Delete bundle level group if empty
		self::delete_course_level_group_if_empty( $post_id );
	}

	/**
	 * Get reliable PMPro state for a course.
	 *
	 * - Discovers associations from pmpro_memberships_pages (source of truth)
	 * - Verifies each level exists in pmpro_membership_levels
	 * - Classifies levels as one_time or recurring
	 * - Prunes stale associations and rewrites _tutorpress_pmpro_levels meta
	 *
	 * @param int   $course_id
	 * @param array $ctx Optional context for logging
	 * @return array {
	 *     @type array $valid_ids All valid level IDs associated with this course
	 *     @type array $one_time_ids Level IDs classified as one-time
	 *     @type array $recurring_ids Level IDs classified as recurring
	 * }
	 */
	private function get_course_pmpro_state( $course_id, $ctx = array() ) {
		global $wpdb;
		$course_id = (int) $course_id;
		$src = is_array( $ctx ) && isset( $ctx['source'] ) ? $ctx['source'] : 'unknown';

		// Discover associations from pmpro_memberships_pages (primary source of truth)
		$associated_ids = array();
		if ( isset( $wpdb->pmpro_memberships_pages ) ) {
			$associated_ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT membership_id FROM {$wpdb->pmpro_memberships_pages} WHERE page_id = %d",
				$course_id
			) );
		}
		$associated_ids = array_map( 'intval', (array) $associated_ids );

		// Also read _tutorpress_pmpro_levels meta (secondary source)
		$meta_ids = get_post_meta( $course_id, '_tutorpress_pmpro_levels', true );
		if ( ! is_array( $meta_ids ) ) {
			$meta_ids = array();
		}
		$meta_ids = array_map( 'intval', $meta_ids );

		// Discover levels via reverse meta (tertiary source — catches orphaned reverse-meta-only levels)
		$reverse_meta_ids = array();
		if ( isset( $wpdb->pmpro_membership_levelmeta ) ) {
			$reverse_meta_ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT pmpro_membership_level_id FROM {$wpdb->pmpro_membership_levelmeta} WHERE meta_key = %s AND meta_value = %s",
				'tutorpress_course_id',
				(string) $course_id
			) );
		}
		$reverse_meta_ids = array_map( 'intval', (array) $reverse_meta_ids );

		// Union of all three sources
		$candidate_ids = array_values( array_unique( array_merge( $associated_ids, $meta_ids, $reverse_meta_ids ) ) );

		// Verify each candidate level exists in pmpro_membership_levels and classify
		$valid_ids = array();
		$one_time_ids = array();
		$recurring_ids = array();
		$stale_ids = array();

		foreach ( $candidate_ids as $lid ) {
			if ( ! $lid ) {
				continue;
			}
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, billing_amount, cycle_number FROM {$wpdb->pmpro_membership_levels} WHERE id = %d",
				$lid
			), ARRAY_A );

			if ( ! $row || ! isset( $row['id'] ) ) {
				// Level doesn't exist in PMPro DB → stale
				$stale_ids[] = $lid;
				continue;
			}

			$valid_ids[] = (int) $row['id'];
			$billing = isset( $row['billing_amount'] ) ? (float) $row['billing_amount'] : 0.0;
			$cycle = isset( $row['cycle_number'] ) ? (int) $row['cycle_number'] : 0;
			$is_one_time = ( $billing <= 0 && $cycle === 0 );

			if ( $is_one_time ) {
				$one_time_ids[] = (int) $row['id'];
			} else {
				$recurring_ids[] = (int) $row['id'];
			}
		}

        // Prune stale associations and rewrite meta
        if ( ! empty( $stale_ids ) ) {
            // Ensure cleanup class is available
            if ( ! class_exists( '\\TUTORPRESS_PMPRO\\PMPro_Level_Cleanup' ) ) {
                require_once $this->path . 'includes/utilities/class-pmpro-level-cleanup.php';
            }
            foreach ( $stale_ids as $sid ) {
                // Use full_delete_level for stale ids so we also remove any lingering
                // level meta, categories and association rows even when the level
                // row itself may already be missing. full_delete_level is idempotent
                // and safe when called for non-existent ids.
                \TUTORPRESS_PMPRO\PMPro_Level_Cleanup::full_delete_level( $sid, true );
                $this->log( '[TP-PMPRO] get_course_pmpro_state pruned_stale_level=' . $sid . ' course=' . $course_id . ' source=' . $src );
            }
            $this->log( '[TP-PMPRO] get_course_pmpro_state pruned_stale_count=' . count( $stale_ids ) . ' course=' . $course_id . ' source=' . $src );
        }

		// Rewrite _tutorpress_pmpro_levels to match verified valid_ids
		update_post_meta( $course_id, '_tutorpress_pmpro_levels', $valid_ids );

		$this->log( '[TP-PMPRO] get_course_pmpro_state discovered; course=' . $course_id . ' valid=' . count( $valid_ids ) . ' one_time=' . count( $one_time_ids ) . ' recurring=' . count( $recurring_ids ) . ' stale=' . count( $stale_ids ) . ' source=' . $src );

		return array(
			'valid_ids'     => $valid_ids,
			'one_time_ids'  => $one_time_ids,
			'recurring_ids' => $recurring_ids,
		);
	}

	/**
	 * Get the regular price for a single course from its PMPro level.
	 *
	 * Under PMPro monetization, the authoritative regular price lives in
	 * the PMPro level meta (tutorpress_regular_price), not in the Tutor
	 * native tutor_course_price post meta which can be stale.
	 *
	 * Fallback chain: PMPro level meta → PMPro initial_payment → tutor_course_price post meta.
	 *
	 * @since 1.0.0
	 * @param int $course_id Course post ID.
	 * @return float The course regular price, or 0.0 if not found.
	 */
	private function get_course_pmpro_regular_price( $course_id ) {
		$course_id = (int) $course_id;

		$level_ids = get_post_meta( $course_id, '_tutorpress_pmpro_levels', true );
		if ( is_array( $level_ids ) && ! empty( $level_ids ) ) {
			$level_id = (int) $level_ids[0];

			$regular_meta = get_pmpro_membership_level_meta( $level_id, 'tutorpress_regular_price', true );
			if ( ! empty( $regular_meta ) && floatval( $regular_meta ) > 0 ) {
				return floatval( $regular_meta );
			}

			if ( function_exists( 'pmpro_getLevel' ) ) {
				$level = pmpro_getLevel( $level_id );
				if ( $level && floatval( $level->initial_payment ) > 0 ) {
					return floatval( $level->initial_payment );
				}
			}
		}

		$post_meta_price = get_post_meta( $course_id, 'tutor_course_price', true );
		return ! empty( $post_meta_price ) ? floatval( $post_meta_price ) : 0.0;
	}

	/**
	 * Calculate bundle regular price by summing regular prices of included courses.
	 *
	 * Uses get_course_pmpro_regular_price() to read from the authoritative
	 * PMPro level meta rather than the potentially stale tutor_course_price post meta.
	 *
	 * @since 1.0.0
	 * @param int $bundle_id Bundle post ID.
	 * @return float Total regular price (sum of course regular prices).
	 */
	private function calculate_bundle_regular_price( $bundle_id ) {
		$bundle_id = (int) $bundle_id;
		$course_ids_meta = get_post_meta( $bundle_id, 'bundle-course-ids', true );

		if ( empty( $course_ids_meta ) ) {
			return 0.0;
		}

		if ( is_string( $course_ids_meta ) ) {
			$course_ids = array_filter( array_map( 'intval', explode( ',', $course_ids_meta ) ) );
		} elseif ( is_array( $course_ids_meta ) ) {
			$course_ids = array_filter( array_map( 'intval', $course_ids_meta ) );
		} else {
			return 0.0;
		}

		if ( empty( $course_ids ) ) {
			return 0.0;
		}

		$total = 0.0;
		foreach ( $course_ids as $course_id ) {
			$course_id = (int) $course_id;

			$price_type = get_post_meta( $course_id, '_tutor_course_price_type', true );
			if ( 'free' === $price_type ) {
				continue;
			}

			$price = $this->get_course_pmpro_regular_price( $course_id );
			if ( $price > 0 ) {
				$total += $price;
			}
		}

		return $total;
	}

	/**
	 * Get reliable PMPro state for a bundle.
	 *
	 * - Discovers associations from pmpro_memberships_pages (source of truth)
	 * - Verifies each level exists in pmpro_membership_levels
	 * - Classifies levels as one_time or recurring
	 * - Prunes stale associations and rewrites _tutorpress_pmpro_levels meta
	 *
	 * @param int   $bundle_id
	 * @param array $ctx Optional context for logging
	 * @return array {
	 *     @type array $valid_ids All valid level IDs associated with this bundle
	 *     @type array $one_time_ids Level IDs classified as one-time
	 *     @type array $recurring_ids Level IDs classified as recurring
	 * }
	 */
	private function get_bundle_pmpro_state( $bundle_id, $ctx = array() ) {
		global $wpdb;
		$bundle_id = (int) $bundle_id;
		$src = is_array( $ctx ) && isset( $ctx['source'] ) ? $ctx['source'] : 'unknown';

		// Discover associations from pmpro_memberships_pages (primary source of truth)
		$associated_ids = array();
		if ( isset( $wpdb->pmpro_memberships_pages ) ) {
			$associated_ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT membership_id FROM {$wpdb->pmpro_memberships_pages} WHERE page_id = %d",
				$bundle_id
			) );
		}
		$associated_ids = array_map( 'intval', (array) $associated_ids );

		// Also read _tutorpress_pmpro_levels meta (secondary source)
		$meta_ids = get_post_meta( $bundle_id, '_tutorpress_pmpro_levels', true );
		if ( ! is_array( $meta_ids ) ) {
			$meta_ids = array();
		}
		$meta_ids = array_map( 'intval', $meta_ids );

		// Discover levels via reverse meta (tertiary source — catches orphaned reverse-meta-only levels)
		$reverse_meta_ids = array();
		if ( isset( $wpdb->pmpro_membership_levelmeta ) ) {
			$reverse_meta_ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT pmpro_membership_level_id FROM {$wpdb->pmpro_membership_levelmeta} WHERE meta_key = %s AND meta_value = %s",
				'tutorpress_bundle_id',
				(string) $bundle_id
			) );
		}
		$reverse_meta_ids = array_map( 'intval', (array) $reverse_meta_ids );

		// Union of all three sources
		$candidate_ids = array_values( array_unique( array_merge( $associated_ids, $meta_ids, $reverse_meta_ids ) ) );

		// Verify each candidate level exists in pmpro_membership_levels and classify
		$valid_ids = array();
		$one_time_ids = array();
		$recurring_ids = array();
		$stale_ids = array();

		foreach ( $candidate_ids as $lid ) {
			if ( ! $lid ) {
				continue;
			}
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, billing_amount, cycle_number FROM {$wpdb->pmpro_membership_levels} WHERE id = %d",
				$lid
			), ARRAY_A );

			if ( ! $row || ! isset( $row['id'] ) ) {
				// Level doesn't exist in PMPro DB → stale
				$stale_ids[] = $lid;
				continue;
			}

			$valid_ids[] = (int) $row['id'];
			$billing = isset( $row['billing_amount'] ) ? (float) $row['billing_amount'] : 0.0;
			$cycle = isset( $row['cycle_number'] ) ? (int) $row['cycle_number'] : 0;
			$is_one_time = ( $billing <= 0 && $cycle === 0 );

			if ( $is_one_time ) {
				$one_time_ids[] = (int) $row['id'];
			} else {
				$recurring_ids[] = (int) $row['id'];
			}
		}

		// Prune stale associations and rewrite meta
		if ( ! empty( $stale_ids ) ) {
			// Ensure cleanup class is available
			if ( ! class_exists( '\\TUTORPRESS_PMPRO\\PMPro_Level_Cleanup' ) ) {
				require_once $this->path . 'includes/utilities/class-pmpro-level-cleanup.php';
			}
			foreach ( $stale_ids as $sid ) {
				// Use full_delete_level for stale ids so we also remove any lingering
				// level meta, categories and association rows even when the level
				// row itself may already be missing. full_delete_level is idempotent
				// and safe when called for non-existent ids.
				\TUTORPRESS_PMPRO\PMPro_Level_Cleanup::full_delete_level( $sid, true );
			}
		}

		// Rewrite _tutorpress_pmpro_levels to match verified valid_ids
		update_post_meta( $bundle_id, '_tutorpress_pmpro_levels', $valid_ids );

		return array(
			'valid_ids'     => $valid_ids,
			'one_time_ids'  => $one_time_ids,
			'recurring_ids' => $recurring_ids,
		);
	}

	/**
	 * Reconcile handler - synchronizes PMPro levels with course pricing settings.
	 *
	 * @param int   $course_id
	 * @param array $ctx Optional context
	 * @return void
	 */
	public function reconcile_course_levels( $course_id, $ctx = array() ) {
		$course_id = (int) $course_id;
		$src = is_array( $ctx ) && isset( $ctx['source'] ) ? $ctx['source'] : 'scheduled';

		// Step 0: Acquire short-lived lock to prevent concurrent double-runs
		$lock_key = 'tp_pmpro_lock_' . $course_id;
		if ( get_transient( $lock_key ) ) {
			return;
		}
		set_transient( $lock_key, 1, 5 ); // 5-second lock expiry

		try {
		// Step 2: Association discovery and context extraction
		$disc = $this->discover_pmpro_levels( $course_id, 'reconcile_course' ); if ( ! empty( $disc['incomplete'] ) ) { $this->report_p20_operation( array( 'object_id' => $course_id, 'branch' => ( 'free' === get_post_meta( $course_id, '_tutor_course_price_type', true ) ) ? 'free' : (string) get_post_meta( $course_id, 'tutor_course_selling_option', true ), 'intent' => array( 'selling_option' => get_post_meta( $course_id, 'tutor_course_selling_option', true ), 'price_type' => get_post_meta( $course_id, '_tutor_course_price_type', true ) ), 'reason' => 'incomplete_discovery' ) ); return; }
		$state = array( 'valid_ids' => array_values( array_merge( (array) $disc['one_time_ids'], (array) $disc['recurring_ids'] ) ), 'one_time_ids' => $disc['one_time_ids'], 'recurring_ids' => $disc['recurring_ids'], 'stale_ids' => $disc['stale_ids'] );
		
		// Read course pricing context from post meta (use standard Tutor Core meta keys)
		$selling_option = get_post_meta( $course_id, 'tutor_course_selling_option', true );
		$price_type = get_post_meta( $course_id, '_tutor_course_price_type', true );

		// Branch handling: free / membership / subscription / one_time / both / all
		if ( 'free' === $price_type ) {
			$this->report_p20_operation( $op = $this->p20_op( $course_id, 'free', array( 'selling_option' => $selling_option, 'price_type' => $price_type ), $this->handle_free_branch( $course_id, $state ) ) ); $this->apply_p20_stale_ids( $course_id, $state, $op );
			return;
		}
		if ( 'membership' === $selling_option ) {
			$this->report_p20_operation( $op = $this->p20_op( $course_id, 'membership', array( 'selling_option' => $selling_option, 'price_type' => $price_type ), $this->handle_membership_branch( $course_id, $state ) ) ); $this->apply_p20_stale_ids( $course_id, $state, $op );
			return;
		}
		if ( 'subscription' === $selling_option ) {
			$this->report_p20_operation( $op = $this->p20_op( $course_id, 'subscription', array( 'selling_option' => $selling_option, 'price_type' => $price_type ), $this->handle_subscription_branch( $course_id, $state ) ) ); $this->apply_p20_stale_ids( $course_id, $state, $op );
			return;
		}
		if ( 'one_time' === $selling_option ) {
			$this->report_p20_operation( $op = $this->p20_op( $course_id, 'one_time', array( 'selling_option' => $selling_option, 'price_type' => $price_type ), $this->handle_one_time_branch( $course_id, $state ) ) ); $this->apply_p20_stale_ids( $course_id, $state, $op );
			return;
		}
		if ( 'both' === $selling_option || 'all' === $selling_option ) {
			$this->handle_both_and_all_branch( $course_id, $state );
		}
		$this->apply_p20_stale_ids( $course_id, $state );
		} finally {
			// Safety net: ensure levels for non-published courses have signups disabled.
			// Branch handlers (one_time, both/all) may auto-create levels with allow_signups=1 (MySQL default).
			if ( 'publish' !== get_post_status( $course_id ) ) {
				$this->toggle_allow_signups( $course_id, 0 );
			}
			// Always cleanup lock, even if exception or early return
			delete_transient( $lock_key );
		}
	}

	/**
	 * Reconcile handler - synchronizes PMPro levels with bundle pricing settings.
	 *
	 * @param int   $bundle_id
	 * @param array $ctx Optional context
	 * @return void
	 */
	public function reconcile_bundle_levels( $bundle_id, $ctx = array() ) {
		$bundle_id = (int) $bundle_id;
		$src = is_array( $ctx ) && isset( $ctx['source'] ) ? $ctx['source'] : 'scheduled';

		// Step 0: Acquire short-lived lock to prevent concurrent double-runs
		$lock_key = 'tp_pmpro_lock_bundle_' . $bundle_id;
		if ( get_transient( $lock_key ) ) {
			return;
		}
		set_transient( $lock_key, 1, 5 ); // 5-second lock expiry

		$did_work = false;
		try {
		// Step 2: Association discovery and context extraction
		$disc = $this->discover_pmpro_levels( $bundle_id, 'reconcile_bundle' ); if ( ! empty( $disc['incomplete'] ) ) { $this->report_p20_operation( array( 'object_id' => $bundle_id, 'branch' => ( 'free' === get_post_meta( $bundle_id, '_tutor_course_price_type', true ) ) ? 'free' : (string) get_post_meta( $bundle_id, 'tutor_course_selling_option', true ), 'intent' => array( 'selling_option' => get_post_meta( $bundle_id, 'tutor_course_selling_option', true ), 'price_type' => get_post_meta( $bundle_id, '_tutor_course_price_type', true ) ), 'reason' => 'incomplete_discovery' ) ); $did_work = true; return; }
		$state = array( 'valid_ids' => array_values( array_merge( (array) $disc['one_time_ids'], (array) $disc['recurring_ids'] ) ), 'one_time_ids' => $disc['one_time_ids'], 'recurring_ids' => $disc['recurring_ids'], 'stale_ids' => $disc['stale_ids'] );
		
		// Read bundle pricing context - prefer values from context (passed from REST), fallback to post meta
		$selling_option = isset( $ctx['selling_option'] ) ? $ctx['selling_option'] : get_post_meta( $bundle_id, 'tutor_course_selling_option', true );
		$price_type = isset( $ctx['price_type'] ) ? $ctx['price_type'] : get_post_meta( $bundle_id, '_tutor_course_price_type', true );
		
		// For bundles: regular_price is auto-calculated, sale_price is instructor-set
		$regular_price = $this->calculate_bundle_regular_price( $bundle_id );
		$sale_price = isset( $ctx['sale_price'] ) ? $ctx['sale_price'] : get_post_meta( $bundle_id, 'tutor_course_sale_price', true );
			
		// Branch handling: free / membership / subscription / one_time / both / all
		// Note: For bundles, if regular_price = 0 (all courses free), treat as free
		// IMPORTANT: Only treat as free if selling_option is NOT set to a paid option
		// (TutorPress bundles default to price_type='free', but selling_option takes precedence)
		$is_paid_selling_option = in_array( $selling_option, array( 'subscription', 'one_time', 'both', 'all' ), true );
		if ( '' !== $selling_option && ( 'free' === $price_type || $regular_price <= 0 ) && ! $is_paid_selling_option ) {
			$this->report_p20_operation( $op = $this->p20_op( $bundle_id, 'free', array( 'selling_option' => $selling_option, 'price_type' => $price_type ), $this->handle_free_branch( $bundle_id, $state ) ) ); $this->apply_p20_stale_ids( $bundle_id, $state, $op );
			$did_work = true;
			return;
		}
		if ( 'membership' === $selling_option ) {
			$this->report_p20_operation( $op = $this->p20_op( $bundle_id, 'membership', array( 'selling_option' => $selling_option, 'price_type' => $price_type ), $this->handle_membership_branch( $bundle_id, $state ) ) ); $this->apply_p20_stale_ids( $bundle_id, $state, $op );
			$did_work = true;
			return;
		}
		if ( 'subscription' === $selling_option ) {
			$this->report_p20_operation( $op = $this->p20_op( $bundle_id, 'subscription', array( 'selling_option' => $selling_option, 'price_type' => $price_type ), $this->handle_subscription_branch( $bundle_id, $state, 'course-bundle' ) ) ); $this->apply_p20_stale_ids( $bundle_id, $state, $op );
			$did_work = true;
			return;
		}
		if ( 'one_time' === $selling_option ) {
			$this->report_p20_operation( $op = $this->p20_op( $bundle_id, 'one_time', array( 'selling_option' => $selling_option, 'price_type' => $price_type ), $this->handle_one_time_branch( $bundle_id, $state, 'course-bundle' ) ) ); $this->apply_p20_stale_ids( $bundle_id, $state, $op );
			$did_work = true;
			return;
		}
		if ( 'both' === $selling_option || 'all' === $selling_option ) {
			$this->handle_both_and_all_branch( $bundle_id, $state, 'course-bundle' );
			$did_work = true;
		}
		$this->apply_p20_stale_ids( $bundle_id, $state );
		} finally {
			// Safety net: ensure levels for non-published bundles have signups disabled.
			if ( 'publish' !== get_post_status( $bundle_id ) ) {
				$this->toggle_allow_signups( $bundle_id, 0 );
			}
			// No-op runs release the lock so a follow-up save can reconcile; real work keeps the lock for TTL.
			if ( ! $did_work ) {
				delete_transient( $lock_key );
			}
		}
	}

	/** Apply frozen P20 stale IDs after live success/no-op. */
	private function apply_p20_stale_ids( $oid, $state, $op = null ) {
		if ( is_array( $op ) && ( ! empty( $op['reason'] ) || ! empty( $op['unprocessed_ids'] ) ) ) { return; }
		$oid = (int) $oid; $ids = array_values( array_filter( array_map( 'intval', (array) ( isset( $state['stale_ids'] ) ? $state['stale_ids'] : array() ) ) ) ); if ( ! $ids ) { return; } if ( ! class_exists( '\\TUTORPRESS_PMPRO\\PMPro_Level_Cleanup' ) ) { require_once $this->path . 'includes/utilities/class-pmpro-level-cleanup.php'; }
		foreach ( $ids as $sid ) { $out = PMPro_Level_Cleanup::cleanup_missing_level( $oid, (int) $sid ); if ( 'ok' !== $out ) { error_log( '[TP-PMPRO] stale_cleanup object=' . $oid . ' level=' . (int) $sid . ' result=' . sanitize_key( (string) $out ) ); return; } }
		PMPro_Level_Cleanup::delete_owned_empty_group( $oid );
	}

	/**
	 * Apply guarded deletion to a typed live ID set for P20 reconciliation.
	 *
	 * Preflights every ID with Guard::evaluate. `protected`, `ineligible`, and
	 * `ownership_conflict` halt the whole set: no coordinator, no empty-group
	 * helper; halt IDs keep their Guard code and the rest are `unprocessed`.
	 * `shared_unlink` is not a halt. Otherwise each ID is handed to the
	 * coordinator once. `ok`, `committed_with_warning`, and runtime
	 * `protected` / `ineligible` / `ownership_conflict` continue; any other
	 * code (including `missing`) stops later IDs as `unprocessed`. The legacy
	 * empty-group helper runs only when that live pass fully continues and
	 * snapshot `stale_ids` is empty.
	 *
	 * @param int   $oid   Course or bundle post ID.
	 * @param array $ids   Live level IDs to delete.
	 * @param array $state Snapshot with optional `stale_ids` (unapplied here).
	 * @return array List of `array( int $level_id, string $code )`.
	 */
	private function apply_typed_live_deletes( $oid, $ids, $state = array() ) {
		$oid = (int) $oid; $type = get_post_type( $oid ); $ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $ids ) ) ) ); $out = array(); $halt = false; $dec = array();
		foreach ( $ids as $lid ) { $dec[ $lid ] = PMPro_Level_Deletion_Guard::evaluate( $lid, $oid, $type ); if ( in_array( $dec[ $lid ], array( 'protected', 'ineligible', 'ownership_conflict' ), true ) ) { $halt = true; } }
		if ( $halt ) { foreach ( $ids as $lid ) { $out[] = array( $lid, in_array( $dec[ $lid ], array( 'protected', 'ineligible', 'ownership_conflict' ), true ) ? $dec[ $lid ] : 'unprocessed' ); } return $out; }
		if ( $ids && ! class_exists( '\\TUTORPRESS_PMPRO\\PMPro_Level_Cleanup' ) ) { require_once $this->path . 'includes/utilities/class-pmpro-level-cleanup.php'; }
		$ok = true;
		foreach ( $ids as $lid ) { if ( ! $ok ) { $out[] = array( $lid, 'unprocessed' ); continue; } $code = PMPro_Level_Deletion_Coordinator::delete_level( $lid, $oid, $type ); $out[] = array( $lid, $code ); if ( ! in_array( $code, array( 'ok', 'committed_with_warning', 'protected', 'ineligible', 'ownership_conflict' ), true ) ) { $ok = false; } }
		if ( $ok && empty( array_filter( array_map( 'intval', (array) ( isset( $state['stale_ids'] ) ? $state['stale_ids'] : array() ) ) ) ) ) { self::delete_course_level_group_if_empty( $oid ); }
		return $out;
	}

	/**
	 * Handle Free branch: delete all associated PMPro levels and clear meta.
	 *
	 * @param int $course_id
	 * @param array $state
	 * @return void
	 */
	private function handle_free_branch( $course_id, $state = array() ) {
		$course_id = (int) $course_id;
		if ( ! is_array( $state ) ) { $state = array(); }
		$ids = isset( $state['valid_ids'] ) ? $state['valid_ids'] : array();
		return $this->apply_typed_live_deletes( $course_id, $ids, $state );
	}

	/**
	 * Handle Subscription-only branch: delete one-time levels, keep recurring.
	 *
	 * @param int $course_id
	 * @param array $state
	 * @return void
	 */
	private function handle_subscription_branch( $object_id, $state = array(), $post_type = 'courses' ) {
		$object_id = (int) $object_id;
		if ( ! is_array( $state ) ) { $state = array(); }
		$one_time = isset( $state['one_time_ids'] ) ? (array) $state['one_time_ids'] : array();
		$recurring = isset( $state['recurring_ids'] ) ? (array) $state['recurring_ids'] : array();
		$out = $this->apply_typed_live_deletes( $object_id, $one_time, $state );
		$ok = true; $has = false;
		foreach ( $out as $row ) { if ( in_array( $row[1], array( 'ok', 'committed_with_warning' ), true ) ) { $has = true; } elseif ( ! in_array( $row[1], array( 'protected', 'ineligible', 'ownership_conflict' ), true ) ) { $ok = false; } }
		if ( $ok && ( ! $out || $has ) ) { $type = get_post_type( $object_id ); foreach ( $recurring as $rid ) { $rid = (int) $rid; if ( 'allowed' !== PMPro_Level_Deletion_Guard::evaluate( $rid, $object_id, $type ) ) { continue; } if ( ! self::add_level_to_course_group( $object_id, $rid, $post_type ) ) { break; } } }
		return $out;
	}

	/**
	 * Sync PMPro level and bundle post meta for checkout, strikethrough display, and Tutor Pro ribbon.
	 *
	 * @param int   $bundle_id    Bundle post ID.
	 * @param int   $level_id     PMPro membership level ID.
	 * @param float $bundle_price Instructor-set bundle price (PMPro checkout amount).
	 * @return void
	 */
	private function set_bundle_pricing_meta( $bundle_id, $level_id, $bundle_price ) {
		$bundle_id    = (int) $bundle_id;
		$level_id     = (int) $level_id;
		$bundle_price = floatval( $bundle_price );

		global $wpdb;
		$wpdb->update(
			$wpdb->pmpro_membership_levels,
			array( 'initial_payment' => $bundle_price ),
			array( 'id' => $level_id ),
			array( '%f' ),
			array( '%d' )
		);

		$total_value = $this->calculate_bundle_regular_price( $bundle_id );

		if ( function_exists( 'update_pmpro_membership_level_meta' ) ) {
			update_pmpro_membership_level_meta( $level_id, 'tutorpress_bundle_price', $bundle_price );
			update_pmpro_membership_level_meta( $level_id, 'tutorpress_bundle_total_value', $total_value );
			update_pmpro_membership_level_meta( $level_id, 'tutorpress_regular_price', $total_value );
			update_pmpro_membership_level_meta( $level_id, 'tutorpress_sale_price', $bundle_price );
		}

		update_post_meta( $bundle_id, 'tutor_course_sale_price', $bundle_price );
		update_post_meta( $bundle_id, '_tutor_course_price_type', 'paid' );

		$this->log( '[TP-PMPRO] set_bundle_pricing_meta bundle=' . $bundle_id . ' level_id=' . $level_id . ' bundle_price=' . $bundle_price . ' total_value=' . $total_value );
	}

	/**
	 * Handle One-time-only branch: delete snapshot recurring levels, keep one-time.
	 *
	 * Recurring IDs go through `apply_typed_live_deletes`. Dependent survivor
	 * work uses the same gate as subscription: halt, runtime stop, or a non-empty
	 * set with no `ok`/`committed_with_warning` skips update/insert. Empty
	 * `recurring_ids` is a vacuous deletion pass. After the gate, only an already
	 * `allowed` first one-time is updated; insert runs only when snapshot
	 * `one_time_ids` is empty and price is positive. Insert order is insert,
	 * current-meta append, markers, course page ensure plus SELECT, checked
	 * group-add, then sale/bundle pricing. Extra one-time IDs stay evidence.
	 * Group-add is insert-only. Page ensure/SELECT is courses only.
	 *
	 * @param int    $object_id Course or bundle post ID.
	 * @param array  $state    Snapshot with `one_time_ids`, `recurring_ids`, `stale_ids`.
	 * @param string $post_type `courses` or `course-bundle`.
	 * @return array List of `array( int $level_id, string $code )` from the applicator.
	 */
	private function handle_one_time_branch( $object_id, $state = array(), $post_type = 'courses' ) {
		$object_id = (int) $object_id;
		if ( ! is_array( $state ) ) { $state = array(); }
		$one_time = isset( $state['one_time_ids'] ) ? (array) $state['one_time_ids'] : array();
		$recurring = isset( $state['recurring_ids'] ) ? (array) $state['recurring_ids'] : array();
		$out = $this->apply_typed_live_deletes( $object_id, $recurring, $state );
		$ok = true; $has = false;
		foreach ( $out as $row ) { if ( in_array( $row[1], array( 'ok', 'committed_with_warning' ), true ) ) { $has = true; } elseif ( ! in_array( $row[1], array( 'protected', 'ineligible', 'ownership_conflict' ), true ) ) { $ok = false; } }
		if ( $ok && ( ! $out || $has ) ) {
			$price = floatval( get_post_meta( $object_id, 'tutor_course_price', true ) );
			if ( 'course-bundle' === $post_type && $price <= 0 ) { $price = floatval( $this->calculate_bundle_regular_price( $object_id ) ); }
			if ( $one_time ) { $lid = (int) $one_time[0]; if ( 'allowed' === PMPro_Level_Deletion_Guard::evaluate( $lid, $object_id, get_post_type( $object_id ) ) ) { $this->update_one_time_survivor_level( $lid ); } }
			elseif ( $price > 0 ) {
				$lid = $this->insert_one_time_level( $object_id );
				if ( $lid && $this->append_current_pmpro_level_meta( $object_id, $lid ) && $this->write_one_time_ownership_markers( $object_id, $lid, $post_type ) ) {
					$go = true;
					if ( 'courses' === $post_type ) {
						if ( ! class_exists( '\\TUTORPRESS_PMPRO\\PMPro_Association' ) ) { require_once $this->path . 'includes/utilities/class-pmpro-association.php'; }
						\TUTORPRESS_PMPRO\PMPro_Association::ensure_course_level_association( $object_id, $lid ); global $wpdb;
						$go = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT page_id FROM {$wpdb->pmpro_memberships_pages} WHERE membership_id = %d AND page_id = %d", $lid, $object_id ) );
					}
					if ( $go && self::add_level_to_course_group( $object_id, $lid, $post_type ) ) {
						if ( 'course-bundle' === $post_type ) { $this->set_one_time_bundle_pricing( $object_id, $lid, $price ); } else { $this->set_one_time_sale_pricing( $object_id, $lid, $price ); }
					}
				}
			}
		}
		return $out;
	}

	/**
	 * Zero recurring billing fields on an existing one-time survivor level.
	 *
	 * Leaves initial_payment alone; sale/bundle helpers own checkout amount.
	 * `$wpdb->update` returning 0 (already zeroed) is success; only SQL false fails.
	 *
	 * @param int $lid PMPro level ID.
	 * @return bool
	 */
	private function update_one_time_survivor_level( $lid ) {
		global $wpdb; $lid = (int) $lid; return ( $lid > 0 && isset( $wpdb->pmpro_membership_levels ) ) ? ( false !== $wpdb->update( $wpdb->pmpro_membership_levels, array( 'billing_amount' => 0, 'cycle_number' => 0, 'cycle_period' => '', 'billing_limit' => 0 ), array( 'id' => $lid ), array( '%f', '%d', '%s', '%d' ), array( '%d' ) ) ) : false;
	}

	/**
	 * Insert a blank one-time PMPro level for a course or bundle.
	 *
	 * initial_payment is 0 until a pricing helper runs. Does not write ownership
	 * markers, `_tutorpress_pmpro_levels`, page rows, or group mappings.
	 *
	 * @param int $oid Course or bundle post ID.
	 * @return int New level ID, or 0 on failure.
	 */
	private function insert_one_time_level( $oid ) {
		global $wpdb; $oid = (int) $oid; if ( $oid <= 0 || ! isset( $wpdb->pmpro_membership_levels ) ) { return 0; }
		$ok = $wpdb->insert( $wpdb->pmpro_membership_levels, array( 'name' => get_the_title( $oid ) . ' (One-time)', 'description' => (string) get_post_field( 'post_excerpt', $oid ), 'initial_payment' => 0, 'billing_amount' => 0, 'cycle_number' => 0, 'cycle_period' => '', 'billing_limit' => 0, 'trial_limit' => 0, 'trial_amount' => 0.0 ), array( '%s', '%s', '%f', '%f', '%d', '%s', '%d', '%d', '%f' ) );
		$id = (int) $wpdb->insert_id; return ( false !== $ok && $id > 0 ) ? $id : 0;
	}

	/**
	 * Append a level ID onto the object's current `_tutorpress_pmpro_levels` meta.
	 *
	 * Merges onto live postmeta (intval, unique). Does not replace from a frozen
	 * snapshot or delete the whole key. Success requires the ID to be present
	 * after the write; `update_post_meta` returning false is not success.
	 *
	 * @param int $oid Course or bundle post ID.
	 * @param int $lid PMPro level ID.
	 * @return bool
	 */
	private function append_current_pmpro_level_meta( $oid, $lid ) {
		$oid = (int) $oid; $lid = (int) $lid; if ( $oid <= 0 || $lid <= 0 ) { return false; }
		$m = get_post_meta( $oid, '_tutorpress_pmpro_levels', true ); $ids = array_values( array_unique( array_map( 'intval', array_merge( is_array( $m ) ? $m : array(), array( $lid ) ) ) ) );
		update_post_meta( $oid, '_tutorpress_pmpro_levels', $ids ); return in_array( $lid, array_map( 'intval', (array) get_post_meta( $oid, '_tutorpress_pmpro_levels', true ) ), true );
	}

	/**
	 * Write TutorPress ownership markers on a one-time level.
	 *
	 * Sets `tutorpress_managed=1` and the type reverse key (`tutorpress_course_id`
	 * or `tutorpress_bundle_id`). Does not create page rows, group mappings, or
	 * postmeta, and does not repair other reverse keys.
	 *
	 * @param int    $oid Course or bundle post ID.
	 * @param int    $lid PMPro level ID.
	 * @param string $pt  Post type (`courses` or `course-bundle`).
	 * @return bool True only when both keys read back as written.
	 */
	private function write_one_time_ownership_markers( $oid, $lid, $pt = 'courses' ) {
		$oid = (int) $oid; $lid = (int) $lid; if ( $oid <= 0 || $lid <= 0 || ! function_exists( 'update_pmpro_membership_level_meta' ) || ! function_exists( 'get_pmpro_membership_level_meta' ) ) { return false; }
		$key = ( 'course-bundle' === $pt ) ? 'tutorpress_bundle_id' : 'tutorpress_course_id';
		update_pmpro_membership_level_meta( $lid, $key, $oid ); update_pmpro_membership_level_meta( $lid, 'tutorpress_managed', 1 );
		return (string) $oid === (string) get_pmpro_membership_level_meta( $lid, $key, true ) && '1' === (string) get_pmpro_membership_level_meta( $lid, 'tutorpress_managed', true );
	}

	/**
	 * Store checked one-time sale pricing on a course level.
	 *
	 * Writes `initial_payment` plus regular/sale levelmeta. Courses only; missing
	 * PMPro meta APIs or a non-course post type return false. Does not write
	 * reverse ownership or `tutorpress_managed` (those belong on the marker helper).
	 *
	 * @param int   $oid   Course post ID.
	 * @param int   $lid   PMPro level ID.
	 * @param float $price Regular (non-sale) price.
	 * @return bool
	 */
	private function set_one_time_sale_pricing( $oid, $lid, $price ) {
		$oid = (int) $oid; $lid = (int) $lid; $price = floatval( $price ); if ( $oid <= 0 || $lid <= 0 || 'courses' !== get_post_type( $oid ) || ! function_exists( 'update_pmpro_membership_level_meta' ) || ! function_exists( 'delete_pmpro_membership_level_meta' ) ) { return false; }
		global $wpdb; if ( false === $wpdb->update( $wpdb->pmpro_membership_levels, array( 'initial_payment' => $price ), array( 'id' => $lid ), array( '%f' ), array( '%d' ) ) ) { return false; }
		update_pmpro_membership_level_meta( $lid, 'tutorpress_regular_price', $price ); $sale = floatval( get_post_meta( $oid, 'tutor_course_sale_price', true ) );
		if ( $sale > 0 && $sale < $price ) { update_pmpro_membership_level_meta( $lid, 'tutorpress_sale_price', $sale ); } else { delete_pmpro_membership_level_meta( $lid, 'tutorpress_sale_price' ); }
		return true;
	}

	/**
	 * Store checked one-time bundle checkout and display pricing.
	 *
	 * Distinct from void `set_bundle_pricing_meta()`: this returns false when PMPro
	 * meta APIs are missing or the level-row update fails. Writes checkout amount,
	 * strikethrough totals, and Tutor paid-price post meta.
	 *
	 * @param int   $oid   Bundle post ID.
	 * @param int   $lid   PMPro level ID.
	 * @param float $price Instructor-set bundle price (PMPro checkout amount).
	 * @return bool
	 */
	private function set_one_time_bundle_pricing( $oid, $lid, $price ) {
		$oid = (int) $oid; $lid = (int) $lid; $price = floatval( $price ); if ( $oid <= 0 || $lid <= 0 || ! function_exists( 'update_pmpro_membership_level_meta' ) ) { return false; }
		global $wpdb; if ( false === $wpdb->update( $wpdb->pmpro_membership_levels, array( 'initial_payment' => $price ), array( 'id' => $lid ), array( '%f' ), array( '%d' ) ) ) { return false; }
		$total = $this->calculate_bundle_regular_price( $oid );
		update_pmpro_membership_level_meta( $lid, 'tutorpress_bundle_price', $price ); update_pmpro_membership_level_meta( $lid, 'tutorpress_bundle_total_value', $total ); update_pmpro_membership_level_meta( $lid, 'tutorpress_regular_price', $total ); update_pmpro_membership_level_meta( $lid, 'tutorpress_sale_price', $price );
		update_post_meta( $oid, 'tutor_course_sale_price', $price ); update_post_meta( $oid, '_tutor_course_price_type', 'paid' );
		return true;
	}

	/**
	 * Classify applicator rows into one P20 reporter payload.
	 *
	 * @param int    $oid    Course or bundle post ID.
	 * @param string $branch Selling-option branch.
	 * @param array  $intent `selling_option` and `price_type` keys only.
	 * @param array  $rows   Ordered `array( int $id, string $code )` rows.
	 * @return array Payload for report_p20_operation(); empty reason is silent.
	 */
	private function p20_op( $oid, $branch, $intent, $rows ) {
		$intended = array(); $committed = array(); $blocked = array(); $unprocessed = array(); $halt = false; $stop = false; $failed = '';
		foreach ( (array) $rows as $row ) { $id = isset( $row[0] ) ? absint( $row[0] ) : 0; $code = isset( $row[1] ) ? sanitize_key( (string) $row[1] ) : ''; if ( $id <= 0 ) { continue; } $intended[] = $id; if ( in_array( $code, array( 'ok', 'committed_with_warning' ), true ) ) { $committed[] = $id; } elseif ( 'unprocessed' === $code ) { $unprocessed[] = $id; } else { $blocked[] = $id; if ( '' === $failed ) { $failed = $code; } if ( in_array( $code, array( 'protected', 'ineligible', 'ownership_conflict' ), true ) ) { $halt = true; } else { $stop = true; } } }
		return array( 'object_id' => (int) $oid, 'branch' => $branch, 'intent' => is_array( $intent ) ? $intent : array(), 'intended_ids' => $intended, 'committed_ids' => $committed, 'blocked_ids' => $blocked, 'unprocessed_ids' => $unprocessed, 'reason' => ( $stop ? 'runtime_failure' : ( $halt && ! $committed ? 'preflight_halt' : '' ) ), 'failed_code' => $failed );
	}

	/**
	 * Report one P20 operation: log-only events, then at most one blocked action.
	 *
	 * Extra IDs log with no action. Action reasons log first, then fire
	 * `tutorpress_pmpro_level_deletion_blocked`. Catch `\Throwable`. Success/no-op is silent. No payment, user, or postmeta writes.
	 *
	 * @param array $op {
	 *     Operation context. Unknown reasons and events are ignored.
	 *     @type int      $object_id          Course or bundle post ID.
	 *     @type string   $branch             Selling-option branch.
	 *     @type array    $intent             `selling_option` and `price_type` keys only.
	 *     @type int[]    $intended_ids       IDs intended for deletion.
	 *     @type int[]    $committed_ids      IDs already committed.
	 *     @type int[]    $blocked_ids        IDs blocked by preflight or runtime.
	 *     @type int[]    $unprocessed_ids    IDs not applied.
	 *     @type string   $reason             `incomplete_discovery`, `preflight_halt`, `runtime_failure`, or `dependent_write_failure`.
	 *     @type string   $failed_code        Guard or coordinator code.
	 *     @type string   $failed_stage       `markers`, `course_page`, `group`, or `pricing`; only for `dependent_write_failure`.
	 *     @type string[] $warnings           Warning keys.
	 *     @type string[] $events             `skipped_non_allowed_survivor`, `non_target_extra`, `postmeta_append_ambiguous`.
	 *     @type int[]    $extra_one_time_ids Extra one-time IDs; standalone log, no action.
	 * }
	 * @return void
	 */
	private function report_p20_operation( $op ) {
		$op = is_array( $op ) ? $op : array(); $oid = isset( $op['object_id'] ) ? absint( $op['object_id'] ) : 0; $branch = isset( $op['branch'] ) ? sanitize_key( (string) $op['branch'] ) : ''; $intent = ( isset( $op['intent'] ) && is_array( $op['intent'] ) ) ? $op['intent'] : array(); $ids = function( $k ) use ( $op ) { return array_values( array_unique( array_filter( array_map( 'absint', (array) ( isset( $op[ $k ] ) ? $op[ $k ] : array() ) ) ) ) ); }; $allow_ev = array( 'skipped_non_allowed_survivor', 'non_target_extra', 'postmeta_append_ambiguous' ); $allow_r = array( 'incomplete_discovery', 'preflight_halt', 'runtime_failure', 'dependent_write_failure' ); $allow_s = array( 'markers', 'course_page', 'group', 'pricing' );
		foreach ( array_intersect( $allow_ev, array_map( 'sanitize_key', array_map( 'strval', (array) ( isset( $op['events'] ) ? $op['events'] : array() ) ) ) ) as $ev ) { error_log( '[TP-PMPRO] p20_event=' . $ev . ' object=' . $oid . ' branch=' . $branch ); }
		foreach ( $ids( 'extra_one_time_ids' ) as $xid ) { error_log( '[TP-PMPRO] p20_event=non_target_extra object=' . $oid . ' extra_id=' . $xid ); }
		$reason = isset( $op['reason'] ) ? sanitize_key( (string) $op['reason'] ) : ''; if ( ! in_array( $reason, $allow_r, true ) ) { return; }
		$stage = isset( $op['failed_stage'] ) ? sanitize_key( (string) $op['failed_stage'] ) : '';
		$payload = array( 'object_id' => $oid, 'branch' => $branch, 'intent' => array( 'selling_option' => isset( $intent['selling_option'] ) ? sanitize_key( (string) $intent['selling_option'] ) : '', 'price_type' => isset( $intent['price_type'] ) ? sanitize_key( (string) $intent['price_type'] ) : '' ), 'intended_ids' => $ids( 'intended_ids' ), 'committed_ids' => $ids( 'committed_ids' ), 'blocked_ids' => $ids( 'blocked_ids' ), 'unprocessed_ids' => $ids( 'unprocessed_ids' ), 'reason' => $reason, 'failed_code' => isset( $op['failed_code'] ) ? sanitize_key( (string) $op['failed_code'] ) : '', 'failed_stage' => ( 'dependent_write_failure' === $reason && in_array( $stage, $allow_s, true ) ) ? $stage : '', 'warnings' => array_values( array_filter( array_map( 'sanitize_key', array_map( 'strval', (array) ( isset( $op['warnings'] ) ? $op['warnings'] : array() ) ) ) ) ) );
		error_log( '[TP-PMPRO] p20_blocked reason=' . $reason . ' object=' . $oid . ' branch=' . $branch . ' failed_code=' . $payload['failed_code'] . ' failed_stage=' . $payload['failed_stage'] );
		try { do_action( 'tutorpress_pmpro_level_deletion_blocked', $payload ); } catch ( \Throwable $t ) { error_log( '[TP-PMPRO] p20_action_listener ' . $t->getMessage() ); }
	}

	/**
	 * Handle sale price storage for one-time purchase levels (COURSES ONLY).
	 * 
	 * Implements PMPro sale price pattern:
	 * 1. When sale active: level.initial_payment = sale_price, store regular_price in meta
	 * 2. When no sale: level.initial_payment = regular_price, clear sale meta
	 * 
	 * This ensures PMPro charges the sale price while maintaining both prices for display.
	 * 
	 * NOTE: Bundles do NOT use this method. Bundle one-time purchases have a single price
	 * (the instructor-set bundle price) with no sale price functionality. Bundle pricing
	 * is handled directly in handle_one_time_branch().
	 * 
	 * Course pricing:
	 * - regular_price: Instructor-set via tutor_course_price meta
	 * - sale_price: Optional, instructor-set via tutor_course_sale_price meta
	 * - Both prices stored in level meta for display (regular_price crossed out, sale_price primary)
	 *
	 * @since 1.5.0
	 * @param int    $object_id     Course ID (bundles should not call this method)
	 * @param int    $level_id      PMPro level ID
	 * @param float  $regular_price Regular price for validation (instructor-set)
	 * @param string $post_type     Optional. Post type ('courses'). Auto-detected if not provided.
	 * @return void
	 */
	private function handle_sale_price_for_one_time( $object_id, $level_id, $regular_price, $post_type = null ) {
		if ( ! function_exists( 'update_pmpro_membership_level_meta' ) || ! function_exists( 'delete_pmpro_membership_level_meta' ) ) {
			return;
		}

		// Auto-detect post type if not provided
		if ( null === $post_type ) {
			$post_type = get_post_type( $object_id );
		}

		// Guard: Bundles don't use sale price logic (they have a single price)
		if ( 'course-bundle' === $post_type ) {
			if ( defined( 'TP_PMPRO_LOG' ) && TP_PMPRO_LOG ) {
				error_log( '[TP-PMPRO] handle_sale_price_for_one_time skipped (bundles use direct pricing); bundle=' . $object_id . ' level=' . $level_id );
			}
			return;
		}

		// Validate post type (only courses should reach here)
		if ( 'courses' !== $post_type ) {
			if ( defined( 'TP_PMPRO_LOG' ) && TP_PMPRO_LOG ) {
				error_log( '[TP-PMPRO] handle_sale_price_for_one_time skipped (invalid post_type: ' . $post_type . '); object=' . $object_id . ' level=' . $level_id );
			}
			return;
		}

		$object_label = ( 'course-bundle' === $post_type ) ? 'bundle' : 'course';

		global $wpdb;

		// Step 3.4a: Always store regular price in initial_payment (zero-delay architecture)
		// Dynamic filters will calculate active price at checkout/display time
		$wpdb->update(
			$wpdb->pmpro_membership_levels,
			array( 'initial_payment' => $regular_price ),
			array( 'id' => $level_id ),
			array( '%f' ),
			array( '%d' )
		);

		// Store regular price in meta (for display and runtime filters to reference)
		// For bundles: regular_price is auto-calculated sum of course prices
		// For courses: regular_price is instructor-set
		update_pmpro_membership_level_meta( $level_id, 'tutorpress_regular_price', $regular_price );

		// Read sale price from post meta (same meta key for both courses and bundles)
		$sale_price = get_post_meta( $object_id, 'tutor_course_sale_price', true );
		$sale_price = ! empty( $sale_price ) ? floatval( $sale_price ) : 0.0;

		// Validate sale price
		$is_valid_sale = ( $sale_price > 0 && $sale_price < $regular_price );

		if ( $is_valid_sale ) {
			// Store sale price in meta (for runtime filters to use)
			// Sale price is instructor-set for both courses and bundles
			update_pmpro_membership_level_meta( $level_id, 'tutorpress_sale_price', $sale_price );
			
			// Store reference to bundle or course (if not already set)
			// This ensures we can identify which bundle/course this level belongs to
			if ( 'course-bundle' === $post_type ) {
				if ( ! get_pmpro_membership_level_meta( $level_id, 'tutorpress_bundle_id', true ) ) {
					update_pmpro_membership_level_meta( $level_id, 'tutorpress_bundle_id', $object_id );
				}
			} else {
				if ( ! get_pmpro_membership_level_meta( $level_id, 'tutorpress_course_id', true ) ) {
					update_pmpro_membership_level_meta( $level_id, 'tutorpress_course_id', $object_id );
				}
			}
			
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[TP-PMPRO] stored_sale_price_one_time level_id=' . $level_id . ' ' . $object_label . '=' . $object_id . ' sale_price=' . $sale_price . ' regular_price=' . $regular_price );
			}
		} else {
			// Sale price removed or invalid - clean up sale meta
			delete_pmpro_membership_level_meta( $level_id, 'tutorpress_sale_price' );
			
			if ( $sale_price > 0 ) {
				// Log validation failure for debugging
				$this->log( '[TP-PMPRO] invalid_sale_price level_id=' . $level_id . ' ' . $object_label . '=' . $object_id . ' sale_price=' . $sale_price . ' regular_price=' . $regular_price . ' reason=sale_must_be_less_than_regular' );
			} else {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[TP-PMPRO] cleared_sale_price_one_time level_id=' . $level_id . ' ' . $object_label . '=' . $object_id );
				}
			}
		}
		
		// Note: Runtime calculation happens in PaidMembershipsPro::get_active_price_for_level() and filter hooks (Step 3.4c)
		// Both regular_price and sale_price are stored in level meta for display:
		// - Regular price: displayed crossed out (<del>$299</del>)
		// - Sale price: displayed as primary ($199)
	}

	/**
	 * Handle sale price storage for recurring subscription levels.
	 * 
	 * Refactored for dynamic pricing model (Step 3.4a):
	 * - Always stores regular price in database (no pre-computation)
	 * - Runtime filters calculate active price based on sale schedule
	 * - Preserves sale meta for scheduled/future sales
	 * 
	 * This supports the enrollment window model where new subscribers can get
	 * a discounted first payment during the sale period, while renewal billing
	 * (billing_amount) remains at the regular recurring price.
	 *
	 * @since 1.5.0
	 * @param int   $level_id                PMPro level ID
	 * @param float $regular_initial_payment Regular first payment amount
	 * @return void
	 */
	public static function handle_sale_price_for_subscription( $level_id, $regular_initial_payment ) {
		if ( ! function_exists( 'get_pmpro_membership_level_meta' ) || ! function_exists( 'update_pmpro_membership_level_meta' ) || ! function_exists( 'delete_pmpro_membership_level_meta' ) ) {
			return;
		}

		global $wpdb;

		// Always store regular price in initial_payment (no runtime computation)
		// Dynamic filters will calculate active price at checkout/display time
		$wpdb->update(
			$wpdb->pmpro_membership_levels,
			array( 'initial_payment' => $regular_initial_payment ),
			array( 'id' => $level_id ),
			array( '%f' ),
			array( '%d' )
		);

		// Store regular price in meta (for display and runtime filters to reference)
		update_pmpro_membership_level_meta( $level_id, 'tutorpress_regular_price', $regular_initial_payment );

		// Check if sale price was explicitly removed (0, null, or empty)
		$sale_price_meta = get_pmpro_membership_level_meta( $level_id, 'sale_price', true );
		if ( empty( $sale_price_meta ) || floatval( $sale_price_meta ) <= 0 ) {
			// User removed sale price - clean up ALL sale meta
			delete_pmpro_membership_level_meta( $level_id, 'sale_price' );
			delete_pmpro_membership_level_meta( $level_id, 'tutorpress_sale_price' );
			delete_pmpro_membership_level_meta( $level_id, 'tutorpress_regular_price' );
			delete_pmpro_membership_level_meta( $level_id, 'tutorpress_sale_price_from' );
			delete_pmpro_membership_level_meta( $level_id, 'tutorpress_sale_price_to' );
			
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[TP-PMPRO] cleared_sale_price_subscription level_id=' . $level_id );
			}
		} else {
			// Sale price exists - store in prefixed meta for runtime filters
			update_pmpro_membership_level_meta( $level_id, 'tutorpress_sale_price', floatval( $sale_price_meta ) );
			
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[TP-PMPRO] stored_sale_price_meta level_id=' . $level_id . ' sale_price=' . $sale_price_meta );
			}
		}
		
		// Note: Sale dates are stored by REST controller (Step 3.1)
		// Runtime calculation happens in PaidMembershipsPro::is_sale_active() (Substep 3.4a)
	}

	/**
	 * Handle Membership-only branch: delete all course-specific PMPro levels.
	 * This is used when selling_option is set to 'membership' (full-site membership only).
	 * 
	 * ⚠️ IMPORTANT: This only deletes course-specific levels. Full-site membership levels
	 * (global) and category-wise membership levels are NEVER touched.
	 *
	 * @param int   $course_id
	 * @param array $state
	 * @return void
	 */
	private function handle_membership_branch( $course_id, $state = array() ) {
		$course_id = (int) $course_id;
		if ( ! is_array( $state ) ) {
			$state = array();
		}
		$ids = isset( $state['valid_ids'] ) ? $state['valid_ids'] : array();
		return $this->apply_typed_live_deletes( $course_id, $ids, $state );
	}

	/**
	 * Handle Both and All branch: ensure both one-time and subscription levels coexist.
	 * 
	 * This handler serves two selling options:
	 * - 'both' (Subscription or one-time purchase): Individual course options only
	 * - 'all' (Everything): Individual options + full-site membership (admin-managed)
	 * 
	 * Backend reconciliation logic is identical for both:
	 * - Auto-creates one-time level if missing (when price is set)
	 * - Preserves all existing subscription plans
	 * - Does NOT delete any levels
	 * 
	 * Note: Full-site membership validation for 'all' happens in frontend (TutorPress core).
	 * This method only manages course-specific one-time and subscription levels.
	 *
	 * @param int    $course_id
	 * @param array  $state
	 * @param string $post_type Post type ('courses' or 'course-bundle')
	 * @return void
	 */
	private function handle_both_and_all_branch( $course_id, $state = array(), $post_type = 'courses' ) {
		$course_id = (int) $course_id;
		$object_label = ( $post_type === 'course-bundle' ) ? 'bundle' : 'course';
		$meta_key = ( $post_type === 'course-bundle' ) ? 'tutorpress_bundle_id' : 'tutorpress_course_id';
		$one_time = isset( $state['one_time_ids'] ) ? (array) $state['one_time_ids'] : array();
		$recurring = isset( $state['recurring_ids'] ) ? (array) $state['recurring_ids'] : array();
		
		// If no one-time level exists, auto-create one (if price is set)
		if ( empty( $one_time ) ) {
			$regular_price = get_post_meta( $course_id, 'tutor_course_price', true );
			$regular_price = ! empty( $regular_price ) ? floatval( $regular_price ) : 0.0;
			
			if ( $regular_price > 0 ) {
				global $wpdb;
				$insert_data = array(
					'name'            => get_the_title( $course_id ) . ' (One-time)',
					'description'     => get_post_field( 'post_excerpt', $course_id ) ?: '',
					'initial_payment' => $regular_price,
					'billing_amount'  => 0,
					'cycle_number'    => 0,
					'cycle_period'    => '',
					'billing_limit'   => 0,
					'trial_limit'     => 0,
					'trial_amount'    => 0.0,
				);
				$wpdb->insert( $wpdb->pmpro_membership_levels, $insert_data );
				$level_id = (int) $wpdb->insert_id;
				
				if ( $level_id > 0 ) {
					// Set reverse ownership meta
					if ( function_exists( 'update_pmpro_membership_level_meta' ) ) {
						update_pmpro_membership_level_meta( $level_id, $meta_key, $course_id );
						update_pmpro_membership_level_meta( $level_id, 'tutorpress_managed', 1 );
					}
					// Ensure association
					if ( ! class_exists( '\\TUTORPRESS_PMPRO\\PMPro_Association' ) ) {
						require_once $this->path . 'includes/utilities/class-pmpro-association.php';
					}
					\TUTORPRESS_PMPRO\PMPro_Association::ensure_course_level_association( $course_id, $level_id );
					
					// Phase 5: Add level to course group
					self::add_level_to_course_group( $course_id, $level_id, $post_type );
					
					// Handle sale price for the newly created one-time level
					$this->handle_sale_price_for_one_time( $course_id, $level_id, $regular_price );

					if ( 'course-bundle' === $post_type ) {
						$this->set_bundle_pricing_meta( $course_id, $level_id, $regular_price );
					}

					$one_time[] = $level_id; $m = get_post_meta( $course_id, '_tutorpress_pmpro_levels', true ); update_post_meta( $course_id, '_tutorpress_pmpro_levels', array_values( array_unique( array_map( 'intval', array_merge( is_array( $m ) ? $m : array(), array( $level_id ) ) ) ) ) );
					$this->log( '[TP-PMPRO] handle_both_and_all_branch created_one_time_level_id=' . $level_id . ' ' . $object_label . '=' . $course_id . ' price=' . $regular_price );
				}
			} else {
				$this->log( '[TP-PMPRO] handle_both_and_all_branch skipped_one_time_creation (no price set); ' . $object_label . '=' . $course_id );
			}
		} else {
			// One-time level already exists - update it with current price and sale
			$existing_level_id = (int) $one_time[0];
			$regular_price = get_post_meta( $course_id, 'tutor_course_price', true );
			$regular_price = ! empty( $regular_price ) ? floatval( $regular_price ) : 0.0;
			
			if ( $regular_price > 0 ) {
				// Handle sale price (which will also update initial_payment)
				$this->handle_sale_price_for_one_time( $course_id, $existing_level_id, $regular_price );

				if ( 'course-bundle' === $post_type ) {
					$this->set_bundle_pricing_meta( $course_id, $existing_level_id, $regular_price );
				}
			}
			
			$this->log( '[TP-PMPRO] handle_both_and_all_branch one_time_level_exists; ' . $object_label . '=' . $course_id . ' level_id=' . $existing_level_id );
		}
		
		$this->log( '[TP-PMPRO] handle_both_and_all_branch updated_meta; ' . $object_label . '=' . $course_id . ' one_time_count=' . count( $one_time ) . ' recurring_count=' . count( $recurring ) );
	}

	/**
	 * Required-mode read-only PMPro level discovery.
	 *
	 * @param int    $object_id Object post ID.
	 * @param string $mode      Required discovery mode.
	 * @return array
	 */
	public function discover_pmpro_levels( $object_id, $mode ) {
		$inc = array( 'incomplete' => true, 'ids' => array(), 'stale_ids' => array(), 'rows' => array(), 'one_time_ids' => array(), 'recurring_ids' => array() );
		if ( ! in_array( $mode, array( 'permanent_course', 'permanent_bundle', 'reconcile_course', 'reconcile_bundle', 'p10' ), true ) ) { return $inc; }
		global $wpdb;
		if ( ! isset( $wpdb->pmpro_membership_levels ) || ( 'p10' !== $mode && ! isset( $wpdb->pmpro_memberships_pages ) ) ) { return $inc; }
		if ( in_array( $mode, array( 'permanent_bundle', 'reconcile_course', 'reconcile_bundle' ), true ) && ! isset( $wpdb->pmpro_membership_levelmeta ) ) { return $inc; }
		$pages = array();
		if ( 'p10' !== $mode ) { $wpdb->last_error = ''; $pages = $wpdb->get_col( $wpdb->prepare( "SELECT membership_id FROM {$wpdb->pmpro_memberships_pages} WHERE page_id = %d", (int) $object_id ) ); if ( '' !== (string) $wpdb->last_error ) { return $inc; } }
		$meta = get_post_meta( (int) $object_id, '_tutorpress_pmpro_levels', true );
		$rev = array();
		if ( in_array( $mode, array( 'permanent_bundle', 'reconcile_course', 'reconcile_bundle' ), true ) ) {
			$wpdb->last_error = '';
			$rev = $wpdb->get_col( $wpdb->prepare( "SELECT pmpro_membership_level_id FROM {$wpdb->pmpro_membership_levelmeta} WHERE meta_key = %s AND meta_value = %s", 'reconcile_course' === $mode ? 'tutorpress_course_id' : 'tutorpress_bundle_id', (string) (int) $object_id ) );
			if ( '' !== (string) $wpdb->last_error ) { return $inc; }
		}
		$ids = array();
		foreach ( array_merge( (array) $pages, is_array( $meta ) ? $meta : array(), (array) $rev ) as $id ) {
			$id = (int) $id; if ( $id > 0 ) { $ids[ $id ] = $id; }
		}
		$stale = array(); $rows = array(); $ot = array(); $rc = array();
		foreach ( $ids as $lid ) {
			$wpdb->last_error = '';
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, billing_amount, cycle_number FROM {$wpdb->pmpro_membership_levels} WHERE id = %d", $lid ), ARRAY_A );
			if ( '' !== (string) $wpdb->last_error ) { return $inc; }
			if ( ! is_array( $row ) || empty( $row['id'] ) ) { $stale[] = $lid; } else { $rid = (int) $row['id']; $rows[ $rid ] = $row; if ( (float) $row['billing_amount'] <= 0 && 0 === (int) $row['cycle_number'] ) { $ot[] = $rid; } else { $rc[] = $rid; } }
		}
		return array( 'incomplete' => false, 'ids' => array_values( $ids ), 'stale_ids' => $stale, 'rows' => $rows, 'one_time_ids' => $ot, 'recurring_ids' => $rc );
	}

	/**
	 * Veto permanent course/bundle deletion when the target set is blocked.
	 *
	 * `pre_delete_post` callback. Non-course/bundle posts return `$check`
	 * unchanged. Incomplete discovery or any live ID that is not `allowed` or
	 * `shared_unlink` returns `false` (blocks WordPress) and clears any freeze.
	 * Otherwise freezes each ID's maximum action (`full_delete`,
	 * `shared_unlink`, `stale_cleanup`) and returns `null` so deletion proceeds.
	 * `$force_delete` is part of the hook signature and is not consulted.
	 *
	 * @param bool|null $check        Prior filter value.
	 * @param \WP_Post  $post         Post being deleted.
	 * @param bool      $force_delete Whether this is a force-delete (unused).
	 * @return bool|null `false` to veto, `null` to allow, or `$check`.
	 */
	public function veto_permanent_pmpro_delete( $check, $post, $force_delete = false ) {
		if ( ! $post instanceof \WP_Post ) { return $check; }
		$type = $post->post_type;
		$mode = ( 'courses' === $type ) ? 'permanent_course' : ( ( 'course-bundle' === $type ) ? 'permanent_bundle' : '' );
		if ( '' === $mode ) { return $check; }
		$oid = (int) $post->ID;
		$disc = $this->discover_pmpro_levels( $oid, $mode );
		if ( ! empty( $disc['incomplete'] ) ) { unset( $this->permanent_delete_freeze[ $oid ] ); return false; }
		$freeze = array(); $block = false;
		foreach ( (array) $disc['stale_ids'] as $lid ) { $freeze[ (int) $lid ] = 'stale_cleanup'; }
		foreach ( array_keys( (array) $disc['rows'] ) as $lid ) {
			$lid = (int) $lid;
			$dec = PMPro_Level_Deletion_Guard::evaluate( $lid, $oid, $type );
			if ( 'allowed' === $dec ) { $freeze[ $lid ] = 'full_delete'; } elseif ( 'shared_unlink' === $dec ) { $freeze[ $lid ] = 'shared_unlink'; } else { $block = true; }
		}
		if ( $block ) { unset( $this->permanent_delete_freeze[ $oid ] ); return false; }
		$this->permanent_delete_freeze[ $oid ] = $freeze;
		return null;
	}

	/**
	 * Consume a frozen permanent-delete map exactly once for a course or bundle.
	 *
	 * Dual `before_delete_post` callbacks share this. A stored array freeze is
	 * replaced with `false` before any write so the second callback is a no-op.
	 * Bypass (no freeze) rediscovers, marks non-allowed/non-shared live IDs as
	 * `refuse`, and still consumes the slot. `refuse` IDs are skipped without
	 * stopping siblings. Full-delete continues on `ok`, `committed_with_warning`,
	 * `protected`, `ineligible`, `ownership_conflict`, and `missing`; other
	 * codes stop later IDs. Owned-empty group cleanup runs only if every
	 * attempted ID succeeded. WordPress still deletes the post after a failure.
	 *
	 * @param int $post_id Course or bundle post ID.
	 * @return void
	 */
	private function apply_permanent_pmpro_delete( $post_id ) {
		$post_id = (int) $post_id; $type = get_post_type( $post_id );
		if ( 'courses' !== $type && 'course-bundle' !== $type ) { return; }
		if ( array_key_exists( $post_id, $this->permanent_delete_freeze ) ) { $freeze = $this->permanent_delete_freeze[ $post_id ]; $this->permanent_delete_freeze[ $post_id ] = false; if ( ! is_array( $freeze ) ) { return; } }
		else { $disc = $this->discover_pmpro_levels( $post_id, ( 'courses' === $type ) ? 'permanent_course' : 'permanent_bundle' ); $this->permanent_delete_freeze[ $post_id ] = false; if ( ! empty( $disc['incomplete'] ) ) { return; } $freeze = array(); foreach ( (array) $disc['stale_ids'] as $lid ) { $freeze[ (int) $lid ] = 'stale_cleanup'; } foreach ( array_keys( (array) $disc['rows'] ) as $lid ) { $lid = (int) $lid; $dec = PMPro_Level_Deletion_Guard::evaluate( $lid, $post_id, $type ); $freeze[ $lid ] = ( 'allowed' === $dec ) ? 'full_delete' : ( ( 'shared_unlink' === $dec ) ? 'shared_unlink' : 'refuse' ); } }
		if ( ! class_exists( '\\TUTORPRESS_PMPRO\\PMPro_Level_Cleanup' ) ) { require_once $this->path . 'includes/utilities/class-pmpro-level-cleanup.php'; }
		$ok = true;
		foreach ( $freeze as $lid => $ceil ) {
			$lid = (int) $lid; if ( $lid <= 0 || 'refuse' === $ceil ) { continue; }
			if ( 'full_delete' === $ceil ) { $out = PMPro_Level_Deletion_Coordinator::delete_level( $lid, $post_id, $type ); if ( ! in_array( $out, array( 'ok', 'committed_with_warning', 'protected', 'ineligible', 'ownership_conflict', 'missing' ), true ) ) { $ok = false; break; } }
			elseif ( 'shared_unlink' === $ceil ) { if ( 'ok' !== PMPro_Level_Cleanup::unlink_shared_level( $post_id, $lid ) ) { $ok = false; break; } }
			elseif ( 'stale_cleanup' === $ceil ) { $out = PMPro_Level_Cleanup::cleanup_missing_level( $post_id, $lid ); if ( 'ok' !== $out && 'skip' !== $out ) { $ok = false; break; } }
		}
		if ( $ok ) { PMPro_Level_Cleanup::delete_owned_empty_group( $post_id ); }
	}

	/**
	 * Reject REST course/bundle updates that would delete a blocked level.
	 *
	 * `rest_pre_insert_courses` / `rest_pre_insert_course-bundle` callback.
	 * Autosave, creates (ID 0), other post types, and requests with no selling
	 * option or price-type intent return `$prepared_post` unchanged. Top-level
	 * canonical keys win over `meta` / `meta_input`; omitted companions come
	 * from stored postmeta. `free` and `membership` preflight all live IDs,
	 * `subscription` one-time IDs, and `one_time` recurring IDs via reconcile
	 * discovery. Incomplete discovery or Guard `protected` / `ineligible` /
	 * `ownership_conflict` returns 409 `tutorpress_pmpro_level_deletion_blocked`.
	 * Performs no coordinator, stale cleanup, or association writes.
	 *
	 * @param object           $prepared_post Prepared post data.
	 * @param \WP_REST_Request $request       REST request.
	 * @return object|\WP_Error
	 */
	public function veto_detectable_rest_pmpro_deletion( $prepared_post, $request ) {
		$this->detectable_rest_intent = null;
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return $prepared_post; }
		$id = ( is_object( $prepared_post ) && ! empty( $prepared_post->ID ) ) ? (int) $prepared_post->ID : 0;
		if ( $id <= 0 ) { return $prepared_post; }
		$type = ( is_object( $prepared_post ) && ! empty( $prepared_post->post_type ) ) ? $prepared_post->post_type : get_post_type( $id );
		$so = null; $pt = null; $gp = is_object( $request ) && method_exists( $request, 'get_param' );
		$meta = ( $gp && is_array( $request->get_param( 'meta' ) ) ) ? $request->get_param( 'meta' ) : array(); $mi = ( $gp && is_array( $request->get_param( 'meta_input' ) ) ) ? $request->get_param( 'meta_input' ) : array();
		if ( 'courses' === $type ) {
			$cs = ( $gp && is_array( $request->get_param( 'course_settings' ) ) ) ? $request->get_param( 'course_settings' ) : array();
			if ( array_key_exists( 'selling_option', $cs ) ) { $so = $cs['selling_option']; } elseif ( array_key_exists( 'subscription_enabled', $cs ) ) { $so = empty( $cs['subscription_enabled'] ) ? 'one_time' : 'subscription'; }
			if ( array_key_exists( 'pricing_model', $cs ) ) { $pt = ( 'free' === $cs['pricing_model'] ) ? 'free' : 'paid'; if ( array_key_exists( 'is_public_course', $cs ) && $cs['is_public_course'] && 'paid' === $pt ) { $pt = 'free'; } }
			if ( null === $so ) { foreach ( array( $meta, $mi ) as $bag ) { if ( array_key_exists( 'tutor_course_selling_option', $bag ) ) { $so = $bag['tutor_course_selling_option']; break; } if ( array_key_exists( 'selling_option', $bag ) ) { $so = $bag['selling_option']; break; } } }
			if ( null === $pt ) { foreach ( array( $meta, $mi ) as $bag ) { foreach ( array( '_tutor_course_price_type', 'tutor_course_price_type', 'price_type' ) as $k ) { if ( array_key_exists( $k, $bag ) ) { $pt = $bag[ $k ]; break 2; } } } }
		} elseif ( 'course-bundle' === $type ) {
			if ( $gp ) { foreach ( array( 'selling_option', 'tutor_course_selling_option' ) as $k ) { if ( null === $so ) { $so = $request->get_param( $k ); } } foreach ( array( 'price_type', 'tutor_course_price_type', '_tutor_course_price_type' ) as $k ) { if ( null === $pt ) { $pt = $request->get_param( $k ); } } }
			if ( null === $so ) { foreach ( array( $meta, $mi ) as $bag ) { foreach ( array( 'selling_option', 'tutor_course_selling_option' ) as $k ) { if ( array_key_exists( $k, $bag ) ) { $so = $bag[ $k ]; break 2; } } } }
			if ( null === $pt ) { foreach ( array( $meta, $mi ) as $bag ) { foreach ( array( 'price_type', 'tutor_course_price_type', '_tutor_course_price_type' ) as $k ) { if ( array_key_exists( $k, $bag ) ) { $pt = $bag[ $k ]; break 2; } } } }
			if ( null !== $so && is_callable( array( '\\TutorPress_Bundle', 'sanitize_selling_option' ) ) ) { $so = \TutorPress_Bundle::sanitize_selling_option( $so ); }
			if ( null !== $pt && is_callable( array( '\\TutorPress_Bundle', 'sanitize_price_type' ) ) ) { $pt = \TutorPress_Bundle::sanitize_price_type( $pt ); }
		} else { return $prepared_post; }
		if ( null === $so && null === $pt ) { return $prepared_post; }
		if ( is_string( $so ) ) { $so = sanitize_text_field( $so ); } if ( is_string( $pt ) ) { $pt = sanitize_text_field( $pt ); }
		if ( null === $so ) { $so = get_post_meta( $id, 'tutor_course_selling_option', true ); } if ( null === $pt ) { $pt = get_post_meta( $id, '_tutor_course_price_type', true ); }
		$this->detectable_rest_intent = array( 'selling_option' => $so, 'price_type' => $pt );
		if ( 'free' === $pt ) { $kind = 'all'; } elseif ( 'membership' === $so ) { $kind = 'all'; } elseif ( 'subscription' === $so ) { $kind = 'ot'; } elseif ( 'one_time' === $so ) { $kind = 'rc'; } else { return $prepared_post; }
		$disc = $this->discover_pmpro_levels( $id, ( 'courses' === $type ) ? 'reconcile_course' : 'reconcile_bundle' );
		$err = new \WP_Error( 'tutorpress_pmpro_level_deletion_blocked', __( 'This save would delete a membership level that is protected or ineligible.', 'tutorpress-pmpro' ), array( 'status' => 409 ) );
		if ( ! empty( $disc['incomplete'] ) ) { return $err; }
		$tg = ( 'all' === $kind ) ? array_keys( (array) $disc['rows'] ) : ( ( 'ot' === $kind ) ? (array) $disc['one_time_ids'] : (array) $disc['recurring_ids'] );
		foreach ( $tg as $lid ) { $lid = (int) $lid; if ( $lid > 0 && in_array( PMPro_Level_Deletion_Guard::evaluate( $lid, $id, $type ), array( 'protected', 'ineligible', 'ownership_conflict' ), true ) ) { return $err; } }
		return $prepared_post;
	}

	/**
	 * Permanently delete PMPro levels associated with a course/bundle when the post is deleted.
	 *
	 * - Does NOT run for unpublish/trash; only for permanent delete.
	 * - Deletes levels that are owned by the course (level meta tutorpress_course_id matches),
	 *   or levels whose association count is 1 (only mapped to this course).
	 * - Otherwise, only removes the course-level association and prunes course meta.
	 *
	 * @param int $post_id
	 * @return void
	 */
	public function delete_course_levels_on_delete( $post_id ) {
		$post_id = (int) $post_id;
		$post_type = get_post_type( $post_id );
		if ( ! in_array( $post_type, array( 'courses', 'course-bundle' ), true ) ) {
			return;
		}
		$this->apply_permanent_pmpro_delete( (int) $post_id );
		return;

		global $wpdb;
		$level_ids = array();
		if ( isset( $wpdb->pmpro_memberships_pages ) ) {
			$level_ids = $wpdb->get_col( $wpdb->prepare( "SELECT membership_id FROM {$wpdb->pmpro_memberships_pages} WHERE page_id = %d", $post_id ) );
		}
		$meta_ids = get_post_meta( $post_id, '_tutorpress_pmpro_levels', true );
		if ( is_array( $meta_ids ) ) {
			$level_ids = array_merge( $level_ids, $meta_ids );
		}
		$level_ids = array_values( array_unique( array_map( 'intval', (array) $level_ids ) ) );
		if ( empty( $level_ids ) ) {
			return;
		}

		// Ensure cleanup class is available
		if ( ! class_exists( '\\TUTORPRESS_PMPRO\\PMPro_Level_Cleanup' ) ) {
			require_once $this->path . 'includes/utilities/class-pmpro-level-cleanup.php';
		}

		foreach ( $level_ids as $lid ) {
			$owned_by_course = false;
			if ( function_exists( 'get_pmpro_membership_level_meta' ) ) {
				$owner = (int) get_pmpro_membership_level_meta( $lid, 'tutorpress_course_id', true );
				$owned_by_course = ( $owner === $post_id );
			}

			$assoc_count = 0;
			if ( isset( $wpdb->pmpro_memberships_pages ) ) {
				$assoc_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_memberships_pages} WHERE membership_id = %d", $lid ) );
			}

			if ( $owned_by_course || $assoc_count <= 1 ) {
				\TUTORPRESS_PMPRO\PMPro_Level_Cleanup::full_delete_level( $lid, true );
			} else {
				\TUTORPRESS_PMPRO\PMPro_Level_Cleanup::remove_course_level_mapping( $post_id, $lid );
			}
		}

		// Clear course meta after cleanup
		delete_post_meta( $post_id, '_tutorpress_pmpro_levels' );
		
		// Phase 5: Delete course level group if empty
		self::delete_course_level_group_if_empty( $post_id );
	}

	/**
	 * Remove a directly deleted PMPro level from TutorPress meta and normalize canonical pricing state.
	 *
	 * @param int $level_id The deleting PMPro membership level ID.
	 * @return void
	 */
	public function cleanup_deleted_pmpro_level_meta( $level_id ) {
		$level_id = absint( $level_id );
		if ( $level_id <= 0 ) {
			return;
		}
		if ( PMPro_Level_Deletion_Coordinator::deletion_listener_suppressed() ) {
			return;
		}

		$owner_context = $this->get_pmpro_delete_owner_context( $level_id );
		if ( empty( $owner_context['object_id'] ) || empty( $owner_context['owner_meta_key'] ) ) {
			return;
		}

		$object_id = (int) $owner_context['object_id'];
		$remaining_state = $this->get_remaining_pmpro_state_for_owner( $object_id, $owner_context['owner_meta_key'], $level_id );
		$remaining_valid_ids = isset( $remaining_state['valid_ids'] ) ? (array) $remaining_state['valid_ids'] : array();
		$one_time_ids = isset( $remaining_state['one_time_ids'] ) ? (array) $remaining_state['one_time_ids'] : array();
		$recurring_ids = isset( $remaining_state['recurring_ids'] ) ? (array) $remaining_state['recurring_ids'] : array();

		if ( empty( $remaining_valid_ids ) ) {
			delete_post_meta( $object_id, '_tutorpress_pmpro_levels' );
			update_post_meta( $object_id, '_tutor_course_price_type', 'free' );
			update_post_meta( $object_id, 'tutor_course_price', 0 );
			update_post_meta( $object_id, 'tutor_course_sale_price', '' );
			update_post_meta( $object_id, 'tutor_course_selling_option', 'free' );

			// Try immediately, then retry after PMPro finishes deleting level-group rows.
			self::delete_course_level_group_if_empty( $object_id );
			$skip = PMPro_Level_Deletion_Coordinator::deletion_listener_suppressed();
			add_action( 'shutdown', function() use ( $object_id, $skip ) {
				if ( $skip ) { return; }
				self::delete_course_level_group_if_empty( $object_id );
			}, 999 );

			$this->log( '[TP-PMPRO] cleanup_deleted_pmpro_level_meta transitioned_to_free deleted_level=' . $level_id . ' ' . $owner_context['object_label'] . '=' . $object_id );
			return;
		}

		update_post_meta( $object_id, '_tutorpress_pmpro_levels', $remaining_valid_ids );

		if ( empty( $one_time_ids ) && ! empty( $recurring_ids ) ) {
			update_post_meta( $object_id, '_tutor_course_price_type', 'paid' );
			update_post_meta( $object_id, 'tutor_course_price', 0 );
			update_post_meta( $object_id, 'tutor_course_sale_price', '' );
			update_post_meta( $object_id, 'tutor_course_selling_option', 'subscription' );

			$this->log( '[TP-PMPRO] cleanup_deleted_pmpro_level_meta normalized_to_subscription deleted_level=' . $level_id . ' ' . $owner_context['object_label'] . '=' . $object_id . ' recurring_remaining=' . count( $recurring_ids ) );
			return;
		}

		update_post_meta( $object_id, '_tutor_course_price_type', 'paid' );

		if ( ! empty( $one_time_ids ) && empty( $recurring_ids ) ) {
			update_post_meta( $object_id, 'tutor_course_selling_option', 'one_time' );

			$this->log( '[TP-PMPRO] cleanup_deleted_pmpro_level_meta normalized_to_one_time deleted_level=' . $level_id . ' ' . $owner_context['object_label'] . '=' . $object_id . ' one_time_remaining=' . count( $one_time_ids ) );
			return;
		}

		$current_selling_option = get_post_meta( $object_id, 'tutor_course_selling_option', true );
		$normalized_selling_option = in_array( $current_selling_option, array( 'both', 'all' ), true ) ? $current_selling_option : 'both';
		update_post_meta( $object_id, 'tutor_course_selling_option', $normalized_selling_option );

		$this->log( '[TP-PMPRO] cleanup_deleted_pmpro_level_meta normalized_to_mixed deleted_level=' . $level_id . ' ' . $owner_context['object_label'] . '=' . $object_id . ' one_time_remaining=' . count( $one_time_ids ) . ' recurring_remaining=' . count( $recurring_ids ) . ' selling_option=' . $normalized_selling_option );
	}

	/**
	 * Resolve the owning course or bundle for a directly deleted PMPro level.
	 *
	 * @param int $level_id The deleting PMPro membership level ID.
	 * @return array<string,mixed>
	 */
	private function get_pmpro_delete_owner_context( $level_id ) {
		global $wpdb;

		$level_id = absint( $level_id );
		$course_id = 0;
		$bundle_id = 0;

		if ( function_exists( 'get_pmpro_membership_level_meta' ) ) {
			$course_id = absint( get_pmpro_membership_level_meta( $level_id, 'tutorpress_course_id', true ) );
			$bundle_id = absint( get_pmpro_membership_level_meta( $level_id, 'tutorpress_bundle_id', true ) );
		} elseif ( isset( $wpdb->pmpro_membership_levelmeta ) ) {
			$course_id = absint( $wpdb->get_var( $wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->pmpro_membership_levelmeta} WHERE pmpro_membership_level_id = %d AND meta_key = %s LIMIT 1",
				$level_id,
				'tutorpress_course_id'
			) ) );
			$bundle_id = absint( $wpdb->get_var( $wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->pmpro_membership_levelmeta} WHERE pmpro_membership_level_id = %d AND meta_key = %s LIMIT 1",
				$level_id,
				'tutorpress_bundle_id'
			) ) );
		}

		if ( $course_id > 0 ) {
			return array(
				'object_id'      => $course_id,
				'owner_meta_key' => 'tutorpress_course_id',
				'object_label'   => 'course',
			);
		}

		if ( $bundle_id > 0 ) {
			return array(
				'object_id'      => $bundle_id,
				'owner_meta_key' => 'tutorpress_bundle_id',
				'object_label'   => 'bundle',
			);
		}

		return array();
	}

	/**
	 * Discover and classify remaining valid PMPro levels for an owner during direct-delete handling.
	 *
	 * @param int    $object_id The owning course or bundle ID.
	 * @param string $owner_meta_key Reverse meta key used by this owner.
	 * @param int    $deleted_level_id The currently deleting PMPro level ID.
	 * @return array{
	 *     valid_ids:int[],
	 *     one_time_ids:int[],
	 *     recurring_ids:int[]
	 * }
	 */
	private function get_remaining_pmpro_state_for_owner( $object_id, $owner_meta_key, $deleted_level_id ) {
		global $wpdb;

		$object_id = (int) $object_id;
		$deleted_level_id = absint( $deleted_level_id );

		$associated_ids = array();
		if ( isset( $wpdb->pmpro_memberships_pages ) ) {
			$associated_ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT membership_id FROM {$wpdb->pmpro_memberships_pages} WHERE page_id = %d",
				$object_id
			) );
		}
		$associated_ids = array_map( 'intval', (array) $associated_ids );

		$meta_ids = get_post_meta( $object_id, '_tutorpress_pmpro_levels', true );
		if ( ! is_array( $meta_ids ) ) {
			$meta_ids = array();
		}
		$meta_ids = array_map( 'intval', $meta_ids );

		$reverse_meta_ids = array();
		if ( isset( $wpdb->pmpro_membership_levelmeta ) ) {
			$reverse_meta_ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT pmpro_membership_level_id FROM {$wpdb->pmpro_membership_levelmeta} WHERE meta_key = %s AND meta_value = %s",
				$owner_meta_key,
				(string) $object_id
			) );
		}
		$reverse_meta_ids = array_map( 'intval', (array) $reverse_meta_ids );

		$candidate_ids = array_values( array_unique( array_merge( $associated_ids, $meta_ids, $reverse_meta_ids ) ) );
		$candidate_ids = array_values( array_filter( $candidate_ids, function( $candidate_id ) use ( $deleted_level_id ) {
			return $candidate_id > 0 && $candidate_id !== $deleted_level_id;
		} ) );

		$valid_ids = array();
		$one_time_ids = array();
		$recurring_ids = array();
		foreach ( $candidate_ids as $candidate_id ) {
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, billing_amount, cycle_number FROM {$wpdb->pmpro_membership_levels} WHERE id = %d",
				$candidate_id
			), ARRAY_A );

			if ( $row && isset( $row['id'] ) ) {
				$valid_id = (int) $row['id'];
				$valid_ids[] = $valid_id;
				$billing = isset( $row['billing_amount'] ) ? (float) $row['billing_amount'] : 0.0;
				$cycle = isset( $row['cycle_number'] ) ? (int) $row['cycle_number'] : 0;
				$is_one_time = ( $billing <= 0 && $cycle === 0 );

				if ( $is_one_time ) {
					$one_time_ids[] = $valid_id;
				} else {
					$recurring_ids[] = $valid_id;
				}
			}
		}

		return array(
			'valid_ids'     => array_values( array_unique( $valid_ids ) ),
			'one_time_ids'  => array_values( array_unique( $one_time_ids ) ),
			'recurring_ids' => array_values( array_unique( $recurring_ids ) ),
		);
	}

	/**
	 * Write complete ownership evidence after a successful P10 new insert.
	 *
	 * Sets managed/reverse markers, then type-specific course page or bundle
	 * group mapping. Does not repair existing levels, replace postmeta, or set pricing.
	 *
	 * @param int    $oid Course or bundle post ID.
	 * @param int    $lid Newly inserted PMPro level ID.
	 * @param string $pt  Post type (`courses` or `course-bundle`).
	 * @return void
	 */
	private function write_p10_new_insert_evidence( $oid, $lid, $pt ) {
		$oid = (int) $oid; $lid = (int) $lid;
		if ( $oid <= 0 || $lid <= 0 ) { return; }
		$this->write_one_time_ownership_markers( $oid, $lid, $pt );
		if ( 'course-bundle' === $pt ) { self::add_level_to_course_group( $oid, $lid, $pt ); return; }
		if ( ! class_exists( '\\TUTORPRESS_PMPRO\\PMPro_Association' ) ) {
			require_once $this->path . 'includes/utilities/class-pmpro-association.php';
		}
		\TUTORPRESS_PMPRO\PMPro_Association::ensure_course_level_association( $oid, $lid );
	}

	/**
	 * Auto-create a one-time PMPro level when selling_option is one_time.
	 *
	 * P10 discovery is listed-postmeta-only. Incomplete and both/others return
	 * without P10 mutation. Free and subscription use the typed applicator.
	 * One-time targets listed live recurring when price is positive.
	 *
	 * @param int    $object_id   The course or bundle ID.
	 * @param string $object_type 'course' or 'bundle'.
	 * @return void
	 */
	private function auto_create_one_time_level( $object_id, $object_type ) {
		// Only proceed if this is a course or bundle post.
		$post_type = get_post_type( $object_id );
		$valid_post_types = array( 'courses', 'course-bundle' );
		if ( ! in_array( $post_type, $valid_post_types ) ) {
			return;
		}

		// Only create/delete levels for published content. Drafts should not create PMPro levels yet.
		$post = get_post( $object_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return;
		}

		// Check selling_option value (use standard Tutor Core meta key)
		$selling_option = get_post_meta( $object_id, 'tutor_course_selling_option', true );
		// P10 listed-postmeta discovery. Free/subscription/both do not mutate here.
		// One-time uses the typed applicator when price is positive.
		if ( ! in_array( $selling_option, array( 'one_time', 'subscription', 'both' ), true ) ) {
			// Membership/other: fall through to discovery, then the no-deletion return.
		}

		// Listed-postmeta-only P10 discovery. Incomplete returns with no mutation.

		$disc = $this->discover_pmpro_levels( $object_id, 'p10' ); if ( ! empty( $disc['incomplete'] ) ) { return; }
		$valid_ids = array_values( array_merge( (array) $disc['one_time_ids'], (array) $disc['recurring_ids'] ) ); $one_time_ids = $disc['one_time_ids']; $recurring_ids = $disc['recurring_ids'];

		if ( empty( $valid_ids ) && ! empty( $disc['stale_ids'] ) ) {
			// Stale listed IDs remain in current meta for P20.
		}

		$price_type = get_post_meta( $object_id, '_tutor_course_price_type', true );
		$p10_state = array( 'stale_ids' => isset( $disc['stale_ids'] ) ? $disc['stale_ids'] : array() );
		if ( 'free' === $price_type ) { // listed live all via typed applicator; P20 still runs.
			$this->apply_typed_live_deletes( $object_id, $valid_ids, $p10_state ); return;
		}
		if ( 'subscription' === $selling_option ) { // listed live one-time via typed applicator; P20 still runs.
			$this->apply_typed_live_deletes( $object_id, $one_time_ids, $p10_state ); return;
		}

		if ( 'one_time' !== $selling_option ) {
			// Both/others: no P10 meta rewrite or deletion.
			return;
		}

		$regular_price = get_post_meta( $object_id, 'tutor_course_price', true );
		if ( empty( $regular_price ) || $regular_price <= 0 ) {
			return; // P10 no-deletion/no-upsert.
		}

		$out = $this->apply_typed_live_deletes( $object_id, $recurring_ids, $p10_state );
		$ok = true; $has = false;
		foreach ( $out as $row ) {
			if ( in_array( $row[1], array( 'ok', 'committed_with_warning' ), true ) ) { $has = true; }
			elseif ( ! in_array( $row[1], array( 'protected', 'ineligible', 'ownership_conflict' ), true ) ) { $ok = false; }
		}
		if ( ! $ok || ( $out && ! $has ) ) { return; }
		if ( $one_time_ids ) {
			$lid = (int) $one_time_ids[0];
			if ( 'allowed' === PMPro_Level_Deletion_Guard::evaluate( $lid, $object_id, $post_type ) ) {
				$this->update_one_time_survivor_level( $lid );
			}
		} else {
			$lid = $this->insert_one_time_level( $object_id );
			if ( $lid && $this->append_current_pmpro_level_meta( $object_id, $lid ) ) {
				$this->write_p10_new_insert_evidence( $object_id, $lid, $post_type );
			}
		}
	}

	/**
	 * Add a bulk action for manual reconciliation.
	 *
	 * @param array $bulk_actions The existing bulk actions.
	 * @return array
	 */
	public function add_reconcile_bulk_action( $bulk_actions ) {
		$bulk_actions['reconcile_pmpro_levels'] = __( 'Reconcile PMPro Levels', 'tutorpress-pmpro' );
		return $bulk_actions;
	}

	/**
	 * Handle the bulk action for manual reconciliation.
	 *
	 * @param string $redirect_to The redirect URL.
	 * @param string $action      The action being taken.
	 * @param array  $ids         The item IDs.
	 * @return string
	 */
	public function handle_reconcile_bulk_action( $redirect_to, $action, $ids ) {
		if ( $action !== 'reconcile_pmpro_levels' ) {
			return $redirect_to;
		}

		$course_ids = array_map( 'intval', $ids );
		foreach ( $course_ids as $course_id ) {
			// Schedule reconcile for each selected course
			if ( ! wp_next_scheduled( 'tp_pmpro_reconcile_course', array( $course_id ) ) ) {
				wp_schedule_single_event( time() + 1, 'tp_pmpro_reconcile_course', array( $course_id ) );
				$this->log( '[TP-PMPRO] Bulk reconcile scheduled for course=' . $course_id );
			}
		}

		$redirect_to = add_query_arg( 'reconcile_bulk_action', 'success', $redirect_to );
		return $redirect_to;
	}

	/**
	 * Log a message if TP_PMPRO_LOG is enabled.
	 *
	 * @param string $message The message to log.
	 * @return void
	 */
	private function log( $message ) {
		if ( defined( 'TP_PMPRO_LOG' ) && TP_PMPRO_LOG ) {
			error_log( $message );
		}
	}

	// ===========================
	// Phase 5: PMPro Level Groups
	// ===========================
	//
	// PMPro has native Level Groups (wp_pmpro_groups, wp_pmpro_membership_levels_groups).
	// We auto-create a group per course to organize its pricing levels.
	//
	// Note: PMPro does NOT have a "level type" field. Type (one-time vs recurring)
	// is inferred dynamically from billing configuration (billing_amount, cycle_number).
	// We do NOT add custom type meta - we use PMPro's native inference.

	/**
	 * Get or create a level group for a course or bundle.
	 *
	 * Creates a group named "Course: {Title}" or "Bundle: {Title}" with allow_multiple_selections = false
	 * so users can only select one pricing option per course/bundle.
	 *
	 * Also syncs the group name with the current title to handle renames.
	 *
	 * @since 1.6.0
	 * @since 1.7.0 Added bundle support
	 * @param int         $object_id The course or bundle ID
	 * @param string|null $post_type Optional. Post type ('courses' or 'course-bundle'). Auto-detected if not provided.
	 * @param string|null $title_override Optional. When the post is `auto-draft`, used as the title segment for the group name instead of DB title or placeholder.
	 * @return int|false The group ID, or false on failure
	 */
	public static function get_or_create_course_level_group( $object_id, $post_type = null, $title_override = null ) {
		if ( ! function_exists( 'pmpro_create_level_group' ) ) {
			if ( defined( 'TP_PMPRO_LOG' ) && TP_PMPRO_LOG ) {
				error_log( '[TP-PMPRO] get_or_create_course_level_group skipped (PMPro groups not available); object=' . $object_id );
			}
			return false;
		}

		// Auto-detect post type if not provided
		if ( null === $post_type ) {
			$post_type = get_post_type( $object_id );
		}

		// Validate post type
		if ( ! in_array( $post_type, array( 'courses', 'course-bundle' ), true ) ) {
			if ( defined( 'TP_PMPRO_LOG' ) && TP_PMPRO_LOG ) {
				error_log( '[TP-PMPRO] get_or_create_course_level_group skipped (invalid post_type: ' . $post_type . '); object=' . $object_id );
			}
			return false;
		}

		global $wpdb;
		
		// Multisite fix: Ensure PMPro groups table name is properly set
		// PMPro uses per-site tables, so we need $wpdb->prefix, not $wpdb->base_prefix
		$groups_table = $wpdb->prefix . 'pmpro_groups';
		$groups_levels_table = $wpdb->prefix . 'pmpro_membership_levels_groups';
		
		// Verify tables exist (PMPro may not have groups enabled)
		$table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $groups_table ) );
		if ( ! $table_exists ) {
			if ( defined( 'TP_PMPRO_LOG' ) && TP_PMPRO_LOG ) {
				error_log( '[TP-PMPRO] get_or_create_course_level_group skipped (groups table not found: ' . $groups_table . '); object=' . $object_id . ' blog_id=' . get_current_blog_id() );
			}
			return false;
		}

		// Title for group name: for auto-draft, prefer REST/editor override, else (Untitled); otherwise DB title.
		$post_status = get_post_status( $object_id );
		if ( 'auto-draft' === $post_status ) {
			if ( is_string( $title_override ) && '' !== trim( $title_override ) ) {
				$title = $title_override;
			} else {
				$title = __( '(Untitled)', 'tutorpress-pmpro' );
			}
		} else {
			$title = get_the_title( $object_id );
		}
		// Use appropriate prefix based on post type
		if ( 'course-bundle' === $post_type ) {
			$group_name = sprintf( __( 'Bundle: %s', 'tutorpress-pmpro' ), $title );
		} else {
			$group_name = sprintf( __( 'Course: %s', 'tutorpress-pmpro' ), $title );
		}

		// Check if group already exists (stored in post meta)
		$existing_group_id = absint( get_post_meta( $object_id, '_tutorpress_pmpro_group_id', true ) );
		if ( $existing_group_id ) {
			if ( function_exists( 'pmpro_get_level_group' ) ) {
				$group = pmpro_get_level_group( $existing_group_id );
				if ( $group ) {
					if ( defined( 'TP_PMPRO_LOG' ) && TP_PMPRO_LOG ) {
						$object_label = ( 'course-bundle' === $post_type ) ? 'bundle' : 'course';
						error_log( '[TP-PMPRO] get_or_create_course_level_group found_existing_group=' . $existing_group_id . ' ' . $object_label . '=' . $object_id );
					}
					return (int) $existing_group_id;
				}
			}

			if ( defined( 'TP_PMPRO_LOG' ) && TP_PMPRO_LOG ) {
				$object_label = ( 'course-bundle' === $post_type ) ? 'bundle' : 'course';
				error_log( '[TP-PMPRO] get_or_create_course_level_group stale_group_id=' . $existing_group_id . ' ' . $object_label . '=' . $object_id . ' blog_id=' . get_current_blog_id() );
			}
			return false;
		}

		// Create new group
		// allow_multiple_selections = false: users can only pick ONE pricing option per course/bundle
		$group_id = pmpro_create_level_group( $group_name, false );

		if ( $group_id ) {
			update_post_meta( $object_id, '_tutorpress_pmpro_group_id', $group_id );
			if ( defined( 'TP_PMPRO_LOG' ) && TP_PMPRO_LOG ) {
				$object_label = ( 'course-bundle' === $post_type ) ? 'bundle' : 'course';
				error_log( '[TP-PMPRO] get_or_create_course_level_group created_group=' . $group_id . ' name="' . $group_name . '" ' . $object_label . '=' . $object_id . ' blog_id=' . get_current_blog_id() );
			}
			return (int) $group_id;
		}

		if ( defined( 'TP_PMPRO_LOG' ) && TP_PMPRO_LOG ) {
			$object_label = ( 'course-bundle' === $post_type ) ? 'bundle' : 'course';
			error_log( '[TP-PMPRO] get_or_create_course_level_group failed to create group; ' . $object_label . '=' . $object_id . ' blog_id=' . get_current_blog_id() );
		}
		return false;
	}

	/**
	 * Add a membership level to the course's or bundle's level group.
	 *
	 * @since 1.6.0
	 * @since 1.7.0 Added bundle support
	 * @param int         $object_id The course or bundle ID
	 * @param int         $level_id The level ID
	 * @param string|null $post_type Optional. Post type ('courses' or 'course-bundle'). Auto-detected if not provided.
	 * @param string|null $title_override Optional. Passed to get_or_create_course_level_group when the post may be `auto-draft`.
	 * @return bool True if added successfully, false otherwise
	 */
	public static function add_level_to_course_group( $object_id, $level_id, $post_type = null, $title_override = null ) {
		if ( ! function_exists( 'pmpro_add_level_to_group' ) ) {
			if ( defined( 'TP_PMPRO_LOG' ) && TP_PMPRO_LOG ) {
				$object_label = ( null !== $post_type && 'course-bundle' === $post_type ) ? 'bundle' : 'course';
				error_log( '[TP-PMPRO] add_level_to_course_group skipped (pmpro_add_level_to_group not available); level=' . $level_id . ' ' . $object_label . '=' . $object_id );
			}
			return false;
		}

		// Auto-detect post type if not provided
		if ( null === $post_type ) {
			$post_type = get_post_type( $object_id );
		}

		$group_id = self::get_or_create_course_level_group( $object_id, $post_type, $title_override );
		if ( ! $group_id ) {
			if ( defined( 'TP_PMPRO_LOG' ) && TP_PMPRO_LOG ) {
				$object_label = ( 'course-bundle' === $post_type ) ? 'bundle' : 'course';
				error_log( '[TP-PMPRO] add_level_to_course_group failed (no group_id); level=' . $level_id . ' ' . $object_label . '=' . $object_id . ' blog_id=' . get_current_blog_id() );
		}
			return false;
		}

		// Check if level is already in a group (PMPro doesn't allow levels in multiple groups)
		global $wpdb;
		$groups_levels_table = $wpdb->prefix . 'pmpro_membership_levels_groups';
		$existing_group = $wpdb->get_var( $wpdb->prepare(
			"SELECT `group` FROM {$groups_levels_table} WHERE level = %d LIMIT 1",
			$level_id
		) );
		
		$object_label = ( 'course-bundle' === $post_type ) ? 'bundle' : 'course';
		
		if ( $existing_group && (int) $existing_group === (int) $group_id ) {
			// Already in the correct group
			if ( defined( 'TP_PMPRO_LOG' ) && TP_PMPRO_LOG ) {
				error_log( '[TP-PMPRO] add_level_to_course_group already in group; level=' . $level_id . ' group=' . $group_id . ' ' . $object_label . '=' . $object_id );
		}
			return true;
		}
		
		// Add level to group using PMPro's function
		$result = pmpro_add_level_to_group( $level_id, $group_id );
		
		// If PMPro function failed, try direct database insert as fallback
		if ( ! $result ) {
			// Verify the group exists first
			$groups_table = $wpdb->prefix . 'pmpro_groups';
			$group_exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$groups_table} WHERE id = %d",
				$group_id
			) );
			
			if ( $group_exists ) {
				// Direct insert as fallback
				$inserted = $wpdb->insert(
					$groups_levels_table,
					array(
						'group' => $group_id,
						'level' => $level_id,
					),
					array( '%d', '%d' )
				);
				
				if ( $inserted ) {
					$result = true;
					if ( defined( 'TP_PMPRO_LOG' ) && TP_PMPRO_LOG ) {
						error_log( '[TP-PMPRO] add_level_to_course_group SUCCESS (fallback direct insert); level=' . $level_id . ' group=' . $group_id . ' ' . $object_label . '=' . $object_id . ' blog_id=' . get_current_blog_id() );
					}
				} else {
					if ( defined( 'TP_PMPRO_LOG' ) && TP_PMPRO_LOG ) {
						$wpdb_error = $wpdb->last_error ? $wpdb->last_error : 'none';
						error_log( '[TP-PMPRO] add_level_to_course_group FAILED (direct insert also failed); level=' . $level_id . ' group=' . $group_id . ' ' . $object_label . '=' . $object_id . ' blog_id=' . get_current_blog_id() . ' wpdb_error=' . $wpdb_error );
					}
				}
			} else {
				if ( defined( 'TP_PMPRO_LOG' ) && TP_PMPRO_LOG ) {
					error_log( '[TP-PMPRO] add_level_to_course_group FAILED (group does not exist); level=' . $level_id . ' group=' . $group_id . ' ' . $object_label . '=' . $object_id . ' blog_id=' . get_current_blog_id() );
				}
			}
		} else {
			if ( defined( 'TP_PMPRO_LOG' ) && TP_PMPRO_LOG ) {
				error_log( '[TP-PMPRO] add_level_to_course_group SUCCESS; level=' . $level_id . ' group=' . $group_id . ' ' . $object_label . '=' . $object_id . ' blog_id=' . get_current_blog_id() );
			}
		}
		
		return (bool) $result;
	}


	/**
	 * Delete the level group for a course or bundle if it's empty.
	 *
	 * Called when a course/bundle is deleted or has no more levels.
	 *
	 * @since 1.6.0
	 * @since 1.7.0 Added bundle support
	 * @param int $object_id The course or bundle ID
	 * @return void
	 */
	public static function delete_course_level_group_if_empty( $object_id ) {
		if ( ! function_exists( 'pmpro_delete_level_group' ) || ! function_exists( 'pmpro_get_level_ids_for_group' ) ) {
			return;
		}

		$group_id = get_post_meta( $object_id, '_tutorpress_pmpro_group_id', true );
		if ( ! $group_id ) {
			return;
		}

		$post_type = get_post_type( $object_id );
		$object_label = ( 'course-bundle' === $post_type ) ? 'bundle' : 'course';
		$group_exists = function_exists( 'pmpro_get_level_group' ) ? pmpro_get_level_group( $group_id ) : true;

		if ( ! $group_exists ) {
			delete_post_meta( $object_id, '_tutorpress_pmpro_group_id' );
			if ( defined( 'TP_PMPRO_LOG' ) && TP_PMPRO_LOG ) {
				error_log( '[TP-PMPRO] delete_course_level_group_if_empty cleared_stale_group_meta=' . $group_id . ' ' . $object_label . '=' . $object_id . ' blog_id=' . get_current_blog_id() );
			}
			return;
		}

		$levels_in_group = pmpro_get_level_ids_for_group( $group_id );
		if ( empty( $levels_in_group ) ) {
			$deleted = pmpro_delete_level_group( $group_id );
			$group_still_exists = function_exists( 'pmpro_get_level_group' ) ? pmpro_get_level_group( $group_id ) : ! $deleted;
			if ( $deleted || ! $group_still_exists ) {
				delete_post_meta( $object_id, '_tutorpress_pmpro_group_id' );
				if ( defined( 'TP_PMPRO_LOG' ) && TP_PMPRO_LOG ) {
					$log_action = $deleted ? 'deleted_group=' : 'cleared_deleted_group_meta=';
					error_log( '[TP-PMPRO] delete_course_level_group_if_empty ' . $log_action . $group_id . ' ' . $object_label . '=' . $object_id . ' blog_id=' . get_current_blog_id() );
				}
			} else {
				if ( defined( 'TP_PMPRO_LOG' ) && TP_PMPRO_LOG ) {
					error_log( '[TP-PMPRO] delete_course_level_group_if_empty failed to delete group=' . $group_id . ' ' . $object_label . '=' . $object_id . ' blog_id=' . get_current_blog_id() );
				}
			}
		} else {
			if ( defined( 'TP_PMPRO_LOG' ) && TP_PMPRO_LOG ) {
				error_log( '[TP-PMPRO] delete_course_level_group_if_empty group_not_empty=' . $group_id . ' level_count=' . count( $levels_in_group ) . ' ' . $object_label . '=' . $object_id . ' blog_id=' . get_current_blog_id() );
			}
		}
	}

	/**
	 * Provide stub WooCommerce functions for Tutor LMS bundle code.
	 * 
	 * Tutor LMS's BundleModel calls various WooCommerce functions when monetization
	 * is not "tutor". When PMPro is active and WooCommerce is not installed, this
	 * causes fatal errors. We provide stub functions to prevent this.
	 * 
	 * This must be called early (in constructor) before Tutor LMS bundle code runs.
	 * 
	 * Note: We use eval() to define functions in the global namespace because:
	 * 1. We're inside the TUTORPRESS_PMPRO namespace
	 * 2. We can't use `namespace {}` blocks inside a method
	 * 3. Tutor LMS calls these functions from within its own namespace, so they must
	 *    be in the global namespace for PHP's namespace fallback to work
	 * 
	 * This is safe because we're only defining simple stub functions with no user input.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function provide_woocommerce_stubs() {
		// Only provide stubs if WooCommerce is not active
		// Check function/class existence first (fast path if WooCommerce already loaded)
		if ( function_exists( '\wc_get_product' ) || class_exists( 'WooCommerce' ) ) {
			return;
		}
		
		// Check if WooCommerce is in active plugins list (handles load order race condition)
		// This is necessary because our plugin may load before WooCommerce alphabetically
		$active_plugins = (array) get_option( 'active_plugins', array() );
		foreach ( $active_plugins as $plugin ) {
			if ( strpos( $plugin, 'woocommerce.php' ) !== false ) {
				// WooCommerce is active but hasn't loaded yet - don't provide stubs
				if ( defined( 'TP_PMPRO_LOG' ) && TP_PMPRO_LOG ) {
					error_log( '[TP-PMPRO] WooCommerce detected in active plugins, skipping stub functions' );
				}
				return;
			}
		}
		
		// Also check multisite network-activated plugins
		if ( is_multisite() ) {
			$network_plugins = (array) get_site_option( 'active_sitewide_plugins', array() );
			foreach ( array_keys( $network_plugins ) as $plugin ) {
				if ( strpos( $plugin, 'woocommerce.php' ) !== false ) {
					if ( defined( 'TP_PMPRO_LOG' ) && TP_PMPRO_LOG ) {
						error_log( '[TP-PMPRO] WooCommerce detected in network plugins, skipping stub functions' );
					}
					return;
				}
			}
		}
		
		// Define stub functions in global namespace using eval()
		// Array format makes it easy to add more stubs in the future
		$stubs = array(
			'wc_get_product'                    => 'function wc_get_product( $product_id ) { return false; }',
			'get_woocommerce_currency_symbol'  => 'function get_woocommerce_currency_symbol() { return ""; }',
		);
		
		foreach ( $stubs as $function_name => $function_code ) {
			if ( ! function_exists( '\\' . $function_name ) ) {
				eval( 'namespace { ' . $function_code . ' }' );
			}
		}
		
		if ( defined( 'TP_PMPRO_LOG' ) && TP_PMPRO_LOG ) {
			error_log( '[TP-PMPRO] Provided WooCommerce stub functions: ' . implode( ', ', array_keys( $stubs ) ) );
		}
	}

}
