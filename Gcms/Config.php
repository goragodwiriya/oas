<?php
/**
 * @filesource Gcms/Config.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Gcms;

/**
 * Config Class สำหรับ GCMS
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Config extends \Kotchasan\Config
{
  /**
   * รายชื่อฟิลด์จากตารางสมาชิก สำหรับตรวจสอบการ login
   *
   * @var array
   */
  public $login_fields = array('username');
  /**
   * สถานะสมาชิก
   * 0 สมาชิกทั่วไป
   * 1 ผู้ดูแลระบบ
   *
   * @var array
   */
  public $member_status = array(
    0 => 'สมาชิก',
    1 => 'ผู้ดูแลระบบ',
  );
  /**
   * สีของสมาชิกตามสถานะ
   *
   * @var array
   */
  public $color_status = array(
    0 => '#259B24',
    1 => '#FF0000',
    2 => '#FF6600',
    3 => '#3366FF',
    4 => '#902AFF',
    5 => '#660000',
    6 => '#336600',
  );
  /**
   * กำหนดอายุของแคช (วินาที)
   * 0 หมายถึงไม่มีการใช้งานแคช
   *
   * @var int
   */
  public $cache_expire = 5;
  /**
   * ไดเร็คทอรี่ template ที่ใช้งานอยู่ ตั้งแต่ DOCUMENT_ROOT
   * ไม่ต้องมี / ทั้งเริ่มต้นและปิดท้าย
   * เช่น skin/default
   *
   * @var string
   */
  public $skin = 'skin/default';
  /*
   * คีย์สำหรับการเข้ารหัส ควรแก้ไขให้เป็นรหัสของตัวเอง
   * ตัวเลขหรือภาษาอังกฤษเท่านั้น ไม่น้อยกว่า 10 ตัว
   *
   * @var string
   */
  public $password_key = '1245678912';
  /**
   * ถ้ากำหนดเป็น true บัญชี Facebook จะเป็นบัญชีตัวอย่าง
   * ได้รับสถานะแอดมิน (สมาชิกใหม่) แต่อ่านได้อย่างเดียว
   *
   * @var boolean
   */
  public $demo_mode = false;
  /**
   * App ID สำหรับการเข้าระบบด้วย Facebook https://gcms.in.th/howto/การขอ_app_id_จาก_facebook.html
   *
   * @var string
   */
  public $facebook_appId = '';
  /**
   * VAT
   *
   * @var int
   */
  public $vat = 7;
  /**
   * สถานะของบัญชีที่ตัดสต๊อก (ขาย)
   *
   * @var int
   */
  public $outstock_status = 6;
  /**
   * สถานะของบัญชีที่นับสต๊อก (ซื้อ)
   *
   * @var int
   */
  public $instock_status = 6;
}