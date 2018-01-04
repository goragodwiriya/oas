<?php
/**
 * @filesource modules/inventory/controllers/export.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Inventory\Export;

use \Kotchasan\Http\Request;
use \Kotchasan\Language;
use \Gcms\Login;
use \Kotchasan\Date;
use \Kotchasan\Currency;

/**
 * Controller สำหรับสร้างไฟล์ PDF หรือ หน้าสำหรับพิมพ์
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
   */
  public function execute(Request $request)
  {
    // สมาชิก
    if (Login::isMember()) {
      // อ่านข้อมูลที่เลือก
      $index = \Inventory\Order\Model::get($request->get('id')->toInt());
      // template
      $templates = array(
        'IN' => array(
          1 => 'purchaseorder',
          6 => 'receivinginventory',
        ),
        'OUT' => array(
          1 => 'quotation',
          6 => 'receipt',
        )
      );
      if ($index && isset($templates[$index->stock_status][$index->status])) {
        // โหลด template
        $billing = $this->getTemplate($templates[$index->stock_status][$index->status]);
        // ข้อมูลการทำรายการ
        $detail = '';
        $i = 0;
        $subtotal = 0;
        $vat = 0;
        foreach (\Inventory\Stock\Model::get($index->id, $index->stock_status) as $item) {
          $i++;
          $detail .= '<tr>';
          foreach ($billing['details'] as $col) {
            if ($col == 'item') {
              $detail .= '<td class=center>'.$i.'</td>';
            } elseif ($col == 'quantity') {
              $detail .= '<td class=center>'.number_format($item['quantity']).' '.$item['unit'].'</td>';
            } elseif ($col == 'topic') {
              $detail .= '<td>'.nl2br($item['topic']).'</td>';
            } elseif ($col == 'amount') {
              $detail .= '<td class=right>'.Currency::format(($item['price'] - $item['discount']) * $item['quantity']).'</td>';
              $subtotal += ($item['price'] * $item['quantity']);
            } else {
              $detail .= '<td class=right>'.Currency::format($item[$col]).'</td>';
            }
          }
          $detail .= '</tr>';
          $vat += $item['vat'];
        }
        // ภาษาที่ใช้งานอยู่
        $lng = Language::name();
        // ใส่ลงใน template
        $content = array(
          '/{LANGUAGE}/' => $lng,
          '/{CONTENT}/' => $billing['detail'],
          '/{WEBURL}/' => WEB_URL,
          '/{TITLE}/' => $billing['title'],
          '/%CONTACTOR%/' => $index->customer,
          '/%ORDERDATE%/' => Date::format($index->order_date, 'd M Y'),
          '/%PAYMENTDATE%/' => Date::format($index->payment_date, 'd M Y'),
          '/%ORDERNO%/' => $index->order_no,
          '/%COMPANY%/' => $index->customer_id == 0 ? Language::get('Cash') : $index->company,
          '/%BRANCH%/' => $index->branch,
          '/%ADDRESS%/' => $index->address,
          '/%PROVINCE%/' => $index->province,
          '/%ZIPCODE%/' => $index->zipcode,
          '/%COUNTRY%/' => $index->country,
          '/%PHONE%/' => $index->phone,
          '/%EMAIL%/' => $index->email,
          '/%TAXID%/' => $index->tax_id,
          '/%COMMENT%/' => nl2br($index->comment),
          '/%AUTHORITY%/' => self::$cfg->authorized,
          '/%AUTHORITYNAME%/' => self::$cfg->company_name,
          '/%AUTHORITYADDRESS%/' => self::$cfg->address,
          '/%AUTHORITYPROVINCE%/' => self::$cfg->province,
          '/%AUTHORITYZIPCODE%/' => self::$cfg->zipcode,
          '/%AUTHORITYTAXID%/' => self::$cfg->tax_id,
          '/%AUTHORITYBRANCH%/' => self::$cfg->branch,
          '/%BANK%/' => self::$cfg->bank,
          '/%BANKNAME%/' => self::$cfg->bank_name,
          '/%BANKNO%/' => self::$cfg->bank_no,
          '/%SUBTOTAL%/' => Currency::format($subtotal),
          '/%DISCOUNT%/' => Currency::format($index->discount),
          '/%TAX%/' => Currency::format($index->tax),
          '/%VAT%/' => Currency::format($index->vat),
          '/%NETAMOUNT%/' => Currency::format($index->total + $index->vat),
          '/%THAIBAHT%/' => $lng == 'th' ? Currency::bahtThai($index->total + $index->vat) : Currency::bahtEng($index->total + $index->vat),
          '/<tr>[\r\n\s\t]{0,}<td>[\r\n\s\t]{0,}%DETAIL%[\r\n\s\t]{0,}<\/td>[\r\n\s\t]{0,}<\/tr>/' => $detail,
          '/%LOGO%/' => is_file(ROOT_PATH.DATA_FOLDER.'logo.jpg') ? '<img class="logo" src="'.WEB_URL.DATA_FOLDER.'logo.jpg">' : '',
        );
        \Inventory\Export\View::toPrint($content);
      }
    }
  }

  /**
   * อ่าน template
   *
   * @param string $tempate
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
          foreach ($items[1] AS $i => $row) {
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