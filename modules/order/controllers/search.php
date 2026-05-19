<?php
/**
 * @filesource modules/order/controllers/search.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Order\Search;

use Gcms\Api as ApiController;
use Kotchasan\Http\Request;

/**
 * Order Search Controller
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Gcms\Api
{
    /**
     * @param Request $request
     */
    public function index(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'GET');
            $login = $this->authenticateRequest($request);
            if (!$login) {
                return $this->errorResponse('Unauthorized', 401);
            }

            $search = $request->get('q')->topic();
            $limit = min($request->get('limit', 20)->toInt(), 50);

            return $this->successResponse(Model::search($search, $limit));
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500);
        }
    }

    /**
     * @param Request $request
     */
    public function get(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'GET');
            $login = $this->authenticateRequest($request);
            if (!$login) {
                return $this->errorResponse('Unauthorized', 401);
            }

            $code = $request->get('product')->topic();
            if ($code === '') {
                return $this->errorResponse('Product is required', 400);
            }

            $item = Model::get($code);
            if (!$item) {
                return $this->errorResponse('Product not found', 404);
            }

            return $this->successResponse($item, 'Product found');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500);
        }
    }
}
