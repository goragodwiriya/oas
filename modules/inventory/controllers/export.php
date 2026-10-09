<?php
/**
 * @filesource modules/inventory/controllers/export.php
 *
 * export.php?module=inventory&typ=billing|pdf|print|envelope|csv
 * หน้าสำหรับพิมพ์เอกสารและซองจดหมาย (ระบบเดิมคือ export.php?module=inventory-export)
 *
 * ที่นี่ดูแลเฉพาะเรื่องของโมดูลนี้ : อ่านเอกสารจากฐานข้อมูล จัดคอลัมน์ตามชนิด
 * เอกสาร สร้างช่องลายเซ็นจากแม่แบบ และผังซองจดหมาย
 * ส่วนขนาดกระดาษ หน้าเว็บสำหรับพิมพ์ แถบตัวเลือก และการแปลงเป็น PDF เป็นของ
 * บริการกลาง \Export\Export\Controller ที่โมดูลอื่นใช้ร่วมกันได้
 *
 * หน้าเหล่านี้เปิดในแท็บใหม่ ไม่ผ่าน SPA จึงยืนยันตัวตนด้วยคุกกี้ auth_token
 * ที่ระบบตั้งไว้ตอนล็อกอิน (ดู Kotchasan\ApiController::getAccessToken)
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Inventory\Export;

use Gcms\Api as ApiController;
use Inventory\Base\Model as Base;
use Inventory\Document\Model as Document;
use Kotchasan\Currency;
use Kotchasan\Date;
use Kotchasan\Http\Request;
use Kotchasan\Http\Response;
use Kotchasan\Language;

/**
 * พิมพ์เอกสาร/ซองจดหมาย
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Export\Export\Controller
{
    /**
     * โฟลเดอร์เก็บแม่แบบสำหรับพิมพ์
     *
     * @return string
     */
    protected function templateDir()
    {
        return ROOT_PATH.'modules/inventory/template/';
    }

    /**
     * ผู้มีอำนาจลงนามของคำขอนี้ (อ่านครั้งเดียว พิมพ์หลายใบก็ไม่ยิงคิวรีซ้ำ)
     *
     * @var array|null|false false = ยังไม่ได้อ่าน
     */
    private $authorized = false;

    /**
     * URL ของรูปบริษัท (โลโก้/ตราประทับ)
     *
     * อ่านจากที่เก็บของ adminframework ก่อน (datas/images/company_*) ถ้าไม่มี
     * ค่อยถอยไปดูที่เก็บของระบบเดิม (datas/logo.jpg) — ลูกค้าที่อัปโหลดไว้กับ
     * ระบบเดิมจึงพิมพ์เอกสารได้ทันทีโดยไม่ต้องอัปโหลดใหม่
     *
     *
     * อ่านจากที่เก็บของ adminframework ก่อน (datas/images/company_*) ถ้าไม่มี
     * ค่อยถอยไปดูที่เก็บของระบบเดิม (datas/logo.jpg) — ลูกค้าที่อัปโหลดไว้กับ
     * ระบบเดิมจึงพิมพ์เอกสารได้ทันทีโดยไม่ต้องอัปโหลดใหม่
     *
     * ลายเซ็นไม่อยู่ที่นี่แล้ว — เป็นของผู้มีอำนาจลงนาม ดู authorized()
     *
     * @param string $name logo|stamp
     *
     * @return string ค่าว่างถ้าไม่มีรูป
     */
    protected function companyImage($name)
    {
        $new = DATA_FOLDER.'images/company_'.$name.self::$cfg->stored_img_type;
        if (is_file(ROOT_PATH.$new)) {
            return WEB_URL.$new;
        }

        $legacy = DATA_FOLDER.$name.'.jpg';
        if (is_file(ROOT_PATH.$legacy)) {
            return WEB_URL.$legacy;
        }

        return '';
    }

    /**
     * ผู้มีอำนาจลงนาม — สมาชิกที่เลือกไว้ในหน้าตั้งค่าบริษัท ชื่อและลายเซ็นมาจากโปรไฟล์ของคนนั้น
     *
     * ⚠️ แคชไว้ในตัวคอนโทรลเลอร์ ไม่ใช่ static — ตัวคอนโทรลเลอร์เกิดใหม่ทุกคำขอ
     * ค่าของไซต์หนึ่งจึงไม่มีทางรั่วไปอีกไซต์ที่ใช้โพรเซสเดียวกัน
     *
     * @return array ['name' => string, 'signature' => URL หรือ '']
     */
    protected function authorized()
    {
        if ($this->authorized === false) {
            $this->authorized = \Index\Company\Model::authorized();
        }

        return $this->authorized === null ? ['name' => '', 'signature' => ''] : $this->authorized;
    }

    /**
     * ชนิดการส่งออกที่เปิดให้เข้าถึงได้โดยไม่ต้องเข้าระบบ
     *
     * ลิงก์พิมพ์เอกสารที่ส่งไปกับอีเมลถึงลูกค้าต้องเปิดได้โดยไม่ต้องมีบัญชีผู้ใช้
     * ตัวเมธอดเองเป็นคนตรวจสิทธิ์ ด้วยกุญแจ 32 ตัวอักษรที่อยู่ในลิงก์แทน
     *
     * @return array
     */
    public function publicTypes()
    {
        return ['print'];
    }

    /**
     * ตรวจสิทธิ์แล้วอ่านเอกสารที่ขอ
     *
     * รับได้ทั้ง id ของเอกสาร และ customer_id (ใช้กับซองจดหมายที่จ่าหน้าถึงลูกค้า
     * โดยไม่อ้างอิงเอกสารใบใด เหมือนปุ่มพิมพ์ซองในหน้ารายชื่อลูกค้าของระบบเดิม)
     *
     * @param Request $request
     *
     * @return object|Response
     */
    protected function resolveOrder(Request $request)
    {
        $login = $this->authenticateRequest($request);
        if (!$login) {
            return $this->errorResponse('Unauthorized', 401);
        }
        if (!ApiController::hasPermission($login, ['can_inventory_order', 'can_manage_inventory'])) {
            return $this->errorResponse('Permission required', 403);
        }

        $customerId = $request->get('customer_id')->toInt();
        if ($customerId > 0) {
            return $this->customerAsOrder($customerId);
        }

        $order = \Inventory\Orders\Model::get($request->get('id')->toInt());
        if (empty($order) || empty($order['id'])) {
            return $this->errorResponse('No data available', 404);
        }

        return (object) $order;
    }

    /**
     * ข้อมูลลูกค้าในรูปของเอกสารเปล่า สำหรับจ่าหน้าซองถึงลูกค้าโดยตรง
     *
     * ใช้โครงเดียวกับเอกสารเปล่าแล้วเติมเฉพาะที่อยู่ของลูกค้าลงไป ตัวแทนค่า
     * ในแม่แบบจึงใช้ชุดเดียวกันได้หมด ไม่ต้องมีทางแยกสำหรับซองของลูกค้า
     *
     * @param int $customerId
     *
     * @return object|Response
     */
    protected function customerAsOrder($customerId)
    {
        $customer = \Inventory\Customers\Model::get($customerId);
        if (empty($customer) || empty($customer['id'])) {
            return $this->errorResponse('No data available', 404);
        }

        $order = \Inventory\Order\Controller::blank('');
        $order['id'] = 0;
        $order['customer_id'] = $customer['id'];
        $order['customer_no'] = $customer['customer_no'];
        $order['customer'] = $customer['company'];
        $order['contactor'] = $customer['name'];
        $order['branch'] = $customer['branch'];
        $order['address'] = $customer['address'];
        $order['province'] = $customer['province'];
        $order['zipcode'] = $customer['zipcode'];
        $order['country'] = $customer['country'];
        $order['phone'] = $customer['phone'];
        $order['email'] = $customer['email'];
        $order['tax_id'] = $customer['tax_id'];
        // ซองของลูกค้าไม่ผูกกับเอกสารใบใด จึงไม่มีเลขที่และวันที่
        $order['order_no'] = '';
        $order['order_date'] = null;
        $order['due_date'] = '';

        return (object) $order;
    }

    /**
     * ค่าที่ใช้แทนที่ในแม่แบบ (ชุดเดียวกับระบบเดิม ชื่อตัวแปรจึงใช้ร่วมกันได้)
     *
     * @param object $order
     *
     * @return array
     */
    protected function placeholders($order)
    {
        $lng = Language::name();
        $authorized = $this->authorized();
        $stamp = $this->companyImage('stamp');
        $logo = $this->companyImage('logo');

        return [
            '%CONTACTOR%' => (string) $order->contactor,
            '%ORDERDATE%' => $order->order_date === null ? '' : Date::format($order->order_date, 'd M Y'),
            '%DUEDATE%' => empty($order->due_date) ? '' : Date::format($order->due_date, 'd M Y'),
            '%ORDERNO%' => (string) $order->order_no,
            '%COMPANY%' => empty($order->customer_id) ? Language::get('Cash') : (string) $order->customer,
            '%BRANCH%' => (string) $order->branch,
            '%ADDRESS%' => (string) $order->address,
            '%PROVINCE%' => (string) $order->province,
            '%ZIPCODE%' => (string) $order->zipcode,
            '%PHONE%' => (string) $order->phone,
            '%EMAIL%' => (string) $order->email,
            '%TAXID%' => (string) $order->tax_id,
            '%AUTHORITY%' => $authorized['name'],
            '%AUTHORITYNAME%' => Base::config('name'),
            '%AUTHORITYPHONE%' => Base::config('phone'),
            '%AUTHORITYADDRESS%' => Base::config('address'),
            '%AUTHORITYBRANCH%' => Base::config('branch'),
            '%AUTHORITYZIPCODE%' => Base::config('zipcode'),
            '%AUTHORITYTAXID%' => Base::config('tax_id'),
            '%AUTHORITYEMAIL%' => Base::config('email'),
            '%BANK%' => Base::config('bank'),
            '%BANKNAME%' => Base::config('bank_name'),
            '%BANKNO%' => Base::config('bank_no'),
            '%CURRENCY%' => Language::get('CURRENCY_UNITS', self::$cfg->currency_unit, self::$cfg->currency_unit),
            '%LOGO%' => $logo === '' ? '' : '<img class="logo" src="'.$logo.'" alt="">',
            '%STAMP%' => $stamp,
            '%SIGNATURE%' => $authorized['signature'],
            '%COMMENT%' => nl2br(htmlspecialchars((string) $order->comment, ENT_QUOTES, 'UTF-8')),
            '%LANGUAGE%' => $lng
        ];
    }

    /**
     * GET export.php?module=inventory&typ=billing&id=N — พิมพ์เอกสาร
     *
     * @param Request $request
     *
     * @return Response
     */
    public function billing(Request $request)
    {
        // เลือกหลายใบจากหน้ารายการแล้วสั่งพิมพ์ทีเดียว (ระบบเดิมพิมพ์ได้ทีละใบ)
        // ทุกใบใช้ขนาดกระดาษและจำนวนรายการต่อหน้าชุดเดียวกัน
        $ids = $this->requestedIds($request);
        if (count($ids) > 1) {
            return $this->renderMany($ids, $request);
        }

        $order = $this->resolveOrder($request);
        if ($order instanceof Response) {
            return $order;
        }

        return $this->renderBilling($order, $request, ['typ' => 'billing', 'id' => (int) $order->id]);
    }

    /**
     * id ของเอกสารที่ขอมา รับได้ทั้ง ?id=N และ ?ids=N,N,N
     *
     * @param Request $request
     *
     * @return array
     */
    protected function requestedIds(Request $request)
    {
        $ids = [];
        $one = $request->get('id')->toInt();
        if ($one > 0) {
            $ids[] = $one;
        }
        $raw = $request->get('ids')->filter('0-9,');
        if ($raw !== '') {
            foreach (explode(',', $raw) as $value) {
                $value = (int) $value;
                if ($value > 0) {
                    $ids[] = $value;
                }
            }
        }

        // จำกัดจำนวนไว้กันสั่งพิมพ์ทั้งฐานข้อมูลด้วยลิงก์เดียว
        return array_slice(array_values(array_unique($ids)), 0, 50);
    }

    /**
     * พิมพ์หลายใบต่อกันในหน้าเดียว แต่ละใบขึ้นหน้ากระดาษใหม่เสมอ
     *
     * @param array   $ids
     * @param Request $request
     *
     * @return Response
     */
    protected function renderMany(array $ids, Request $request)
    {
        $login = $this->authenticateRequest($request);
        if (!$login) {
            return $this->errorResponse('Unauthorized', 401);
        }
        if (!ApiController::hasPermission($login, ['can_inventory_order', 'can_manage_inventory'])) {
            return $this->errorResponse('Permission required', 403);
        }

        $sheets = '';
        $found = 0;
        $head = null;
        foreach ($ids as $id) {
            $order = \Inventory\Orders\Model::get($id);
            if (empty($order) || empty($order['id'])) {
                continue;
            }
            $one = $this->buildBilling((object) $order, $request);
            if ($one === null) {
                continue;
            }
            ++$found;
            $sheets .= $one['html'];
            // ขนาดกระดาษของทุกใบเหมือนกัน ใช้ชุดแรกที่วาดสำเร็จเป็นตัวตั้ง
            // ยกเว้นกระดาษม้วนที่ความยาวต่างกันได้ ต้องยาวพอสำหรับใบที่ยาวที่สุด
            if ($head === null) {
                $head = $one;
            } elseif ($one['slip_height'] > $head['slip_height']) {
                $head['slip_height'] = $one['slip_height'];
                $head['sizing'] = $one['sizing'];
            }
        }

        if ($found === 0) {
            return $this->errorResponse('No data available', 404);
        }

        return $this->wrapPrintPage(
            Language::get('Print').' '.$found.' '.Language::get('items'),
            $sheets,
            $head,
            ['typ' => 'billing', 'ids' => implode(',', $ids)]
        );
    }


    /**
     * GET export.php?module=inventory&typ=pdf&id=N (หรือ &ids=N,N,N) — ดาวน์โหลดเป็น PDF
     *
     * ใช้ HTML ชุดเดียวกับหน้าพิมพ์ แล้วให้เบราว์เซอร์ที่ติดตั้งบนเครื่องเซิร์ฟเวอร์
     * แปลงเป็น PDF ผลลัพธ์จึงหน้าตาเหมือนที่เห็นบนจอเป๊ะ ๆ ทั้งฟอนต์ไทย การแบ่งหน้า
     * และขนาดกระดาษ
     *
     * ⚠️ ระบบเดิมมีเมนู typ=pdf เหมือนกัน แต่เรียก \Mpdf\Mpdf ทั้งที่ไม่มี vendor/
     * อยู่ในโปรเจ็คเลย กดแล้วได้หน้าจอขาวทุกครั้ง
     *
     * ⚠️ ไม่ใช้ตัวสร้าง PDF ฝั่ง PHP (mPDF/Dompdf) เพราะไม่รองรับ flex/grid ที่ผัง
     * เอกสารใช้อยู่ ต้องเขียนผังแยกอีกชุดสำหรับ PDF ซึ่งกลายเป็นของสองที่ที่ต้อง
     * ดูแลให้ตรงกันตลอด
     *
     * @param Request $request
     *
     * @return Response
     */
    public function pdf(Request $request)
    {
        $ids = $this->requestedIds($request);
        if (count($ids) > 1) {
            $page = $this->renderMany($ids, $request);
        } else {
            $order = $this->resolveOrder($request);
            if ($order instanceof Response) {
                return $order;
            }
            $page = $this->renderBilling($order, $request, ['typ' => 'billing', 'id' => (int) $order->id]);
        }
        if ($page->getStatusCode() !== 200) {
            return $page;
        }


        return self::toPdf($page, $this->pdfName($ids));
    }

    /**
     * ชื่อไฟล์ PDF จากเลขที่เอกสาร
     *
     * @param array $ids
     *
     * @return string
     */
    protected function pdfName(array $ids)
    {
        if (count($ids) === 1) {
            $order = \Inventory\Orders\Model::get($ids[0]);
            if (!empty($order['order_no'])) {
                return (string) $order['order_no'];
            }
        }

        return 'documents-'.count($ids).'-'.date('Ymd-His');
    }


    /**
     * GET export.php?module=inventory&typ=print&order=<กุญแจ> — เอกสารสำหรับลูกค้า
     *
     * ลิงก์นี้คือลิงก์ที่อยู่ในอีเมลถึงลูกค้า จึงเปิดได้โดยไม่ต้องเข้าระบบ
     * สิทธิ์อยู่ที่กุญแจ 32 ตัวอักษรของเอกสารใบนั้นใบเดียว ไม่ใช่ id ที่ไล่เดาได้
     * (ระบบเดิมเปิดให้พิมพ์ด้วย id ตรง ๆ โดยไม่ตรวจอะไรเลย ใครก็เปิดเอกสารของ
     * คนอื่นได้ทั้งระบบ ที่นี่ทางสาธารณะเปิดได้เฉพาะใบที่มีกุญแจเท่านั้น)
     *
     * @param Request $request
     *
     * @return Response
     */
    public function print(Request $request)
    {
        $key = $request->get('order')->filter('a-zA-Z0-9');
        $order = \Inventory\Orders\Model::getByKey($key);
        if (empty($order) || empty($order['id'])) {
            return $this->errorResponse('No data available', 404);
        }

        // ⚠️ แถบตัวเลือกตอนพิมพ์ต้องวนกลับมาที่ลิงก์กุญแจเดิม ไม่ใช่ typ=billing&id=
        // ซึ่งต้องล็อกอิน ไม่งั้นลูกค้ากดเปลี่ยนขนาดกระดาษแล้วเจอ 401
        return $this->renderBilling((object) $order, $request, ['typ' => 'print', 'order' => $key]);
    }


    /**
     * แถวรายการหนึ่งหน้า ตามรายชื่อคอลัมน์ของชนิดเอกสารนั้น
     *
     * @param array $items   รายการของหน้านี้
     * @param array $columns รายชื่อคอลัมน์
     * @param int   $offset  ลำดับเริ่มต้นของหน้านี้ (นับต่อจากหน้าก่อน)
     * @param bool  $slip    true = กระดาษม้วนแคบ ใช้สองบรรทัดต่อรายการแทนหลายคอลัมน์
     *
     * @return array ['html' => ..., 'sum' => ...]
     */
    protected function itemRows(array $items, array $columns, $offset, $slip = false)
    {
        $html = '';
        $sum = 0;
        $index = $offset;
        foreach ($items as $item) {
            ++$index;
            $discount = ($item['discount'] * $item['price']) / 100;
            $amount = ($item['price'] - $discount) * $item['quantity'];
            $sum += $amount;

            if ($slip) {
                // กระดาษกว้าง 54-74 มม. ใส่หลายคอลัมน์แล้วอ่านไม่ออก
                // จึงเป็นชื่อสินค้าหนึ่งบรรทัด แล้วจำนวน x ราคา = จำนวนเงิน อีกบรรทัด
                $html .= '<tr class="slip-name"><td colspan="2">'.$index.'. '
                    .nl2br(htmlspecialchars((string) $item['topic'], ENT_QUOTES, 'UTF-8')).'</td></tr>'
                    .'<tr class="slip-line"><td>'.number_format($item['quantity'], 0).' '
                    .htmlspecialchars((string) $item['unit'], ENT_QUOTES, 'UTF-8')
                    .' &times; '.Currency::format($item['price'])
                    .($item['discount'] > 0 ? ' -'.Currency::format($item['discount']).'%' : '')
                    .'</td><td class="right">'.Currency::format($amount).'</td></tr>';
                continue;
            }

            $cells = '';
            foreach ($columns as $column) {
                if ($column === 'item') {
                    $cells .= '<td class="center col-item">'.$index.'</td>';
                } elseif ($column === 'quantity') {
                    $cells .= '<td class="center col-quantity">'.number_format($item['quantity'], 0).' '
                        .htmlspecialchars((string) $item['unit'], ENT_QUOTES, 'UTF-8').'</td>';
                } elseif ($column === 'topic') {
                    $cells .= '<td class="col-topic">'.nl2br(htmlspecialchars((string) $item['topic'], ENT_QUOTES, 'UTF-8')).'</td>';
                } elseif ($column === 'productno') {
                    $cells .= '<td class="col-productno">'.htmlspecialchars((string) $item['product_code'], ENT_QUOTES, 'UTF-8').'</td>';
                } elseif ($column === 'amount') {
                    $cells .= '<td class="right col-amount">'.Currency::format($amount).'</td>';
                } else {
                    $cells .= '<td class="right col-'.$column.'">'
                        .Currency::format(isset($item[$column]) ? $item[$column] : 0).'</td>';
                }
            }
            $html .= '<tr>'.$cells.'</tr>';
        }

        return ['html' => $html, 'sum' => $sum];
    }

    /**
     * หัวตารางรายการ ตามรายชื่อคอลัมน์
     *
     * @param array $columns
     *
     * @return string
     */
    protected function itemHead(array $columns)
    {
        $labels = self::columnLabels();

        $html = '';
        foreach ($columns as $column) {
            $label = isset($labels[$column]) ? $labels[$column] : $column;
            $html .= '<th class="col-'.$column.'">'.$label.'</th>';
        }

        return $html;
    }

    /**
     * คอลัมน์ของตารางรายการที่พิมพ์ได้ — ทะเบียนกลางที่เดียว
     *
     * ⚠️ ต้องตรงกับที่ itemRows() รู้จักเสมอ ชื่อที่ไม่อยู่ในนี้จะถูกพิมพ์เป็น
     * ตัวเลขเงินเปล่า ๆ (สาขา else ของ itemRows) โดยไม่มีอะไรฟ้อง — หน้าตั้งค่า
     * แม่แบบจึงต้องเสนอให้เลือกจากทะเบียนนี้เท่านั้น ไม่ใช่ให้พิมพ์ชื่อเอง
     *
     * @return array
     */
    public static function columnLabels()
    {
        return [
            'item' => '{LNG_Item}',
            'productno' => '{LNG_Product code}',
            'topic' => '{LNG_Detail}',
            'quantity' => '{LNG_Quantity}',
            'price' => '{LNG_Unit price}',
            'discount' => '{LNG_Discount}',
            'amount' => '{LNG_Amount}'
        ];
    }

    /**
     * ชุดคอลัมน์มาตรฐาน ใช้เมื่อแม่แบบยังไม่ได้เลือกไว้
     *
     * @return array
     */
    public static function defaultColumns()
    {
        return ['item', 'topic', 'quantity', 'price', 'discount', 'amount'];
    }

    /**
     * คอลัมน์ของแม่แบบหนึ่ง — กรองเอาเฉพาะชื่อที่พิมพ์ได้จริง
     *
     * @param array $template
     *
     * @return array
     */
    public static function templateColumns(array $template)
    {
        $known = self::columnLabels();
        $columns = [];
        foreach (explode(',', isset($template['columns']) ? (string) $template['columns'] : '') as $column) {
            $column = trim($column);
            if ($column !== '' && isset($known[$column]) && !in_array($column, $columns, true)) {
                $columns[] = $column;
            }
        }

        return empty($columns) ? self::defaultColumns() : $columns;
    }

    /**
     * ช่องลายเซ็นท้ายเอกสาร สร้างจากชื่อผู้ลงนามที่ตั้งไว้ในแม่แบบเอกสาร
     *
     * ระบบเดิมแยกเป็นไฟล์ละแบบ (IN/RET มี 3 ช่อง DES มี 2 INV/OUT มี 1)
     * ทั้งที่ข้อมูลอยู่ในฐานข้อมูลอยู่แล้ว ที่นี่จึงสร้างจากข้อมูลตรง ๆ
     * เพิ่ม/ลดผู้ลงนามได้จากหน้าตั้งค่าแม่แบบ ไม่ต้องแก้ไฟล์
     *
     * @param array $template แม่แบบเอกสาร
     * @param array $content  ตัวแทนค่าที่แทนแล้ว (ใช้ตราประทับ/ลายเซ็น/วันที่)
     * @param bool  $marks    false = ไม่ใส่รูปตราประทับ/ลายเซ็น (กระดาษม้วนพิมพ์รูปไม่สวย)
     *
     * @return string
     */
    protected function signatureBoxes(array $template, array $content, $marks = true)
    {
        $boxes = '';
        $names = [$template['signature_1'], $template['signature_2'], $template['signature_3']];
        $last = 0;
        foreach ($names as $i => $name) {
            if (trim((string) $name) !== '') {
                $last = $i;
            }
        }

        foreach ($names as $i => $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            // ตราประทับกับลายเซ็นบริษัทอยู่ช่องสุดท้ายช่องเดียว (ช่องผู้มีอำนาจ)
            $mark = '';
            if ($marks && $i === $last) {
                $mark = '<div class="marks">'
                    .($content['%STAMP%'] === '' ? '' : '<img class="stamp" src="'.$content['%STAMP%'].'" alt="">')
                    .($content['%SIGNATURE%'] === '' ? '' : '<img class="signature" src="'.$content['%SIGNATURE%'].'" alt="">')
                    .'</div>';
            }
            $boxes .= '<div class="sign-box">'
                .$mark
                .'<div class="sign-line"></div>'
                .'<div class="sign-name">'.strtr($name, $content).'</div>'
                .'<div class="sign-date">'.$content['%ORDERDATE%'].'</div>'
                .'</div>';
        }

        return $boxes;
    }

    /**
     * วาดเอกสารหนึ่งใบตามแม่แบบของชนิดเอกสารนั้น
     *
     * แบ่งหน้าตามจำนวนรายการต่อหน้าที่เลือกได้ตอนพิมพ์ รายการที่เกินขึ้นใบใหม่
     * พร้อมยอดยกไป/ยกมา ยอดรวมทั้งใบและช่องลายเซ็นอยู่ที่หน้าสุดท้ายเท่านั้น
     *
     * @param object  $order
     * @param Request $request
     * @param array   $target  พารามิเตอร์ที่พากลับมาหน้านี้ (แถบตัวเลือกตอนพิมพ์ใช้)
     *
     * @return Response
     */
    protected function renderBilling($order, Request $request, array $target = [])
    {
        $built = $this->buildBilling($order, $request);
        if ($built === null) {
            return $this->errorResponse('No data available', 404);
        }

        return $this->wrapPrintPage($built['title'], $built['html'], $built, $target);
    }

    /**
     * แม่แบบสำรองสำหรับเอกสารที่หาแม่แบบของชนิดตัวเองไม่เจอ
     *
     * ใช้กับข้อมูลเก่าที่ชนิดเอกสารว่าง หรือชนิดที่ไซต์ลบแม่แบบทิ้งไปแล้ว
     * คอลัมน์ตกไปใช้ชุดปริยาย และไม่มีช่องลายเซ็น หัวเรื่องใช้คำกลาง ๆ ว่า
     * "เอกสาร" เพื่อไม่ให้แอบอ้างเป็นชนิดที่ไม่ใช่
     *
     * @param object $order
     *
     * @return array
     */
    protected static function fallbackTemplate($order)
    {
        return [
            'document_type' => (string) $order->document_type,
            // ⚠️ ห้ามใช้คีย์ 'Document' — แฟ้มภาษาแกนแปลว่า "บทความ" (ของ CMS)
            // หัวใบเสร็จจะขึ้นว่าบทความ ต้องใช้คีย์ของโมดูลเองที่แปลว่า "เอกสาร"
            'topic' => Language::get('Unspecified document'),
            'comment' => '',
            'due_date' => 0,
            'signature_1' => '',
            'signature_2' => '',
            'signature_3' => '',
            'columns' => '',
            'in_stock' => 0,
            'cut_stock' => 0,
            'movement_type' => null,
            'published' => 0
        ];
    }

    /**
     * ประกอบใบเอกสารทุกหน้าของเอกสารหนึ่งใบ (ยังไม่ห่อด้วยหน้าเว็บ)
     *
     * แยกจาก renderBilling เพื่อให้พิมพ์หลายใบต่อกันในหน้าเดียวได้
     *
     * @param object  $order
     * @param Request $request
     *
     * @return array|null
     */
    protected function buildBilling($order, Request $request)
    {
        $template = Document::template($order->document_type);
        if ($template === null) {
            // ⚠️ ข้อมูลจริงของ oms มีเอกสารเก่าที่ `document_type` ว่าง (ตกค้างจาก
            // ระบบรุ่นก่อนหน้า) และไซต์อาจลบแม่แบบของชนิดที่เลิกใช้ไปแล้วโดยที่
            // เอกสารเดิมยังอยู่ — ทั้งสองกรณีต้อง "เปิดและพิมพ์ได้" อยู่
            // ปฏิเสธด้วย 404 = เอกสารที่ออกไปแล้วกลายเป็นของที่เข้าไม่ถึงถาวร
            $template = self::fallbackTemplate($order);
        }

        // คอลัมน์ของตารางรายการมาจากแถวแม่แบบ ตั้งได้จากหน้าแม่แบบเอกสาร
        // (เดิมเป็นไฟล์ละชนิดใน modules/inventory/template/ ซึ่งทั้งห้าไฟล์มี
        // รายชื่อคอลัมน์ชุดเดียวกันทุกไบต์ การเลือกไฟล์จึงไม่เคยเปลี่ยนอะไรเลย)
        $columns = self::templateColumns($template);

        $papers = self::papers();
        $paper = $request->get('paper', 'a4')->filter('a-z0-9');
        if (!isset($papers[$paper])) {
            $paper = 'a4';
        }
        $sizing = $papers[$paper];

        // จำนวนรายการต่อหน้า เลือกได้ตอนพิมพ์ 0 = ไม่แบ่งหน้า (กระดาษม้วน)
        $perPage = $request->get('per_page')->exists()
            ? max(0, min(60, $request->get('per_page')->toInt()))
            : $sizing['per_page'];

        $body = $this->loadTemplate($sizing['slip'] ? 'slip' : 'sheet');
        if ($body === null) {
            return null;
        }

        $items = \Inventory\Document\Model::items($order->id);

        // กระดาษม้วนไม่มีความยาวตายตัว แต่ CSS ต้องระบุความสูงเป็นตัวเลขเสมอ
        // คำนวณจากเนื้อหาจริง ผู้ใช้ไม่ต้องกรอกเอง
        $slipHeight = self::rollLength($sizing, array_column($items, 'topic'));
        if ($slipHeight > 0) {
            $sizing['page'] = $sizing['page'].' '.$slipHeight.'mm';
            $sizing['h'] = 'auto';
        }

        $chunks = $perPage > 0 ? array_chunk($items, $perPage) : [$items];
        if (empty($chunks)) {
            $chunks = [[]];
        }
        $pages = count($chunks);

        // ยอดก่อนหักส่วนลดของทั้งใบ ต้องรู้ก่อน เพราะยอดสรุปอยู่หน้าสุดท้าย
        $all = $this->itemRows($items, $columns, 0);
        $subtotal = $all['sum'];

        $content = $this->placeholders($order);
        $content['%SUBTOTAL%'] = Currency::format($subtotal);
        $content['%DISCOUNT%'] = Currency::format($order->discount);
        $content['%AMOUNT%'] = Currency::format($subtotal - $order->discount);
        $content['%TAX%'] = Currency::format($order->tax);
        $content['%TOTAL%'] = Currency::format($subtotal - $order->discount - $order->tax);
        $content['%VAT%'] = Currency::format($order->vat);
        $content['%NETAMOUNT%'] = Currency::format($order->total);
        $content['%THAIBAHT%'] = Language::name() === 'th'
            ? Currency::bahtThai($order->total)
            : Currency::bahtEng($order->total);
        // ข้อความของแม่แบบเอกสารมีตัวแปรอยู่ข้างใน (เช่น %BANK% ในหมายเหตุการชำระเงิน)
        // ต้องแทนค่าก่อนเอาไปใส่ในหน้า เพราะ strtr แทนที่รอบเดียว ไม่ย้อนกลับไปอ่านซ้ำ
        $content['{TOPIC}'] = $template['topic'];
        $content['{COMMENT}'] = nl2br(strtr($template['comment'], $content));
        $content['%ITEMHEAD%'] = $this->itemHead($columns);
        $content['%COLSPAN%'] = count($columns);
        $content['%SIGNATURES%'] = $this->signatureBoxes($template, $content, !$sizing['slip']);
        $content['%PAGES%'] = $pages;
        $content['%ITEMS%'] = count($items);
        // เอกสารที่แม่แบบตั้งไว้ว่าไม่มีวันครบกำหนด ต้องไม่มีช่องนี้บนกระดาษเลย
        // (ตั้งได้ที่หน้าแม่แบบเอกสาร) ไม่ใช่เดาเอาจากค่าว่างอย่างเดียว
        if (empty($template['due_date'])) {
            $content['%DUEDATE%'] = '';
        }

        // ช่องที่ไม่มีข้อมูลต้องหายไปทั้งบรรทัด ไม่ใช่เหลือป้ายชื่อลอยอยู่เฉย ๆ
        foreach (['DUEDATE' => 'DUE', 'EMAIL' => 'EMAIL', 'TAXID' => 'TAXID', 'PHONE' => 'PHONE',
            'CONTACTOR' => 'CONTACTOR', 'BRANCH' => 'BRANCH',
            'AUTHORITYADDRESS' => 'AUTHORITYADDRESS', 'AUTHORITYPHONE' => 'AUTHORITYPHONE',
            'AUTHORITYEMAIL' => 'AUTHORITYEMAIL', 'AUTHORITYTAXID' => 'AUTHORITYTAXID'] as $key => $name) {
            $content['%'.$name.'CLASS%'] = trim((string) $content['%'.$key.'%']) === '' ? 'is-hidden' : '';
        }
        $content['%PAPERW%'] = $sizing['w'];
        $content['%PAPERH%'] = $sizing['h'];
        $content['%PAGESIZE%'] = $sizing['page'];

        $running = 0;
        $index = 0;
        $html = '';
        foreach ($chunks as $page => $pageItems) {
            $rows = $this->itemRows($pageItems, $columns, $index, $sizing['slip']);
            $index += count($pageItems);
            $brought = $running;
            $running += $rows['sum'];

            $one = $content;
            $one['%PAGE%'] = $page + 1;
            // หน้าสุดท้ายเท่านั้นที่มียอดสรุปและช่องลายเซ็น หน้าอื่นเป็นยอดยกไป
            $one['%SHEETCLASS%'] = $page + 1 === $pages ? 'is-last' : 'is-cont';
            $one['%ITEMROWS%'] = $rows['html'];
            $one['%PAGESUM%'] = Currency::format($rows['sum']);
            $one['%BROUGHT%'] = Currency::format($brought);
            $one['%CARRIED%'] = Currency::format($running);
            // หน้าแรกไม่มียอดยกมา ซ่อนบรรทัดนั้นด้วยคลาส
            $one['%BROUGHTCLASS%'] = $page === 0 ? 'is-hidden' : '';

            $html .= strtr($body['html'], $one);
        }

        return [
            'html' => $html,
            'title' => $template['topic'].' '.$order->order_no,
            'sizing' => $sizing,
            'paper' => $paper,
            'per_page' => $perPage,
            'slip_height' => $slipHeight,
            'papers' => $papers
        ];
    }

    /**
     * ห่อใบเอกสารด้วยหน้าเว็บสำหรับพิมพ์ พร้อมแถบตัวเลือกและช่องติ๊ก
     *
     * @param string $title
     * @param string $sheets  HTML ของทุกใบต่อกัน
     * @param array  $built   ผลจาก buildBilling (เอาขนาดกระดาษที่ใช้จริง)
     * @param array  $target  พารามิเตอร์ที่พากลับมาหน้านี้
     *
     * @return Response
     */
    protected function wrapPrintPage($title, $sheets, array $built, array $target)
    {
        $content = self::printToggles()
            .self::printControls(array_merge(['module' => 'inventory'], $target),
                $built['paper'], $built['per_page'])
            .'<div class="print-content">'.$sheets.'</div>';

        return self::printHtml($title, $content, [
            'body_class' => 'billing paper-'.$built['paper'],
            'page_style' => self::pageStyle($built['sizing'])
        ]);
    }


    /**
     * ขนาดซองมาตรฐาน (มิลลิเมตร) วัดตอนถือซองอ่านตามปกติ ด้านยาวอยู่แนวนอน
     *
     * @return array
     */
    protected function envelopeSizes()
    {
        return [
            'dl' => ['w' => 220, 'h' => 110, 'name' => 'DL / {LNG_Long envelope} 220 x 110'],
            'th9' => ['w' => 235, 'h' => 108, 'name' => '{LNG_Thai envelope No.} 9  235 x 108'],
            'c6' => ['w' => 162, 'h' => 114, 'name' => 'C6  162 x 114'],
            'c5' => ['w' => 229, 'h' => 162, 'name' => 'C5 / A5  229 x 162'],
            'c4' => ['w' => 324, 'h' => 229, 'name' => 'C4 / A4  324 x 229']
        ];
    }

    /**
     * GET export.php?module=inventory&typ=envelope&id=N — พิมพ์ซองจดหมาย
     *
     * ⚠️ ซองต้องป้อนเข้าเครื่องพิมพ์ "แนวตั้ง" เท่านั้น เพราะด้านยาวของซอง
     * (เช่น DL ยาว 220 มม.) กว้างกว่าทางเดินกระดาษของเครื่องพิมพ์ A4 (210 มม.)
     * ป้อนแบบนอนจึงไม่เข้าเครื่อง หน้านี้จึงตั้งหน้ากระดาษเป็นแนวตั้งเสมอ
     * แล้วหมุนข้อความ 90° ให้อ่านได้ถูกทางเมื่อซองออกมาจากเครื่อง
     *
     * @param Request $request
     *
     * @return Response
     */
    public function envelope(Request $request)
    {
        $order = $this->resolveOrder($request);
        if ($order instanceof Response) {
            return $order;
        }

        $body = $this->loadTemplate('envelope');
        if ($body === null) {
            return $this->errorResponse('Print template not found: envelope', 404);
        }

        // ที่มาของกระดาษ : ถาด A4 ปกติ หรือถาด/ช่องป้อนซองโดยเฉพาะ
        // ค่า dl เดิมคือ "เครื่องที่มีที่ป้อนซอง" รับไว้ด้วยเพื่อไม่ให้ลิงก์เก่าพัง
        $paper = $request->get('paper', 'a4')->filter('a-z0-9');
        if ($paper === 'dl' || $paper === 'env') {
            $paper = 'env';
        } else {
            $paper = 'a4';
        }

        $sizes = $this->envelopeSizes();
        $size = $request->get('size', 'dl')->filter('a-z0-9');
        if (!isset($sizes[$size])) {
            $size = 'dl';
        }
        // ขนาดซองเป็นมาตรฐานสำเร็จรูป ผู้ใช้เลือกอย่างเดียว ไม่ต้องกรอกตัวเลขเอง
        // (เดิมมีตัวเลือก "กำหนดขนาดเอง" พร้อมช่องกรอกกว้าง/สูง ซึ่งชวนงงว่าต้องใส่อะไร
        // ทั้งที่ซองที่ใช้จริงมีไม่กี่ขนาด ถ้าต้องการขนาดอื่นให้เพิ่มในรายการนี้)
        $envW = $sizes[$size]['w'];
        $envH = $sizes[$size]['h'];

        // ทิศการหมุน : เครื่องพิมพ์แต่ละรุ่นกินซองคนละด้าน เลือกได้ว่าจะหมุนทางไหน
        $rotate = $request->get('rotate', 'ccw')->filter('a-z') === 'cw' ? 'cw' : 'ccw';

        $content = $this->placeholders($order);
        $content['{TOPIC}'] = Language::get('Envelope');
        $content['%PAPER%'] = $paper;
        $content['%ENVW%'] = $envW;
        $content['%ENVH%'] = $envH;
        // หน้ากระดาษเป็นแนวตั้งเสมอ : ด้านสั้นของซองคือความกว้างของหน้า
        $content['%PAGEW%'] = $paper === 'a4' ? 210 : $envH;
        $content['%PAGEH%'] = $paper === 'a4' ? 297 : $envW;
        // หมุนรอบมุมบนซ้าย แล้วเลื่อนกลับเข้าหน้ากระดาษ
        $content['%ROTATE%'] = $rotate === 'cw'
            ? 'translateX('.$envH.'mm) rotate(90deg)'
            : 'translateY('.$envW.'mm) rotate(-90deg)';
        // ปลายซองที่เข้าเครื่องก่อน (ขอบบนของหน้ากระดาษ) อยู่คนละข้างกันตามทิศหมุน
        $content['%LEADEDGE%'] = $rotate === 'cw'
            ? Language::get('the end with the sender address')
            : Language::get('the end with the recipient address');

        // ซองอาจมาจากเอกสาร (id) หรือมาจากรายชื่อลูกค้า (customer_id)
        // ทุกลิงก์/ฟอร์มบนหน้านี้ต้องพากลับมาที่ตัวเดิม ไม่งั้นกดแล้วซองว่างเปล่า
        $target = empty($order->id)
            ? ['customer_id', (int) $order->customer_id]
            : ['id', (int) $order->id];
        $base = WEB_URL.'export.php?module=inventory&typ=envelope&'.$target[0].'='.$target[1];
        $content['%TARGETINPUT%'] = '<input type="hidden" name="'.$target[0].'" value="'.$target[1].'">';
        $content['%SWITCHURL%'] = $base.'&size='.$size.'&rotate='.$rotate
            .'&paper='.($paper === 'a4' ? 'env' : 'a4');

        // ตัวเลือกบนหน้าจอ — ประกอบที่นี่เพราะต้องรู้ว่าค่าไหนถูกเลือกอยู่
        $options = '';
        foreach ($sizes as $key => $one) {
            $options .= '<option value="'.$key.'"'.($key === $size ? ' selected' : '').'>'
                .$one['name'].'</option>';
        }
        $content['%SIZEOPTIONS%'] = $options;
        $content['%ROTATEOPTIONS%'] = '<option value="ccw"'.($rotate === 'ccw' ? ' selected' : '').'>'
            .'{LNG_Rotate left}</option><option value="cw"'.($rotate === 'cw' ? ' selected' : '').'>'
            .'{LNG_Rotate right}</option>';

        // เตือนเมื่อซองกว้างเกินถาด A4 — ถาดกระดาษกว้าง 210 มม. ใส่ไม่เข้าจริง ๆ
        $content['%TOOWIDE%'] = ($paper === 'a4' && $envH > 210)
            ? '<p class="guide-warn">'.Language::replace('This envelope is :w mm wide, wider than the :max mm paper tray. Use the envelope feeder or the manual feed slot.', [
                ':w' => $envH,
                ':max' => 210
            ]).'</p>'
            : '';

        $html = strtr($body['html'], $content);
        // เก็บเฉพาะบล็อกของที่มากระดาษที่เลือก (แม่แบบมีทั้งสองแบบอยู่ในไฟล์เดียว)
        $html = preg_replace('/<!--PAPER:(?!'.$paper.')[a-z0-9]+-->.*?<!--\/PAPER-->/us', '', $html);
        $html = preg_replace('/<!--PAPER:[a-z0-9]+-->|<!--\/PAPER-->/u', '', $html);

        $subject = $order->order_no === '' ? (string) $order->customer : $order->order_no;

        // ผังซองเป็นเรื่องเฉพาะของโมดูลนี้ จึงมีสไตล์ของตัวเองเพิ่มจากสไตล์กลาง
        return self::printHtml(Language::get('Envelope').' '.$subject, $html, [
            'body_class' => 'envelope envelope-'.$paper,
            'stylesheets' => ['modules/inventory/template/envelope.css']
        ]);
    }

    /**
     * GET export.php?module=inventory&typ=csv&type=product|customer
     *
     * ส่งออกสินค้า/ลูกค้าเป็นไฟล์ CSV — ใช้เป็นทั้งการสำรองข้อมูล และเป็น
     * "ไฟล์ตัวอย่าง" ของหน้านำเข้า (แก้ในไฟล์นี้แล้วนำเข้ากลับได้เลย
     * เพราะหัวตารางตรงกันพอดี)
     *
     * @param Request $request
     *
     * @return Response|void
     */
    public function csv(Request $request)
    {
        $login = $this->authenticateRequest($request);
        if (!$login) {
            return $this->errorResponse('Unauthorized', 401);
        }
        if (!ApiController::hasPermission($login, 'can_manage_inventory')) {
            return $this->errorResponse('Permission required', 403);
        }

        $type = $request->get('type')->filter('a-z');
        $spec = \Inventory\Import\Model::type($type);
        if ($spec === null) {
            return $this->errorResponse('No data available', 404);
        }

        // ส่ง header แล้วจบการทำงานเอง จึงไม่มี Response กลับไป
        self::sendCsv($type, $spec['columns'], \Inventory\Import\Model::rows($type));
    }

    /**
     * อ่านผังหน้ากระดาษจากไฟล์แม่แบบ
     *
     * เหลือแค่สามผังที่ใช้จริง : sheet (A4/A5) slip (กระดาษม้วน) envelope (ซองจดหมาย)
     * เดิมมีไฟล์รายชนิดเอกสารด้วย (IN/OUT/INV/RET/DES) แต่ทั้งห้าไฟล์มีรายชื่อ
     * คอลัมน์ชุดเดียวกันทุกไบต์ การเลือกไฟล์จึงไม่เคยเปลี่ยนอะไร — คอลัมน์ย้ายไป
     * อยู่ในแถวแม่แบบแล้ว (inventory_template.columns) ตั้งได้จากหน้าแม่แบบเอกสาร
     *
     * @param string $name
     *
     * @return array|null ['html' => ...]
     */
    protected function loadTemplate($name)
    {
        $name = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $name);
        // ผังใบเอกสารเป็นของกลาง ส่วนซองจดหมายเป็นของโมดูลนี้
        $file = in_array($name, ['sheet', 'slip'], true)
            ? self::viewDir().$name.'.html'
            : $this->templateDir().$name.'.html';
        if ($name === '' || !is_file($file)) {
            return null;
        }

        $source = file_get_contents($file);
        if (!preg_match('/<body>(.*?)<\/body>/isu', $source, $match)) {
            return null;
        }

        return ['html' => $match[1]];
    }

}
