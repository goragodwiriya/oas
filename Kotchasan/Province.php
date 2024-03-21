<?php
/**
 * @filesource Kotchasan/Province.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan;

/**
 * This class provides a list of provinces in Thailand and Laos.
 *
 * @see https://www.kotchasan.com/
 */
class Province
{
    /**
     * Load provinces based on the selected country.
     * If not specified, it uses Thailand as the default country.
     *
     * @param string $country
     *
     * @return array
     */
    private static function init($country)
    {
        if (method_exists('Kotchasan\Province', $country)) {
            return \Kotchasan\Province::$country();
        } else {
            return [];
        }
    }

    /**
     * Get a list of all provinces.
     * It returns the names of provinces in the specified language (defaults to English).
     *
     * @param string $country (default: 'TH')
     *
     * @return array
     */
    public static function all($country = 'TH')
    {
        $datas = self::init($country);
        $result = [];
        if (!empty($datas)) {
            $language = Language::name();
            $language = in_array($language, array_keys(reset($datas))) ? $language : 'en';
            $result = [];
            foreach ($datas as $iso => $values) {
                $result[$iso] = $values[$language];
            }
            if ($language == 'en') {
                asort($result);
            }
        }
        return $result;
    }

    /**
     * Get a list of countries with installed provinces.
     *
     * @return array
     */
    public static function countries()
    {
        return array('TH', 'LA');
    }

    /**
     * Get the name of a province based on its ISO code and language.
     * If the language is not specified, it uses the current language.
     * Returns an empty string if the province is not found.
     *
     * @assert (10) [==] 'กรุงเทพมหานคร'
     *
     * @param int    $iso
     * @param string $lang
     * @param string $country (default: 'TH')
     *
     * @return string
     */
    public static function get($iso, $lang = '', $country = 'TH')
    {
        $datas = self::init($country);
        if (empty($lang)) {
            $lang = Language::name();
        }
        $lang = in_array($lang, array_keys(reset($datas))) ? $lang : 'en';
        return isset($datas[$iso]) ? $datas[$iso][$lang] : '';
    }

    /**
     * Get the ISO code of a province based on its name and language.
     *
     * @param string $province The name of the province
     * @param string $lang The language code for the desired language (e.g., 'th' for Thai, 'en' for English)
     * @param string $country (default: 'TH')
     *
     * @return string The ISO code of the province
     */
    public static function isoFromProvince($province, $lang = '', $country = 'TH')
    {
        $datas = self::init($country);
        if (empty($lang)) {
            $lang = Language::name();
        }
        $lang = in_array($lang, array_keys(reset($datas))) ? $lang : 'en';
        $result = '';
        foreach ($datas as $iso => $items) {
            if ($items[$lang] === $province) {
                $result = $iso;
                break;
            }
        }
        return $result;
    }

