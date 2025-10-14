<?php
/**
 * Manage database tables related to Content Bank
 *
 * @package TutorPro\ContentBank
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.7.0
 */

namespace TutorPro\ContentBank;

use Tutor\Helpers\QueryHelper;

/**
 * Database Class.
 *
 * @since 3.7.0
 */
class Database {
	/**
	 * Register hooks and dependencies
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'create_tables' ) );
	}

	/**
	 * Create database tables
	 *
	 * @since 3.7.0
	 *
	 * @return void
	 */
	public function create_tables() {
		if ( ! ContentBank::get_instance()->is_enabled() ) {
			return;
		}

		global $wpdb;

		$post_table          = $wpdb->posts;
		$content_usage_table = "{$wpdb->prefix}tutor_cb_content_usage";
		$question_table      = $wpdb->prefix . 'tutor_quiz_questions';
		$charset_collate     = $wpdb->get_charset_collate();

		/**
		 * Table schema for content bank content usage.
		 *
		 * @since 3.7.0
		 *
		 * Column `content_id` for lesson, assignment, question id stored in posts table.
		 * Column `object_type` for content type (lesson, assignment, question)
		 */
		$content_usage_table_schema = "CREATE TABLE $content_usage_table (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT(20) UNSIGNED NOT NULL,
			object_type VARCHAR(50) NOT NULL,
			collection_id BIGINT(20) UNSIGNED NOT NULL,
			course_id BIGINT(20) UNSIGNED NOT NULL,
			topic_id BIGINT(20) UNSIGNED NOT NULL,
			content_id BIGINT(20) UNSIGNED DEFAULT NULL,
			copied_post_id BIGINT(20) UNSIGNED DEFAULT NULL,
			quiz_id BIGINT(20) UNSIGNED DEFAULT NULL,
			question_id BIGINT(20) DEFAULT NULL,
			copied_question_id BIGINT(20) DEFAULT NULL, 
			content_order INT NOT NULL DEFAULT '0',
			PRIMARY KEY (id),
			FOREIGN KEY (collection_id) REFERENCES $post_table(id) ON DELETE CASCADE,
			FOREIGN KEY (course_id) REFERENCES $post_table(ID) ON DELETE CASCADE,
			FOREIGN KEY (topic_id) REFERENCES $post_table(ID) ON DELETE CASCADE,
			FOREIGN KEY (content_id) REFERENCES $post_table(ID) ON DELETE CASCADE,
			FOREIGN KEY (copied_post_id) REFERENCES $post_table(ID) ON DELETE CASCADE,
			FOREIGN KEY (quiz_id) REFERENCES $post_table(ID) ON DELETE CASCADE,
			FOREIGN KEY (question_id) REFERENCES $question_table(question_id) ON DELETE CASCADE,
			FOREIGN KEY (copied_question_id) REFERENCES $question_table(question_id) ON DELETE CASCADE,
    		INDEX (object_type),
			INDEX (user_id)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		QueryHelper::table_exists( $content_usage_table ) ? null : dbDelta( $content_usage_table_schema );
	}
}
