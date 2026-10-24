<?php
/**
 * modules/inventory/install/legacy-oas.php — ข้อมูลของ oas รุ่นก่อน → รูปที่ตัวแปลงสี่ชั้น (legacy.php) และแอปอ่านได้
 *
 * oas เท่านั้น (ผู้ใช้ตัดสิน 2026-10-24 — oms มีไซต์เดียวที่แปลงแล้ว ไม่มีการปรับรุ่น) · modules/inventory/install/upgrade.php เรียก
 *
 * ต้นทางที่รองรับ
 *   oas ระบบเดิม (GCMS11)  inventory ไม่มีรหัสและราคา · inventory_items คีย์หลัก product_no (รหัส ราคา หน่วยอยู่ที่หน่วยย่อย) ·
 *                          inventory.unit = id ของหมวดหน่วยนับ · ยอดคงเหลืออยู่ที่ inventory.stock · บรรทัดเอกสารอยู่ใน stock
 *                          → inventoryPrepareFlat() ทำให้เป็นทะเบียนแบบแบน (product_code · inventory_items.id/sku)
 *   oas 7.0.x             เอกสารอยู่ใน `order` / `order_item` (QT · GR · RCP · PO) · สมุดบัญชีอ้างเอกสารและบรรทัดด้วย id เดิม
 *                          → inventoryConvertOrders() ย้ายเข้า orders / order_items (id เดิมเมื่อว่าง)
 *   oas 7.0.3+ (แบบแบน)    product_code + inventory_items.sku — ไม่ต้องเตรียม legacy.php แปลงต่อได้เลย
 *
 * กติกา (เดียวกับ upgrade_core) : ทุกขั้นถามว่า "ต้องแก้ไหม" รันซ้ำได้ ไม่ทับค่าที่มีแล้ว · ไม่ลบข้อมูลธุรกิจ
 * (ตาราง order / order_item / stock เดิมคงไว้ครบ) · คอลัมน์ที่ย้ายค่าไปแล้ว ผู้ย้ายเป็นผู้ตัด
 * ยอดคงเหลือ/ต้นทุนเป็น cache — upgrade.php ตั้งธง inventory_rebuild ให้แอปคำนวณใหม่ (Inventory\Upgrade\Model)
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

/**
 * ทะเบียนสินค้าของ oas ระบบเดิม → ทะเบียนแบบแบนที่ legacy.php แปลงต่อได้ — ไม่ทำอะไรกับฐานที่เป็นแบบแบนแล้ว
 *
 * @param object $db          Db ของตัวติดตั้ง
 * @param string $prefix
 * @param array  $content     รายการผลลัพธ์ (HTML <li>)
 * @param int    $warehouseId คลังตั้งต้น (ยอดยกมาลงคลังนี้ — engine ไม่ยอมรับคลัง 0)
 */
