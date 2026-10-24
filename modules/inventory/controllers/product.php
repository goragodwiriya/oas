<?php
/**
 * @filesource modules/inventory/controllers/product.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Inventory\Product;

use Gcms\Api as ApiController;
use Kotchasan\File;
use Kotchasan\Http\Request;
use Kotchasan\Http\Response;
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

            // id = แบบ (variant) ที่เปิดอยู่ — หน้าย่อยทุกหน้าของสินค้าใช้ id ของแบบ · 0 = สินค้าใหม่
            $id = $request->request('id')->toInt();
            $product = $id > 0 ? self::fromVariant($id) : self::blank();
            if ($product === null) {
                return $this->errorResponse('No data available', 404);
            }

            // ส่งค่า from กลับไปด้วย เพื่อให้ form รู้ว่าต้องเรียกฟังก์ชันไหนเมื่อปิด modal
            $product['from'] = $request->request('from')->text();

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
            // สวิตช์ "แสดงในร้านค้าออนไลน์" มีเฉพาะไซต์ที่มีร้าน (ฟอร์มซ่อนด้วย data-if="shop")
            $product['shop'] = self::hasShop();

            return $this->successResponse([
                'data' => $product,
                'options' => [
                    'category_id' => $categories->toOptions('category_id'),
                    'model_id' => $categories->toOptions('model_id'),
                    'type_id' => $categories->toOptions('type_id'),
                    'unit' => $categories->toOptions('unit', false),
                    'count_stock' => $modes,
                    'vat' => $taxes
                ]
            ]);
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
                'product_code' => $product['product_code'],
                'sku' => $product['sku'],
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

            // id = แบบที่เปิดอยู่ (แก้สินค้าของแบบนั้น) · 0 = สินค้าใหม่พร้อมแบบตั้งต้น
            $id = $request->post('id')->toInt();
            $productId = $id > 0 ? \Inventory\Variants\Model::productId($id) : 0;
            if ($id > 0 && $productId === 0) {
                return $this->errorResponse('No data available', 404);
            }
            // ข้อมูลของสินค้า — ใช้ร่วมกันทุกแบบ (DESIGN.md ข้อ 3.1)
            $data = [
                'product_code' => $request->post('product_code')->topic(),
                'topic' => $request->post('topic')->topic(),
                'category_id' => $request->post('category_id')->topic(),
                'unit' => $request->post('unit')->topic(),
                'vat' => $request->post('vat')->toInt(),
                'allow_negative' => $request->post('allow_negative')->toBoolean() ? 1 : 0,
                'option1_name' => $request->post('option1_name')->topic(),
                'option2_name' => $request->post('option2_name')->topic(),
                'option3_name' => $request->post('option3_name')->topic(),
                'is_active' => $request->post('is_active')->toBoolean() ? 1 : 0,
                'published' => $request->post('published')->toBoolean() ? 1 : 0
            ];
            // ช่องที่ถูกล็อก (disabled) ไม่ถูกส่งมา = ไม่เปลี่ยน — วิธีนับของสินค้าที่มีความเคลื่อนไหวแล้ว
            if ($request->post('count_stock')->exists()) {
                $data['count_stock'] = $request->post('count_stock')->toInt();
            }
            // หน้าต่างเพิ่มสินค้าจากหน้าเอกสารไม่มีช่องหมวดหมู่ย่อย/ตัวเลือก — ไม่ส่งมา = ไม่แตะ
            // published ไม่ส่งมาเมื่อไซต์ไม่มีร้าน (ช่องถูกถอดด้วย data-if) = ไม่แตะ
            foreach (['category_id', 'option1_name', 'option2_name', 'option3_name', 'allow_negative', 'published'] as $_key) {
                if (!$request->post($_key)->exists()) {
                    unset($data[$_key]);
                }
            }

            $errors = [];
            if ($data['topic'] === '') {
                $errors['topic'] = 'Please fill in';
            }
            if ($data['unit'] === '') {
                // หน่วยฐาน = หน่วยที่นับสต๊อกและหน่วยของบาร์โค้ดตั้งต้น (ฟอง · ชิ้น · เครื่อง)
                $errors['unit'] = 'Please fill in';
            }
            if (!empty($errors)) {
                return $this->formErrorResponse($errors, 400);
            }

            $categories = \Inventory\Category\Controller::init(true, true, false);
            foreach (['category_id', 'model_id', 'type_id'] as $_type) {
                if (isset($data[$_type]) && $data[$_type] !== '' && !$categories->exists($_type, $data[$_type])) {
                    $data[$_type] = (string) \Inventory\Category\Controller::save($_type, $data[$_type]);
                }
            }

            if (isset($data['unit']) && $data['unit'] !== '') {
                \Inventory\Category\Controller::save('unit', $data['unit']);
            }

            if ($id > 0) {
                Model::saveProduct($productId, $data);
            } else {
                // สินค้าใหม่ = สินค้า + แบบตั้งต้น + หน่วยขายฐาน (+ ยอดยกมา) ในก้อนเดียว
                $data['sku'] = $request->post('sku')->topic();
                $data['barcode'] = $request->post('barcode')->topic();
                $data['price'] = $request->post('price')->toDouble();
                $stock = $request->post('stock')->toDouble();
                $cost = $request->post('cost')->toDouble();
                $date = $request->post('create_date')->date();
                $id = \Inventory\Base\Model::transaction(function () use ($data, $stock, $cost, $date) {
                    $variantId = Model::addProduct($data);
                    // ยอดยกมาลงสมุดบัญชีพร้อมทุนและวันที่ เป็นชั้นต้นทุนแรก — สินค้ารายชิ้นรับเข้าเป็นเครื่องด้วยใบรับ
                    if ($stock > 0 && Model::countStock($variantId) === 1) {
                        Model::openingBalance($variantId, 0, $stock, $cost, 0, $date === '' ? null : $date.' 00:00:00');
                    }

                    return $variantId;
                });
            }

            // รูปภาพสินค้า
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

            // หน้าต่างซ้อน (จากหน้าเอกสาร) : ปิดหน้าต่างแล้วเติมสินค้าที่เพิ่งบันทึกลงช่องสินค้าของเอกสาร
            if ($request->post('modal')->toInt() === 1) {
                $product = Model::get($id);
                if ($product === null) {
                    return $this->errorResponse('No data available', 404);
                }
                return $this->successResponse([
                    'actions' => [
                        [
                            'type' => 'notification',
                            'level' => 'success',
                            'message' => 'Saved successfully'
                        ],
                        [
                            'type' => 'modal',
                            'action' => 'close'
                        ],
                        [
                            'type' => 'callback',
                            'function' => 'setInventoryProduct',
                            // sku ของแบบ — ยิงแล้วได้หน่วยขายฐานของแบบนั้นตรงตัว (DESIGN.md ข้อ 4)
                            'args' => [[
                                'product_code' => (string) $product['sku']
                            ]]
                        ]
                    ]
                ], 'Saved successfully');
            }

            // สินค้าใหม่ → หน้าของสินค้านั้น (เพิ่มแบบอื่น/หน่วยขายต่อได้) · "บันทึกแล้วสร้างใหม่" → ฟอร์มเปล่า
            if ($request->post('id')->toInt() === 0) {
                return $this->redirectResponse($request->post('save_and_create')->toBoolean() ? '/inventory-write'
                    : '/inventory-write?id='.$id, Language::get('Saved successfully'), 200, 1000);
            }

            return $this->redirectResponse('reload', Language::get('Saved successfully'), 200, 1000);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    /**
     * ข้อมูลของฟอร์มสินค้าจากแบบที่เปิดอยู่ — ค่าของสินค้า (ใช้ร่วมทุกแบบ) + รหัสของแบบนี้
     *
     * @param int $variantId
     *
     * @return array|null
     */
    protected static function fromVariant($variantId)
    {
        $variant = Model::get($variantId);
        if ($variant === null) {
            return null;
        }
        $product = self::blank();
        foreach (array_keys($product) as $key) {
            if (array_key_exists($key, $variant) && $variant[$key] !== null) {
                $product[$key] = $variant[$key];
            }
        }
        $product['id'] = (int) $variantId;
        $product['product_id'] = (int) $variant['product_id'];
        $product['topic'] = (string) $variant['product_topic'];
        $product['variant_topic'] = (string) $variant['topic'];
        $product['is_active'] = (int) $variant['product_is_active'];
        $product['count_stock'] = (int) $variant['count_stock'];
        // วิธีนับและหน่วยฐานเปลี่ยนไม่ได้เมื่อมีความเคลื่อนไหวแล้ว — ฟอร์มล็อกช่องไว้ให้เห็นก่อนกดบันทึก
        $product['has_movement'] = Model::hasMovement($product['product_id']);

        return $product;
    }

    /**
     * ไซต์นี้มีร้านค้าออนไลน์หรือไม่ — มีโมดูล ecommerce และเปิดใช้ในแพ็กเกจของไซต์ (oms.in.th · Gcms\Tenant)
     *
     * @return bool
     */
    public static function hasShop()
    {
        if (!is_dir(ROOT_PATH.'modules/ecommerce')) {
            return false;
        }

        return !class_exists('\Gcms\Tenant\Modules') || \Gcms\Tenant\Modules::isEnabled('ecommerce');
    }

    /**
     * ค่าเริ่มต้นของสินค้าใหม่ — ข้อมูลสินค้า + แบบตั้งต้น (sku · บาร์โค้ด · ราคา · ยอดยกมา)
     *
     * @return array
     */
    protected static function blank()
    {
        return [
            'id' => 0,
            'product_id' => 0,
            'product_code' => '',
            'topic' => '',
            'variant_topic' => '',
            'category_id' => '',
            'model_id' => '',
            'type_id' => '',
            'unit' => '',
            'count_stock' => 1,
            'vat' => 0,
            'allow_negative' => 0,
            'option1_name' => '',
            'option2_name' => '',
            'option3_name' => '',
            'is_active' => 1,
            // ไม่แสดงในร้านจนกว่าจะเปิดเอง (DESIGN.md ข้อ 3.1)
            'published' => 0,
            'has_movement' => false,
            'sku' => '',
            'barcode' => '',
            'price' => 0,
            'cost' => 0,
            'stock' => 0,
            'create_date' => date('Y-m-d')
        ];
    }
}
