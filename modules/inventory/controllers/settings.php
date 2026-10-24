<?php
/**
 * @filesource modules/inventory/controllers/settings.php
 *
 * api/inventory/settings/get|save — ค่ากำหนดของโมดูล inventory
 *
 * ⚠️ ที่นี่รับเฉพาะค่าที่เป็นของ "ทั้งระบบ" เท่านั้น
 *   - ข้อมูลบริษัทและบัญชีธนาคาร เป็นของหน้า settings/company ซึ่งเขียนลง
 *     $config->company[] และ Base::config() อ่าน company[] ก่อนคีย์แบนเสมอ
 *     เขียนซ้ำจากที่นี่จึงถูกบังทิ้งและไม่มีวันขึ้นบนเอกสารที่พิมพ์
 *   - รูปแบบเลขที่เอกสาร เป็นของเอกสารชนิดหนึ่ง ไม่ใช่ของทั้งระบบ อยู่ในแถว
 *     แม่แบบ (inventory_template.prefix / .number_format) ตั้งที่หน้าแม่แบบ
 *
 * ⚠️ ค่าเหล่านี้เป็นของ "องค์กร" ไม่ใช่ของระบบ — เมื่อถึงวันที่ทำระบบผู้เช่า
 * ต้องอ่าน/เขียนแยกตามผู้เช่า จึงต้องผ่าน Base::config() จุดเดียวเท่านั้น
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Inventory\Settings;

use Gcms\Api as ApiController;
use Gcms\Config;
use Inventory\Base\Model as Base;
use Kotchasan\ApiException;
use Kotchasan\Http\Request;
use Kotchasan\Http\Response;
use Kotchasan\Language;

/**
 * API ค่ากำหนดของโมดูล
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends ApiController
{
    /**
     * GET api/inventory/settings/get
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
            if (!ApiController::hasPermission($login, 'can_config')) {
                return $this->errorResponse('Permission required', 403);
            }

            return $this->successResponse([
                'data' => [
                    'vat' => (float) Base::config('vat', 7),
                    'currency_unit' => (string) Base::config('currency_unit', 'THB'),
                    'customer_no' => (string) Base::config('customer_no', 'CU%04d'),
                    'product_code_format' => (string) Base::config('product_code_format', 'P%04d'),
                    'stock_method' => \Inventory\Fifo\Model::method(),
                    'lot_tracking' => (int) Base::config('lot_tracking', 0),
                    'input_vat_creditable' => (int) Base::config('input_vat_creditable', 1)
                ],
                'options' => [
                    'currency_unit' => self::currencyOptions(),
                    'stock_method' => [
                        ['value' => 'fifo', 'text' => '{LNG_First in, first out} (FIFO)'],
                        ['value' => 'fefo', 'text' => '{LNG_First expired, first out} (FEFO)']
                    ]
                ]
            ], 'OK');
        } catch (ApiException $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 400, $e);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400, $e);
        }
    }

    /**
     * ตัวเลือกสกุลเงิน — จากแฟ้มภาษา ไม่ใช่ให้พิมพ์เอง
     *
     * @return array
     */
    public static function currencyOptions()
    {
        $options = [];
        foreach ((array) Language::get('CURRENCY_UNITS', []) as $value => $text) {
            $options[] = ['value' => $value, 'text' => $text];
        }

        return $options;
    }

    /**
     * POST api/inventory/settings/save
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
            if (!ApiController::canModify($login, 'can_config')) {
                return $this->errorResponse('Permission required', 403);
            }

            $file = ROOT_PATH.'settings/config.php';
            $config = Config::load($file);

            $config->vat = $request->post('vat')->toDouble();
            $config->customer_no = $request->post('customer_no')->topic();
            $config->product_code_format = $request->post('product_code_format')->topic();
            // วิธีตัดเมื่อไม่ระบุล็อต/เครื่อง (DESIGN.md ข้อ 5.1) — ใช้กับการตัดครั้งถัดไป ของที่ตัดไปแล้วไม่เปลี่ยน
            $config->stock_method = $request->post('stock_method')->filter('a-z') === 'fefo' ? 'fefo' : 'fifo';
            // เปลี่ยนวิธีตัด = เปลี่ยนนโยบายการบัญชี (TAS 8) — มีผลกับการตัดหลังจากนี้ ต้องตรวจย้อนได้ว่าเปลี่ยนเมื่อไร โดยใคร
            $stockMethodBefore = \Inventory\Fifo\Model::method();
            // แสดงช่องล็อต/วันหมดอายุบนบรรทัดเอกสาร — ปิดไว้ข้อมูลล็อตเดิมยังอยู่ครบ
            $config->lot_tracking = $request->post('lot_tracking')->toBoolean() ? 1 : 0;
            // ภาษีซื้อขอคืนได้ไม่ใช่ต้นทุนสินค้า (TAS 2) — ใช้กับใบรับที่บันทึกหลังจากนี้ ของที่รับไปแล้วไม่เปลี่ยน
            $config->input_vat_creditable = $request->post('input_vat_creditable')->toBoolean() ? 1 : 0;

            // รับเฉพาะรหัสสกุลเงินที่แฟ้มภาษารู้จัก รหัสอื่นจะถูกพิมพ์ดิบ ๆ บนเอกสาร
            $_currency = $request->post('currency_unit')->filter('A-Z');
            $_currencies = (array) Language::get('CURRENCY_UNITS', []);
            $config->currency_unit = isset($_currencies[$_currency]) ? $_currency : 'THB';

            if (!Config::save($config, $file)) {
                return $this->errorResponse('Unable to save settings', 500);
            }

            // Log
            \Index\Log\Model::add(0, 'inventory', 'Save', '{LNG_Module Settings} {LNG_Inventory}', $login->id);
            if ($stockMethodBefore !== $config->stock_method) {
                \Index\Log\Model::add(0, 'inventory', 'Save', '{LNG_Stock method} : '.strtoupper($stockMethodBefore)
                    .' → '.strtoupper($config->stock_method), $login->id);
            }

            // โหลดหน้าใหม่
            return $this->redirectResponse('reload', Language::get('Saved successfully'), 200, 0, 'form');
        } catch (ApiException $e) {
            // คงรหัสเดิมไว้ — 419 คือ CSRF ที่ฝั่งเบราว์เซอร์ขอ token ใหม่แล้วยิงซ้ำได้เอง
            // (SecurityManager.js) ถ้ายุบเป็น 400 การกู้อัตโนมัตินั้นจะไม่ทำงาน
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 400, $e);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400, $e);
        }
    }
}
