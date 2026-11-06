<?php
/**
 * @filesource modules/index/controllers/telegramwebhook.php
 *
 * Telegram webhook bridge to the shared AI chat core.
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Index\Telegramwebhook;

use Gcms\Api as ApiController;
use Gcms\Chat\Dispatcher;
use Gcms\Chat\Processor;
use Kotchasan\Http\Request;

/**
 * @since 1.0
 */
class Controller extends ApiController
{
    /**
     * Handle Telegram webhook updates.
     *
     * @param Request $request
     *
     * @return mixed
     */
    public function index(Request $request)
    {
        $rawBody = (string) $request->getBody();

        // Allow manual/browser checks without throwing a fatal 405 exception.
        if ($request->getMethod() === 'GET') {
            return $this->successResponse([], 'Telegram webhook endpoint is active');
        }

        if ($request->getMethod() !== 'POST') {
            return $this->errorResponse('Method not allowed', 405);
        }

        if (!$this->isValidSecret($request)) {
            return $this->errorResponse('Unauthorized', 401);
        }

        $payload = $this->decodePayload($rawBody);
        if (empty($payload)) {
            return $this->errorResponse('Invalid webhook payload', 400);
        }

        $rawText = $this->extractIncomingText($payload);
        $processor = new Processor();
        $message = $processor->normalize('telegram', $payload);
        $this->appendIncomingLog($payload, $rawText, (string) $message->text, (string) $message->conversationId);
        if ($message->text === '') {
            return $this->successResponse([
                'handled' => 0,
                'ignored' => 1
            ], 'Telegram update ignored');
        }

        $result = $processor->handleMessage($message);
        $error = (new Dispatcher())->dispatch($message, $result['response'], $result['payload']);

        // ปิดสถานะหมุนของปุ่ม inline keyboard
        //
        // ต้องทำแม้การประมวลผลจะล้มเหลว ไม่งั้นปุ่มจะค้างอยู่ราวสามสิบวินาที
        // แล้วผู้ใช้จะกดซ้ำเพราะคิดว่าไม่ติด
        $callbackId = (string) ($payload['callback_query']['id'] ?? '');
        if ($callbackId !== '') {
            \Gcms\Telegram::answerCallbackQuery(
                $callbackId,
                $error === '' ? '' : 'Request failed',
            );
        }

        // ตอบ 200 เสมอ แม้ส่งข้อความกลับไม่สำเร็จ
        //
        // Telegram ถือว่าทุกสถานะที่ไม่ใช่ 2xx คือ "ยังไม่ได้รับ" แล้วส่ง update
        // เดิมซ้ำเรื่อย ๆ ได้ถึงยี่สิบสี่ชั่วโมง · ตอนนี้เราประมวลผลจบไปแล้ว
        // (เครื่องมือทำงาน เขียนฐานข้อมูลแล้ว) การส่งซ้ำจึงรันทุกอย่างใหม่อีกรอบ
        // ผลคือข้อความเดิมโผล่ในห้องเป็นระยะโดยไม่มีใครพิมพ์อะไร และขั้นตอนของ
        // ตัวช่วยลงนัดถูกเดินหน้าซ้ำ · ความล้มเหลวขาส่งออกบันทึกไว้ในล็อกแทน
        if ($error !== '') {
            $this->appendDispatchError($message, $error);

            return $this->successResponse([
                'handled' => 1,
                'ignored' => 0,
                'delivery_error' => $error
            ], 'Telegram webhook processed with delivery errors');
        }

        return $this->successResponse([
            'handled' => 1,
            'ignored' => 0
        ], 'Telegram webhook processed');
    }

