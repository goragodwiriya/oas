<?php
/**
 * @filesource Gcms/Timeline/Provider.php
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
 * แกนกลางของ Timeline Provider — โค้ดชุดเดียวที่ทุกแอปใช้ร่วมกัน
 *
 * รับผิดชอบทุกอย่างที่ไม่ขึ้นกับแอป: อ่านพารามิเตอร์ กรอบเวลา แบ่งหน้า เรียง
 * ตรวจ item ประกอบ manifest และส่ง action ต่อให้ mapper ที่ถูกตัว
 *
 * สิ่งที่ต้องรู้กฎธุรกิจอยู่ใน mapper ของแต่ละแอปทั้งหมด (ดู MapperInterface)
 *
 * @see TIMELINE-PROTOCOL.md
 *
 * @since 1.0
 */
class Provider extends \Kotchasan\KBase
{
    /**
     * เวอร์ชันของ protocol ที่โค้ดชุดนี้พูดได้
     */
    const PROTOCOL = '1.0';

    /**
     * เพดานจำนวน item ต่อหน้า
     */
    const PER_PAGE_MAX = 500;

    /**
     * จำนวนต่อหน้าเมื่อไม่ระบุ
     */
    const PER_PAGE_DEFAULT = 200;

    /**
     * อายุของ idempotency key
     */
    const IDEMPOTENCY_TTL = 86400;

    /**
     * mapper ที่สร้างไว้แล้ว
     *
     * @var MapperInterface[]|null
     */
    private static $mappers = null;

    /**
     * สร้าง mapper ทุกตัวที่ประกาศไว้ใน $cfg->timeline_mappers
     *
     * @throws ApiException 500 เมื่อคลาสที่ประกาศไว้ไม่มีจริงหรือไม่ implement interface
     *
     * @return MapperInterface[]
     */
    public static function mappers()
    {
        if (self::$mappers !== null) {
            return self::$mappers;
        }

        self::$mappers = [];
        $classes = self::$cfg->timeline_mappers ?? [];

        foreach ((array) $classes as $class) {
            if (!class_exists($class)) {
                throw new ApiException(Language::sprintf('Timeline mapper class not found: %s', $class), 500);
            }
            $mapper = new $class();
            if (!($mapper instanceof MapperInterface)) {
                throw new ApiException(Language::sprintf('%s does not implement Gcms\\Timeline\\MapperInterface', $class), 500);
            }
            self::$mappers[] = $mapper;
        }

        return self::$mappers;
    }

    /**
     * โซนเวลาของแอปนี้
     *
     * @return string
     */
    public static function timezone()
    {
        $tz = self::$cfg->timezone ?? '';

        return $tz === '' ? date_default_timezone_get() : $tz;
    }

    /**
     * ข้อมูลสำหรับ GET /timeline/manifest
     *
     * @return array
     */
    public static function manifest()
    {
        $kinds = [];
        $actions = [];
        foreach (self::mappers() as $mapper) {
            $kinds = array_merge($kinds, (array) $mapper->kinds());
            $actions = array_merge($actions, (array) $mapper->actions());
        }
        $kinds = array_values(array_unique($kinds));
        $actions = array_values(array_unique($actions));

        return [
            'protocol' => self::PROTOCOL,
            'source' => [
                'slug' => (string) (self::$cfg->timeline_slug ?? ''),
                'name' => (string) (self::$cfg->timeline_name ?? self::$cfg->web_title ?? ''),
                'url' => rtrim(WEB_URL, '/').'/',
                'version' => (string) (self::$cfg->version ?? '')
            ],
            'timezone' => self::timezone(),
            'kinds' => $kinds,
            'capabilities' => [
                'items' => true,
                'actions' => !empty($actions),
                'incremental' => false,
                'push' => false,
                'max_per_page' => self::PER_PAGE_MAX,
                'max_horizon_past' => 'P24M',
                'max_horizon_future' => 'P24M'
            ],
            // Hub เทียบค่านี้กับนาฬิกาตัวเอง — ระบบเตือนเวลาที่นาฬิกาสองฝั่ง
            // ไม่ตรงกันจะผิดแบบเงียบ ๆ ซึ่งเป็นความผิดพลาดชนิดที่ไม่มีใครสังเกต
            'server_time' => date(DATE_ATOM)
        ];
    }

