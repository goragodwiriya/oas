<?php
namespace Kotchasan;

/**
 * Kotchasan Curl Class
 *
 * This class provides methods for making HTTP requests using cURL.
 *
 * @package Kotchasan
 */
class Curl
{
    /**
     * Variable to store cURL errors
     * 0 means no error (default value)
     * Greater than 0 represents the cURL error number
     *
     * @var int
     */
    protected $error = 0;
    /**
     * Error message from cURL if there is an error in sending
     *
     * @var string
     */
    protected $errorMessage = '';
    /**
     * HTTP headers
     *
     * @var array
     */
    protected $headers = [];
    /**
     * CURLOPT parameters
     *
     * @var array
     */
    protected $options = [];
    /**
     * Transfer info from the last request (output of curl_getinfo()).
     *
     * @var array
     */
    protected $info = [];

    /**
     * Constructor
     *
     * @throws \Exception If cURL is not supported
     */
    public function __construct()
    {
        if (!extension_loaded('curl')) {
            throw new \Exception('cURL library is not loaded');
        }
        // Default parameters
        $this->headers = [
            'Connection' => 'keep-alive',
            'Keep-Alive' => '300',
            'Accept-Charset' => 'ISO-8859-1,utf-8;q=0.7,*;q=0.7',
            'Accept-Language' => 'en-us,en;q=0.5'
        ];
        $this->options = [
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => 'KotchasanBot/1.0',
            // Verify TLS certificates by default. Sending requests (with API
            // keys / bearer tokens) over an unverified connection allows MITM
            // credential theft. Use disableSslVerify() only for trusted local
            // testing — never against public endpoints.
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_VERIFYPEER => true
        ];
    }

    /**
     * DELETE
     *
     * @param string $url
     * @param mixed $params
     *
     * @return string
     */
    public function delete($url, $params)
    {
        $this->options[CURLOPT_CUSTOMREQUEST] = 'DELETE';
        if (!empty($params)) {
            if (is_array($params)) {
                $this->options[CURLOPT_POSTFIELDS] = http_build_query($params, '', '&');
            } else {
                $this->options[CURLOPT_POSTFIELDS] = $params;
            }
        }
        return $this->execute($url);
    }

    /**
     * Returns the cURL error number
     * 0 means no error
     *
     * @return int
     */
    public function error()
    {
        return $this->error;
    }

    /**
     * Returns the error message from cURL if there is an error in sending
     *
     * @return string
     */
    public function errorMessage()
    {
        return $this->errorMessage;
    }

    /**
     * GET
     *
     * @param string $url
     * @param mixed $params
     *
     * @return string
     */
    public function get($url, $params = [])
    {
        $this->options[CURLOPT_CUSTOMREQUEST] = 'GET';
        $this->options[CURLOPT_HTTPGET] = true;
        if (!empty($params)) {
            if (is_array($params)) {
                $url .= (strpos($url, '?') === false ? '?' : '&').http_build_query($params, '', '&');
            } else {
                $this->options[CURLOPT_POSTFIELDS] = $params;
            }
        }
        return $this->execute($url);
    }

    /**
     * HEAD
     *
     * @param string $url
     * @param mixed $params
     *
     * @return string
     */
    public function head($url, $params = [])
    {
        $this->options[CURLOPT_CUSTOMREQUEST] = 'HEAD';
        $this->options[CURLOPT_NOBODY] = true;
        if (!empty($params)) {
            if (is_array($params)) {
                $this->options[CURLOPT_POSTFIELDS] = http_build_query($params, '', '&');
            } else {
                $this->options[CURLOPT_POSTFIELDS] = $params;
            }
        }
        return $this->execute($url);
    }

    /**
     * HTTP authentication for sending requests
     *
     * @param string $username
     * @param string $password
     * @param string $type     any (default), digest, basic, digest_ie, negotiate, ntlm, ntlm_wb, anysafe, only
     *
     * @return $this
     */
    public function httpauth($username = '', $password = '', $type = 'any')
    {
        $this->options[CURLOPT_HTTPAUTH] = constant('CURLAUTH_'.strtoupper($type));
        $this->options[CURLOPT_USERPWD] = $username.':'.$password;
        return $this;
    }

    /**
     * Use PROXY
     *
     * @param string $url
     * @param int    $port
     * @param string $username
     * @param string $password
     *
     * @return $this
     */
    public function httpproxy($url = '', $port = 80, $username = null, $password = null)
    {
        $this->options[CURLOPT_HTTPPROXYTUNNEL] = true;
        $this->options[CURLOPT_PROXY] = $url.':'.$port;
        if ($username !== null && $password !== null) {
            $this->options[CURLOPT_PROXYUSERPWD] = $username.':'.$password;
        }
        return $this;
    }

