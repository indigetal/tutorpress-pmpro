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

		// Check selling_option value
		$selling_option = get_post_meta( $object_id, '_tutor_course_selling_option', true );
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
		require_once $this->path . 'includes/class-pmpro-mapper.php';
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

}
