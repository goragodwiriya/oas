<?php
/**
 * @filesource modules/index/controllers/export.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Export;

use Gcms\Api as ApiController;
use Kotchasan\Http\Request;

/**
 * API Export Controller
 *
 * Handles data export endpoints
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends ApiController
{

    /**
     * The main controller for exporting various data such as reports, product data,
     * or other data. that require users to download as a CSV or Excel file
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        // Require authenticated token before dispatching any export
        $login = $this->authenticateRequest($request);
        if (!$login) {
            return $this->errorResponse('Unauthorized', 401);
        }

        $module = $request->get('module')->filter('a-z');
        $type = $request->get('typ')->filter('a-z');

        if (empty($module) || empty($type)) {
            return $this->errorResponse('Bad Request', 400);
        }

        $className = '\\'.ucfirst($module).'\\Export\\Controller';
        if (class_exists($className)) {
            $controller = new $className();
            if (method_exists($controller, $type)) {
                return $controller->$type($request);
            }
        }

        // Return 404 if module or type not found
        return $this->errorResponse('Not Found', 404);
    }
}