    /**
     * POST
     *
     * @param string $url
     * @param mixed $params
     *
     * @return string
     */
    public function post($url, $params = [])
    {
        $this->options[CURLOPT_CUSTOMREQUEST] = 'POST';
        $this->options[CURLOPT_POST] = true;
        if (!empty($params)) {
            if (is_array($params)) {
                $this->options[CURLOPT_POSTFIELDS] = http_build_query($params, '', '&');
            } else {
                $this->options[CURLOPT_POSTFIELDS] = $params;
            }
        }
        return $this->execute($url);
    }

    /**
     * PUT
     *
     * @param string $url
     * @param mixed $params
     *
     * @return string
     */
    public function put($url, $params = [])
    {
        $this->options[CURLOPT_CUSTOMREQUEST] = 'PUT';
        if (!empty($params)) {
            if (is_array($params)) {
                $this->options[CURLOPT_POSTFIELDS] = http_build_query($params, '', '&');
            } else {
                $this->options[CURLOPT_POSTFIELDS] = $params;
            }
        }
        return $this->execute($url);
    }

    /**
     * Set referer
     *
     * @param string $referrer
     *
     * @return $this
     */
    public function referer($referrer)
    {
        $this->options[CURLOPT_REFERER] = $referrer;
        return $this;
    }

    /**
     * Set cookie file
     *
     * @param string $cookiePath
     *
     * @return $this
     */
    public function setCookie($cookiePath)
    {
        $this->options[CURLOPT_COOKIEFILE] = $cookiePath;
        $this->options[CURLOPT_COOKIEJAR] = $cookiePath;
        return $this;
    }

    /**
     * Set headers
     *
     * @param array $headers
     *
     * @return $this
     */
    public function setHeaders($headers)
    {
        foreach ($headers as $key => $value) {
            $this->headers[$key] = $value;
        }
        return $this;
    }

    /**
     * Set options
     *
     * @param array $options
     *
     * @return $this
     */
    public function setOptions($options)
    {
        foreach ($options as $key => $value) {
            $this->options[$key] = $value;
        }
        return $this;
    }

    /**
     * Explicitly disable TLS certificate verification.
     * ONLY for trusted local endpoints (e.g. http://localhost Ollama/LM Studio)
     * during development. Never use against public/remote endpoints.
     *
     * @return $this
     */
    public function disableSslVerify()
    {
        $this->options[CURLOPT_SSL_VERIFYHOST] = 0;
        $this->options[CURLOPT_SSL_VERIFYPEER] = false;
        return $this;
    }

