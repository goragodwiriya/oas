<?php
/**
 * @filesource modules/inventory/controllers/template.php
 *
 * api/inventory/template/get|save — ฟอร์มแม่แบบเอกสาร
 *
 * ค่าที่ตั้งในหน้านี้เป็นตัวกำหนดพฤติกรรมของเอกสารทั้งชนิด
 *   in_stock / cut_stock     เอกสารชนิดนี้แตะสต๊อกไหมและแตะทางไหน
 *   movement_type            การเคลื่อนไหวที่สร้างชื่อว่าอะไร (ทะเบียนกลาง 4.6)
 *   prefix / number_format   รูปแบบเลขที่เอกสารของชนิดนี้
 *   columns                  คอลัมน์ของตารางรายการตอนพิมพ์
 *
 * ⚠️ เลขที่เอกสารเคยเป็นคีย์แบนใน settings/config.php (`PO_prefix` `PO_NO`) และ
 * ตั้งจากหน้าตั้งค่าโมดูล — ชนิดเอกสารที่ไซต์เพิ่มเองจึงตั้งเลขที่ไม่ได้เลย
 * เพราะไม่มีใครไปเขียนคีย์ให้ในไฟล์ ตอนนี้อยู่ในแถวเดียวกับชนิดนั้นแล้ว
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Inventory\Template;

use Gcms\Api as ApiController;
use Inventory\Base\Model as Base;
use Inventory\Document\Model as Document;
use Inventory\Export\Controller as Export;
use Kotchasan\Http\Request;
use Kotchasan\Http\Response;
use Kotchasan\Language;

/**
 * API แม่แบบเอกสาร
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends ApiController
{
    /**
     * GET api/inventory/template/get
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

            $id = $request->get('id')->toInt();
            $row = $id > 0
                ? \Kotchasan\Model::createDB()->first(Base::table('inventory_template'), ['id' => $id])
                : null;
            if ($id > 0 && !$row) {
                return $this->errorResponse('No data available', 404);
            }
            $template = $row ? (array) $row : self::blank();
            // ช่องเลือกคอลัมน์เป็นแบบเลือกได้หลายค่า จึงต้องส่งเป็นอาเรย์
            // (ในฐานข้อมูลเก็บเป็นข้อความคั่นด้วย , เพราะเป็นรายการสั้นและคงที่)
            $template['columns'] = Export::templateColumns($template);

            // ชนิดการเคลื่อนไหวที่เลือกได้ แยกตามทิศทาง เพื่อไม่ให้ตั้งค่าที่ขัดกันเอง
            $movements = [['value' => '', 'text' => '{LNG_Do not print}']];
            foreach (Base::movementTypes() as $type => $direction) {
                $movements[] = [
                    'value' => $type,
                    'text' => $type.' ('.($direction === 'in' ? '{LNG_Stock in}' : '{LNG_Stock out}').')'
                ];
            }

            return $this->successResponse([
                'data' => $template,
                'options' => [
                    'mode' => [
                        ['value' => 'buy', 'text' => '{LNG_Purchase}'],
                        ['value' => 'sell', 'text' => '{LNG_Sales}']
                    ],
                    'movement_type' => $movements,
                    'columns' => self::columnOptions()
                ]
            ], 'OK');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    /**
     * POST api/inventory/template/save
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

            $id = $request->post('id')->toInt();
            $documentType = $request->post('document_type')->filter('A-Z0-9');
            $inStock = $request->post('in_stock')->toInt();
            $cutStock = $request->post('cut_stock')->toInt();
            $movementType = $request->post('movement_type')->filter('a-z_');
            $numberFormat = $request->post('number_format')->topic();

            $known = Export::columnLabels();
            $columns = [];
            foreach ($request->post('columns', [])->toArray() as $_column) {
                $_column = is_string($_column) ? trim($_column) : '';
                if ($_column !== '' && isset($known[$_column]) && !in_array($_column, $columns, true)) {
                    $columns[] = $_column;
                }
            }

            $errors = [];
            if ($documentType === '') {
                $errors['document_type'] = Language::get('Please fill in');
            }
            if ($inStock && $cutStock) {
                // เอกสารใบเดียวรับเข้าและตัดออกพร้อมกันไม่ได้ ทิศทางต้องมีคำตอบเดียว
                $errors['cut_stock'] = Language::get('Please select only one');
            }
            // ⚠️ รูปแบบที่ไม่มีตัวแทนตัวเลขทำให้ทุกใบได้เลขที่เหมือนกันหมด
            // Number::printf() คืนข้อความเดิมกลับมาโดยไม่มีอะไรผิดพลาดให้เห็น
            if ($numberFormat !== '' && strpos($numberFormat, '%') === false) {
                $errors['number_format'] = Language::get('Invalid value');
            }
            if (empty($columns)) {
                $errors['columns'] = Language::get('Please fill in');
            }
            $direction = $inStock ? 'in' : ($cutStock ? 'out' : '');
            if ($movementType !== '' && $direction !== '' && Base::movementDirection($movementType) !== $direction) {
                $errors['movement_type'] = Language::get('Invalid value');
            }
            if (!empty($errors)) {
                return $this->formErrorResponse($errors, 400);
            }

            $db = \Kotchasan\Model::createDB();
            $table = Base::table('inventory_template');
            $exists = $db->first($table, ['document_type' => $documentType]);
            if ($exists && (int) $exists->id !== $id) {
                return $this->formErrorResponse([
                    'document_type' => Language::replace('This :name already exist', [
                        ':name' => Language::get('Select document type')
                    ])
                ], 400);
            }

            $data = [
                'document_type' => $documentType,
                // ชื่อเดิมของชนิดเอกสารยังต้องเขียนคู่กันไว้ ระบบเดิมยังอ่านอยู่
                'status' => mb_substr($documentType, 0, 3),
                'mode' => $request->post('mode')->filter('a-z'),
                'topic' => $request->post('topic')->topic(),
                'comment' => $request->post('comment')->textarea(),
                'due_date' => $request->post('due_date')->toInt(),
                'signature_1' => $request->post('signature_1')->topic(),
                'signature_2' => $request->post('signature_2')->topic(),
                'signature_3' => $request->post('signature_3')->topic(),
                'published' => $request->post('published')->toInt(),
                'in_stock' => $inStock,
                'cut_stock' => $cutStock,
                'movement_type' => $movementType === '' ? null : $movementType,
                // เลขที่เอกสารเป็นของชนิดนี้ชนิดเดียว ไม่ใช่ค่าของทั้งระบบ
                // prefix ผ่าน Kotchasan\Number::printf() ก่อน จึงรับ token %Y %M ได้
                'prefix' => $request->post('prefix')->topic(),
                'number_format' => $numberFormat === '' ? '%04d' : $numberFormat,
                // เก็บเฉพาะชื่อคอลัมน์ที่พิมพ์ได้จริง ชื่อที่ไม่รู้จักจะถูกพิมพ์เป็น
                // ตัวเลขเงินเปล่า ๆ โดยไม่มีอะไรฟ้อง (ดู Export::columnLabels)
                'columns' => implode(',', $columns)
            ];

            if ($id > 0) {
                $db->update($table, ['id', $id], $data);
            } else {
                $id = (int) $db->insert($table, $data);
            }
            // แคชแม่แบบอยู่ในหน่วยความจำของคำขอนี้ ต้องล้างหลังแก้ ไม่งั้นเมนูและ
            // พฤติกรรมสต๊อกในคำขอเดียวกันจะยังเป็นค่าเก่า
            Document::clearCache();

            return $this->redirectResponse('/inventory-templates', Language::get('Saved successfully'), 200, 1000);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    /**
     * คอลัมน์ของตารางรายการที่เลือกได้
     *
     * ⚠️ มาจากทะเบียนเดียวกับที่หน้าพิมพ์ใช้จริง (Export::columnLabels) — เดิม
     * ให้เลือก "ไฟล์แม่แบบ" ซึ่งทั้งห้าไฟล์มีคอลัมน์ชุดเดียวกันทุกไบต์ เลือกอันไหน
     * เอกสารก็ออกมาเหมือนกันหมด และ productno ที่หน้าพิมพ์รองรับกลับไม่มีให้เลือก
     *
     * @return array
     */
    protected static function columnOptions()
    {
        $result = [];
        foreach (Export::columnLabels() as $value => $text) {
            $result[] = ['value' => $value, 'text' => $text];
        }

        return $result;
    }

    /**
     * ค่าเริ่มต้นของแม่แบบใหม่
     *
     * @return array
     */
    protected static function blank()
    {
        return [
            'id' => 0,
            'document_type' => '',
            'status' => '',
            'mode' => 'sell',
            'topic' => '',
            'comment' => '',
            'due_date' => 0,
            'signature_1' => '',
            'signature_2' => '',
            'signature_3' => '',
            'published' => 1,
            'in_stock' => 0,
            'cut_stock' => 0,
            'movement_type' => '',
            'prefix' => '',
            'number_format' => '%04d',
            'columns' => Export::defaultColumns()
        ];
    }
}
