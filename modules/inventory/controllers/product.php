<?php
/**
 * @filesource modules/inventory/controllers/product.php
 *
 * api/inventory/product/get|save|delete — ฟอร์มสินค้า/บริการ
 *
 * ⚠️ สัญญากับหน้าเว็บ (MODULE_DEVELOPMENT_GUIDE §4)
 *   get()  ต้องคืน successResponse(['data' => $record, 'options' => [...]])
 *          ค่าของฟอร์มอ่านจาก data.data และตัวเลือกอ่านจาก data.options[<ชื่อ field>]
 *          **ห้ามทำให้แบน** ฟอร์มจะโหลดขึ้นมาว่างเปล่าโดยไม่มีข้อความเตือน
 *   save() ผิดพลาดใช้ formErrorResponse($errors, 400) โดยคีย์ของ errors ต้องตรง
 *          กับ name ของช่อง สำเร็จใช้ redirectResponse()
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Inventory\Product;

use Gcms\Api as ApiController;
use Kotchasan\Http\Request;
use Kotchasan\Http\Response;
use Kotchasan\File;
use Kotchasan\Language;

/**
 * API สินค้า
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends ApiController
{
    /**
     * GET api/inventory/product/get
     *
     * @param Request $request
     *
     * @return Response
     */
    public function get(Request $request)
    {
        try {
            // ⚠️ รับได้ทั้ง GET และ POST — ปุ่มที่เปิดหน้าต่างซ้อนด้วย data-modal-api
            // ยิงมาเป็น POST เสมอ (Now/js/ModalDataBinder.js : loadModalFromApi ใช้
            // client.post) ส่วนปุ่มแก้ไขในแถวของตารางยิงมาเป็น GET การบังคับ GET
            // อย่างเดียวทำให้ปุ่มเพิ่มได้ 405 Method not allowed แล้วกดไม่ขึ้นทั้งปุ่ม
            if (!in_array($request->getMethod(), ['GET', 'POST'], true)) {
                return $this->errorResponse('Method not allowed', 405);
            }
            $login = $this->authenticateRequest($request);
            if (!$login) {
                return $this->errorResponse('Unauthorized', 401);
            }
            if (!ApiController::hasPermission($login, 'can_manage_inventory')) {
                return $this->errorResponse('Permission required', 403);
            }

            $id = $request->request('id')->toInt();
            $product = $id > 0 ? Model::get($id) : self::blank();
            if ($product === null) {
                return $this->errorResponse('No data available', 404);
            }

            $categories = \Inventory\Category\Controller::init();

            $modes = [];
            foreach (\Inventory\Inventories\Model::countStockModes() as $value => $text) {
                $modes[] = ['value' => (string) $value, 'text' => $text];
            }
            $taxes = [];
            foreach (\Inventory\Base\Model::taxStatuses() as $value => $text) {
                $taxes[] = ['value' => (string) $value, 'text' => $text];
            }

            // หมวดหมู่ส่งเป็น {value, text} ช่องข้อความจึงแสดงชื่อได้เสมอ
            // สินค้าที่ยังไม่ได้ระบุหมวดหมู่ต้องเห็นเป็นช่องว่าง ไม่ใช่เลข 0
            $product['category_id'] = [
                'value' => (string) $product['category_id'],
                'text' => $categories->get('category_id', $product['category_id'], '')
            ];
            // รุ่นและประเภทเป็นหมวดหมู่เหมือน category_id — ระบบเดิมของ inventory
            // ใช้สามช่องนี้คู่กันเสมอ ก่อนหน้านี้คืนมาเป็นเลขดิบจนฟอร์มแสดงชื่อไม่ได้
            foreach (['model_id', 'type_id'] as $_key) {
                $product[$_key] = [
                    'value' => (string) $product[$_key],
                    'text' => $categories->get($_key, $product[$_key], '')
                ];
            }
            // รูปของพัสดุ/สินค้า เก็บที่ datas/inventory/{id}.{ext} เหมือนระบบเดิม
            $product['image'] = $id > 0 ? \Inventory\Detail\Model::imageUrl($id) : null;

            return $this->successResponse([
                'data' => $product,
                'options' => [
                    'category_id' => $categories->toOptions('category_id'),
                    'model_id' => $categories->toOptions('model_id'),
                    'type_id' => $categories->toOptions('type_id'),
                    // ค่าของหน่วยนับคือ "ชื่อ" เพราะคอลัมน์ unit เก็บข้อความ
                    // ⚠️ toOptions($type, false) คืนอาเรย์ที่ยังคงคีย์เดิม ต้อง reindex
                    // ไม่งั้นกลายเป็น object ใน JSON ไม่ใช่ list
                    'unit' => array_values($categories->toOptions('unit', false)),
                    'count_stock' => $modes,
                    'vat' => $taxes
                ]
            ], 'OK');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    /**
     * GET api/inventory/product/page — หัวเรื่องของหน้าย่อยในกลุ่มสินค้า
     *
     * หน้าย่อย (ภาพรวม / บาร์โค้ด / รายละเอียด / สต๊อก) ต้องขึ้นชื่อสินค้าที่กำลัง
     * ดูอยู่เหมือนกันทุกหน้า จึงอ่านจากที่เดียวแทนที่จะให้แต่ละหน้าไปดึงเอง
     *
     * @param Request $request
     *
     * @return Response
     */
    public function page(Request $request)
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
            $product = Model::get($id);
            if ($product === null) {
                return $this->errorResponse('No data available', 404);
            }

            return $this->successResponse([
                'id' => (int) $product['id'],
                'topic' => $product['topic'],
                'product_no' => $product['product_no'],
                'product_code' => $product['product_code'],
                'count_stock' => (int) $product['count_stock'],
                // ปุ่มปรับยอดสต๊อกในแท็บสต๊อกอ่านค่านี้ — ซ่อนปุ่มที่กดแล้วถูกปฏิเสธแน่ ๆ
                // (ฝั่งเซิร์ฟเวอร์ยังตรวจซ้ำเองที่ stocks/adjust อยู่ดี)
                'can_adjust_stock' => (int) $product['count_stock'] > 0
                    && ApiController::hasPermission($login, 'can_manage_inventory')
            ], 'OK');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    /**
     * GET api/inventory/product/modal — ฟอร์มสินค้าในรูปหน้าต่างซ้อน
     *
     * ใช้จากหน้าเอกสาร ตอนที่ผู้ใช้พิมพ์รหัสสินค้าที่ยังไม่มีในทะเบียน จะได้
     * เพิ่มสินค้าแล้วทำเอกสารต่อได้เลย ไม่ต้องออกจากหน้าที่กำลังทำอยู่
     *
     * @param Request $request
     *
     * @return Response
     */
    public function modal(Request $request)
    {
        $response = $this->get($request);

        // ⚠️ ต้องตอบ action ชนิด modal พร้อมชื่อแม่แบบด้วย ไม่ใช่คืนแค่ข้อมูล
        // ฝั่งเบราว์เซอร์ไม่รู้จะเปิดไฟล์ไหน ปุ่มจึงกดแล้วเงียบ ไม่มีข้อผิดพลาดให้เห็น
        return self::withModal($response, 'inventory/product-modal.html',
            Language::get('Add').' '.Language::get('Product'), 'icon-product');
    }

    /**
     * ใส่ action เปิดหน้าต่างซ้อนลงในคำตอบที่ get() สร้างไว้แล้ว
     *
     * แกะ JSON ที่ successResponse ประกอบไว้แล้วเติม actions เข้าไป เพื่อไม่ต้อง
     * เขียนตรรกะของ get() ซ้ำอีกชุด (สองชุดจะค่อย ๆ ต่างกันเอง)
     *
     * @param Response $response
     * @param string   $template
     * @param string   $title
     * @param string   $titleClass
     *
     * @return Response
     */
    protected static function withModal($response, $template, $title, $titleClass)
    {
        $body = json_decode((string) $response->getBody(), true);
        if (!is_array($body) || empty($body['success']) || !isset($body['data'])) {
            return $response;
        }

        $body['data']['actions'] = [
            [
                'type' => 'modal',
                'action' => 'show',
                'template' => $template,
                'title' => $title,
                'titleClass' => $titleClass
            ]
        ];

        return Response::create(json_encode($body), 200,
            ['Content-Type' => 'application/json; charset=utf-8']);
    }

    /**
     * POST api/inventory/product/save
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
            if (!ApiController::hasPermission($login, 'can_manage_inventory')) {
                return $this->errorResponse('Permission required', 403);
            }

            $id = $request->post('id')->toInt();

            // ⚠️ ใส่เฉพาะช่องที่ฟอร์ม "ส่งมาจริง"
            //
            // $request->post('price') คืน 0 เสมอเมื่อไม่ได้ส่งมา ถ้าใส่ลง $data
            // ทุกช่องตายตัว ฟอร์มที่ตัดช่องราคาออกไป (ทะเบียนพัสดุที่ไม่มีการขาย)
            // จะล้างราคา/ต้นทุน/ภาษีของรายการเป็น 0 ทุกครั้งที่กดบันทึก
            // Product\Model::updateProduct() เขียนเฉพาะคีย์ที่มีใน $data
            $submitted = (array) $request->getParsedBody();
            $data = [];
            foreach ([
                'product_code' => 'topic',
                'topic' => 'topic',
                'description' => 'textarea',
                'category_id' => 'topic',
                'model_id' => 'topic',
                'type_id' => 'topic',
                'unit' => 'topic',
                'price' => 'toDouble',
                'cost' => 'toDouble',
                'vat' => 'toInt',
                'count_stock' => 'toInt',
                'allow_negative' => 'toInt',
                'is_active' => 'toInt'
            ] as $_field => $_filter) {
                // ช่องติ๊กที่ไม่ได้ติ๊กจะไม่ถูกส่งมาเลย จึงต้องถือว่า "ส่งมาแล้วเป็น 0"
                // เมื่อฟอร์มมีช่องนั้นอยู่ ดูจากช่อง id ที่ทุกฟอร์มส่งมาเสมอ
                if (!array_key_exists($_field, $submitted)) {
                    if ($_field !== 'is_active' && $_field !== 'allow_negative') {
                        continue;
                    }
                }
                $data[$_field] = $request->post($_field)->{$_filter}();
            }
            if ($id === 0 && !isset($data['topic'])) {
                $data['topic'] = '';
            }

            $errors = [];
            if ($data['topic'] === '') {
                $errors['topic'] = Language::get('Please fill in');
            }
            if (!empty($errors)) {
                return $this->formErrorResponse($errors, 400);
            }

            // หมวดหมู่ · ยี่ห้อ · ประเภท เป็นช่อง autocomplete ที่ส่งค่ามาในชื่อ
            // เดียวกันทั้งสองแบบ — id ของรายการที่เลือกจากรายการ หรือ "ชื่อ" ที่
            // ผู้ใช้พิมพ์เองเมื่อไม่ได้เลือก (Now.js ใส่ข้อความที่พิมพ์ลงในค่าที่ส่ง)
            //
            // ⚠️ ชื่อที่ยังไม่มีต้องสร้างหมวดหมู่ให้ก่อนเสมอ ถ้าเขียนลงตารางตรง ๆ
            // ข้อความจะไปอยู่ในคอลัมน์ varchar(10) ที่เก็บ id (ชื่อยาวกว่านั้นถูก
            // ตัดทิ้ง) แล้วหน้าฟอร์มจะแสดงช่องว่างเพราะหา id นั้นไม่เจอ
            //
            // อ่านสดไม่เอาแคช — แคชคิวรีของแกนอายุสั้นแต่ก็ยังคลาดกันได้
            // ถ้าอ่านรายการเก่า id ที่เพิ่งถูกสร้างจะถูกมองว่า "ยังไม่มี"
            // ⚠️ ต้องเช็ค isset ด้วย ฟอร์มนี้ใส่ใน $data เฉพาะช่องที่ส่งมาจริง
            $categories = \Inventory\Category\Controller::init(true, true, false);
            foreach (['category_id', 'model_id', 'type_id'] as $_type) {
                if (isset($data[$_type]) && $data[$_type] !== '' && !$categories->exists($_type, $data[$_type])) {
                    $data[$_type] = (string) \Inventory\Category\Controller::save($_type, $data[$_type]);
                }
            }

            // หน่วยนับต่างจากสามช่องบน — เก็บเป็น "ชื่อ" ลงคอลัมน์ unit ตรง ๆ
            // (toOptions('unit', false) ใช้ชื่อเป็นทั้ง value และ text) ชื่อใหม่จึง
            // บันทึกได้อยู่แล้วไม่พัง แต่ถ้าไม่จดไว้ รายการแนะนำจะไม่โตตามของจริง
            // ทิ้งค่าที่คืนมาเพราะคอลัมน์เก็บชื่อไม่ใช่ id และ save() คืน id เดิม
            // เมื่อชื่อซ้ำ จึงเรียกได้ทุกครั้งโดยไม่เกิดหมวดหมู่ซ้ำ
            if (isset($data['unit']) && $data['unit'] !== '') {
                \Inventory\Category\Controller::save('unit', $data['unit']);
            }

            if ($id > 0) {
                Model::updateProduct($id, $data);
            } else {
                $id = Model::createProduct($data);
                // ยอดยกมาของรายการใหม่ — บันทึกเป็นการเคลื่อนไหวชนิด opening
                // ในสมุดบัญชี ไม่ใช่เขียนทับคอลัมน์ stock ตรง ๆ ยอดคงเหลือจึงมี
                // ที่มาที่ตรวจสอบได้ตั้งแต่แถวแรกของสินค้า
                $stock = $request->post('stock')->toDouble();
                if ($stock != 0 && isset($data['count_stock']) && $data['count_stock'] > 0) {
                    $date = $request->post('create_date')->date();
                    Model::openingBalance($id, 0, $stock, isset($data['cost']) ? $data['cost'] : 0);
                    if ($date !== '') {
                        \Kotchasan\Model::createDB()->update(
                            \Inventory\Base\Model::table('inventory_stock_movement'),
                            [['inventory_id', $id], ['movement_type', 'opening']],
                            ['occurred_at' => $date.' 00:00:00']
                        );
                    }
                }
            }

            // รูปภาพ — อยู่ในฟอร์มเดียวกับข้อมูลพัสดุ ไม่ต้องเข้าหน้าย่อยอีกหน้า
            //
            // ⚠️ ต้องทำหลังบันทึกเสร็จเสมอ เพราะชื่อไฟล์ใช้ id ของรายการ
            // รายการใหม่จึงต้องรู้ id ก่อน (Detail\Model::imageName($id))
            // ไม่ส่งไฟล์มาก็ไม่ทำอะไร ผลิตภัณฑ์ที่เก็บรูปไว้ในหน้าย่อยจึงไม่กระทบ
            foreach ($request->getUploadedFiles() as $_name => $_file) {
                if ($_name !== 'image') {
                    continue;
                }
                if ($_file->hasUploadFile()) {
                    $_dir = \Inventory\Detail\Model::imageDir();
                    if (!File::makeDirectory($_dir)) {
                        return $this->formErrorResponse([
                            'image' => Language::replace(
                                'Directory %s cannot be created or is read-only.',
                                DATA_FOLDER.'inventory/'
                            )
                        ], 400);
                    }
                    try {
                        $_file->resizeImage(
                            self::$cfg->img_typies,
                            $_dir,
                            \Inventory\Detail\Model::imageName($id),
                            self::$cfg->stored_img_size
                        );
                    } catch (\Exception $_exc) {
                        return $this->formErrorResponse(['image' => Language::get($_exc->getMessage())], 400);
                    }
                } elseif ($_file->hasError()) {
                    return $this->formErrorResponse(['image' => Language::get($_file->getErrorMessage())], 400);
                }
            }

            return $this->redirectResponse('/inventory-setup', Language::get('Saved successfully'), 200, 1000);
        } catch (\Exception $e) {
            // ข้อผิดพลาดที่โมเดลโยนมาเป็นเรื่องของช่องใดช่องหนึ่งเสมอ (รหัสซ้ำ ชื่อว่าง)
            // ส่งกลับเป็น error ของช่องนั้น ผู้ใช้จะได้เห็นตรงจุดที่ต้องแก้
            return $this->formErrorResponse(['product_code' => $e->getMessage()], 400);
        }
    }

    /**
     * ค่าเริ่มต้นของสินค้าใหม่
     *
     * @return array
     */
    protected static function blank()
    {
        return [
            'id' => 0,
            'product_code' => '',
            'topic' => '',
            'description' => '',
            'category_id' => '',
            'unit' => '',
            'price' => 0,
            'cost' => 0,
            'vat' => 0,
            'count_stock' => 1,
            'allow_negative' => 0,
            'is_active' => 1,
            'balance' => 0,
            'items' => []
        ];
    }
}
