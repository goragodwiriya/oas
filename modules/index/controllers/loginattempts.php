<?php
/**
 * @filesource modules/index/controllers/loginattempts.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Loginattempts;

use Kotchasan\Http\Request;
use Kotchasan\Http\Response;
use Kotchasan\Language;

/**
 * API Login Attempts Controller
 *
 * รายงานการเข้าระบบไม่สำเร็จ · ลบรายการออก = ปลดล็อก IP/ชื่อผู้ใช้ก่อนครบเวลาล็อก
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Gcms\Table
{
    /**
     * Allowed sort columns for SQL injection prevention
     *
     * @var array
     */
    protected $allowedSortColumns = ['id', 'attempted_at', 'username', 'ip_address'];

    /**
     * ชื่อผู้ใช้กับ IP ของทุกคนเป็นข้อมูลระดับเดียวกับหน้าสมาชิก ใช้กฎเดียวกัน
     * (แอดมินทุกคน ยกเว้นบัญชีตัวอย่างของ demo_mode)
     *
     * @param Request $request
     * @param object $login
     *
     * @return true|Response
     */
    protected function checkAuthorization(Request $request, $login)
    {
        if (!\Index\Users\Model::canManage($login)) {
            return $this->errorResponse('Forbidden', 403);
        }

        return true;
    }

    /**
     * Get custom parameters for data table
     *
     * @param Request $request
     * @param object $login
     *
     * @return array
     */
    protected function getCustomParams(Request $request, $login): array
    {
        return [
            'from' => $request->get('from')->date(),
            'to' => $request->get('to')->date()
        ];
    }

    /**
     * Query data to send to DataTable
     *
     * @param array $params
     * @param object $login
     *
     * @return \Kotchasan\QueryBuilder\QueryBuilderInterface
     */
    protected function toDataTable($params, $login)
    {
        return Model::toDataTable($params);
    }

    /**
     * Handle delete action
     *
     * @param Request $request
     * @param object $login
     *
     * @return Response
     */
    protected function handleDeleteAction(Request $request, $login)
    {
        // action() ไม่ผ่าน checkAuthorization() ต้องตรวจเอง
        if (!\Index\Users\Model::canManage($login)) {
            return $this->errorResponse('Forbidden', 403);
        }

        $ids = $request->request('ids', [])->toInt();
        $removeCount = Model::remove($ids);

        if (empty($removeCount)) {
            return $this->errorResponse('Delete action failed', 400);
        }

        \Index\Log\Model::add(0, 'index', 'Delete', 'Deleted '.$removeCount.' login attempt(s)', $login->id);

        return $this->redirectResponse('reload', Language::replace('Deleted :count items', [':count' => $removeCount]), 200, 0, 'loginattempts');
    }
}
