<?php
/**
 * @filesource modules/inventory/controllers/items.php
 *
 * api/inventory/items/get|save — หน่วยขาย (บาร์โค้ด) และเครื่อง (หมายเลขเครื่อง) ของแบบสินค้าหนึ่งแบบ
 *
 * หน่วยขาย = ยิงแล้วได้อะไรกี่หน่วยฐาน (ฟอง · โหล ×12 · แผง ×30) · เครื่อง = ชิ้นที่ระบุตัวได้ของ
 * สินค้านับรายชิ้น (DESIGN.md ข้อ 3.3) — ตารางแก้ไขทั้งตารางแล้วกดบันทึกครั้งเดียวเหมือนหน้าหมวดหมู่
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Inventory\Items;

use Gcms\Api as ApiController;
use Kotchasan\Http\Request;
use Kotchasan\Http\Response;
use Kotchasan\Language;

/**
 * API หน่วยขายและเครื่อง
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends ApiController
{
    /**
     * คอลัมน์ของตารางหน่วยขาย
     *
     * ชื่อฟิลด์ของสองตารางต้องไม่ซ้ำกัน — ทั้งคู่อยู่ในฟอร์มเดียวและส่งมาเป็น field[แถว]
     *
     * @param array $variant
     *
     * @return array
     */
    public static function packColumns(array $variant)
    {
        $columns = [
            // ⚠️ ห้ามชื่อ id — ชนกับ <input name="id"> ของฟอร์ม (PHP เก็บค่าที่ส่งมาหลังสุด แถวเดิมหายทั้งตาราง)
            ['field' => 'pack_id', 'label' => 'ID', 'cellElement' => 'text', 'hidden' => true],
            [
                'field' => 'barcode_image',
                'label' => '{LNG_Image}',
                'class' => 'center',
                'cellClass' => 'center',
                'template' => '<img class="inventory-barcode" src="${barcode_image}" alt="">'
            ],
            [
                'field' => 'barcode',
                'label' => '{LNG_Barcode}',
                'cellElement' => 'text',
                'maxLength' => 150,
                'placeholder' => '{LNG_Leave empty for generate auto}'
            ],
            ['field' => 'unit', 'label' => '{LNG_Unit}', 'class' => 'center', 'cellElement' => 'text', 'size' => 6, 'maxLength' => 50]
        ];
        // สินค้านับรายชิ้นขายทีละเครื่องเสมอ ตัวคูณเป็น 1 ไม่ต้องให้แก้
        if ((int) $variant['count_stock'] !== 2) {
            $columns[] = [
                'field' => 'cut_stock',
                'label' => '{LNG_Multiplier} ('.$variant['unit'].')',
                'class' => 'center',
                'cellClass' => 'center',
                'cellElement' => 'number',
                'size' => 5
            ];
        }
        $columns[] = [
            'field' => 'price',
            'label' => '{LNG_Selling price}',
            'class' => 'center',
            'cellClass' => 'right',
            'cellElement' => 'currency',
            'size' => 8,
            'placeholder' => '{LNG_Price} × {LNG_Multiplier}'
        ];
        $columns[] = ['field' => 'is_active', 'label' => '{LNG_Active}', 'class' => 'center', 'cellClass' => 'center', 'cellElement' => 'switch'];

        return $columns;
    }

    /**
     * คอลัมน์ของตารางเครื่อง (เฉพาะสินค้านับรายชิ้น)
     *
     * @return array
     */
    public static function serialColumns()
    {
        return [
            ['field' => 'serial_id', 'label' => 'ID', 'cellElement' => 'text', 'hidden' => true],
            [
                'field' => 'serial_image',
                'label' => '{LNG_Image}',
                'class' => 'center',
                'cellClass' => 'center',
                'template' => '<img class="inventory-barcode" src="${serial_image}" alt="">'
            ],
            [
                'field' => 'serial_no',
                'label' => '{LNG_Serial/Registration No.}',
                'cellElement' => 'text',
                'maxLength' => 150,
                'placeholder' => '{LNG_Leave empty for generate auto}'
            ],
            // คีย์ภาษาต้องผ่าน template — เซลล์ธรรมดาไม่ถูกแปล
            ['field' => 'status', 'label' => '{LNG_Status}', 'class' => 'center', 'cellClass' => 'center', 'template' => '{LNG_${status}}'],
            ['field' => 'serial_active', 'label' => '{LNG_Active}', 'class' => 'center', 'cellClass' => 'center', 'cellElement' => 'switch']
        ];
    }

    /**
     * GET api/inventory/items/get?id=N (แบบสินค้า)
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
            $variant = \Inventory\Product\Model::get($id);
            if ($variant === null || $id <= 0) {
                return $this->errorResponse('No data available', 404);
            }

            $packs = [];
            $serials = [];
            foreach ($variant['items'] as $item) {
                if ($item['serial_no'] !== null) {
                    $serials[] = [
                        'serial_id' => (int) $item['id'],
                        'serial_image' => Model::barcodeImage($item['serial_no']),
                        'serial_no' => $item['serial_no'],
                        'status' => (float) $item['stock'] > 0 ? 'In Stock' : 'Not in stock',
                        'serial_active' => (int) $item['is_active']
                    ];
                } else {
                    $packs[] = [
                        'pack_id' => (int) $item['id'],
                        'barcode_image' => Model::barcodeImage($item['barcode']),
                        'barcode' => $item['barcode'],
                        'unit' => $item['unit'],
                        'cut_stock' => (float) $item['cut_stock'],
                        // ว่าง = ราคาของแบบ × ตัวคูณ (DESIGN.md ข้อ 4)
                        'price' => $item['price'] === null ? '' : (float) $item['price'],
                        'is_active' => (int) $item['is_active']
                    ];
                }
            }

            return $this->successResponse([
                'data' => [
                    'id' => (int) $variant['id'],
                    'topic' => $variant['topic'],
                    'product_code' => $variant['product_code'],
                    'sku' => $variant['sku'],
                    'unit' => $variant['unit'],
                    'price' => (float) $variant['price'],
                    'count_stock' => (int) $variant['count_stock'],
                    'packs' => ['columns' => self::packColumns($variant), 'data' => $packs],
                    'serials' => ['columns' => self::serialColumns(), 'data' => $serials]
                ]
            ], 'OK');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    /**
     * POST api/inventory/items/save
     *
     * ตารางแก้ไขส่งมาเป็น field[แถว] — แถวเดิมมี id · แถวใหม่ id ว่าง · แถวที่หายไปคือถูกลบ
     * ทั้งชุดอยู่ใน transaction เดียว : แถวไหนผิด ไม่มีแถวไหนถูกบันทึก (บอกทุกแถวที่ผิดพร้อมกัน)
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
            $variant = \Inventory\Product\Model::get($id);
            if ($variant === null || $id <= 0) {
                return $this->errorResponse('No data available', 404);
            }
            $countStock = (int) $variant['count_stock'];
            $existing = ['pack' => [], 'serial' => []];
            foreach ($variant['items'] as $item) {
                $existing[$item['serial_no'] === null ? 'pack' : 'serial'][(int) $item['id']] = $item;
            }

            // ⚠️ ราคาว่างมีความหมาย (= ราคาของแบบ × ตัวคูณ) — toFloat() จะทำให้กลายเป็น 0
            $packIds = $request->post('pack_id', [])->topic();
            $barcodes = $request->post('barcode', [])->topic();
            $units = $request->post('unit', [])->topic();
            $cuts = $request->post('cut_stock', [])->topic();
            $prices = $request->post('price', [])->topic();
            $actives = $request->post('is_active', [])->topic();
            $serialIds = $request->post('serial_id', [])->topic();
            $serialNos = $request->post('serial_no', [])->topic();
            $serialActives = $request->post('serial_active', [])->topic();

            $errors = [];
            try {
                \Inventory\Base\Model::transaction(function () use ($id, $countStock, $existing, $packIds, $barcodes, $units,
                    $cuts, $prices, $actives, $serialIds, $serialNos, $serialActives, &$errors) {
                    $keep = [];
                    $packs = 0;
                    // ⚠️ ปุ่ม + ของตาราง "คัดลอก" แถว — รวม id ที่ซ่อนอยู่ด้วย แถวที่ id ซ้ำกับแถวก่อนหน้าจึงเป็นแถวใหม่
                    $seen = [];
                    foreach ($packIds as $key => $itemId) {
                        $itemId = isset($seen[(int) $itemId]) ? 0 : (int) $itemId;
                        $seen[$itemId] = true;
                        $barcode = isset($barcodes[$key]) ? $barcodes[$key] : '';
                        $unit = isset($units[$key]) ? $units[$key] : '';
                        $price = isset($prices[$key]) ? str_replace(',', '', $prices[$key]) : '';
                        if ($itemId === 0 && $barcode === '' && $unit === '' && $price === '') {
                            // แถวเปล่าที่ตารางแถมมาให้
                            continue;
                        }
                        $data = [
                            'barcode' => $barcode,
                            'unit' => $unit,
                            'price' => $price === '' ? null : (float) $price,
                            'is_active' => isset($actives[$key]) ? 1 : 0
                        ];
                        if ($countStock !== 2) {
                            $data['cut_stock'] = isset($cuts[$key]) && $cuts[$key] !== '' ? (float) str_replace(',', '', $cuts[$key]) : 1;
                        }
                        try {
                            $keep[] = Model::savePack($id, isset($existing['pack'][$itemId]) ? $itemId : 0, $data);
                            ++$packs;
                        } catch (\Exception $e) {
                            $errors['inventoryPacks_barcode_'.$key] = $e->getMessage();
                        }
                    }
                    if ($countStock === 2) {
                        foreach ($serialIds as $key => $itemId) {
                            $itemId = isset($seen[(int) $itemId]) ? 0 : (int) $itemId;
                            $seen[$itemId] = true;
                            $serialNo = isset($serialNos[$key]) ? $serialNos[$key] : '';
                            try {
                                if (isset($existing['serial'][$itemId])) {
                                    $keep[] = Model::saveSerial($id, $itemId, [
                                        'serial_no' => $serialNo,
                                        'is_active' => isset($serialActives[$key]) ? 1 : 0
                                    ]);
                                } else {
                                    // ไม่กรอก = ระบบออกเลข {sku}-NNNN
                                    $keep[] = Model::addSerial($id, $serialNo);
                                }
                            } catch (\Exception $e) {
                                $errors['inventorySerials_serial_no_'.$key] = $e->getMessage();
                            }
                        }
                    }
                    // แถวที่ไม่ถูกส่งกลับมา = ผู้ใช้ลบ · ที่ถูกใช้แล้วลบไม่ได้ (ปิดการใช้งานแทน)
                    foreach (array_merge(array_keys($existing['pack']), $countStock === 2 ? array_keys($existing['serial']) : []) as $itemId) {
                        if (!in_array($itemId, $keep, true)) {
                            try {
                                Model::remove($itemId);
                            } catch (\Exception $e) {
                                $errors['items'] = $e->getMessage();
                            }
                        }
                    }
                    // ยิง sku แล้วได้หน่วยขายฐาน (Products::detail) — แบบที่ไม่มีหน่วยขายเลยขายไม่ได้
                    if ($packs === 0 && empty($errors)) {
                        $errors['items'] = Language::get('Each variant needs at least one sale unit');
                    }
                    if (!empty($errors)) {
                        // ถอนทั้งชุด — บันทึกครึ่งเดียวแล้วผู้ใช้ไม่รู้ว่าแถวไหนเข้าแล้ว
                        throw new \RuntimeException('items');
                    }
                });
            } catch (\RuntimeException $e) {
                if (empty($errors)) {
                    throw $e;
                }

                return $this->formErrorResponse($errors, 400);
            }

            \Index\Log\Model::add($id, 'inventory', 'Save', '{LNG_Barcode} ID : '.$id, $login->id);

            return $this->redirectResponse('reload', Language::get('Saved successfully'), 200, 1000);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }
}
