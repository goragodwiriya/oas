<?php
/**
 * @filesource Gcms/Timeline/Item.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Gcms\Timeline;

use Kotchasan\ApiException;
use Kotchasan\Language;

/**
 * ตรวจและ normalize timeline item หนึ่งใบก่อนส่งออกไปยัง Hub
 *
 * มีอยู่เพื่อให้ mapper ของแต่ละแอปเขียนสั้นที่สุด — คืน array หลวม ๆ มาได้เลย
 * แล้วคลาสนี้เป็นคนบังคับให้ตรงตาม TIMELINE-PROTOCOL.md §6 เหมือนกันทุกแอป
 *
 * ตรวจที่ต้นทางแบบนี้ดีกว่าปล่อยให้ Hub ไปเจอเอง เพราะข้อผิดพลาดจะโผล่ตอน
 * เขียน mapper ซึ่งเป็นตอนที่คนแก้รู้ว่าเกิดจากอะไร ไม่ใช่โผล่เป็นข้อมูลเพี้ยน
 * บนจอ Hub อีกสามสัปดาห์ให้หลัง
 *
 * @since 1.0
 */
class Item
{
    /**
     * สถานะที่ protocol รู้จัก
     */
    const STATUSES = ['active', 'completed', 'cancelled', 'expired'];

    /**
     * ระดับความสำคัญที่ protocol รู้จัก
     */
    const PRIORITIES = ['low', 'normal', 'high', 'critical'];

    /**
     * ชนิดของ field ในฟอร์มของ action
     */
    const FIELD_TYPES = ['text', 'textarea', 'number', 'date', 'datetime', 'select', 'checkbox'];

    /**
     * ตรวจและ normalize item หนึ่งใบ
     *
     * @param array  $raw       item ที่ mapper คืนมา
     * @param string $timezone  โซนเวลาปริยายของแอปนี้
     *
     * @throws ApiException 500 เมื่อ mapper สร้าง item ที่ผิดสัญญา
     *
     * @return array item ที่พร้อมส่งออก
     */
    public static function normalize(array $raw, $timezone)
    {
        $uid = isset($raw['uid']) ? trim((string) $raw['uid']) : '';
        if ($uid === '' || mb_strlen($uid) > 190) {
            throw new ApiException(Language::get('A timeline item needs a uid of at most 190 characters'), 500);
        }

        $kind = isset($raw['kind']) ? trim((string) $raw['kind']) : '';
        if ($kind === '') {
            throw new ApiException(Language::sprintf('Timeline item %s has no kind', $uid), 500);
        }

        $title = isset($raw['title']) ? trim((string) $raw['title']) : '';
        if ($title === '') {
            throw new ApiException(Language::sprintf('Timeline item %s has no title', $uid), 500);
        }

        $allDay = !empty($raw['all_day']);
        $tz = isset($raw['tz']) && $raw['tz'] !== '' ? (string) $raw['tz'] : $timezone;

        $startAt = self::time($raw, 'start_at', $allDay, $tz, $uid);
        $endAt = self::time($raw, 'end_at', $allDay, $tz, $uid);
        $dueAt = self::time($raw, 'due_at', $allDay, $tz, $uid);

        // ไม่มีเวลาก็ไม่ใช่ timeline item — จับตรงนี้ ไม่ปล่อยให้ไปโผล่เป็น
        // แถวที่เรียงลำดับไม่ได้บนจอ Hub
        if ($startAt === null && $dueAt === null) {
            throw new ApiException(Language::sprintf('Timeline item %s needs due_at or start_at', $uid), 500);
        }

        if ($startAt !== null && $endAt !== null && strcmp($endAt, $startAt) < 0) {
            throw new ApiException(Language::sprintf('Timeline item %s ends before it starts', $uid), 500);
        }

        $status = isset($raw['status']) ? (string) $raw['status'] : 'active';
        if (!in_array($status, self::STATUSES, true)) {
            throw new ApiException(Language::sprintf('Timeline item %s has an invalid status: %s', $uid, $status), 500);
        }

        $priority = isset($raw['priority']) ? (string) $raw['priority'] : 'normal';
        if (!in_array($priority, self::PRIORITIES, true)) {
            throw new ApiException(Language::sprintf('Timeline item %s has an invalid priority: %s', $uid, $priority), 500);
        }

        $item = [
            'uid' => $uid,
            'kind' => $kind,
            'title' => self::clip($title, 255),
            'subtitle' => self::clip(isset($raw['subtitle']) ? (string) $raw['subtitle'] : '', 255),
            'body' => isset($raw['body']) && $raw['body'] !== '' ? (string) $raw['body'] : null,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'due_at' => $dueAt,
            'all_day' => $allDay,
            'tz' => $tz,
            'status' => $status,
            'priority' => $priority,
            'entity' => self::entity($raw),
            'contact' => self::contact($raw),
            'links' => self::links($raw, $uid),
            'actions' => self::actions($raw, $uid),
            'facts' => self::facts($raw),
            'meta' => isset($raw['meta']) && is_array($raw['meta']) ? $raw['meta'] : null,
            'hint' => isset($raw['hint']) && is_array($raw['hint']) ? $raw['hint'] : null
        ];

        return $item;
    }

