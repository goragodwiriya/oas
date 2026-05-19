<?php
namespace Kotchasan;

use Kotchasan\Http\Request;

/**
 * Simple JWT middleware: validate access_token cookie and populate session login.
 * Keeps backward compatibility by setting $_SESSION[Login::sessionKey()].
 */
class JwtMiddleware
{
    /**
     * @var mixed
     */
    private $secret;

    /**
     * @param $secret
     */
    public function __construct($secret)
    {
        $this->secret = $secret;
    }

    /**
     * Validate cookie and populate session. Call early in request lifecycle.
     * @return array|null Decoded payload or null
     */
    public function handle()
    {
        if (empty($this->secret)) {
            return null;
        }
        if (empty($_COOKIE['access_token'])) {
            return null;
        }
        $token = $_COOKIE['access_token'];
        $payload = Jwt::decode($token, $this->secret);
        if ($payload && isset($payload['sub'])) {
            // Populate session to keep backward compatibility (as object)
            $data = (object) [
                'id' => $payload['sub'],
                'username' => $payload['username'] ?? null,
                // store token identifier if needed
                'token' => $token
            ];
            $key = \Kotchasan\Login::sessionKey();
            if (empty($_SESSION[$key])) {
                $_SESSION[$key] = $data;
            }
            return $payload;
        }
        return null;
    }
}
