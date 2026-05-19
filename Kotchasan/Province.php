<?php

namespace Kotchasan;

/**
 * Kotchasan Province Class
 *
 * This class provides methods to retrieve province data based on the selected country.
 * It supports multiple countries and allows fetching province names in different languages.
 *
 * @package Kotchasan
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
     * Get a list of all provinces.
     * It returns the names of provinces in the specified language (defaults to English).
     *
     * @param string $country (default: 'TH')
     *
     * @return array
     */
    public static function getOptions($country = 'TH')
    {
        $datas = self::init($country);
        $result = [];
        if (!empty($datas)) {
            $language = Language::name();
            $language = in_array($language, array_keys(reset($datas))) ? $language : 'en';
            $result = [];
            foreach ($datas as $iso => $values) {
                $result[] = ['value' => $iso, 'text' => $values[$language]];
            }
            if ($language == 'en') {
                usort($result, fn($a, $b) => strcmp($a['text'], $b['text']));
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
        return ['TH', 'LA'];
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
        if (empty($datas)) {
            return '';
        }
        if (empty($lang)) {
            $lang = Language::name();
        }
        $first = reset($datas);
        $lang = in_array($lang, array_keys($first)) ? $lang : 'en';
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
        if (empty($datas)) {
            return '';
        }
        if (empty($lang)) {
            $lang = Language::name();
        }
        $first = reset($datas);
        $lang = in_array($lang, array_keys($first)) ? $lang : 'en';
        $result = '';
        foreach ($datas as $iso => $items) {
            if ($items[$lang] === $province) {
                $result = (string) $iso;
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
        return [
            '81' => ['th' => 'กระบี่', 'en' => 'Krabi'],
            '10' => ['th' => 'กรุงเทพมหานคร', 'en' => 'Bangkok'],
            '71' => ['th' => 'กาญจนบุรี', 'en' => 'Kanchanaburi'],
            '46' => ['th' => 'กาฬสินธุ์', 'en' => 'Kalasin'],
            '62' => ['th' => 'กำแพงเพชร', 'en' => 'KamphaengPhet'],
            '40' => ['th' => 'ขอนแก่น', 'en' => 'KhonKaen'],
            '22' => ['th' => 'จันทบุรี', 'en' => 'Chanthaburi'],
            '24' => ['th' => 'ฉะเชิงเทรา', 'en' => 'Chachoengsao'],
            '20' => ['th' => 'ชลบุรี', 'en' => 'ChonBuri'],
            '18' => ['th' => 'ชัยนาท', 'en' => 'ChaiNat'],
            '36' => ['th' => 'ชัยภูมิ', 'en' => 'Chaiyaphum'],
            '86' => ['th' => 'ชุมพร', 'en' => 'Chumphon'],
            '57' => ['th' => 'เชียงราย', 'en' => 'ChiangRai'],
            '50' => ['th' => 'เชียงใหม่', 'en' => 'ChiangMai'],
            '92' => ['th' => 'ตรัง', 'en' => 'Trang'],
            '23' => ['th' => 'ตราด', 'en' => 'Trat'],
            '63' => ['th' => 'ตาก', 'en' => 'Tak'],
            '26' => ['th' => 'นครนายก', 'en' => 'NakhonNayok'],
            '73' => ['th' => 'นครปฐม', 'en' => 'NakhonPathom'],
            '48' => ['th' => 'นครพนม', 'en' => 'NakhonPhanom'],
            '30' => ['th' => 'นครราชสีมา', 'en' => 'NakhonRatchasima'],
            '80' => ['th' => 'นครศรีธรรมราช', 'en' => 'NakhonSiThammarat'],
            '60' => ['th' => 'นครสวรรค์', 'en' => 'NakhonSawan'],
            '12' => ['th' => 'นนทบุรี', 'en' => 'Nonthaburi'],
            '96' => ['th' => 'นราธิวาส', 'en' => 'Narathiwat'],
            '55' => ['th' => 'น่าน', 'en' => 'Nan'],
            '97' => ['th' => 'บึงกาฬ', 'en' => 'buogkan'],
            '31' => ['th' => 'บุรีรัมย์', 'en' => 'BuriRam'],
            '13' => ['th' => 'ปทุมธานี', 'en' => 'PathumThani'],
            '77' => ['th' => 'ประจวบคีรีขันธ์', 'en' => 'PrachuapKhiriKhan'],
            '25' => ['th' => 'ปราจีนบุรี', 'en' => 'PrachinBuri'],
            '94' => ['th' => 'ปัตตานี', 'en' => 'Pattani'],
            '14' => ['th' => 'พระนครศรีอยุธยา', 'en' => 'PhraNakhonSiAyutthaya'],
            '56' => ['th' => 'พะเยา', 'en' => 'Phayao'],
            '82' => ['th' => 'พังงา', 'en' => 'Phangnga'],
            '93' => ['th' => 'พัทลุง', 'en' => 'Phatthalung'],
            '66' => ['th' => 'พิจิตร', 'en' => 'Phichit'],
            '65' => ['th' => 'พิษณุโลก', 'en' => 'Phitsanulok'],
            '76' => ['th' => 'เพชรบุรี', 'en' => 'Phetchaburi'],
            '67' => ['th' => 'เพชรบูรณ์', 'en' => 'Phetchabun'],
            '54' => ['th' => 'แพร่', 'en' => 'Phrae'],
            '83' => ['th' => 'ภูเก็ต', 'en' => 'Phuket'],
            '44' => ['th' => 'มหาสารคาม', 'en' => 'MahaSarakham'],
            '49' => ['th' => 'มุกดาหาร', 'en' => 'Mukdahan'],
            '58' => ['th' => 'แม่ฮ่องสอน', 'en' => 'MaeHongSon'],
            '35' => ['th' => 'ยโสธร', 'en' => 'Yasothon'],
            '95' => ['th' => 'ยะลา', 'en' => 'Yala'],
            '45' => ['th' => 'ร้อยเอ็ด', 'en' => 'RoiEt'],
            '85' => ['th' => 'ระนอง', 'en' => 'Ranong'],
            '21' => ['th' => 'ระยอง', 'en' => 'Rayong'],
            '70' => ['th' => 'ราชบุรี', 'en' => 'Ratchaburi'],
            '16' => ['th' => 'ลพบุรี', 'en' => 'Loburi'],
            '52' => ['th' => 'ลำปาง', 'en' => 'Lampang'],
            '51' => ['th' => 'ลำพูน', 'en' => 'Lamphun'],
            '42' => ['th' => 'เลย', 'en' => 'Loei'],
            '33' => ['th' => 'ศรีสะเกษ', 'en' => 'SiSaKet'],
            '47' => ['th' => 'สกลนคร', 'en' => 'SakonNakhon'],
            '90' => ['th' => 'สงขลา', 'en' => 'Songkhla'],
            '91' => ['th' => 'สตูล', 'en' => 'Satun'],
            '11' => ['th' => 'สมุทรปราการ', 'en' => 'SamutPrakan'],
            '75' => ['th' => 'สมุทรสงคราม', 'en' => 'SamutSongkhram'],
            '74' => ['th' => 'สมุทรสาคร', 'en' => 'SamutSakhon'],
            '27' => ['th' => 'สระแก้ว', 'en' => 'SaKaeo'],
            '19' => ['th' => 'สระบุรี', 'en' => 'Saraburi'],
            '17' => ['th' => 'สิงห์บุรี', 'en' => 'SingBuri'],
            '64' => ['th' => 'สุโขทัย', 'en' => 'Sukhothai'],
            '72' => ['th' => 'สุพรรณบุรี', 'en' => 'SuphanBuri'],
            '84' => ['th' => 'สุราษฎร์ธานี', 'en' => 'SuratThani'],
            '32' => ['th' => 'สุรินทร์', 'en' => 'Surin'],
            '43' => ['th' => 'หนองคาย', 'en' => 'NongKhai'],
            '39' => ['th' => 'หนองบัวลำภู', 'en' => 'NongBuaLamPhu'],
            '15' => ['th' => 'อ่างทอง', 'en' => 'AngThong'],
            '37' => ['th' => 'อำนาจเจริญ', 'en' => 'AmnatCharoen'],
            '41' => ['th' => 'อุดรธานี', 'en' => 'UdonThani'],
            '53' => ['th' => 'อุตรดิตถ์', 'en' => 'Uttaradit'],
            '61' => ['th' => 'อุทัยธานี', 'en' => 'UthaiThani'],
            '34' => ['th' => 'อุบลราชธานี', 'en' => 'UbonRatchathani']
        ];
    }

    /**
     * List of provinces in Laos, sorted by Lao name.
     *
     * @return array An array containing province data for Laos
     */
    private static function LA()
    {
        return [
            '12' => ['th' => 'คำม่วน', 'la' => 'ຄໍາມ່ວນ', 'en' => 'Khammouane'],
            '16' => ['th' => 'จำปาศักดิ์', 'la' => 'ຈຳປາສັກ', 'en' => 'Champasak'],
            '09' => ['th' => 'เชียงขวาง', 'la' => 'ຊຽງຂວາງ', 'en' => 'Xiangkhouang'],
            '08' => ['th' => 'ไชยบุรี', 'la' => 'ໄຊຍະບູລີ', 'en' => 'Sainyabuli'],
            '18' => ['th' => 'ไชยสมบูรณ์', 'la' => 'ໄຊສົມບູນ', 'en' => 'Xaisomboun'],
            '15' => ['th' => 'เซกอง', 'la' => 'ເຊກອງ', 'en' => 'Sekong'],
            '11' => ['th' => 'บอลิคำไซ', 'la' => 'ບໍລິຄໍາໄຊ', 'en' => 'Bolikhamsai'],
            '05' => ['th' => 'บ่อแก้ว', 'la' => 'ບໍ່ແກ້ວ', 'en' => 'Bokeo'],
            '02' => ['th' => 'พงสาลี', 'la' => 'ຜົ້ງສາລີ', 'en' => 'Phongsaly'],
            '10' => ['th' => 'เวียงจันทน์', 'la' => 'ວຽງຈັນ', 'en' => 'Vientiane'],
            '14' => ['th' => 'สาละวัน', 'la' => 'ສາລະວັນ', 'en' => 'Salavan'],
            '13' => ['th' => 'สุวรรณเขต', 'la' => 'ສະຫວັນນະເຂດ', 'en' => 'Savannakhet'],
            '03' => ['th' => 'หลวงน้ำทา', 'la' => 'ຫລວງນໍ້າທາ', 'en' => 'Luang Namtha'],
            '06' => ['th' => 'หลวงพระบาง', 'la' => 'ຫລວງພະບາງ', 'en' => 'Luang Prabang'],
            '07' => ['th' => 'หัวพัน', 'la' => 'ຫົວພັນ', 'en' => 'Houaphanh'],
            '17' => ['th' => 'อัตตะปือ', 'la' => 'ອັດຕະປື', 'en' => 'Attapeu'],
            '04' => ['th' => 'อุดมไซ', 'la' => 'ອຸດົມໄຊ', 'en' => 'Oudomxay'],
            '01' => ['th' => 'นครหลวงเวียงจันทน์', 'la' => 'ນະຄອນຫຼວງວຽງຈັນ', 'en' => 'Oudomxay']
        ];
    }
}
