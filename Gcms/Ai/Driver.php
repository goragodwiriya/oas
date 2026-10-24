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
     * HTTP status code of the most recent provider request (0 if none/transport error).
     * Populated by post() and streamSse() so error responses can be classified.
     *
     * @var int
     */
    protected $lastHttpStatus = 0;

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
     * Stream a chat completion, invoking $onDelta for each text fragment as it
     * arrives, and returning a final Response with the full content and usage.
     *
     * The default implementation falls back to the non-streaming chat() and
     * emits the whole content as a single delta, so every driver works even
     * before it implements native streaming. Drivers SHOULD override this with
     * true Server-Sent-Events streaming.
     *
     * @param array         $messages Conversation history (OpenAI format)
     * @param array         $options  Per-call overrides (same keys as chat())
     * @param callable|null $onDelta  function(string $text): void — called per fragment
     *
     * @return Response Final aggregated response
     */
    public function streamChat(array $messages, array $options = [],  ? callable $onDelta = null)
    {
        $response = $this->chat($messages, $options);
        if ($onDelta !== null && $response->success && $response->content !== '') {
            $onDelta($response->content);
        }
        return $response;
    }

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
        // SSRF guard: only http/https, and never the cloud-metadata range.
        $urlError = self::validateRequestUrl($url);
        if ($urlError !== null) {
            return ['error' => $urlError];
        }

        $ch = $this->jsonCurl($url, $headers);
        $body = $ch->post($url, json_encode($payload));
        $this->lastHttpStatus = $ch->httpStatus();

        return self::decodeBody($url, $body, $ch->error(), $ch->errorMessage());
    }

    /**
     * Send several chat requests at once and return one Response per call.
     *
     * Each call is ['messages' => [...], 'options' => [...]] with the same
     * meaning as chat(). Drivers that expose buildChatRequest() and
     * parseChatResponse() run up to $concurrency requests in parallel; any other
     * driver falls back to calling chat() one at a time.
     *
     * @param array $calls       [key => ['messages' => array, 'options' => array]]
     * @param int   $concurrency Maximum simultaneous requests
     *
     * @return Response[] Keyed like $calls, in the same order
     */
    public function chatBatch(array $calls, $concurrency = 4)
    {
        if (!method_exists($this, 'buildChatRequest') || !method_exists($this, 'parseChatResponse')) {
            $responses = [];
            foreach ($calls as $key => $call) {
                $responses[$key] = $this->chat($call['messages'] ?? [], $call['options'] ?? []);
            }
            return $responses;
        }

        $built = [];
        $requests = [];
        foreach ($calls as $key => $call) {
            $request = $this->buildChatRequest($call['messages'] ?? [], $call['options'] ?? []);
            $built[$key] = $request;
            if (self::validateRequestUrl($request['url']) === null) {
                $requests[$key] = [
                    'curl' => $this->jsonCurl($request['url'], $request['headers']),
                    'url' => $request['url'],
                    'post' => json_encode($request['payload'])
                ];
            }
        }
        $results = \Kotchasan\Curl::multi($requests, $concurrency);

        $responses = [];
        foreach ($built as $key => $request) {
            if (!isset($results[$key])) {
                $raw = ['error' => (string) self::validateRequestUrl($request['url'])];
                $status = 0;
            } else {
                $raw = self::decodeBody($request['url'], $results[$key]['body'], $results[$key]['error'], $results[$key]['errorMessage']);
                $status = $results[$key]['status'];
            }
            $this->lastHttpStatus = $status;
            $responses[$key] = $this->parseChatResponse($raw, $status, $request['model']);
        }

        return $responses;
    }

    /**
     * A Curl instance set up for a JSON API call, with the same transport
     * rules for every request this driver sends.
     *
     * @param string $url
     * @param array  $headers
     *
     * @return \Kotchasan\Curl
     */
    protected function jsonCurl($url, array $headers)
    {
        $ch = new \Kotchasan\Curl();
        $ch->setOptions([
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            // Do not follow redirects — prevents a permitted host from 30x-ing
            // the request (carrying the API key) into an internal target.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        // Allow self-signed certs ONLY for loopback (local Ollama/LM Studio).
        if (self::isLoopbackHost($url)) {
            $ch->disableSslVerify();
        }
        $ch->setHeaders(array_merge([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json'
        ], $headers));

        return $ch;
    }

    /**
     * Decode a JSON API response body, or describe why it cannot be used.
     *
     * @param string $url
     * @param string $body
     * @param int    $curlError    cURL error number (0 = none)
     * @param string $errorMessage cURL error text
     *
     * @return array Decoded JSON or ['error' => '...']
     */
    protected static function decodeBody($url, $body, $curlError, $errorMessage)
    {
        if ($curlError !== 0) {
            // Do not leak transport detail to callers; log server-side instead.
            error_log('AI request transport error: '.$errorMessage);
            return ['error' => 'AI provider request failed'];
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            error_log('AI request non-JSON response: '.substr((string) $body, 0, 500));
            $message = 'AI provider returned an invalid response';
            if (self::isLoopbackHost($url) && preg_match('#/api(?:/|$)#i', (string) $url)) {
                $message .= '. For Ollama, use http://localhost:11434/v1 (not /api)';
            }
            return ['error' => $message];
        }
        return $decoded;
    }

    /**
     * Send a GET request and return the decoded JSON array.
     * On transport error returns ['error' => '...']; sets $this->lastHttpStatus.
     *
     * @param string $url     Full endpoint URL
     * @param array  $headers HTTP headers (key => value)
     *
     * @return array Decoded JSON, or ['error' => '...'] on transport failure
     */
    protected function get($url, array $headers = [])
    {
        $urlError = self::validateRequestUrl($url);
        if ($urlError !== null) {
            $this->lastHttpStatus = 0;
            return ['error' => $urlError];
        }

        $ch = new \Kotchasan\Curl();
        $ch->setOptions([
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        if (self::isLoopbackHost($url)) {
            $ch->disableSslVerify();
        }
        $ch->setHeaders(array_merge(['Accept' => 'application/json'], $headers));
        $body = $ch->get($url);
        $this->lastHttpStatus = $ch->httpStatus();
        if ($ch->error() !== 0) {
            error_log('AI request transport error: '.$ch->errorMessage());
            return ['error' => 'AI provider request failed'];
        }
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Probe provider availability for routing/health scoring.
     *
     * The default issues a minimal one-token chat; drivers with a cheaper
     * dedicated endpoint (e.g. GET /models) SHOULD override this.
     *
     * @return array{ok:bool,latencyMs:int,httpStatus:int,errorType:string,error:string}
     */
    public function healthCheck()
    {
        $start = microtime(true);
        $r = $this->chat([['role' => 'user', 'content' => 'ping']], ['max_tokens' => 1]);
        return [
            'ok' => $r->success,
            'latencyMs' => (int) round((microtime(true) - $start) * 1000),
            'httpStatus' => $r->httpStatus,
            'errorType' => $r->errorType,
            'error' => $r->error
        ];
    }

    /**
     * Validate an outbound request URL (SSRF guard).
     * Returns an error string if the URL is unsafe, or null if acceptable.
     *
     * @param string $url
     * @return string|null
     */
    protected static function validateRequestUrl($url)
    {
        $parts = parse_url((string) $url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return 'AI endpoint URL is invalid';
        }
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return 'AI endpoint scheme is not allowed';
        }
        $host = $parts['host'];
        // Block the cloud-metadata / link-local range outright — never a valid
        // AI endpoint, and the prime SSRF target.
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : @gethostbyname($host);
        if (is_string($ip) && (strpos($ip, '169.254.') === 0 || $ip === '0.0.0.0')) {
            return 'AI endpoint host is not allowed';
        }
        return null;
    }

    /**
     * @param string $url
     * @return bool True if the URL host is loopback (localhost / 127.0.0.0/8 / ::1)
     */
    protected static function isLoopbackHost($url)
    {
        $host = strtolower((string) parse_url((string) $url, PHP_URL_HOST));
        if ($host === 'localhost' || $host === '::1') {
            return true;
        }
        return (bool) preg_match('/^127\./', $host);
    }

    /**
     * Classify a provider failure into a machine-readable type and whether it
     * is safe to retry (e.g. on a fallback provider). Retry policy follows
     * docs/09-provider-gateway-routing.md: retry on transport/timeout/5xx/429,
     * never on auth/invalid-request/content-filter.
     *
     * @param int   $status HTTP status (0 = transport error / no response)
     * @param array $raw    Decoded provider payload, if any
     *
     * @return array{type:string,retryable:bool}
     */
    protected function classifyError($status, array $raw = [])
    {
        $status = (int) $status;

        // Transport error / no HTTP response — safe to retry elsewhere.
        if ($status === 0) {
            return ['type' => 'network_error', 'retryable' => true];
        }
        if ($status === 408 || $status === 504) {
            return ['type' => 'timeout', 'retryable' => true];
        }
        if ($status === 429) {
            return ['type' => 'rate_limit', 'retryable' => true];
        }
        if ($status >= 500) {
            return ['type' => 'provider_error', 'retryable' => true];
        }
        if ($status === 401 || $status === 403) {
            return ['type' => 'auth_error', 'retryable' => false];
        }
        if ($status === 404) {
            return ['type' => 'not_found', 'retryable' => false];
        }
        if ($status === 400 || $status === 422) {
            // Distinguish content-policy blocks where the provider signals them.
            $blob = strtolower(json_encode($raw));
            if (strpos($blob, 'content') !== false && (strpos($blob, 'policy') !== false || strpos($blob, 'filter') !== false || strpos($blob, 'safety') !== false)) {
                return ['type' => 'content_filter', 'retryable' => false];
            }
            return ['type' => 'invalid_request', 'retryable' => false];
        }

        return ['type' => 'error', 'retryable' => false];
    }

    /**
     * Build a classified error Response from the last request's HTTP status.
     *
     * @param string   $message Human-readable error message
     * @param array    $raw     Decoded provider payload, if any
     * @param int|null $status  Override status; defaults to $this->lastHttpStatus
     *
     * @return Response
     */
    protected function errorResponse($message, array $raw = [], $status = null)
    {
        $status = $status === null ? $this->lastHttpStatus : (int) $status;
        $class = $this->classifyError($status, $raw);
        return Response::fromError($message, $raw, $class['type'], $class['retryable'], $status);
    }

    /**
     * Send a JSON POST and stream the Server-Sent-Events response.
     *
     * For each complete SSE event, $onEvent is invoked with the raw `data:`
     * payload string (provider-specific JSON, or the literal "[DONE]"). When
     * the provider responds with an HTTP error status the body is buffered
     * instead and returned under 'error_body' for the caller to decode.
     *
     * @param string   $url     Full endpoint URL
     * @param array    $payload Request body to JSON-encode
     * @param array    $headers HTTP headers (key => value)
     * @param callable $onEvent function(string $data): void
     *
     * @return array{status:int,error?:string,error_body?:string}
     */
    protected function streamSse($url, array $payload, array $headers, callable $onEvent)
    {
        $urlError = self::validateRequestUrl($url);
        if ($urlError !== null) {
            $this->lastHttpStatus = 0;
            return ['status' => 0, 'error' => $urlError];
        }

        $buffer = '';
        $errorBody = '';
        $ch = new \Kotchasan\Curl();
        $ch->setOptions([
            // Streaming responses can run long; allow generous total timeout.
            CURLOPT_TIMEOUT => 300,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_VERIFYPEER => true,
            // We consume the body via the write callback, not the return value.
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => function ($curl, $chunk) use (&$buffer, &$errorBody, $onEvent) {
                $code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
                if ($code >= 400) {
                    // Error responses are normal JSON, not SSE — buffer for the caller.
                    $errorBody .= $chunk;
                    return strlen($chunk);
                }
                $buffer .= $chunk;
                foreach (self::extractSseEvents($buffer) as $data) {
                    $onEvent($data);
                }
                return strlen($chunk);
            }
        ]);
        if (self::isLoopbackHost($url)) {
            $ch->disableSslVerify();
        }
        $ch->setHeaders(array_merge([
            'Content-Type' => 'application/json',
            'Accept' => 'text/event-stream'
        ], $headers));

        $ch->post($url, json_encode($payload));
        $this->lastHttpStatus = $ch->httpStatus();

        if ($ch->error() !== 0) {
            error_log('AI stream transport error: '.$ch->errorMessage());
            return ['status' => $this->lastHttpStatus, 'error' => 'AI provider request failed'];
        }

        $result = ['status' => $this->lastHttpStatus];
        if ($errorBody !== '') {
            $result['error_body'] = $errorBody;
        }
        return $result;
    }

    /**
     * Pull all complete SSE events out of $buffer (modifying it in place to
     * retain only the trailing, incomplete fragment) and return their joined
     * `data:` payloads. Handles both "\n\n" and "\r\n\r\n" event separators.
     *
     * @param string $buffer Accumulated stream buffer (passed by reference)
     *
     * @return string[] One entry per complete event's data payload
     */
    protected static function extractSseEvents(&$buffer)
    {
        $buffer = str_replace("\r\n", "\n", $buffer);
        $events = [];
        while (($pos = strpos($buffer, "\n\n")) !== false) {
            $rawEvent = substr($buffer, 0, $pos);
            $buffer = substr($buffer, $pos + 2);
            $data = self::parseSseData($rawEvent);
            if ($data !== null) {
                $events[] = $data;
            }
        }
        return $events;
    }

    /**
     * Extract the `data:` payload from a single raw SSE event block. Multiple
     * data lines in one event are joined with newlines per the SSE spec.
     * Returns null when the event carries no data line.
     *
     * @param string $rawEvent
     *
     * @return string|null
     */
    protected static function parseSseData($rawEvent)
    {
        $data = [];
        foreach (explode("\n", $rawEvent) as $line) {
            if (strncmp($line, 'data:', 5) === 0) {
                $data[] = ltrim(substr($line, 5), ' ');
            }
        }
        if (empty($data)) {
            return null;
        }
        return implode("\n", $data);
    }
}
