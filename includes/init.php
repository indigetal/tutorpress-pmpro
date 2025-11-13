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
		add_action( 'rest_after_insert_courses', array( $this, 'auto_create_one_time_level_for_course' ), 10, 3 );
		add_action( 'rest_after_insert_course-bundle', array( $this, 'auto_create_one_time_level_for_bundle' ), 10, 3 );

		// Reconcile hooks (scaffolding): REST, classic save, status transition, and scheduled action
		add_action( 'rest_after_insert_courses', array( $this, 'reconcile_course_levels_rest' ), 20, 3 );
		add_action( 'save_post_courses', array( $this, 'schedule_reconcile_course_levels' ), 999, 3 );
		add_action( 'transition_post_status', array( $this, 'maybe_reconcile_on_status' ), 20, 3 );
		add_action( 'tp_pmpro_reconcile_course', array( $this, 'reconcile_course_levels' ), 10, 1 );
		add_action( 'before_delete_post', array( $this, 'delete_course_levels_on_delete' ), 10, 1 );

		// Admin on-demand action for manual reconciliation
		add_filter( 'bulk_actions-edit-courses', array( $this, 'add_reconcile_bulk_action' ) );
		add_action( 'handle_bulk_actions-edit-courses', array( $this, 'handle_reconcile_bulk_action' ), 10, 3 );
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
		// Guard: only proceed for published courses
		if ( 'publish' !== get_post_status( $course_id ) ) {
			$this->log( $error_prefix . ' skipped (not published); course=' . $course_id );
			return;
		}

		// Extract context from REST request
		$so_keys = array( 'selling_option', 'tutor_course_selling_option' );
		$pt_keys = array( 'price_type', 'tutor_course_price_type', '_tutor_course_price_type' );
		$price_keys = array( 'price', 'tutor_course_price' );
		$so = null; $pt = null; $price = null;
		if ( method_exists( $request, 'get_param' ) ) {
			foreach ( $so_keys as $k ) { if ( null === $so ) { $so = $request->get_param( $k ); } }
			foreach ( $pt_keys as $k ) { if ( null === $pt ) { $pt = $request->get_param( $k ); } }
			foreach ( $price_keys as $k ) { if ( null === $price ) { $price = $request->get_param( $k ); } }
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

		$this->log( $error_prefix . ' context extracted; course=' . $course_id . ' creating=' . ( $creating ? '1' : '0' ) . ' selling_option=' . ( $so ? $so : 'n/a' ) . ' price_type=' . ( $pt ? $pt : 'n/a' ) . ' price=' . ( null !== $price ? $price : 'n/a' ) );

		// Schedule reconcile shortly after REST save to ensure all meta is persisted
		if ( ! wp_next_scheduled( 'tp_pmpro_reconcile_course', array( $course_id ) ) ) {
			wp_schedule_single_event( time() + 1, 'tp_pmpro_reconcile_course', array( $course_id ) );
			$this->log( $error_prefix . ' scheduled reconcile in 1s; course=' . $course_id );
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
		// Guard: only schedule reconcile for published courses
		if ( 'publish' !== get_post_status( $post_id ) ) {
			$this->log( '[TP-PMPRO] schedule_reconcile_course_levels skipped (not published); course=' . (int) $post_id );
			return;
		}
		$this->log( '[TP-PMPRO] schedule_reconcile_course_levels fired; course=' . (int) $post_id . ' update=' . ( $update ? '1' : '0' ) );
		if ( ! wp_next_scheduled( 'tp_pmpro_reconcile_course', array( (int) $post_id ) ) ) {
			wp_schedule_single_event( time() + 1, 'tp_pmpro_reconcile_course', array( (int) $post_id ) );
			$this->log( '[TP-PMPRO] scheduled reconcile in 1s; course=' . (int) $post_id );
		}
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
			$this->log( '[TP-PMPRO] maybe_reconcile_on_status fired; course=' . (int) $post->ID . ' new=publish old=' . $old_status );
			if ( ! wp_next_scheduled( 'tp_pmpro_reconcile_course', array( (int) $post->ID ) ) ) {
				wp_schedule_single_event( time() + 1, 'tp_pmpro_reconcile_course', array( (int) $post->ID ) );
				$this->log( '[TP-PMPRO] maybe_reconcile_on_status scheduled reconcile in 1s; course=' . (int) $post->ID );
			}
		}
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
	 * Scheduled reconcile handler (scaffold w/ logs).
	 *
	 * @param int   $course_id
	 * @param array $ctx Optional context
	 * @return void
	 */
	public function reconcile_course_levels( $course_id, $ctx = array() ) {
		$course_id = (int) $course_id;
		// Guard: only reconcile for published courses
		if ( 'publish' !== get_post_status( $course_id ) ) {
			$this->log( '[TP-PMPRO] reconcile_course_levels skipped (not published); course=' . $course_id );
			return;
		}
		$src = is_array( $ctx ) && isset( $ctx['source'] ) ? $ctx['source'] : 'scheduled';
		$this->log( '[TP-PMPRO] reconcile_course_levels fired; course=' . $course_id . ' source=' . $src );

		// Step 0: Acquire short-lived lock to prevent concurrent double-runs
		$lock_key = 'tp_pmpro_lock_' . $course_id;
		if ( get_transient( $lock_key ) ) {
			$this->log( '[TP-PMPRO] reconcile_course_levels skipped (already running); course=' . $course_id );
			return;
		}
		set_transient( $lock_key, 1, 5 ); // 5-second lock expiry
		$this->log( '[TP-PMPRO] reconcile_course_levels acquired_lock; course=' . $course_id );

		try {
			// Consume any pending plans saved while in draft
			$pending = get_post_meta( $course_id, '_tutorpress_pmpro_pending_plans', true );
			if ( is_array( $pending ) && ! empty( $pending ) ) {
				$this->log( '[TP-PMPRO] reconcile_course_levels pending_count=' . count( $pending ) . ' course=' . $course_id );
				foreach ( $pending as $plan_params ) {
					try {
						// Map to PMPro level data and create level now that we're published
						require_once $this->path . 'includes/utilities/class-pmpro-mapper.php';
						$mapper = new \TutorPress_PMPro_Mapper();
						$level_data = $mapper->map_ui_to_pmpro( (array) $plan_params );
						$db_data = $level_data;
						if ( isset( $db_data['meta'] ) ) { unset( $db_data['meta'] ); }
						// Normalize recurring vs one_time if provided
						if ( isset( $level_data['payment_type'] ) && 'one_time' === $level_data['payment_type'] ) {
							$db_data['billing_amount'] = 0; $db_data['cycle_number'] = 0; $db_data['cycle_period'] = ''; $db_data['billing_limit'] = 0;
						}
						global $wpdb;
						$wpdb->insert( $wpdb->pmpro_membership_levels, $db_data );
						$level_id = (int) $wpdb->insert_id;
						if ( $level_id > 0 ) {
							// ✅ KEY INSIGHT: Always set reverse ownership meta
							if ( function_exists( 'update_pmpro_membership_level_meta' ) ) {
								update_pmpro_membership_level_meta( $level_id, 'tutorpress_course_id', $course_id );
								update_pmpro_membership_level_meta( $level_id, 'tutorpress_managed', 1 );
							}
							// ✅ KEY INSIGHT: Always ensure association row exists
							if ( ! class_exists( '\\TUTORPRESS_PMPRO\\PMPro_Association' ) ) {
								require_once $this->path . 'includes/utilities/class-pmpro-association.php';
							}
							\TUTORPRESS_PMPRO\PMPro_Association::ensure_course_level_association( $course_id, $level_id );
							
							// Update course meta list
							$existing = get_post_meta( $course_id, '_tutorpress_pmpro_levels', true );
							if ( ! is_array( $existing ) ) { $existing = array(); }
							$existing[] = $level_id;
							update_post_meta( $course_id, '_tutorpress_pmpro_levels', array_values( array_unique( array_map( 'intval', $existing ) ) ) );
							$this->log( '[TP-PMPRO] reconcile_course_levels created_level_id=' . $level_id . ' owner=' . $course_id . ' assoc=ensured course=' . $course_id );
						}
					} catch ( \Exception $e ) {
						$this->log( '[TP-PMPRO] reconcile_course_levels error creating pending plan: ' . $e->getMessage() . ' course=' . $course_id );
					}
				}
				// Clear pending
				delete_post_meta( $course_id, '_tutorpress_pmpro_pending_plans' );
			}

		// Step 2: Association discovery and context extraction
		$state = $this->get_course_pmpro_state( $course_id, array( 'source' => $src ) );
		
		// Read course pricing context from post meta (use standard Tutor Core meta keys)
		$selling_option = get_post_meta( $course_id, 'tutor_course_selling_option', true );
		$price_type = get_post_meta( $course_id, '_tutor_course_price_type', true );
		$price = get_post_meta( $course_id, 'tutor_course_price', true );
			
		$this->log( '[TP-PMPRO] reconcile_course_levels context; course=' . $course_id . ' selling_option=' . ( $selling_option ? $selling_option : 'n/a' ) . ' price_type=' . ( $price_type ? $price_type : 'n/a' ) . ' price=' . ( $price ? $price : 'n/a' ) . ' valid_levels=' . count( $state['valid_ids'] ) . ' one_time=' . count( $state['one_time_ids'] ) . ' recurring=' . count( $state['recurring_ids'] ) );

		// Branch handling: free / membership / subscription / one_time / both / all
		if ( 'free' === $price_type ) {
			$this->handle_free_branch( $course_id, $state );
			return;
		}
		if ( 'membership' === $selling_option ) {
			$this->handle_membership_branch( $course_id, $state );
			return;
		}
		if ( 'subscription' === $selling_option ) {
			$this->handle_subscription_branch( $course_id, $state );
			return;
		}
		if ( 'one_time' === $selling_option ) {
			$this->handle_one_time_branch( $course_id, $state );
			return;
		}
		if ( 'both' === $selling_option || 'all' === $selling_option ) {
			$this->handle_both_and_all_branch( $course_id, $state );
			return;
		}
		// Default: ensure meta matches valid IDs (fallback for undefined selling options)
		if ( ! empty( $state['valid_ids'] ) ) {
			update_post_meta( $course_id, '_tutorpress_pmpro_levels', $state['valid_ids'] );
		}
		} finally {
			// Always cleanup lock, even if exception or early return
			delete_transient( $lock_key );
			$this->log( '[TP-PMPRO] reconcile_course_levels released_lock; course=' . $course_id );
		}
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
		
		if ( ! empty( $ids ) ) {
			if ( ! class_exists( '\\TUTORPRESS_PMPRO\\PMPro_Level_Cleanup' ) ) {
				require_once $this->path . 'includes/utilities/class-pmpro-level-cleanup.php';
			}
			foreach ( $ids as $lid ) {
				\TUTORPRESS_PMPRO\PMPro_Level_Cleanup::full_delete_level( (int) $lid, true );
				$this->log( '[TP-PMPRO] handle_free_branch deleted_level_id=' . (int) $lid . ' course=' . $course_id );
			}
			$this->log( '[TP-PMPRO] handle_free_branch cleared all levels; course=' . $course_id . ' deleted_count=' . count( $ids ) );
		}
		
		delete_post_meta( $course_id, '_tutorpress_pmpro_levels' );
		
		// Phase 5: Delete course level group (now empty or already empty)
		self::delete_course_level_group_if_empty( $course_id );
	}

	/**
	 * Handle Subscription-only branch: delete one-time levels, keep recurring.
	 *
	 * @param int $course_id
	 * @param array $state
	 * @return void
	 */
	private function handle_subscription_branch( $course_id, $state = array() ) {
		$course_id = (int) $course_id;
		$one_time = isset( $state['one_time_ids'] ) ? (array) $state['one_time_ids'] : array();
		$recurring = isset( $state['recurring_ids'] ) ? (array) $state['recurring_ids'] : array();
		if ( empty( $one_time ) ) {
			// Nothing to delete; ensure meta only contains recurring ids
			update_post_meta( $course_id, '_tutorpress_pmpro_levels', array_values( array_map( 'intval', $recurring ) ) );
			return;
		}
		if ( ! class_exists( '\\TUTORPRESS_PMPRO\\PMPro_Level_Cleanup' ) ) {
			require_once $this->path . 'includes/utilities/class-pmpro-level-cleanup.php';
		}
		foreach ( $one_time as $lid ) {
			\TUTORPRESS_PMPRO\PMPro_Level_Cleanup::full_delete_level( (int) $lid, true );
			$this->log( '[TP-PMPRO] handle_subscription_branch deleted_one_time_level_id=' . (int) $lid . ' course=' . $course_id );
		}
		// Persist remaining recurring IDs
		update_post_meta( $course_id, '_tutorpress_pmpro_levels', array_values( array_map( 'intval', $recurring ) ) );
		$this->log( '[TP-PMPRO] handle_subscription_branch updated_course_levels; course=' . $course_id . ' recurring_count=' . count( $recurring ) );
	}

	/**
	 * Handle One-time-only branch: keep one-time levels, remove recurring levels.
	 * If multiple one-time levels exist, prefer the first and keep all one-time ids by default.
	 *
	 * @param int $course_id
	 * @param array $state
	 * @return void
	 */
	private function handle_one_time_branch( $course_id, $state = array() ) {
		$course_id = (int) $course_id;
		$one_time = isset( $state['one_time_ids'] ) ? (array) $state['one_time_ids'] : array();
		$recurring = isset( $state['recurring_ids'] ) ? (array) $state['recurring_ids'] : array();

		// Step 1: Delete all recurring levels
		if ( ! empty( $recurring ) ) {
			if ( ! class_exists( '\\TUTORPRESS_PMPRO\\PMPro_Level_Cleanup' ) ) {
				require_once $this->path . 'includes/utilities/class-pmpro-level-cleanup.php';
			}
			foreach ( $recurring as $rid ) {
				\TUTORPRESS_PMPRO\PMPro_Level_Cleanup::full_delete_level( (int) $rid, true );
				$this->log( '[TP-PMPRO] handle_one_time_branch deleted_recurring_level_id=' . (int) $rid . ' course=' . $course_id );
			}
		}

		// Step 2: Ensure exactly one one-time level exists (upsert logic)
		$level_id = 0;
		$regular_price = get_post_meta( $course_id, 'tutor_course_price', true );
		$regular_price = ! empty( $regular_price ) ? floatval( $regular_price ) : 0.0;

		if ( ! empty( $one_time ) ) {
			// One-time level(s) exist: update the first one
			// NOTE: initial_payment is handled by handle_sale_price_for_one_time() to support sales
			$level_id = (int) $one_time[0];
			global $wpdb;
			$update_data = array(
				'name'            => get_the_title( $course_id ) . ' (One-time)',
				'description'     => get_post_field( 'post_excerpt', $course_id ) ?: '',
				'billing_amount'  => 0,
				'cycle_number'    => 0,
				'cycle_period'    => '',
				'billing_limit'   => 0,
			);
			$wpdb->update( $wpdb->pmpro_membership_levels, $update_data, array( 'id' => $level_id ), array( '%s', '%s', '%f', '%d', '%s', '%d' ), array( '%d' ) );
			$this->log( '[TP-PMPRO] handle_one_time_branch updated_level_id=' . $level_id . ' course=' . $course_id . ' (initial_payment handled by sale_price logic)' );
		} else {
			// No one-time level exists: create one
			// NOTE: initial_payment is handled by handle_sale_price_for_one_time() to support sales
			if ( $regular_price > 0 ) {
				global $wpdb;
				$insert_data = array(
					'name'            => get_the_title( $course_id ) . ' (One-time)',
					'description'     => get_post_field( 'post_excerpt', $course_id ) ?: '',
					'initial_payment' => 0,  // Temporary, will be set by handle_sale_price_for_one_time()
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
					$this->log( '[TP-PMPRO] handle_one_time_branch created_level_id=' . $level_id . ' course=' . $course_id . ' (initial_payment will be set by sale_price logic)' );
				}
			} else {
				$this->log( '[TP-PMPRO] handle_one_time_branch no_price_set; course=' . $course_id );
				// No price set and no existing level; just clear meta
				delete_post_meta( $course_id, '_tutorpress_pmpro_levels' );
				return;
			}
		}

		// Step 3: Set reverse ownership meta and ensure association
		if ( $level_id > 0 ) {
			if ( function_exists( 'update_pmpro_membership_level_meta' ) ) {
				update_pmpro_membership_level_meta( $level_id, 'tutorpress_course_id', $course_id );
				update_pmpro_membership_level_meta( $level_id, 'tutorpress_managed', 1 );
				$this->log( '[TP-PMPRO] handle_one_time_branch set_reverse_meta level_id=' . $level_id . ' course=' . $course_id );
			}
			if ( ! class_exists( '\\TUTORPRESS_PMPRO\\PMPro_Association' ) ) {
				require_once $this->path . 'includes/utilities/class-pmpro-association.php';
			}
			\TUTORPRESS_PMPRO\PMPro_Association::ensure_course_level_association( $course_id, $level_id );
			$this->log( '[TP-PMPRO] handle_one_time_branch ensured_association level_id=' . $level_id . ' course=' . $course_id );
			
			// Phase 5: Add level to course group
			self::add_level_to_course_group( $course_id, $level_id );
			
			// Step 3.5: Handle sale price for one-time purchase
			$this->handle_sale_price_for_one_time( $course_id, $level_id, $regular_price );
		}

		// Step 4: Update course meta with final level ID(s)
		if ( $level_id > 0 ) {
			update_post_meta( $course_id, '_tutorpress_pmpro_levels', array( $level_id ) );
			$this->log( '[TP-PMPRO] handle_one_time_branch final_meta_update; course=' . $course_id . ' level_id=' . $level_id );
		} else {
			delete_post_meta( $course_id, '_tutorpress_pmpro_levels' );
			$this->log( '[TP-PMPRO] handle_one_time_branch cleared_meta; course=' . $course_id );
		}
	}

	/**
	 * Handle sale price storage for one-time purchase levels.
	 * 
	 * Implements PMPro sale price pattern:
	 * 1. When sale active: level.initial_payment = sale_price, store regular_price in meta
	 * 2. When no sale: level.initial_payment = regular_price, clear sale meta
	 * 
	 * This ensures PMPro charges the sale price while maintaining both prices for display.
	 *
	 * @since 1.5.0
	 * @param int   $course_id      Course ID
	 * @param int   $level_id       PMPro level ID
	 * @param float $regular_price  Regular price for validation
	 * @return void
	 */
	private function handle_sale_price_for_one_time( $course_id, $level_id, $regular_price ) {
		if ( ! function_exists( 'update_pmpro_membership_level_meta' ) || ! function_exists( 'delete_pmpro_membership_level_meta' ) ) {
			return;
		}

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
		update_pmpro_membership_level_meta( $level_id, 'tutorpress_regular_price', $regular_price );

		// Read sale price from course meta
		$sale_price = get_post_meta( $course_id, 'tutor_course_sale_price', true );
		$sale_price = ! empty( $sale_price ) ? floatval( $sale_price ) : 0.0;

		// Validate sale price
		$is_valid_sale = ( $sale_price > 0 && $sale_price < $regular_price );

		if ( $is_valid_sale ) {
			// Store sale price in meta (for runtime filters to use)
			update_pmpro_membership_level_meta( $level_id, 'tutorpress_sale_price', $sale_price );
			
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[TP-PMPRO] stored_sale_price_one_time level_id=' . $level_id . ' course=' . $course_id . ' sale_price=' . $sale_price . ' regular_price=' . $regular_price );
			}
		} else {
			// Sale price removed or invalid - clean up sale meta
			delete_pmpro_membership_level_meta( $level_id, 'tutorpress_sale_price' );
			
			if ( $sale_price > 0 ) {
				// Log validation failure for debugging
				$this->log( '[TP-PMPRO] invalid_sale_price level_id=' . $level_id . ' course=' . $course_id . ' sale_price=' . $sale_price . ' regular_price=' . $regular_price . ' reason=sale_must_be_less_than_regular' );
			} else {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[TP-PMPRO] cleared_sale_price_one_time level_id=' . $level_id . ' course=' . $course_id );
				}
			}
		}
		
		// Note: Runtime calculation happens in PaidMembershipsPro::get_active_price_for_level() and filter hooks (Step 3.4c)
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
		
		if ( ! empty( $ids ) ) {
			if ( ! class_exists( '\\TUTORPRESS_PMPRO\\PMPro_Level_Cleanup' ) ) {
				require_once $this->path . 'includes/utilities/class-pmpro-level-cleanup.php';
			}
			foreach ( $ids as $lid ) {
				\TUTORPRESS_PMPRO\PMPro_Level_Cleanup::full_delete_level( (int) $lid, true );
				$this->log( '[TP-PMPRO] handle_membership_branch deleted_level_id=' . (int) $lid . ' course=' . $course_id );
			}
			$this->log( '[TP-PMPRO] handle_membership_branch cleared all levels; course=' . $course_id . ' deleted_count=' . count( $ids ) );
		}
		
		delete_post_meta( $course_id, '_tutorpress_pmpro_levels' );
		
		// Phase 5: Delete course level group (now empty or already empty)
		self::delete_course_level_group_if_empty( $course_id );
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
	 * @param int   $course_id
	 * @param array $state
	 * @return void
	 */
	private function handle_both_and_all_branch( $course_id, $state = array() ) {
		$course_id = (int) $course_id;
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
						update_pmpro_membership_level_meta( $level_id, 'tutorpress_course_id', $course_id );
						update_pmpro_membership_level_meta( $level_id, 'tutorpress_managed', 1 );
					}
					// Ensure association
					if ( ! class_exists( '\\TUTORPRESS_PMPRO\\PMPro_Association' ) ) {
						require_once $this->path . 'includes/utilities/class-pmpro-association.php';
					}
					\TUTORPRESS_PMPRO\PMPro_Association::ensure_course_level_association( $course_id, $level_id );
					
					// Phase 5: Add level to course group
					self::add_level_to_course_group( $course_id, $level_id );
					
					// Handle sale price for the newly created one-time level
					$this->handle_sale_price_for_one_time( $course_id, $level_id, $regular_price );
					
					$one_time[] = $level_id;
					$this->log( '[TP-PMPRO] handle_both_and_all_branch created_one_time_level_id=' . $level_id . ' course=' . $course_id . ' price=' . $regular_price );
				}
			} else {
				$this->log( '[TP-PMPRO] handle_both_and_all_branch skipped_one_time_creation (no price set); course=' . $course_id );
			}
		} else {
			// One-time level already exists - update it with current price and sale
			$existing_level_id = (int) $one_time[0];
			$regular_price = get_post_meta( $course_id, 'tutor_course_price', true );
			$regular_price = ! empty( $regular_price ) ? floatval( $regular_price ) : 0.0;
			
			if ( $regular_price > 0 ) {
				// Update the level's title and description
				global $wpdb;
				$wpdb->update(
					$wpdb->pmpro_membership_levels,
					array(
						'name'        => get_the_title( $course_id ) . ' (One-time)',
						'description' => get_post_field( 'post_excerpt', $course_id ) ?: '',
					),
					array( 'id' => $existing_level_id ),
					array( '%s', '%s' ),
					array( '%d' )
				);
				
				// Phase 5: Ensure level is in course group
				self::add_level_to_course_group( $course_id, $existing_level_id );
				
				// Handle sale price (which will also update initial_payment)
				$this->handle_sale_price_for_one_time( $course_id, $existing_level_id, $regular_price );
			}
			
			$this->log( '[TP-PMPRO] handle_both_and_all_branch one_time_level_exists; course=' . $course_id . ' level_id=' . $existing_level_id );
		}
		
		// Update meta with all valid IDs (one-time + recurring)
		$all_ids = array_values( array_unique( array_map( 'intval', array_merge( $one_time, $recurring ) ) ) );
		if ( ! empty( $all_ids ) ) {
			update_post_meta( $course_id, '_tutorpress_pmpro_levels', $all_ids );
		}
		$this->log( '[TP-PMPRO] handle_both_and_all_branch updated_meta; course=' . $course_id . ' one_time_count=' . count( $one_time ) . ' recurring_count=' . count( $recurring ) . ' total=' . count( $all_ids ) );
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
	 * Auto-create a one-time PMPro level when selling_option is one_time.
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
		// We handle two cases here:
		// - one_time: ensure a single one-time level exists and remove recurring ones
		// - subscription: remove any one-time levels (keep recurring), don't auto-create
		if ( ! in_array( $selling_option, array( 'one_time', 'subscription', 'both' ), true ) ) {
			// For other options, just clean stale meta and exit.
		}

		// Gather attached and valid PMPro level IDs and classify by type.
		$existing_levels = get_post_meta( $object_id, '_tutorpress_pmpro_levels', true );

		global $wpdb;
		$valid_ids    = array();
		$one_time_ids = array();
		$recurring_ids = array();
		if ( ! empty( $existing_levels ) && is_array( $existing_levels ) ) {
			foreach ( $existing_levels as $lvl_id ) {
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, billing_amount, cycle_number FROM {$wpdb->pmpro_membership_levels} WHERE id = %d", absint( $lvl_id ) ), ARRAY_A );
				if ( $row && isset( $row['id'] ) ) {
					$valid_ids[] = (int) $row['id'];
					$is_one_time = ( floatval( $row['billing_amount'] ) <= 0 ) && ( intval( $row['cycle_number'] ) === 0 );
					if ( $is_one_time ) {
						$one_time_ids[] = (int) $row['id'];
					} else {
						$recurring_ids[] = (int) $row['id'];
					}
				}
			}
		}

		if ( empty( $valid_ids ) && ! empty( $existing_levels ) ) {
			// Stale meta present; clear it so we can proceed.
			delete_post_meta( $object_id, '_tutorpress_pmpro_levels' );
		}

		// If pricing type is free, remove all PMPro levels and clear meta.
		$price_type = get_post_meta( $object_id, '_tutor_course_price_type', true );
		if ( 'free' === $price_type ) {
			if ( ! empty( $valid_ids ) ) {
				foreach ( $valid_ids as $vid ) {
					$wpdb->delete( $wpdb->pmpro_membership_levels, array( 'id' => $vid ), array( '%d' ) );
				}
			}
			delete_post_meta( $object_id, '_tutorpress_pmpro_levels' );
			return;
		}

		// If selling_option is subscription-only: remove any one-time levels and update meta.
		if ( 'subscription' === $selling_option ) {
			if ( ! empty( $one_time_ids ) ) {
				foreach ( $one_time_ids as $oid ) {
					$wpdb->delete( $wpdb->pmpro_membership_levels, array( 'id' => $oid ), array( '%d' ) );
				}
				$valid_ids = $recurring_ids; // remaining valid ids are recurring
				update_post_meta( $object_id, '_tutorpress_pmpro_levels', $valid_ids );
			}
			return; // nothing else to do for subscription-only
		}

		// From here, handle one_time option: remove recurring, ensure single one-time exists
		if ( 'one_time' !== $selling_option ) {
			// For 'both' or others, no auto-create; just ensure meta has valid ids
			if ( ! empty( $valid_ids ) ) {
				update_post_meta( $object_id, '_tutorpress_pmpro_levels', $valid_ids );
			}
			return;
		}

		// Get the regular price from post meta.
		$regular_price = get_post_meta( $object_id, 'tutor_course_price', true );
		if ( empty( $regular_price ) || $regular_price <= 0 ) {
			// No price set, skip auto-creation.
			return;
		}

		// Load the mapper to prepare the PMPro level data.
		require_once $this->path . 'includes/utilities/class-pmpro-mapper.php';
		$mapper = new \TutorPress_PMPro_Mapper();

		// Prepare UI-style payload for the mapper.
		$post_title = get_the_title( $object_id );
		$ui_payload = array(
			'object_id'       => $object_id,
			'plan_name'       => $post_title ? $post_title . ' (One-time)' : __( 'One-time Plan for ' . $object_id, 'tutorpress-pmpro' ),
			'payment_type'    => 'one_time',
			'regular_price'   => floatval( $regular_price ),
			'recurring_price' => 0,
			'recurring_value' => 0,
			'recurring_interval' => '',
			'recurring_limit' => 0,
		);

		// Map to PMPro format.
		$db_level_data = $mapper->map_ui_to_pmpro( $ui_payload );

		// Normalize for one-time: initial_payment = regular_price, billing_amount = 0.
		$db_level_data['initial_payment'] = floatval( $regular_price );
		$db_level_data['billing_amount'] = 0;
		$db_level_data['cycle_number'] = 0;
		$db_level_data['cycle_period'] = '';
		$db_level_data['billing_limit'] = 0;

		// Remove meta array before inserting into PMPro DB.
		unset( $db_level_data['meta'] );

		// First, remove any recurring levels currently attached
		if ( ! empty( $recurring_ids ) ) {
			foreach ( $recurring_ids as $rid ) {
				$wpdb->delete( $wpdb->pmpro_membership_levels, array( 'id' => $rid ), array( '%d' ) );
					// Clean up any pmpro_memberships_pages associations for removed level
				if ( class_exists( '\TUTORPRESS_PMPRO\PMPro_Association' ) ) {
					\TUTORPRESS_PMPRO\PMPro_Association::remove_associations_for_level( $rid );
				}
			}
		}

		// If an existing one-time level exists, update it. Otherwise create a new one.
		$level_id = 0;
		if ( ! empty( $one_time_ids ) ) {
			$level_id = $one_time_ids[0];
			$wpdb->update( $wpdb->pmpro_membership_levels, $db_level_data, array( 'id' => $level_id ) );
			
		} else {
			$table = $wpdb->pmpro_membership_levels;
			$wpdb->insert( $table, $db_level_data );
			$level_id = $wpdb->insert_id;
					
		}

		if ( empty( $level_id ) || $level_id <= 0 ) {
			// Insert failed, log error and return.
			return;
		}

		// Attach the single one-time level to the course/bundle.
		update_post_meta( $object_id, '_tutorpress_pmpro_levels', array( $level_id ) );

		// Ensure association row exists in pmpro_memberships_pages
		if ( class_exists( '\\TUTORPRESS_PMPRO\\PMPro_Association' ) ) {
			\TUTORPRESS_PMPRO\PMPro_Association::ensure_course_level_association( $object_id, $level_id );
		}

		// Successfully attached the level.
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
	 * Get or create a level group for a course.
	 *
	 * Creates a group named "Course: {Course Title}" with allow_multiple_selections = false
	 * so users can only select one pricing option per course.
	 *
	 * Also syncs the group name with the current course title to handle course renames.
	 *
	 * @since 1.6.0
	 * @param int $course_id The course ID
	 * @return int|false The group ID, or false on failure
	 */
	public static function get_or_create_course_level_group( $course_id ) {
		if ( ! function_exists( 'pmpro_create_level_group' ) ) {
			if ( defined( 'TP_PMPRO_LOG' ) && TP_PMPRO_LOG ) {
				error_log( '[TP-PMPRO] get_or_create_course_level_group skipped (PMPro groups not available); course=' . $course_id );
			}
			return false;
		}

		// Get current course title (always fresh)
		$course_title = get_the_title( $course_id );
		$group_name = sprintf( __( 'Course: %s', 'tutorpress-pmpro' ), $course_title );

		// Check if group already exists (stored in course meta)
		$existing_group_id = get_post_meta( $course_id, '_tutorpress_pmpro_group_id', true );
		if ( $existing_group_id && function_exists( 'pmpro_get_level_group' ) ) {
			$group = pmpro_get_level_group( $existing_group_id );
			if ( $group ) {
				// Sync group name with current course title (handles course renames)
				if ( $group->name !== $group_name ) {
					global $wpdb;
					$wpdb->update(
						$wpdb->pmpro_groups,
						array( 'name' => $group_name ),
						array( 'id' => $existing_group_id ),
						array( '%s' ),
						array( '%d' )
					);
					if ( defined( 'TP_PMPRO_LOG' ) && TP_PMPRO_LOG ) {
						error_log( '[TP-PMPRO] get_or_create_course_level_group updated_group_name; group=' . $existing_group_id . ' old="' . $group->name . '" new="' . $group_name . '" course=' . $course_id );
					}
				}
				
				if ( defined( 'TP_PMPRO_LOG' ) && TP_PMPRO_LOG ) {
					error_log( '[TP-PMPRO] get_or_create_course_level_group found_existing_group=' . $existing_group_id . ' course=' . $course_id );
				}
				return (int) $existing_group_id;
			}
		}

		// Create new group
		// allow_multiple_selections = false: users can only pick ONE pricing option per course
		$group_id = pmpro_create_level_group( $group_name, false );

		if ( $group_id ) {
			update_post_meta( $course_id, '_tutorpress_pmpro_group_id', $group_id );
			if ( defined( 'TP_PMPRO_LOG' ) && TP_PMPRO_LOG ) {
				error_log( '[TP-PMPRO] get_or_create_course_level_group created_group=' . $group_id . ' name="' . $group_name . '" course=' . $course_id );
			}
			return (int) $group_id;
		}

		if ( defined( 'TP_PMPRO_LOG' ) && TP_PMPRO_LOG ) {
			error_log( '[TP-PMPRO] get_or_create_course_level_group failed to create group; course=' . $course_id );
		}
		return false;
	}

	/**
	 * Add a membership level to the course's level group.
	 *
	 * @since 1.6.0
	 * @param int $course_id The course ID
	 * @param int $level_id The level ID
	 * @return void
	 */
	public static function add_level_to_course_group( $course_id, $level_id ) {
		if ( ! function_exists( 'pmpro_add_level_to_group' ) ) {
			return;
		}

		$group_id = self::get_or_create_course_level_group( $course_id );
		if ( ! $group_id ) {
			return;
		}

		pmpro_add_level_to_group( $level_id, $group_id );
		if ( defined( 'TP_PMPRO_LOG' ) && TP_PMPRO_LOG ) {
			error_log( '[TP-PMPRO] add_level_to_course_group level=' . $level_id . ' group=' . $group_id . ' course=' . $course_id );
		}
	}


	/**
	 * Delete the level group for a course if it's empty.
	 *
	 * Called when a course is deleted or has no more levels.
	 *
	 * @since 1.6.0
	 * @param int $course_id The course ID
	 * @return void
	 */
	public static function delete_course_level_group_if_empty( $course_id ) {
		if ( ! function_exists( 'pmpro_delete_level_group' ) || ! function_exists( 'pmpro_get_level_ids_for_group' ) ) {
			return;
		}

		$group_id = get_post_meta( $course_id, '_tutorpress_pmpro_group_id', true );
		if ( ! $group_id ) {
			return;
		}

		$levels_in_group = pmpro_get_level_ids_for_group( $group_id );
		if ( empty( $levels_in_group ) ) {
			$deleted = pmpro_delete_level_group( $group_id );
			if ( $deleted ) {
				delete_post_meta( $course_id, '_tutorpress_pmpro_group_id' );
				if ( defined( 'TP_PMPRO_LOG' ) && TP_PMPRO_LOG ) {
					error_log( '[TP-PMPRO] delete_course_level_group_if_empty deleted_group=' . $group_id . ' course=' . $course_id );
				}
			}
		} else {
			if ( defined( 'TP_PMPRO_LOG' ) && TP_PMPRO_LOG ) {
				error_log( '[TP-PMPRO] delete_course_level_group_if_empty group_not_empty=' . $group_id . ' level_count=' . count( $levels_in_group ) . ' course=' . $course_id );
			}
		}
	}

}
