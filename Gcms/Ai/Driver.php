<?php
/**
 * @filesource Gcms/Ai/Driver.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Gcms\Ai;

/**
 * Abstract AI driver base class
 *
 * All provider-specific drivers extend this class.
 * Configuration is read from self::$cfg (set by KBase) and can be
 * overridden per-instance via the $config array passed to __construct().
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
abstract class Driver extends \Kotchasan\KBase
{
    /**
     * Provider identifier from Gcms\Ai::driver().
     *
     * @var string
     */
    protected $provider = '';

    /**
     * API key for authentication (empty for local models)
     *
     * @var string
     */
    protected $apiKey = '';

    /**
     * API endpoint URL
     * Each concrete driver sets its own default; can be overridden for
     * local models such as Ollama (http://localhost:11434/v1) or
     * LM Studio (http://localhost:1234/v1).
     *
     * @var string
     */
    protected $apiUrl = '';

    /**
     * Model identifier to use for requests
     *
     * @var string
     */
    protected $model = '';

    /**
     * Maximum tokens to generate in a single response
     *
     * @var int
     */
    protected $maxTokens = 1024;

    /**
     * Sampling temperature (0.0–2.0; lower = more deterministic)
     *
     * @var float
     */
    protected $temperature = 0.7;

    /**
     * Initialise the driver, merging global config with per-call overrides.
     *
     * Supported keys in $config:
     *   api_key, api_url, model, max_tokens, temperature
     *
     * @param array $config Per-instance overrides
     */
    public function __construct(array $config = [])
    {
        if (!empty($config['provider'])) {
            $this->provider = (string) $config['provider'];
        }
        if (!empty(self::$cfg->ai_api_key)) {
            $this->apiKey = self::$cfg->ai_api_key;
        }
        if (!empty(self::$cfg->ai_api_url)) {
            $this->apiUrl = self::$cfg->ai_api_url;
        }
        if (!empty(self::$cfg->ai_model)) {
            $this->model = self::$cfg->ai_model;
        }
        if (!empty(self::$cfg->ai_max_tokens)) {
            $this->maxTokens = (int) self::$cfg->ai_max_tokens;
        }
        if (isset(self::$cfg->ai_temperature)) {
            $this->temperature = (float) self::$cfg->ai_temperature;
        }
        // Per-instance overrides take priority over global config
        if (!empty($config['api_key'])) {
            $this->apiKey = $config['api_key'];
        }
        if (!empty($config['api_url'])) {
            $this->apiUrl = $config['api_url'];
        }
        if (!empty($config['model'])) {
            $this->model = $config['model'];
        }
        if (!empty($config['max_tokens'])) {
            $this->maxTokens = (int) $config['max_tokens'];
        }
        if (isset($config['temperature'])) {
            $this->temperature = (float) $config['temperature'];
        }
    }

    /**
     * Send a chat completion request to the provider.
     *
     * $messages follows the OpenAI messages format:
     *   [['role' => 'user', 'content' => '...']]
     *   ['role' => 'assistant', 'content' => '...']
     *   ['role' => 'system', 'content' => '...']   (optional; handled per-driver)
     *
     * Supported keys in $options:
     *   model, max_tokens, temperature, system (system prompt string)
     *
     * @param array $messages Conversation history
     * @param array $options  Per-call overrides
     *
     * @return Response
     */
    abstract public function chat(array $messages, array $options = []);

    /**
     * Generate image output from a prompt.
     *
     * Drivers that do not support images may inherit this default implementation.
     * Supported keys in $options depend on the provider and may include:
     *   model, size, count
     *
     * @param string $prompt  Image prompt
     * @param array  $options Per-call overrides
     *
     * @return Response
     */
    public function generateImage($prompt, array $options = [])
    {
        return Response::fromError('Image generation is not supported by the current AI provider.');
    }

    /**
     * Extract and validate an effective option value, falling back to the
     * instance property then to a hard default.
     *
     * @param array  $options  Per-call options array
     * @param string $key      Option key to look up
     * @param mixed  $default  Hard default when the property is also empty
     *
     * @return mixed
     */
    protected function option(array $options, $key, $default)
    {
        if (isset($options[$key]) && $options[$key] !== '') {
            return $options[$key];
        }
        $prop = str_replace('_', '', $key);
        if (!empty($this->$prop)) {
            return $this->$prop;
        }
        return $default;
    }

    /**
     * Send a JSON POST request and return the decoded response array.
     *
     * On cURL error or non-JSON body, returns an array with key 'error'.
     *
     * @param string $url     Full endpoint URL
     * @param array  $payload Data to JSON-encode and POST
     * @param array  $headers HTTP headers (key => value)
     *
     * @return array Decoded JSON or ['error' => '...']
     */
    protected function post($url, array $payload, array $headers)
    {
        $ch = new \Kotchasan\Curl();
        $ch->setOptions([CURLOPT_TIMEOUT => 60]);
        $ch->setHeaders(array_merge([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json'
        ], $headers));
        $body = $ch->post($url, json_encode($payload));
        if ($ch->error() !== 0) {
            return ['error' => $ch->errorMessage()];
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return ['error' => 'Invalid JSON response: '.substr($body, 0, 200)];
        }
        return $decoded;
    }
}
