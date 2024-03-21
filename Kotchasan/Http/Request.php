<?php
/**
 * @filesource Kotchasan/Http/Request.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan\Http
 */

namespace Kotchasan\Http;

/**
 * Class for handling various variables from the server.
 *
 * @see https://www.kotchasan.com/
 */
class Request extends AbstractRequest implements \Psr\Http\Message\RequestInterface
{
    /**
     * @var array
     */
    private $attributes = [];

    /**
     * $_COOKIE
     *
     * @var array
     */
    private $cookieParams;

    /**
     * $_POST
     *
     * @var array
     */
    private $parsedBody;

    /**
     * $_GET
     *
     * @var array
     */
    private $queryParams;

    /**
     * $_SERVER
     *
     * @var array
     */
    private $serverParams;

    /**
     * @var Kotchasan\Files
     */
    private $uploadedFiles;

    /**
     * Reads a value from the COOKIE variable.
     * Returns an InputItem or a collection of InputItem.
     *
     * @param string $name    The name of the variable.
     * @param mixed  $default The default value if the variable is not found.
     *
     * @return \Kotchasan\InputItem|\Kotchasan\Inputs
     */
    public function cookie($name, $default = '')
    {
        return $this->createInputItem($this->getCookieParams(), $name, $default, 'COOKIE');
    }

    /**
     * Generates a token.
     *
     * @return string The generated token.
     */
    public function createToken()
    {
        $token = \Kotchasan\Password::uniqid(32);
        $_SESSION[$token] = array(
            'times' => 0,
            'expired' => time() + TOKEN_AGE
        );
        return $token;
    }

    /**
     * Reads a value from the GET variable.
     * Returns an InputItem or a collection of InputItem.
     *
     * @param string $name    The name of the variable.
     * @param mixed  $default The default value if the variable is not found.
     * @param string $cookie  null (default) to not read from the cookie, string specifying the cookie name to read from.
     *
     * @return \Kotchasan\InputItem|\Kotchasan\Inputs
     */
    public function get($name, $default = null, $cookie = null)
    {
        $from = array('GET');
        if ($cookie !== null) {
            $from[] = 'COOKIE';
        }
        return $this->globals($from, $name, $default, $cookie);
    }

