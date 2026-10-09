<?php
/**
 * @filesource modules/timeline/controllers/items.php
 *
 * GET /api/timeline/items — snapshot ของทุก item ในกรอบเวลาที่ขอ
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Timeline\Items;

use Gcms\Api as ApiController;
use Gcms\Timeline\Provider;
use Kotchasan\ApiException;
use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * @see TIMELINE-PROTOCOL.md §5
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

            $result = Provider::items([
                'past' => $request->get('past', 'P90D')->filter('a-zA-Z0-9'),
                'future' => $request->get('future', 'P12M')->filter('a-zA-Z0-9'),
                'page' => $request->get('page', 1)->toInt(),
                'per_page' => $request->get('per_page', 0)->toInt(),
                'kinds' => $request->get('kinds', '')->filter('a-z0-9_,.*')
            ]);

            return $this->successResponse($result);
        } catch (ApiException $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode(), $e);
        } catch (\Throwable $e) {
            return $this->errorResponse(Language::get('Timeline items failed'), 500, $e);
        }
    }
}
