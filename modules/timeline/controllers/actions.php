<?php
/**
 * @filesource modules/timeline/controllers/actions.php
 *
 * POST /api/timeline/actions — ทางเดียวที่ Hub เขียนอะไรกลับเข้ามาในระบบนี้
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Timeline\Actions;

use Gcms\Api as ApiController;
use Gcms\Timeline\Provider;
use Kotchasan\ApiException;
use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * @see TIMELINE-PROTOCOL.md §10
 *
 * @since 1.0
 */
class Controller extends ApiController
{
    /**
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function index(Request $request)
    {
        try {
            \Gcms\Timeline\Guard::check($request, 'POST');

            $body = json_decode((string) $request->getBody(), true);
            if (!is_array($body)) {
                throw new ApiException(Language::get('The request body must be JSON'), 400);
            }

            return $this->successResponse(Provider::action($body));
        } catch (ApiException $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode(), $e);
        } catch (\Throwable $e) {
            return $this->errorResponse(Language::get('Timeline action failed'), 500, $e);
        }
    }
}
