<?php

namespace Kotchasan\Http\Middleware;

use Kotchasan\Http\Request;
use Kotchasan\Http\Response;

/**
 * Digest Authentication Middleware
 * Implements HTTP Digest Authentication according to RFC 7616
 *
 * The nonce is signed (HMAC with a key from $secret, or from password_key in
 * settings/config.php) and carries the time it was issued, so the server checks
 * it without storing anything: a nonce it did not issue, or one older than
 * $nonceLifetime seconds, is refused. The uri the client hashed must be the
 * URI of the request. It used to accept any nonce, opaque and uri — one
 * captured Authorization header could be replayed for ever, on any URL.
 * Within the lifetime of a nonce a captured header can still be replayed on
 * the same URL (the nonce count is not tracked).
 *
 * @package Kotchasan\Http\Middleware
 */
class DigestAuthMiddleware implements MiddlewareInterface
{
    /**
     * Authentication callback
     * @var callable
     */
    private $callback;

    /**
     * Authentication realm
     * @var string
     */
    private $realm;

    /**
     * Key that signs the nonces
     * @var string
     */
    private $nonceKey;

    /**
     * Seconds a nonce is accepted for
     * @var int
     */
    private $nonceLifetime;

    /**
     * Constructor
     *
     * @param callable $callback Function that returns md5(username:realm:password) for a username, or null
     * @param string $realm Authentication realm
     * @param string|null $secret Key that signs the nonces (null: derived from password_key)
     * @param int $nonceLifetime Seconds a nonce is accepted for
     */
    public function __construct(callable $callback, string $realm = 'API', ?string $secret = null, int $nonceLifetime = 300)
    {
        $this->callback = $callback;
        $this->realm = $realm;
        $this->nonceLifetime = max(10, $nonceLifetime);
        if ($secret === null || $secret === '') {
            $secret = '';
            if (class_exists('\\Kotchasan\\Config') && defined('ROOT_PATH')) {
                $secret = (string) (\Kotchasan\Config::create()->password_key ?? '');
            }
        }
        // Without a key nonces cannot be checked across requests: a random one
        // makes every nonce of another process invalid instead of every nonce valid
        $this->nonceKey = $secret === ''
            ? random_bytes(32)
            : hash_hmac('sha256', 'Kotchasan\\Http\\Middleware\\DigestAuthMiddleware nonce', $secret, true);
    }

    /**
     * Parse Digest authentication header
     *
     * @param string $digestHeader The digest header string
     * @return array Parsed digest parts
     */
    private function parseDigestHeader(string $digestHeader): array
    {
        $needed_parts = [
            'username' => 1,
            'realm' => 1,
            'nonce' => 1,
            'uri' => 1,
            'response' => 1,
            'opaque' => 1,
            'qop' => 1,
            'nc' => 1,
            'cnonce' => 1
        ];
        $data = [];

        preg_match_all('/(\w+)=(?:"([^"]+)"|([^,]+))/', $digestHeader, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $data[$match[1]] = isset($match[3]) ? $match[3] : $match[2];
            unset($needed_parts[$match[1]]);
        }

        return $data;
    }

    /**
     * A new nonce: issue time and its signature
     *
     * @param int|null $time
     * @return string
     */
    private function createNonce(?int $time = null): string
    {
        $time = $time ?? time();

        return $time.'.'.hash_hmac('sha256', $time.':'.$this->realm, $this->nonceKey);
    }

    /**
     * Whether this middleware issued $nonce; $stale is true when it did but too long ago
     *
     * @param string $nonce
     * @param bool $stale
     * @return bool
     */
    private function checkNonce(string $nonce, &$stale = false): bool
    {
        $stale = false;
        if (!preg_match('/^([0-9]{1,12})\.([a-f0-9]{64})$/', $nonce, $match)) {
            return false;
        }
        if (!hash_equals($this->createNonce((int) $match[1]), $nonce)) {
            return false;
        }
        $age = time() - (int) $match[1];
        if ($age < -30 || $age > $this->nonceLifetime) {
            $stale = true;

            return false;
        }

        return true;
    }

