<?php
/**
 * @filesource modules/export/controllers/export.php
 *
 * บริการกลางของการพิมพ์เอกสารและส่งออกไฟล์ ใช้ร่วมกันได้ทุกโมดูล
 *
 * โมดูลที่จะพิมพ์เอกสารเป็นผู้เตรียม "เนื้อใบ" (HTML ของแต่ละหน้ากระดาษ) เอง
 * ส่วนที่นี่ดูแลของที่เหมือนกันทุกโมดูล :
 *   - ตารางขนาดกระดาษ (A4 / A5 / กระดาษม้วน 80-58 มม. สำหรับเครื่องพิมพ์ใบเสร็จ)
 *   - หน้าเว็บสำหรับพิมพ์ ฟอนต์ และ @page
 *   - แถบตัวเลือกตอนพิมพ์ (ขนาดกระดาษ / จำนวนรายการต่อหน้า)
 *   - ตัวเลือก "ไม่พิมพ์ตราประทับ/ลายเซ็น/วันที่" (ทำงานด้วย CSS ล้วน)
 *   - แปลงเป็น PDF ด้วยเบราว์เซอร์บนเครื่องเซิร์ฟเวอร์
 *   - ส่งออก CSV
 *
 * ผังใบเอกสารอยู่ที่ views/sheet.html (A4/A5) และ views/slip.html (กระดาษม้วน)
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Export\Export;

use Kotchasan\Http\Response;
use Kotchasan\Language;

/**
 * บริการพิมพ์/ส่งออกกลาง
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Gcms\Api
{
    /**
     * โฟลเดอร์แม่แบบของบริการนี้
     *
     * @return string
     */
    public static function viewDir()
    {
        return ROOT_PATH.'modules/export/views/';
    }

    /**
     * ขนาดกระดาษที่พิมพ์ได้ พร้อมค่าเริ่มต้นของจำนวนรายการต่อหน้า
     *
     * ⚠️ ความสูงของใบต่ำกว่าหน้ากระดาษ 1 มม. เพราะการปัดเศษ mm→px ของเบราว์เซอร์
     * ทำให้ใบที่สูงเท่าหน้ากระดาษเป๊ะ ๆ ล้นไปอีกหน้าเสมอ (ได้กระดาษเปล่าท้ายเอกสาร)
     *
     * ⚠️ `size: 80mm auto` เป็นไวยากรณ์ที่ไม่ถูกต้อง (auto ใช้เดี่ยว ๆ เท่านั้น)
     * เบราว์เซอร์จึงทิ้งทั้งกฎแล้วกลับไปใช้ Letter กระดาษม้วนต้องระบุความสูง
     * ความสูงคำนวณจากเนื้อหาจริงผ่าน rollLength()
     *
     * @return array
     */
    public static function papers()
    {
        return [
            'a4' => ['page' => '210mm 297mm', 'w' => '210mm', 'h' => '296mm', 'pad' => '11mm 10mm',
                'per_page' => 14, 'slip' => false, 'name' => 'A4  210 x 297 {LNG_mm}']
        ];
    }

    /**
     * ขนาดกระดาษที่เลือกไว้ (ตรวจค่าที่ส่งมาแล้ว)
     *
     * @param string $paper
     *
     * @return array
     */
    public static function paper($paper)
    {
        $papers = self::papers();

        return isset($papers[$paper]) ? $papers[$paper] : $papers['a4'];
    }

    /**
     * ความยาวกระดาษม้วนที่พอดีกับเนื้อหา (มิลลิเมตร)
     *
     * ชื่อสินค้ายาวจะตัดหลายบรรทัดบนกระดาษที่กว้างแค่ 54-74 มม. จึงนับบรรทัดจาก
     * ความยาวข้อความจริง แล้วบวกอีกหนึ่งบรรทัดของจำนวน x ราคา ต่อหนึ่งรายการ
     *
     * @param array $sizing  ขนาดกระดาษที่เลือก
     * @param array $texts   ข้อความของแต่ละรายการ
     *
     * @return int
     */
    public static function rollLength(array $sizing, array $texts)
    {
        if (empty($sizing['slip'])) {
            return 0;
        }

        $lines = 0;
        foreach ($texts as $text) {
            $chars = mb_strlen((string) $text, 'UTF-8');
            $lines += max(1, (int) ceil($chars / $sizing['chars'])) + 1;
        }

        return min(2000, max(80, $sizing['base'] + (int) ceil($lines * $sizing['line'])));
    }

    /**
     * ช่องติ๊ก "ไม่พิมพ์..." ที่ต้องอยู่ต้นหน้า
     *
     * ⚠️ ต้องมีชุดเดียวทั้งหน้า (id ซ้ำกันไม่ได้) และต้องอยู่ก่อนทุกใบ เพราะกฎ CSS
     * เลือกด้วยตัวเชื่อมพี่น้องที่ตามหลัง (~)
     *
     * @return string
     */
    public static function printToggles()
    {
        return '<input type="checkbox" id="no_stamp" class="print-option">'
            .'<input type="checkbox" id="no_signature" class="print-option">'
            .'<input type="checkbox" id="no_date" class="print-option">';
    }

    /**
     * แถบตัวเลือกตอนพิมพ์ (ขนาดกระดาษ จำนวนรายการต่อหน้า และตัวเลือกไม่พิมพ์...)
     *
     * ⚠️ ไม่มีปุ่มตกลง เปลี่ยนตัวเลือกแล้วมีผลทันที (this.form.submit()) ใช้ onchange
     * ในแท็กแทน <script> หน้าพิมพ์จึงยังไม่มีสคริปต์ของตัวเอง และตัวเลือก
     * "ไม่พิมพ์..." ยังทำงานด้วย CSS ล้วนเหมือนเดิม
     *
     * @param array  $target  พารามิเตอร์ที่พากลับมาหน้าเดิม (module, typ, id/ids/order)
     * @param string $paper   ขนาดกระดาษที่เลือกอยู่
     * @param int    $perPage จำนวนรายการต่อหน้าที่เลือกอยู่
     *
     * @return string
     */
    public static function printControls(array $target, $paper, $perPage)
    {
        $options = '';
        foreach (self::papers() as $key => $one) {
            $options .= '<option value="'.$key.'"'.($key === $paper ? ' selected' : '').'>'
                .$one['name'].'</option>';
        }

        // จำนวนรายการต่อหน้าเป็นตัวเลือกสำเร็จรูป ไม่ใช่ช่องกรอกตัวเลข ผู้ใช้จะได้
        // ไม่ต้องเดาว่าควรใส่เท่าไหร่ ค่าที่ใช้อยู่ถูกใส่เข้าไปในรายการเสมอ
        // แม้จะไม่ใช่ค่ามาตรฐาน (เช่นมาจากลิงก์เก่า)
        $steps = [0, 5, 8, 10, 12, 14, 16, 20, 25, 30, 40];
        if (!in_array((int) $perPage, $steps, true)) {
            $steps[] = (int) $perPage;
            sort($steps);
        }
        $perPageOptions = '';
        foreach ($steps as $step) {
            $perPageOptions .= '<option value="'.$step.'"'.($step === (int) $perPage ? ' selected' : '').'>'
                .($step === 0 ? '{LNG_Do not split pages}' : $step).'</option>';
        }

        $hidden = '';
        foreach ($target as $name => $value) {
            $hidden .= '<input type="hidden" name="'.htmlspecialchars($name, ENT_QUOTES, 'UTF-8')
            .'" value="'.htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8').'">';
        }

        return '<div class="print-bar noprint">'
            .'<form method="get" action="export.php">'
            .$hidden
            .'<label>{LNG_Paper size}'
            .'<select name="paper" onchange="this.form.submit()">'.$options.'</select></label>'
            .'<label>{LNG_Items per page}'
            .'<select name="per_page" onchange="this.form.submit()">'.$perPageOptions.'</select></label>'
            .'<noscript><button type="submit">{LNG_Apply}</button></noscript>'
            .'<span class="print-hint">{LNG_If the content overflows the page, lower the number of items per page.}</span>'
            .'</form>'
            .'<div class="print-toggles">'
            .'<label for="no_stamp">{LNG_Do not print the stamp}</label>'
            .'<label for="no_signature">{LNG_Do not print the signature}</label>'
            .'<label for="no_date">{LNG_Do not print the date}</label>'
            .'</div>'
            .'<div><button type="button" class="print-button noprint" onclick="window.print()">{LNG_Print}</button></div>'
            .'</div>';
    }

    /**
     * ห่อเนื้อหาด้วยหน้าเว็บสำหรับพิมพ์
     *
     * @param string $title
     * @param string $content เนื้อหาทั้งหมดที่จะอยู่ใน <body>
     * @param array  $options {
     *   body_class:  string  คลาสของ <body>
     *   page_style:  string  CSS ของ @page และตัวแปรขนาดกระดาษ
     *   stylesheets: array   ไฟล์ CSS เพิ่มเติมของโมดูลผู้เรียก (path จาก WEB_URL)
     * }
     *
     * @return Response
     */
    public static function printHtml($title, $content, array $options = [])
    {
        $links = '';
        foreach ((array) ($options['stylesheets'] ?? []) as $href) {
            $links .= '<link rel="stylesheet" href="'.WEB_URL.$href.'">';
        }

        $wrapper = file_get_contents(self::viewDir().'print.html');
        $html = strtr($wrapper, [
            '{LANGUAGE}' => Language::name(),
            '{TITLE}' => htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
            '{BODYCLASS}' => (string) ($options['body_class'] ?? 'billing'),
            '{WEBURL}' => WEB_URL,
            '{STYLESHEETS}' => $links,
            '{PAGESTYLE}' => (string) ($options['page_style'] ?? ''),
            '{CONTENT}' => $content
        ]);

        $response = new Response();

        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8')
            ->withContent(Language::trans($html));
    }

    /**
     * CSS ของขนาดกระดาษที่เลือก
     *
     * @param array $sizing
     *
     * @return string
     */
    public static function pageStyle(array $sizing)
    {
        return '<style>@page { size: '.$sizing['page'].'; margin: 0; }'
            .':root { --sheet-w: '.$sizing['w'].'; --sheet-h: '.$sizing['h']
            .'; --sheet-pad: '.$sizing['pad'].'; }</style>';
    }

    /**
     * หน้าพิมพ์ที่เปิดกล่องพิมพ์ให้เอง สำหรับเครื่องที่สร้าง PDF ฝั่งเซิร์ฟเวอร์ไม่ได้
     *
     * @param Response $page
     *
     * @return Response
     */
    public static function printDialogFallback(Response $page)
    {
        $html = (string) $page->getBody();
        $notice = '<div class="print-bar noprint print-notice">'
        .Language::get('This server cannot create the PDF itself. The print dialog is opening : choose "Save as PDF" as the destination.')
            .'</div>';
        // แทรกคำอธิบายไว้บนสุดของเนื้อหา แล้วสั่งเปิดกล่องพิมพ์หลังหน้าโหลดเสร็จ
        // (รอฟอนต์โหลดก่อน ไม่งั้นกล่องพิมพ์เปิดตอนตัวอักษรยังไม่มา)
        $html = preg_replace('/(<body[^>]*>)/i', '$1'.$notice, $html, 1);
        $script = '<script>document.fonts && document.fonts.ready'
            .' ? document.fonts.ready.then(function () { window.print(); })'
            .' : window.print();</script>';
        $html = str_replace('</body>', $script.'</body>', $html);

        $response = new Response();

        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8')
            ->withContent($html);
    }

    /**
     * ลบโฟลเดอร์ชั่วคราวพร้อมไฟล์ข้างใน
     *
     * @param string $dir
     */
    protected static function removeTree($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    /**
     * ส่งไฟล์ CSV ให้ดาวน์โหลด (ส่ง header แล้วจบการทำงาน)
     *
     * ⚠️ ห้ามตั้งชื่อว่า csv() เพราะโมดูลที่สืบทอดไปใช้ชื่อเมธอดเป็น "ชนิดการส่งออก"
     * (export.php?typ=csv) ซึ่งเป็นเมธอดของอินสแตนซ์ ชนกับเมธอดสถิตของที่นี่
     *
     * @param string   $filename ชื่อไฟล์ (ไม่ต้องมีนามสกุล)
     * @param string[] $headers  หัวตาราง
     * @param array[]  $rows     ข้อมูล
     * @param string   $charset  ชุดอักขระของไฟล์ (UTF-8 หรือ TIS-620)
     * @param bool     $bom      ใส่ BOM นำหน้าไฟล์หรือไม่ (UTF-8 เท่านั้น)
     */
    public static function sendCsv($filename, array $headers, array $rows, $charset = 'UTF-8', $bom = true)
    {
        \Kotchasan\Csv::send($filename, $headers, $rows, $charset, $bom);
        exit;
    }
}
