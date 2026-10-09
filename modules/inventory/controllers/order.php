<?php
/**
 * @filesource modules/inventory/controllers/order.php
 *
 * api/inventory/order/get|save — ฟอร์มเอกสารหนึ่งใบ
 *
 * สั้นกว่าของระบบเดิมมาก เพราะการเดินสต๊อกทั้งหมดอยู่ที่ Inventory\Document\Model
 * แล้ว คอนโทรลเลอร์นี้เหลือหน้าที่เดียวคือ "แปลงสิ่งที่ฟอร์มส่งมาให้เป็นเอกสาร"
 * ระบบเดิมต้องคำนวณเองว่าสินค้าตัวไหนต้องคิดสต๊อกใหม่ (resolveStock) ซึ่งเป็น
 * ที่มาของยอดเพี้ยนเวลาแก้เอกสาร
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Inventory\Order;

use Gcms\Api as ApiController;
use Inventory\Base\Model as Base;
use Inventory\Document\Model as Document;
use Kotchasan\Http\Request;
use Kotchasan\Http\Response;
use Kotchasan\Language;
use Kotchasan\Text;

/**
 * API เอกสาร
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends ApiController
{
    /**
     * GET api/inventory/order/get
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
            if (!ApiController::hasPermission($login, ['can_inventory_order', 'can_manage_inventory'])) {
                return $this->errorResponse('Permission required', 403);
            }

            $id = $request->get('id')->toInt();
            $status = $request->get('status')->filter('A-Z');
            $order = $id > 0 ? \Inventory\Orders\Model::get($id) : self::blank($status);
            if ($order === null) {
                return $this->errorResponse('No data available', 404);
            }
            $order['items'] = $id > 0 ? Document::items($id) : [];

            // ชนิดเอกสารที่เลือกได้ — มาจากแม่แบบในฐานข้อมูล ไม่ได้เขียนไว้ในโค้ด
            // พร้อมแผนที่ว่าชนิดไหนใช้วันครบกำหนด ให้ฟอร์มซ่อนช่องตอนเปลี่ยนชนิดได้เอง
            $documentTypes = [];
            $dueDateMap = [];
            foreach (Document::templates() as $type => $template) {
                $dueDateMap[$type] = empty($template['due_date']) ? 0 : 1;
                if (!empty($template['published'])) {
                    $documentTypes[] = ['value' => $type, 'text' => $template['topic']];
                }
            }
            $template = Document::template((string) $order['document_type']);
            $order['template'] = $template === null ? null : ['topic' => $template['topic']];
            $order['mode'] = $template === null ? 'sell' : $template['mode'];
            // ช่อง hidden รับได้แค่ข้อความ — ส่ง array ไปจะกลายเป็น "[object Object]"
            $order['due_date_map'] = json_encode($dueDateMap);
            $order['vat_rate'] = \Inventory\Cart\Model::vatRate();

            // ⚠️ เอกสารที่ยกมาจากระบบเดิม (created_at ว่าง) มีวันครบกำหนดเท่ากับวันที่เอกสาร
            // จำนวนมาก (ใบเสนอราคา 52 จาก 113 ใบ ในฐานของ app) ชนิดที่แม่แบบไม่ใช้
            // วันครบกำหนดจึงไม่ส่งค่านี้ไป ไม่งั้นเปลี่ยนเป็นชนิดที่ใช้ ช่องจะโผล่มาพร้อมวันที่นั้น
            if (empty($dueDateMap[$order['document_type']])) {
                $order['due_date'] = '';
            }

            // ช่องชื่อลูกค้าเป็น autocomplete — input ที่เห็นถือชื่อ ส่วน hidden ชื่อเดียวกัน
            // ถือ id ที่ส่งไปบันทึก ต้องผูกด้วยคู่ {value, text} ถ้าผูกแค่ชื่อ ตัวจัดการ
            // จะเขียนชื่อลง hidden ด้วย แล้ว customer_id ที่ส่งไปกลายเป็น 0 ทุกครั้งที่บันทึก
            $order['customer_option'] = '';
            if (!empty($order['customer_id'])) {
                $text = empty($order['customer']) ? (string) $order['contactor'] : $order['customer'];
                if (!empty($order['branch'])) {
                    $text .= ' ('.$order['branch'].')';
                }
                $order['customer_option'] = ['value' => (string) $order['customer_id'], 'text' => $text];
            }

            // ปุ่มที่โมดูลอื่นขอมาแทรกในหน้าเอกสารนี้
            //
            // ⚠️ โมดูลนี้ไม่รู้จักโมดูลไหนเป็นพิเศษ — เรียก hook กลางแล้วใครมี
            // ก็ใส่มา (กลไกเดียวกับ initMenus/initDashboard) ถอดโมดูลนั้นออก
            // ปุ่มหายไปเอง · ผลิตภัณฑ์ที่ต้องการแค่ออกเอกสารกับสต๊อก (เช่น oas)
            // จึงใช้ไฟล์ชุดเดียวกันนี้ได้โดยไม่มีปุ่มของโมดูลที่ไม่ได้ติดตั้ง
            //
            // สัญญาของแต่ละรายการ: title · className · url · modal · param_id
            $hookParams = [
                'id' => (int) $order['id'],
                'document_type' => (string) $order['document_type'],
                'mode' => isset($order['mode']) ? $order['mode'] : ''
            ];
            $documentActions = \Gcms\Controller::initModule([], 'initOrderActions', $login, $hookParams);

            return $this->successResponse([
                'data' => $order + ['document_actions' => $documentActions],
                'options' => [
                    'document_type' => $documentTypes,
                    'vat_status' => [
                        ['value' => '0', 'text' => '{LNG_No tax}'],
                        ['value' => '1', 'text' => '{LNG_VAT excluded}'],
                        ['value' => '2', 'text' => '{LNG_VAT included}']
                    ]
                ]
            ], 'OK');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    /**
     * POST api/inventory/order/save
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
            if (!ApiController::canModify($login, 'can_inventory_order')) {
                return $this->errorResponse('Permission required', 403);
            }

            $documentType = $request->post('document_type')->filter('A-Z');
            if (Document::template($documentType) === null) {
                return $this->formErrorResponse(['document_type' => Language::get('Invalid document type')], 400);
            }

            $orderId = $request->post('id')->toInt();
            // "บันทึกแล้วสร้างใหม่" = ออกเอกสารใบใหม่เสมอ ใบที่เปิดอยู่ไม่ถูกแตะ
            // เลขที่และวันที่ของใบใหม่ต้องเป็นของมันเอง ค่าที่ค้างอยู่ในฟอร์มเป็นของใบเดิม
            $saveAndCreate = $request->post('save_and_create')->toInt() === 1;
            // ⚠️ ->date() คืน null เมื่อช่องว่าง ไม่ใช่ '' — เทียบ === '' แล้วไม่เคยจริง
            // วันที่จะกลายเป็น " 12:00:00" ลงฐานข้อมูล
            $orderDate = $saveAndCreate ? null : $request->post('order_date')->date();
            if (empty($orderDate)) {
                $orderDate = date('Y-m-d');
            }

            $header = [
                'document_type' => $documentType,
                'order_no' => $request->post('order_no')->topic(),
                'customer_id' => $request->post('customer_id')->toInt(),
                'order_date' => $orderDate.' '.date('H:i:s'),
                'due_date' => $request->post('due_date')->date(),
                'comment' => $request->post('comment')->textarea(),
                'remark' => $request->post('remark')->textarea(),
                'discount_percent' => $request->post('discount_percent')->toDouble(),
                'discount' => $request->post('total_discount')->toDouble(),
                'vat' => $request->post('vat_total')->toDouble(),
                'vat_status' => $request->post('vat_status')->toInt(),
                'tax' => $request->post('tax_total')->toDouble(),
                'tax_status' => $request->post('tax_status')->toDouble(),
                'subtotal' => $request->post('sub_total')->toDouble(),
                'total' => $request->post('amount')->toDouble()
            ];

            // เอกสารฝั่งซื้อต้องระบุผู้ขายเสมอ (กติกาเดิมของทุกผลิตภัณฑ์)
            if (empty($header['customer_id']) && in_array($documentType, Base::statuses('buy'), true)) {
                return $this->formErrorResponse(['customer_id' => Language::get('Please fill in')], 400);
            }

            list($lines, $errors) = self::collectLines($request, $login, $header['order_date']);
            if (!empty($errors)) {
                return $this->formErrorResponse($errors, 400);
            }

            // ชนิดที่แม่แบบไม่ใช้วันครบกำหนด ช่องถูกซ่อนในฟอร์ม ค่าที่ติดมาไม่ใช่ของใบนี้
            if (empty(Document::template($documentType)['due_date'])) {
                $header['due_date'] = null;
            }

            if ($saveAndCreate) {
                // เปลี่ยนชนิดด้วย = แปลงเอกสาร เช่น ใบเสนอราคา → ใบแจ้งหนี้ ใบใหม่ชี้กลับ
                // ไปหาใบเดิม นี่คือหัวใจของสายเอกสาร
                // ⚠️ ชนิดเดิม = สำเนา ห้ามผูกสาย ใบที่มี source_document_id จะไปปลด
                // การจองของใบต้นทาง (Posting::syncReservedForDocument) สำเนาใบสั่งขาย
                // จึงทำให้ของที่ใบเดิมจองไว้หลุดออกไป
                $current = $orderId > 0 ? \Inventory\Orders\Model::get($orderId) : null;
                if ($current !== null && $current['document_type'] !== $documentType) {
                    $header['source_document_id'] = $orderId;
                    $header['root_document_id'] = empty($current['root_document_id'])
                        ? $orderId : (int) $current['root_document_id'];
                    $header['reference_document_no'] = $current['order_no'];
                }
                $header['order_no'] = '';
                $orderId = 0;
            }

            // ⚠️ เลขที่เอกสารต้องออกก่อนบันทึก และต้องไม่ซ้ำกับใบที่มีอยู่
            if ($header['order_no'] === '') {
                $header['order_no'] = self::nextNumber($documentType);
            }

            $orderId = Document::save($header, $lines, $orderId, $login->id);

            return $this->redirectResponse('/inventory-orders?status='.$documentType,
                Language::get('Saved successfully'), 200, 1000);
        } catch (\Exception $e) {
            // ข้อผิดพลาดที่ชั้นเอกสารโยนมาเป็นเรื่องของสต๊อกหรือแม่แบบเสมอ
            // แสดงไว้ที่หัวฟอร์ม ผู้ใช้จะได้อ่านข้อความเต็ม ๆ
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    /**
     * แปลงบรรทัดรายการที่ฟอร์มส่งมาให้เป็นแถวของ order_items
     *
     * ⚠️ LineItemsManager ส่งมาเป็น items[0][field], items[1][field] ... จึงอ่าน
     * เป็นแถว ไม่ใช่ array แยกรายคอลัมน์แบบฟอร์มเดิมของ Kotchasan
     *
     * @param Request $request
     * @param object  $login
     * @param string  $orderDate
     *
     * @return array [บรรทัดที่พร้อมบันทึก, ข้อผิดพลาดรายช่อง]
     */
    protected static function collectLines(Request $request, $login, $orderDate)
    {
        $rows = $request->post('items', [])->toArray();

        // ⚠️ ห้าม (float) ตรง ๆ กับจำนวนเงินที่มาจากฟอร์ม ช่องเงินแสดงผลแบบมีลูกน้ำ
        // ("4,123.71") ซึ่ง (float) จะได้ 4 เงียบ ๆ — Text::toDouble() ตัดลูกน้ำก่อน
        $money = function ($value) {
            return Text::toDouble(isset($value) ? $value : 0);
        };

        $lines = [];
        $seen = [];
        $errors = [];
        foreach ((array) $rows as $row => $item) {
            if (!is_array($item)) {
                continue;
            }
            $code = isset($item['product_code']) ? Text::topic($item['product_code']) : '';
            if ($code === '' && isset($item['product_no'])) {
                $code = Text::topic($item['product_no']);
            }
            if ($code === '') {
                continue;
            }
            if (isset($seen[$code])) {
                // สินค้าเดิมซ้ำสองบรรทัด ไม่ยอมรับ เพราะสต๊อกจะถูกนับซ้ำ
                $errors['items['.$row.'][product_code]'] = Language::replace('This :name already exist', [
                    ':name' => Language::get('Product code')
                ]);
                continue;
            }
            $seen[$code] = true;

            $quantity = $money(isset($item['quantity']) ? $item['quantity'] : 0);
            $lines[] = [
                'inventory_id' => isset($item['inventory_id']) ? (int) $item['inventory_id'] : 0,
                'inventory_item_id' => isset($item['inventory_item_id']) ? (int) $item['inventory_item_id'] : null,
                'product_code' => $code,
                'product_no' => $code,
                'topic' => isset($item['topic']) ? Text::topic($item['topic']) : '',
                'quantity' => $quantity,
                'unit' => isset($item['unit']) ? Text::topic($item['unit']) : '',
                'price' => $money(isset($item['price']) ? $item['price'] : 0),
                'discount' => $money(isset($item['discount']) ? $item['discount'] : 0),
                'vat' => empty($item['vat']) ? 0 : 1,
                'total' => $money(isset($item['total']) ? $item['total'] : 0),
                // จำนวนที่ตัดจากสต๊อกหลัก = จำนวนที่สั่ง x ตัวคูณของหน่วยย่อย
                'cut_stock' => $quantity * (isset($item['cut_stock']) && $item['cut_stock'] > 0
                    ? $money($item['cut_stock']) : 1),
                'member_id' => $login->id,
                'create_date' => $orderDate
            ];
        }

        return [$lines, $errors];
    }

    /**
     * เลขที่เอกสารถัดไปของชนิดหนึ่ง
     *
     * รูปแบบมาจากค่ากำหนดของไซต์ (<TYPE>_prefix และ <TYPE>_NO) ซึ่งอ่านผ่าน
     * Base::config() จุดเดียว — เมื่อถึงวันที่ทำระบบผู้เช่าจะได้แก้ที่เดียว
     *
     * @param string $documentType
     *
     * @return string
     */
    protected static function nextNumber($documentType)
    {
        // เลขที่เอกสารออกที่เดียว (Document::nextNumber) — ร้านค้าออนไลน์และการแปลง
        // เอกสารใช้ตัวเดียวกัน รูปแบบเลขที่จึงไม่มีทางต่างกันตามทางที่เอกสารเกิด
        return Document::nextNumber($documentType);
    }

    /**
     * ค่าเริ่มต้นของเอกสารใหม่
     *
     * เปิดเป็น public เพราะ Export\Controller::customerAsOrder() ใช้โครงเดียวกันนี้
     * สร้างเอกสารเปล่าสำหรับจ่าหน้าซองถึงลูกค้าโดยตรง (ไม่ผ่าน Model::get())
     *
     * @param string $documentType
     *
     * @return array
     */
    public static function blank($documentType)
    {
        return [
            'id' => 0,
            'document_type' => $documentType,
            'document_status' => 'issued',
            'order_no' => '',
            'customer_id' => 0,
            'customer' => '',
            'customer_no' => '',
            'order_date' => date('Y-m-d'),
            'due_date' => '',
            'discount' => 0,
            'discount_percent' => 0,
            'subtotal' => 0,
            'vat' => 0,
            'vat_status' => 0,
            'tax' => 0,
            'tax_status' => 0,
            'total' => 0,
            'paid' => 0,
            'comment' => '',
            'remark' => '',
            'items' => []
        ];
    }
}
