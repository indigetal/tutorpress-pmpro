<?php
/**
 * ContentModel Model
 *
 * @package TutorPro\ContentBank
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.7.0
 */

namespace TutorPro\ContentBank\Models;

use Tutor\Models\BaseModel;
use Tutor\Models\QuizModel;
use Tutor\Helpers\QueryHelper;

/**
 * ContentModel Class.
 *
 * @since 3.7.0
 */
class ContentModel extends BaseModel {
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
	 * Content post types
	 *
	 * @since 3.7.0
	 */
	const LESSON_POST_TYPE     = 'cb-lesson';
	const ASSIGNMENT_POST_TYPE = 'cb-assignment';
	const QUESTION_POST_TYPE   = 'cb-question';

	/**
	 * Constants for content type.
	 *
	 * @var string
	 */
	const TYPE_LESSON     = 'lesson';
	const TYPE_ASSIGNMENT = 'assignment';
	const TYPE_QUESTION   = 'question';

	/**
	 * Get all content types.
	 *
	 * @since 3.7.0
	 *
	 * @return array
	 */
	public static function get_content_types() {
		return array(
			self::TYPE_LESSON,
			self::TYPE_ASSIGNMENT,
			self::TYPE_QUESTION,
		);
	}

	/**
	 * Get all content post types.
	 *
	 * @since 3.7.0
	 *
	 * @return array
	 */
	public static function get_content_post_types() {
		return array(
			self::LESSON_POST_TYPE,
			self::ASSIGNMENT_POST_TYPE,
			self::QUESTION_POST_TYPE,
		);
	}

	/**
	 * Get the post type based on content type.
	 *
	 * @since 3.7.0
	 *
	 * @param string $content_type The content type key (e.g., 'lesson', 'assignment', 'question').
	 *
	 * @return string|null The post type or null if not found.
	 */
	public static function get_post_type_by_content_type( $content_type ) {
		$map = array(
			self::TYPE_LESSON     => self::LESSON_POST_TYPE,
			self::TYPE_ASSIGNMENT => self::ASSIGNMENT_POST_TYPE,
			self::TYPE_QUESTION   => self::QUESTION_POST_TYPE,
		);

		return $map[ $content_type ] ?? null;
	}

	/**
	 * Get content type by post type.
	 *
	 * @since 3.7.0
	 *
	 * @param string $post_type post type.
	 *
	 * @return string
	 */
	public static function get_content_type_by_post_type( $post_type ) {
		$map = array(
			self::LESSON_POST_TYPE     => self::TYPE_LESSON,
			self::ASSIGNMENT_POST_TYPE => self::TYPE_ASSIGNMENT,
			self::QUESTION_POST_TYPE   => self::TYPE_QUESTION,
		);

		return $map[ $post_type ] ?? null;
	}

	/**
	 * Delete a collection content by content id.
	 *
	 * @param int $content_id content id.
	 *
	 * @return bool
	 */
	public function delete( $content_id ) {
		$content = $this->get_content( $content_id );
		if ( ! $content || ! ( new CollectionModel() )->has_collection_access( $content->post_parent ) ) {
			return false;
		}

		// Delete content and its meta.
		wp_delete_post( $content_id, true );

		// Delete question and it's answers.
		if ( self::QUESTION_POST_TYPE === $content->post_type ) {
			$question = $this->get_question_by_content_id( $content_id );
			QueryHelper::delete(
				QueryHelper::prepare_table_name( 'tutor_quiz_questions' ),
				array( 'question_id' => $question->question_id )
			);

			// Delete answers.
			QueryHelper::bulk_delete(
				QueryHelper::prepare_table_name( 'tutor_quiz_question_answers' ),
				array( 'belongs_question_id' => $question->question_id )
			);
		}

		return true;
	}

