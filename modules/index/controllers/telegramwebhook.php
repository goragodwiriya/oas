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
        if ($error !== '') {
            return $this->errorResponse('Failed to reply to Telegram: '.$error, 502);
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
        $secret = trim((string) (self::$cfg->telegram_webhook_secret ?? ''));
        if ($secret === '') {
            return true;
        }

        $header = trim((string) $request->getHeaderLine('x-telegram-bot-api-secret-token'));

        return $header !== '' && hash_equals($secret, $header);
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

        $update = !empty($payload['message']) && is_array($payload['message'])
            ? $payload['message']
            : (!empty($payload['edited_message']) && is_array($payload['edited_message']) ? $payload['edited_message'] : $payload);

        return trim((string) ($update['text'] ?? $update['caption'] ?? $update['message'] ?? ''));
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