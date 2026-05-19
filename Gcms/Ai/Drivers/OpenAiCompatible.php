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
     * Send a chat completion request.
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

        // Prepend system message if provided via options and not already in messages
        $msgs = $messages;
        if (!empty($options['system'])) {
            array_unshift($msgs, ['role' => 'system', 'content' => $options['system']]);
        }

        $payload = [
            'model' => $model,
            'messages' => $msgs,
            'max_tokens' => $maxTokens,
            'temperature' => $temperature
        ];

        $headers = ['Authorization' => 'Bearer '.$this->apiKey];

        // OpenRouter requires these headers for rate-limit accountability
        if (strpos($this->apiUrl, 'openrouter.ai') !== false) {
            $headers['HTTP-Referer'] = isset(self::$cfg->web_url) ? self::$cfg->web_url : '';
            $headers['X-Title'] = isset(self::$cfg->web_title) ? self::$cfg->web_title : '';
        }

        $raw = $this->post($this->apiUrl.'/chat/completions', $payload, $headers);

        if (isset($raw['error'])) {
            $errMsg = is_array($raw['error']) ? ($raw['error']['message'] ?? json_encode($raw['error'])) : (string) $raw['error'];
            return Response::fromError($errMsg, $raw);
        }

        $r = new Response();
        $r->success = true;
        $r->raw = $raw;
        $r->model = $raw['model'] ?? $model;
        $r->content = $raw['choices'][0]['message']['content'] ?? '';
        $r->inputTokens = $raw['usage']['prompt_tokens'] ?? 0;
        $r->outputTokens = $raw['usage']['completion_tokens'] ?? 0;

        return $r;
    }

    /**
     * Generate one or more images using the OpenAI Images API.
     *
     * In this build, image generation is enabled only for the OpenAI provider.
     * Other OpenAI-compatible providers can be added later once their response
     * shapes and supported models are verified in-source.
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
        if ($this->provider !== 'openai') {
            return Response::fromError('Image generation is currently supported only with the OpenAI provider.');
        }

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
            return Response::fromError($errMsg, $raw);
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
