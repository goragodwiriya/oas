<?php
/**
 * @filesource modules/inventory/controllers/export.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Inventory\Export;

use Gcms\Login;
use Kotchasan\Currency;
use Kotchasan\Date;
use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * export.php?module=inventory-export&typ=xxx
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Kotchasan\Controller
{
    /**
     * ส่งออกไฟล์ csv หรือ การพิมพ์
     *
     * @param Request $request
     *
     * @return string
     */
    public function export(Request $request)
    {
        // สมาชิก
        if (Login::isMember()) {
            // อ่านข้อมูลที่เลือก
            $index = \Inventory\Order\Model::get($request->get('id')->toInt(), 'IN');
            // template
            $templates = Language::get('ORDER_STATUS');
            if ($index && isset($templates[$index->status])) {
                // โหลด template
                $billing = $this->getTemplate($index->status);
                // ข้อมูลการทำรายการ
                $detail = '';
                $i = 0;
                $subtotal = 0;
                $vat = 0;
                foreach (\Inventory\Stock\Model::get($index->id, $index->status) as $item) {
                    ++$i;
                    $detail .= '<tr>';
                    foreach ($billing['details'] as $col) {
                        if ($col == 'item') {
                            $detail .= '<td class=center>'.$i.'</td>';
                        } elseif ($col == 'quantity') {
                            $detail .= '<td class=center>'.$item['quantity'].' '.$item['unit'].'</td>';
                        } elseif ($col == 'topic') {
                            $detail .= '<td>'.nl2br($item['topic']).'</td>';
                        } elseif ($col == 'amount') {
                            $discount = ($item['discount'] * $item['price']) / 100;
                            $amount = ($item['price'] - $discount) * $item['quantity'];
                            $detail .= '<td class=right>'.Currency::format($amount).'</td>';
                            $subtotal += $amount;
                        } else {
                            $detail .= '<td class=right>'.Currency::format($item[$col]).'</td>';
                        }
                    }
                    $detail .= '</tr>';
                    $vat += $item['vat'];
                }
                // ภาษาที่ใช้งานอยู่
                $lng = Language::name();
                // จำนวนเงินสุทธิ
                $net_amount = $index->total + $index->vat - $index->tax;
                // ใส่ลงใน template
                $content = array(
                    '/{LANGUAGE}/' => $lng,
                    '/{CONTENT}/' => $billing['detail'],
                    '/{WEBURL}/' => WEB_URL,
                    '/{TITLE}/' => $billing['title'],
                    '/%CONTACTOR%/' => $index->contactor,
                    '/%ORDERDATE%/' => Date::format($index->order_date, 'd M Y'),
                    '/%PAYMENTDATE%/' => Date::format($index->payment_date, 'd M Y'),
                    '/%ORDERNO%/' => $index->order_no,
                    '/%COMPANY%/' => $index->customer_id == 0 ? Language::get('Cash') : $index->customer,
                    '/%BRANCH%/' => $index->branch,
                    '/%ADDRESS%/' => $index->address,
                    '/%PROVINCE%/' => $index->province,
                    '/%ZIPCODE%/' => $index->zipcode,
                    '/%COUNTRY%/' => $index->country,
                    '/%PHONE%/' => $index->phone,
                    '/%EMAIL%/' => $index->email,
                    '/%TAXID%/' => $index->tax_id,
                    '/%COMMENT%/' => nl2br($index->comment),
                    '/%AUTHORITY%/' => isset(self::$cfg->authorized) ? self::$cfg->authorized : '',
                    '/%AUTHORITYNAME%/' => isset(self::$cfg->company_name) ? self::$cfg->company_name : '',
                    '/%AUTHORITYADDRESS%/' => isset(self::$cfg->address) ? self::$cfg->address : '',
                    '/%AUTHORITYPROVINCE%/' => isset(self::$cfg->province) ? self::$cfg->province : '',
                    '/%AUTHORITYZIPCODE%/' => isset(self::$cfg->zipcode) ? self::$cfg->zipcode : '',
                    '/%AUTHORITYTAXID%/' => isset(self::$cfg->tax_id) ? self::$cfg->tax_id : '',
                    '/%AUTHORITYBRANCH%/' => isset(self::$cfg->branch) ? self::$cfg->branch : '',
                    '/%BANK%/' => Language::get('BANKS', '', (isset(self::$cfg->bank) ? self::$cfg->bank : '')),
                    '/%BANKNAME%/' => isset(self::$cfg->bank_name) ? self::$cfg->bank_name : '',
                    '/%BANKNO%/' => isset(self::$cfg->bank_no) ? self::$cfg->bank_no : '',
                    '/%SUBTOTAL%/' => Currency::format($subtotal),
                    '/%DISCOUNT%/' => Currency::format($index->discount),
                    '/%TAX%/' => Currency::format($index->tax),
                    '/%VAT%/' => Currency::format($index->vat),
                    '/%NETAMOUNT%/' => Currency::format($net_amount),
                    '/%THAIBAHT%/' => $lng == 'th' ? Currency::bahtThai($net_amount) : Currency::bahtEng($net_amount),
                    '/<tr>[\r\n\s\t]{0,}<td>[\r\n\s\t]{0,}%DETAIL%[\r\n\s\t]{0,}<\/td>[\r\n\s\t]{0,}<\/tr>/' => $detail,
                    '/%LOGO%/' => is_file(ROOT_PATH.DATA_FOLDER.'logo.jpg') ? '<img class="logo" src="'.WEB_URL.DATA_FOLDER.'logo.jpg">' : '',
                    '/{CLASS}/' => $index->status
                );
                \Inventory\Export\View::toPrint($content);
            }
        }
    }

    /**
     * อ่าน template
     *
     * @param string $tempate
     *
     * @return array|null คืนค่าข้อมูล template ถ้าไม่พบคืนค่า null
     */
    public function getTemplate($tempate)
    {
        $file = ROOT_PATH.'modules/inventory/template/'.$tempate.'.html';
        if (is_file($file)) {
            // โหลด template
            $file = file_get_contents($file);
            // parse template
            $patt = '/(.*?)<title>(.*?)<\/title>?(.*?)(<detail>(.*?)<\/detail>)?(.*?)<body>(.*?)<\/body>(.*?)/isu';
            $billing = array();
            if (preg_match($patt, $file, $match)) {
                $billing['title'] = $match[2];
                $billing['detail'] = $match[7];
                if (preg_match_all('/<item>([a-z]{0,})<\/item>/isu', $match[6], $items)) {
                    foreach ($items[1] as $i => $row) {
                        if ($row != '') {
                            $billing['details'][] = $row;
                        }
                    }
                }
            }
            return $billing;
        }
        return null;
    }
}
