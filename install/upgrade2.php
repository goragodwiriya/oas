<?php
/**
 * install/upgrade2.php — ปรับรุ่นฐานข้อมูลของโปรเจ็คนี้
 *
 * ส่วนที่เป็นของ Gcms (user, category, logs, login_attempt, number, migration,
 * user_meta, user_session, language, timeline_idempotency) อยู่ใน
 * install/upgrade_core.php ซึ่งเหมือนกันทุกโปรเจ็ค ไฟล์นี้เก็บเฉพาะตารางของ
 * โปรเจ็คนี้ — adminframework เป็นโครงตั้งต้นจึงยังไม่มีตารางของตัวเอง
 * โปรเจ็คลูกที่คัดลอกไปใช้ ให้เพิ่มบล็อกของตารางตัวเองต่อจาก include ของ
 * upgrade_core.php (จุดที่เขียนว่า "ตารางของโปรเจ็คนี้")
 *
 * ลำดับการทำงาน (ตามข้อกำหนดของตัวอัปเกรดที่ต้องเอาตัวรอดเองที่ไซต์ปลายทาง)
 *   1. ยืนยันว่าสำรองฐานข้อมูลแล้ว          — ไม่ยืนยัน ไม่เริ่ม
 *   2. preflight ตรวจก่อนแตะ                — ไม่ผ่าน หยุดโดยยังไม่แก้อะไรเลย
 *   3. นับจำนวนแถวจริงทุกตารางก่อนเริ่ม
 *   4. ปรับรุ่นตารางแกน แล้วตามด้วยตารางของโปรเจ็คนี้
 *   5. นับใหม่แล้วแสดงตารางเทียบก่อน/หลังให้ผู้ใช้เห็นเอง
 *   6. บันทึกรุ่นลง {prefix}_migration และเขียน log ลง datas/logs/
 *
 * กฎของทุกเงื่อนไขในไฟล์นี้ — ต้องถามว่า "ต้องแก้ไหม" ไม่ใช่ "ตอนนี้เป็นอะไร"
 * เงื่อนไขแบบหลัง (เช่น if (isColumnType($t, 'comment', 'text'))) ยังเป็นจริง
 * หลังแก้เสร็จ ตารางจึงถูก ALTER ซ้ำทุกครั้งที่ปรับรุ่นโดยไม่ได้เปลี่ยนอะไรเลย
 */
