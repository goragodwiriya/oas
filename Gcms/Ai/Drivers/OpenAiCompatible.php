<?php
/**
 * @filesource Gcms/Ai/Drivers/OpenAiCompatible.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Gcms\Ai\Drivers;

use Gcms\Ai\Response;

/**
 * OpenAI-compatible chat completion driver
 *
 * Works with any provider that implements the OpenAI /v1/chat/completions API:
 *   - OpenAI       https://api.openai.com/v1
 *   - Groq         https://api.groq.com/openai/v1  (free tier)
 *   - OpenRouter   https://openrouter.ai/api/v1    (free models available)
 *   - Vercel       https://ai-gateway.vercel.sh/v1
 *   - NVIDIA NIM   https://integrate.api.nvidia.com/v1
 *   - Zhipu GLM    https://api.z.ai/api/paas/v4           (glm-5.2 thinking mode)
 *   - Ollama       http://localhost:11434/v1        (local, no API key)
 *   - LM Studio    http://localhost:1234/v1         (local, no API key)
 *
 * To use a non-OpenAI provider set ai_api_url (and ai_api_key if required)
 * in Gcms\Config or pass 'api_url' / 'api_key' in the $config array.
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class OpenAiCompatible extends \Gcms\Ai\Driver
{
    /**
     * Default endpoint — overridden per-provider via ai_api_url config
     *
     * @var string
     */
    protected $apiUrl = 'https://api.openai.com/v1';

    /**
     * Default model
     *
     * @var string
     */
    protected $model = 'gpt-4o-mini';

    /**
     * Default image model for OpenAI image generation.
     *
     * @var string
     */
    protected $imageModel = 'gpt-image-1';

    /**
     * Enable chain-of-thought thinking mode (GLM provider only).
     *
     * @var bool
     */
    protected $thinkingEnabled = false;

    /**
     * Thinking effort: high or max (GLM provider only).
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
        if ($this->provider === 'glm') {
            if (isset($config['thinking_enabled'])) {
                $this->thinkingEnabled = !empty($config['thinking_enabled']);
            }
            if (!empty($config['reasoning_effort'])) {
                $effort = strtolower(trim((string) $config['reasoning_effort']));
                $this->reasoningEffort = $effort === 'max' ? 'max' : 'high';
            }
        }
    }

    /**
     * Send a chat completion request.
     *
     * Supported extra options:
     *   model, max_tokens, temperature, system (system prompt string)
     *   thinking_enabled (bool, GLM only), reasoning_effort (high|max, GLM only)
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
     * Build the /chat/completions request without sending it.
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

        // Prepend system message if provided via options and not already in messages
        $msgs = $messages;
        if (!empty($options['system'])) {
            array_unshift($msgs, ['role' => 'system', 'content' => $options['system']]);
        }

        $thinkingEnabled = $this->provider === 'glm'
            ? (!empty($options['thinking_enabled']) || $this->thinkingEnabled)
            : false;
        $reasoningEffort = !empty($options['reasoning_effort'])
            ? (strtolower(trim((string) $options['reasoning_effort'])) === 'max' ? 'max' : 'high')
            : $this->reasoningEffort;

        $payload = [
            'model' => $model,
            'messages' => $msgs,
            'max_tokens' => $maxTokens
        ];

        if ($thinkingEnabled) {
            // GLM thinking mode: temperature must be omitted
            $payload['thinking'] = ['type' => 'enabled'];
            $payload['reasoning_effort'] = $reasoningEffort;
        } else {
            $payload['temperature'] = $temperature;
        }

        // Some OpenRouter-routed models prefer max_completion_tokens.
        if (strpos($this->apiUrl, 'openrouter.ai') !== false) {
            $payload['max_completion_tokens'] = $maxTokens;
        }

        $headers = ['Authorization' => 'Bearer '.$this->apiKey];

        // OpenRouter requires these headers for rate-limit accountability
        if (strpos($this->apiUrl, 'openrouter.ai') !== false) {
            $headers['HTTP-Referer'] = isset(self::$cfg->web_url) ? self::$cfg->web_url : '';
            $headers['X-Title'] = isset(self::$cfg->web_title) ? self::$cfg->web_title : '';
        }

        return [
            'url' => $this->apiUrl.'/chat/completions',
            'payload' => $payload,
            'headers' => $headers,
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
        $r->inputTokens = $raw['usage']['prompt_tokens'] ?? 0;
        $r->outputTokens = $raw['usage']['completion_tokens'] ?? 0;
        $r->finishReason = $raw['choices'][0]['finish_reason'] ?? 'stop';

        return $r;
    }

    /**
     * Stream a chat completion via SSE (OpenAI /v1/chat/completions stream=true).
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

        $msgs = $messages;
        if (!empty($options['system'])) {
            array_unshift($msgs, ['role' => 'system', 'content' => $options['system']]);
        }

        $payload = [
            'model' => $model,
            'messages' => $msgs,
            'max_tokens' => $maxTokens,
            'temperature' => $temperature,
            'stream' => true,
            // Ask for usage in the final chunk; OpenAI omits usage otherwise.
            'stream_options' => ['include_usage' => true]
        ];

        // Some OpenRouter-routed models prefer max_completion_tokens.
        if (strpos($this->apiUrl, 'openrouter.ai') !== false) {
            $payload['max_completion_tokens'] = $maxTokens;
        }

        $headers = ['Authorization' => 'Bearer '.$this->apiKey];
        if (strpos($this->apiUrl, 'openrouter.ai') !== false) {
            $headers['HTTP-Referer'] = isset(self::$cfg->web_url) ? self::$cfg->web_url : '';
            $headers['X-Title'] = isset(self::$cfg->web_title) ? self::$cfg->web_title : '';
        }

        $content = '';
        $modelSeen = $model;
        $inputTokens = 0;
        $outputTokens = 0;
        $finishReason = 'stop';

        $result = $this->streamSse($this->apiUrl.'/chat/completions', $payload, $headers, function ($data) use (&$content, &$modelSeen, &$inputTokens, &$outputTokens, &$finishReason, $onDelta) {
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
            $delta = $json['choices'][0]['delta']['content'] ?? '';
            if ($delta !== '' && $delta !== null) {
                $content .= $delta;
                if ($onDelta !== null) {
                    $onDelta($delta);
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
        $r->inputTokens = $inputTokens;
        $r->outputTokens = $outputTokens;
        $r->finishReason = $finishReason;

        return $r;
    }

    /**
     * Probe availability via the free GET /models endpoint (no token cost).
     *
     * @return array{ok:bool,latencyMs:int,httpStatus:int,errorType:string,error:string}
     */
    public function healthCheck()
    {
        $start = microtime(true);
        $raw = $this->get($this->apiUrl.'/models', ['Authorization' => 'Bearer '.$this->apiKey]);
        $latency = (int) round((microtime(true) - $start) * 1000);
        $status = $this->lastHttpStatus;

        if (isset($raw['error']) || $status === 0 || $status >= 400) {
            $class = $this->classifyError($status, is_array($raw) ? $raw : []);
            $message = $raw['error']['message'] ?? (is_string($raw['error'] ?? null) ? $raw['error'] : 'Health check failed');
            return [
                'ok' => false,
                'latencyMs' => $latency,
                'httpStatus' => $status,
                'errorType' => $class['type'],
                'error' => (string) $message
            ];
        }

        return [
            'ok' => true,
            'latencyMs' => $latency,
            'httpStatus' => $status,
            'errorType' => '',
            'error' => ''
        ];
    }

    /**
     * Generate one or more images
     *
     * Supported options:
     *   model, size, count
     *
     * @param string $prompt
     * @param array  $options
     *
     * @return Response
     */
    public function generateImage($prompt, array $options = [])
    {
        $prompt = trim((string) $prompt);
        if ($prompt === '') {
            return Response::fromError('Image prompt is required.');
        }

        $model = isset($options['model']) && $options['model'] !== '' ? (string) $options['model'] : $this->imageModel;
        $size = isset($options['size']) && $options['size'] !== '' ? (string) $options['size'] : '1024x1024';
        $count = isset($options['count']) && (int) $options['count'] > 0 ? min(4, (int) $options['count']) : 1;

        $payload = [
            'model' => $model,
            'prompt' => $prompt,
            'n' => $count,
            'size' => $size
        ];

        $headers = ['Authorization' => 'Bearer '.$this->apiKey];
        $raw = $this->post($this->apiUrl.'/images/generations', $payload, $headers);

        if (isset($raw['error'])) {
            $errMsg = is_array($raw['error']) ? ($raw['error']['message'] ?? json_encode($raw['error'])) : (string) $raw['error'];
            return $this->errorResponse($errMsg, $raw);
        }

        $images = [];
        foreach ($raw['data'] ?? [] as $item) {
            $url = isset($item['url']) ? (string) $item['url'] : '';
            $b64 = isset($item['b64_json']) ? (string) $item['b64_json'] : '';

            if ($url === '' && $b64 === '') {
                continue;
            }

            $images[] = [
                'url' => $url,
                'b64_json' => $b64,
                'mime_type' => isset($item['mime_type']) && $item['mime_type'] !== '' ? (string) $item['mime_type'] : 'image/png',
                'revised_prompt' => isset($item['revised_prompt']) ? (string) $item['revised_prompt'] : ''
            ];
        }

        if (empty($images)) {
            return Response::fromError('AI image generation returned no images.', $raw);
        }

        $r = new Response();
        $r->success = true;
        $r->raw = $raw;
        $r->model = $raw['model'] ?? $model;
        $r->images = $images;
        $r->inputTokens = $raw['usage']['prompt_tokens'] ?? $raw['usage']['input_tokens'] ?? 0;
        $r->outputTokens = $raw['usage']['completion_tokens'] ?? $raw['usage']['output_tokens'] ?? 0;

        return $r;
    }
}
