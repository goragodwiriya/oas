<?php
/**
 * @filesource Gcms/Api.php
 *
 * API Base class Controller
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Gcms;

class Api extends \Kotchasan\ApiController
{
    /**
     * Authenticate request and prefer the auth model so user_meta is available.
     *
     * @param \Kotchasan\Http\Request $request
     *
     * @return object|null
     */
    protected function authenticateRequest(\Kotchasan\Http\Request $request)
    {
        $accessToken = $this->getAccessToken($request);

        if (!empty($accessToken) && class_exists('\\Index\\Auth\\Model')) {
            $user = \Index\Auth\Model::getUserByToken($accessToken);
            if ($user !== null) {
                return $user;
            }
        }

        return parent::authenticateRequest($request);
    }

    /**
     * Permission helper: check that user has a specific permission key or admin.
     * User object comes from authenticateRequest() and may contain `permission` as string or array.
     *
     * @param object|null $login User object from authenticateRequest()
     * @param string|array $permission Single permission or array of permissions (OR logic)
     * @param bool $allowAdmin If true, admin users (status=1) automatically pass
     *
     * @return bool True if user has permission
     */
    public static function hasPermission($login, $permission, $allowAdmin = true)
    {
        if (!$login) {
            return false;
        }
        if ($login->status === 1 && $allowAdmin) {
            return true;
        }
        $perms = [];
        if (isset($login->permission)) {
            if (is_array($login->permission)) {
                $perms = $login->permission;
            } elseif (is_string($login->permission)) {
                $perms = empty($login->permission) ? [] : explode(',', trim($login->permission, " \t\n\r\0\x0B,"));
            }
        }
        $perms = array_map('trim', $perms);
        $perms = array_filter($perms, fn($v) => $v !== '');
        // Handle both string and array
        if (is_array($permission)) {
            // OR logic: has ANY of the permissions
            foreach ($permission as $perm) {
                if (in_array($perm, $perms, true)) {
                    return true;
                }
            }
            return false;
        }

        return in_array($permission, $perms, true);
    }

    /**
     * Role helper: SuperAdmin (id=1) is always allowed.
     *
     * @param object $login
     *
     * @return bool
     */
    public static function isSuperAdmin($login)
    {
        return $login && isset($login->id) && (int) $login->id === 1;
    }

    /**
     * Role helper: Admin (status=1) but not superadmin
     *
     * @param object $login
     *
     * @return bool
     */
    public static function isAdmin($login)
    {
        return $login && isset($login->status) && $login->status === 1;
    }

    /**
     * Role helper: บัญชีนี้ "ไม่ใช่" บัญชีตัวอย่างของ demo_mode ใช่หรือไม่
     * บัญชีตัวอย่าง = เข้าระบบด้วยโซเชียล ในขณะที่เปิด demo_mode ไว้
     *
     * @param object $login
     *
     * @return bool true = บัญชีปกติ (ทำงานได้เต็มที่), false = บัญชีตัวอย่าง (อ่านอย่างเดียว)
     */
    public static function isNotDemoMode($login)
    {
        if (!$login || empty(self::$cfg->demo_mode)) {
            return true;
        }

        // คอลัมน์ user.social เป็น enum('user','facebook','google','line','telegram')
        // DEFAULT 'user' · บัญชีที่สมัครตามปกติจึงเก็บค่า 'user' ไม่ใช่ค่าว่าง
        // ถ้าเช็กแค่ !empty($login->social) แอดมินตัวจริงจะถูกนับเป็นบัญชีตัวอย่าง
        // ไปด้วยทันทีที่เปิด demo_mode
        $social = isset($login->social) ? strtolower(trim((string) $login->social)) : '';

        return $social === '' || $social === 'user';
    }

    /**
     * Role helper: Check if user can modify configuration or admin features
     * SuperAdmin always allowed, others need permission and must not be in demo mode
     *
     * @param object|null $login User object from authenticateRequest()
     * @param string|array $permission Single permission or array of permissions (default: ['can_config'])
     *
     * @return bool True if allowed to modify
     */
    public static function canModify($login, $permission = ['can_config'])
    {
        return self::isSuperAdmin($login)
            || (self::hasPermission($login, $permission) && self::isNotDemoMode($login));
    }

    /**
     * Get avatar URL for a user by ID
     *
     * @param int $id User ID
     * @return string|null Avatar URL or null if not found
     */
    public static function getAvatarUrl($id, $type = 'avatar')
    {
        $file = self::storedImage($type.'/'.$id);

        return $file === null ? null : WEB_URL.DATA_FOLDER.$file;
    }

    /**
     * ไฟล์รูปที่บันทึกไว้ หาได้ทุกนามสกุลรูปภาพ (นามสกุลตาม stored_img_type ก่อน)
     *
     * รูปถูกบันทึกด้วยนามสกุลตาม stored_img_type ณ ตอนอัปโหลด ถ้าค่านี้เปลี่ยนทีหลัง
     * (แก้ใน settings/config.php หรือย้ายมาจากระบบเดิม) ไฟล์ที่มีอยู่ยังเป็นนามสกุลเก่า
     * การหาด้วย stored_img_type อย่างเดียวทำให้โลโก้และรูปที่มีอยู่จริงหายจากทุกหน้า
     * (เว็บ enroll มีแค่ logo.webp ขณะที่ stored_img_type เป็น .jpg)
     *
     * @param string $name ที่อยู่ใต้ DATA_FOLDER ไม่มีนามสกุล เช่น 'images/logo'
     *
     * @return string|null ที่อยู่ใต้ DATA_FOLDER พร้อมนามสกุล หรือ null ถ้าไม่มีไฟล์
     */
    public static function storedImage($name)
    {
        foreach (self::storedImageTypes() as $ext) {
            if (is_file(ROOT_PATH.DATA_FOLDER.$name.$ext)) {
                return $name.$ext;
            }
        }

        return null;
    }

    /**
     * นามสกุลที่ storedImage() ลองหา เรียงจาก stored_img_type ก่อน
     *
     * @return array
     */
    public static function storedImageTypes()
    {
        return array_values(array_unique([self::$cfg->stored_img_type, '.webp', '.jpg', '.jpeg', '.png', '.gif']));
    }

    /**
     * ลบรูปที่บันทึกไว้ทุกนามสกุล (คู่กับ storedImage())
     *
     * ลบแค่นามสกุลปัจจุบันไม่พอ ไฟล์ที่บันทึกก่อนเปลี่ยน stored_img_type จะถูก storedImage() หาเจอ
     * แล้วรูปเก่ากลับมาแสดงแทน
     *
     * @param string $name ที่อยู่ใต้ DATA_FOLDER ไม่มีนามสกุล เช่น 'avatar/5'
     * @param bool $keepCurrent true = เก็บไฟล์นามสกุล stored_img_type ไว้ (ใช้ล้างรูปเก่าหลังอัปโหลดรูปใหม่)
     *
     * @return int|false จำนวนไฟล์ที่ลบ หรือ false ถ้าลบบางไฟล์ไม่ได้
     */
    public static function removeStoredImage($name, $keepCurrent = false)
    {
        $removed = 0;
        foreach (self::storedImageTypes() as $ext) {
            if ($keepCurrent && $ext === self::$cfg->stored_img_type) {
                continue;
            }
            $file = ROOT_PATH.DATA_FOLDER.$name.$ext;
            if (is_file($file)) {
                if (!@unlink($file)) {
                    return false;
                }
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * ฐาน URL ของหน้าที่ส่งคำขอมา ใช้ต่อเป็นลิงก์ในอีเมล (reset-password, activate, login)
     * เช่น http://site/forgot → http://site/ · http://site/admin/users → http://site/admin/
     * ลิงก์จึงพากลับไปหน้าเว็บหรือหน้าแอดมินตามที่ผู้ใช้เริ่มต้นไว้
     *
     * ⚠️ Referer มาจากผู้ส่งคำขอ ปลอมได้ · ถ้าเชื่อตรง ๆ คนร้ายขอรหัสผ่านใหม่ให้
     * บัญชีของคนอื่นพร้อม Referer ของเว็บตัวเอง ลิงก์ในอีเมล (ที่มี token) จะชี้ไป
     * เว็บของคนร้าย (password reset poisoning) จึงรับเฉพาะ Referer ที่อยู่ใต้
     * WEB_URL ของเว็บนี้ ไม่อย่างนั้นใช้ WEB_URL
     * (GCMS : WEB_URL มาจากโฮสต์ของคำขอ ซึ่งเป็นโฮสต์เดียวกับที่ใช้เลือกไซต์ลูกค้า)
     *
     * @param \Kotchasan\Http\Request $request
     *
     * @return string ลงท้ายด้วย /
     */
    public static function refererBaseUrl(\Kotchasan\Http\Request $request)
    {
        // ตัด query string ออกก่อน (index.php?module=forgot&x=a/b ไม่ให้ / ใน query ถูกนับ)
        $referer = preg_replace('/[?#].*$/s', '', (string) $request->server('HTTP_REFERER'));
        if ($referer === '' || stripos($referer, WEB_URL) !== 0) {
            return WEB_URL;
        }
        return substr($referer, 0, strrpos($referer, '/') + 1);
    }
}