    /**
     * Returns the list of acceptable languages from the HTTP header.
     *
     * @return array The list of acceptable languages.
     */
    public function getAcceptableLanguages()
    {
        $acceptLanguages = empty($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? [] : explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE']);
        $matches = [];
        if (!empty($acceptLanguages)) {
            foreach ($acceptLanguages as $item) {
                $item = array_map('trim', explode(';', $item));
                if (isset($item[1])) {
                    $q = str_replace('q=', '', $item[1]);
                } else {
                    if ($item[0] == '*/*') {
                        $q = 0.01;
                    } elseif (substr($item[0], -1) == '*') {
                        $q = 0.02;
                    } else {
                        $q = 1000 - count($matches);
                    }
                }
                $matches[(string) $q] = $item[0];
            }
            krsort($matches, SORT_NUMERIC);
            $matches = array_values($matches);
        }
        return $matches;
    }

    /**
     * Retrieves the attribute with the given name.
     *
     * @param string $name    The attribute name.
     * @param mixed  $default The default value if the attribute is not found.
     *
     * @return mixed The attribute value.
     */
    public function getAttribute($name, $default = null)
    {
        return isset($this->attributes[$name]) ? $this->attributes[$name] : $default;
    }

    /**
     * Retrieves the attributes.
     *
     * @return array The attributes.
     */
    public function getAttributes()
    {
        return $this->attributes;
    }

    /**
     * Retrieves the parsed body parameters, if any.
     *
     * @return array|null The parsed body parameters.
     */
    public function getClientIp()
    {
        if (isset($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        } elseif (isset($_SERVER['HTTP_FORWARDED_FOR'])) {
            return $_SERVER['HTTP_FORWARDED_FOR'];
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $IParray = array_filter(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
            return $IParray[0];
        } elseif (isset($_SERVER['REMOTE_ADDR'])) {
            return $_SERVER['REMOTE_ADDR'];
        }
        return null;
    }

    /**
     * Get values from the $_COOKIE variable.
     *
     * @return array
     */
    public function getCookieParams()
    {
        if ($this->cookieParams === null) {
            $this->cookieParams = self::filterRequestKey($_COOKIE);
        }
        return $this->cookieParams;
    }

    /**
     * Get values from the $_POST variable.
     *
     * @return null|array|object
     */
    public function getParsedBody()
    {
        if ($this->parsedBody === null) {
            $this->parsedBody = self::filterRequestKey($_POST);
        }
        return $this->parsedBody;
    }

    /**
     * Get values from the $_GET variable.
     *
     * @return null|array|object
     */
    public function getQueryParams()
    {
        if ($this->queryParams === null) {
            $this->queryParams = self::filterRequestKey($_GET);
        }
        return $this->queryParams;
    }

    /**
     * Get values from the $_SERVER variable.
     *
     * @return array
     */
    public function getServerParams()
    {
        if ($this->serverParams === null) {
            $this->serverParams = self::filterRequestKey($_SERVER);
        }
        return $this->serverParams;
    }

    /**
     * Read the stream.
     *
     * @return StreamInterface
     */
    public function getBody()
    {
        return new Stream('php://input');
    }

    /**
     * Get uploaded files from $_FILES.
     *
     * @return \Kotchasan\Files
     */
    public function getUploadedFiles()
    {
        if ($this->uploadedFiles === null) {
            $this->uploadedFiles = new \Kotchasan\Files();
            if (isset($_FILES)) {
                foreach ($_FILES as $name => $file) {
                    if (is_array($file['name'])) {
                        foreach ($file['name'] as $key => $value) {
                            $this->uploadedFiles->add($name.'['.$key.']', $file['tmp_name'][$key], $value, $file['type'][$key], $file['size'][$key], $file['error'][$key]);
                        }
                    } else {
                        $this->uploadedFiles->add($name, $file['tmp_name'], $file['name'], $file['type'], $file['size'], $file['error']);
                    }
                }
            }
        }
        return $this->uploadedFiles;
    }

    /**
     * Read values from the GLOBALS variables ($_POST, $_GET, $_SESSION, $_COOKIE) according to the specified keys.
     * For example, if the keys are ['POST', 'GET'], it will read from $_POST first and if not found, it will read from $_GET.
     * If the value is still not found, it will return the default value.
     *
     * @param array  $keys                   The names of the variables to read (uppercase), e.g., ['POST', 'GET'].
     * @param string $name                   The name of the variable.
     * @param mixed  $default                The default value if the variable is not found.
     * @param string $cookie_or_session_name The name of the cookie or session if it's different from $name.
     *
     * @return \Kotchasan\InputItem|\Kotchasan\Inputs
     */
    public function globals($keys, $name, $default = null, $cookie_or_session_name = null)
    {
        foreach ($keys as $key) {
            if ($key === 'POST') {
                $datas = $this->getParsedBody();
            } elseif ($key === 'GET') {
                $datas = $this->getQueryParams();
            } elseif ($key === 'SESSION') {
                $datas = $_SESSION;
                if ($cookie_or_session_name !== null) {
                    $name = $cookie_or_session_name;
                }
            } elseif ($key === 'COOKIE') {
                $datas = $this->getCookieParams();
                if ($cookie_or_session_name !== null) {
                    $name = $cookie_or_session_name;
                }
            }
            if (isset($datas[$name])) {
                return is_array($datas[$name]) ? new \Kotchasan\Inputs($datas[$name], $key) : new \Kotchasan\InputItem($datas[$name], $key);
            }
        }
        return is_array($default) ? new \Kotchasan\Inputs($default) : new \Kotchasan\InputItem($default);
    }

    /**
     * Initialize session.
     *
     * @return bool
     */
    public function initSession()
    {
        // Get the sessid from the query string
        $sessid = $this->get('sess')->toString();
        // If sessid is valid, set it as the session ID
        if (!empty($sessid) && preg_match('/^[a-zA-Z0-9]{20,}/', $sessid)) {
            session_id($sessid);
            session_start();
            // Redirect to the same URI without the sess parameter
            $redirect = $this->getUri()->withoutParams('sess');
            header('Location: '.$redirect);
            exit;
        }
        // If USE_SESSION_DATABASE is defined and true, set the custom session handler
        if (defined('USE_SESSION_DATABASE') && USE_SESSION_DATABASE === true) {
            $sess = new \Kotchasan\Session();
            session_set_save_handler(
                [$sess, '_open'],
                [$sess, '_close'],
                [$sess, '_read'],
                [$sess, '_write'],
                [$sess, '_destroy'],
                [$sess, '_gc']
            );
            // Register a shutdown function to write the session data
            register_shutdown_function('session_write_close');
        }
        // Start the session
        session_start();
        // Start output buffering if it's not already started
        if (!ob_get_status()) {
            if (extension_loaded('zlib') && !ini_get('zlib.output_compression')) {
                // Enable gzip compression
                ob_start('ob_gzhandler');
            } else {
                ob_start();
            }
        }
        return true;
    }

    /**
     * Check if the request is made via Ajax.
     * Return true if the request is an Ajax request (XMLHttpRequest).
     *
     * @return bool
     */
    public function isAjax()
    {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';
    }

    /**
     * Check if the referer is from the same website.
     * Return true if the referer is from the same website.
     *
     * @return bool
     */
    public function isReferer()
    {
        $host = empty($_SERVER['HTTP_HOST']) ? $_SERVER['SERVER_NAME'] : $_SERVER['HTTP_HOST'];
        $referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
        if (preg_match("/$host/ui", $referer)) {
            return true;
        } elseif (preg_match('/^(http(s)?:\/\/)(.*)(\/.*){0,}$/U', WEB_URL, $match)) {
            return preg_match("/$match[3]/ui", $referer);
        }
        return false;
    }

    /**
     * Check the token from the form and validate the referer.
     * Only accepts values from $_POST.
     * This function must be called after initSession() every time.
     * The token has a lifetime defined by TOKEN_LIMIT.
     * Return true if the token is valid and not expired.
     *
     * @return bool
     */
    public function isSafe()
    {
        $token = $this->request('token')->toString();
        if (!empty($token)) {
            if (isset($_SESSION[$token]) && $_SESSION[$token]['times'] < TOKEN_LIMIT && $_SESSION[$token]['expired'] > time() && $this->isReferer()) {
                ++$_SESSION[$token]['times'];
                return true;
            } else {
                unset($_SESSION[$token]);
            }
        }
        return false;
    }

    /**
     * Read a value from the $_POST variable.
     * If not found, return the $default value.
     * Return an InputItem or an array of InputItem.
     *
     * @param string      $name    The variable name.
     * @param mixed       $default The default value if the variable is not found.
     * @param string|null $cookie  null (default) to not read from cookie, string the name of the cookie to read from.
     *
     * @return \Kotchasan\InputItem|\Kotchasan\Inputs
     */
    public function post($name, $default = null, $cookie = null)
    {
        $from = ['POST'];
        if ($cookie !== null) {
            $from[] = 'COOKIE';
        }
        return $this->globals($from, $name, $default, $cookie);
    }

    /**
     * Remove the token.
     */
    public function removeToken()
    {
        $token = $this->request('token')->toString();
        if (!empty($token)) {
            unset($_SESSION[$token]);
        }
    }

    /**
     * Read a value from the $_POST, $_GET, $_COOKIE (optional) variables in order.
     * Return the first item found, if not found, return the $default value.
     * Return an InputItem or an array of InputItem.
     *
     * @param string      $name    The variable name.
     * @param mixed       $default The default value if the variable is not found.
     * @param string|null $cookie  null (default) to not read from cookie, string the name of the cookie to read from.
     *
     * @return \Kotchasan\InputItem|\Kotchasan\Inputs
     */
    public function request($name, $default = null, $cookie = null)
    {
        $from = ['POST', 'GET'];
        if ($cookie !== null) {
            $from[] = 'COOKIE';
        }
        return $this->globals($from, $name, $default, $cookie);
    }

    /**
     * Read a value from the $_SERVER variable.
     * If not found, return the $default value.
     *
     * @param string $name    The variable name.
     * @param mixed  $default The default value if the variable is not found.
     *
     * @return mixed
     */
    public function server($name, $default = null)
    {
        return isset($_SERVER[$name]) ? $_SERVER[$name] : $default;
    }

    /**
     * Read a value from the $_SESSION variable.
     * If not found, return the $default value.
     * Return an InputItem or a Collection of InputItem.
     *
     * @param string $name    The variable name.
     * @param mixed  $default The default value if the variable is not found.
     *
     * @return \Kotchasan\InputItem|\Kotchasan\Inputs
     */
    public function session($name, $default = null)
    {
        return $this->createInputItem($_SESSION, $name, $default, 'SESSION');
    }

    /**
     * Set the value of $_SESSION variable.
     *
     * @param string $name  The variable name.
     * @param mixed  $value The value of the variable.
     *
     * @return static
     */
    public function setSession($name, $value)
    {
        $_SESSION[$name] = $value;
        return $this;
    }

    /**
     * Set the value of attributes.
     *
     * @param string $name  The name of the attribute.
     * @param mixed  $value The value of the attribute.
     *
     * @return static
     */
    public function withAttribute($name, $value)
    {
        $clone = clone $this;
        $clone->attributes[$name] = $value;
        return $clone;
    }

    /**
     * Set the value of cookieParams.
     *
     * @param array $cookies The cookie parameters.
     *
     * @return static
     */
    public function withCookieParams(array $cookies)
    {
        $clone = clone $this;
        $clone->cookieParams = $cookies;
        return $clone;
    }

    /**
     * Set the value of parsedBody.
     *
     * @param mixed $data The parsed body data.
     *
     * @return static
     */
    public function withParsedBody($data)
    {
        $clone = clone $this;
        $clone->parsedBody = $data;
        return $clone;
    }

    /**
     * Set the value of queryParams.
     *
     * @param array $query The query parameters.
     *
     * @return static
     */
    public function withQueryParams(array $query)
    {
        $clone = clone $this;
        $clone->queryParams = $query;
        return $clone;
    }

    /**
     * Set the value of uploadedFiles.
     *
     * @param array $uploadedFiles The uploaded files.
     *
     * @return static
     */
    public function withUploadedFiles(array $uploadedFiles)
    {
        $clone = clone $this;
        $clone->uploadedFiles = $uploadedFiles;
        return $clone;
    }

    /**
     * Removes attributes.
     *
     * @param string|array $names The names of the attributes to remove.
     *
     * @return static
     */
    public function withoutAttribute($names)
    {
        $clone = clone $this;
        if (is_array($names)) {
            foreach ($names as $name) {
                unset($clone->attributes[$name]);
            }
        } else {
            unset($clone->attributes[$names]);
        }
        return $clone;
    }

    /**
     * Reads a value from the $source.
     * Returns an InputItem or a Collection of InputItem.
     *
     * @param array       $source  The GET or POST variables.
     * @param string      $name    The variable name.
     * @param mixed       $default The default value if the variable is not found.
     * @param string|null $type    The type of input (e.g., GET, POST, SESSION, COOKIE) or null if not from the listed sources.
     *
     * @return \Kotchasan\InputItem|\Kotchasan\Inputs
     */
    private function createInputItem($source, $name, $default, $type)
    {
        if (isset($source[$name])) {
            return is_array($source[$name]) ? new \Kotchasan\Inputs($source[$name], $type) : new \Kotchasan\InputItem($source[$name], $type);
        } elseif (preg_match('/(.*)\[(.*)\]/', $name, $match) && isset($source[$match[1]][$match[2]])) {
            return new \Kotchasan\InputItem($source[$match[1]][$match[2]], $type);
        }
        return is_array($default) ? new \Kotchasan\Inputs($default) : new \Kotchasan\InputItem($default);
    }

    /**
     * Filters the keys of the requests.
     *
     * @param array $source The source array.
     *
     * @return array The filtered array.
     */
    public static function filterRequestKey($source)
    {
        $result = [];
        foreach ($source as $key => $values) {
            if (preg_match('/^[a-zA-Z0-9\[\]_\-]+/', $key)) {
                if (is_array($values)) {
                    $result[$key] = self::filterRequestKey($values);
                } elseif ($values !== null) {
                    $result[$key] = $values;
                }
            }
        }
        return $result;
    }
}
