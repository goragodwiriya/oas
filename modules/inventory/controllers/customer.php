<?php
/**
 * @filesource modules/inventory/controllers/customer.php
 *
 * api/inventory/customer/get|save|modal — ฟอร์มลูกค้า/ผู้ขาย
 *
 * ฟอร์มนี้เปิดได้สองทาง : จากหน้ารายชื่อลูกค้า และจากหน้าเอกสาร (หน้าต่างซ้อน)
 * จึงต้องคืนข้อมูลรูปเดียวกันทั้งสองทาง ต่างกันแค่ปลายทางหลังบันทึก
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Inventory\Customer;

use Gcms\Api as ApiController;
use Inventory\Customers\Model as Customers;
use Kotchasan\Http\Request;
use Kotchasan\Http\Response;
use Kotchasan\Language;

/**
 * API ลูกค้า
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends ApiController
{
    /**
     * GET api/inventory/customer/get
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
            if (!ApiController::hasPermission($login, ['can_inventory_order', 'can_manage_inventory'])) {
                return $this->errorResponse('Permission required', 403);
            }

            $id = $request->request('id')->toInt();
            $type = $request->request('type')->filter('a-z');
            $customer = $id > 0 ? Customers::get($id) : self::blank($type);
            if ($customer === null) {
                return $this->errorResponse('No data available', 404);
            }
            // เมนูแยกลูกค้ากับคู่ค้า แต่ที่อยู่ของหน้านี้มีแค่ id — ใช้แท็บที่เปิดมาถ้าระเบียนเป็นชนิดนั้นจริง
            // ไม่งั้นดูจากธงของระเบียน (เป็นทั้งสองอย่างได้ จึงถือว่าเป็นลูกค้าก่อน)
            $menuType = in_array($type, ['customer', 'supplier'], true) && !empty($customer['is_'.$type])
                ? $type
                : (empty($customer['is_customer']) && !empty($customer['is_supplier']) ? 'supplier' : 'customer');
            $customer['menu_path'] = '/inventory-customers?type='.$menuType;

            // ส่งค่า from กลับไปด้วย เพื่อให้ form รู้ว่าต้องเรียกฟังก์ชันไหนเมื่อปิด modal
            $customer['from'] = $request->request('from')->text();

            return $this->successResponse([
                'data' => $customer
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    /**
     * GET api/inventory/customer/modal — ฟอร์มเดียวกันในรูปหน้าต่างซ้อน
     *
     * ใช้จากหน้าเอกสาร ตอนที่ผู้ใช้พิมพ์ชื่อลูกค้าแล้วยังไม่มีในทะเบียน
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
        $body = json_decode((string) $response->getBody(), true);
        if (!is_array($body) || empty($body['success']) || !isset($body['data'])) {
            return $response;
        }

        $id = $request->request('id')->toInt();
        $body['data']['actions'] = [
            [
                'type' => 'modal',
                'action' => 'show',
                'template' => 'inventory/customer-modal.html',
                'title' => Language::get($id > 0 ? 'Edit' : 'Add').' '.Language::get('Customer'),
                'titleClass' => 'icon-customer'
            ]
        ];

        return Response::create(json_encode($body), 200,
            ['Content-Type' => 'application/json; charset=utf-8']);
    }

    /**
     * POST api/inventory/customer/save
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
            if (!ApiController::canModify($login, ['can_inventory_order', 'can_manage_inventory'])) {
                return $this->errorResponse('Permission required', 403);
            }

            $id = $request->post('id')->toInt();
            $data = [
                'bank' => $request->post('bank')->topic(),
                'bank_name' => $request->post('bank_name')->topic(),
                'bank_no' => $request->post('bank_no')->topic(),
                'customer_no' => $request->post('customer_no')->topic(),
                'discount' => $request->post('discount')->toDouble(),
                'company' => $request->post('company')->topic(),
                'branch' => $request->post('branch')->topic(),
                'name' => $request->post('name')->topic(),
                'contact' => $request->post('contact')->topic(),
                'tax_id' => $request->post('tax_id')->number(),
                'fax' => $request->post('fax')->topic(),
                'email' => $request->post('email')->email(),
                'address' => $request->post('address')->topic(),
                'zipcode' => $request->post('zipcode')->number(),
                'website' => $request->post('website')->url(),
                'is_customer' => $request->post('is_customer')->toBoolean(),
                'is_supplier' => $request->post('is_supplier')->toBoolean(),
                'is_active' => $request->post('is_active')->toBoolean(),
                'invoice_due_date' => $request->post('invoice_due_date')->toInt(),
                'expense_due_date' => $request->post('expense_due_date')->toInt()
            ];

            $errors = [];
            if ($data['company'] === '') {
                $errors['company'] = 'Please fill in';
            }
            if ($data['address'] === '') {
                $errors['address'] = 'Please fill in';
            }

            if (!empty($errors)) {
                return $this->formErrorResponse($errors, 400);
            }

            $db = \Kotchasan\Model::createDB();

            // รหัสลูกค้าต้องไม่ซ้ำ (คอลัมน์นี้เป็น UNIQUE) ถ้าไม่กรอกให้ออกให้เอง
            if ($data['customer_no'] === '') {
                $data['customer_no'] = Customers::nextNumber();
            }
            $exists = $db->first('customer', ['customer_no', $data['customer_no']]);
            if ($exists && (int) $exists->id !== $id) {
                $errors['customer_no'] = Language::replace('This :name already exist', [':name' => Language::get('Customer code')]);
                return $this->formErrorResponse($errors, 400);
            }

            $data['updated_at'] = date('Y-m-d H:i:s');
            $id = \Inventory\Base\Model::transaction(function () use ($db, $id, $data) {
                if ($id > 0) {
                    $data['updated_at'] = date('Y-m-d H:i:s');
                    $db->update('customer', ['id', $id], $data);
                } else {
                    $data['created_at'] = date('Y-m-d H:i:s');
                    $id = (int) $db->insert('customer', $data);
                }

                return $id;
            });

            // หน้าต่างซ้อน (จากหน้าเอกสาร) : ปิดหน้าต่างแล้วเติมลูกค้าที่เพิ่งบันทึกลงช่องลูกค้าของเอกสาร
            // พร้อมที่อยู่ที่เพิ่งกรอกให้ช่องที่อยู่ออกบิลเลือกไว้ (ไม่งั้นขึ้น "ค่าเริ่มต้น" ผู้ใช้งงว่าที่อยู่ที่กรอกไปไหน)
            // args เป็นรายการอาร์กิวเมนต์ — ลูกค้าคืออาร์กิวเมนต์ตัวแรกของ setInventoryCustomer จึงห่อสองชั้น
            if ($request->post('modal')->toInt() === 1) {
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
                            'function' => 'setInventoryCustomer',
                            'args' => [[
                                'id' => $id,
                                'customer_no' => $data['customer_no'],
                                'company' => $data['company']
                            ]]
                        ]
                    ]
                ], 'Saved successfully');
            }

            return $this->redirectResponse('/inventory-customers', Language::get('Saved successfully'), 200, 1000);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    /**
     * ค่าเริ่มต้นของลูกค้าใหม่
     *
     * @param string $type customer|supplier — มุมที่เปิดฟอร์มมา ใช้ตั้งธงเริ่มต้น
     *
     * @return array
     */
    protected static function blank($type = '')
    {
        return array_merge([
            'id' => 0,
            'customer_no' => '',
            'company' => '',
            'branch' => '',
            'name' => '',
            'contact' => '',
            'tax_id' => '',
            'phone' => '',
            'fax' => '',
            'email' => '',
            'address' => '',
            'zipcode' => '',
            'website' => '',
            'is_active' => 1,
            'invoice_due_date' => '',
            'expense_due_date' => ''
        ], self::defaultFlags($type));
    }

    /**
     * ธงลูกค้า/คู่ค้าเริ่มต้น ตามมุมที่เปิดฟอร์มเพิ่มมา (แท็บลูกค้า/แท็บคู่ค้า)
     *
     * เพิ่มจากแท็บไหนก็ควรได้ธงของแท็บนั้นติดมาให้เลย ไม่ต้องกลับไปติ๊กเอง —
     * ผู้ใช้ยังแก้ได้ทั้งสองช่องอิสระในฟอร์ม (เป็นได้ทั้งลูกค้าและคู่ค้าพร้อมกัน)
     *
     * @param string $type customer|supplier|''
     *
     * @return array ['is_customer' => int, 'is_supplier' => int]
     */
    public static function defaultFlags($type)
    {
        if ($type === 'supplier') {
            return ['is_customer' => 0, 'is_supplier' => 1];
        }

        return ['is_customer' => 1, 'is_supplier' => 0];
    }
}
