<?php
/**
 * @filesource modules/index/controllers/categories.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Categories;

use Gcms\Api as ApiController;
use Kotchasan\Http\Request;
use Kotchasan\Http\Response;
use Kotchasan\Text;

/**
 * API Category Controller
 *
 * Handles category translation endpoints
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends ApiController
{
    /**
     * Supported category types and their labels
     *
     * @var array
     */
    protected $categories = [
        'department' => '{LNG_Department}'
    ];

    /**
     * Indicates if the category supports multiple languages
     *
     * @var boolean
     */
    protected $multiLanguage = false;

    /**
     * GET /api/index/categories/get
     * Get Category details by ID
     *
     * @param Request $request
     *
     * @return Response
     */
    public function get(Request $request)
    {
        try {
            // Validate request method (GET request doesn't need CSRF token)
            ApiController::validateMethod($request, 'GET');

            // Read user from token (Bearer /X-Access-Token param)
            $login = $this->authenticateRequest($request);
            if (!$login) {
                return $this->errorResponse('Unauthorized', 401);
            }

            // Authorization check
            if (!ApiController::hasPermission($login, ['can_config'])) {
                return $this->errorResponse('Forbidden', 403);
            }

            $type = $request->get('type')->filter('a-z_');
            if (!isset($this->categories[$type])) {
                return $this->redirectResponse('/404', 'No data available', 404);
            }

            $category = Model::get($type);
            if (!$category) {
                return $this->redirectResponse('/404', 'No data available', 404);
            }

            return $this->successResponse([
                'data' => [
                    'type' => $type,
                    'title' => $this->categories[$type],
                    'options' => [
                        'columns' => Model::getColumns($this->multiLanguage),
                        'data' => $category
                    ]
                ]
            ], 'Category details retrieved');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }

    /**
     * POST /api/index/categories/save
     * Save category (create or update)
     *
     * @param Request $request
     * @return Response
     */
    public function save(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'POST');
            $this->validateCsrfToken($request);

            // Authentication check (required)
            $login = $this->authenticateRequest($request);
            if (!$login) {
                return $this->redirectResponse('/login', 'Unauthorized', 401);
            }

            // Authorization for saving
            if (!ApiController::canModify($login)) {
                return $this->errorResponse('Permission required', 403);
            }

            $ids = $request->post('id', [])->topic();
            $type = $request->post('type')->filter('a-z_');

            if (!isset($this->categories[$type])) {
                return $this->redirectResponse('/404', 'No data available', 404);
            }

            // Installed language
            $languages = \Index\Language\Model::getLanguages();

            $langValues = [];
            foreach ($languages as $lng) {
                $langValues[$lng] = $request->post($lng, [])->topic();
            }

            $error = [];
            $save = [];
            $check = [];
            foreach ($ids as $key => $id) {
                $category_id = Text::topic($id);
                if ($category_id === '') {
                    $error['category_id_'.$key] = 'Category ID is required';
                } else {
                    foreach ($languages as $lng) {
                        if (isset($save[$category_id.$lng])) {
                            $error['category_id_'.$key] = 'Category ID '.$category_id.' already exists';
                        } else {
                            $topic = Text::topic($langValues[$lng][$key]);
                            if ($topic !== '') {
                                $save[$category_id.$lng] = [
                                    'type' => $type,
                                    'category_id' => $category_id,
                                    'language' => $lng,
                                    'topic' => $topic
                                ];
                                $check[$category_id][$topic] = $lng;
                            }
                        }
                    }
                    if (empty($check[$category_id])) {
                        $error['category_'.$languages[0].'_'.$key] = 'Please fill in';
                    }
                }
            }

            if (!empty($error)) {
                return $this->formErrorResponse($error);
            }

            if (!$this->multiLanguage) {
                $unique = [];
                foreach ($save as $item) {
                    $key = $item['category_id'].'|'.$item['topic'];
                    if (!isset($unique[$key])) {
                        $unique[$key] = $item;
                    }
                }
                $save = $unique;
            }

            // Save
            Model::save($type, $save, $this->multiLanguage);

            // Log
            \Index\Log\Model::add(0, 'index', 'Save', 'Categories saved '.ucfirst($type).' ('.count($save).' rows)', $login->id);

            // Redirect to reload
            return $this->redirectResponse('reload', 'Saved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }
}
