<?php
/**
 * @filesource modules/index/controllers/manifest.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Manifest;

use Kotchasan\Http\Request;
use Kotchasan\Http\Response;
use Kotchasan\Language;

/**
 * Web App Manifest (manifest.php)
 *
 * ชื่อ คำอธิบาย และสีมาจากค่าชุดเดียวกับที่ index.php ใช้กับ <title> และ
 * <meta name="theme-color"> แก้ในหน้าตั้งค่าแล้ว แอปที่ติดตั้งเป็น PWA เปลี่ยนตาม
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Kotchasan\Controller
{
    /**
     * manifest ของเว็บนี้
     *
     * @param Request $request
     *
     * @return Response
     */
    public function index(Request $request)
    {
        $webTitle = self::plainText(self::$cfg->web_title);
        $theme = isset(self::$cfg->theme) && is_array(self::$cfg->theme) ? self::$cfg->theme : [];

        // URL ทั้งหมดอ้างจากที่อยู่ของไฟล์นี้ (รากของเว็บ) เหมือน manifest.json เดิม
        // ไม่ใส่ id เพราะแอปที่ติดตั้งไปแล้วใช้ start_url เป็นตัวตนของแอป
        $manifest = [
            'name' => $webTitle,
            'short_name' => $webTitle,
            'description' => self::plainText(self::$cfg->web_description),
            'start_url' => './',
            'scope' => './',
            'display' => 'standalone',
            'orientation' => 'portrait-primary',
            'background_color' => ($theme['--color-background'] ?? '') ?: '#ffffff',
            'theme_color' => ($theme['--color-primary'] ?? '') ?: '#4f46e5',
            'lang' => Language::name(),
            'dir' => 'ltr',
            'icons' => [
                [
                    'src' => 'images/web-app-manifest-192x192.png',
                    'sizes' => '192x192',
                    'type' => 'image/png',
                    'purpose' => 'any'
                ],
                [
                    'src' => 'images/web-app-manifest-512x512.png',
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'any'
                ],
                [
                    'src' => 'images/web-app-manifest-512x512.png',
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'maskable'
                ]
            ]
        ];

        $response = new Response();

        // no-cache: บันทึกหน้าธีมไม่ได้เปลี่ยน reversion ทำให้ ?v= ที่ index.php ต่อท้ายไว้
        // ไม่เปลี่ยนตามสีที่แก้
        return $response->withHeader('Content-Type', 'application/manifest+json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-cache')
            ->withContent(json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * ข้อความล้วนสำหรับ manifest
     * web_title และ web_description บันทึกผ่าน Text::htmlText จึงอาจมีแท็ก em/b และ entity
     *
     * @param string $text
     *
     * @return string
     */
    private static function plainText($text)
    {
        return trim(html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
