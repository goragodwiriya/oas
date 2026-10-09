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
                    'product_no' => (string) Base::config('product_no', 'P%04d')
                ],
                'options' => [
                    'currency_unit' => self::currencyOptions()
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
     * ⚠️ ค่านี้ไปโผล่บนเอกสารผ่าน Language::get('CURRENCY_UNITS', ...) รหัสที่
     * ไม่อยู่ในรายการจะถูกพิมพ์ออกมาดิบ ๆ ตามที่พิมพ์เข้ามา
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

            // ⚠️ Config::load() อ่าน "ไฟล์" ไม่ใช่ Config::create() ซึ่งคืน singleton
            // ที่มีค่าเริ่มต้นของทุกคลาสติดมาด้วย — บันทึกตัวนั้นทีเดียวไฟล์ค่ากำหนด
            // จะโตจาก 50 เป็น 105 คีย์ แล้วค่าเริ่มต้นของเฟรมเวิร์กถูกแช่แข็งไว้
            // ในไฟล์ตลอดไป รุ่นใหม่แก้ค่าเริ่มต้นก็ไม่มีผลกับไซต์นี้อีกเลย
            // (โมดูล index ของแกนใช้แบบนี้เหมือนกัน)
            $file = ROOT_PATH.'settings/config.php';
            $config = Config::load($file);

            $config->vat = $request->post('vat')->toDouble();
            $config->customer_no = $request->post('customer_no')->topic();
            $config->product_no = $request->post('product_no')->topic();

            // รับเฉพาะรหัสสกุลเงินที่แฟ้มภาษารู้จัก รหัสอื่นจะถูกพิมพ์ดิบ ๆ บนเอกสาร
            $_currency = $request->post('currency_unit')->filter('A-Z');
            $_currencies = (array) Language::get('CURRENCY_UNITS', []);
            $config->currency_unit = isset($_currencies[$_currency]) ? $_currency : 'THB';

            // \Kotchasan\Config::save($config, $file) เป็นเมธอดสถิต ไม่ใช่ $config->save()
            // (ตรวจกับซอร์สแล้ว — โมดูล index ของแกนก็เรียกแบบนี้)
            if (!Config::save($config, $file)) {
                return $this->errorResponse('Unable to save settings', 500);
            }

            \Index\Log\Model::add(0, 'inventory', 'Save',
                '{LNG_Module settings} {LNG_Inventory}', $login->id);

            // โหลดหน้าใหม่ ไม่งั้นช่องที่ระบบปรับค่าให้ (สกุลเงิน/ขนาดกระดาษที่
            // ไม่รู้จักถูกดันกลับเป็นค่าเริ่มต้น) จะยังค้างค่าที่ผู้ใช้พิมพ์ไว้บนจอ
            return $this->redirectResponse('reload', Language::get('Saved successfully'), 200, 1000);
        } catch (ApiException $e) {
            // คงรหัสเดิมไว้ — 419 คือ CSRF ที่ฝั่งเบราว์เซอร์ขอ token ใหม่แล้วยิงซ้ำได้เอง
            // (SecurityManager.js) ถ้ายุบเป็น 400 การกู้อัตโนมัตินั้นจะไม่ทำงาน
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 400, $e);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400, $e);
        }
    }
}