    /**
     * ข้อมูลสำหรับ GET /timeline/items
     *
     * @param array $params past, future, page, per_page, kinds
     *
     * @return array
     */
    public static function items(array $params)
    {
        $tz = new \DateTimeZone(self::timezone());
        $now = new \DateTime('now', $tz);

        $from = (clone $now)->sub(self::duration($params['past'] ?? 'P90D', 'past'));
        $to = (clone $now)->add(self::duration($params['future'] ?? 'P12M', 'future'));

        // ตัวเรียกที่ไม่ได้ระบุมักส่ง 0 มา (InputItem::toInt() ของค่าที่ไม่มี)
        // ถ้าเช็คด้วย isset() อย่างเดียว 0 จะกลายเป็น "หน้าละ 1 รายการ" แล้วการ
        // ดึงข้อมูลชุดเดียวจะกลายเป็น HTTP request เท่าจำนวน item
        $perPage = (int) ($params['per_page'] ?? 0);
        $perPage = $perPage > 0 ? min($perPage, self::PER_PAGE_MAX) : self::PER_PAGE_DEFAULT;
        $page = isset($params['page']) ? max(1, (int) $params['page']) : 1;

        $only = [];
        if (!empty($params['kinds'])) {
            $only = array_filter(array_map('trim', explode(',', (string) $params['kinds'])));
        }

        $timezone = self::timezone();
        $items = [];
        $complete = true;
        $errors = [];

        foreach (self::mappers() as $mapper) {
            // mapper ตัวหนึ่งพังต้องไม่ทำให้ทั้ง snapshot หายไป — ตอบ complete=false
            // แล้ว Hub จะรู้เองว่าห้ามลบ item ที่หายไปในรอบนี้ (protocol §8)
            try {
                foreach ((array) $mapper->items($from, $to) as $raw) {
                    $item = Item::normalize($raw, $timezone);
                    if (!empty($only) && !in_array($item['kind'], $only, true)) {
                        continue;
                    }
                    $items[] = $item;
                }
            } catch (\Throwable $e) {
                $complete = false;
                $errors[] = get_class($mapper).': '.$e->getMessage();
                \Kotchasan\Logger::error('Timeline mapper failed', [
                    'mapper' => get_class($mapper),
                    'message' => $e->getMessage()
                ]);
            }
        }

        // เรียงด้วย (เวลา, uid) ไม่ใช่เวลาอย่างเดียว — item ที่เวลาเท่ากันต้องเรียง
        // เหมือนเดิมทุกครั้ง ไม่งั้นการแบ่งหน้าจะทำให้บาง item หลุดหรือซ้ำ
        usort($items, function ($a, $b) {
            $ka = ($a['due_at'] ?? $a['start_at'] ?? '').'|'.$a['uid'];
            $kb = ($b['due_at'] ?? $b['start_at'] ?? '').'|'.$b['uid'];

            return strcmp($ka, $kb);
        });

        $total = count($items);
        $pages = $total === 0 ? 1 : (int) ceil($total / $perPage);

        $meta = [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'pages' => $pages,
            'generated_at' => date(DATE_ATOM),
            'complete' => $complete
        ];
        if (!empty($errors) && defined('DEBUG') && DEBUG == 2) {
            $meta['errors'] = $errors;
        }

        return [
            'items' => array_slice($items, ($page - 1) * $perPage, $perPage),
            'meta' => $meta
        ];
    }

