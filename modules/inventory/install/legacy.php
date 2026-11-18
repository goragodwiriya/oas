<?php
/**
 * modules/inventory/install/legacy.php — แปลงทะเบียนสินค้าแบบแบนเป็นสี่ชั้น (modules/inventory/DESIGN.md ข้อ 2–3)
 *
 * ทะเบียนแบบแบน = `inventory` หนึ่งแถวต่อสินค้า (product_code · count_stock · unit …) +
 * `inventory_items` "หน่วยย่อย" (sku · product_no · topic · cut_stock) + บรรทัดเอกสาร product_code/product_no/cut_stock
 * ของ oms.in.th รุ่นก่อน และของ oas 7.0.3 · oas ระบบเดิม (GCMS11) เตรียมให้เป็นแบบนี้ก่อนด้วย legacy-oas.php
 *
 * แกนเดียวกันทั้งสองที่ — ใช้แค่ Db ของตัวติดตั้ง (install/db.php) :
 *   oas       install/upgrade.php เรียก inventoryConvertLegacy() ตอนปรับรุ่น
 *   oms.in.th modules/inventory/install/cli-convert.php สั่งรันเองครั้งเดียว ก่อนปรับรุ่นสคีมา (cli-setup / cli-multisite)
 *
 * กติกาการแปลง (ผู้ใช้ตัดสินแบบข้อมูลไว้ใน DESIGN.md · ไม่มีการเดา ที่ตัดสินแทนไม่ได้รายงานไว้ใน review)
 *   สินค้า     inventory แถวเดิม → inventory_product id เดียวกัน · รหัส = product_code (ว่าง = product_no · ว่างอีก = P{id})
 *   แบบ       แบบแรกคือแถว inventory เดิม (id เดิม — บรรทัดเอกสาร สมุดบัญชี รูป แคตตาล็อกอะไหล่ ยังชี้ถูก)
 *             หน่วยย่อย ×1 หลายตัวของสินค้าที่ไม่นับรายชิ้น = หลายแบบ (ค่าตัวเลือก = ชื่อหน่วยย่อย · ราคาของหน่วยย่อย)
 *   หน่วยขาย   หน่วยย่อย ×1 = หน่วยขายฐานของแบบนั้น (บาร์โค้ด = barcode ว่าง = sku) · ×n = หน่วยขายของแบบแรก
 *             แบบที่ไม่มีหน่วยขายฐาน ได้หน่วยขายฐานใหม่ (บาร์โค้ด = sku ของแบบ)
 *   เครื่อง    สินค้านับรายชิ้น : หน่วยย่อยทุกตัว = เครื่อง (serial_no = sku) · หน่วยขายฐาน (บาร์โค้ดรุ่น) = sku ของแบบ
 *   บรรทัด     code = product_code (ว่าง = product_no — หน้าพิมพ์รุ่นก่อนพิมพ์ product_code จึงพิมพ์เหมือนเดิม) ·
 *             sku = sku ของแบบ · unit_factor = ตัวคูณของหน่วยขายที่อ้าง หรือ cut_stock ÷ quantity · base_qty = quantity × unit_factor
 *   แม่แบบ     in_stock/cut_stock → stock_in/stock_out · RET (ค่าตั้งต้นเดิมฝั่งซื้อ) → ใบคืนสินค้าจากลูกค้า ฝั่งขาย
 *             (oas ไม่เติม RTS — ใบคืนสินค้าไปยังผู้ขายมีเฉพาะ oms.in.th · สำเนาของ oms ยังเติมให้)
 *   คอลัมน์เดิมที่ย้ายค่าไปแล้วถูกตัดออก — สคีมาเท่ากับไซต์ติดตั้งใหม่ (ตัวเทียบสคีมาของตัวติดตั้งตรวจ)
 *
 * ⚠️ ปฏิเสธก่อนแตะอะไร (ไม่มีการแก้ครึ่งทาง) : สินค้านับจำนวนที่มีหน่วยย่อย ×1 หลายตัว และมีรายการในสมุดบัญชีแล้ว —
 * ยอดของรุ่นก่อนลงไว้ที่ระดับสินค้า แบ่งให้แต่ละแบบไม่ได้โดยไม่เดา
 * ⚠️ DDL ของ MySQL commit ทันที ย้อนไม่ได้ — ผู้เรียกต้องสำรองฐานข้อมูลก่อน (cli-convert บังคับ --confirm-backup)
 * ค่ากำหนด product_no → product_code_format ผู้เรียกย้ายเอง (ไฟล์ค่ากำหนดอยู่คนละที่ในแต่ละผลิตภัณฑ์)
 * cache (inventory.stock · cost · inventory_stock) ไม่ถูกคำนวณที่นี่ — สั่ง Inventory\Posting\Model::rebuild() หลังปรับรุ่น
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

/**
 * ฐานนี้ยังเป็นทะเบียนแบบแบนอยู่หรือไม่
 *
 * @param object $db     Db ของตัวติดตั้ง
 * @param string $prefix
 *
 * @return bool
 */
