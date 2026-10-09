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
        // Legacy links use the <module>-export form (e.g. inventory-export).
        // Links already sent out to customers must keep working, so accept it.
        $module = preg_replace('/-export$/', '', $request->get('module')->filter('a-z\\-'));
        $type = $request->get('typ')->filter('a-z');

        if (empty($module) || empty($type)) {
            return $this->errorResponse('Bad Request', 400);
        }

        $className = '\\'.ucfirst($module).'\\Export\\Controller';
        $controller = class_exists($className) ? new $className() : null;
        // method_exists() also sees protected helpers and ignores letter case, so on
        // its own it let `typ=` reach internals such as printPage or resolveOrder,
        // and every public method the framework base class brings along. Only a
        // public method the export controller itself declares is an export type,
        // and publicTypes() is the declaration of them, never one of them.
        $callable = false;
        if ($controller !== null && method_exists($controller, $type)) {
            $method = new \ReflectionMethod($controller, $type);
            $declared = $method->getDeclaringClass()->getName();
            $callable = $method->isPublic()
                && strcasecmp($method->getName(), 'publicTypes') !== 0
                && strpos($declared, 'Kotchasan\\') !== 0
                && strpos($declared, 'Gcms\\') !== 0;
        }

        // Some exports are links handed to people outside the system (e.g. the
        // document link inside an email to a customer). The module declares which
        // types are public and is then responsible for authorizing them itself,
        // typically with a secret key carried in the link.
        $isPublic = $callable
            && method_exists($controller, 'publicTypes')
            && in_array($type, (array) $controller->publicTypes(), true);

        if (!$isPublic) {
            // Require authenticated token before dispatching any export
            $login = $this->authenticateRequest($request);
            if (!$login) {
                return $this->errorResponse('Unauthorized', 401);
            }
        }

        if ($callable) {
            return $controller->$type($request);
        }

        // Return 404 if module or type not found
        return $this->errorResponse('Not Found', 404);
    }
}