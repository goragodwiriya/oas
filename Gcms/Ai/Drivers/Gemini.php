<?php
/**
 * @filesource Gcms/Ai/Drivers/Gemini.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Gcms\Ai\Drivers;

use Gcms\Ai\Response;

/**
 * Google Gemini chat completion driver
 *
 * Uses the Gemini native generateContent API:
 *   https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent
 *
 * Free-tier models:
 *   gemini-2.0-flash, gemini-2.0-flash-lite, gemini-1.5-flash, gemini-1.5-flash-8b
 *
 * Obtain a free API key at https://aistudio.google.com/app/apikey
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Gemini extends \Gcms\Ai\Driver
{
    /**
     * Gemini API base URL (model and key appended at request time)
     *
     * @var string
     */
    protected $apiUrl = 'https://generativelanguage.googleapis.com/v1beta/models';

    /**
     * Default model
     *
     * @var string
     */
    protected $model = 'gemini-3-flash-preview';

    /**
     * Default image model.
     *
     * @var string
     */
    protected $imageModel = 'gemini-3.1-flash-image-preview';

    /**
     * Send a chat completion request to the Gemini API.
     *
     * OpenAI-style messages are converted to Gemini's content format.
     * System messages are lifted out into the top-level systemInstruction field.
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

        // Separate system message from conversation messages
        $systemText = '';
        if (!empty($options['system'])) {
            $systemText = $options['system'];
        }

        $contents = [];
        foreach ($messages as $msg) {
            if (($msg['role'] ?? '') === 'system') {
                // Gemini does not allow system role in contents; accumulate instead
                $systemText = ($systemText !== '' ? $systemText."\n" : '').$msg['content'];
                continue;
            }
            // OpenAI uses 'assistant'; Gemini uses 'model'
            $role = ($msg['role'] ?? 'user') === 'assistant' ? 'model' : 'user';
            $contents[] = [
                'role' => $role,
                'parts' => [['text' => $msg['content'] ?? '']]
            ];
        }

        $payload = [
            'contents' => $contents,
            'generationConfig' => [
                'maxOutputTokens' => $maxTokens,
                'temperature' => $temperature
            ]
        ];

        if ($systemText !== '') {
            $payload['systemInstruction'] = [
                'parts' => [['text' => $systemText]]
            ];
        }

        // API key is appended as a query parameter; no Authorization header required
        $url = $this->apiUrl.'/'.$model.':generateContent?key='.$this->apiKey;
        $raw = $this->post($url, $payload, []);

        if (isset($raw['error'])) {
            $errMsg = is_array($raw['error']) ? ($raw['error']['message'] ?? json_encode($raw['error'])) : (string) $raw['error'];
            return Response::fromError($errMsg, $raw);
        }

        $r = new Response();
        $r->success = true;
        $r->raw = $raw;
        $r->model = $model;
        $r->content = $raw['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $r->inputTokens = $raw['usageMetadata']['promptTokenCount'] ?? 0;
        $r->outputTokens = $raw['usageMetadata']['candidatesTokenCount'] ?? 0;

        return $r;
    }

    /**
     * Generate an image using the Gemini native generateContent API.
     *
     * Supported options:
     *   model, size, aspect_ratio, image_size
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
        $payload = [
            'contents' => [[
                'role' => 'user',
                'parts' => [['text' => $prompt]]
            ]],
            'generationConfig' => [
                'responseModalities' => ['IMAGE'],
                'imageConfig' => $this->imageConfig($options)
            ]
        ];

        $url = $this->apiUrl.'/'.$model.':generateContent?key='.$this->apiKey;
        $raw = $this->post($url, $payload, []);

        if (isset($raw['error'])) {
            $errMsg = is_array($raw['error']) ? ($raw['error']['message'] ?? json_encode($raw['error'])) : (string) $raw['error'];
            return Response::fromError($errMsg, $raw);
        }

        $images = [];
        $texts = [];
        foreach ($raw['candidates'] ?? [] as $candidate) {
            foreach ($candidate['content']['parts'] ?? [] as $part) {
                if (!empty($part['thought'])) {
                    continue;
                }
                if (!empty($part['text'])) {
                    $texts[] = (string) $part['text'];
                }

                $inlineData = [];
                if (!empty($part['inlineData']) && is_array($part['inlineData'])) {
                    $inlineData = $part['inlineData'];
                } elseif (!empty($part['inline_data']) && is_array($part['inline_data'])) {
                    $inlineData = $part['inline_data'];
                }

                if (!empty($inlineData['data'])) {
                    $images[] = [
                        'url' => '',
                        'b64_json' => (string) $inlineData['data'],
                        'mime_type' => isset($inlineData['mimeType']) && $inlineData['mimeType'] !== ''
                            ? (string) $inlineData['mimeType']
                            : (isset($inlineData['mime_type']) && $inlineData['mime_type'] !== '' ? (string) $inlineData['mime_type'] : 'image/png'),
                        'revised_prompt' => ''
                    ];
                }
            }
        }

        if (empty($images)) {
            $message = $raw['candidates'][0]['finishMessage'] ?? $raw['candidates'][0]['finishReason'] ?? $raw['promptFeedback']['blockReason'] ?? 'AI image generation returned no images.';

            return Response::fromError((string) $message, $raw);
        }

        $r = new Response();
        $r->success = true;
        $r->raw = $raw;
        $r->model = $raw['modelVersion'] ?? $model;
        $r->content = trim(implode("\n\n", array_filter($texts)));
        $r->images = $images;
        $r->inputTokens = $raw['usageMetadata']['promptTokenCount'] ?? 0;
        $r->outputTokens = $raw['usageMetadata']['candidatesTokenCount'] ?? 0;

        return $r;
    }

    /**
     * Normalize image generation options to Gemini imageConfig.
     *
     * @param array $options
     *
     * @return array
     */
    private function imageConfig(array $options)
    {
        $size = isset($options['size']) && $options['size'] !== '' ? strtolower((string) $options['size']) : '1024x1024';
        $aspectRatio = isset($options['aspect_ratio']) && $options['aspect_ratio'] !== '' ? (string) $options['aspect_ratio'] : '1:1';
        $imageSize = isset($options['image_size']) && $options['image_size'] !== '' ? strtoupper((string) $options['image_size']) : '1K';

        if (empty($options['aspect_ratio'])) {
            if ($size === '1536x1024') {
                $aspectRatio = '3:2';
            } elseif ($size === '1024x1536') {
                $aspectRatio = '2:3';
            }
        }

        return [
            'aspectRatio' => $aspectRatio,
            'imageSize' => $imageSize
        ];
    }
}
