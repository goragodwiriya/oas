<?php
/**
 * @filesource Kotchasan/Http/Response.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Kotchasan\Http;

use \Psr\Http\Message\ResponseInterface;

/**
 * Response Class
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Response extends Message implements ResponseInterface
{
  /**
   * @var int
   */
  protected $statusCode;
  /**
   * @var string
   */
  protected $reasonPhrase;
  /**
   * เนื้อหา
   *
   * @var string
   */
  protected $content;
  /**
   * Status codes
   *
   * @var array
   */
  public static $statusTexts = array(
    100 => 'Continue',
    101 => 'Switching Protocols',
    102 => 'Processing', // RFC2518
    200 => 'OK',
    201 => 'Created',
    202 => 'Accepted',
    203 => 'Non-Authoritative Information',
    204 => 'No Content',
    205 => 'Reset Content',
    206 => 'Partial Content',
    207 => 'Multi-Status', // RFC4918
    208 => 'Already Reported', // RFC5842
    226 => 'IM Used', // RFC3229
    300 => 'Multiple Choices',
    301 => 'Moved Permanently',
    302 => 'Found',
    303 => 'See Other',
    304 => 'Not Modified',
    305 => 'Use Proxy',
    306 => 'Reserved',
    307 => 'Temporary Redirect',
    308 => 'Permanent Redirect', // RFC7238
    400 => 'Bad Request',
    401 => 'Unauthorized',
    402 => 'Payment Required',
    403 => 'Forbidden',
    404 => 'Not Found',
    405 => 'Method Not Allowed',
    406 => 'Not Acceptable',
    407 => 'Proxy Authentication Required',
    408 => 'Request Timeout',
    409 => 'Conflict',
    410 => 'Gone',
    411 => 'Length Required',
    412 => 'Precondition Failed',
    413 => 'Request Entity Too Large',
    414 => 'Request-URI Too Long',
    415 => 'Unsupported Media Type',
    416 => 'Requested Range Not Satisfiable',
    417 => 'Expectation Failed',
    418 => 'I\'m a teapot', // RFC2324
    422 => 'Unprocessable Entity', // RFC4918
    423 => 'Locked', // RFC4918
    424 => 'Failed Dependency', // RFC4918
    425 => 'Reserved for WebDAV advanced collections expired proposal', // RFC2817
    426 => 'Upgrade Required', // RFC2817
    428 => 'Precondition Required', // RFC6585
    429 => 'Too Many Requests', // RFC6585
    431 => 'Request Header Fields Too Large', // RFC6585
    500 => 'Internal Server Error',
    501 => 'Not Implemented',
    502 => 'Bad Gateway',
    503 => 'Service Unavailable',
    504 => 'Gateway Timeout',
    505 => 'HTTP Version Not Supported',
    506 => 'Variant Also Negotiates (Experimental)', // RFC2295
    507 => 'Insufficient Storage', // RFC4918
    508 => 'Loop Detected', // RFC5842
    510 => 'Not Extended', // RFC2774
    511 => 'Network Authentication Required', // RFC6585
  );

  /**
   * create Response
   *
   * @param int $code status Code
   * @param string||null $reasonPhrase ถ้าไม่กำหนดจะใช้ข้อความจากระบบ
   */
  public function __construct($code = 200, $reasonPhrase = null)
  {
    $this->statusCode = $code;
    $this->reasonPhrase = $reasonPhrase;
  }

  /**
   * คืนค่า Response Status
   *
   * @return int.
   */
  public function getStatusCode()
  {
    return $this->statusCode;
  }

  /**
   * กำหนดค่า status code
   *
   * @param int $code
   * @param string $reasonPhrase
   * @return \static
   */
  public function withStatus($code, $reasonPhrase = '')
  {
    $clone = clone $this;
    $clone->statusCode = $code;
    $clone->reasonPhrase = $reasonPhrase;
    return $clone;
  }

  /**
   * กำหนดเนื้อหาให้กับ  Response
   *
   * @param mixed $content
   * @return \static
   * @throws \InvalidArgumentException ถ้า $content ไม่ใช่ string
   */
  public function withContent($content)
  {
    if (null !== $content && !is_string($content) && !is_numeric($content) && !is_callable(array($content, '__toString'))) {
      throw new \InvalidArgumentException(sprintf('The Response content must be a string or object implementing __toString(), "%s" given.', gettype($content)));
    }
    $this->content = (string)$content;
    return $this;
  }

  /**
   * อ่านเนื้อหาของ Response
   *
   * @return string Content
   */
  public function getContent()
  {
    return $this->content;
  }

  /**
   * Gets the response reason phrase associated with the status code.
   *
   * @link http://tools.ietf.org/html/rfc7231#section-6
   * @link http://www.iana.org/assignments/http-status-codes/http-status-codes.xhtml
   * @return string
   */
  public function getReasonPhrase()
  {
    if ($this->reasonPhrase) {
      return $this->reasonPhrase;
    }
    if (isset(static::$statusTexts[$this->statusCode])) {
      return static::$statusTexts[$this->statusCode];
    }
    return '';
  }

  /**
   * Sends HTTP headers and content.
   *
   * @return \static
   */
  public function send()
  {
    $this->sendHeaders();
    $this->sendContent();
    return $this;
  }

  /**
   * ส่งออก HTTP headers.
   *
   * @return \static
   */
  protected function sendHeaders()
  {
    if (headers_sent()) {
      return $this;
    }
    header(sprintf('HTTP/%s %s %s', $this->protocol, $this->statusCode, $this->getReasonPhrase()), true, $this->statusCode);
    foreach ($this->headers as $name => $values) {
      foreach ($values as $value) {
        header($name.': '.$value);
      }
    }
    return $this;
  }

  /**
   * ส่งออกเนื้อหา
   *
   * @return \static
   */
  protected function sendContent()
  {
    if ($this->content) {
      echo $this->content;
    }
    return $this;
  }
}