function inventoryPrepareFlat($db, $prefix, array &$content, $warehouseId)
{
    $tInventory = $prefix.'_inventory';
    $tItems = $prefix.'_inventory_items';
    if (!$db->tableExists($tInventory) || $db->fieldExists($tInventory, 'product_id')) {
        return;
    }

    // ---- inventory — คอลัมน์ที่ทะเบียนแบบแบนมี (ส่วนที่ legacy.php ย้ายไปตัวสินค้าแล้วตัดทิ้งเอง) ----
    foreach ([
        'product_code' => ['varchar(150)', true, null, 'id'],
        'description' => ['text', true, null, 'topic'],
        'model_id' => ['varchar(10)', true, null, 'category_id'],
        'type_id' => ['varchar(10)', true, null, 'model_id'],
        'price' => ['double', false, 0, 'unit'],
        'allow_negative' => ['tinyint(1)', false, 0, 'count_stock'],
        'is_active' => ['tinyint(1)', false, 1, 'allow_negative'],
        'created_at' => ['datetime', true, null, 'is_active'],
        'updated_at' => ['datetime', true, null, 'created_at']
    ] as $_col => $_def) {
        if (!$db->fieldExists($tInventory, $_col)) {
            ensureColumn($db, $tInventory, $_col, $_def[0], $_def[1], $_def[2], '', $_def[3]);
        }
    }
    // รหัสระดับสินค้าของ e-OMS / oas ที่มี product_no ที่ตัวสินค้า
    if ($db->fieldExists($tInventory, 'product_no')) {
        $db->query("UPDATE `$tInventory` SET `product_code` = `product_no` WHERE (`product_code` IS NULL OR `product_code` = '') AND `product_no` IS NOT NULL AND `product_no` != ''");
    }
    if ($db->fieldExists($tInventory, 'inuse')) {
        $db->query("UPDATE `$tInventory` SET `is_active` = IF(`inuse` > 0, 1, 0)");
    }
    // วันที่สร้างของระบบเดิม (date) → created_at แล้วตัดคอลัมน์เดิม (ไซต์ติดตั้งใหม่ไม่มี)
    if ($db->fieldExists($tInventory, 'create_date')) {
        $db->query("UPDATE `$tInventory` SET `created_at` = `create_date` WHERE `created_at` IS NULL AND `create_date` IS NOT NULL");
        $db->query("ALTER TABLE `$tInventory` DROP `create_date`");
        $content[] = '<li class="correct">inventory: ย้ายวันที่สร้างเดิม (create_date) เข้า created_at</li>';
    }

    // ---- inventory_items — คีย์หลักของระบบเดิมคือ product_no ต้องย้ายมาที่ id (บรรทัดและสมุดบัญชีอ้างด้วยตัวเลข) ----
    if ($db->tableExists($tItems)) {
        if (!$db->fieldExists($tItems, 'id')) {
            $db->query("ALTER TABLE `$tItems` ADD COLUMN `id` int(11) NOT NULL FIRST");
            $db->query('SET @row_number = 0');
            $db->query("UPDATE `$tItems` SET `id` = (@row_number := @row_number + 1) ORDER BY `inventory_id`, `product_no`");
            $db->query("ALTER TABLE `$tItems` DROP PRIMARY KEY, ADD PRIMARY KEY (`id`), MODIFY `id` int(11) NOT NULL AUTO_INCREMENT");
            $content[] = '<li class="correct">inventory_items: ย้ายคีย์หลักจาก product_no ไป id</li>';
        }
        foreach ([
            'sku' => ['varchar(150)', true, null, 'id'],
            'barcode' => ['varchar(150)', true, null, 'sku'],
            'stock' => ['double', false, 0, 'cut_stock']
        ] as $_col => $_def) {
            if (!$db->fieldExists($tItems, $_col)) {
                ensureColumn($db, $tItems, $_col, $_def[0], $_def[1], $_def[2], '', $_def[3]);
            }
        }
        if ($db->fieldExists($tItems, 'product_no')) {
            $db->query("UPDATE `$tItems` SET `sku` = `product_no` WHERE (`sku` IS NULL OR `sku` = '') AND `product_no` IS NOT NULL AND `product_no` != ''");
        }

        // รหัส ราคา หน่วยนับ ที่ระบบเดิมเก็บไว้ที่หน่วยขายทีละ 1 (cut_stock = 1) — เติมเฉพาะช่องที่ยังว่าง
        // รหัสที่สินค้าตัวอื่นใช้อยู่แล้วข้ามไป (legacy.php ตั้งรหัสให้เองเมื่อว่าง)
        $base = "SELECT `inventory_id`, MIN(`id`) AS `id` FROM `$tItems` WHERE `cut_stock` = 1 GROUP BY `inventory_id`";
        $n = $db->query(
            "UPDATE `$tInventory` V INNER JOIN ($base) B ON B.`inventory_id` = V.`id` INNER JOIN `$tItems` I ON I.`id` = B.`id`
              LEFT JOIN `$tInventory` X ON X.`product_code` = I.`sku`
                SET V.`product_code` = I.`sku`
              WHERE (V.`product_code` IS NULL OR V.`product_code` = '') AND I.`sku` != '' AND X.`id` IS NULL"
        );
        if ($n > 0) {
            $content[] = '<li class="correct">inventory: เติมรหัสสินค้าจากหน่วยขายทีละ 1 จำนวน '.$n.' รายการ</li>';
        }
        $n = $db->query(
            "UPDATE `$tInventory` V INNER JOIN ($base) B ON B.`inventory_id` = V.`id` INNER JOIN `$tItems` I ON I.`id` = B.`id`
                SET V.`price` = I.`price`
              WHERE V.`price` = 0 AND I.`price` > 0"
        );
        if ($n > 0) {
            $content[] = '<li class="correct">inventory: เติมราคาขายจากหน่วยขายทีละ 1 จำนวน '.$n.' รายการ</li>';
        }
    }

    // หน่วยนับของ oas เดิม = id ของหมวด unit ('0' = ไม่ได้เลือก) → ชื่อ · หาชื่อไม่เจอใช้หน่วยของหน่วยขายทีละ 1
    if ($db->fieldExists($tInventory, 'unit')) {
        $n = 0;
        if ($db->tableExists($prefix.'_category')) {
            $n += $db->query(
                "UPDATE `$tInventory` V INNER JOIN `{$prefix}_category` C ON C.`type` = 'unit' AND C.`category_id` = V.`unit`
                    SET V.`unit` = C.`topic`
                  WHERE V.`unit` REGEXP '^[0-9]+$' AND V.`unit` != '0' AND C.`topic` != ''"
            );
        }
        if ($db->tableExists($tItems)) {
            $n += $db->query(
                "UPDATE `$tInventory` V
                  INNER JOIN (SELECT `inventory_id`, MIN(`id`) AS `id` FROM `$tItems` WHERE `cut_stock` = 1 GROUP BY `inventory_id`) B ON B.`inventory_id` = V.`id`
                  INNER JOIN `$tItems` I ON I.`id` = B.`id`
                    SET V.`unit` = I.`unit`
                  WHERE (V.`unit` IS NULL OR V.`unit` = '' OR V.`unit` REGEXP '^[0-9]+$') AND I.`unit` IS NOT NULL AND I.`unit` != ''"
            );
        }
        if ($n > 0) {
            $content[] = '<li class="correct">inventory: เติมหน่วยนับเป็นชื่อ '.$n.' รายการ</li>';
        }
    }

    inventoryOpeningBalance($db, $prefix, $content, $warehouseId);
}

