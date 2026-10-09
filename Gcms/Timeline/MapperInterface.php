<?php
/**
 * @filesource Gcms/Timeline/MapperInterface.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Gcms\Timeline;

/**
 * สัญญาของตัวแปลงข้อมูลของแอปหนึ่งโมดูลให้เป็น timeline item
 *
 * ทุกอย่างที่ต้องรู้กฎธุรกิจของแอปอยู่ในคลาสที่ implement interface นี้เท่านั้น
 * ส่วนที่เหลือของ Gcms\Timeline เป็นโค้ดกลางที่ทุกแอปใช้ร่วมกันแบบไม่ต้องแก้
 *
 * คลาสที่ implement **ต้องอยู่ในโมดูลของตัวเอง** ไม่ใช่ใน modules/timeline
 * เช่นระบบลูกหนี้เขียนไว้ที่ modules/ar/models/timeline.php เป็น \Ar\Timeline\Model
 * แล้วประกาศไว้ใน $cfg->timeline_mappers
 *
 * เหตุผล: modules/timeline เป็นโค้ดกลางที่ก๊อปทับจาก adminframework ลงแอปลูกได้ตรง ๆ
 * ถ้าเอา mapper ไปวางไว้ในนั้น การอัปเดตโค้ดกลางครั้งเดียวจะลบ mapper ของทุกแอปทิ้ง
 *
 * @since 1.0
 */
interface MapperInterface
{
    /**
     * kind ทั้งหมดที่ mapper นี้ผลิตได้ — ประกาศไว้ใน manifest ให้ Hub ตั้งกฎเตือน
     * ล่วงหน้าได้โดยไม่ต้องรอให้มี item จริงเกิดขึ้นก่อน
     *
     * @return array<string> เช่น ['payment.due', 'payment.overdue']
     */
    public function kinds();

    /**
     * รายการ item ทั้งหมดในกรอบเวลาที่ขอ — snapshot ไม่ใช่ delta
     *
     * ต้องคืน item ที่เกินกำหนดและยัง active มาด้วยเสมอ แม้จะเก่ากว่า $from
     * (หนี้ที่ค้างมาสองปีต้องไม่หายไปจากจอเพราะกรอบเวลา)
     *
     * แต่ละ item เป็น array ตาม TIMELINE-PROTOCOL.md §6 — คืนเป็น array ธรรมดา
     * ได้เลย ไม่ต้อง validate เอง Provider เป็นคนตรวจและ normalize ให้
     *
     * @param \DateTimeInterface $from
     * @param \DateTimeInterface $to
     *
     * @return array<array>
     */
    public function items(\DateTimeInterface $from, \DateTimeInterface $to);

    /**
     * action id ที่ mapper นี้รับได้ — ต้องตรงกับที่ประกาศไว้ใน item.actions
     *
     * คืน array ว่างถ้าเป็น provider แบบอ่านอย่างเดียว
     *
     * @return array<string>
     */
    public function actions();

    /**
     * ลงมือทำ action ที่ Hub สั่งมา
     *
     * ต้องตรวจความถูกต้องของ $params ใหม่ทั้งหมด — Hub ตรวจฟอร์มมาแล้วก็จริง
     * แต่คำขอมาจากเครือข่าย จะเชื่อไม่ได้
     *
     * @param string $uid    uid ของ item ที่ถูกกด
     * @param string $action action id
     * @param array  $params ค่าที่ผู้ใช้กรอก
     *
     * @throws \Kotchasan\ApiException 422 เมื่อข้อมูลไม่ผ่าน validation
     *
     * @return array|null item ใบเดิมที่อัปเดตแล้ว เพื่อให้ Hub รีเฟรชการ์ดได้ทันที
     *                    โดยไม่ต้องรอ sync รอบถัดไป
     *
     *                    คืน null เมื่อ $uid ไม่ใช่ของ mapper ตัวนี้ — Provider จะ
     *                    ไปถาม mapper ตัวถัดไปที่ประกาศ action เดียวกันต่อ และตอบ
     *                    422 เมื่อไม่มีใครรับเลย
     */
    public function handleAction($uid, $action, array $params);
}