    /**
     * Execute cURL
     *
     * @param string $url
     *
     * @return string
     */
    protected function execute($url)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        if (!empty($this->headers)) {
            $headers = [];
            foreach ($this->headers as $key => $value) {
                $headers[] = $key.': '.$value;
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        foreach ($this->options as $key => $value) {
            curl_setopt($ch, $key, $value);
        }
        $response = curl_exec($ch);
        if (curl_error($ch)) {
            $this->error = curl_errno($ch);
            $this->errorMessage = curl_error($ch);
        }
        $this->info = curl_getinfo($ch);
        curl_close($ch);
        return $response;
    }

    /**
     * Returns transfer info from the last request.
     *
     * @param string|null $key A specific curl_getinfo() key, or null for the whole array
     *
     * @return mixed
     */
    public function getInfo($key = null)
    {
        if ($key === null) {
            return $this->info;
        }
        return isset($this->info[$key]) ? $this->info[$key] : null;
    }

    /**
     * Returns the HTTP status code of the last request (0 if unavailable).
     *
     * @return int
     */
    public function httpStatus()
    {
        return isset($this->info['http_code']) ? (int) $this->info['http_code'] : 0;
    }

    /**
     * Build a cURL handle from this instance's headers and options without
     * executing it. Used by multi() to run several requests in parallel.
     *
     * @param string $url
     * @param string $method HTTP method
     * @param mixed  $body   Request body (array is form-encoded)
     *
     * @return resource|\CurlHandle
     */
    public function buildHandle($url, $method = 'GET', $body = null)
    {
        $method = strtoupper($method);
        $options = $this->options;
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
        } elseif ($method !== 'GET') {
            $options[CURLOPT_CUSTOMREQUEST] = $method;
        }
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = is_array($body) ? http_build_query($body, '', '&') : $body;
        }
        // multi() reads bodies via curl_multi_getcontent(), which requires the
        // transfer to be returned rather than written to stdout.
        if (empty($options[CURLOPT_WRITEFUNCTION])) {
            $options[CURLOPT_RETURNTRANSFER] = true;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        if (!empty($this->headers)) {
            $headers = [];
            foreach ($this->headers as $key => $value) {
                $headers[] = $key.': '.$value;
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        foreach ($options as $key => $value) {
            curl_setopt($ch, $key, $value);
        }
        return $ch;
    }

    /**
     * Copy a finished handle's transfer info and error state onto this
     * instance, so httpStatus()/error()/errorMessage() behave exactly as they
     * do after a normal get()/post().
     *
     * @param resource|\CurlHandle $ch
     *
     * @return $this
     */
    public function applyResult($ch)
    {
        $this->info = curl_getinfo($ch);
        $this->error = curl_errno($ch);
        $this->errorMessage = $this->error === 0 ? '' : curl_error($ch);
        return $this;
    }

    /**
     * Execute several requests in parallel, keeping at most $concurrency
     * connections open at a time.
     *
     * Each entry of $requests is:
     *   ['curl' => Curl, 'url' => string, 'post' => string|array|null]
     * where 'curl' is a Curl instance already configured with setHeaders() /
     * setOptions(). Omit 'post' for a GET. Each instance receives its own
     * result via applyResult(), so error() and httpStatus() work per request.
     *
     * Returns results keyed identically to $requests — key order is preserved,
     * completion order is not.
     *
     * @param array $requests    [key => ['curl' => Curl, 'url' => ..., 'post' => ...]]
     * @param int   $concurrency Maximum simultaneous connections
     *
     * @return array [key => ['body' => string, 'status' => int, 'error' => int, 'errorMessage' => string]]
     */
    public static function multi(array $requests, $concurrency = 5)
    {
        if (empty($requests)) {
            return [];
        }
        $concurrency = max(1, (int) $concurrency);
        $keys = array_keys($requests);
        $multi = curl_multi_init();
        $results = [];
        $pending = [];
        $next = 0;
        $running = 0;

        // Start one transfer from the queue; returns false when the queue is empty
        $start = static function () use (&$next, &$pending, $keys, $requests, $multi) {
            if ($next >= count($keys)) {
                return false;
            }
            $key = $keys[$next++];
            $request = $requests[$key];
            $curl = $request['curl'];
            $ch = $curl->buildHandle(
                $request['url'],
                isset($request['post']) ? 'POST' : 'GET',
                isset($request['post']) ? $request['post'] : null
            );
            curl_multi_add_handle($multi, $ch);
            $pending[self::handleId($ch)] = ['key' => $key, 'curl' => $curl];
            return true;
        };

        for ($i = 0; $i < $concurrency; $i++) {
            if (!$start()) {
                break;
            }
        }

        do {
            do {
                $status = curl_multi_exec($multi, $running);
            } while ($status === CURLM_CALL_MULTI_PERFORM);

            while (($info = curl_multi_info_read($multi)) !== false) {
                $ch = $info['handle'];
                $id = self::handleId($ch);
                if (!isset($pending[$id])) {
                    continue;
                }
                $entry = $pending[$id];
                $entry['curl']->applyResult($ch);
                $errno = curl_errno($ch);
                $results[$entry['key']] = [
                    'body' => (string) curl_multi_getcontent($ch),
                    'status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
                    'error' => $errno,
                    'errorMessage' => $errno === 0 ? '' : curl_error($ch)
                ];
                curl_multi_remove_handle($multi, $ch);
                curl_close($ch);
                unset($pending[$id]);
                // Backfill the freed slot so the window stays full
                $start();
            }

            if ($running > 0) {
                // Block until there is activity instead of spinning the CPU
                curl_multi_select($multi, 1.0);
            }
        } while ($running > 0 || !empty($pending));

        curl_multi_close($multi);

        // Restore the caller's key order
        $ordered = [];
        foreach ($keys as $key) {
            if (isset($results[$key])) {
                $ordered[$key] = $results[$key];
            }
        }
        return $ordered;
    }

    /**
     * Identify a cURL handle across PHP versions — a resource before PHP 8,
     * a CurlHandle object from PHP 8 onwards.
     *
     * @param resource|\CurlHandle $ch
     *
     * @return int
     */
    private static function handleId($ch)
    {
        return is_object($ch) ? spl_object_id($ch) : (int) $ch;
    }
}
