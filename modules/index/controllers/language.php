<?php
/**
 * @filesource modules/index/controllers/language.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Language;

use Gcms\Api as ApiController;
use Kotchasan\Http\Request;
use Kotchasan\Http\Response;

/**
 * API Language Controller
 *
 * Handles language translation endpoints
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends ApiController
{
    /**
     * GET /api/index/language/get
     * Get Language details by ID
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

            $id = $request->get('id')->toInt();
            $language = \Index\Language\Model::get($id);

            if (!$language) {
                return $this->redirectResponse('/404', 'No data available', 404);
            }

            return $this->successResponse([
                'id' => $language->id,
                'key' => $language->key,
                'type' => $language->type,
                'translate' => [
                    'columns' => \Index\Language\Model::getColumns(),
                    'data' => \Index\Language\Model::prepareTranslateData($language)
                ]
            ], 'Language details retrieved');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }

    /**
     * POST /api/index/language/save
     * Save language (create or update)
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

            $id = $request->post('id')->toInt();
            $type = $request->post('language_type')->filter('a-z');

            // Build save data
            $save = [
                'key' => $this->allowTags($request->post('language_key')->topic()),
                'type' => $type
            ];

            // Validate key
            if (empty($save['key'])) {
                return $this->formErrorResponse(['language_key' => 'Key is required'], 400);
            }

            // Installed language
            $languages = \Index\Language\Model::getLanguages();

            // Get indexed array parameters - post() now auto-detects key[0], key[1], etc.
            $keys = $request->post('key', [])->topic();

            $langValues = [];
            foreach ($languages as $lng) {
                $langValues[$lng] = $request->post($lng, [])->topic();
            }

            // Handle values based on type
            if ($type === 'array') {
                // For array type, build JSON from keys and values
                foreach ($languages as $lng) {
                    $arr = [];
                    foreach ($keys as $index => $key) {
                        if ($key !== '') {
                            $arrayKey = is_numeric($key) ? (int) $key : $key;
                            $arr[$arrayKey] = $this->allowTags($langValues[$lng][$index] ?? '');
                        }
                    }
                    $save[$lng] = json_encode($arr, JSON_UNESCAPED_UNICODE);
                }
            } else {
                // For text/int type, use first value
                foreach ($languages as $lng) {
                    $value = reset($langValues[$lng]);
                    if ($type === 'int') {
                        $save[$lng] = (int) $value;
                    } else {
                        $save[$lng] = $this->allowTags($value);
                    }
                }

                // If en equals key, store as empty
                if (isset($save['en']) && $save['en'] === $save['key']) {
                    $save['en'] = '';
                }
            }

            // Check for duplicate key
            $db = \Kotchasan\DB::create();
            $existing = $db->first('language', [['key', $save['key']]]);

            if ($existing && $existing->id != $id) {
                return $this->formErrorResponse(['language_key' => 'Key already exists'], 400);
            }

            // Save
            $savedId = \Index\Language\Model::save($db, $id, $save);

            // Log
            \Index\Log\Model::add($savedId, 'index', 'Save', ($id === 0 ? 'Add' : 'Edit').' language: '.$save['key'], $login->id);

            return $this->redirectResponse('back', 'Saved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }

    /**
     * Allow certain HTML tags in the string
     *
     * @param string $string
     *
     * @return string
     */
    public function allowTags($string)
    {
        return preg_replace_callback('/(&lt;(\/?(a|em|b|strong|ul|ol|li|dd|dt|dl|small)).*?&gt;)/is', function ($matches) {
            return html_entity_decode($matches[1]);
        }, $string);
    }
}
