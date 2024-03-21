<?php
/**
 * @filesource modules/index/models/editprofile.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Editprofile;

use Gcms\Login;
use Kotchasan\ArrayTool;
use Kotchasan\Database\Sql;
use Kotchasan\File;
use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * module=editprofile
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * อ่านข้อมูลสมาชิกที่ $id
     * คืนค่าข้อมูล array ไม่พบคืนค่า false
     *
     * @param int $id
     *
     * @return array|bool
     */
    public static function get($id)
    {
        if (!empty($id)) {
            $select = ['U.*'];
            $category = \Index\Category\Model::init(false);
            foreach ($category->items() as $k => $label) {
                $q = static::createQuery()
                    ->select(Sql::GROUP_CONCAT("D.value", $k, ',', true))
                    ->from('user_meta D')
                    ->where(array(array('D.member_id', 'U.id'), array('D.name', $k)));
                $select[] = array($q, $k);
            }
            $user = static::createQuery()
                ->from('user U')
                ->where(array('U.id', $id))
                ->toArray()
                ->first($select);
            if ($user) {
                // permission
                $user['permission'] = empty($user['permission']) ? [] : explode(',', trim($user['permission'], " \t\n\r\0\x0B,"));
                // categories
                foreach ($category->items() as $k => $label) {
                    $user[$k] = empty($user[$k]) ? [] : explode(',', trim($user[$k], " \t\n\r\0\x0B,"));
                }
                return $user;
            }
        }
        return false;
    }

    /**
     * บันทึกข้อมูล (editprofile.php)
     *
     * @param Request $request
     */
    public function submit(Request $request)
    {
        $ret = [];
        // session, token, สมาชิก และไม่ใช่สมาชิกตัวอย่าง
        if ($request->initSession() && $request->isSafe() && $login = Login::isMember()) {
            if (Login::notDemoMode($login)) {
                try {
                    // รับค่าจากการ POST
                    $save = array(
                        'username' => $request->post('register_username')->username(),
                        'phone' => $request->post('register_phone')->number(),
                        'name' => $request->post('register_name')->topic(),
                        'sex' => $request->post('register_sex')->topic(),
                        'id_card' => $request->post('register_id_card')->number(),
                        'address' => $request->post('register_address')->topic(),
                        'provinceID' => $request->post('register_provinceID')->number(),
                        'province' => $request->post('register_province')->topic(),
                        'zipcode' => $request->post('register_zipcode')->number(),
                        'country' => $request->post('register_country')->filter('A-Z')
                    );
                    // ชื่อตาราง
                    $table_user = $this->getTableName('user');
                    // database connection
                    $db = $this->db();
                    // แอดมิน
                    $isAdmin = Login::isAdmin();
                    // ตรวจสอบค่าที่ส่งมา
                    $user = self::get($request->post('register_id')->toInt());
                    if ($user) {
                        // ข้อมูลการเข้าระบบ
                        $login_fields = Language::get('LOGIN_FIELDS');
                        if ($isAdmin) {
                            // แอดมิน
                            $permission = $request->post('register_permission', [])->filter('a-z0-9_');
                            $save['permission'] = empty($permission) ? '' : ','.implode(',', $permission).',';
                            // แอดมินและไม่ใช่ตัวเอง สามารถอัปเดต status ได้
                            if ($login['id'] != $user['id']) {
                                $save['status'] = $request->post('register_status')->toInt();
                            }
                        } elseif ($login['id'] != $user['id']) {
                            // ไม่ใช่แอดมินแก้ไขได้แค่ตัวเองเท่านั้น
                            $user = null;
                        } else {
                            // สมาชิก ใช้ username เดิม
                            $save['username'] = $user['username'];
                        }
                    }
                    if ($user) {
                        if ($request->post('register_line_uid')->exists()) {
                            $save['line_uid'] = $request->post('register_line_uid')->filter('Ua-z0-9');
                        }
                        $save['id_card'] = empty($save['id_card']) ? null : $save['id_card'];
                        $save['phone'] = empty($save['phone']) ? null : $save['phone'];
                        // ตรวจสอบค่าที่ส่งมา
                        $checking = [];
                        foreach (self::$cfg->login_fields as $field) {
                            $k = $field == 'email' || $field == 'username' ? 'username' : $field;
                            if (empty($save[$k])) {
                                if ($request->post('register_'.$k)->exists()) {
                                    // กรอกค่าว่างมา เปลี่ยนเป็น null
                                    $save[$k] = null;
                                } else {
                                    // ไม่ได้ส่งค่ามา ใช้ค่าเดิม
                                    $save[$k] = $user[$k];
                                }
                            } else {
                                $checking[$k] = $save[$k];
                                // ตรวจสอบข้อมูลซ้ำ
                                $search = $db->first($table_user, array($k, $save[$k]));
                                if ($search && $search->id != $user['id']) {
                                    $ret['ret_register_'.$k] = Language::replace('This :name already exist', array(':name' => $login_fields[$k]));
                                }
                            }
                        }
                        if (empty($checking) && $user['active'] == 1) {
                            // สามารถเข้าระบบได้ต้องมีข้อมูลการเข้าระบบอย่างน้อย 1 รายการ
                            $k = reset(self::$cfg->login_fields);
                            $ret['ret_register_'.$k] = 'Please fill in';
                        }
                        // password
                        $password = $request->post('register_password')->password();
                        $repassword = $request->post('register_repassword')->password();
                        if (!empty($password) || !empty($repassword)) {
                            if (mb_strlen($password) < 4) {
                                // รหัสผ่านต้องไม่น้อยกว่า 4 ตัวอักษร
                                $ret['ret_register_password'] = 'this';
                            } elseif ($repassword != $password) {
                                // ถ้าต้องการเปลี่ยนรหัสผ่าน กรุณากรอกรหัสผ่านสองช่องให้ตรงกัน
                                $ret['ret_register_repassword'] = 'this';
                            }
                        }
                        if ($save['name'] == '') {
                            // ไม่ได้กรอก ชื่อ
                            $ret['ret_register_name'] = 'Please fill in';
                        }
                        // หมวดหมู่
                        $user_categories = [];
                        $category = \Index\Category\Model::init();
                        foreach ($category->items() as $k => $label) {
                            if (in_array($k, self::$cfg->categories_multiple)) {
                                if (!$category->isEmpty($k)) {
                                    $user_categories[$k] = $request->post('register_'.$k, [])->topic();
                                    if (empty($user_categories[$k]) && in_array($k, self::$cfg->categories_required)) {
                                        $ret['ret_register_'.$k] = 'Please select at least one item';
                                    }
                                }
                            } elseif ($isAdmin) {
                                $user_categories[$k] = $category->save($k, $request->post('register_'.$k.'_text')->topic());
                                if (empty($user_categories[$k]) && in_array($k, self::$cfg->categories_required)) {
                                    $ret['ret_register_'.$k] = 'Please fill in';
                                }
                            } elseif (!$category->isEmpty($k) && !in_array($k, self::$cfg->categories_disabled)) {
                                $user_categories[$k] = $request->post('register_'.$k)->topic();
                                if (empty($user_categories[$k]) && in_array($k, self::$cfg->categories_required)) {
                                    $ret['ret_register_'.$k] = 'Please fill in';
                                }
                            }
                        }
                        if (empty($ret)) {
                            // ไดเร็คทอรี่เก็บไฟล์
                            $dir = ROOT_PATH.DATA_FOLDER;
                            // อัปโหลดไฟล์
                            foreach ($request->getUploadedFiles() as $item => $file) {
                                // ชื่อไฟล์ที่ต้องการอัปโหลด
                                if ($item == 'avatar') {
                                    if (!File::makeDirectory($dir.$item.'/')) {
                                        // ไดเรคทอรี่ไม่สามารถสร้างได้
                                        $ret['ret_'.$item] = Language::replace('Directory %s cannot be created or is read-only.', DATA_FOLDER.$item.'/');
                                    } elseif ($request->post('delete_'.$item)->toBoolean() == 1) {
                                        // ลบรูปภาพ
                                        $image = $dir.$item.'/'.$user['id'].'.jpg';
                                        if (is_file($image)) {
                                            @unlink($image);
                                        }
                                    } elseif ($file->hasUploadFile()) {
                                        try {
                                            $image = $dir.$item.'/'.$user['id'].'.jpg';
                                            $file->cropImage(self::$cfg->member_img_typies, $image, self::$cfg->member_img_size, self::$cfg->member_img_size);
                                        } catch (\Exception $exc) {
                                            // ไม่สามารถอัปโหลดได้
                                            $ret['ret_'.$item] = Language::get($exc->getMessage());
                                        }
                                    } elseif ($err = $file->getErrorMessage()) {
                                        // upload error
                                        $ret['ret_'.$item] = $err;
                                    }
                                }
                            }
                        }
                        // บันทึก
                        if (empty($ret)) {
                            if (!empty($password)) {
                                $save['salt'] = \Kotchasan\Password::uniqid();
                                $save['password'] = sha1(self::$cfg->password_key.$password.$save['salt']);
                            }
                            // แก้ไข
                            $db->update($table_user, $user['id'], $save);
                            // user_meta
                            $table_user_meta = $this->getTableName('user_meta');
                            foreach ($user_categories as $key => $category) {
                                $db->delete($table_user_meta, array(array('member_id', $user['id']), array('name', $key)), 0);
                                if (in_array($key, self::$cfg->categories_multiple)) {
                                    foreach ($category as $item) {
                                        $db->insert($table_user_meta, array(
                                            'value' => $item,
                                            'name' => $key,
                                            'member_id' => $user['id']
                                        ));
                                    }
                                    $save[$key] = array_values($category);
                                } elseif (!empty($category)) {
                                    $db->insert($table_user_meta, array(
                                        'value' => $category,
                                        'name' => $key,
                                        'member_id' => $user['id']
                                    ));
                                    $save[$key] = [$category];
                                }
                            }
                            // log
                            \Index\Log\Model::add($login['id'], 'index', 'User', '{LNG_Editing your account} ID : '.$user['id'], $login['id']);
                            if ($login['id'] == $user['id']) {
                                // ตัวเอง อัปเดตข้อมูลการ login
                                if ($isAdmin) {
                                    $save['permission'] = $permission;
                                }
                                unset($save['password']);
                                $_SESSION['login'] = ArrayTool::replace($_SESSION['login'], $save);
                                // reload หน้าเว็บ
                                $ret['location'] = 'reload';
                            } else {
                                // ไปหน้าเดิม แสดงรายการ
                                $ret['location'] = $request->getUri()->postBack('index.php', array('module' => 'member', 'id' => null));
                            }
                            // คืนค่า
                            $ret['alert'] = Language::get('Saved successfully');
                            // เคลียร์
                            $request->removeToken();
                        }
                    }
                } catch (\Kotchasan\InputItemException $e) {
                    $ret['alert'] = $e->getMessage();
                }
            }
        }
        if (empty($ret)) {
            $ret['alert'] = Language::get('Unable to complete the transaction');
        }
        // คืนค่าเป็น JSON
        echo json_encode($ret);
    }
}