    /**
     * List of provinces in Thailand, sorted by Thai name.
     *
     * @return array An array containing province data for Thailand
     */
    private static function TH()
    {
        return array(
            '81' => array('th' => 'กระบี่', 'en' => 'Krabi'),
            '10' => array('th' => 'กรุงเทพมหานคร', 'en' => 'Bangkok'),
            '71' => array('th' => 'กาญจนบุรี', 'en' => 'Kanchanaburi'),
            '46' => array('th' => 'กาฬสินธุ์', 'en' => 'Kalasin'),
            '62' => array('th' => 'กำแพงเพชร', 'en' => 'KamphaengPhet'),
            '40' => array('th' => 'ขอนแก่น', 'en' => 'KhonKaen'),
            '22' => array('th' => 'จันทบุรี', 'en' => 'Chanthaburi'),
            '24' => array('th' => 'ฉะเชิงเทรา', 'en' => 'Chachoengsao'),
            '20' => array('th' => 'ชลบุรี', 'en' => 'ChonBuri'),
            '18' => array('th' => 'ชัยนาท', 'en' => 'ChaiNat'),
            '36' => array('th' => 'ชัยภูมิ', 'en' => 'Chaiyaphum'),
            '86' => array('th' => 'ชุมพร', 'en' => 'Chumphon'),
            '57' => array('th' => 'เชียงราย', 'en' => 'ChiangRai'),
            '50' => array('th' => 'เชียงใหม่', 'en' => 'ChiangMai'),
            '92' => array('th' => 'ตรัง', 'en' => 'Trang'),
            '23' => array('th' => 'ตราด', 'en' => 'Trat'),
            '63' => array('th' => 'ตาก', 'en' => 'Tak'),
            '26' => array('th' => 'นครนายก', 'en' => 'NakhonNayok'),
            '73' => array('th' => 'นครปฐม', 'en' => 'NakhonPathom'),
            '48' => array('th' => 'นครพนม', 'en' => 'NakhonPhanom'),
            '30' => array('th' => 'นครราชสีมา', 'en' => 'NakhonRatchasima'),
            '80' => array('th' => 'นครศรีธรรมราช', 'en' => 'NakhonSiThammarat'),
            '60' => array('th' => 'นครสวรรค์', 'en' => 'NakhonSawan'),
            '12' => array('th' => 'นนทบุรี', 'en' => 'Nonthaburi'),
            '96' => array('th' => 'นราธิวาส', 'en' => 'Narathiwat'),
            '55' => array('th' => 'น่าน', 'en' => 'Nan'),
            '97' => array('th' => 'บึงกาฬ', 'en' => 'buogkan'),
            '31' => array('th' => 'บุรีรัมย์', 'en' => 'BuriRam'),
            '13' => array('th' => 'ปทุมธานี', 'en' => 'PathumThani'),
            '77' => array('th' => 'ประจวบคีรีขันธ์', 'en' => 'PrachuapKhiriKhan'),
            '25' => array('th' => 'ปราจีนบุรี', 'en' => 'PrachinBuri'),
            '94' => array('th' => 'ปัตตานี', 'en' => 'Pattani'),
            '14' => array('th' => 'พระนครศรีอยุธยา', 'en' => 'PhraNakhonSiAyutthaya'),
            '56' => array('th' => 'พะเยา', 'en' => 'Phayao'),
            '82' => array('th' => 'พังงา', 'en' => 'Phangnga'),
            '93' => array('th' => 'พัทลุง', 'en' => 'Phatthalung'),
            '66' => array('th' => 'พิจิตร', 'en' => 'Phichit'),
            '65' => array('th' => 'พิษณุโลก', 'en' => 'Phitsanulok'),
            '76' => array('th' => 'เพชรบุรี', 'en' => 'Phetchaburi'),
            '67' => array('th' => 'เพชรบูรณ์', 'en' => 'Phetchabun'),
            '54' => array('th' => 'แพร่', 'en' => 'Phrae'),
            '83' => array('th' => 'ภูเก็ต', 'en' => 'Phuket'),
            '44' => array('th' => 'มหาสารคาม', 'en' => 'MahaSarakham'),
            '49' => array('th' => 'มุกดาหาร', 'en' => 'Mukdahan'),
            '58' => array('th' => 'แม่ฮ่องสอน', 'en' => 'MaeHongSon'),
            '35' => array('th' => 'ยโสธร', 'en' => 'Yasothon'),
            '95' => array('th' => 'ยะลา', 'en' => 'Yala'),
            '45' => array('th' => 'ร้อยเอ็ด', 'en' => 'RoiEt'),
            '85' => array('th' => 'ระนอง', 'en' => 'Ranong'),
            '21' => array('th' => 'ระยอง', 'en' => 'Rayong'),
            '70' => array('th' => 'ราชบุรี', 'en' => 'Ratchaburi'),
            '16' => array('th' => 'ลพบุรี', 'en' => 'Loburi'),
            '52' => array('th' => 'ลำปาง', 'en' => 'Lampang'),
            '51' => array('th' => 'ลำพูน', 'en' => 'Lamphun'),
            '42' => array('th' => 'เลย', 'en' => 'Loei'),
            '33' => array('th' => 'ศรีสะเกษ', 'en' => 'SiSaKet'),
            '47' => array('th' => 'สกลนคร', 'en' => 'SakonNakhon'),
            '90' => array('th' => 'สงขลา', 'en' => 'Songkhla'),
            '91' => array('th' => 'สตูล', 'en' => 'Satun'),
            '11' => array('th' => 'สมุทรปราการ', 'en' => 'SamutPrakan'),
            '75' => array('th' => 'สมุทรสงคราม', 'en' => 'SamutSongkhram'),
            '74' => array('th' => 'สมุทรสาคร', 'en' => 'SamutSakhon'),
            '27' => array('th' => 'สระแก้ว', 'en' => 'SaKaeo'),
            '19' => array('th' => 'สระบุรี', 'en' => 'Saraburi'),
            '17' => array('th' => 'สิงห์บุรี', 'en' => 'SingBuri'),
            '64' => array('th' => 'สุโขทัย', 'en' => 'Sukhothai'),
            '72' => array('th' => 'สุพรรณบุรี', 'en' => 'SuphanBuri'),
            '84' => array('th' => 'สุราษฎร์ธานี', 'en' => 'SuratThani'),
            '32' => array('th' => 'สุรินทร์', 'en' => 'Surin'),
            '43' => array('th' => 'หนองคาย', 'en' => 'NongKhai'),
            '39' => array('th' => 'หนองบัวลำภู', 'en' => 'NongBuaLamPhu'),
            '15' => array('th' => 'อ่างทอง', 'en' => 'AngThong'),
            '37' => array('th' => 'อำนาจเจริญ', 'en' => 'AmnatCharoen'),
            '41' => array('th' => 'อุดรธานี', 'en' => 'UdonThani'),
            '53' => array('th' => 'อุตรดิตถ์', 'en' => 'Uttaradit'),
            '61' => array('th' => 'อุทัยธานี', 'en' => 'UthaiThani'),
            '34' => array('th' => 'อุบลราชธานี', 'en' => 'UbonRatchathani')
        );
    }

