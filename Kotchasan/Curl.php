<?php
/**
 * @filesource Kotchasan/Curl.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan;

/**
 * Curl Class
 *
 * @see https://www.kotchasan.com/
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
        $this->headers = array(
            'Connection' => 'keep-alive',
            'Keep-Alive' => '300',
            'Accept-Charset' => 'ISO-8859-1,utf-8;q=0.7,*;q=0.7',
            'Accept-Language' => 'en-us,en;q=0.5'
        );
        $this->options = array(
            CURLOPT_TIMEOUT => 30,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => 'Googlebot/2.1 (+http://www.google.com/bot.html)',
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false
        );
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
        curl_close($ch);
        return $response;
    }
}
