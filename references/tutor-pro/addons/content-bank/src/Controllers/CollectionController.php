<?php
/**
 * Manage Content Bank Collection
 *
 * @package TutorPro\ContentBank
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.7.0
 */

namespace TutorPro\ContentBank\Controllers;

use Tutor\Helpers\QueryHelper;
use TUTOR\Input;
use Tutor\Traits\JsonResponse;
use TUTOR\User;
use TutorPro\ContentBank\Helper;
use TutorPro\ContentBank\Models\CollectionModel;

/**
 * CollectionController Class.
 *
 * @since 3.7.0
 */
class CollectionController extends BaseController {
	use JsonResponse;

	/**
	 * Register hooks and dependencies
	 *
	 * @since 3.7.0
	 */
	public function __construct() {
		parent::__construct();

		add_action( 'wp_ajax_tutor_content_bank_collections', array( $this, 'ajax_get_content_bank_collections' ) );
		add_action( 'wp_ajax_tutor_content_bank_collection_save', array( $this, 'ajax_save_content_bank_collection' ) );
		add_action( 'wp_ajax_tutor_content_bank_collection_delete', array( $this, 'ajax_delete_content_bank_collection' ) );
		add_action( 'wp_ajax_tutor_content_bank_collection_duplicate', array( $this, 'ajax_duplicate_collection' ) );
	}

	/**
	 * Get content bank collections via AJAX.
	 *
	 * @since 3.7.0
	 */
	public function ajax_get_content_bank_collections() {
		Helper::check_nonce_and_capability();

		$search     = Input::post( 'search', '' );
		$per_page   = max( Input::post( 'per_page', 10, Input::TYPE_INT ), 1 );
		$page       = max( Input::post( 'page', 1, Input::TYPE_INT ), 1 );
		$hide_empty = Input::post( 'hide_empty', false, Input::TYPE_BOOL );
		$context    = Input::post( 'context', '' );

		$where = array();

		$where['post_author'] = get_current_user_id();
		if ( is_admin() && User::is_admin() ) {
			unset( $where['post_author'] );
		}

		$data = $this->collection_model->get_collections(
			$where,
			$search,
			$per_page,
			$page,
			'ID',
			'DESC',
			array(
				'hide_empty' => $hide_empty,
				'context'    => $context,
			)
		);

		$this->json_response(
			__( 'Collections fetched successfully', 'tutor-pro' ),
			$data
		);
	}

	/**
	 * Save content bank collection via AJAX.
	 *
	 * @since 3.7.0
	 */
	public function ajax_save_content_bank_collection() {
		Helper::check_nonce_and_capability();

		$rules = array(
			'id'   => 'if_input|numeric',
			'name' => 'required',
		);

		Helper::check_validation( $rules );

		$name = Input::post( 'name' );

		if ( Input::has( 'id' ) ) {
			$collection_id = Input::post( 'id', 0, Input::TYPE_INT );

			$has_collection_access = $this->collection_model->has_collection_access( $collection_id );
			if ( ! $has_collection_access ) {
				$this->response_bad_request( tutor_utils()->error_message() );
			}

			$this->collection_model->update(
				$collection_id,
				array( 'post_title' => $name )
			);

			$response_message = __( 'Collection updated successfully', 'tutor-pro' );
		} else {
			$collection_id    = $this->collection_model->create( array( 'post_title' => $name ) );
			$response_message = __( 'Collection created successfully', 'tutor-pro' );
		}

		return $this->json_response( $response_message, $collection_id );
	}

	/**
	 * Delete content bank collection via AJAX.
	 *
	 * @since 3.7.0
	 *
	 * @return void
	 */
	public function ajax_delete_content_bank_collection() {
		Helper::check_nonce_and_capability();

		$collection_id = Input::post( 'id', 0, Input::TYPE_INT );
		if ( ! $collection_id ) {
			$this->response_bad_request( __( 'Invalid collection ID', 'tutor-pro' ) );
		}

		if ( ! $this->collection_model->has_collection_access( $collection_id ) ) {
			$this->response_bad_request( tutor_utils()->error_message() );
		}

		$this->collection_model->delete( $collection_id );

		$content_ids = $this->content_model->get_content_ids( $collection_id );
		foreach ( $content_ids as $content_id ) {
			$this->content_model->delete( $content_id );
		}

		$this->json_response( __( 'Collection deleted successfully', 'tutor-pro' ) );
	}

	/**
	 * Duplicate a collection via AJAX
	 *
	 * @since 3.7.0
	 *
	 * @return void
	 */
	public function ajax_duplicate_collection() {
		Helper::check_nonce_and_capability();

		$collection_id     = Input::post( 'id', 0, Input::TYPE_INT );
		$collection_record = $this->collection_model->get_row(
			array(
				'ID'        => $collection_id,
				'post_type' => CollectionModel::POST_TYPE,
			)
		);

		if ( ! $collection_record ) {
			$this->response_bad_request( __( 'No collection record found', 'tutor-pro' ) );
		}

		if ( ! $this->collection_model->has_collection_access( $collection_id ) ) {
			$this->response_bad_request( tutor_utils()->error_message() );
		}

		global $wpdb;
		$new_collection_id = QueryHelper::duplicate_row(
			$wpdb->posts,
			array( 'ID' => $collection_id ),
			function ( array $row ) {
				unset( $row['ID'] );
				$row['post_title'] = $row['post_title'] . __( ' (copy)', 'tutor-pro' );
				return $row;
			}
		);

		$content_ids = $this->content_model->get_content_ids( $collection_id );
		foreach ( $content_ids as $content_id ) {
			try {
				$this->content_model->duplicate_content( $content_id, $new_collection_id, false );
			} catch ( \Throwable $th ) {
				$this->response_bad_request( $th->getMessage() );
			}
		}

		$this->json_response( __( 'Collection successfully duplicated', 'tutor-pro' ), $new_collection_id );
	}
}
