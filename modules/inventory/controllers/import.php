<?php
/**
 * @filesource modules/inventory/controllers/import.php
 *
 * api/inventory/import/get|save — นำเข้าสินค้า/ลูกค้าจากไฟล์ CSV
 * ระบบเดิมคือ module=inventory-import&type=product|customer
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Inventory\Import;

use Gcms\Api as ApiController;
use Kotchasan\Http\Request;
use Kotchasan\Http\Response;
use Kotchasan\Language;

/**
 * API นำเข้าข้อมูล
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends ApiController
{
    /**
     * ผลการนำเข้า
     *
     * @var array
     */
    protected $result = ['new' => 0, 'update' => 0, 'skip' => 0];

    /**
     * ชนิดที่กำลังนำเข้า
     *
     * @var string
     */
    protected $importType = '';

    /**
     * GET api/inventory/import/get?type=product|customer
     *
     * @param Request $request
     *
     * @return Response
     */
    public function get(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'GET');

            $login = $this->authenticateRequest($request);
            if (!$login) {
                return $this->errorResponse('Unauthorized', 401);
            }
            if (!ApiController::hasPermission($login, 'can_manage_inventory')) {
                return $this->errorResponse('Permission required', 403);
            }

            $type = $request->get('type')->filter('a-z');
            $spec = Model::type($type);
            if ($spec === null) {
                return $this->errorResponse('No data available', 404);
            }

            return $this->successResponse([
                'data' => [
                    'type' => $type,
                    'title' => $spec['title'],
                    'back_url' => $spec['url'],
                    // ไฟล์ตัวอย่าง = ข้อมูลจริงที่ส่งออกมา แก้แล้วนำเข้ากลับได้เลย
                    'sample_url' => 'export.php?module=inventory&typ=csv&type='.$type,
                    'columns' => implode(', ', $spec['columns']),
                    'key_column' => $spec['key'],
                    'required_columns' => implode(', ', $spec['required']),
                    // ประกอบข้อความฝั่งนี้เพราะต้องแทนค่า :type และ :size
                    'file_comment' => Language::replace('Upload :type files no larger than :size', [
                        ':type' => 'csv',
                        ':size' => \Kotchasan\Http\UploadedFile::getUploadSize()
                    ])
                ]
            ], 'OK');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    /**
     * POST api/inventory/import/save
     *
     * @param Request $request
     *
     * @return Response
     */
    public function save(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'POST');
            $this->validateCsrfToken($request);

            $login = $this->authenticateRequest($request);
            if (!$login) {
                return $this->errorResponse('Unauthorized', 401);
            }
            if (!ApiController::canModify($login, 'can_manage_inventory')) {
                return $this->errorResponse('Permission required', 403);
            }

            $type = $request->post('type')->filter('a-z');
            $spec = Model::type($type);
            if ($spec === null) {
                return $this->errorResponse('No data available', 404);
            }
            $this->importType = $type;

            $file = null;
            foreach ($request->getUploadedFiles() as $name => $one) {
                if ($name === 'file') {
                    $file = $one;
                }
            }
            if ($file === null || !$file->hasUploadFile()) {
                return $this->formErrorResponse(['file' => Language::get('Please browse file')], 400);
            }
            if (!$file->validFileExt(['csv'])) {
                return $this->formErrorResponse(['file' => Language::get('The type of file is invalid')], 400);
            }

            // ไฟล์ใหญ่ ๆ ใช้เวลานาน อย่าให้ PHP ตัดกลางคัน ข้อมูลจะเข้าไปครึ่งเดียว
            set_time_limit(0);

            try {
                // ไม่ส่งรายชื่อคอลัมน์ให้ Csv::read ตรวจ เพราะมันบังคับให้จำนวนคอลัมน์
                // ต้องเท่ากันเป๊ะ ไฟล์ที่ผู้ใช้เพิ่ม/ลดคอลัมน์เองจะถูกปฏิเสธทั้งไฟล์
                // ตรวจเฉพาะคอลัมน์ที่จำเป็นเองผ่าน callback ก่อนอ่านแถวแรก
                \Kotchasan\Csv::read(
                    $file->getTempFileName(),
                    [$this, 'importRow'],
                    null,
                    empty(self::$cfg->csv_language) ? 'UTF-8' : self::$cfg->csv_language,
                    [$this, 'checkHeader'],
                    $spec['required']
                );
            } catch (\Exception $e) {
                return $this->formErrorResponse(['file' => Language::get($e->getMessage())], 400);
            }

            \Index\Log\Model::add(0, 'inventory', 'Import',
                '{LNG_Import} '.$type.' : '.$this->result['new'].'/'.$this->result['update'], $login->id);

            $message = Language::replace('Successfully imported :new :type, updated :update items.', [
                ':type' => Language::trans($spec['title']),
                ':new' => number_format($this->result['new']),
                ':update' => number_format($this->result['update'])
            ]);
            if ($this->result['skip'] > 0) {
                $message .= ' ('.Language::replace(':error errors', [':error' => number_format($this->result['skip'])]).')';
            }

            return $this->redirectResponse($spec['url'], $message, 200, 1500);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    /**
     * ตรวจหัวตารางของไฟล์ก่อนอ่านแถวแรก
     *
     * ต้องมีคอลัมน์ที่จำเป็นครบ ไม่งั้นบอกให้ชัดว่าขาดอะไร ดีกว่าปล่อยให้นำเข้า
     * จนจบแล้วได้ผลว่า "0 รายการ" โดยไม่รู้สาเหตุ
     *
     * @param array $columns หัวตารางที่อ่านได้จากไฟล์
     * @param array $required คอลัมน์ที่จำเป็น
     *
     * @throws \Exception เมื่อคอลัมน์ไม่ครบ
     *
     * @return void
     */
    public function checkHeader($columns, $required)
    {
        $missing = [];
        foreach ((array) $required as $need) {
            if (!in_array($need, (array) $columns, true)) {
                $missing[] = $need;
            }
        }
        if (!empty($missing)) {
            throw new \Exception('Column not found : '.implode(', ', $missing));
        }
    }

    /**
     * ตัวรับข้อมูลรายแถวจาก Kotchasan\Csv::read()
     *
     * @param array $row
     *
     * @return void
     */
    public function importRow($row)
    {
        $status = Model::importRow($this->importType, (array) $row);
        if (isset($this->result[$status])) {
            ++$this->result[$status];
        }
    }
}
