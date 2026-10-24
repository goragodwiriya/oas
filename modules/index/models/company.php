<?php
/**
 * @filesource modules/index/models/company.php
 *
 * ผู้มีอำนาจลงนามของบริษัท
 *
 * ผู้มีอำนาจลงนามคือสมาชิกในระบบหนึ่งคน (company.authorized_id) ชื่อและลายเซ็น
 * บนเอกสารที่พิมพ์ออกมาจากสมาชิกคนนั้น — ลายเซ็นอัปโหลดที่หน้าข้อมูลส่วนตัว
 * (member_images.signature) ไม่มีรูปลายเซ็นของบริษัทแยกอีกแล้ว
 *
 * ของรุ่นก่อนที่ยังอาจค้างอยู่ในไซต์ที่ยังไม่ได้บันทึกหน้าตั้งค่าบริษัทใหม่
 *   company.authorized       ชื่อที่พิมพ์เอง (คีย์แบน authorized ของระบบเดิมด้วย)
 *   images/company_signature  รูปลายเซ็นบริษัท (signature.jpg ของระบบเดิมด้วย)
 * อ่านได้เพื่อให้เอกสารพิมพ์ออกมาเหมือนเดิมจนกว่าจะบันทึกหน้าตั้งค่า ซึ่งจะ
 * แปลงเป็นสมาชิกและย้ายรูปเข้าโปรไฟล์ให้ (adoptLegacySignature)
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Company;

/**
 * ผู้มีอำนาจลงนาม
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * สมาชิกที่เลือกเป็นผู้มีอำนาจลงนามได้ — ใช้งานอยู่และไม่ใช่สมาชิกทั่วไป (status 0)
     *
     * คนที่เลือกไว้แล้วอยู่ในรายการเสมอ แม้ภายหลังจะถูกปิดหรือลดสถานะ
     * ไม่งั้นแค่เปิดหน้าตั้งค่าแล้วกดบันทึก ค่าเดิมจะหายไปเงียบ ๆ
     *
     * @param int $selectedId
     *
     * @return array [{value, text}] ตัวแรกคือ "ไม่ได้ระบุ"
     */
    public static function authorizedOptions($selectedId = 0)
    {
        $rows = static::createQuery()
            ->select('id', 'name')
            ->from('user')
            ->where([['active', 1], ['status', '>', 0]])
            ->orderBy('name')
            ->execute(null, 'array')
            ->fetchAll();
        $selectedId = (int) $selectedId;
        $found = false;
        foreach ($rows as $row) {
            $found = $found || (int) $row['id'] === $selectedId;
        }
        if ($selectedId > 0 && !$found) {
            $user = self::user($selectedId);
            if ($user !== null) {
                $rows[] = $user;
            }
        }

        // ⚠️ ตัวเลือกว่างต้องมาจากที่นี่ — data-options-key ทิ้ง <option> ในเทมเพลต
        $options = [['value' => '', 'text' => '{LNG_Not specified}']];
        foreach ($rows as $row) {
            $options[] = ['value' => (string) $row['id'], 'text' => $row['name']];
        }

        return $options;
    }

    /**
     * id ของผู้มีอำนาจลงนาม
     *
     * ไซต์ที่ยังมีแต่ชื่อแบบเดิม หาสมาชิกที่ใช้งานอยู่และชื่อตรงกันทุกตัวอักษร
     * ชื่อซ้ำกันหลายคนไม่เดา (คืน 0) ให้ผู้ดูแลเลือกเองที่หน้าตั้งค่า
     *
     * @return int 0 = ยังไม่ได้ระบุ
     */
    public static function authorizedId()
    {
        $company = self::company();
        if (!empty($company['authorized_id'])) {
            return (int) $company['authorized_id'];
        }
        $name = self::legacyName();
        if ($name === '') {
            return 0;
        }
        $rows = static::createQuery()
            ->select('id')
            ->from('user')
            ->where([['name', $name], ['active', 1]])
            ->limit(2)
            ->execute(null, 'array')
            ->fetchAll();

        return count($rows) === 1 ? (int) $rows[0]['id'] : 0;
    }

    /**
     * ผู้มีอำนาจลงนามสำหรับพิมพ์ลงเอกสาร
     *
     * @return array|null ['id' => int, 'name' => string, 'signature' => URL หรือ ''] · null = ไม่ได้ระบุ
     */
    public static function authorized()
    {
        $id = self::authorizedId();
        $user = $id > 0 ? self::user($id) : null;
        if ($user !== null) {
            return ['id' => (int) $user['id'], 'name' => $user['name'], 'signature' => static::signatureUrl($user['id'])];
        }
        // ยังไม่ได้เลือกสมาชิก และชื่อเดิมไม่ตรงกับใคร — พิมพ์ชื่อเดิมไปก่อน
        $name = self::legacyName();

        return $name === '' ? null : ['id' => 0, 'name' => $name, 'signature' => static::signatureUrl(0)];
    }

    /**
     * URL ลายเซ็นของสมาชิก
     *
     * สมาชิกที่ยังไม่มีลายเซ็นของตัวเอง ใช้รูปลายเซ็นบริษัทแบบเดิมที่ยังค้างอยู่
     *
     * @param int $userId
     *
     * @return string ค่าว่างถ้าไม่มีรูป
     */
    public static function signatureUrl($userId)
    {
        $own = static::signaturePath($userId);
        if ((int) $userId > 0 && is_file(ROOT_PATH.$own)) {
            return WEB_URL.$own;
        }
        foreach (static::legacySignatures() as $legacy) {
            if (is_file(ROOT_PATH.$legacy)) {
                return WEB_URL.$legacy;
            }
        }

        return '';
    }

    /**
     * ย้ายรูปลายเซ็นบริษัทแบบเดิมเข้าโปรไฟล์ของผู้มีอำนาจลงนาม
     *
     * เรียกตอนบันทึกหน้าตั้งค่าบริษัท รูปนั้นคือลายเซ็นในช่องผู้มีอำนาจมาตลอด
     * สมาชิกที่ยังไม่มีลายเซ็นจึงรับไปเป็นของตัวเอง ถ้ามีอยู่แล้ว รูปเดิมถูกแทน
     * ไปแล้วจึงลบทิ้ง · signature.jpg ของระบบเดิมไม่แตะ (เป็นของโครงไฟล์ระบบเดิม)
     *
     * @param int $userId 0 = ยังไม่ได้เลือก ปล่อยรูปไว้ก่อน
     *
     * @return bool true เมื่อย้ายรูปเข้าโปรไฟล์
     */
    public static function adoptLegacySignature($userId)
    {
        $userId = (int) $userId;
        $legacy = ROOT_PATH.static::dataFolder().'images/company_signature'.self::$cfg->stored_img_type;
        if ($userId <= 0 || !is_file($legacy)) {
            return false;
        }
        $own = ROOT_PATH.static::signaturePath($userId);
        if (is_file($own)) {
            @unlink($legacy);

            return false;
        }
        if (!\Kotchasan\File::makeDirectory(dirname($own).'/')) {
            return false;
        }

        return @rename($legacy, $own);
    }

    /**
     * ที่เก็บลายเซ็นของสมาชิก (ชุดเดียวกับ member_images ของหน้าข้อมูลส่วนตัว)
     *
     * @param int $userId
     *
     * @return string path จาก ROOT_PATH
     */
    public static function signaturePath($userId)
    {
        return static::dataFolder().'signature/'.(int) $userId.self::$cfg->stored_img_type;
    }

    /**
     * โฟลเดอร์ข้อมูลของไซต์ (ชุดทดสอบทับเมธอดนี้ให้ชี้ไปที่ว่าง ไม่แตะรูปของไซต์จริง)
     *
     * @return string path จาก ROOT_PATH
     */
    protected static function dataFolder()
    {
        return DATA_FOLDER;
    }

    /**
     * ชื่อผู้มีอำนาจลงนามที่พิมพ์ไว้แบบเดิม
     *
     * @return string
     */
    protected static function legacyName()
    {
        $company = self::company();
        if (isset($company['authorized']) && trim((string) $company['authorized']) !== '') {
            return trim((string) $company['authorized']);
        }

        return isset(self::$cfg->authorized) ? trim((string) self::$cfg->authorized) : '';
    }

    /**
     * รูปลายเซ็นบริษัทแบบเดิม เรียงตามลำดับที่ใช้
     *
     * @return array path จาก ROOT_PATH
     */
    protected static function legacySignatures()
    {
        return [
            static::dataFolder().'images/company_signature'.self::$cfg->stored_img_type,
            static::dataFolder().'signature.jpg'
        ];
    }

    /**
     * @return array
     */
    protected static function company()
    {
        return isset(self::$cfg->company) && is_array(self::$cfg->company) ? self::$cfg->company : [];
    }

    /**
     * @param int $id
     *
     * @return array|null ['id', 'name']
     */
    protected static function user($id)
    {
        $rows = static::createQuery()
            ->select('id', 'name')
            ->from('user')
            ->where(['id', (int) $id])
            ->execute(null, 'array')
            ->fetchAll();

        return empty($rows) ? null : $rows[0];
    }
}