    /**
     * ข้อมูลสรุปที่แสดงบนการ์ดได้ทันที
     *
     * `meta` เป็นค่าดิบสำหรับเครื่องอ่าน — Hub เอาไปวางบนจอตรง ๆ ไม่ได้เพราะ
     * ไม่รู้ว่าคีย์ไหนควรโชว์ ควรเรียงอย่างไร ควรใส่หน่วยอะไร และตัวเลข
     * ทศนิยมของแต่ละแอปจัดรูปไม่เหมือนกัน · `facts` คือคำตอบของอีกฝั่ง —
     * ต้นทางจัดรูปและเรียงลำดับมาให้เสร็จ Hub แค่แสดง
     *
     * ใช้ตอบคำถาม "ขอดูสถานะล่าสุดโดยไม่ต้องเข้าระบบ" ซึ่งเป็นเหตุผลเดียว
     * ที่ Hub ถูกสร้างขึ้นมา · จำกัดสิบบรรทัดเพราะการ์ดที่ยาวกว่านั้นอ่านไม่ทัน
     * และในแชตจะถูกตัดกลางคันอยู่ดี
     *
     * @param array $raw
     *
     * @return array|null [['label' => string, 'value' => string], ...]
     */
    private static function facts(array $raw)
    {
        if (!isset($raw['facts']) || !is_array($raw['facts'])) {
            return null;
        }

        $out = [];
        foreach ($raw['facts'] as $key => $fact) {
            // รับได้สองรูป — ['label' => .., 'value' => ..] และ ['ป้าย' => 'ค่า']
            // รูปหลังสั้นกว่ามากเมื่อ mapper ประกอบทีละบรรทัด
            if (is_array($fact)) {
                $label = trim((string) ($fact['label'] ?? ''));
                $value = trim((string) ($fact['value'] ?? ''));
            } else {
                $label = is_string($key) ? trim($key) : '';
                $value = trim((string) $fact);
            }

            // ไม่มีค่าก็ไม่ต้องมีบรรทัด — บรรทัดว่างบนการ์ดอ่านเหมือนข้อมูลหาย
            if ($label === '' || $value === '') {
                continue;
            }

            $out[] = [
                'label' => self::clip($label, 40),
                'value' => self::clip($value, 120)
            ];

            if (count($out) >= 10) {
                break;
            }
        }

        return $out === [] ? null : $out;
    }

    /**
     * แปลงค่าเวลาให้อยู่ในรูปที่ protocol กำหนด
     *
     * มีเวลา   -> RFC3339 พร้อม offset  2026-09-30T10:00:00+07:00
     * ทั้งวัน   -> วันที่ล้วน            2026-09-30
     *
     * รับได้ทั้ง DateTimeInterface, unix timestamp (int) และ string ที่ strtotime อ่านออก
     * เพราะสามแอปที่ต่อในรอบแรกเก็บเวลาไว้คนละแบบทั้งสามแอป
     *
     * @param array  $raw
     * @param string $key
     * @param bool   $allDay
     * @param string $tz
     * @param string $uid
     *
     * @throws ApiException
     *
     * @return string|null
     */
    private static function time(array $raw, $key, $allDay, $tz, $uid)
    {
        if (!isset($raw[$key]) || $raw[$key] === '' || $raw[$key] === null) {
            return null;
        }
        $value = $raw[$key];

        try {
            if ($value instanceof \DateTimeInterface) {
                $date = new \DateTime($value->format('Y-m-d H:i:s'), new \DateTimeZone($tz));
            } elseif (is_int($value) || (is_string($value) && ctype_digit($value))) {
                // unix timestamp — BluHost และ ar_details เก็บแบบนี้
                $date = new \DateTime('@'.((int) $value));
                $date->setTimezone(new \DateTimeZone($tz));
            } else {
                $date = new \DateTime((string) $value, new \DateTimeZone($tz));
            }
        } catch (\Exception $e) {
            throw new ApiException(Language::sprintf('Timeline item %s has an unreadable %s', $uid, $key), 500);
        }

        // 0000-00-00 ของ MySQL กลายเป็นปี -1 ตอนแปลง ซึ่งไม่ใช่วันที่ที่ตั้งใจ
        if ((int) $date->format('Y') < 1970) {
            return null;
        }

        return $allDay ? $date->format('Y-m-d') : $date->format(DATE_ATOM);
    }

    /**
     * @param array $raw
     *
     * @return array|null
     */
    private static function entity(array $raw)
    {
        if (empty($raw['entity']) || !is_array($raw['entity'])) {
            return null;
        }
        $entity = $raw['entity'];
        if (empty($entity['type']) || !isset($entity['id']) || $entity['id'] === '') {
            return null;
        }

        return [
            'type' => self::clip((string) $entity['type'], 64),
            'id' => self::clip((string) $entity['id'], 190),
            'label' => self::clip(isset($entity['label']) ? (string) $entity['label'] : '', 190)
        ];
    }

