<?php
/**
 * BaseController
 *
 * @package TutorPro\ContentBank
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.7.0
 */

namespace TutorPro\ContentBank\Controllers;

use Tutor\Traits\JsonResponse;
use TutorPro\ContentBank\Models\CollectionModel;
use TutorPro\ContentBank\Models\ContentModel;
use TutorPro\ContentBank\Models\ContentUsageModel;

/**
 * BaseController Class.
 *
 * @since 3.7.0
 */
class BaseController {
	use JsonResponse;

	/**
	 * Collection model.
	 *
	 * @var CollectionModel
	 */
	public $collection_model;

	/**
	 * Content model.
	 *
	 * @var ContentModel
	 */
	public $content_model;

	/**
	 * Content Usage Model
	 *
	 * @var ContentUsageModel
	 */
	public $content_usage_model;

	/**
	 * Constructor.
	 *
	 * @since 3.7.0
	 */
	public function __construct() {
		$this->collection_model    = new CollectionModel();
		$this->content_model       = new ContentModel();
		$this->content_usage_model = new ContentUsageModel();
	}
}