	/**
	 * Get count status of content in a collection.
	 *
	 * @since 3.7.0
	 *
	 * @param int $collection_id Collection ID.
	 *
	 * @return object Returns an object containing counts of lessons, assignments, questions, and total content.
	 */
	public function get_count_stats( $collection_id ) {
		global $wpdb;

		$content_post_types_str = QueryHelper::prepare_in_clause( self::get_content_post_types() );

		//phpcs:disabled --variable safely passed.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_type, count(id) AS total
				FROM {$wpdb->posts}
				WHERE post_parent = %d AND post_type IN ( {$content_post_types_str} )
				GROUP BY post_type",
				$collection_id
			)
		);
		//phpcs:enable

		$stats = array(
			self::TYPE_LESSON     => 0,
			self::TYPE_ASSIGNMENT => 0,
			self::TYPE_QUESTION   => 0,
		);

		foreach ( $results as $result ) {
			switch ( $result->post_type ) {
				case self::LESSON_POST_TYPE:
					$stats[ self::TYPE_LESSON ] = (int) $result->total;
					break;
				case self::ASSIGNMENT_POST_TYPE:
					$stats[ self::TYPE_ASSIGNMENT ] = (int) $result->total;
					break;
				case self::QUESTION_POST_TYPE:
					$stats[ self::TYPE_QUESTION ] = (int) $result->total;
					break;
			}
		}

		$stats['total'] = array_sum( $stats );

		return (object) $stats;
	}

	/**
	 * Get count of content by type in a collection.
	 *
	 * @since 3.7.0
	 *
	 * @param int    $collection_id Collection ID.
	 * @param string $content_type Content type (e.g., 'cb-lesson', 'cb-assignment', 'cb-question').
	 *
	 * @return int Returns the count of content of the specified type in the collection.
	 */
	public function get_count_by_content_type( $collection_id, $content_type ) {
		return $this->count(
			array(
				'post_parent' => $collection_id,
				'post_type'   => self::get_post_type_by_content_type( $content_type ),
			)
		);
	}

	/**
	 * Get list of contents.
	 *
	 * @since 3.7.0
	 *
	 * @param array   $where where conditions.
	 * @param string  $search_term search term.
	 * @param integer $per_page per page.
	 * @param integer $page page.
	 * @param string  $order_by order by.
	 * @param string  $order order.
	 *
	 * @return object
	 */
	public function get_contents( array $where = array(), $search_term = '', int $per_page = 10, int $page = 1, string $order_by = 'ID', string $order = 'desc' ) {
		$context = $where['context'] ?? '';
		unset( $where['context'] );

		$search = array();
		if ( ! empty( $search_term ) ) {
			$search = array( 'post_title' => $search_term );
		}

		$args = array(
			'search'   => $search,
			'order_by' => $order_by,
			'order'    => $order,
		);

		/**
		 * Join the question table for Course builder > Question import from content bank.
		 *
		 * @since 3.7.0
		 */
		if ( 'quiz_builder' === $context ) {
			$question_table = QueryHelper::prepare_table_name( 'tutor_quiz_questions' );

			$args['alias']      = 'post';
			$args['select']     = array( 'post.*', 'question.question_id', 'question.question_type' );
			$where['post_type'] = self::QUESTION_POST_TYPE;

			if ( isset( $where['question_types'] ) ) {
				$where['question.question_type'] = array( 'IN', $where['question_types'] );
				unset( $where['question_types'] );
			}

			$joins = array(
				array(
					'type'  => 'LEFT',
					'table' => "{$question_table} AS question",
					'on'    => 'post.ID = question.content_id',
				),
			);

			$args['joins'] = $joins;
		}

		$args['where'] = $where;

		$data = $this->paginate(
			$per_page,
			$page,
			$args
		);

		$final = array();
		foreach ( $data->data as $item ) {
			$content = array(
				'ID'           => $item->ID,
				'post_author'  => $item->post_author,
				'post_parent'  => $item->post_parent,
				'post_title'   => $item->post_title,
				'post_type'    => $item->post_type,
				'post_date'    => $item->post_date,
				'post_content' => $item->post_content,
			);

			if ( 'collection_list_page' === $context || empty( $context ) ) {
				$content['linked_courses'] = $this->get_linked_courses( $item->ID );
			}

			if ( self::QUESTION_POST_TYPE === $item->post_type && 'quiz_builder' === $context ) {
				$question                    = $this->get_question_by_content_id( $item->ID );
				$question->question_settings = maybe_unserialize( $question->question_settings );
				$question->question_answers  = QuizModel::get_question_answers( $question->question_id, $question->question_type );

				$content['question']      = $question;
				$content['question_type'] = $item->question_type;
			}

			$final[] = (object) $content;
		}

		$data->data = $final;

		return $data;
	}

	/**
	 * Get liked courses
	 *
	 * @since 3.7.0
	 *
	 * @param int $content_id content id.
	 *
	 * @return array
	 */
	public function get_linked_courses( $content_id ) {
		$res = array(
			'total'     => 0,
			'courses'   => array(),
			'more_text' => '',
		);

		$course_ids = $this->db->get_col(
			$this->db->prepare(
				"SELECT DISTINCT course_id FROM {$this->db->prefix}tutor_cb_content_usage
				WHERE content_id = %d",
				$content_id
			)
		);

		$course_ids_str = QueryHelper::prepare_in_clause( $course_ids );
		if ( empty( $course_ids_str ) ) {
			return $res;
		}

		$courses = $this->db->get_results(
			$this->db->prepare(
				"SELECT post_title FROM {$this->db->posts}
				WHERE ID IN ( {$course_ids_str} )
				LIMIT 0, 5",
				$content_id
			)
		);

		$res['total']   = count( $course_ids );
		$res['courses'] = $courses;
		if ( $res['total'] > 5 ) {
			$res['more_text'] = sprintf(
				/* translators: %d is the number of courses linked to the content */
				__( '+%d More', 'tutor-pro' ),
				$res['total'] - 5
			);
		}
		return $res;
	}

	/**
	 * Get content record.
	 *
	 * @since 3.7.0
	 *
	 * @param int    $content_id content id.
	 * @param string $post_type post type.
	 *
	 * @return mixed
	 */
	public function get_content( $content_id, $post_type = null ) {
		$args = array( 'ID' => $content_id );
		if ( $post_type ) {
			$args['post_type'] = $post_type;
		}

		return $this->get_row( $args );
	}

	/**
	 * Get question record by content id.
	 *
	 * @since 3.7.0
	 *
	 * @param itn $content_id content id.
	 *
	 * @return mixed
	 */
	public static function get_question_by_content_id( $content_id ) {
		$question_table = QueryHelper::prepare_table_name( 'tutor_quiz_questions' );
		return QueryHelper::get_row( $question_table, array( 'content_id' => $content_id ), 'question_id' );
	}

	/**
	 * Duplicate a content.
	 *
	 * @since 3.7.0
	 *
	 * @param int      $content_id content id.
	 * @param int|null $collection_id set to collection.
	 * @param bool     $copy_suffix add copy suffix.
	 *
	 * @return bool
	 */
	public function duplicate_content( $content_id, $collection_id = null, $copy_suffix = true ) {
		$content_record = $this->get_content( $content_id );
		if ( ! ( new CollectionModel() )->has_collection_access( $content_record->post_parent ) ) {
			return false;
		}

		$suffix_text = empty( $copy_suffix ) ? '' : __( ' (copy)', 'tutor-pro' );
		$new_data    = $content_record;
		if ( $collection_id ) {
			$new_data->post_parent = $collection_id;
		}
		unset( $new_data->ID );
		$new_data->post_title = $new_data->post_title . $suffix_text;
		$new_content_id       = $this->create( (array) $new_data );

		$meta_data = get_post_meta( $content_id );
		$meta_data = is_array( $meta_data ) ? $meta_data : array();

		foreach ( $meta_data as $key => $values ) {
			foreach ( $values as $value ) {
				add_post_meta( $new_content_id, $key, maybe_unserialize( $value ) );
			}
		}

		// For question content type.
		if ( self::QUESTION_POST_TYPE === $content_record->post_type ) {

			$questions_table = QueryHelper::prepare_table_name( 'tutor_quiz_questions' );
			$answers_table   = QueryHelper::prepare_table_name( 'tutor_quiz_question_answers' );

			$question        = $this->get_question_by_content_id( $content_id );
			$old_question_id = $question->question_id;
			$answers         = QueryHelper::get_all( $answers_table, array( 'belongs_question_id' => $old_question_id ), 'answer_id', -1 );

			$new_question_id = QueryHelper::duplicate_row(
				$questions_table,
				array( 'question_id' => $question->question_id ),
				function( array $row ) use ( $new_content_id, $suffix_text ) {
					unset( $row['question_id'] );
					$row['content_id']     = $new_content_id;
					$row['question_title'] = $row['question_title'] . $suffix_text;
					return $row;
				}
			);

			foreach ( $answers as $answer ) {
				QueryHelper::duplicate_row(
					$answers_table,
					array( 'answer_id' => $answer->answer_id ),
					function( array $row ) use ( $new_question_id ) {
						unset( $row['answer_id'] );

						$row['belongs_question_id'] = $new_question_id;
						return $row;
					}
				);
			}
		}

		return true;
	}

	/**
	 * Get all content ids by collection id.
	 *
	 * @since 3.7.0
	 *
	 * @param int $collection_id collection id.
	 *
	 * @return array
	 */
	public function get_content_ids( $collection_id ) {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->prefix}posts WHERE post_parent = %d",
				$collection_id
			)
		);

		return $ids;
	}
}
