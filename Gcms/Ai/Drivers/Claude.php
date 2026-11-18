<?php
/**
 * @filesource Gcms/Ai/Drivers/Claude.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Gcms\Ai\Drivers;

use Gcms\Ai\Response;

/**
 * Anthropic Claude chat completion driver
 *
 * Uses the Anthropic Messages API:
 *   https://api.anthropic.com/v1/messages
 *
 * API reference: https://docs.anthropic.com/en/api/messages
 *
 * Available models (as of 2026):
 *   claude-opus-4-5, claude-sonnet-4-5, claude-haiku-3-5
 *   claude-opus-4, claude-sonnet-4, claude-haiku-3
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Claude extends \Gcms\Ai\Driver
{
    /**
     * Anthropic Messages API endpoint
     *
     * @var string
     */
    protected $apiUrl = 'https://api.anthropic.com/v1/messages';

    /**
     * Default model
     *
     * @var string
     */
    protected $model = 'claude-haiku-3-5';

    /**
     * Anthropic API version header value
     *
     * @var string
     */
    protected $anthropicVersion = '2023-06-01';

    /**
     * Send a chat completion request to the Claude API.
     *
     * The system prompt must NOT appear in the messages array for Claude;
     * it is extracted and sent as a top-level field instead.
     *
     * Supported extra options:
     *   model, max_tokens, temperature, system (system prompt string)
     *
     * @param array $messages OpenAI-format messages array
     * @param array $options  Per-call overrides
     *
     * @return Response
     */
    public function chat(array $messages, array $options = [])
    {
        $request = $this->buildChatRequest($messages, $options);
        $raw = $this->post($request['url'], $request['payload'], $request['headers']);

        return $this->parseChatResponse($raw, $this->lastHttpStatus, $request['model']);
    }

    /**
     * Build the Messages API request without sending it.
     *
     * @param array $messages
     * @param array $options
     *
     * @return array ['url' => string, 'payload' => array, 'headers' => array, 'model' => string]
     */
    protected function buildChatRequest(array $messages, array $options = [])
    {
        $model = isset($options['model']) && $options['model'] !== '' ? $options['model'] : $this->model;
        $maxTokens = isset($options['max_tokens']) ? (int) $options['max_tokens'] : $this->maxTokens;
        $temperature = isset($options['temperature']) ? (float) $options['temperature'] : $this->temperature;

        // Extract system prompt — Claude requires it at the top level, not in messages
        $systemText = '';
        if (!empty($options['system'])) {
            $systemText = $options['system'];
        }

        $msgs = [];
        foreach ($messages as $msg) {
            if (($msg['role'] ?? '') === 'system') {
                $systemText = ($systemText !== '' ? $systemText."\n" : '').$msg['content'];
                continue;
            }
            $msgs[] = [
                'role' => $msg['role'] ?? 'user',
                'content' => $msg['content'] ?? ''
            ];
        }

        $payload = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'temperature' => $temperature,
            'messages' => $msgs
        ];

        if ($systemText !== '') {
            $payload['system'] = $systemText;
        }

        $headers = [
            'x-api-key' => $this->apiKey,
            'anthropic-version' => $this->anthropicVersion
        ];

        return [
            'url' => $this->apiUrl,
            'payload' => $payload,
            'headers' => $headers,
            'model' => $model
        ];
    }

    /**
     * Turn a Messages API payload into a Response.
     *
     * @param array  $raw
     * @param int    $status
     * @param string $model
     *
     * @return Response
     */
    protected function parseChatResponse(array $raw, $status, $model)
    {
        if (isset($raw['error'])) {
            $errMsg = is_array($raw['error']) ? ($raw['error']['message'] ?? json_encode($raw['error'])) : (string) $raw['error'];
            return $this->errorResponse($errMsg, $raw, $status);
        }

        $r = new Response();
        $r->success = true;
        $r->raw = $raw;
        $r->model = $raw['model'] ?? $model;
        $r->content = $raw['content'][0]['text'] ?? '';
        $r->inputTokens = $raw['usage']['input_tokens'] ?? 0;
        $r->outputTokens = $raw['usage']['output_tokens'] ?? 0;
        // Anthropic uses 'max_tokens' as stop_reason when truncated; normalise to 'length'.
        $r->finishReason = ($raw['stop_reason'] ?? 'end_turn') === 'max_tokens' ? 'length' : 'stop';

        return $r;
    }

    /**
     * Stream a chat completion via the Anthropic Messages streaming API.
     * Event types are distinguished by the `type` field in each SSE data line.
     *
     * @param array         $messages
     * @param array         $options
     * @param callable|null $onDelta  function(string $text): void
     *
     * @return Response
     */
    public function streamChat(array $messages, array $options = [],  ? callable $onDelta = null)
    {
        $model = isset($options['model']) && $options['model'] !== '' ? $options['model'] : $this->model;
        $maxTokens = isset($options['max_tokens']) ? (int) $options['max_tokens'] : $this->maxTokens;
        $temperature = isset($options['temperature']) ? (float) $options['temperature'] : $this->temperature;

        $systemText = '';
        if (!empty($options['system'])) {
            $systemText = $options['system'];
        }

        $msgs = [];
        foreach ($messages as $msg) {
            if (($msg['role'] ?? '') === 'system') {
                $systemText = ($systemText !== '' ? $systemText."\n" : '').$msg['content'];
                continue;
            }
            $msgs[] = [
                'role' => $msg['role'] ?? 'user',
                'content' => $msg['content'] ?? ''
            ];
        }

        $payload = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'temperature' => $temperature,
            'messages' => $msgs,
            'stream' => true
        ];
        if ($systemText !== '') {
            $payload['system'] = $systemText;
        }

        $headers = [
            'x-api-key' => $this->apiKey,
            'anthropic-version' => $this->anthropicVersion
        ];

        $content = '';
        $modelSeen = $model;
        $inputTokens = 0;
        $outputTokens = 0;
        $finishReason = 'stop';

        $result = $this->streamSse($this->apiUrl, $payload, $headers, function ($data) use (&$content, &$modelSeen, &$inputTokens, &$outputTokens, &$finishReason, $onDelta) {
            $json = json_decode($data, true);
            if (!is_array($json) || !isset($json['type'])) {
                return;
            }
            switch ($json['type']) {
            case 'message_start' :
                $modelSeen = $json['message']['model'] ?? $modelSeen;
                $inputTokens = (int) ($json['message']['usage']['input_tokens'] ?? $inputTokens);
                $outputTokens = (int) ($json['message']['usage']['output_tokens'] ?? $outputTokens);
                break;
            case 'content_block_delta':
                if (($json['delta']['type'] ?? '') === 'text_delta') {
                    $text = (string) ($json['delta']['text'] ?? '');
                    if ($text !== '') {
                        $content .= $text;
                        if ($onDelta !== null) {
                            $onDelta($text);
                        }
                    }
                }
                break;
            case 'message_delta':
                // Cumulative output token count is reported here.
                if (isset($json['usage']['output_tokens'])) {
                    $outputTokens = (int) $json['usage']['output_tokens'];
                }
                // Anthropic signals token truncation via stop_reason 'max_tokens'.
                if (($json['delta']['stop_reason'] ?? '') === 'max_tokens') {
                    $finishReason = 'length';
                }
                break;
            }
        });

        if (isset($result['error'])) {
            return $this->errorResponse($result['error'], [], $result['status']);
        }
        if (($result['status'] ?? 0) >= 400) {
            $raw = isset($result['error_body']) ? (json_decode($result['error_body'], true) ?: []): [];
            $errMsg = $raw['error']['message'] ?? 'AI provider returned HTTP '.$result['status'];
            return $this->errorResponse($errMsg, $raw, $result['status']);
        }

        $r = new Response();
        $r->success = true;
        $r->model = $modelSeen;
        $r->content = $content;
        $r->inputTokens = $inputTokens;
        $r->outputTokens = $outputTokens;
        $r->finishReason = $finishReason;

        return $r;
    }
}
