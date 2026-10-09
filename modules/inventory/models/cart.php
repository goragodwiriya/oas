<?php
/**
 * @filesource modules/inventory/models/cart.php
 *
 * แปลง "ตะกร้า" (รหัสสินค้า + จำนวน + ส่วนลด) เป็นบรรทัดเอกสาร และรวมยอด
 *
 * ⚠️ อยู่ในโมดูล inventory เพราะเป็นเรื่องของ "ชั้นเอกสาร" ล้วน ๆ ไม่ใช่ของ
 * หน้าร้านหรือของ e-commerce — ทั้งสองโมดูลนั้นเรียกที่เดียวกันนี้ จึงไม่มีทาง
 * ที่ราคาหรือสูตรรวมยอดของสองทางจะค่อย ๆ ต่างกัน และไม่ต้องพึ่งกันเอง
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Inventory\Cart;

use Inventory\Base\Model as Base;
use Inventory\Products\Model as Products;

/**
 * Model ตะกร้า
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * แปลงตะกร้าจากหน้าจอเป็นบรรทัดเอกสาร
     *
     * ราคาและหน่วยอ่านจากทะเบียนสินค้าเสมอ ไม่เชื่อค่าที่ส่งมาจากเบราว์เซอร์
     * (ยกเว้นจำนวนกับส่วนลด ซึ่งเป็นสิ่งที่คนขายตั้งใจกรอก) — ไม่งั้นแก้ราคาใน
     * หน้าเว็บแล้วขายได้ในราคาที่ตัวเองตั้ง
     *
     * @param array $cart [['product_code' => ..., 'quantity' => ..., 'discount' => ...], ...]
     *
     * @throws \Exception เมื่อไม่พบสินค้า หรือจำนวนไม่ถูกต้อง
     *
     * @return array
     */
    public static function buildLines(array $cart)
    {
        $lines = [];
        $seen = [];
        foreach ($cart as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = trim((string) (isset($row['product_code']) ? $row['product_code'] : ''));
            if ($code === '') {
                continue;
            }
            if (isset($seen[$code])) {
                // สินค้าเดิมซ้ำสองบรรทัด = สต๊อกถูกนับซ้ำ ต้องรวมจำนวนมาก่อนส่ง
                throw new \Exception('สินค้า '.$code.' ซ้ำกันในตะกร้า');
            }
            $seen[$code] = true;

            $detail = Products::detail($code);
            if ($detail === null) {
                throw new \Exception('ไม่พบสินค้ารหัส '.$code);
            }
            $quantity = (float) (isset($row['quantity']) ? $row['quantity'] : 0);
            if ($quantity <= 0) {
                throw new \Exception('จำนวนของ '.$code.' ต้องมากกว่าศูนย์');
            }
            $discount = (float) (isset($row['discount']) ? $row['discount'] : 0);
            $price = (float) $detail['price'];
            // ส่วนลดเป็นเปอร์เซ็นต์ต่อบรรทัด เหมือนหน้าเอกสารของโมดูล inventory
            $amount = round(($price - ($price * $discount / 100)) * $quantity, 2);

            $lines[] = [
                'inventory_id' => (int) $detail['inventory_id'],
                'inventory_item_id' => empty($detail['inventory_item_id']) ? null : (int) $detail['inventory_item_id'],
                'product_code' => $detail['product_code'],
                'product_no' => $detail['product_no'],
                'topic' => $detail['topic'],
                'quantity' => $quantity,
                'unit' => $detail['unit'],
                'price' => $price,
                'discount' => $discount,
                'vat' => empty($detail['vat']) ? 0 : 1,
                'total' => $amount,
                'cut_stock' => $quantity * ($detail['cut_stock'] > 0 ? (float) $detail['cut_stock'] : 1)
            ];
        }

        if (empty($lines)) {
            throw new \Exception('ตะกร้าว่าง ไม่มีสินค้าให้ขาย');
        }

        return $lines;
    }

    /**
     * รวมยอดของตะกร้า
     *
     * ⚠️ คิดฝั่งเซิร์ฟเวอร์เสมอ ไม่รับยอดรวมจากเบราว์เซอร์ — หน้าจอคำนวณไว้ให้ดู
     * ระหว่างกดเท่านั้น ยอดที่บันทึกจริงต้องมาจากที่เดียวคือที่นี่
     *
     * ⚠️ **สัญญาของคอลัมน์ `orders.total` คือ "ยอดหลังส่วนลด ไม่รวมภาษี"**
     * ยอดที่ต้องจ่ายจริงคำนวณเป็น `total + vat - tax` ทุกที่ในระบบ
     * (models/orders.php:25 · dashboard · หน้ารับชำระ · admin.js ของหน้าเอกสาร)
     * ถ้าเก็บยอดรวมภาษีลง `total` ทุกที่จะบวกภาษีซ้ำอีกรอบโดยไม่มีอะไรฟ้อง
     *
     * ⚠️ **ส่วนลดท้ายบิลลดฐานภาษีด้วย** และ **ค่าจัดส่งรวมอยู่ใน `total`**
     * สองข้อนี้ไม่ใช่ความชอบส่วนตัว แต่เป็นสัญญาที่ของเดิมใช้อยู่แล้ว :
     *   - หน้าเอกสาร (modules/inventory/admin.js บรรทัด 286-304) ลด vatBase ด้วย
     *     ส่วนลดท้ายบิลก่อนคิดภาษี ถ้าที่นี่ไม่ลด หน้าร้านกับหน้าเอกสารจะได้ภาษี
     *     คนละยอดจากตะกร้าใบเดียวกัน
     *   - โมดูล shipping (models/shipment.php บรรทัด 111) แก้ค่าจัดส่งด้วยการเขียน
     *     `total` ใหม่เป็น `total - ค่าจัดส่งเดิม + ค่าจัดส่งใหม่` แปลว่าค่าจัดส่ง
     *     ถูกนับอยู่ใน `total` อยู่แล้ว ถ้าที่นี่ไม่บวกเข้าไป ใบที่ขายหน้าร้านแบบ
     *     ส่งของจะเก็บเงินขาดไปเท่ากับค่าส่งทุกใบ
     *
     * @param array $lines
     * @param float $vatRate
     * @param int   $vatStatus    0=ไม่มีภาษี 1=แยกภาษี 2=รวมภาษีในราคาแล้ว
     * @param float $discount     ส่วนลดท้ายบิลเป็นจำนวนเงิน
     * @param float $shippingCost ค่าจัดส่ง
     *
     * @return array subtotal · discount · shipping_cost · vat · tax · total (ไม่รวมภาษี) · payable
     */
    public static function totals(array $lines, $vatRate, $vatStatus = 1, $discount = 0, $shippingCost = 0)
    {
        $vatRate = (float) $vatRate;
        $vatStatus = (int) $vatStatus;
        $subtotal = 0.0;
        $vatBase = 0.0;
        foreach ($lines as $line) {
            $subtotal += (float) $line['total'];
            if (!empty($line['vat'])) {
                $vatBase += (float) $line['total'];
            }
        }
        $subtotal = round($subtotal, 2);

        // ส่วนลดเกินยอดขายไม่ได้ ไม่งั้นยอดท้ายบิลติดลบแล้วกลายเป็นการจ่ายเงินคืน
        $discount = round(max(0.0, min((float) $discount, $subtotal)), 2);
        $shippingCost = round(max(0.0, (float) $shippingCost), 2);
        $vatBase = max(0.0, $vatBase - $discount);

        $vat = 0.0;
        if ($vatStatus === 1 && $vatRate > 0) {
            $vat = round($vatBase * $vatRate / 100, 2);
        } elseif ($vatStatus === 2 && $vatRate > 0) {
            // ราคารวมภาษีแล้ว — แยกภาษีออกมาจากยอด ไม่บวกเพิ่ม
            $vat = round($vatBase - ($vatBase * 100 / (100 + $vatRate)), 2);
        }

        // ราคารวมภาษีแล้ว: ยอดฐานคือยอดที่หักภาษีออกไปแล้ว
        // แยกภาษี / ไม่มีภาษี: ยอดฐานคือยอดตามที่ตั้งราคาไว้
        $total = $vatStatus === 2 ? round($subtotal - $vat, 2) : $subtotal;
        $total = round($total - $discount + $shippingCost, 2);

        return [
            'subtotal' => $subtotal,
            'discount' => $discount,
            'shipping_cost' => $shippingCost,
            'vat' => $vat,
            'tax' => 0.0,
            'total' => $total,
            // ยอดที่ต้องเก็บจากลูกค้าจริง — สูตรเดียวกับที่ทั้งระบบใช้
            'payable' => round($total + $vat, 2)
        ];
    }

    /**
     * อัตราภาษีของไซต์
     *
     * @return float
     */
    public static function vatRate()
    {
        return (float) Base::config('vat', 0);
    }
}
