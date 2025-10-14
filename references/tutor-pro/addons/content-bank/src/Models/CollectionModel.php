<?php
/**
 * Collection Model
 *
 * @package TutorPro\ContentBank
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.7.0
 */

namespace TutorPro\ContentBank\Models;

use Tutor\Helpers\QueryHelper;
use Tutor\Models\BaseModel;
use TUTOR\User;

/**
 * CollectionModel Class.
 *
 * @since 3.7.0
 */
class CollectionModel extends BaseModel {
	/**
	 * Table name
	 *
	 * @var string
	 */
	protected $table_name = 'posts';

	/**
	 * Primary key of the table
	 *
	 * @var string
	 */
	protected $primary_key = 'ID';

	/**
	 * Post type for collections.
	 *
	 * @since 3.7.0
	 */
	const POST_TYPE = 'cb-collection';

	/**
	 * Get list of collections.
	 *
	 * @since 3.7.0
	 *
	 * @param array   $where where conditions.
	 * @param string  $search_term search term.
	 * @param integer $per_page per page.
	 * @param integer $page page.
	 * @param string  $order_by order by.
	 * @param string  $order order.
	 * @param array   $args extra arguments.
	 *
	 * @return object
	 */
	public function get_collections( array $where = array(), $search_term = '', int $per_page = 10, int $page = 1, string $order_by = 'ID', string $order = 'desc', $args = array() ) {

		$where['post_type'] = self::POST_TYPE;

		$search = array();
		if ( ! empty( $search_term ) ) {
			$search = array( 'post_title' => $search_term );
		}

		$hide_empty = isset( $args['hide_empty'] ) ? (bool) $args['hide_empty'] : false;
		$context    = $args['context'] ?? '';

		$query_args = array(
			'select'   => array( 'ID', 'post_title' ),
			'where'    => $where,
			'search'   => $search,
			'order_by' => $order_by,
			'order'    => $order,
		);

		if ( $hide_empty ) {
			$post_type_clause = '';
			if ( 'topic' === $context ) {
				$post_type_str    = QueryHelper::prepare_in_clause( array( ContentModel::LESSON_POST_TYPE, ContentModel::ASSIGNMENT_POST_TYPE ) );
				$post_type_clause = " AND post_type IN ({$post_type_str})";
			} elseif ( 'quiz_builder' === $context ) {
				$post_type_str    = QueryHelper::prepare_in_clause( array( ContentModel::QUESTION_POST_TYPE ) );
				$post_type_clause = " AND post_type IN ({$post_type_str})";
			}

			$query_args['having'] = "(SELECT count(ID) FROM {$this->db->posts} WHERE post_parent=main.ID {$post_type_clause}) > 0";

		}

		$data = $this->paginate(
			$per_page,
			$page,
			$query_args
		);

		if ( $data->total_record > 0 ) {
			foreach ( $data->data as $row ) {
				$row->count_stats = ( new ContentModel() )->get_count_stats( $row->ID );
			}
		}

		return $data;
	}

	/**
	 * Check user has access to a collection.
	 *
	 * @since 3.7.0
	 *
	 * @param int $collection_id Collection ID.
	 * @param int $user_id User ID.
	 *
	 * @return bool True if user has access, false otherwise.
	 */
	public function has_collection_access( int $collection_id, int $user_id = 0 ): bool {
		$user_id = tutor_utils()->get_user_id( $user_id );
		if ( User::is_admin() ) {
			return true;
		}

		$count = $this->count(
			array(
				'ID'          => $collection_id,
				'post_author' => $user_id,
				'post_type'   => self::POST_TYPE,
			)
		);

		return $count > 0;
	}

	/**
	 * Create a new collection.
	 *
	 * @since 3.7.0
	 *
	 * @param array $data Collection data.
	 *
	 * @return int|false Returns the post ID on success, or false on failure.
	 */
	public function create( $data ) {
		$data['post_type']   = self::POST_TYPE;
		$data['post_status'] = 'publish';

		return wp_insert_post( $data, false, false );
	}

	/**
	 * Update an existing collection.
	 *
	 * @since 3.7.0
	 *
	 * @param int   $id Collection ID.
	 * @param array $data Collection data.
	 *
	 * @return int|false Returns the post ID on success, or false on failure.
	 */
	public function update( $id, $data ) {
		$data['ID']        = $id;
		$data['post_type'] = self::POST_TYPE;

		return wp_update_post( $data, false, false );
	}

	/**
	 * Delete a collection.
	 *
	 * @since 3.7.0
	 *
	 * @param int $id Collection ID.
	 *
	 * @return \WP_Post|false Returns the deleted post object on success, or false on failure.
	 */
	public function delete( $id ) {
		return wp_delete_post( $id, true );
	}
}