    /**
     * The opaque value sent with the challenge
     *
     * @return string
     */
    private function opaque(): string
    {
        return hash_hmac('sha256', 'opaque:'.$this->realm, $this->nonceKey);
    }

    /**
     * Validate digest authentication
     *
     * @param array $digest Parsed digest parts
     * @param string $method HTTP method
     * @param string $uri Request target (path and query) of this request
     * @param bool $stale Set to true when only the nonce was too old
     * @return bool True if digest is valid
     */
    private function validateDigest(array $digest, string $method, string $uri, &$stale = false): bool
    {
        $stale = false;
        // Check required fields (qop="auth" is what the challenge asks for)
        foreach (['username', 'realm', 'nonce', 'uri', 'response', 'opaque', 'qop', 'nc', 'cnonce'] as $field) {
            if (!isset($digest[$field]) || !is_string($digest[$field]) || $digest[$field] === '') {
                return false;
            }
        }
        if ($digest['realm'] !== $this->realm || $digest['qop'] !== 'auth'
            || !hash_equals($this->opaque(), $digest['opaque'])
            || !preg_match('/^[0-9a-f]{8}$/i', $digest['nc'])) {
            return false;
        }
        // The client hashed this uri: it must be the one requested
        if ($digest['uri'] !== $uri) {
            return false;
        }
        $nonceOk = $this->checkNonce($digest['nonce'], $stale);

        // Get password hash for user
        $A1 = call_user_func($this->callback, $digest['username']);

        if (!is_string($A1) || $A1 === '') {
            return false; // User not found
        }

        // Calculate expected response
        $A2 = md5($method.':'.$digest['uri']);
        $expected = md5($A1.':'.$digest['nonce'].':'.$digest['nc'].':'.
            $digest['cnonce'].':'.$digest['qop'].':'.$A2);

        // A stale nonce counts only when the rest is right (RFC 7616 stale=true)
        $responseOk = hash_equals($expected, strtolower($digest['response']));
        $stale = $stale && $responseOk;

        return $nonceOk && $responseOk;
    }

    /**
     * Create unauthorized response
     *
     * @param bool $stale The credentials were right but the nonce too old
     * @return Response
     */
    private function unauthorized(bool $stale = false): Response
    {
        $digestHeader = 'Digest realm="'.addcslashes($this->realm, '"\\').'", '.
            'qop="auth", '.
            'algorithm=MD5, '.
            'nonce="'.$this->createNonce().'", '.
            'opaque="'.$this->opaque().'"'.
            ($stale ? ', stale=true' : '');

        return Response::makeUnauthorized([
            'error' => 'Unauthorized',
            'message' => 'Invalid credentials'
        ])->withHeader('WWW-Authenticate', $digestHeader);
    }

    /**
     * Handle the request through middleware
     *
     * @param Request $request The incoming request
     * @param callable|null $next The next middleware
     * @return mixed Response or next middleware result
     */
    public function handle(Request $request, ?callable $next = null)
    {
        $authHeader = $request->getHeaderLine('Authorization');

        if (empty($authHeader)) {
            return $this->unauthorized();
        }

        if (!preg_match('/^Digest\s+(.+)$/i', $authHeader, $matches)) {
            return $this->unauthorized();
        }

        $digestParts = $this->parseDigestHeader($matches[1]);

        // The request target as the client sent it (the Uri object is the full URL)
        $server = $request->getServerParams();
        $uri = $request->getUri();
        $target = isset($server['REQUEST_URI']) && is_string($server['REQUEST_URI'])
            ? $server['REQUEST_URI']
            : $uri->getPath().($uri->getQuery() !== '' ? '?'.$uri->getQuery() : '');

        $stale = false;
        if (!$this->validateDigest($digestParts, $request->getOriginalMethod(), $target, $stale)) {
            return $this->unauthorized($stale);
        }

        // Set authenticated user in the request
        $request->setAttribute('authenticated_user', $digestParts['username']);

        if ($next) {
            return $next($request);
        }

        return $request;
    }
}
