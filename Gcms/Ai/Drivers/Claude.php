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

        $raw = $this->post($this->apiUrl, $payload, $headers);

        if (isset($raw['error'])) {
            $errMsg = is_array($raw['error']) ? ($raw['error']['message'] ?? json_encode($raw['error'])) : (string) $raw['error'];
            return Response::fromError($errMsg, $raw);
        }

        $r = new Response();
        $r->success = true;
        $r->raw = $raw;
        $r->model = $raw['model'] ?? $model;
        $r->content = $raw['content'][0]['text'] ?? '';
        $r->inputTokens = $raw['usage']['input_tokens'] ?? 0;
        $r->outputTokens = $raw['usage']['output_tokens'] ?? 0;

        return $r;
    }
}