    /**
     * ตัดข้อมูลติดต่อให้เหลือเฉพาะที่ใช้ "ลงมือทำ" ได้จริง ตาม §6.5 ของ protocol
     *
     * ฟิลด์อื่นที่ mapper เผลอใส่มาจะถูกทิ้งที่นี่ ไม่หลุดออกไปกับ payload
     *
     * @param array $raw
     *
     * @return array|null
     */
    private static function contact(array $raw)
    {
        if (empty($raw['contact']) || !is_array($raw['contact'])) {
            return null;
        }
        $contact = [];
        foreach (['name', 'phone', 'email'] as $key) {
            if (!empty($raw['contact'][$key])) {
                $contact[$key] = self::clip((string) $raw['contact'][$key], 190);
            }
        }

        return empty($contact) ? null : $contact;
    }

    /**
     * @param array  $raw
     * @param string $uid
     *
     * @throws ApiException
     *
     * @return array
     */
    private static function links(array $raw, $uid)
    {
        if (empty($raw['links']) || !is_array($raw['links'])) {
            return [];
        }
        $links = [];
        foreach ($raw['links'] as $link) {
            if (empty($link['url'])) {
                continue;
            }
            // url ต้องเป็น absolute เพราะ Hub อยู่คนละโดเมน relative url จะพาไปผิดที่
            if (!preg_match('#^https?://#i', (string) $link['url'])) {
                throw new ApiException(Language::sprintf('Timeline item %s has a link that is not an absolute URL', $uid), 500);
            }
            $links[] = [
                'label' => self::clip(isset($link['label']) ? (string) $link['label'] : Language::get('Open'), 100),
                'url' => (string) $link['url'],
                'primary' => !empty($link['primary'])
            ];
        }

        return $links;
    }

    /**
     * @param array  $raw
     * @param string $uid
     *
     * @throws ApiException
     *
     * @return array
     */
    private static function actions(array $raw, $uid)
    {
        if (empty($raw['actions']) || !is_array($raw['actions'])) {
            return [];
        }
        $actions = [];
        $seen = [];
        foreach ($raw['actions'] as $action) {
            if (empty($action['id']) || !preg_match('/^[a-z0-9_]{1,32}$/', (string) $action['id'])) {
                throw new ApiException(Language::sprintf('Timeline item %s has an invalid action id', $uid), 500);
            }
            $id = (string) $action['id'];
            if (isset($seen[$id])) {
                throw new ApiException(Language::sprintf('Timeline item %s has a duplicate action id: %s', $uid, $id), 500);
            }
            $seen[$id] = true;

            $style = isset($action['style']) ? (string) $action['style'] : 'default';
            if (!in_array($style, ['default', 'primary', 'danger'], true)) {
                $style = 'default';
            }

            $actions[] = [
                'id' => $id,
                'label' => self::clip(isset($action['label']) ? (string) $action['label'] : $id, 100),
                'style' => $style,
                'confirm' => !empty($action['confirm']) ? (string) $action['confirm'] : null,
                'fields' => self::fields($action, $uid, $id)
            ];
        }

        return $actions;
    }

    /**
     * @param array  $action
     * @param string $uid
     * @param string $actionId
     *
     * @throws ApiException
     *
     * @return array
     */
    private static function fields(array $action, $uid, $actionId)
    {
        if (empty($action['fields']) || !is_array($action['fields'])) {
            return [];
        }
        $fields = [];
        foreach ($action['fields'] as $field) {
            if (empty($field['name'])) {
                throw new ApiException(Language::sprintf('Timeline item %s action %s has a field without a name', $uid, $actionId), 500);
            }
            $type = isset($field['type']) ? (string) $field['type'] : 'text';
            if (!in_array($type, self::FIELD_TYPES, true)) {
                throw new ApiException(Language::sprintf('Timeline item %s action %s has an unsupported field type: %s', $uid, $actionId, $type), 500);
            }

            $one = [
                'name' => (string) $field['name'],
                'label' => self::clip(isset($field['label']) ? (string) $field['label'] : (string) $field['name'], 100),
                'type' => $type,
                'required' => !empty($field['required'])
            ];

            if ($type === 'select') {
                if (empty($field['options']) || !is_array($field['options'])) {
                    throw new ApiException(Language::sprintf('Timeline item %s action %s field %s is a select without options', $uid, $actionId, $one['name']), 500);
                }
                $one['options'] = [];
                foreach ($field['options'] as $value => $option) {
                    // รับได้ทั้ง [['value'=>..,'label'=>..], ...] และ [value => label]
                    if (is_array($option)) {
                        $one['options'][] = [
                            'value' => $option['value'] ?? $value,
                            'label' => (string) ($option['label'] ?? $option['value'] ?? $value)
                        ];
                    } else {
                        $one['options'][] = ['value' => $value, 'label' => (string) $option];
                    }
                }
            }

            if (isset($field['default'])) {
                $one['default'] = $field['default'];
            }

            $fields[] = $one;
        }

        return $fields;
    }

    /**
     * @param string $text
     * @param int    $length
     *
     * @return string
     */
    private static function clip($text, $length)
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $text));

        return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 1).'…' : $text;
    }
}
