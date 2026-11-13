<?php
/**
 * @filesource Gcms/Ai/Drivers/DeepSeek.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Gcms\Ai\Drivers;

use Gcms\Ai\Response;

/**
 * DeepSeek V4 chat driver (OpenAI-compatible + thinking mode).
 *
 * @see https://api-docs.deepseek.com/guides/thinking_mode
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class DeepSeek extends \Gcms\Ai\Driver
{
    /**
     * Legacy model IDs retired after 2026-07-24.
     *
     * @var array<string, array{model:string,thinking:bool}>
     */
    private static $legacyModels = [
        'deepseek-chat' => [
            'model' => 'deepseek-v4-flash',
            'thinking' => false
        ],
        'deepseek-reasoner' => [
            'model' => 'deepseek-v4-flash',
            'thinking' => true
        ]
    ];

    /**
     * @var string
     */
    protected $apiUrl = 'https://api.deepseek.com/v1';

    /**
     * @var string
     */
    protected $model = 'deepseek-v4-flash';

    /**
     * Enable chain-of-thought thinking mode.
     *
     * @var bool
     */
    protected $thinkingEnabled = false;

    /**
     * Thinking effort: high or max.
     *
     * @var string
     */
    protected $reasoningEffort = 'high';

    /**
     * @param array $config
     */
    public function __construct(array $config = [])
    {
        parent::__construct($config);

        if (isset($config['thinking_enabled'])) {
            $this->thinkingEnabled = !empty($config['thinking_enabled']);
        }
        if (!empty($config['reasoning_effort'])) {
            $this->reasoningEffort = self::normalizeEffort($config['reasoning_effort']);
        }

        $resolved = self::resolveModel($this->model, $this->thinkingEnabled);
        $this->model = $resolved['model'];
        if ($resolved['thinking']) {
            $this->thinkingEnabled = true;
        }
    }

    /**
     * Normalize a model name and infer thinking mode from legacy IDs.
     *
     * @param string $model
     * @param bool   $thinkingEnabled
     *
     * @return array{model:string,thinking:bool}
     */
    public static function resolveModel($model, $thinkingEnabled = false)
    {
        $model = trim((string) $model);
        if ($model !== '' && isset(self::$legacyModels[$model])) {
            return self::$legacyModels[$model];
        }

        return [
            'model' => $model !== '' ? $model : 'deepseek-v4-flash',
            'thinking' => (bool) $thinkingEnabled
        ];
    }

    /**
     * @param mixed $effort
     *
     * @return string
     */
    public static function normalizeEffort($effort)
    {
        $effort = strtolower(trim((string) $effort));

        return $effort === 'max' ? 'max' : 'high';
    }

    /**
     * Send a chat completion request.
     *
     * Supported options:
     *   model, max_tokens, temperature, system,
     *   thinking (bool), thinking_enabled (bool), reasoning_effort (high|max)
     *
     * @param array $messages
     * @param array $options
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
     * Build the /chat/completions request without sending it.
     *
     * @param array $messages
     * @param array $options
     *
     * @return array ['url' => string, 'payload' => array, 'headers' => array, 'model' => string]
     */
    protected function buildChatRequest(array $messages, array $options = [])
    {
        $model = trim((string) $this->option($options, 'model', $this->model));
        $maxTokens = (int) $this->option($options, 'max_tokens', $this->maxTokens);
        $temperature = (float) $this->option($options, 'temperature', $this->temperature);
        $thinkingEnabled = $this->resolveThinkingEnabled($options, $model);

        $resolved = self::resolveModel($model, $thinkingEnabled);
        $model = $resolved['model'];
        $thinkingEnabled = $resolved['thinking'] || $thinkingEnabled;
        $reasoningEffort = self::normalizeEffort(
            $this->option($options, 'reasoning_effort', $this->reasoningEffort)
        );

        $msgs = self::sanitizeMessages($messages);
        if (!empty($options['system'])) {
            array_unshift($msgs, ['role' => 'system', 'content' => (string) $options['system']]);
        }

        $payload = [
            'model' => $model,
            'messages' => $msgs,
            'max_tokens' => $maxTokens
        ];

        if ($thinkingEnabled) {
            $payload['thinking'] = ['type' => 'enabled'];
            $payload['reasoning_effort'] = $reasoningEffort;
        } else {
            $payload['temperature'] = $temperature;
        }

        return [
            'url' => $this->apiUrl.'/chat/completions',
            'payload' => $payload,
            'headers' => ['Authorization' => 'Bearer '.$this->apiKey],
            'model' => $model
        ];
    }

    /**
     * Turn a /chat/completions payload into a Response.
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

        $message = $raw['choices'][0]['message'] ?? [];

        $r = new Response();
        $r->success = true;
        $r->raw = $raw;
        $r->model = $raw['model'] ?? $model;
        $r->content = isset($message['content']) ? (string) $message['content'] : '';
        $r->reasoningContent = isset($message['reasoning_content']) ? (string) $message['reasoning_content'] : '';
        $r->inputTokens = $raw['usage']['prompt_tokens'] ?? $raw['usage']['input_tokens'] ?? 0;
        $r->outputTokens = $raw['usage']['completion_tokens'] ?? $raw['usage']['output_tokens'] ?? 0;
        $r->finishReason = $raw['choices'][0]['finish_reason'] ?? 'stop';

        return $r;
    }

    /**
     * Stream a chat completion via SSE. Accumulates chain-of-thought into
     * reasoningContent; only visible content fragments are sent to $onDelta.
     *
     * @param array         $messages
     * @param array         $options
     * @param callable|null $onDelta  function(string $text): void
     *
     * @return Response
     */
    public function streamChat(array $messages, array $options = [],  ? callable $onDelta = null)
    {
        $model = trim((string) $this->option($options, 'model', $this->model));
        $maxTokens = (int) $this->option($options, 'max_tokens', $this->maxTokens);
        $temperature = (float) $this->option($options, 'temperature', $this->temperature);
        $thinkingEnabled = $this->resolveThinkingEnabled($options, $model);

        $resolved = self::resolveModel($model, $thinkingEnabled);
        $model = $resolved['model'];
        $thinkingEnabled = $resolved['thinking'] || $thinkingEnabled;
        $reasoningEffort = self::normalizeEffort(
            $this->option($options, 'reasoning_effort', $this->reasoningEffort)
        );

        $msgs = self::sanitizeMessages($messages);
        if (!empty($options['system'])) {
            array_unshift($msgs, ['role' => 'system', 'content' => (string) $options['system']]);
        }

        $payload = [
            'model' => $model,
            'messages' => $msgs,
            'max_tokens' => $maxTokens,
            'stream' => true,
            'stream_options' => ['include_usage' => true]
        ];
        if ($thinkingEnabled) {
            $payload['thinking'] = ['type' => 'enabled'];
            $payload['reasoning_effort'] = $reasoningEffort;
        } else {
            $payload['temperature'] = $temperature;
        }

        $headers = ['Authorization' => 'Bearer '.$this->apiKey];

        $content = '';
        $reasoning = '';
        $modelSeen = $model;
        $inputTokens = 0;
        $outputTokens = 0;
        $finishReason = 'stop';

        $result = $this->streamSse($this->apiUrl.'/chat/completions', $payload, $headers, function ($data) use (&$content, &$reasoning, &$modelSeen, &$inputTokens, &$outputTokens, &$finishReason, $onDelta) {
            if ($data === '[DONE]') {
                return;
            }
            $json = json_decode($data, true);
            if (!is_array($json)) {
                return;
            }
            if (!empty($json['model'])) {
                $modelSeen = $json['model'];
            }
            $delta = $json['choices'][0]['delta'] ?? [];
            if (isset($delta['reasoning_content']) && $delta['reasoning_content'] !== '') {
                $reasoning .= (string) $delta['reasoning_content'];
            }
            if (isset($delta['content']) && $delta['content'] !== '' && $delta['content'] !== null) {
                $content .= $delta['content'];
                if ($onDelta !== null) {
                    $onDelta((string) $delta['content']);
                }
            }
            if (isset($json['usage']['prompt_tokens'])) {
                $inputTokens = (int) $json['usage']['prompt_tokens'];
            }
            if (isset($json['usage']['completion_tokens'])) {
                $outputTokens = (int) $json['usage']['completion_tokens'];
            }
            $fr = $json['choices'][0]['finish_reason'] ?? null;
            if ($fr !== null && $fr !== '') {
                $finishReason = $fr;
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
        $r->reasoningContent = $reasoning;
        $r->inputTokens = $inputTokens;
        $r->outputTokens = $outputTokens;
        $r->finishReason = $finishReason;

        return $r;
    }

    /**
     * @param array  $options
     * @param string $model
     *
     * @return bool
     */
    private function resolveThinkingEnabled(array $options, $model)
    {
        if (array_key_exists('thinking', $options)) {
            return !empty($options['thinking']);
        }
        if (array_key_exists('thinking_enabled', $options)) {
            return !empty($options['thinking_enabled']);
        }
        if ($model !== '' && isset(self::$legacyModels[$model])) {
            return self::$legacyModels[$model]['thinking'];
        }

        return $this->thinkingEnabled;
    }

    /**
     * Remove reasoning_content from prior assistant turns — DeepSeek rejects it on input.
     *
     * @param array $messages
     *
     * @return array
     */
    private static function sanitizeMessages(array $messages)
    {
        $clean = [];
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }
            $item = $message;
            unset($item['reasoning_content']);
            $clean[] = $item;
        }

        return $clean;
    }
}
