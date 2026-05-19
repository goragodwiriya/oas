<?php
/**
 * @filesource modules/order/controllers/export.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Order\Export;

use Gcms\Api as ApiController;
use Kotchasan\DB;
use Kotchasan\Http\Request;
use Kotchasan\Login;

/**
 * API Orders Export Controller
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Export\Export\Controller
{

    /**
     * Print order receipt.
     * Called by Index\Export\Controller when export.php?module=order&typ=print
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function print(Request $request)
    {
        $login = $this->authenticateRequest($request);
        if (!$login) {
            return $this->errorResponse("Unauthorized", 401);
        }

        if (!ApiController::isAdmin($login) && !Login::hasPermission(['can_view_order', 'can_edit_order'], $login)) {
            return $this->errorResponse("Permission required", 403);
        }

        $ids = $this->parseIds($request);
        $orders = [];
        foreach ($ids as $id) {
            $order = Model::get($id);
            if ($order !== null) {
                $orders[] = $order;
            }
        }

        $itemsPerPage = max(5, min(25, $request->get('items_per_page')->toInt() ?: 8));
        $company = is_array(self::$cfg->company ?? null) ? self::$cfg->company : [];
        $authorizedMemberId = (int) (self::$cfg->company_authorized ?? 0);
        if ($authorizedMemberId > 0) {
            $authorizedUser = DB::create()->first('user', ['id', $authorizedMemberId]);
            if ($authorizedUser) {
                $company['authorized_member_id'] = (int) $authorizedUser->id;
                $company['authorized_name'] = (string) ($authorizedUser->name ?? '');
            }
        }
        $content = View::renderSheets($orders, $itemsPerPage, $company);
        $title = count($orders) === 1
            ? View::getDocumentTitle($orders[0]->document_type ?? null)
            : View::getDocumentTitle(null);

        return self::printHtml([
            '/%CONTENT%/' => $content,
            '/%TITLE%/' => $title
        ]);
    }

    /**
     * Parse order IDs from ?id=N or ?ids=N,N,N request params.
     *
     * @param Request $request
     *
     * @return int[]
     */
    private function parseIds(Request $request): array
    {
        $ids = [];

        $id = $request->get('id')->toInt();
        if ($id > 0) {
            $ids[] = $id;
        }

        $rawIds = $request->get('ids')->toString();
        if ($rawIds !== '') {
            foreach (preg_split('/\s*,\s*/', $rawIds) as $rawId) {
                $value = (int) $rawId;
                if ($value > 0) {
                    $ids[] = $value;
                }
            }
        }

        return array_values(array_unique($ids));
    }
}