function inventoryLegacyDetect($db, $prefix)
{
    $table = $prefix.'_inventory';

    return $db->tableExists($table) && !$db->fieldExists($table, 'product_id') && $db->fieldExists($table, 'count_stock');
}

/**
 * แปลงทะเบียนสินค้าแบบแบนเป็นสี่ชั้น — ไม่ทำอะไรเลยถ้าฐานเป็นแบบใหม่แล้ว
 *
 * @param object $db
 * @param string $prefix
 * @param array  $content รายการผลลัพธ์ (HTML <li>)
 * @param bool   $dryRun  true = ตรวจและคืนแผนอย่างเดียว ไม่เขียนฐาน
 *
 * @throws \Exception เมื่อข้อมูลแปลงโดยไม่เดาไม่ได้ (ยังไม่แตะฐาน)
 *
 * @return array ['products', 'variants', 'packs', 'serials', 'lines', 'review' => [ข้อความ ...]]
 */
function inventoryConvertLegacy($db, $prefix, array &$content, $dryRun = false)
{
    $report = ['products' => 0, 'variants' => 0, 'packs' => 0, 'serials' => 0, 'lines' => 0, 'review' => []];
    if (!inventoryLegacyDetect($db, $prefix)) {
        return $report;
    }
    $t = function ($name) use ($prefix) {
        return $prefix.'_'.$name;
    };
    $has = function ($table, $field) use ($db, $t) {
        return $db->fieldExists($t($table), $field);
    };
    // คงข้อความตามที่ระบบเดิมเก็บ (ไม่ถอด HTML entity — หน้าเว็บแสดงค่าตามที่เก็บ)
    $text = function ($value) {
        return trim((string) $value);
    };

    // ---- 1. อ่านทะเบียนเดิมแล้ววางแผน (ยังไม่เขียนอะไร) ----
    $products = $db->customQuery('SELECT * FROM `'.$t('inventory').'` ORDER BY `id`', true);
    $items = [];
    if ($db->tableExists($t('inventory_items'))) {
        foreach ($db->customQuery('SELECT * FROM `'.$t('inventory_items').'` ORDER BY `id`', true) as $item) {
            $items[(int) $item['inventory_id']][] = $item;
        }
    }
    $moved = [];
    if ($db->tableExists($t('inventory_stock_movement'))) {
        foreach ($db->customQuery('SELECT DISTINCT `inventory_id` FROM `'.$t('inventory_stock_movement').'`', true) as $row) {
            $moved[(int) $row['inventory_id']] = true;
        }
    }

    $productCodes = [];
    $skus = [];
    $barcodes = [];
    $unique = function (array &$used, $code, $suffix, $label) use (&$report) {
        $code = mb_substr(trim((string) $code), 0, 150);
        if ($code === '' || isset($used[mb_strtolower($code)])) {
            $fixed = mb_substr($code === '' ? (string) $suffix : $code.'-'.$suffix, 0, 150);
            if ($code !== '') {
                $report['review'][] = $label.' "'.$code.'" ซ้ำ — ใช้ "'.$fixed.'" แทน';
            }
            $code = $fixed;
        }
        $used[mb_strtolower($code)] = true;

        return $code;
    };
    $plan = [];
    $problems = [];
    foreach ($products as $p) {
        $id = (int) $p['id'];
        $countStock = (int) $p['count_stock'];
        $code = $text($p['product_code']) !== '' ? $text($p['product_code']) : $text(isset($p['product_no']) ? $p['product_no'] : '');
        $code = $unique($productCodes, $code === '' ? 'P'.sprintf('%04d', $id) : $code, $id, 'รหัสสินค้า');
        $own = isset($items[$id]) ? $items[$id] : [];
        $singles = [];
        $packs = [];
        foreach ($own as $item) {
            if ($countStock === 2 || (float) $item['cut_stock'] <= 1.00001) {
                $singles[] = $item;
            } else {
                $packs[] = $item;
            }
        }
        $entry = ['product' => $p, 'code' => $code, 'variants' => []];
        $itemSku = function ($item) use ($text) {
            $sku = $text($item['sku']);

            return $sku !== '' ? $sku : $text(isset($item['product_no']) ? $item['product_no'] : '');
        };
        if ($countStock === 2 || count($singles) <= 1) {
            // แบบเดียว — สินค้านับรายชิ้น : หน่วยย่อยทุกตัวคือเครื่อง
            $single = $countStock !== 2 && !empty($singles) ? $singles[0] : null;
            // ชื่อหน่วยย่อยเดิมต่อท้ายชื่อสินค้าบนเอกสาร (โปรเติบโต · รายปี) = ค่าตัวเลือกของแบบ ชื่อเต็มของแบบจึงเท่าเดิม
            // ชื่อที่ซ้ำกับชื่อสินค้าอยู่แล้วไม่ต่อซ้ำ
            $option = $single !== null ? $text($single['topic']) : '';
            if ($option !== '' && mb_strpos($text($p['topic']), $option) !== false) {
                $option = '';
            }
            if ($option !== '') {
                $report['review'][] = 'สินค้า '.$code.' : ชื่อหน่วยย่อยเดิม "'.$option.'" เป็นค่าตัวเลือกของแบบ — ตั้งชื่อตัวเลือกที่หน้าสินค้า';
            }
            $entry['variants'][] = [
                'id' => $id,
                'sku' => $single !== null && $itemSku($single) !== '' ? $itemSku($single) : $code,
                'option1' => $option,
                'price' => $single !== null && $single['price'] !== null ? (float) $single['price'] : (float) $p['price'],
                'base' => $single,
                'packs' => $packs,
                'serials' => $countStock === 2 ? $own : []
            ];
        } else {
            // หน่วยย่อย ×1 หลายตัว = แบบของสินค้า (โดเมน .com .net …) — ยอดเดิมระดับสินค้าแบ่งไม่ได้ถ้าเคยเดินสต๊อก
            if ($countStock === 1 && isset($moved[$id])) {
                $problems[] = 'สินค้า '.$code.' ('.$text($p['topic']).') นับสต๊อกและมีหน่วยย่อยราคาเดียว '.count($singles)
                    .' ตัวที่จะกลายเป็นแบบ แต่มีรายการในสมุดบัญชีแล้ว — ยอดเดิมอยู่ระดับสินค้า แบ่งให้แต่ละแบบไม่ได้';
            }
            foreach ($singles as $n => $single) {
                $entry['variants'][] = [
                    'id' => $n === 0 ? $id : 0,
                    'sku' => $itemSku($single) !== '' ? $itemSku($single) : $code.'-'.($n + 1),
                    'option1' => $text($single['topic']) !== '' ? $text($single['topic']) : $itemSku($single),
                    'price' => $single['price'] !== null ? (float) $single['price'] : (float) $p['price'],
                    'base' => $single,
                    'packs' => $n === 0 ? $packs : [],
                    'serials' => []
                ];
            }
            $report['review'][] = 'สินค้า '.$code.' ได้ '.count($singles).' แบบจากหน่วยย่อยเดิม — ตั้งชื่อตัวเลือกที่หน้าสินค้า';
            if (!empty($packs)) {
                $report['review'][] = 'สินค้า '.$code.' : หน่วยขายที่มีตัวคูณผูกกับแบบแรก ('.$entry['variants'][0]['sku'].') — ตรวจที่หน้าหน่วยขาย';
            }
        }
        // ค่าตัวเลือกซ้ำในสินค้าเดียว = ของชิ้นเดียวกัน แยกเป็นสองแบบไม่ได้ (ดัชนี UNIQUE options)
        $optionsUsed = [];
        foreach ($entry['variants'] as $i => $variant) {
            $key = mb_strtolower($variant['option1']);
            if (isset($optionsUsed[$key])) {
                $entry['variants'][$i]['option1'] = mb_substr($variant['option1'].' ('.$variant['sku'].')', 0, 100);
            }
            $optionsUsed[mb_strtolower($entry['variants'][$i]['option1'])] = true;
            $entry['variants'][$i]['sku'] = $unique($skus, $entry['variants'][$i]['sku'], $id.'-'.($i + 1), 'SKU');
        }
        $plan[] = $entry;
    }
    if (!empty($problems)) {
        throw new \Exception("แปลงทะเบียนสินค้าไม่ได้ (ยังไม่แก้อะไรในฐาน):\n- ".implode("\n- ", $problems));
    }
    foreach ($plan as $entry) {
        ++$report['products'];
        $report['variants'] += count($entry['variants']);
        foreach ($entry['variants'] as $variant) {
            $report['packs'] += 1 + count($variant['packs']);
            $report['serials'] += count($variant['serials']);
        }
    }
    if ($db->tableExists($t('order_items'))) {
        $rows = $db->customQuery('SELECT COUNT(*) AS `c` FROM `'.$t('order_items').'`', true);
        $report['lines'] = (int) $rows[0]['c'];
    }
    if ($dryRun) {
        return $report;
    }

    // ---- 2. โครงสร้างที่ต้องมีก่อนย้ายค่า (ตัวปรับรุ่นสคีมาเติมส่วนที่เหลือทีหลัง) ----
    $commands = sqlCommands(ROOT_PATH.'modules/inventory/install/database.sql', $prefix);
    if (!$db->tableExists($t('inventory_product'))) {
        foreach ($commands as $command) {
            if (preg_match('/^\s*CREATE TABLE `'.preg_quote($t('inventory_product'), '/').'`/i', $command)) {
                $db->query($command);
            }
        }
    }
    $add = function ($table, $field, $definition) use ($db, $t, $has) {
        if (!$has($table, $field)) {
            $db->query('ALTER TABLE `'.$t($table).'` ADD `'.$field.'` '.$definition);
        }
    };
    $add('inventory', 'product_id', 'int(11) unsigned NOT NULL DEFAULT 0 AFTER `id`');
    $add('inventory', 'sku', 'varchar(150) DEFAULT NULL AFTER `product_id`');
    foreach (['option1', 'option2', 'option3'] as $_col) {
        $add('inventory', $_col, "varchar(100) NOT NULL DEFAULT ''");
    }
    $add('inventory', 'sort_order', 'int(11) NOT NULL DEFAULT 0');
    $add('inventory_items', 'serial_no', 'varchar(150) DEFAULT NULL AFTER `barcode`');
    $add('inventory_items', 'is_active', 'tinyint(1) NOT NULL DEFAULT 1');
    $add('inventory_items', 'created_at', 'datetime DEFAULT NULL');
    // หน่วยย่อยเดิมบังคับ sku ไม่ว่าง — แถวหน่วยขายใหม่ไม่มีคอลัมน์นี้ (ถูกตัดท้ายสุด)
    foreach (['sku', 'product_no'] as $_col) {
        if ($has('inventory_items', $_col)) {
            $db->query('ALTER TABLE `'.$t('inventory_items').'` MODIFY `'.$_col.'` varchar(150) DEFAULT NULL');
        }
    }
    $add('inventory_meta', 'product_id', 'int(11) unsigned NOT NULL DEFAULT 0 FIRST');
    $add('order_items', 'sku', "varchar(150) NOT NULL DEFAULT ''");
    $add('order_items', 'code', "varchar(150) NOT NULL DEFAULT ''");
    $add('order_items', 'lot_no', 'varchar(50) DEFAULT NULL');
    $add('order_items', 'expiry_date', 'date DEFAULT NULL');
    $add('order_items', 'unit_factor', 'decimal(16,4) NOT NULL DEFAULT 1.0000');
    $add('order_items', 'base_qty', 'decimal(16,4) NOT NULL DEFAULT 0.0000');

    // ---- 3. สินค้า · แบบ · หน่วยขาย · เครื่อง ----
    $now = date('Y-m-d H:i:s');
    $itemOwner = [];
    foreach ($plan as $entry) {
        $p = $entry['product'];
        $productId = (int) $p['id'];
        $created = !empty($p['created_at']) ? $p['created_at'] : (!empty($p['last_update']) ? date('Y-m-d H:i:s', (int) $p['last_update']) : $now);
        $active = isset($p['is_active']) ? (int) $p['is_active'] : (isset($p['inuse']) ? (int) $p['inuse'] : 1);
        $db->insert($t('inventory_product'), [
            'id' => $productId,
            'product_code' => $entry['code'],
            'topic' => $text($p['topic']),
            'description' => isset($p['description']) ? (string) $p['description'] : null,
            'category_id' => isset($p['category_id']) && $p['category_id'] !== '' ? (string) $p['category_id'] : null,
            'model_id' => isset($p['model_id']) && $p['model_id'] !== '' ? (string) $p['model_id'] : null,
            'type_id' => isset($p['type_id']) && $p['type_id'] !== '' ? (string) $p['type_id'] : null,
            'count_stock' => (int) $p['count_stock'],
            'unit' => isset($p['unit']) ? mb_substr($text($p['unit']), 0, 50) : '',
            'vat' => isset($p['vat']) && (float) $p['vat'] > 0 ? 1 : 0,
            'allow_negative' => isset($p['allow_negative']) ? (int) $p['allow_negative'] : 0,
            'is_active' => $active ? 1 : 0,
            // หน้าร้านรุ่นก่อนแสดงทุกสินค้าที่ใช้งานอยู่ — คงไว้
            'published' => $active ? 1 : 0,
            'created_at' => $created,
            'updated_at' => !empty($p['updated_at']) ? $p['updated_at'] : $created
        ]);
        foreach ($entry['variants'] as $sort => $variant) {
            $options = trim($variant['option1']);
            $topic = mb_substr(trim($text($p['topic']).($options === '' ? '' : ' '.$options)), 0, 255);
            $row = [
                'product_id' => $productId,
                'sku' => $variant['sku'],
                'option1' => mb_substr($options, 0, 100),
                'topic' => $topic,
                'price' => $variant['price'],
                'is_active' => $active ? 1 : 0,
                'sort_order' => $sort
            ];
            if ($variant['id'] > 0) {
                $variantId = $variant['id'];
                $db->update($t('inventory'), ['id' => $variantId], $row);
            } else {
                $variantId = (int) $db->insert($t('inventory'), $row + [
                    'cost' => (float) $p['cost'], 'stock' => 0, 'created_at' => $created, 'updated_at' => $now
                ]);
            }
            // หน่วยขายฐาน — หน่วยย่อย ×1 เดิม (id เดิม บรรทัดเอกสารที่อ้างยังชี้ถูก) หรือแถวใหม่
            $unit = isset($p['unit']) ? mb_substr($text($p['unit']), 0, 50) : '';
            if ($variant['base'] !== null && (int) $p['count_stock'] !== 2) {
                $base = $variant['base'];
                $barcode = $text($base['barcode']) !== '' ? $text($base['barcode']) : $variant['sku'];
                $db->update($t('inventory_items'), ['id' => (int) $base['id']], [
                    'inventory_id' => $variantId,
                    'barcode' => $unique($barcodes, $barcode, $base['id'], 'บาร์โค้ด'),
                    'serial_no' => null,
                    'unit' => $text($base['unit']) !== '' ? mb_substr($text($base['unit']), 0, 50) : $unit,
                    'cut_stock' => 1,
                    // ราคาของหน่วยย่อยย้ายไปเป็นราคาของแบบแล้ว
                    'price' => null,
                    'stock' => 0,
                    'is_active' => 1
                ]);
                $itemOwner[(int) $base['id']] = $variantId;
            } else {
                $db->insert($t('inventory_items'), [
                    'inventory_id' => $variantId,
                    'barcode' => $unique($barcodes, $variant['sku'], $variantId.'-1', 'บาร์โค้ด'),
                    'serial_no' => null,
                    'unit' => $unit,
                    'cut_stock' => 1,
                    'price' => null,
                    'stock' => 0,
                    'is_active' => 1,
                    'created_at' => $now
                ]);
            }
            foreach ($variant['packs'] as $pack) {
                $barcode = $text($pack['barcode']) !== '' ? $text($pack['barcode']) : $text($pack['sku']);
                $db->update($t('inventory_items'), ['id' => (int) $pack['id']], [
                    'inventory_id' => $variantId,
                    'barcode' => $unique($barcodes, $barcode, $pack['id'], 'บาร์โค้ด'),
                    'serial_no' => null,
                    'unit' => $text($pack['unit']) !== '' ? mb_substr($text($pack['unit']), 0, 50) : $unit,
                    'stock' => 0,
                    'is_active' => 1
                ]);
                $itemOwner[(int) $pack['id']] = $variantId;
            }
            foreach ($variant['serials'] as $piece) {
                $serial = $text($piece['sku']) !== '' ? $text($piece['sku']) : $text(isset($piece['product_no']) ? $piece['product_no'] : '');
                $db->update($t('inventory_items'), ['id' => (int) $piece['id']], [
                    'inventory_id' => $variantId,
                    'barcode' => null,
                    'serial_no' => $unique($barcodes, $serial, $piece['id'], 'หมายเลขเครื่อง'),
                    'unit' => $unit,
                    'cut_stock' => 1,
                    'price' => null,
                    'is_active' => 1
                ]);
                $itemOwner[(int) $piece['id']] = $variantId;
            }
        }
    }
    // หน่วยย่อยที่ไม่มีสินค้าแม่ (ข้อมูลเสีย) — คงไว้ ปิดการใช้งาน รายงานให้ตรวจ
    foreach ($items as $inventoryId => $own) {
        foreach ($own as $item) {
            if (!isset($itemOwner[(int) $item['id']]) && !in_array($inventoryId, array_column($products, 'id'))) {
                $db->update($t('inventory_items'), ['id' => (int) $item['id']], [
                    'barcode' => $unique($barcodes, $text($item['sku']), $item['id'], 'บาร์โค้ด'), 'is_active' => 0
                ]);
                $report['review'][] = 'หน่วยย่อย '.$text($item['sku']).' ไม่มีสินค้าแม่ (inventory_id '.$inventoryId.') — ปิดการใช้งาน';
            }
        }
    }

    // บรรทัดเอกสาร สมุดบัญชี ยอด ชั้นต้นทุน ที่อ้างหน่วยย่อยซึ่งกลายเป็นแบบใหม่ ย้ายตามแบบนั้น
    foreach ($itemOwner as $itemId => $variantId) {
        foreach (['order_items', 'inventory_stock_movement', 'inventory_stock', 'inventory_cost_layer', 'inventory_cost_allocation'] as $_table) {
            if ($db->tableExists($t($_table)) && $has($_table, 'inventory_item_id')) {
                $db->query('UPDATE `'.$t($_table).'` SET `inventory_id` = '.(int) $variantId
                    .' WHERE `inventory_item_id` = '.(int) $itemId.' AND `inventory_id` <> '.(int) $variantId);
            }
        }
    }

    // ---- 4. รายละเอียดเพิ่มเติม : ผูกกับสินค้า (id ของสินค้า = id ของแถว inventory เดิม) ----
    if ($has('inventory_meta', 'inventory_id')) {
        $db->query('UPDATE `'.$t('inventory_meta').'` SET `product_id` = `inventory_id`');
    }

    // ---- 5. บรรทัดเอกสาร ----
    if ($db->tableExists($t('order_items')) && $has('order_items', 'cut_stock')) {
        $codeExpr = $has('order_items', 'product_no')
            ? "COALESCE(NULLIF(T.`product_code`, ''), NULLIF(T.`product_no`, ''), '')"
            : "COALESCE(NULLIF(T.`product_code`, ''), '')";
        $db->query('UPDATE `'.$t('order_items').'` T'
            .' LEFT JOIN `'.$t('inventory_items').'` I ON I.`id` = T.`inventory_item_id`'
            .' LEFT JOIN `'.$t('inventory').'` V ON V.`id` = T.`inventory_id`'
            .' SET T.`code` = '.$codeExpr.','
            .' T.`sku` = COALESCE(V.`sku`, '.$codeExpr.'),'
            // ตัวคูณ : หน่วยขายที่อ้าง (มีตัวคูณ) · ไม่งั้นจำนวนที่ตัดต่อจำนวนที่สั่ง (บรรทัดที่ตัดสต๊อกของรุ่นก่อน) · ไม่งั้น 1
            .' T.`unit_factor` = CASE WHEN I.`id` IS NOT NULL AND I.`serial_no` IS NULL AND I.`cut_stock` > 1 THEN I.`cut_stock`'
            .' WHEN T.`cut_stock` > 0 AND T.`quantity` > 0 THEN ROUND(T.`cut_stock` / T.`quantity`, 4) ELSE 1 END');
        $db->query('UPDATE `'.$t('order_items').'` SET `base_qty` = ROUND(`quantity` * `unit_factor`, 4)');
        // บรรทัดที่ไม่ใช่สินค้า (หักมัดจำ · ค่าบริการพิมพ์เอง) ไม่มีหน่วยขายที่อ้าง
        $db->query('UPDATE `'.$t('order_items').'` SET `inventory_item_id` = NULL WHERE `inventory_item_id` = 0');
    }

    // ---- 6. แม่แบบเอกสาร ----
    $template = $t('inventory_template');
    if ($db->tableExists($template)) {
        foreach (['in_stock' => 'stock_in', 'cut_stock' => 'stock_out'] as $_old => $_new) {
            if ($db->fieldExists($template, $_old) && !$db->fieldExists($template, $_new)) {
                $db->query('ALTER TABLE `'.$template.'` CHANGE `'.$_old.'` `'.$_new.'` tinyint(1) NOT NULL DEFAULT 0');
                $content[] = '<li class="correct">'.$template.': '.$_old.' → '.$_new.'</li>';
            }
        }
        // RET ค่าตั้งต้นเดิมอยู่ฝั่งซื้อทั้งที่เป็นการรับคืนจากลูกค้า (ผู้ใช้ตัดสิน 2026-10-23 · DESIGN.md ข้อ 5.4)
        $ret = $db->first($template, ['document_type' => 'RET']);
        if ($ret && $ret->mode === 'buy' && $ret->movement_type === 'return_in') {
            $db->update($template, ['id' => (int) $ret->id], ['mode' => 'sell']
                + ($ret->topic === 'ใบคืนสินค้า' ? ['topic' => 'ใบคืนสินค้าจากลูกค้า'] : []));
            $content[] = '<li class="correct">'.$template.': RET เป็นใบคืนสินค้าจากลูกค้า (ฝั่งขาย)</li>';
        }
    }

    // ---- 7. ตัดคอลัมน์เดิมที่ย้ายค่าไปแล้ว ----
    $drop = function ($table, array $fields) use ($db, $t) {
        $parts = [];
        foreach ($fields as $field) {
            if ($db->fieldExists($t($table), $field)) {
                $parts[] = 'DROP COLUMN `'.$field.'`';
            }
        }
        if (!empty($parts)) {
            $db->query('ALTER TABLE `'.$t($table).'` '.implode(', ', $parts));
        }
    };
    $drop('inventory', ['product_code', 'product_no', 'description', 'last_update', 'vat', 'unit', 'category_id',
        'model_id', 'type_id', 'count_stock', 'stockable', 'allow_negative', 'inuse']);
    $drop('inventory_items', ['sku', 'product_no', 'topic', 'instock', 'url', 'last_update']);
    $drop('inventory_meta', ['inventory_id']);
    $drop('order_items', ['product_code', 'product_no', 'cut_stock']);

    $content[] = '<li class="correct">inventory: แปลงทะเบียนสินค้า '.$report['products'].' รายการ → แบบ '.$report['variants']
        .' · หน่วยขาย '.$report['packs'].' · เครื่อง '.$report['serials'].' · บรรทัดเอกสาร '.$report['lines'].'</li>';
    // สิ่งที่ผู้ดูแลต้องตัดสินเองหลังแปลง (ตั้งชื่อตัวเลือก) — คำเตือน ไม่ใช่ความล้มเหลว
    foreach ($report['review'] as $line) {
        $content[] = '<li class="warning">inventory: '.htmlspecialchars($line, ENT_QUOTES, 'UTF-8').'</li>';
    }

    return $report;
}