    /**
     * ข้อมูลสำหรับ POST /timeline/actions
     *
     * @param array $body uid, action, params, idempotency_key, requested_at
     *
     * @throws ApiException
     *
     * @return array
     */
    public static function action(array $body)
    {
        $uid = isset($body['uid']) ? trim((string) $body['uid']) : '';
        $action = isset($body['action']) ? trim((string) $body['action']) : '';
        $params = isset($body['params']) && is_array($body['params']) ? $body['params'] : [];
        $key = isset($body['idempotency_key']) ? trim((string) $body['idempotency_key']) : '';

        if ($uid === '' || $action === '') {
            throw new ApiException(Language::get('uid and action are required'), 400);
        }
        if ($key === '') {
            throw new ApiException(Language::get('idempotency_key is required'), 400);
        }

        // คำสั่งที่ค้างในคิวนานผิดปกติอาจเป็นการ retry ของสิ่งที่ผู้ใช้ยกเลิกไปแล้ว
        if (!empty($body['requested_at'])) {
            $requested = strtotime((string) $body['requested_at']);
            if ($requested !== false && abs(time() - $requested) > 600) {
                throw new ApiException(Language::get('The request is too old'), 409);
            }
        }

        // กด "บันทึกว่าติดต่อแล้ว" สองครั้งเพราะเน็ตช้า ต้องไม่ได้สองแถว
        $cached = self::recallIdempotent($key);
        if ($cached !== null) {
            $cached['applied'] = false;

            return $cached;
        }

        $result = null;
        foreach (self::mappers() as $mapper) {
            if (!in_array($action, (array) $mapper->actions(), true)) {
                continue;
            }
            $item = $mapper->handleAction($uid, $action, $params);
            if ($item !== null) {
                $result = [
                    'applied' => true,
                    'message' => Language::get('Action completed'),
                    'item' => Item::normalize($item, self::timezone())
                ];
                break;
            }
        }

        if ($result === null) {
            throw new ApiException(Language::sprintf('Unknown action or item not found: %s', $action), 422);
        }

        self::rememberIdempotent($key, $uid, $action, $result);

        return $result;
    }

    /**
     * แปลง ISO 8601 duration เป็น DateInterval
     *
     * @param string $value
     * @param string $name
     *
     * @throws ApiException 400
     *
     * @return \DateInterval
     */
    private static function duration($value, $name)
    {
        try {
            return new \DateInterval((string) $value);
        } catch (\Exception $e) {
            throw new ApiException(Language::sprintf('%s is not a valid ISO 8601 duration: %s', $name, $value), 400);
        }
    }

    /**
     * @param string $key
     *
     * @return array|null
     */
    private static function recallIdempotent($key)
    {
        $row = \Kotchasan\DB::create()->first(
            'timeline_idempotency',
            [
                ['key_hash', hash('sha256', $key)],
                ['created_at', '>', date('Y-m-d H:i:s', time() - self::IDEMPOTENCY_TTL)]
            ],
            ['response_json']
        );

        if ($row === null) {
            return null;
        }
        $decoded = json_decode($row->response_json, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param string $key
     * @param string $uid
     * @param string $action
     * @param array  $result
     */
    private static function rememberIdempotent($key, $uid, $action, array $result)
    {
        $db = \Kotchasan\DB::create();

        // ยิงสองครั้งพร้อมกันจริง ๆ จะชนที่ PRIMARY KEY — งานหลักทำไปแล้วและสำเร็จ
        // การจดบันทึกไม่ได้ต้องไม่ทำให้ผลลัพธ์กลายเป็นล้มเหลว
        try {
            $db->insert('timeline_idempotency', [
                'key_hash' => hash('sha256', $key),
                'uid' => $uid,
                'action' => $action,
                'response_json' => json_encode($result, JSON_UNESCAPED_UNICODE),
                'created_at' => date('Y-m-d H:i:s')
            ]);
        } catch (\Throwable $e) {
            \Kotchasan\Logger::error('Saving the timeline idempotency key failed', [
                'message' => $e->getMessage()
            ]);
        }

        // เก็บกวาดแบบสุ่มแทนการตั้ง cron แยก — ตารางนี้โตช้ามาก
        if (mt_rand(1, 50) === 1) {
            $db->delete(
                'timeline_idempotency',
                [['created_at', '<', date('Y-m-d H:i:s', time() - self::IDEMPOTENCY_TTL)]],
                0
            );
        }
    }
}
