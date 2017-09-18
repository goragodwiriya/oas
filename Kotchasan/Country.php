<?php
/**
 * @filesource Kotchasan/Country.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Kotchasan;

use \Kotchasan\Language;

/**
 * รายชื่อประเทศ เรียงลำดับตามชื่อไทย
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Country
{

  /**
   * รายชื่อประเทศ เรียงลำดับตามชื่อไทย
   *
   * @return array
   */
  private static function init()
  {
    return array(
      'GR' => array('th' => 'กรีซ', 'en' => 'Greece', 'local' => 'Ελλάδα'),
      'GL' => array('th' => 'กรีนแลนด์', 'en' => 'Greenland', 'local' => 'Kalaallit Nunaat'),
      'GU' => array('th' => 'กวม', 'en' => 'Guam', 'local' => 'Guam'),
      'GP' => array('th' => 'กวาเดอลูป', 'en' => 'Guadeloupe', 'local' => 'Guadeloupe'),
      'KH' => array('th' => 'กัมพูชา', 'en' => 'Cambodia', 'local' => 'កម្ពុជា'),
      'GT' => array('th' => 'กัวเตมาลา', 'en' => 'Guatemala', 'local' => 'Guatemala'),
      'QA' => array('th' => 'กาตาร์', 'en' => 'Qatar', 'local' => '‫قطر‬‎'),
      'GH' => array('th' => 'กานา', 'en' => 'Ghana', 'local' => 'Gaana'),
      'GA' => array('th' => 'กาบอง', 'en' => 'Gabon', 'local' => 'Gabon'),
      'GY' => array('th' => 'กายอานา', 'en' => 'Guyana', 'local' => 'Guyana'),
      'GN' => array('th' => 'กินี', 'en' => 'Guinea', 'local' => 'Guinée'),
      'GW' => array('th' => 'กินี-บิสเซา', 'en' => 'Guinea-Bissau', 'local' => 'Guiné-Bissau'),
      'GD' => array('th' => 'เกรเนดา', 'en' => 'Grenada', 'local' => 'Grenada'),
      'KR' => array('th' => 'เกาหลีใต้', 'en' => 'South Korea', 'local' => '대한민국'),
      'KP' => array('th' => 'เกาหลีเหนือ', 'en' => 'North Korea', 'local' => '조선민주주의인민공화국'),
      'CX' => array('th' => 'เกาะคริสต์มาส', 'en' => 'Christmas Island', 'local' => 'Christmas Island'),
      'CP' => array('th' => 'เกาะคลิปเปอร์ตัน', 'en' => 'Clipperton Island', 'local' => 'Clipperton Island'),
      'GS' => array('th' => 'เกาะเซาท์จอร์เจียและหมู่เกาะเซาท์แซนด์วิช', 'en' => 'South Georgia &amp; South Sandwich Islands', 'local' => 'South Georgia &amp; South Sandwich Islands'),
      'NF' => array('th' => 'เกาะนอร์ฟอล์ก', 'en' => 'Norfolk Island', 'local' => 'Norfolk Island'),
      'BV' => array('th' => 'เกาะบูเวต', 'en' => 'Bouvet Island', 'local' => 'Bouvet Island'),
      'IM' => array('th' => 'เกาะแมน', 'en' => 'Isle of Man', 'local' => 'Isle of Man'),
      'AC' => array('th' => 'เกาะแอสเซนชัน', 'en' => 'Ascension Island', 'local' => 'Ascension Island'),
      'HM' => array('th' => 'เกาะเฮิร์ดและหมู่เกาะแมกดอนัลด์', 'en' => 'Heard &amp; McDonald Islands', 'local' => 'Heard &amp; McDonald Islands'),
      'GG' => array('th' => 'เกิร์นซีย์', 'en' => 'Guernsey', 'local' => 'Guernsey'),
      'GM' => array('th' => 'แกมเบีย', 'en' => 'Gambia', 'local' => 'Gambia'),
      'CD' => array('th' => 'คองโก-กินชาซา', 'en' => 'Congo (DRC)', 'local' => 'Jamhuri ya Kidemokrasia ya Kongo'),
      'CG' => array('th' => 'คองโก-บราซซาวิล', 'en' => 'Congo (Republic)', 'local' => 'Congo-Brazzaville'),
      'KM' => array('th' => 'คอโมโรส', 'en' => 'Comoros', 'local' => '‫جزر القمر‬‎'),
      'CR' => array('th' => 'คอสตาริกา', 'en' => 'Costa Rica', 'local' => 'Costa Rica'),
      'KZ' => array('th' => 'คาซัคสถาน', 'en' => 'Kazakhstan', 'local' => 'Казахстан'),
      'KI' => array('th' => 'คิริบาส', 'en' => 'Kiribati', 'local' => 'Kiribati'),
      'CU' => array('th' => 'คิวบา', 'en' => 'Cuba', 'local' => 'Cuba'),
      'KG' => array('th' => 'คีร์กีซสถาน', 'en' => 'Kyrgyzstan', 'local' => 'Кыргызстан'),
      'CW' => array('th' => 'คูราเซา', 'en' => 'Curaçao', 'local' => 'Curaçao'),
      'KW' => array('th' => 'คูเวต', 'en' => 'Kuwait', 'local' => '‫الكويت‬‎'),
      'KE' => array('th' => 'เคนยา', 'en' => 'Kenya', 'local' => 'Kenya'),
      'CV' => array('th' => 'เคปเวิร์ด', 'en' => 'Cape Verde', 'local' => 'Kabu Verdi'),
      'CA' => array('th' => 'แคนาดา', 'en' => 'Canada', 'local' => 'Canada'),
      'CM' => array('th' => 'แคเมอรูน', 'en' => 'Cameroon', 'local' => 'Cameroun'),
      'XK' => array('th' => 'โคโซโว', 'en' => 'Kosovo', 'local' => 'Kosovë'),
      'HR' => array('th' => 'โครเอเชีย', 'en' => 'Croatia', 'local' => 'Hrvatska'),
      'CO' => array('th' => 'โคลอมเบีย', 'en' => 'Colombia', 'local' => 'Colombia'),
      'GE' => array('th' => 'จอร์เจีย', 'en' => 'Georgia', 'local' => 'საქართველო'),
      'JO' => array('th' => 'จอร์แดน', 'en' => 'Jordan', 'local' => '‫الأردن‬‎'),
      'JM' => array('th' => 'จาเมกา', 'en' => 'Jamaica', 'local' => 'Jamaica'),
      'DJ' => array('th' => 'จิบูตี', 'en' => 'Djibouti', 'local' => 'Djibouti'),
      'CN' => array('th' => 'จีน', 'en' => 'China', 'local' => '中国'),
      'JE' => array('th' => 'เจอร์ซีย์', 'en' => 'Jersey', 'local' => 'Jersey'),
      'TD' => array('th' => 'ชาด', 'en' => 'Chad', 'local' => 'Tchad'),
      'CL' => array('th' => 'ชิลี', 'en' => 'Chile', 'local' => 'Chile'),
      'SM' => array('th' => 'ซานมารีโน', 'en' => 'San Marino', 'local' => 'San Marino'),
      'WS' => array('th' => 'ซามัว', 'en' => 'Samoa', 'local' => 'Samoa'),
      'SA' => array('th' => 'ซาอุดีอาระเบีย', 'en' => 'Saudi Arabia', 'local' => '‫المملكة العربية السعودية‬‎'),
      'EH' => array('th' => 'ซาฮาราตะวันตก', 'en' => 'Western Sahara', 'local' => '‫الصحراء الغربية‬‎'),
      'ZW' => array('th' => 'ซิมบับเว', 'en' => 'Zimbabwe', 'local' => 'Zimbabwe'),
      'SY' => array('th' => 'ซีเรีย', 'en' => 'Syria', 'local' => '‫سوريا‬‎'),
      'EA' => array('th' => 'ซีโอตาและเมลิลลา', 'en' => 'Ceuta &amp; Melilla', 'local' => 'Ceuta y Melilla'),
      'SD' => array('th' => 'ซูดาน', 'en' => 'Sudan', 'local' => '‫السودان‬‎'),
      'SS' => array('th' => 'ซูดานใต้', 'en' => 'South Sudan', 'local' => '‫جنوب السودان‬‎'),
      'SR' => array('th' => 'ซูรินาเม', 'en' => 'Suriname', 'local' => 'Suriname'),
      'SC' => array('th' => 'เซเชลส์', 'en' => 'Seychelles', 'local' => 'Seychelles'),
      'KN' => array('th' => 'เซนต์คิตส์และเนวิส', 'en' => 'St. Kitts &amp; Nevis', 'local' => 'St. Kitts &amp; Nevis'),
      'BL' => array('th' => 'เซนต์บาร์เธเลมี', 'en' => 'St. Barthélemy', 'local' => 'Saint-Barthélemy'),
      'MF' => array('th' => 'เซนต์มาติน', 'en' => 'St. Martin', 'local' => 'Saint-Martin'),
      'SX' => array('th' => 'เซนต์มาร์ติน', 'en' => 'Sint Maarten', 'local' => 'Sint Maarten'),
      'LC' => array('th' => 'เซนต์ลูเซีย', 'en' => 'St. Lucia', 'local' => 'St. Lucia'),
      'VC' => array('th' => 'เซนต์วินเซนต์และเกรนาดีนส์', 'en' => 'St. Vincent &amp; Grenadines', 'local' => 'St. Vincent &amp; Grenadines'),
      'SH' => array('th' => 'เซนต์เฮเลนา', 'en' => 'St. Helena', 'local' => 'St. Helena'),
      'SN' => array('th' => 'เซเนกัล', 'en' => 'Senegal', 'local' => 'Senegal'),
      'RS' => array('th' => 'เซอร์เบีย', 'en' => 'Serbia', 'local' => 'Србија'),
      'ST' => array('th' => 'เซาตูเมและปรินซิปี', 'en' => 'São Tomé &amp; Príncipe', 'local' => 'São Tomé e Príncipe'),
      'SL' => array('th' => 'เซียร์ราลีโอน', 'en' => 'Sierra Leone', 'local' => 'Sierra Leone'),
      'PM' => array('th' => 'แซงปีแยร์และมีเกอลง', 'en' => 'St. Pierre &amp; Miquelon', 'local' => 'Saint-Pierre-et-Miquelon'),
      'ZM' => array('th' => 'แซมเบีย', 'en' => 'Zambia', 'local' => 'Zambia'),
      'SO' => array('th' => 'โซมาเลีย', 'en' => 'Somalia', 'local' => 'Soomaaliya'),
      'CY' => array('th' => 'ไซปรัส', 'en' => 'Cyprus', 'local' => 'Κύπρος'),
      'JP' => array('th' => 'ญี่ปุ่น', 'en' => 'Japan', 'local' => '日本'),
      'DG' => array('th' => 'ดิเอโกการ์เซีย', 'en' => 'Diego Garcia', 'local' => 'Diego Garcia'),
      'DK' => array('th' => 'เดนมาร์ก', 'en' => 'Denmark', 'local' => 'Danmark'),
      'DM' => array('th' => 'โดมินิกา', 'en' => 'Dominica', 'local' => 'Dominica'),
      'TT' => array('th' => 'ตรินิแดดและโตเบโก', 'en' => 'Trinidad &amp; Tobago', 'local' => 'Trinidad &amp; Tobago'),
      'TO' => array('th' => 'ตองกา', 'en' => 'Tonga', 'local' => 'Tonga'),
      'TL' => array('th' => 'ติมอร์-เลสเต', 'en' => 'Timor-Leste', 'local' => 'Timor-Leste'),
      'TR' => array('th' => 'ตุรกี', 'en' => 'Turkey', 'local' => 'Türkiye'),
      'TN' => array('th' => 'ตูนิเซีย', 'en' => 'Tunisia', 'local' => 'Tunisia'),
      'TV' => array('th' => 'ตูวาลู', 'en' => 'Tuvalu', 'local' => 'Tuvalu'),
      'TM' => array('th' => 'เติร์กเมนิสถาน', 'en' => 'Turkmenistan', 'local' => 'Turkmenistan'),
      'TK' => array('th' => 'โตเกเลา', 'en' => 'Tokelau', 'local' => 'Tokelau'),
      'TG' => array('th' => 'โตโก', 'en' => 'Togo', 'local' => 'Togo'),
      'TW' => array('th' => 'ไต้หวัน', 'en' => 'Taiwan', 'local' => '台灣'),
      'TA' => array('th' => 'ทริสตัน เดอ คูนา', 'en' => 'Tristan da Cunha', 'local' => 'Tristan da Cunha'),
      'TJ' => array('th' => 'ทาจิกิสถาน', 'en' => 'Tajikistan', 'local' => 'Tajikistan'),
      'TZ' => array('th' => 'แทนซาเนีย', 'en' => 'Tanzania', 'local' => 'Tanzania'),
      'TH' => array('th' => 'ไทย', 'en' => 'Thailand', 'local' => 'ไทย'),
      'VA' => array('th' => 'นครวาติกัน', 'en' => 'Vatican City', 'local' => 'Città del Vaticano'),
      'NO' => array('th' => 'นอร์เวย์', 'en' => 'Norway', 'local' => 'Norge'),
      'NA' => array('th' => 'นามิเบีย', 'en' => 'Namibia', 'local' => 'Namibië'),
      'NR' => array('th' => 'นาอูรู', 'en' => 'Nauru', 'local' => 'Nauru'),
      'NI' => array('th' => 'นิการากัว', 'en' => 'Nicaragua', 'local' => 'Nicaragua'),
      'NC' => array('th' => 'นิวแคลิโดเนีย', 'en' => 'New Caledonia', 'local' => 'Nouvelle-Calédonie'),
      'NZ' => array('th' => 'นิวซีแลนด์', 'en' => 'New Zealand', 'local' => 'New Zealand'),
      'NU' => array('th' => 'นีอูเอ', 'en' => 'Niue', 'local' => 'Niue'),
      'NL' => array('th' => 'เนเธอร์แลนด์', 'en' => 'Netherlands', 'local' => 'Nederland'),
      'BQ' => array('th' => 'เนเธอร์แลนด์แคริบเบียน', 'en' => 'Caribbean Netherlands', 'local' => 'Caribbean Netherlands'),
      'NP' => array('th' => 'เนปาล', 'en' => 'Nepal', 'local' => 'नेपाल'),
      'NG' => array('th' => 'ไนจีเรีย', 'en' => 'Nigeria', 'local' => 'Nigeria'),
      'NE' => array('th' => 'ไนเจอร์', 'en' => 'Niger', 'local' => 'Nijar'),
      'BR' => array('th' => 'บราซิล', 'en' => 'Brazil', 'local' => 'Brasil'),
      'IO' => array('th' => 'บริติชอินเดียนโอเชียนเทร์ริทอรี', 'en' => 'British Indian Ocean Territory', 'local' => 'British Indian Ocean Territory'),
      'BN' => array('th' => 'บรูไน', 'en' => 'Brunei', 'local' => 'Brunei'),
      'BW' => array('th' => 'บอตสวานา', 'en' => 'Botswana', 'local' => 'Botswana'),
      'BA' => array('th' => 'บอสเนียและเฮอร์เซโกวีนา', 'en' => 'Bosnia &amp; Herzegovina', 'local' => 'Босна и Херцеговина'),
      'BD' => array('th' => 'บังกลาเทศ', 'en' => 'Bangladesh', 'local' => 'বাংলাদেশ'),
      'BG' => array('th' => 'บัลแกเรีย', 'en' => 'Bulgaria', 'local' => 'България'),
      'BB' => array('th' => 'บาร์เบโดส', 'en' => 'Barbados', 'local' => 'Barbados'),
      'BH' => array('th' => 'บาห์เรน', 'en' => 'Bahrain', 'local' => '‫البحرين‬‎'),
      'BS' => array('th' => 'บาฮามาส', 'en' => 'Bahamas', 'local' => 'Bahamas'),
      'BI' => array('th' => 'บุรุนดี', 'en' => 'Burundi', 'local' => 'Uburundi'),
      'BF' => array('th' => 'บูร์กินาฟาโซ', 'en' => 'Burkina Faso', 'local' => 'Burkina Faso'),
      'BJ' => array('th' => 'เบนิน', 'en' => 'Benin', 'local' => 'Bénin'),
      'BE' => array('th' => 'เบลเยียม', 'en' => 'Belgium', 'local' => 'Belgium'),
      'BY' => array('th' => 'เบลารุส', 'en' => 'Belarus', 'local' => 'Беларусь'),
      'BZ' => array('th' => 'เบลีซ', 'en' => 'Belize', 'local' => 'Belize'),
      'BM' => array('th' => 'เบอร์มิวดา', 'en' => 'Bermuda', 'local' => 'Bermuda'),
      'BO' => array('th' => 'โบลิเวีย', 'en' => 'Bolivia', 'local' => 'Bolivia'),
      'PK' => array('th' => 'ปากีสถาน', 'en' => 'Pakistan', 'local' => '‫پاکستان‬‎'),
      'PA' => array('th' => 'ปานามา', 'en' => 'Panama', 'local' => 'Panamá'),
      'PG' => array('th' => 'ปาปัวนิวกินี', 'en' => 'Papua New Guinea', 'local' => 'Papua New Guinea'),
      'PY' => array('th' => 'ปารากวัย', 'en' => 'Paraguay', 'local' => 'Paraguay'),
      'PS' => array('th' => 'ปาเลสไตน์', 'en' => 'Palestine', 'local' => '‫فلسطين‬‎'),
      'PW' => array('th' => 'ปาเลา', 'en' => 'Palau', 'local' => 'Palau'),
      'PE' => array('th' => 'เปรู', 'en' => 'Peru', 'local' => 'Perú'),
      'PR' => array('th' => 'เปอร์โตริโก', 'en' => 'Puerto Rico', 'local' => 'Puerto Rico'),
      'PT' => array('th' => 'โปรตุเกส', 'en' => 'Portugal', 'local' => 'Portugal'),
      'PL' => array('th' => 'โปแลนด์', 'en' => 'Poland', 'local' => 'Polska'),
      'FR' => array('th' => 'ฝรั่งเศส', 'en' => 'France', 'local' => 'France'),
      'FJ' => array('th' => 'ฟิจิ', 'en' => 'Fiji', 'local' => 'Fiji'),
      'FI' => array('th' => 'ฟินแลนด์', 'en' => 'Finland', 'local' => 'Suomi'),
      'PH' => array('th' => 'ฟิลิปปินส์', 'en' => 'Philippines', 'local' => 'Philippines'),
      'GF' => array('th' => 'เฟรนช์เกียนา', 'en' => 'French Guiana', 'local' => 'Guyane française'),
      'TF' => array('th' => 'เฟรนช์เซาเทิร์นเทร์ริทอรีส์', 'en' => 'French Southern Territories', 'local' => 'Terres australes françaises'),
      'PF' => array('th' => 'เฟรนช์โปลินีเซีย', 'en' => 'French Polynesia', 'local' => 'Polynésie française'),
      'BT' => array('th' => 'ภูฏาน', 'en' => 'Bhutan', 'local' => 'འབྲུག'),
      'MN' => array('th' => 'มองโกเลีย', 'en' => 'Mongolia', 'local' => 'Монгол'),
      'MS' => array('th' => 'มอนต์เซอร์รัต', 'en' => 'Montserrat', 'local' => 'Montserrat'),
      'ME' => array('th' => 'มอนเตเนโกร', 'en' => 'Montenegro', 'local' => 'Crna Gora'),
      'MU' => array('th' => 'มอริเชียส', 'en' => 'Mauritius', 'local' => 'Moris'),
      'MR' => array('th' => 'มอริเตเนีย', 'en' => 'Mauritania', 'local' => '‫موريتانيا‬‎'),
      'MD' => array('th' => 'มอลโดวา', 'en' => 'Moldova', 'local' => 'Republica Moldova'),
      'MT' => array('th' => 'มอลตา', 'en' => 'Malta', 'local' => 'Malta'),
      'MV' => array('th' => 'มัลดีฟส์', 'en' => 'Maldives', 'local' => 'Maldives'),
      'MO' => array('th' => 'มาเก๊า', 'en' => 'Macau', 'local' => '澳門'),
      'MK' => array('th' => 'มาซิโดเนีย', 'en' => 'Macedonia (FYROM)', 'local' => 'Македонија'),
      'MG' => array('th' => 'มาดากัสการ์', 'en' => 'Madagascar', 'local' => 'Madagasikara'),
      'YT' => array('th' => 'มายอต', 'en' => 'Mayotte', 'local' => 'Mayotte'),
      'MQ' => array('th' => 'มาร์ตินีก', 'en' => 'Martinique', 'local' => 'Martinique'),
      'MW' => array('th' => 'มาลาวี', 'en' => 'Malawi', 'local' => 'Malawi'),
      'ML' => array('th' => 'มาลี', 'en' => 'Mali', 'local' => 'Mali'),
      'MY' => array('th' => 'มาเลเซีย', 'en' => 'Malaysia', 'local' => 'Malaysia'),
      'MX' => array('th' => 'เม็กซิโก', 'en' => 'Mexico', 'local' => 'México'),
      'MM' => array('th' => 'เมียนม่าร์ (พม่า)', 'en' => 'Myanmar (Burma)', 'local' => 'မြန်မာ'),
      'MZ' => array('th' => 'โมซัมบิก', 'en' => 'Mozambique', 'local' => 'Moçambique'),
      'MC' => array('th' => 'โมนาโก', 'en' => 'Monaco', 'local' => 'Monaco'),
      'MA' => array('th' => 'โมร็อกโก', 'en' => 'Morocco', 'local' => 'Morocco'),
      'FM' => array('th' => 'ไมโครนีเซีย', 'en' => 'Micronesia', 'local' => 'Micronesia'),
      'GI' => array('th' => 'ยิบรอลตาร์', 'en' => 'Gibraltar', 'local' => 'Gibraltar'),
      'UG' => array('th' => 'ยูกันดา', 'en' => 'Uganda', 'local' => 'Uganda'),
      'UA' => array('th' => 'ยูเครน', 'en' => 'Ukraine', 'local' => 'Україна'),
      'YE' => array('th' => 'เยเมน', 'en' => 'Yemen', 'local' => '‫اليمن‬‎'),
      'DE' => array('th' => 'เยอรมนี', 'en' => 'Germany', 'local' => 'Deutschland'),
      'RW' => array('th' => 'รวันดา', 'en' => 'Rwanda', 'local' => 'Rwanda'),
      'RU' => array('th' => 'รัสเซีย', 'en' => 'Russia', 'local' => 'Россия'),
      'RE' => array('th' => 'เรอูนียง', 'en' => 'Reunion', 'local' => 'La Réunion'),
      'RO' => array('th' => 'โรมาเนีย', 'en' => 'Romania', 'local' => 'România'),
      'LU' => array('th' => 'ลักเซมเบิร์ก', 'en' => 'Luxembourg', 'local' => 'Luxembourg'),
      'LV' => array('th' => 'ลัตเวีย', 'en' => 'Latvia', 'local' => 'Latvija'),
      'LA' => array('th' => 'ลาว', 'en' => 'Laos', 'local' => 'ລາວ'),
      'LI' => array('th' => 'ลิกเตนสไตน์', 'en' => 'Liechtenstein', 'local' => 'Liechtenstein'),
      'LT' => array('th' => 'ลิทัวเนีย', 'en' => 'Lithuania', 'local' => 'Lietuva'),
      'LY' => array('th' => 'ลิเบีย', 'en' => 'Libya', 'local' => '‫ليبيا‬‎'),
      'LS' => array('th' => 'เลโซโท', 'en' => 'Lesotho', 'local' => 'Lesotho'),
      'LB' => array('th' => 'เลบานอน', 'en' => 'Lebanon', 'local' => '‫لبنان‬‎'),
      'LR' => array('th' => 'ไลบีเรีย', 'en' => 'Liberia', 'local' => 'Liberia'),
      'VU' => array('th' => 'วานูอาตู', 'en' => 'Vanuatu', 'local' => 'Vanuatu'),
      'WF' => array('th' => 'วาลลิสและฟุตูนา', 'en' => 'Wallis &amp; Futuna', 'local' => 'Wallis &amp; Futuna'),
      'VE' => array('th' => 'เวเนซุเอลา', 'en' => 'Venezuela', 'local' => 'Venezuela'),
      'VN' => array('th' => 'เวียดนาม', 'en' => 'Vietnam', 'local' => 'Việt Nam'),
      'LK' => array('th' => 'ศรีลังกา', 'en' => 'Sri Lanka', 'local' => 'ශ්‍රී ලංකාව'),
      'ES' => array('th' => 'สเปน', 'en' => 'Spain', 'local' => 'España'),
      'SJ' => array('th' => 'สฟาลบาร์และยานไมเอน', 'en' => 'Svalbard &amp; Jan Mayen', 'local' => 'Svalbard og Jan Mayen'),
      'SK' => array('th' => 'สโลวะเกีย', 'en' => 'Slovakia', 'local' => 'Slovensko'),
      'SI' => array('th' => 'สโลวีเนีย', 'en' => 'Slovenia', 'local' => 'Slovenija'),
      'SZ' => array('th' => 'สวาซิแลนด์', 'en' => 'Swaziland', 'local' => 'Swaziland'),
      'CH' => array('th' => 'สวิตเซอร์แลนด์', 'en' => 'Switzerland', 'local' => 'Schweiz'),
      'SE' => array('th' => 'สวีเดน', 'en' => 'Sweden', 'local' => 'Sverige'),
      'US' => array('th' => 'สหรัฐอเมริกา', 'en' => 'United States', 'local' => 'United States'),
      'AE' => array('th' => 'สหรัฐอาหรับเอมิเรตส์', 'en' => 'United Arab Emirates', 'local' => '‫الإمارات العربية المتحدة‬‎'),
      'GB' => array('th' => 'สหราชอาณาจักร', 'en' => 'United Kingdom', 'local' => 'United Kingdom'),
      'CZ' => array('th' => 'สาธารณรัฐเช็ก', 'en' => 'Czech Republic', 'local' => 'Česká republika'),
      'DO' => array('th' => 'สาธารณรัฐโดมินิกัน', 'en' => 'Dominican Republic', 'local' => 'República Dominicana'),
      'CF' => array('th' => 'สาธารณรัฐแอฟริกากลาง', 'en' => 'Central African Republic', 'local' => 'République centrafricaine'),
      'SG' => array('th' => 'สิงคโปร์', 'en' => 'Singapore', 'local' => 'Singapore'),
      'IC' => array('th' => 'หมู่เกาะคานารี', 'en' => 'Canary Islands', 'local' => 'islas Canarias'),
      'CK' => array('th' => 'หมู่เกาะคุก', 'en' => 'Cook Islands', 'local' => 'Cook Islands'),
      'KY' => array('th' => 'หมู่เกาะเคย์แมน', 'en' => 'Cayman Islands', 'local' => 'Cayman Islands'),
      'CC' => array('th' => 'หมู่เกาะโคโคส (คีลิง) (Kepulauan Cocos', 'en' => 'Cocos (Keeling) Islands (Kepulauan Cocos', 'local' => 'Keeling)'),
      'SB' => array('th' => 'หมู่เกาะโซโลมอน', 'en' => 'Solomon Islands', 'local' => 'Solomon Islands'),
      'TC' => array('th' => 'หมู่เกาะเติกส์และหมู่เกาะเคคอส', 'en' => 'Turks &amp; Caicos Islands', 'local' => 'Turks &amp; Caicos Islands'),
      'MP' => array('th' => 'หมู่เกาะนอร์เทิร์นมาเรียนา', 'en' => 'Northern Mariana Islands', 'local' => 'Northern Mariana Islands'),
      'VG' => array('th' => 'หมู่เกาะบริติชเวอร์จิน', 'en' => 'British Virgin Islands', 'local' => 'British Virgin Islands'),
      'PN' => array('th' => 'หมู่เกาะพิตแคร์น', 'en' => 'Pitcairn Islands', 'local' => 'Pitcairn Islands'),
      'FK' => array('th' => 'หมู่เกาะฟอล์กแลนด์ (Falkland Islands', 'en' => 'Falkland Islands', 'local' => 'Islas Malvinas'),
      'FO' => array('th' => 'หมู่เกาะแฟโร', 'en' => 'Faroe Islands', 'local' => 'Føroyar'),
      'MH' => array('th' => 'หมู่เกาะมาร์แชลล์', 'en' => 'Marshall Islands', 'local' => 'Marshall Islands'),
      'VI' => array('th' => 'หมู่เกาะยูเอสเวอร์จิน', 'en' => 'U.S. Virgin Islands', 'local' => 'U.S. Virgin Islands'),
      'UM' => array('th' => 'หมู่เกาะรอบนอกของสหรัฐอเมริกา', 'en' => 'U.S. Outlying Islands', 'local' => 'U.S. Outlying Islands'),
      'AX' => array('th' => 'หมู่เกาะโอลันด์', 'en' => 'Aland Islands', 'local' => 'Åland Islands'),
      'AS' => array('th' => 'อเมริกันซามัว', 'en' => 'American Samoa', 'local' => 'American Samoa'),
      'AU' => array('th' => 'ออสเตรเลีย', 'en' => 'Australia', 'local' => 'Australia'),
      'AT' => array('th' => 'ออสเตรีย', 'en' => 'Austria', 'local' => 'Österreich'),
      'AD' => array('th' => 'อันดอร์รา', 'en' => 'Andorra', 'local' => 'Andorra'),
      'AF' => array('th' => 'อัฟกานิสถาน', 'en' => 'Afghanistan', 'local' => '‫افغانستان‬‎'),
      'AZ' => array('th' => 'อาเซอร์ไบจาน', 'en' => 'Azerbaijan', 'local' => 'Azərbaycan'),
      'AR' => array('th' => 'อาร์เจนตินา', 'en' => 'Argentina', 'local' => 'Argentina'),
      'AM' => array('th' => 'อาร์เมเนีย', 'en' => 'Armenia', 'local' => 'Հայաստան'),
      'AW' => array('th' => 'อารูบา', 'en' => 'Aruba', 'local' => 'Aruba'),
      'GQ' => array('th' => 'อิเควทอเรียลกินี', 'en' => 'Equatorial Guinea', 'local' => 'Guinea Ecuatorial'),
      'IT' => array('th' => 'อิตาลี', 'en' => 'Italy', 'local' => 'Italia'),
      'IN' => array('th' => 'อินเดีย', 'en' => 'India', 'local' => 'भारत'),
      'ID' => array('th' => 'อินโดนีเซีย', 'en' => 'Indonesia', 'local' => 'Indonesia'),
      'IQ' => array('th' => 'อิรัก', 'en' => 'Iraq', 'local' => '‫العراق‬‎'),
      'IL' => array('th' => 'อิสราเอล', 'en' => 'Israel', 'local' => '‫ישראל‬‎'),
      'IR' => array('th' => 'อิหร่าน', 'en' => 'Iran', 'local' => '‫ایران‬‎'),
      'EG' => array('th' => 'อียิปต์', 'en' => 'Egypt', 'local' => '‫مصر‬‎'),
      'UZ' => array('th' => 'อุซเบกิสถาน', 'en' => 'Uzbekistan', 'local' => 'Oʻzbekiston'),
      'UY' => array('th' => 'อุรุกวัย', 'en' => 'Uruguay', 'local' => 'Uruguay'),
      'EC' => array('th' => 'เอกวาดอร์', 'en' => 'Ecuador', 'local' => 'Ecuador'),
      'ET' => array('th' => 'เอธิโอเปีย', 'en' => 'Ethiopia', 'local' => 'Ethiopia'),
      'ER' => array('th' => 'เอริเทรีย', 'en' => 'Eritrea', 'local' => 'Eritrea'),
      'SV' => array('th' => 'เอลซัลวาดอร์', 'en' => 'El Salvador', 'local' => 'El Salvador'),
      'EE' => array('th' => 'เอสโตเนีย', 'en' => 'Estonia', 'local' => 'Eesti'),
      'AI' => array('th' => 'แองกวิลลา', 'en' => 'Anguilla', 'local' => 'Anguilla'),
      'AO' => array('th' => 'แองโกลา', 'en' => 'Angola', 'local' => 'Angola'),
      'AQ' => array('th' => 'แอนตาร์กติกา', 'en' => 'Antarctica', 'local' => 'Antarctica'),
      'AG' => array('th' => 'แอนติกาและบาร์บูดา', 'en' => 'Antigua &amp; Barbuda', 'local' => 'Antigua &amp; Barbuda'),
      'ZA' => array('th' => 'แอฟริกาใต้', 'en' => 'South Africa', 'local' => 'South Africa'),
      'DZ' => array('th' => 'แอลจีเรีย', 'en' => 'Algeria', 'local' => 'Algeria'),
      'AL' => array('th' => 'แอลเบเนีย', 'en' => 'Albania', 'local' => 'Shqipëri'),
      'OM' => array('th' => 'โอมาน', 'en' => 'Oman', 'local' => '‫عُمان‬‎'),
      'IS' => array('th' => 'ไอซ์แลนด์', 'en' => 'Iceland', 'local' => 'Ísland'),
      'IE' => array('th' => 'ไอร์แลนด์', 'en' => 'Ireland', 'local' => 'Ireland'),
      'CI' => array('th' => 'ไอวอรี่โคสต์', 'en' => 'Côte d’Ivoire', 'local' => 'Côte d’Ivoire'),
      'HK' => array('th' => 'ฮ่องกง', 'en' => 'Hong Kong', 'local' => '香港'),
      'HN' => array('th' => 'ฮอนดูรัส', 'en' => 'Honduras', 'local' => 'Honduras'),
      'HU' => array('th' => 'ฮังการี', 'en' => 'Hungary', 'local' => 'Magyarország'),
      'HT' => array('th' => 'เฮติ', 'en' => 'Haiti', 'local' => 'Haiti')
    );
  }

  /**
   * อ่านชื่อประเทศจาก ISO ตามภาษา (ถ้าไม่มีใช้ภาษาอังกฤษ)
   *
   * @param int $iso
   * @return string คืนค่าว่างถ้าไม่พบ
   * @assert ('TH') [==] 'ไทย'
   */
  public static function get($iso)
  {
    $datas = self::init();
    $language = Language::name();
    $language = in_array($language, array_keys(reset($datas))) ? $language : 'en';
    return isset($datas[$iso]) ? $datas[$iso][$language] : '';
  }

  /**
   * list รายชื่อประเทศทั้งหมด  ตามภาษา (ถ้าไม่มีใช้ภาษาอังกฤษ)
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
      $result[$iso] = $values[$language].($values[$language] == $values['local'] ? '' : ' ('.$values['local'].')');
    }
    if ($language == 'en') {
      asort($result);
    }
    return $result;
  }
}