    /**
     * @param string $rawBody
     *
     * @return array
     */
    private function decodePayload($rawBody)
    {
        if ($rawBody === '') {
            return [];
        }

        $decoded = json_decode($rawBody, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param Request $request
     *
     * @return bool
     */
    private function isValidSecret(Request $request)
    {
        // Single source of truth — \Gcms\Telegram::verifyWebhookSecret() fails
        // CLOSED when no secret is configured (previously this failed OPEN,
        // accepting any request whenever telegram_webhook_secret was unset).
        return \Gcms\Telegram::verifyWebhookSecret(
            trim((string) $request->getHeaderLine('x-telegram-bot-api-secret-token'))
        );
    }

    /**
     * @param array $payload
     *
     * @return string
     */
    private function extractIncomingText(array $payload): string
    {
        if (!empty($payload['callback_query']) && is_array($payload['callback_query'])) {
            return trim((string) ($payload['callback_query']['data'] ?? ''));
        }

        // ในช่อง Telegram ใช้คีย์ channel_post ไม่ใช่ message — อ่านแค่ message
        // แปลว่าคำสั่งที่พิมพ์ในช่องจะหายไปเงียบ ๆ ขณะที่ปุ่มยังทำงานอยู่
        foreach (['message', 'edited_message', 'channel_post', 'edited_channel_post'] as $key) {
            if (!empty($payload[$key]) && is_array($payload[$key])) {
                $update = $payload[$key];

                return self::stripBotMention((string) ($update['text'] ?? $update['caption'] ?? ''));
            }
        }

        return self::stripBotMention((string) ($payload['text'] ?? $payload['caption'] ?? $payload['message'] ?? ''));
    }

    /**
     * ตัด @ชื่อบอต ที่ Telegram เติมต่อท้ายคำสั่ง
     *
     * ในกลุ่มและในช่อง การเลือกคำสั่งจากเมนู "/" ทำให้ข้อความที่ส่งจริงเป็น
     * `/today@now_timeline_bot` — ไม่มีเครื่องมือไหนรู้จักรูปนั้น ผลคือคำสั่งที่
     * กดจากเมนู (ซึ่งเป็นวิธีที่คนใช้มากที่สุด) ตกไปหา AI ทั้งหมด
     *
     * ตัดเฉพาะที่ติดกับคำสั่งตัวแรก ไม่แตะ @ ที่อยู่กลางข้อความ ซึ่งเป็น
     * เครื่องหมายบอกสถานที่ในคำสั่งนัดหมาย
     *
     * @param string $text
     *
     * @return string
     */
    private static function stripBotMention(string $text): string
    {
        $text = trim($text);

        return (string) preg_replace('/^(\/[A-Za-z0-9_\x{0E00}-\x{0E7F}]+)@[A-Za-z0-9_]+/u', '$1', $text);
    }

    /**
     * บันทึกความล้มเหลวขาส่งออก เพราะเราตอบ 200 ไปแล้วจึงไม่มีอย่างอื่นฟ้อง
     *
     * @param \Gcms\Chat\Message $message
     * @param string               $error
     */
    private function appendDispatchError($message, string $error): void
    {
        $logDir = ROOT_PATH.DATA_FOLDER.'logs/';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        $line = json_encode([
            'at' => date('c'),
            'conversation_id' => (string) $message->conversationId,
            'text' => (string) $message->text,
            'error' => $error
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($line)) {
            @file_put_contents($logDir.'telegram-dispatch-error.log', $line.PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }

    /**
     * @param array  $payload
     * @param string $rawText
     * @param string $normalizedText
     * @param string $conversationId
     */
    private function appendIncomingLog(array $payload, string $rawText, string $normalizedText, string $conversationId): void
    {
        if ($rawText === '' && $normalizedText === '') {
            return;
        }

        $logDir = ROOT_PATH.DATA_FOLDER.'logs/';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        $chatType = '';
        $kind = 'unknown';
        if (!empty($payload['message']) && is_array($payload['message'])) {
            $kind = 'message';
            $chatType = (string) ($payload['message']['chat']['type'] ?? '');
        } elseif (!empty($payload['edited_message']) && is_array($payload['edited_message'])) {
            $kind = 'edited_message';
            $chatType = (string) ($payload['edited_message']['chat']['type'] ?? '');
        } elseif (!empty($payload['callback_query']) && is_array($payload['callback_query'])) {
            $kind = 'callback_query';
            $chatType = (string) ($payload['callback_query']['message']['chat']['type'] ?? '');
        } elseif (!empty($payload['channel_post']) && is_array($payload['channel_post'])) {
            $kind = 'channel_post';
            $chatType = (string) ($payload['channel_post']['chat']['type'] ?? '');
        } elseif (!empty($payload['edited_channel_post']) && is_array($payload['edited_channel_post'])) {
            $kind = 'edited_channel_post';
            $chatType = (string) ($payload['edited_channel_post']['chat']['type'] ?? '');
        }

        $record = [
            'at' => date('c'),
            'update_id' => $payload['update_id'] ?? null,
            'kind' => $kind,
            'chat_type' => $chatType,
            'conversation_id' => $conversationId,
            'raw_text' => $rawText,
            'normalized_text' => $normalizedText
        ];

        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($line)) {
            return;
        }

        @file_put_contents($logDir.'telegram-incoming.log', $line.PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