    /**
     * List of provinces in Laos, sorted by Lao name.
     *
     * @return array An array containing province data for Laos
     */
    private static function LA()
    {
        return array(
            '12' => array('th' => 'คำม่วน', 'la' => 'ຄໍາມ່ວນ', 'en' => 'Khammouane'),
            '16' => array('th' => 'จำปาศักดิ์', 'la' => 'ຈຳປາສັກ', 'en' => 'Champasak'),
            '09' => array('th' => 'เชียงขวาง', 'la' => 'ຊຽງຂວາງ', 'en' => 'Xiangkhouang'),
            '08' => array('th' => 'ไชยบุรี', 'la' => 'ໄຊຍະບູລີ', 'en' => 'Sainyabuli'),
            '18' => array('th' => 'ไชยสมบูรณ์', 'la' => 'ໄຊສົມບູນ', 'en' => 'Xaisomboun'),
            '15' => array('th' => 'เซกอง', 'la' => 'ເຊກອງ', 'en' => 'Sekong'),
            '11' => array('th' => 'บอลิคำไซ', 'la' => 'ບໍລິຄໍາໄຊ', 'en' => 'Bolikhamsai'),
            '05' => array('th' => 'บ่อแก้ว', 'la' => 'ບໍ່ແກ້ວ', 'en' => 'Bokeo'),
            '02' => array('th' => 'พงสาลี', 'la' => 'ຜົ້ງສາລີ', 'en' => 'Phongsaly'),
            '10' => array('th' => 'เวียงจันทน์', 'la' => 'ວຽງຈັນ', 'en' => 'Vientiane'),
            '14' => array('th' => 'สาละวัน', 'la' => 'ສາລະວັນ', 'en' => 'Salavan'),
            '13' => array('th' => 'สุวรรณเขต', 'la' => 'ສະຫວັນນະເຂດ', 'en' => 'Savannakhet'),
            '03' => array('th' => 'หลวงน้ำทา', 'la' => 'ຫລວງນໍ້າທາ', 'en' => 'Luang Namtha'),
            '06' => array('th' => 'หลวงพระบาง', 'la' => 'ຫລວງພະບາງ', 'en' => 'Luang Prabang'),
            '07' => array('th' => 'หัวพัน', 'la' => 'ຫົວພັນ', 'en' => 'Houaphanh'),
            '17' => array('th' => 'อัตตะปือ', 'la' => 'ອັດຕະປື', 'en' => 'Attapeu'),
            '04' => array('th' => 'อุดมไซ', 'la' => 'ອຸດົມໄຊ', 'en' => 'Oudomxay'),
            '01' => array('th' => 'นครหลวงเวียงจันทน์', 'la' => 'ນະຄອນຫຼວງວຽງຈັນ', 'en' => 'Oudomxay')
        );
    }
}
