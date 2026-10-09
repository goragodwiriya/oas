<?php
/**
 * @filesource modules/inventory/models/email.php
 *
 * ส่งเอกสารให้ลูกค้าทางอีเมล พร้อมลิงก์เปิดเอกสารที่เปิดได้โดยไม่ต้องเข้าระบบ
 * ระบบเดิมคือ action `email` ของ module=inventory-orders
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Inventory\Email;

use Inventory\Base\Model as Base;
use Kotchasan\Language;

/**
 * Model ส่งเอกสารทางอีเมล
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * ไฟล์แม่แบบอีเมล
     *
     * @return string
     */
    public static function templateFile()
    {
        return ROOT_PATH.'modules/inventory/template/email.html';
    }

    /**
     * กุญแจของเอกสารสำหรับใส่ในลิงก์ที่ส่งให้ลูกค้า
     *
     * ⚠️ ระบบเดิมใช้ md5 ของ id เอกสาร (md5(1), md5(2), ...) ซึ่งเดาได้ทั้งหมด
     * ภายในไม่กี่วินาที เท่ากับเอกสารทุกใบเปิดดูได้จากภายนอก ที่นี่จึงสุ่มกุญแจใหม่
     * แต่ "ไม่แตะ" กุญแจเดิมที่มีอยู่แล้ว เพราะลิงก์เก่าที่ส่งให้ลูกค้าไปแล้วต้องใช้ได้ต่อ
     *
     * @param int    $id      id ของเอกสาร
     * @param string $current กุญแจเดิมที่บันทึกไว้ (ถ้ามี)
     *
     * @return string
     */
    public static function documentKey($id, $current = '')
    {
        if (preg_match('/^[0-9a-zA-Z]{32}$/', (string) $current)) {
            return $current;
        }

        $key = bin2hex(random_bytes(16));
        \Kotchasan\DB::create()->update(Base::table('orders'), ['id', (int) $id], ['order' => $key]);

        return $key;
    }

    /**
     * ลิงก์เปิดเอกสารสำหรับลูกค้า (ไม่ต้องเข้าระบบ ตรวจสิทธิ์ด้วยกุญแจในลิงก์)
     *
     * @param string $key
     *
     * @return string
     */
    public static function publicUrl($key)
    {
        return WEB_URL.'export.php?module=inventory&typ=print&order='.$key;
    }

    /**
     * อ่านแม่แบบอีเมล แล้วแทนค่าตัวแปร
     *
     * แปลภาษาก่อนแทนค่าตัวแปรเสมอ ไม่งั้นข้อมูลของลูกค้าที่บังเอิญมี {LNG_...}
     * อยู่ข้างในจะถูกเอาไปแปลด้วย
     *
     * @param array $variables
     *
     * @return array|null ['subject' => ..., 'body' => ...] หรือ null ถ้าไม่มีไฟล์แม่แบบ
     */
    public static function compose(array $variables)
    {
        $file = self::templateFile();
        if (!is_file($file)) {
            return null;
        }

        $source = Language::trans(file_get_contents($file));
        if (!preg_match('/<subject>(.*?)<\/subject>/isu', $source, $subject)
            || !preg_match('/<body>(.*?)<\/body>/isu', $source, $body)
        ) {
            return null;
        }

        // หัวเรื่องเป็นข้อความล้วน ไม่ต้องแปลงอักขระ HTML แต่ต้องตัดขึ้นบรรทัดใหม่ทิ้ง
        // ไม่งั้นค่าที่มี \n แทรกหัวจดหมายเพิ่มเองได้ (mail header injection)
        $plain = [];
        $escaped = [];
        foreach ($variables as $name => $value) {
            $value = is_scalar($value) ? (string) $value : '';
            $plain['%'.$name.'%'] = str_replace(["\r", "\n"], '', $value);
            $escaped['%'.$name.'%'] = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        }

        return [
            'subject' => trim(strtr($subject[1], $plain)),
            'body' => strtr($body[1], $escaped)
        ];
    }

    /**
     * ส่งเอกสารหนึ่งใบทางอีเมล
     *
     * @param int $orderId
     *
     * @return array ['sent' => bool, 'email' => string, 'error' => string]
     */
    public static function send($orderId)
    {
        // ⚠️ ระบบเดิม join แบบ INNER เอกสารขายสด (ไม่มีลูกค้า) จึงเงียบหายไปเฉย ๆ
        // ไม่มีข้อความบอกว่าทำไมไม่ส่ง ที่นี่ใช้ LEFT แล้วบอกเหตุผลกลับไปทุกกรณี
        $rows = static::createQuery()
            ->select('O.id', 'O.order_no', 'O.status', 'O.order', 'O.customer_id')
            ->selectRaw('U.`name` AS `contactor`')
            ->selectRaw('U.`company` AS `company`')
            ->selectRaw('U.`email` AS `email`')
            ->from('orders O')
            ->join('customer U', ['U.id', 'O.customer_id'], 'LEFT')
            ->where(['O.id', (int) $orderId])
            ->execute(null, 'array')
            ->fetchAll();

        if (empty($rows)) {
            return ['sent' => false, 'email' => '', 'error' => Language::get('No data available')];
        }

        $order = $rows[0];
        $label = $order['order_no'] === '' ? '#'.$order['id'] : $order['order_no'];
        $email = trim((string) $order['email']);

        if ($email === '') {
            // ⚠️ ระบบเดิมเขียนทับที่อยู่ผู้รับด้วย admin@goragod.com ทุกครั้ง
            // ($search->email = 'admin@goragod.com';) ลูกค้าจึงไม่เคยได้รับเอกสารเลย
            // สักฉบับ ที่นี่ส่งถึงอีเมลของลูกค้าจริง และบอกให้รู้เมื่อลูกค้ายังไม่มีอีเมล
            return [
                'sent' => false,
                'email' => '',
                'error' => $label.' : '.Language::get('Customer does not have an email address')
            ];
        }

        $template = \Inventory\Document\Model::template($order['document_type']);
        $name = $order['contactor'] === null || $order['contactor'] === '' ? $order['company'] : $order['contactor'];
        $company = $order['company'] === null || $order['company'] === '' ? $order['contactor'] : $order['company'];

        $mail = self::compose([
            // ชื่อเอกสารมาจากแม่แบบเอกสารจริง (ใบเสนอราคา ใบแจ้งหนี้ ...)
            // ระบบเดิมใช้ ORDER_STATUS ซึ่งเป็นชุดตัวเลขเก่า ไม่ตรงกับชนิดเอกสารที่ใช้จริง
            'TOPIC' => $template === null ? Language::get('Order') : $template['topic'],
            'ORDERNO' => (string) $order['order_no'],
            'NAME' => (string) $name,
            'COMPANY' => (string) $company,
            'URL' => self::publicUrl(self::documentKey($order['id'], $order['order'])),
            'WEBTITLE' => strip_tags((string) self::$cfg->web_title),
            'WEBURL' => WEB_URL
        ]);

        if ($mail === null) {
            return ['sent' => false, 'email' => $email, 'error' => Language::get('Email template not found')];
        }

        $result = \Kotchasan\Email::send($email, null, $mail['subject'], $mail['body']);
        if ($result->error()) {
            return ['sent' => false, 'email' => $email, 'error' => $label.' : '.$result->getErrorMessage()];
        }

        return ['sent' => true, 'email' => $email, 'error' => ''];
    }
}