/**
 * ยอดคงเหลือเดิม (คอลัมน์ stock ของ inventory / inventory_items) → ยอดยกมาในสมุดบัญชี
 *
 * ทำ "ต่อสินค้า" — เฉพาะสินค้าที่นับสต๊อกและยังไม่มีรายการในสมุดบัญชีเลย จึงรันซ้ำไม่เกิดยอดซ้อน
 * ระดับที่ลงตรงกับ Posting::stockLevel() : นับรายชิ้น (2) ลงที่หน่วยย่อย · นอกนั้นลงที่ระดับสินค้า (0)
 * เขียนด้วย SQL (ตัวปรับรุ่นทำงานนอกแอป) ครบสามที่ : movement · ยอดคงเหลือ · ชั้นต้นทุน
 *
 * @param object $db
 * @param string $prefix
 * @param array  $content
 * @param int    $warehouseId
 */
function inventoryOpeningBalance($db, $prefix, array &$content, $warehouseId)
{
    $tInventory = $prefix.'_inventory';
    $tItems = $prefix.'_inventory_items';
    $tMovement = $prefix.'_inventory_stock_movement';
    $tBalance = $prefix.'_inventory_stock';
    $tLayer = $prefix.'_inventory_cost_layer';
    if (!$db->tableExists($tMovement) || !$db->tableExists($tInventory) || !$db->fieldExists($tInventory, 'stock')) {
        return;
    }
    $now = date('Y-m-d H:i:s');
    $note = 'ยอดยกมาตอนเริ่มใช้สมุดบัญชีสต๊อก';
    $warehouseId = (int) $warehouseId;
    $rows = $db->customQuery("SELECT IFNULL(MAX(`id`), 0) AS `id` FROM `$tMovement`");
    $last = empty($rows) ? 0 : (int) $rows[0]->id;
    $hasItemStock = $db->tableExists($tItems) && $db->fieldExists($tItems, 'stock');
    if ($hasItemStock) {
        // สินค้านับรายชิ้น — ยอดของแต่ละเครื่อง
        $db->query(
            "INSERT INTO `$tMovement`
                (`inventory_id`, `inventory_item_id`, `warehouse_id`, `sku`, `movement_direction`, `movement_type`,
                 `reference_type`, `quantity`, `unit_cost`, `total_cost`, `note`, `occurred_at`, `created_at`)
             SELECT I.`inventory_id`, I.`id`, $warehouseId, IFNULL(I.`sku`, ''), IF(I.`stock` > 0, 'in', 'out'),
                    IF(I.`stock` > 0, 'opening', 'adjust_out'), 'opening', ABS(I.`stock`), IFNULL(V.`cost`, 0),
                    ABS(I.`stock`) * IFNULL(V.`cost`, 0), '$note', '$now', '$now'
               FROM `$tItems` I INNER JOIN `$tInventory` V ON V.`id` = I.`inventory_id`
              WHERE V.`count_stock` = 2 AND I.`stock` <> 0
                AND NOT EXISTS (SELECT 1 FROM `$tMovement` M WHERE M.`inventory_id` = I.`inventory_id`)"
        );
    }
    // สินค้านับจำนวน — ยอดอยู่ที่ตัวสินค้า
    $db->query(
        "INSERT INTO `$tMovement`
            (`inventory_id`, `inventory_item_id`, `warehouse_id`, `sku`, `movement_direction`, `movement_type`,
             `reference_type`, `quantity`, `unit_cost`, `total_cost`, `note`, `occurred_at`, `created_at`)
         SELECT V.`id`, 0, $warehouseId, IFNULL(V.`product_code`, ''), IF(V.`stock` > 0, 'in', 'out'),
                IF(V.`stock` > 0, 'opening', 'adjust_out'), 'opening', ABS(V.`stock`), IFNULL(V.`cost`, 0),
                ABS(V.`stock`) * IFNULL(V.`cost`, 0), '$note', '$now', '$now'
           FROM `$tInventory` V
          WHERE V.`count_stock` = 1 AND V.`stock` <> 0
            AND NOT EXISTS (SELECT 1 FROM `$tMovement` M WHERE M.`inventory_id` = V.`id`)"
    );
    $rows = $db->customQuery("SELECT COUNT(*) AS `c` FROM `$tMovement` WHERE `id` > $last AND `reference_type` = 'opening' AND `note` = '$note'");
    $seeded = empty($rows) ? 0 : (int) $rows[0]->c;
    if ($seeded === 0) {
        return;
    }
    // ยอดคงเหลือของคู่ (สินค้า, หน่วยย่อย) ที่เพิ่งได้ยอดยกมา · ชั้นต้นทุนของยอดที่รับเข้า (FIFO ตัดต่อได้)
    $db->query(
        "INSERT INTO `$tBalance` (`inventory_id`, `inventory_item_id`, `warehouse_id`, `sku`, `qty`, `reserved_qty`, `updated_at`)
         SELECT `inventory_id`, `inventory_item_id`, `warehouse_id`, MAX(`sku`),
                SUM(IF(`movement_direction` = 'in', `quantity`, -`quantity`)), 0, '$now'
           FROM `$tMovement` WHERE `id` > $last AND `reference_type` = 'opening' AND `note` = '$note'
          GROUP BY `inventory_id`, `inventory_item_id`, `warehouse_id`
         ON DUPLICATE KEY UPDATE `qty` = `qty` + VALUES(`qty`), `updated_at` = VALUES(`updated_at`)"
    );
    $db->query(
        "INSERT INTO `$tLayer`
            (`inventory_id`, `inventory_item_id`, `warehouse_id`, `sku`, `movement_id`, `reference_type`,
             `received_qty`, `remaining_qty`, `unit_cost`, `received_at`, `created_at`)
         SELECT `inventory_id`, `inventory_item_id`, `warehouse_id`, `sku`, `id`, 'opening',
                `quantity`, `quantity`, IFNULL(`unit_cost`, 0), `occurred_at`, `created_at`
           FROM `$tMovement`
          WHERE `id` > $last AND `movement_direction` = 'in' AND `reference_type` = 'opening' AND `note` = '$note'"
    );
    $content[] = '<li class="correct">สมุดบัญชีสต๊อก: ย้ายยอดคงเหลือเดิมเข้าเป็นยอดยกมา '.$seeded.' รายการ</li>';
}

