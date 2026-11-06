<?php
/**
 * @filesource modules/timeline/controllers/manifest.php
 *
 * GET /api/timeline/manifest — ระบบนี้คือใคร ให้ item ชนิดไหน ทำอะไรได้
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Timeline\Manifest;

use Gcms\Api as ApiController;
use Gcms\Timeline\Provider;
use Kotchasan\ApiException;
use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * @see TIMELINE-PROTOCOL.md §4
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
            \Gcms\Timeline\Guard::check($request, 'GET');

            return $this->successResponse(Provider::manifest());
        } catch (ApiException $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode(), $e);
        } catch (\Throwable $e) {
            return $this->errorResponse(Language::get('Timeline manifest failed'), 500, $e);
        }
    }
}
