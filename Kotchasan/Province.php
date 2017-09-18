<?php
/**
 * @filesource Kotchasan/Province.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Kotchasan;

use \Kotchasan\Language;

/**
 * รายชื่อจังหวัด  ของประเทศไทย
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Province
{

  /**
   * รายชื่อจังหวัด เรียงลำดับตามชื่อไทย
   *
   * @return array
   */
  private static function init()
  {
    return array(
      101 => array('th' => 'กระบี่', 'en' => 'Krabi'),
      102 => array('th' => 'กรุงเทพมหานคร', 'en' => 'Bankok'),
      103 => array('th' => 'กาญจนบุรี', 'en' => 'Kanchanaburi'),
      104 => array('th' => 'กาฬสินธุ์', 'en' => 'Kalsin'),
      105 => array('th' => 'กำแพงเพชร', 'en' => 'Kamphaeng phet'),
      106 => array('th' => 'ขอนแก่น', 'en' => 'Khon kean'),
      107 => array('th' => 'จันทบุรี', 'en' => 'Chanthaburi'),
      108 => array('th' => 'ฉะเชิงเทรา', 'en' => 'Chachoengsao'),
      109 => array('th' => 'ชลบุรี', 'en' => 'Chon buri'),
      110 => array('th' => 'ชัยนาท', 'en' => 'Chai nat'),
      111 => array('th' => 'ชัยภูมิ', 'en' => 'Chai yaphum'),
      112 => array('th' => 'ชุมพร', 'en' => 'Chum phon'),
      170 => array('th' => 'เชียงราย', 'en' => 'Chiang rai'),
      171 => array('th' => 'เชียงใหม่', 'en' => 'Chiang mai'),
      113 => array('th' => 'ตรัง', 'en' => 'Trang'),
      114 => array('th' => 'ตราด', 'en' => 'Trat'),
      115 => array('th' => 'ตาก', 'en' => 'Tak'),
      116 => array('th' => 'นครนายก', 'en' => 'Nakhon nayok'),
      117 => array('th' => 'นครปฐม', 'en' => 'Nakhon pathom'),
      118 => array('th' => 'นครพนม', 'en' => 'Nakhon phanom'),
      119 => array('th' => 'นครราชสีมา', 'en' => 'Nakhon ratchasima'),
      120 => array('th' => 'นครศรีธรรมราช', 'en' => 'Nakhon si thammarat'),
      121 => array('th' => 'นครสวรรค์', 'en' => 'Nakhon sawan'),
      122 => array('th' => 'นนทบุรี', 'en' => 'Nonthaburi'),
      123 => array('th' => 'นราธิวาส', 'en' => 'Narathiwat'),
      124 => array('th' => 'น่าน', 'en' => 'Nan'),
      177 => array('th' => 'บึงกาฬ', 'en' => 'Bueng Kan'),
      125 => array('th' => 'บุรีรัมย์', 'en' => 'Buri ram'),
      126 => array('th' => 'ปทุมธานี', 'en' => 'Pathum thani'),
      127 => array('th' => 'ประจวบคีรีขันธ์', 'en' => 'Prachuap khiri khan'),
      128 => array('th' => 'ปราจีนบุรี', 'en' => 'Prachinburi'),
      129 => array('th' => 'ปัตตานี', 'en' => 'Pattani'),
      130 => array('th' => 'พระนครศรีอยุธยา', 'en' => 'Phra Nakhon Si Ayutthaya'),
      131 => array('th' => 'พะเยา', 'en' => 'Phayao'),
      132 => array('th' => 'พังงา', 'en' => 'Phangnga'),
      133 => array('th' => 'พัทลุง', 'en' => 'Phatthalung'),
      134 => array('th' => 'พิจิตร', 'en' => 'Phichit'),
      135 => array('th' => 'พิษณุโลก', 'en' => 'Phitsanulok'),
      172 => array('th' => 'เพชรบุรี', 'en' => 'Phetchaburi'),
      173 => array('th' => 'เพชรบูรณ์', 'en' => 'Phetchabun'),
      175 => array('th' => 'แพร่', 'en' => 'Phrae'),
      136 => array('th' => 'ภูเก็ต', 'en' => 'Phuket'),
      137 => array('th' => 'มหาสารคาม', 'en' => 'Mahasarakham'),
      138 => array('th' => 'มุกดาหาร', 'en' => 'Mukdahan'),
      176 => array('th' => 'แม่ฮ่องสอน', 'en' => 'Mae hong son'),
      140 => array('th' => 'ยโสธร', 'en' => 'Yasothon'),
      139 => array('th' => 'ยะลา', 'en' => 'Yala'),
      144 => array('th' => 'ร้อยเอ็ด', 'en' => 'Roi et'),
      141 => array('th' => 'ระนอง', 'en' => 'Ranong'),
      142 => array('th' => 'ระยอง', 'en' => 'Rayong'),
      143 => array('th' => 'ราชบุรี', 'en' => 'Rachaburi'),
      145 => array('th' => 'ลพบุรี', 'en' => 'Lopburi'),
      174 => array('th' => 'เลย', 'en' => 'Loei'),
      146 => array('th' => 'ลำปาง', 'en' => 'Lampang'),
      147 => array('th' => 'ลำพูน', 'en' => 'Lamphun'),
      148 => array('th' => 'ศรีสะเกษ', 'en' => 'Srisaket'),
      149 => array('th' => 'สกลนคร', 'en' => 'Sakonnakhon'),
      150 => array('th' => 'สงขลา', 'en' => 'Songkhla'),
      151 => array('th' => 'สตูล', 'en' => 'Satun'),
      152 => array('th' => 'สมุทรปราการ', 'en' => 'Samutprakan'),
      153 => array('th' => 'สมุทรสงคราม', 'en' => 'Samut songkhram'),
      154 => array('th' => 'สมุทรสาคร', 'en' => 'Samut sakhon'),
      156 => array('th' => 'สระแก้ว', 'en' => 'Sra kaeo'),
      155 => array('th' => 'สระบุรี', 'en' => 'Saraburi'),
      157 => array('th' => 'สิงห์บุรี', 'en' => 'Singburi'),
      161 => array('th' => 'สุโขทัย', 'en' => 'Sukhothai'),
      158 => array('th' => 'สุพรรณบุรี', 'en' => 'Suphanburi'),
      159 => array('th' => 'สุราษฎร์ธานี', 'en' => 'Surat thani'),
      160 => array('th' => 'สุรินทร์', 'en' => 'Surin'),
      162 => array('th' => 'หนองคาย', 'en' => 'Nongkhai'),
      163 => array('th' => 'หนองบัวลำภู', 'en' => 'Nongbualamphu'),
      169 => array('th' => 'อ่างทอง', 'en' => 'Angthong'),
      164 => array('th' => 'อำนาจเจริญ', 'en' => 'Amnatcharoen'),
      165 => array('th' => 'อุดรธานี', 'en' => 'Udonthani'),
      166 => array('th' => 'อุตรดิตถ์', 'en' => 'Uttaradit'),
      167 => array('th' => 'อุทัยธานี', 'en' => 'Uthaithani'),
      168 => array('th' => 'อุบลราชธานี', 'en' => 'Uboratchathani')
    );
  }

  /**
   * อ่านชื่อจังหวัดจาก ISO ตามภาษา (ถ้าไม่มีใช้ภาษาอังกฤษ)
   *
   * @param int $iso
   * @return string คืนค่าว่างถ้าไม่พบ
   * @assert (102) [==] 'กรุงเทพมหานคร'
   */
  public static function get($iso)
  {
    $datas = self::init();
    $language = Language::name();
    $language = in_array($language, array_keys(reset($datas))) ? $language : 'en';
    return isset($datas[$iso]) ? $datas[$iso][$language] : '';
  }

  /**
   * list รายชื่อจังหวัดทั้งหมด  ตามภาษา (ถ้าไม่มีใช้ภาษาอังกฤษ)
   * สามารถนำไปใช้โดย Form ได้ทันที
   *
   * @return array
   */
  public static function all()
  {
    $datas = self::init();
    $language = Language::name();
    $language = in_array($language, array_keys(reset($datas))) ? $language : 'en';
    $result = array();
    foreach ($datas as $iso => $values) {
      $result[$iso] = $values[$language];
    }
    if ($language == 'en') {
      asort($result);
    }
    return $result;
  }
}