if (defined('ROOT_PATH')) {
    if (empty($_POST['username']) || empty($_POST['password'])) {
        include ROOT_PATH.'install/upgrade1.php';
    } elseif (empty($_POST['confirm_backup'])) {
        // เรากู้ข้อมูลให้ไซต์ที่เรามองไม่เห็นไม่ได้ ผู้ดูแลไซต์ต้องมีสำเนาของตัวเอง
        // ก่อนเสมอ และต้องเป็นการกดยืนยันด้วยมือ ไม่ใช่ค่าเริ่มต้นที่ติ๊กมาให้แล้ว
        adminForm(2, true, 'กรุณายืนยันว่าคุณสำรองฐานข้อมูลไว้แล้ว ก่อนเริ่มการปรับรุ่น');
    } else {
        $error = false;
        // Database Class
        include ROOT_PATH.'install/db.php';
        // เครื่องมือตรวจก่อนแตะ + รายงานผล (เหมือนกันทุกโปรเจ็ค)
        include_once ROOT_PATH.'install/preflight.php';
        // ค่าติดตั้งฐานข้อมูล
        //
        // ปกติอ่านจาก settings/database.php ของไซต์ ยกเว้นเมื่อถูกเรียกจาก
        // install/cli-upgrade.php --db= ซึ่งชี้ฐานทดสอบมาให้แล้ว — ชุดทดสอบ
        // F1-F7 ต้องรัน "ตัวปรับรุ่นตัวจริง" ลงหลายฐานโดยไม่แตะไฟล์ตั้งค่าของโปรเจ็ค
        $db_config = isset($db_config_override) ? $db_config_override : include ROOT_PATH.'settings/database.php';
        $config_file = isset($config_file_override) ? $config_file_override : ROOT_PATH.'settings/config.php';
        try {
            $db_config = $db_config['mysql'];
            // เขื่อมต่อฐานข้อมูล
            $db = new Db($db_config);
        } catch (\Exception $exc) {
            $error = true;
            echo '<h2>ความผิดพลาดในการเชื่อมต่อกับฐานข้อมูล</h2>';
            echo '<p class=warning>ไม่สามารถเชื่อมต่อกับฐานข้อมูลของคุณได้ในขณะนี้</p>';
            echo '<p>อาจเป็นไปได้ว่า</p>';
            echo '<ol>';
            echo '<li>เซิร์ฟเวอร์ของฐานข้อมูลของคุณไม่สามารถใช้งานได้ในขณะนี้</li>';
            echo '<li>ค่ากำหนดของฐานข้อมูลไม่ถูกต้อง (ตรวจสอบไฟล์ settings/database.php)</li>';
            echo '<li>ไม่พบฐานข้อมูลที่ต้องการติดตั้ง กรุณาสร้างฐานข้อมูลก่อน หรือใช้ฐานข้อมูลที่มีอยู่แล้ว</li>';
            echo '<li class="incorrect">'.$exc->getMessage().'</li>';
            echo '</ol>';
            echo '<p>หากคุณไม่สามารถดำเนินการแก้ไขข้อผิดพลาดด้วยตัวของคุณเองได้ ให้ติดต่อผู้ดูแลระบบเพื่อขอข้อมูลที่ถูกต้อง หรือ ลองติดตั้งใหม่</p>';
            echo '<p class="submit"><a href="index.php?step=1" class="btn large btn-secondary">กลับไปลองใหม่</a></p>';
        }
        if (!$error) {
            // =================================================================
            // ตรวจก่อนแตะ — ทุกอย่างในบล็อกนี้อ่านอย่างเดียว
            // ถ้าไม่ผ่าน ฐานข้อมูลจะยังไม่ถูกแก้แม้แต่ตัวอักษรเดียว
            // =================================================================
            $preflight = preflight($db, $db_config, $config, $new_config, $config_file);
            if (!empty($preflight['errors'])) {
                $html = preflightHtml($preflight);
                $html .= '<p class="submit"><a href="." class="btn btn-primary large">ลองใหม่</a></p>';
                echo $html;
                $log = writeUpgradeLog(upgradeReportText($html));
                if ($log !== '') {
                    echo '<p class=comment>บันทึกผลการตรวจไว้ที่ <code>'.htmlspecialchars(str_replace(ROOT_PATH, '', $log), ENT_QUOTES).'</code></p>';
                }

                return;
            }

            // เชื่อมต่อฐานข้อมูลสำเร็จ
            $content = ['<li class="correct">เชื่อมต่อฐานข้อมูลสำเร็จ</li>'];
            foreach ($preflight['notes'] as $_note) {
                $content[] = '<li class="correct">'.$_note.'</li>';
            }
            foreach ($preflight['warnings'] as $_warn) {
                $content[] = '<li class="warning">'.$_warn.'</li>';
            }
            // จำนวนแถวจริงก่อนเริ่ม (preflight นับมาให้แล้วด้วย COUNT(*))
            $counts_before = $preflight['counts'];
            try {
                $prefix = $db_config['prefix'];
                $table_user = $prefix.'_user';
                if (empty($config['password_key'])) {
                    // password_key คือ "พริกไทย" ที่ใช้ผสมทุกรหัสผ่านในระบบ
                    //   password ที่เก็บไว้ = sha1(password_key . รหัสผ่าน . salt)
                    // สร้างใหม่ได้เฉพาะตอนที่ยังไม่มีรหัสผ่านให้เสียหาย ซึ่ง
                    // preflight ตรวจให้แล้วว่าฐานนี้ยังไม่มีรหัสผ่านของใครเลย
                    $config['password_key'] = uniqid();
                }
                // ตรวจสอบการ login
                updateAdmin($db, $table_user, $_POST['username'], $_POST['password'], $config['password_key']);

                // =========================================================
                // ตารางแกนของ Gcms (เหมือนกันทุกโปรเจ็ค)
                // =========================================================
                include ROOT_PATH.'install/upgrade_core.php';

                // =========================================================
                // ตัวติดตั้งเรียก upgrade_core.php ของโมดูลต่างๆให้เอง
                // =========================================================

                // =========================================================
                // บันทึก settings/config.php — ผ่านฟังก์ชันเดียวกับที่ตัวติดตั้งใช้
                // =========================================================
                $config = ensureConfigDefaults($config, $new_config);
                $f = save($config, $config_file);
                $content[] = '<li class="'.($f ? 'correct' : 'incorrect').'">บันทึก <b>config.php</b> ...</li>';
                // นำเข้าภาษา
                include ROOT_PATH.'install/language.php';
            } catch (\PDOException $exc) {
                $content[] = '<li class="incorrect">'.$exc->getMessage().'</li>';
                $error = true;
            } catch (\Exception $exc) {
                $content[] = '<li class="incorrect">'.$exc->getMessage().'</li>';
                $error = true;
            }

            // =================================================================
            // นับใหม่แล้วเทียบให้ผู้ใช้เห็นเองว่าข้อมูลไม่หาย
            // ทำทั้งกรณีสำเร็จและล้มเหลว เพราะกรณีล้มกลางทางคือกรณีที่ต้องรู้ที่สุด
            // =================================================================
            $counts_after = countRows($db, prefixTables($db, $db_config['prefix']));
            list($count_html, $data_lost) = rowCountHtml($counts_before, $counts_after);
            if ($data_lost) {
                $error = true;
                $content[] = '<li class="incorrect">จำนวนข้อมูลบางตารางลดลงหลังปรับรุ่น '
                    .'กรุณากู้คืนฐานข้อมูลจากไฟล์สำรอง แล้วส่งไฟล์บันทึกผลใน <code>datas/logs/</code> มาให้ผู้พัฒนา</li>';
            }

            $html = '';
            if (!$error) {
                // บันทึกรุ่นลงฐานข้อมูลของไซต์เอง (ตาราง {prefix}_migration)
                stampMigration($db, $db_config['prefix'], 'core', $new_config['version'], 'upgrade2.php');
                $html .= '<h2>ปรับรุ่นเรียบร้อย</h2>';
                $html .= '<p>การปรับรุ่นได้ดำเนินการเสร็จเรียบร้อยแล้ว หากคุณต้องการความช่วยเหลือในการใช้งาน คุณสามารถ ติดต่อสอบถามได้ที่ <a href="https://www.kotchasan.com" target="_blank">https://www.kotchasan.com</a></p>';
                $html .= '<ul>'.implode('', $content).'</ul>';
                $html .= $count_html;
                $html .= '<p class=warning>กรุณาลบไดเร็คทอรี่ <em>install/</em> ออกจาก Server ของคุณ</p>';
                $html .= '<p>คุณควรปรับ chmod ให้ไดเร็คทอรี่ <em>datas/</em> และ <em>settings/</em> (และไดเร็คทอรี่อื่นๆที่คุณได้ปรับ chmod ไว้ก่อนการปรับรุ่น) ให้เป็น 644 ก่อนดำเนินการต่อ (ถ้าคุณได้ทำการปรับ chmod ไว้ด้วยตัวเอง)</p>';
                $html .= '<p class="submit"><a href="../" class="btn btn-primary large">เข้าระบบ</a></p>';
            } else {
                $html .= '<h2>ปรับรุ่นไม่สำเร็จ</h2>';
                $html .= '<p>การปรับรุ่นยังไม่สมบูรณ์ ตัวปรับรุ่นนี้รันซ้ำได้ ถ้าแก้ข้อผิดพลาดข้างล่างแล้วกดปรับรุ่นใหม่ ระบบจะทำต่อจากจุดที่ค้างไว้ หากคุณต้องการความช่วยเหลือ คุณสามารถ ติดต่อสอบถามได้ที่ <a href="https://www.kotchasan.com" target="_blank">https://www.kotchasan.com</a></p>';
                $html .= '<ul>'.implode('', $content).'</ul>';
                $html .= $count_html;
                $html .= '<p class="submit"><a href="." class="btn btn-primary large">ลองใหม่</a></p>';
            }
            echo $html;

            // เขียนผลลงไฟล์ ถ้าผู้ใช้ทักมา ให้เขาส่งไฟล์นี้แทนการขอเข้าเครื่อง
            $log = writeUpgradeLog(upgradeReportText($html));
            if ($log !== '') {
                echo '<p class=comment>บันทึกผลการปรับรุ่นไว้ที่ <code>'.htmlspecialchars(str_replace(ROOT_PATH, '', $log), ENT_QUOTES).'</code></p>';
            }
        }
    }
}
