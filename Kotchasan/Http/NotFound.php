<?php

namespace Kotchasan\Http;

/**
 * NotFound Class
 *
 * This class represents a Not Found HTTP response.
 * It extends the Response class.
 *
 * @see https://www.kotchasan.com/
 */
class NotFound extends Response
{
    /**
     * Send HTTP Error 404 response.
     *
     * @param string|null $message The error message (optional). If not specified, a default message is used.
     * @param int         $code    The error code (default: 404).
     */
    public function __construct($message = null, $code = 404)
    {
        $message = empty($message) ? '404 Not Found' : $message;
        parent::__construct($code);
        $response = $this->withProtocolVersion('1.0');
        if ($message) {
            $response = $response->withContent($message);
        }
        $response->send();
        exit;
    }
}
