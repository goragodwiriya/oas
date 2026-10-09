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
            if (!ApiController::hasPermission($login, ['can_inventory_order', 'can_manage_inventory'])) {
                return $this->errorResponse('Permission required', 403);
            }

            $id = $request->request('id')->toInt();
            // ⚠️ ปุ่มเพิ่มบนหน้ารายชื่อส่ง type ตามแท็บที่เปิดอยู่มาด้วย (data-param-type)
            // ต้องอ่านมาตั้งธง is_customer/is_supplier ให้ตรงแท็บ ไม่งั้นกด "เพิ่มคู่ค้า"
            // จากแท็บคู่ค้าแล้วได้ระเบียนที่ทำเครื่องหมายเป็นลูกค้าอย่างเดียวเสมอ
            $type = $request->request('type')->filter('a-z');
            $customer = $id > 0 ? Customers::get($id) : self::blank($type);
            if ($customer === null) {
                return $this->errorResponse('No data available', 404);
            }

            // ประเทศของลูกค้าที่บันทึกไว้เป็นตัวกำหนดว่าจะส่งรายชื่อจังหวัดของประเทศไหนมา
            // ไม่ใช่ TH เสมอ ไม่งั้นลูกค้าต่างประเทศจะเห็นจังหวัดไทยในฟอร์มของตัวเอง
            $country = empty($customer['country']) ? 'TH' : $customer['country'];

            return $this->successResponse([
                'data' => $customer,
                'options' => [
                    // ⚠️ ต้องส่ง options ของ country มาด้วย ไม่งั้นช่องเลือกประเทศ
                    // ว่างเปล่าทั้งช่อง (ผู้ใช้เลือกประเทศไม่ได้เลย แม้แต่ประเทศที่บันทึกไว้)
                    'country' => self::countryOptions(),
                    // getOptions() คืนรูป {value, text} ที่ช่องเลือกต้องการอยู่แล้ว
                    // ส่วน all() คืนข้อมูลดิบซึ่งฟอร์มแกะไม่ออก
                    'provinceID' => \Kotchasan\Province::getOptions($country)
                ]
            ], 'OK');
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
                'template' => 'inventory/customer.html',
                'title' => Language::get($id > 0 ? 'Edit' : 'Add').' '.Language::get('Customer'),
                'titleClass' => 'icon-customer'
            ]
        ];

        return Response::create(json_encode($body), 200,
            ['Content-Type' => 'application/json; charset=utf-8']);
    }

    /**
     * GET api/inventory/customer/provinces — รายชื่อจังหวัดของประเทศที่เลือก
     *
     * ช่องเลือกประเทศในฟอร์มยิงมาที่นี่เองตอน change (data-action="change:requestApi")
     * จึงไม่ต้องมี JS ประจำหน้า — ตอบกลับเป็น action ชนิด form ให้ ResponseHandler
     * เอาไป patch ตัวเลือกของช่อง provinceID ในฟอร์มเดียวกัน
     *
     * ⚠️ สัญญากับ ResponseHandler (Now/js/ResponseHandler.js → patchFormInstance)
     *    actions[].type ต้องเป็น 'form' และตัวเลือกอยู่ใต้คีย์ `options`
     *    ถ้าใส่ผิดชั้น ช่องจังหวัดจะไม่เปลี่ยนอะไรเลยและไม่มีข้อความเตือน
     *
     * @param Request $request
     *
     * @return Response
     */
    public function provinces(Request $request)
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

            $country = $request->get('country')->filter('A-Za-z');
            $country = $country === '' ? 'TH' : strtoupper($country);

            return $this->successResponse([
                'actions' => [
                    [
                        'type' => 'form',
                        'action' => 'patch',
                        'options' => [
                            'provinceID' => \Kotchasan\Province::getOptions($country)
                        ],
                        // เปลี่ยนประเทศแล้วจังหวัดเดิมย่อมใช้ไม่ได้ ต้องล้างค่าทิ้ง
                        // ไม่งั้นจะเหลือรหัสจังหวัดของประเทศเก่าค้างอยู่ในฟอร์ม
                        'fields' => [
                            'provinceID' => ''
                        ]
                    ]
                ]
            ], 'OK');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
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
                'customer_no' => $request->post('customer_no')->topic(),
                'company' => $request->post('company')->topic(),
                'branch' => $request->post('branch')->topic(),
                'name' => $request->post('name')->topic(),
                'contact' => $request->post('contact')->topic(),
                'tax_id' => $request->post('tax_id')->number(),
                'idcard' => $request->post('idcard')->number(),
                'phone' => $request->post('phone')->topic(),
                'fax' => $request->post('fax')->topic(),
                'email' => $request->post('email')->email(),
                'address' => $request->post('address')->topic(),
                'provinceID' => $request->post('provinceID')->toInt(),
                'province' => $request->post('province')->topic(),
                'zipcode' => $request->post('zipcode')->number(),
                'country' => $request->post('country')->filter('A-Za-z'),
                'website' => $request->post('website')->url(),
                'is_customer' => $request->post('is_customer')->toInt(),
                'is_supplier' => $request->post('is_supplier')->toInt(),
                'is_active' => $request->post('is_active')->toInt(),
                'invoice_due_date' => $request->post('invoice_due_date')->toInt(),
                'expense_due_date' => $request->post('expense_due_date')->toInt()
            ];

            $errors = [];
            if ($data['company'] === '' && $data['name'] === '') {
                // ต้องมีอย่างน้อยหนึ่งอย่างไว้เรียกชื่อ ไม่งั้นเอกสารจะพิมพ์ออกมาไม่มีผู้รับ
                $errors['company'] = Language::get('Please fill in');
            }
            if (!empty($errors)) {
                return $this->formErrorResponse($errors, 400);
            }

            $db = \Kotchasan\Model::createDB();
            $table = \Inventory\Base\Model::table('customer');

            // รหัสลูกค้าต้องไม่ซ้ำ (คอลัมน์นี้เป็น UNIQUE) ถ้าไม่กรอกให้ออกให้เอง
            if ($data['customer_no'] === '') {
                $data['customer_no'] = Customers::nextNumber();
            }
            $exists = $db->first($table, ['customer_no' => $data['customer_no']]);
            if ($exists && (int) $exists->id !== $id) {
                return $this->formErrorResponse([
                    'customer_no' => Language::replace('This :name already exist', [
                        ':name' => Language::get('Customer code')
                    ])
                ], 400);
            }

            if ($id > 0) {
                $data['updated_at'] = date('Y-m-d H:i:s');
                $db->update($table, ['id', $id], $data);
            } else {
                $data['created_at'] = date('Y-m-d H:i:s');
                $id = (int) $db->insert($table, $data);
            }

            // หน้าต่างซ้อนต้องได้ค่ากลับไปเติมในช่องลูกค้าของเอกสารทันที
            if ($request->post('modal')->toInt() === 1) {
                return $this->successResponse([
                    'id' => $id,
                    'customer_no' => $data['customer_no'],
                    'company' => $data['company'],
                    'name' => $data['name']
                ], Language::get('Saved successfully'));
            }

            return $this->redirectResponse('/inventory-customers', Language::get('Saved successfully'), 200, 1000);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    /**
     * รายชื่อประเทศในรูป {value, text}
     *
     * Country::all() คืน [iso => ชื่อ] ซึ่งฟอร์มแกะไม่ออก ต้องแปลงก่อน
     * ให้ครบทุกประเทศ ไม่ใช่เฉพาะประเทศที่มีข้อมูลจังหวัด — ที่อยู่ลูกค้า
     * เป็นประเทศไหนก็ได้ ส่วนจังหวัดมีให้เลือกเท่าที่มีข้อมูล
     *
     * @return array
     */
    protected static function countryOptions()
    {
        $result = [];
        foreach (\Kotchasan\Country::all() as $iso => $name) {
            $result[] = ['value' => $iso, 'text' => $name];
        }

        return $result;
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
            'idcard' => '',
            'phone' => '',
            'fax' => '',
            'email' => '',
            'address' => '',
            'provinceID' => 0,
            'province' => '',
            'zipcode' => '',
            'country' => 'TH',
            'website' => '',
            'is_active' => 1,
            'invoice_due_date' => 0,
            'expense_due_date' => 0
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