/**
 * เอกสารของ oas 7.0.x (`order` / `order_item`) → orders / order_items
 *
 * ใบที่มีอยู่แล้ว (id และเลขที่ตรงกัน — ตัวปรับรุ่น 7.0.3 คัดลอกหัวเอกสารมาโดยไม่มีชนิด) เติมเฉพาะชนิดที่ว่าง ·
 * ใบที่ยังไม่มีย้ายด้วย id เดิม (สมุดบัญชีอ้าง reference_id = id ของใบ · reference_item_id = id ของบรรทัด)
 * id ที่ใบอื่นใช้ไปแล้ว — ย้ายด้วย id ใหม่เมื่อสมุดบัญชีไม่ได้อ้างใบนั้น ไม่งั้นไม่ย้าย รายงานให้ตรวจ
 *
 * @param object $db
 * @param string $prefix
 * @param array  $content
 */
function inventoryConvertOrders($db, $prefix, array &$content)
{
    $tOrder = $prefix.'_order';
    $tItem = $prefix.'_order_item';
    $tOrders = $prefix.'_orders';
    $tLines = $prefix.'_order_items';
    if (!$db->tableExists($tOrder) || !$db->tableExists($tOrders) || !$db->fieldExists($tOrder, 'document_type')) {
        return;
    }
    // ชนิดเอกสารของ oas 7.0.x → ชนิดของแม่แบบปัจจุบัน · ชนิดอื่นใช้ตามเดิม (ไซต์ตั้งแม่แบบเองได้)
    $types = ['QT' => 'QUO', 'GR' => 'IN', 'RCP' => 'OUT', 'PO' => 'PO'];
    $mapType = function ($type) use ($types) {
        $type = strtoupper(trim((string) $type));

        return isset($types[$type]) ? $types[$type] : $type;
    };
    $fixed = 0;
    $moved = 0;
    $lines = 0;
    $skipped = [];
    // id ที่ใบอื่นใช้ไปแล้ว ย้ายรอบหลังด้วย id ใหม่ — ให้ทุกใบ/บรรทัดที่ยังคง id เดิมได้ ได้ id ของตัวเองก่อน
    // (ย้ายด้วย id ใหม่ระหว่างทางจะไปกิน id ของใบถัดไปที่สมุดบัญชีอ้างอยู่ แล้วใบนั้นย้ายไม่ได้)
    $deferredOrders = [];
    $deferredLines = [];
    $moveLines = function ($oldOrderId, $orderId) use ($db, $prefix, $tItem, $tLines, &$lines, &$deferredLines) {
        if (!$db->tableExists($tItem)) {
            return;
        }
        foreach ($db->customQuery("SELECT * FROM `$tItem` WHERE `order_id` = ".(int) $oldOrderId.' ORDER BY `id`', true) as $item) {
            $code = trim((string) $item['product_code']);
            // หน่วยที่ยิง : หน่วยขาย (บาร์โค้ด) หรือเครื่อง (หมายเลขเครื่อง) ของรหัสนั้น — ไม่พบใช้หน่วยย่อยเดิมตาม id
            $unit = $code === '' ? false : $db->first($prefix.'_inventory_items', ['barcode' => $code]);
            if ($unit === false && $code !== '') {
                $unit = $db->first($prefix.'_inventory_items', ['serial_no' => $code]);
            }
            if ($unit === false && !empty($item['inventory_item_id'])) {
                $unit = $db->first($prefix.'_inventory_items', ['id' => (int) $item['inventory_item_id']]);
            }
            $variant = $unit === false ? false : $db->first($prefix.'_inventory', ['id' => (int) $unit->inventory_id]);
            $quantity = (float) $item['quantity'];
            $price = (float) $item['unit_price'];
            $factor = $unit !== false && (float) $unit->cut_stock > 0 ? (float) $unit->cut_stock : 1.0;
            $gross = $price * $quantity;
            $row = [
                'order_id' => $orderId,
                'source_item_id' => $item['source_item_id'],
                'root_item_id' => $item['root_item_id'],
                'inventory_id' => $variant === false ? 0 : (int) $variant->id,
                'inventory_item_id' => $unit === false ? null : (int) $unit->id,
                'sku' => $variant === false ? '' : (string) $variant->sku,
                'code' => $code,
                'topic' => $item['name'],
                'quantity' => $quantity,
                'unit' => $item['unit'],
                'unit_factor' => $factor,
                'base_qty' => $quantity * $factor,
                'price' => $price,
                'cost_price' => $item['cost_price'],
                // ส่วนลดของรุ่นก่อนเป็นจำนวนเงิน — บรรทัดปัจจุบันเก็บเป็นร้อยละ
                'discount' => $gross > 0 ? round((float) $item['discount_amount'] * 100 / $gross, 2) : 0,
                'vat' => (float) $item['tax_amount'],
                'total' => (float) $item['subtotal']
            ];
            ++$lines;
            // id เดิมของบรรทัด — สมุดบัญชีอ้างบรรทัดด้วย reference_item_id
            if ($db->first($tLines, ['id' => (int) $item['id']]) === false) {
                $db->insert($tLines, ['id' => (int) $item['id']] + $row);
            } else {
                $deferredLines[] = $row;
            }
        }
    };
    $moveOrder = function (array $o, $keepId) use ($db, $tOrders, $mapType, $moveLines, &$moved) {
        $type = $mapType($o['document_type']);
        $subtotal = (float) $o['subtotal'];
        $discount = (float) $o['discount_amount'];
        $vat = (float) $o['tax_amount'];
        $total = (float) $o['total'];
        $issued = !empty($o['issued_at']) ? $o['issued_at'] : (!empty($o['created_at']) ? $o['created_at'] : date('Y-m-d H:i:s'));
        $header = [
            'order_no' => (string) $o['order_no'],
            'document_type' => $type,
            'status' => substr($type, 0, 3),
            'document_status' => in_array($o['document_status'], ['draft', 'issued', 'cancelled'], true) ? $o['document_status'] : 'issued',
            'payment_status' => (string) $o['payment_status'],
            'source_document_id' => $o['source_document_id'],
            'root_document_id' => $o['root_document_id'],
            'reference_document_no' => $o['reference_document_no'],
            'customer_id' => $o['customer_id'],
            'customer_name' => $o['customer_name'],
            'customer_company' => $o['customer_company'],
            'customer_contact' => $o['customer_contact'],
            'customer_phone' => $o['customer_phone'],
            'customer_email' => $o['customer_email'],
            'customer_tax_id' => $o['customer_tax_id'],
            'customer_address' => $o['customer_address'],
            'customer_province' => $o['customer_province'],
            'customer_zipcode' => $o['customer_zipcode'],
            'member_id' => $o['member_id'],
            'order_date' => $issued,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'vat' => $vat,
            // ภาษีคิดเพิ่มจากยอด (1) หรือรวมอยู่ในยอดแล้ว (2)
            'vat_status' => $vat > 0 ? (abs($total - ($subtotal - $discount + $vat)) < 0.01 ? 1 : 2) : 0,
            'tax' => 0,
            'shipping_cost' => (float) $o['shipping_cost'],
            'total' => $total,
            'paid' => (float) $o['paid_amount'],
            'change_amount' => (float) $o['change_amount'],
            'currency' => $o['currency'] === null || $o['currency'] === '' ? 'THB' : $o['currency'],
            'payment_method' => $o['payment_method'],
            'payment_ref' => $o['payment_ref'],
            'payment_date' => $o['paid_at'],
            'due_date' => $o['due_date'],
            'completed_at' => $o['completed_at'],
            'cancelled_at' => $o['cancelled_at'],
            'comment' => $o['note'],
            'internal_note' => $o['internal_note'],
            'created_at' => $o['created_at'],
            'updated_at' => $o['updated_at']
        ];
        if ($keepId) {
            $orderId = (int) $o['id'];
            $db->insert($tOrders, ['id' => $orderId] + $header);
        } else {
            $orderId = (int) $db->insert($tOrders, $header);
        }
        ++$moved;
        $moveLines((int) $o['id'], $orderId);
    };
    foreach ($db->customQuery("SELECT * FROM `$tOrder` ORDER BY `id`", true) as $o) {
        $exists = $db->first($tOrders, ['id' => (int) $o['id']]);
        if ($exists === false) {
            // ใบที่ย้ายด้วย id ใหม่ไปแล้วรอบก่อน — id เดิมกลับมาว่างได้ถ้าใบที่ชนถูกลบไป
            if ($db->first($tOrders, ['order_no' => (string) $o['order_no']]) === false) {
                $moveOrder($o, true);
            }
            continue;
        }
        if ((string) $exists->order_no === (string) $o['order_no']) {
            if ((string) $exists->document_type === '') {
                $type = $mapType($o['document_type']);
                $db->update($tOrders, ['id' => (int) $o['id']], ['document_type' => $type, 'status' => substr($type, 0, 3)]);
                ++$fixed;
            }
            continue;
        }
        // id ถูกใบอื่นใช้ไปแล้ว — ใบที่ย้ายไปแล้ว (เลขที่มีอยู่แล้ว) ข้าม · ย้ายด้วย id ใหม่ได้เมื่อสมุดบัญชีไม่ได้อ้างใบนี้
        // ไม่งั้นรายงานให้ตรวจ (id ใหม่ทำให้สมุดบัญชีชี้ผิดใบ)
        if ($db->first($tOrders, ['order_no' => (string) $o['order_no']]) !== false) {
            continue;
        }
        $ref = $db->tableExists($prefix.'_inventory_stock_movement') ? $db->customQuery(
            'SELECT COUNT(*) AS `c` FROM `'.$prefix.'_inventory_stock_movement` WHERE `reference_type` = \'order\' AND `reference_id` = '.(int) $o['id']
        ) : [];
        if (!empty($ref) && (int) $ref[0]->c > 0) {
            $skipped[] = $o['order_no'];
        } else {
            $deferredOrders[] = $o;
        }
    }
    foreach ($deferredOrders as $o) {
        $moveOrder($o, false);
    }
    foreach ($deferredLines as $row) {
        $db->insert($tLines, $row);
    }
    if ($fixed > 0) {
        $content[] = '<li class="correct">orders: เติมชนิดเอกสารที่ว่างจากเอกสารรุ่นก่อน (order) '.$fixed.' ใบ</li>';
    }
    if ($moved > 0) {
        $content[] = '<li class="correct">orders: ย้ายเอกสารรุ่นก่อน (order / order_item) '.$moved.' ใบ · '.$lines.' บรรทัด (ตารางเดิมยังอยู่ครบ)</li>';
    }
    if (!empty($skipped)) {
        $content[] = '<li class="warning">orders: เอกสารรุ่นก่อน '.count($skipped).' ใบใช้ id ซ้ำกับเอกสารอื่นที่สมุดบัญชีอ้างอยู่ ยังไม่ย้าย ('
            .htmlspecialchars(implode(', ', array_slice($skipped, 0, 10)), ENT_QUOTES).')</li>';
    }
}
