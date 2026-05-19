<?php

namespace Kotchasan;

/**
 * Kotchasan Country Class
 *
 * This class provides a list of countries with their names in Thai, English, and local languages.
 *
 * @package Kotchasan
 */
class Country
{
    /**
     * Initialize country data.
     *
     * @return array
     */
    private static function init()
    {
        return [
            'GR' => ['th' => 'กรีซ', 'en' => 'Greece', 'local' => 'Ελλάδα'],
            'GL' => ['th' => 'กรีนแลนด์', 'en' => 'Greenland', 'local' => 'Kalaallit Nunaat'],
            'GU' => ['th' => 'กวม', 'en' => 'Guam', 'local' => 'Guam'],
            'GP' => ['th' => 'กวาเดอลูป', 'en' => 'Guadeloupe', 'local' => 'Guadeloupe'],
            'KH' => ['th' => 'กัมพูชา', 'en' => 'Cambodia', 'local' => 'កម្ពុជា'],
            'GT' => ['th' => 'กัวเตมาลา', 'en' => 'Guatemala', 'local' => 'Guatemala'],
            'QA' => ['th' => 'กาตาร์', 'en' => 'Qatar', 'local' => '‫قطر‬‎'],
            'GH' => ['th' => 'กานา', 'en' => 'Ghana', 'local' => 'Gaana'],
            'GA' => ['th' => 'กาบอง', 'en' => 'Gabon', 'local' => 'Gabon'],
            'GY' => ['th' => 'กายอานา', 'en' => 'Guyana', 'local' => 'Guyana'],
            'GN' => ['th' => 'กินี', 'en' => 'Guinea', 'local' => 'Guinée'],
            'GW' => ['th' => 'กินี-บิสเซา', 'en' => 'Guinea-Bissau', 'local' => 'Guiné-Bissau'],
            'GD' => ['th' => 'เกรเนดา', 'en' => 'Grenada', 'local' => 'Grenada'],
            'KR' => ['th' => 'เกาหลีใต้', 'en' => 'South Korea', 'local' => '대한민국'],
            'KP' => ['th' => 'เกาหลีเหนือ', 'en' => 'North Korea', 'local' => '조선민주주의인민공화국'],
            'CX' => ['th' => 'เกาะคริสต์มาส', 'en' => 'Christmas Island', 'local' => 'Christmas Island'],
            'CP' => ['th' => 'เกาะคลิปเปอร์ตัน', 'en' => 'Clipperton Island', 'local' => 'Clipperton Island'],
            'GS' => ['th' => 'เกาะเซาท์จอร์เจียและหมู่เกาะเซาท์แซนด์วิช', 'en' => 'South Georgia &amp; South Sandwich Islands', 'local' => 'South Georgia &amp; South Sandwich Islands'],
            'NF' => ['th' => 'เกาะนอร์ฟอล์ก', 'en' => 'Norfolk Island', 'local' => 'Norfolk Island'],
            'BV' => ['th' => 'เกาะบูเวต', 'en' => 'Bouvet Island', 'local' => 'Bouvet Island'],
            'IM' => ['th' => 'เกาะแมน', 'en' => 'Isle of Man', 'local' => 'Isle of Man'],
            'AC' => ['th' => 'เกาะแอสเซนชัน', 'en' => 'Ascension Island', 'local' => 'Ascension Island'],
            'HM' => ['th' => 'เกาะเฮิร์ดและหมู่เกาะแมกดอนัลด์', 'en' => 'Heard &amp; McDonald Islands', 'local' => 'Heard &amp; McDonald Islands'],
            'GG' => ['th' => 'เกิร์นซีย์', 'en' => 'Guernsey', 'local' => 'Guernsey'],
            'GM' => ['th' => 'แกมเบีย', 'en' => 'Gambia', 'local' => 'Gambia'],
            'CD' => ['th' => 'คองโก-กินชาซา', 'en' => 'Congo (DRC)', 'local' => 'Jamhuri ya Kidemokrasia ya Kongo'],
            'CG' => ['th' => 'คองโก-บราซซาวิล', 'en' => 'Congo (Republic)', 'local' => 'Congo-Brazzaville'],
            'KM' => ['th' => 'คอโมโรส', 'en' => 'Comoros', 'local' => '‫جزر القمر‬‎'],
            'CR' => ['th' => 'คอสตาริกา', 'en' => 'Costa Rica', 'local' => 'Costa Rica'],
            'KZ' => ['th' => 'คาซัคสถาน', 'en' => 'Kazakhstan', 'local' => 'Казахстан'],
            'KI' => ['th' => 'คิริบาส', 'en' => 'Kiribati', 'local' => 'Kiribati'],
            'CU' => ['th' => 'คิวบา', 'en' => 'Cuba', 'local' => 'Cuba'],
            'KG' => ['th' => 'คีร์กีซสถาน', 'en' => 'Kyrgyzstan', 'local' => 'Кыргызстан'],
            'CW' => ['th' => 'คูราเซา', 'en' => 'Curaçao', 'local' => 'Curaçao'],
            'KW' => ['th' => 'คูเวต', 'en' => 'Kuwait', 'local' => '‫الكويت‬‎'],
            'KE' => ['th' => 'เคนยา', 'en' => 'Kenya', 'local' => 'Kenya'],
            'CV' => ['th' => 'เคปเวิร์ด', 'en' => 'Cape Verde', 'local' => 'Kabu Verdi'],
            'CA' => ['th' => 'แคนาดา', 'en' => 'Canada', 'local' => 'Canada'],
            'CM' => ['th' => 'แคเมอรูน', 'en' => 'Cameroon', 'local' => 'Cameroun'],
            'XK' => ['th' => 'โคโซโว', 'en' => 'Kosovo', 'local' => 'Kosovë'],
            'HR' => ['th' => 'โครเอเชีย', 'en' => 'Croatia', 'local' => 'Hrvatska'],
            'CO' => ['th' => 'โคลอมเบีย', 'en' => 'Colombia', 'local' => 'Colombia'],
            'GE' => ['th' => 'จอร์เจีย', 'en' => 'Georgia', 'local' => 'საქართველო'],
            'JO' => ['th' => 'จอร์แดน', 'en' => 'Jordan', 'local' => '‫الأردن‬‎'],
            'JM' => ['th' => 'จาเมกา', 'en' => 'Jamaica', 'local' => 'Jamaica'],
            'DJ' => ['th' => 'จิบูตี', 'en' => 'Djibouti', 'local' => 'Djibouti'],
            'CN' => ['th' => 'จีน', 'en' => 'China', 'local' => '中国'],
            'JE' => ['th' => 'เจอร์ซีย์', 'en' => 'Jersey', 'local' => 'Jersey'],
            'TD' => ['th' => 'ชาด', 'en' => 'Chad', 'local' => 'Tchad'],
            'CL' => ['th' => 'ชิลี', 'en' => 'Chile', 'local' => 'Chile'],
            'SM' => ['th' => 'ซานมารีโน', 'en' => 'San Marino', 'local' => 'San Marino'],
            'WS' => ['th' => 'ซามัว', 'en' => 'Samoa', 'local' => 'Samoa'],
            'SA' => ['th' => 'ซาอุดีอาระเบีย', 'en' => 'Saudi Arabia', 'local' => '‫المملكة العربية السعودية‬‎'],
            'EH' => ['th' => 'ซาฮาราตะวันตก', 'en' => 'Western Sahara', 'local' => '‫الصحراء الغربية‬‎'],
            'ZW' => ['th' => 'ซิมบับเว', 'en' => 'Zimbabwe', 'local' => 'Zimbabwe'],
            'SY' => ['th' => 'ซีเรีย', 'en' => 'Syria', 'local' => '‫سوريا‬‎'],
            'EA' => ['th' => 'ซีโอตาและเมลิลลา', 'en' => 'Ceuta &amp; Melilla', 'local' => 'Ceuta y Melilla'],
            'SD' => ['th' => 'ซูดาน', 'en' => 'Sudan', 'local' => '‫السودان‬‎'],
            'SS' => ['th' => 'ซูดานใต้', 'en' => 'South Sudan', 'local' => '‫جنوب السودان‬‎'],
            'SR' => ['th' => 'ซูรินาเม', 'en' => 'Suriname', 'local' => 'Suriname'],
            'SC' => ['th' => 'เซเชลส์', 'en' => 'Seychelles', 'local' => 'Seychelles'],
            'KN' => ['th' => 'เซนต์คิตส์และเนวิส', 'en' => 'St. Kitts &amp; Nevis', 'local' => 'St. Kitts &amp; Nevis'],
            'BL' => ['th' => 'เซนต์บาร์เธเลมี', 'en' => 'St. Barthélemy', 'local' => 'Saint-Barthélemy'],
            'MF' => ['th' => 'เซนต์มาติน', 'en' => 'St. Martin', 'local' => 'Saint-Martin'],
            'SX' => ['th' => 'เซนต์มาร์ติน', 'en' => 'Sint Maarten', 'local' => 'Sint Maarten'],
            'LC' => ['th' => 'เซนต์ลูเซีย', 'en' => 'St. Lucia', 'local' => 'St. Lucia'],
            'VC' => ['th' => 'เซนต์วินเซนต์และเกรนาดีนส์', 'en' => 'St. Vincent &amp; Grenadines', 'local' => 'St. Vincent &amp; Grenadines'],
            'SH' => ['th' => 'เซนต์เฮเลนา', 'en' => 'St. Helena', 'local' => 'St. Helena'],
            'SN' => ['th' => 'เซเนกัล', 'en' => 'Senegal', 'local' => 'Senegal'],
            'RS' => ['th' => 'เซอร์เบีย', 'en' => 'Serbia', 'local' => 'Србија'],
            'ST' => ['th' => 'เซาตูเมและปรินซิปี', 'en' => 'São Tomé &amp; Príncipe', 'local' => 'São Tomé e Príncipe'],
            'SL' => ['th' => 'เซียร์ราลีโอน', 'en' => 'Sierra Leone', 'local' => 'Sierra Leone'],
            'PM' => ['th' => 'แซงปีแยร์และมีเกอลง', 'en' => 'St. Pierre &amp; Miquelon', 'local' => 'Saint-Pierre-et-Miquelon'],
            'ZM' => ['th' => 'แซมเบีย', 'en' => 'Zambia', 'local' => 'Zambia'],
            'SO' => ['th' => 'โซมาเลีย', 'en' => 'Somalia', 'local' => 'Soomaaliya'],
            'CY' => ['th' => 'ไซปรัส', 'en' => 'Cyprus', 'local' => 'Κύπρος'],
            'JP' => ['th' => 'ญี่ปุ่น', 'en' => 'Japan', 'local' => '日本'],
            'DG' => ['th' => 'ดิเอโกการ์เซีย', 'en' => 'Diego Garcia', 'local' => 'Diego Garcia'],
            'DK' => ['th' => 'เดนมาร์ก', 'en' => 'Denmark', 'local' => 'Danmark'],
            'DM' => ['th' => 'โดมินิกา', 'en' => 'Dominica', 'local' => 'Dominica'],
            'TT' => ['th' => 'ตรินิแดดและโตเบโก', 'en' => 'Trinidad &amp; Tobago', 'local' => 'Trinidad &amp; Tobago'],
            'TO' => ['th' => 'ตองกา', 'en' => 'Tonga', 'local' => 'Tonga'],
            'TL' => ['th' => 'ติมอร์-เลสเต', 'en' => 'Timor-Leste', 'local' => 'Timor-Leste'],
            'TR' => ['th' => 'ตุรกี', 'en' => 'Turkey', 'local' => 'Türkiye'],
            'TN' => ['th' => 'ตูนิเซีย', 'en' => 'Tunisia', 'local' => 'Tunisia'],
            'TV' => ['th' => 'ตูวาลู', 'en' => 'Tuvalu', 'local' => 'Tuvalu'],
            'TM' => ['th' => 'เติร์กเมนิสถาน', 'en' => 'Turkmenistan', 'local' => 'Turkmenistan'],
            'TK' => ['th' => 'โตเกเลา', 'en' => 'Tokelau', 'local' => 'Tokelau'],
            'TG' => ['th' => 'โตโก', 'en' => 'Togo', 'local' => 'Togo'],
            'TW' => ['th' => 'ไต้หวัน', 'en' => 'Taiwan', 'local' => '台灣'],
            'TA' => ['th' => 'ทริสตัน เดอ คูนา', 'en' => 'Tristan da Cunha', 'local' => 'Tristan da Cunha'],
            'TJ' => ['th' => 'ทาจิกิสถาน', 'en' => 'Tajikistan', 'local' => 'Tajikistan'],
            'TZ' => ['th' => 'แทนซาเนีย', 'en' => 'Tanzania', 'local' => 'Tanzania'],
            'TH' => ['th' => 'ไทย', 'en' => 'Thailand', 'local' => 'ไทย'],
            'VA' => ['th' => 'นครวาติกัน', 'en' => 'Vatican City', 'local' => 'Città del Vaticano'],
            'NO' => ['th' => 'นอร์เวย์', 'en' => 'Norway', 'local' => 'Norge'],
            'NA' => ['th' => 'นามิเบีย', 'en' => 'Namibia', 'local' => 'Namibië'],
            'NR' => ['th' => 'นาอูรู', 'en' => 'Nauru', 'local' => 'Nauru'],
            'NI' => ['th' => 'นิการากัว', 'en' => 'Nicaragua', 'local' => 'Nicaragua'],
            'NC' => ['th' => 'นิวแคลิโดเนีย', 'en' => 'New Caledonia', 'local' => 'Nouvelle-Calédonie'],
            'NZ' => ['th' => 'นิวซีแลนด์', 'en' => 'New Zealand', 'local' => 'New Zealand'],
            'NU' => ['th' => 'นีอูเอ', 'en' => 'Niue', 'local' => 'Niue'],
            'NL' => ['th' => 'เนเธอร์แลนด์', 'en' => 'Netherlands', 'local' => 'Nederland'],
            'BQ' => ['th' => 'เนเธอร์แลนด์แคริบเบียน', 'en' => 'Caribbean Netherlands', 'local' => 'Caribbean Netherlands'],
            'NP' => ['th' => 'เนปาล', 'en' => 'Nepal', 'local' => 'नेपाल'],
            'NG' => ['th' => 'ไนจีเรีย', 'en' => 'Nigeria', 'local' => 'Nigeria'],
            'NE' => ['th' => 'ไนเจอร์', 'en' => 'Niger', 'local' => 'Nijar'],
            'BR' => ['th' => 'บราซิล', 'en' => 'Brazil', 'local' => 'Brasil'],
            'IO' => ['th' => 'บริติชอินเดียนโอเชียนเทร์ริทอรี', 'en' => 'British Indian Ocean Territory', 'local' => 'British Indian Ocean Territory'],
            'BN' => ['th' => 'บรูไน', 'en' => 'Brunei', 'local' => 'Brunei'],
            'BW' => ['th' => 'บอตสวานา', 'en' => 'Botswana', 'local' => 'Botswana'],
            'BA' => ['th' => 'บอสเนียและเฮอร์เซโกวีนา', 'en' => 'Bosnia &amp; Herzegovina', 'local' => 'Босна и Херцеговина'],
            'BD' => ['th' => 'บังกลาเทศ', 'en' => 'Bangladesh', 'local' => 'বাংলাদেশ'],
            'BG' => ['th' => 'บัลแกเรีย', 'en' => 'Bulgaria', 'local' => 'България'],
            'BB' => ['th' => 'บาร์เบโดส', 'en' => 'Barbados', 'local' => 'Barbados'],
            'BH' => ['th' => 'บาห์เรน', 'en' => 'Bahrain', 'local' => '‫البحرين‬‎'],
            'BS' => ['th' => 'บาฮามาส', 'en' => 'Bahamas', 'local' => 'Bahamas'],
            'BI' => ['th' => 'บุรุนดี', 'en' => 'Burundi', 'local' => 'Uburundi'],
            'BF' => ['th' => 'บูร์กินาฟาโซ', 'en' => 'Burkina Faso', 'local' => 'Burkina Faso'],
            'BJ' => ['th' => 'เบนิน', 'en' => 'Benin', 'local' => 'Bénin'],
            'BE' => ['th' => 'เบลเยียม', 'en' => 'Belgium', 'local' => 'Belgium'],
            'BY' => ['th' => 'เบลารุส', 'en' => 'Belarus', 'local' => 'Беларусь'],
            'BZ' => ['th' => 'เบลีซ', 'en' => 'Belize', 'local' => 'Belize'],
            'BM' => ['th' => 'เบอร์มิวดา', 'en' => 'Bermuda', 'local' => 'Bermuda'],
            'BO' => ['th' => 'โบลิเวีย', 'en' => 'Bolivia', 'local' => 'Bolivia'],
            'PK' => ['th' => 'ปากีสถาน', 'en' => 'Pakistan', 'local' => '‫پاکستان‬‎'],
            'PA' => ['th' => 'ปานามา', 'en' => 'Panama', 'local' => 'Panamá'],
            'PG' => ['th' => 'ปาปัวนิวกินี', 'en' => 'Papua New Guinea', 'local' => 'Papua New Guinea'],
            'PY' => ['th' => 'ปารากวัย', 'en' => 'Paraguay', 'local' => 'Paraguay'],
            'PS' => ['th' => 'ปาเลสไตน์', 'en' => 'Palestine', 'local' => '‫فلسطين‬‎'],
            'PW' => ['th' => 'ปาเลา', 'en' => 'Palau', 'local' => 'Palau'],
            'PE' => ['th' => 'เปรู', 'en' => 'Peru', 'local' => 'Perú'],
            'PR' => ['th' => 'เปอร์โตริโก', 'en' => 'Puerto Rico', 'local' => 'Puerto Rico'],
            'PT' => ['th' => 'โปรตุเกส', 'en' => 'Portugal', 'local' => 'Portugal'],
            'PL' => ['th' => 'โปแลนด์', 'en' => 'Poland', 'local' => 'Polska'],
            'FR' => ['th' => 'ฝรั่งเศส', 'en' => 'France', 'local' => 'France'],
            'FJ' => ['th' => 'ฟิจิ', 'en' => 'Fiji', 'local' => 'Fiji'],
            'FI' => ['th' => 'ฟินแลนด์', 'en' => 'Finland', 'local' => 'Suomi'],
            'PH' => ['th' => 'ฟิลิปปินส์', 'en' => 'Philippines', 'local' => 'Philippines'],
            'GF' => ['th' => 'เฟรนช์เกียนา', 'en' => 'French Guiana', 'local' => 'Guyane française'],
            'TF' => ['th' => 'เฟรนช์เซาเทิร์นเทร์ริทอรีส์', 'en' => 'French Southern Territories', 'local' => 'Terres australes françaises'],
            'PF' => ['th' => 'เฟรนช์โปลินีเซีย', 'en' => 'French Polynesia', 'local' => 'Polynésie française'],
            'BT' => ['th' => 'ภูฏาน', 'en' => 'Bhutan', 'local' => 'འབྲུག'],
            'MN' => ['th' => 'มองโกเลีย', 'en' => 'Mongolia', 'local' => 'Монгол'],
            'MS' => ['th' => 'มอนต์เซอร์รัต', 'en' => 'Montserrat', 'local' => 'Montserrat'],
            'ME' => ['th' => 'มอนเตเนโกร', 'en' => 'Montenegro', 'local' => 'Crna Gora'],
            'MU' => ['th' => 'มอริเชียส', 'en' => 'Mauritius', 'local' => 'Moris'],
            'MR' => ['th' => 'มอริเตเนีย', 'en' => 'Mauritania', 'local' => '‫موريتانيا‬‎'],
            'MD' => ['th' => 'มอลโดวา', 'en' => 'Moldova', 'local' => 'Republica Moldova'],
            'MT' => ['th' => 'มอลตา', 'en' => 'Malta', 'local' => 'Malta'],
            'MV' => ['th' => 'มัลดีฟส์', 'en' => 'Maldives', 'local' => 'Maldives'],
            'MO' => ['th' => 'มาเก๊า', 'en' => 'Macau', 'local' => '澳門'],
            'MK' => ['th' => 'มาซิโดเนีย', 'en' => 'Macedonia (FYROM)', 'local' => 'Македонија'],
            'MG' => ['th' => 'มาดากัสการ์', 'en' => 'Madagascar', 'local' => 'Madagasikara'],
            'YT' => ['th' => 'มายอต', 'en' => 'Mayotte', 'local' => 'Mayotte'],
            'MQ' => ['th' => 'มาร์ตินีก', 'en' => 'Martinique', 'local' => 'Martinique'],
            'MW' => ['th' => 'มาลาวี', 'en' => 'Malawi', 'local' => 'Malawi'],
            'ML' => ['th' => 'มาลี', 'en' => 'Mali', 'local' => 'Mali'],
            'MY' => ['th' => 'มาเลเซีย', 'en' => 'Malaysia', 'local' => 'Malaysia'],
            'MX' => ['th' => 'เม็กซิโก', 'en' => 'Mexico', 'local' => 'México'],
            'MM' => ['th' => 'เมียนม่าร์ (พม่า)', 'en' => 'Myanmar (Burma)', 'local' => 'မြန်မာ'],
            'MZ' => ['th' => 'โมซัมบิก', 'en' => 'Mozambique', 'local' => 'Moçambique'],
            'MC' => ['th' => 'โมนาโก', 'en' => 'Monaco', 'local' => 'Monaco'],
            'MA' => ['th' => 'โมร็อกโก', 'en' => 'Morocco', 'local' => 'Morocco'],
            'FM' => ['th' => 'ไมโครนีเซีย', 'en' => 'Micronesia', 'local' => 'Micronesia'],
            'GI' => ['th' => 'ยิบรอลตาร์', 'en' => 'Gibraltar', 'local' => 'Gibraltar'],
            'UG' => ['th' => 'ยูกันดา', 'en' => 'Uganda', 'local' => 'Uganda'],
            'UA' => ['th' => 'ยูเครน', 'en' => 'Ukraine', 'local' => 'Україна'],
            'YE' => ['th' => 'เยเมน', 'en' => 'Yemen', 'local' => '‫اليمن‬‎'],
            'DE' => ['th' => 'เยอรมนี', 'en' => 'Germany', 'local' => 'Deutschland'],
            'RW' => ['th' => 'รวันดา', 'en' => 'Rwanda', 'local' => 'Rwanda'],
            'RU' => ['th' => 'รัสเซีย', 'en' => 'Russia', 'local' => 'Россия'],
            'RE' => ['th' => 'เรอูนียง', 'en' => 'Reunion', 'local' => 'La Réunion'],
            'RO' => ['th' => 'โรมาเนีย', 'en' => 'Romania', 'local' => 'România'],
            'LU' => ['th' => 'ลักเซมเบิร์ก', 'en' => 'Luxembourg', 'local' => 'Luxembourg'],
            'LV' => ['th' => 'ลัตเวีย', 'en' => 'Latvia', 'local' => 'Latvija'],
            'LA' => ['th' => 'ลาว', 'en' => 'Laos', 'local' => 'ລາວ'],
            'LI' => ['th' => 'ลิกเตนสไตน์', 'en' => 'Liechtenstein', 'local' => 'Liechtenstein'],
            'LT' => ['th' => 'ลิทัวเนีย', 'en' => 'Lithuania', 'local' => 'Lietuva'],
            'LY' => ['th' => 'ลิเบีย', 'en' => 'Libya', 'local' => '‫ليبيا‬‎'],
            'LS' => ['th' => 'เลโซโท', 'en' => 'Lesotho', 'local' => 'Lesotho'],
            'LB' => ['th' => 'เลบานอน', 'en' => 'Lebanon', 'local' => '‫لبنان‬‎'],
            'LR' => ['th' => 'ไลบีเรีย', 'en' => 'Liberia', 'local' => 'Liberia'],
            'VU' => ['th' => 'วานูอาตู', 'en' => 'Vanuatu', 'local' => 'Vanuatu'],
            'WF' => ['th' => 'วาลลิสและฟุตูนา', 'en' => 'Wallis &amp; Futuna', 'local' => 'Wallis &amp; Futuna'],
            'VE' => ['th' => 'เวเนซุเอลา', 'en' => 'Venezuela', 'local' => 'Venezuela'],
            'VN' => ['th' => 'เวียดนาม', 'en' => 'Vietnam', 'local' => 'Việt Nam'],
            'LK' => ['th' => 'ศรีลังกา', 'en' => 'Sri Lanka', 'local' => 'ශ්‍රී ලංකාව'],
            'ES' => ['th' => 'สเปน', 'en' => 'Spain', 'local' => 'España'],
            'SJ' => ['th' => 'สฟาลบาร์และยานไมเอน', 'en' => 'Svalbard &amp; Jan Mayen', 'local' => 'Svalbard og Jan Mayen'],
            'SK' => ['th' => 'สโลวะเกีย', 'en' => 'Slovakia', 'local' => 'Slovensko'],
            'SI' => ['th' => 'สโลวีเนีย', 'en' => 'Slovenia', 'local' => 'Slovenija'],
            'SZ' => ['th' => 'สวาซิแลนด์', 'en' => 'Swaziland', 'local' => 'Swaziland'],
            'CH' => ['th' => 'สวิตเซอร์แลนด์', 'en' => 'Switzerland', 'local' => 'Schweiz'],
            'SE' => ['th' => 'สวีเดน', 'en' => 'Sweden', 'local' => 'Sverige'],
            'US' => ['th' => 'สหรัฐอเมริกา', 'en' => 'United States', 'local' => 'United States'],
            'AE' => ['th' => 'สหรัฐอาหรับเอมิเรตส์', 'en' => 'United Arab Emirates', 'local' => '‫الإمارات العربية المتحدة‬‎'],
            'GB' => ['th' => 'สหราชอาณาจักร', 'en' => 'United Kingdom', 'local' => 'United Kingdom'],
            'CZ' => ['th' => 'สาธารณรัฐเช็ก', 'en' => 'Czech Republic', 'local' => 'Česká republika'],
            'DO' => ['th' => 'สาธารณรัฐโดมินิกัน', 'en' => 'Dominican Republic', 'local' => 'República Dominicana'],
            'CF' => ['th' => 'สาธารณรัฐแอฟริกากลาง', 'en' => 'Central African Republic', 'local' => 'République centrafricaine'],
            'SG' => ['th' => 'สิงคโปร์', 'en' => 'Singapore', 'local' => 'Singapore'],
            'IC' => ['th' => 'หมู่เกาะคานารี', 'en' => 'Canary Islands', 'local' => 'islas Canarias'],
            'CK' => ['th' => 'หมู่เกาะคุก', 'en' => 'Cook Islands', 'local' => 'Cook Islands'],
            'KY' => ['th' => 'หมู่เกาะเคย์แมน', 'en' => 'Cayman Islands', 'local' => 'Cayman Islands'],
            'CC' => ['th' => 'หมู่เกาะโคโคส (คีลิง) (Kepulauan Cocos)', 'en' => 'Cocos (Keeling) Islands (Kepulauan Cocos)', 'local' => 'Keeling)'],
            'SB' => ['th' => 'หมู่เกาะโซโลมอน', 'en' => 'Solomon Islands', 'local' => 'Solomon Islands'],
            'TC' => ['th' => 'หมู่เกาะเติกส์และหมู่เกาะเคคอส', 'en' => 'Turks &amp; Caicos Islands', 'local' => 'Turks &amp; Caicos Islands'],
            'MP' => ['th' => 'หมู่เกาะนอร์เทิร์นมาเรียนา', 'en' => 'Northern Mariana Islands', 'local' => 'Northern Mariana Islands'],
            'VG' => ['th' => 'หมู่เกาะบริติชเวอร์จิน', 'en' => 'British Virgin Islands', 'local' => 'British Virgin Islands'],
            'PN' => ['th' => 'หมู่เกาะพิตแคร์น', 'en' => 'Pitcairn Islands', 'local' => 'Pitcairn Islands'],
            'FK' => ['th' => 'หมู่เกาะฟอล์กแลนด์ (Falkland Islands', 'en' => 'Falkland Islands', 'local' => 'Islas Malvinas'],
            'FO' => ['th' => 'หมู่เกาะแฟโร', 'en' => 'Faroe Islands', 'local' => 'Føroyar'],
            'MH' => ['th' => 'หมู่เกาะมาร์แชลล์', 'en' => 'Marshall Islands', 'local' => 'Marshall Islands'],
            'VI' => ['th' => 'หมู่เกาะยูเอสเวอร์จิน', 'en' => 'U.S. Virgin Islands', 'local' => 'U.S. Virgin Islands'],
            'UM' => ['th' => 'หมู่เกาะรอบนอกของสหรัฐอเมริกา', 'en' => 'U.S. Outlying Islands', 'local' => 'U.S. Outlying Islands'],
            'AX' => ['th' => 'หมู่เกาะโอลันด์', 'en' => 'Aland Islands', 'local' => 'Åland Islands'],
            'AS' => ['th' => 'อเมริกันซามัว', 'en' => 'American Samoa', 'local' => 'American Samoa'],
            'AU' => ['th' => 'ออสเตรเลีย', 'en' => 'Australia', 'local' => 'Australia'],
            'AT' => ['th' => 'ออสเตรีย', 'en' => 'Austria', 'local' => 'Österreich'],
            'AD' => ['th' => 'อันดอร์รา', 'en' => 'Andorra', 'local' => 'Andorra'],
            'AF' => ['th' => 'อัฟกานิสถาน', 'en' => 'Afghanistan', 'local' => '‫افغانستان‬‎'],
            'AZ' => ['th' => 'อาเซอร์ไบจาน', 'en' => 'Azerbaijan', 'local' => 'Azərbaycan'],
            'AR' => ['th' => 'อาร์เจนตินา', 'en' => 'Argentina', 'local' => 'Argentina'],
            'AM' => ['th' => 'อาร์เมเนีย', 'en' => 'Armenia', 'local' => 'Հայաստան'],
            'AW' => ['th' => 'อารูบา', 'en' => 'Aruba', 'local' => 'Aruba'],
            'GQ' => ['th' => 'อิเควทอเรียลกินี', 'en' => 'Equatorial Guinea', 'local' => 'Guinea Ecuatorial'],
            'IT' => ['th' => 'อิตาลี', 'en' => 'Italy', 'local' => 'Italia'],
            'IN' => ['th' => 'อินเดีย', 'en' => 'India', 'local' => 'भारत'],
            'ID' => ['th' => 'อินโดนีเซีย', 'en' => 'Indonesia', 'local' => 'Indonesia'],
            'IQ' => ['th' => 'อิรัก', 'en' => 'Iraq', 'local' => '‫العراق‬‎'],
            'IL' => ['th' => 'อิสราเอล', 'en' => 'Israel', 'local' => '‫ישראל‬‎'],
            'IR' => ['th' => 'อิหร่าน', 'en' => 'Iran', 'local' => '‫ایران‬‎'],
            'EG' => ['th' => 'อียิปต์', 'en' => 'Egypt', 'local' => '‫مصر‬‎'],
            'UZ' => ['th' => 'อุซเบกิสถาน', 'en' => 'Uzbekistan', 'local' => 'Oʻzbekiston'],
            'UY' => ['th' => 'อุรุกวัย', 'en' => 'Uruguay', 'local' => 'Uruguay'],
            'EC' => ['th' => 'เอกวาดอร์', 'en' => 'Ecuador', 'local' => 'Ecuador'],
            'ET' => ['th' => 'เอธิโอเปีย', 'en' => 'Ethiopia', 'local' => 'Ethiopia'],
            'ER' => ['th' => 'เอริเทรีย', 'en' => 'Eritrea', 'local' => 'Eritrea'],
            'SV' => ['th' => 'เอลซัลวาดอร์', 'en' => 'El Salvador', 'local' => 'El Salvador'],
            'EE' => ['th' => 'เอสโตเนีย', 'en' => 'Estonia', 'local' => 'Eesti'],
            'AI' => ['th' => 'แองกวิลลา', 'en' => 'Anguilla', 'local' => 'Anguilla'],
            'AO' => ['th' => 'แองโกลา', 'en' => 'Angola', 'local' => 'Angola'],
            'AQ' => ['th' => 'แอนตาร์กติกา', 'en' => 'Antarctica', 'local' => 'Antarctica'],
            'AG' => ['th' => 'แอนติกาและบาร์บูดา', 'en' => 'Antigua &amp; Barbuda', 'local' => 'Antigua &amp; Barbuda'],
            'ZA' => ['th' => 'แอฟริกาใต้', 'en' => 'South Africa', 'local' => 'South Africa'],
            'DZ' => ['th' => 'แอลจีเรีย', 'en' => 'Algeria', 'local' => 'Algeria'],
            'AL' => ['th' => 'แอลเบเนีย', 'en' => 'Albania', 'local' => 'Shqipëri'],
            'OM' => ['th' => 'โอมาน', 'en' => 'Oman', 'local' => '‫عُمان‬‎'],
            'IS' => ['th' => 'ไอซ์แลนด์', 'en' => 'Iceland', 'local' => 'Ísland'],
            'IE' => ['th' => 'ไอร์แลนด์', 'en' => 'Ireland', 'local' => 'Ireland'],
            'CI' => ['th' => 'ไอวอรี่โคสต์', 'en' => 'Côte d’Ivoire', 'local' => 'Côte d’Ivoire'],
            'HK' => ['th' => 'ฮ่องกง', 'en' => 'Hong Kong', 'local' => '香港'],
            'HN' => ['th' => 'ฮอนดูรัส', 'en' => 'Honduras', 'local' => 'Honduras'],
            'HU' => ['th' => 'ฮังการี', 'en' => 'Hungary', 'local' => 'Magyarország'],
            'HT' => ['th' => 'เฮติ', 'en' => 'Haiti', 'local' => 'Haiti']
        ];
    }

    /**
     * Get country name based on ISO code and language.
     * Returns an empty string if not found.
     *
     * @param string $iso
     *
     * @return string
     */
    public static function get($iso)
    {
        $datas = self::init();
        $language = Language::name();
        $language = in_array($language, array_keys(reset($datas))) ? $language : 'en';
        return isset($datas[$iso]) ? $datas[$iso][$language] : '';
    }

    /**
     * Get a list of all country names in the specified language.
     * If the language is not available, use English.
     * Can be used directly in forms.
     *
     * @return array
     */
    public static function all()
    {
        $datas = self::init();
        $language = Language::name();
        $language = in_array($language, array_keys(reset($datas))) ? $language : 'en';
        $result = [];
        foreach ($datas as $iso => $values) {
            $result[$iso] = $values[$language].($values[$language] == $values['local'] ? '' : ' ('.$values['local'].')');
        }
        if ($language == 'en') {
            asort($result);
        }
        return $result;
    }
}
