<?php
namespace Kotchasan;

use Kotchasan\Http\Request;
use Kotchasan\Http\Response;

/**
 * API Controller base class for handling API requests and routing.
 *
 * @see https://www.kotchasan.com/
 */
class ApiController extends \Kotchasan\KBase
{
    /**
     * Per-request response ID.
     *
     * @var string|null
     */
    private static $requestId;

    /**
     * API Controller index action - Router for API endpoints.
     *
     * @param Request $request The HTTP request object.
     */
    public function index(Request $request)
    {
        $allowOrigin = $this->resolveAllowedOrigin($request);
        $allowHeaders = 'origin, x-requested-with, content-type, authorization, x-api-token, x-access-token, x-csrf-token, x-request-id';
        $allowMethods = 'GET, POST, PUT, DELETE, OPTIONS';

        $headers = ['Content-type' => 'application/json; charset=UTF-8'];

        // Handle preflight
        if ($request->getMethod() === 'OPTIONS') {
            $response = new \Kotchasan\Http\Response();
            $corsHeaders = [];
            if (!empty($allowOrigin)) {
                $corsHeaders['Access-Control-Allow-Origin'] = $allowOrigin;
                $corsHeaders['Access-Control-Allow-Headers'] = $allowHeaders;
                $corsHeaders['Access-Control-Allow-Methods'] = $allowMethods;
            }
            $response->withHeaders($corsHeaders)->withStatus(204)->send();
            return;
        }

        if (empty(self::$cfg->api_tokens) || empty(self::$cfg->api_ips)) {
            // Token or IP authorization not set up
            $result = [
                'code' => 503,
                'message' => 'Unavailable API',
                'success' => false
            ];
        } elseif (in_array('0.0.0.0', self::$cfg->api_ips) || in_array($request->getClientIp(), self::$cfg->api_ips)) {
            try {
                // Get values from the router - support both patterns:
                $module = $request->get('module')->filter('a-z0-9');
                $method = $request->get('method')->filter('a-zA-Z');
                $action = $request->get('action', 'index')->filter('a-zA-Z');

                // Validate required route parts
                if (empty($module) || empty($method)) {
                    $result = [
                        'code' => 400,
                        'message' => 'Invalid route: module and method are required',
                        'success' => false
                    ];
                } else {
                    // Try Controller pattern first (v1/auth/login -> V1\Auth\Controller::login)
                    $controllerClass = ucfirst($module).'\\'.ucfirst($method).'\\Controller';

                    if (class_exists($controllerClass) && method_exists($controllerClass, $action)) {
                        // Instantiate controller and call method
                        $controller = new $controllerClass();
                        $result = $controller->$action($request);

                        // If result is a Response object, handle it specially
                        if ($result instanceof \Kotchasan\Http\Response) {
                            // Add CORS headers only for allowed origins.
                            if (!empty($allowOrigin)) {
                                $result = $result->withHeader('Access-Control-Allow-Origin', $allowOrigin)
                                    ->withHeader('Access-Control-Allow-Headers', $allowHeaders)
                                    ->withHeader('Access-Control-Allow-Methods', $allowMethods);
                            }
                            $result->send();
                            return;
                        }
                    } else {
                        // Error: class or method not found
                        $result = [
                            'code' => 404,
                            'message' => 'Endpoint not found: '.$controllerClass.'::'.$action,
                            'success' => false
                        ];
                    }
                }

                // Add CORS headers for JSON responses (only if we determined an allowed origin)
                if (!empty($allowOrigin)) {
                    $headers['Access-Control-Allow-Origin'] = $allowOrigin;
                    $headers['Access-Control-Allow-Headers'] = $allowHeaders;
                    $headers['Access-Control-Allow-Methods'] = $allowMethods;
                }
            } catch (ApiException $e) {
                // API Error - log to appropriate destination
                Logger::apiError($e->getCode(), $e->getMessage(), $this->getApiLogContext([
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]));

                $result = [
                    'code' => $e->getCode(),
                    'message' => $e->getMessage(),
                    'success' => false
                ];
            } catch (\Exception $e) {
                // General Exception - log with full stack trace
                $this->logApiThrowable($e, 500, [
                    'additional' => 'API request failed'
                ]);

                $result = [
                    'code' => 500,
                    'message' => 'Internal server error',
                    'success' => false
                ];

                // Only show detailed error in debug mode
                if (defined('DEBUG') && DEBUG == 2) {
                    $result['message'] = 'Internal server error: '.$e->getMessage();
                    $result['errors'] = $e->getMessage();
                    $result['trace'] = $e->getTraceAsString();
                }
            }
        } else {
            // IP not allowed - log security event
            Logger::security('API_IP_BLOCKED', [
                'blocked_ip' => $request->getClientIp(),
                'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown'
            ]);

            $result = [
                'code' => 403,
                'message' => 'Forbidden',
                'success' => false
            ];
        }

        // Return JSON response based on $result
        $response = new \Kotchasan\Http\Response();
        $response->withHeaders($headers)
            ->withStatus(empty($result['code']) ? 200 : $result['code'])
            ->withContent(json_encode($this->normalizeResult($result), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            ->send();
    }

    /**
     * Validate the API token.
     *
     * @param string $token The token to validate.
     *
     * @return bool True if the token is valid, otherwise throws an ApiException with an "Invalid token" error.
     */
    public static function validateToken($token)
    {
        $tokens = self::$cfg->api_tokens ?? [];

        if (in_array($token, $tokens, true)) {
            return true;
        }

        throw new ApiException('Invalid token', 401);
    }

    /**
     * Validate the Bearer token.
     *
     * @param Request $request The HTTP request object.
     *
     * @return bool True if the token is valid, otherwise throws an ApiException with an "Invalid token" error.
     */
    public static function validateTokenBearer(Request $request)
    {
        $authHeader = $request->getHeaderLine('Authorization');
        $tokens = self::$cfg->api_tokens ?? [];

        if (!$authHeader || !preg_match('/^Bearer\\s+(.+)$/', $authHeader, $matches)) {
            throw new ApiException('Invalid token', 401);
        }

        $token = $matches[1];

        if (in_array($token, $tokens, true)) {
            return true;
        }

        throw new ApiException('Invalid token', 401);
    }

    /**
     * Validate the sign.
     *
     * @param array $params The parameters to validate.
     *
     * @return bool True if the sign is valid, otherwise throws an ApiException with an "Invalid sign" error.
     */
    public static function validateSign($params)
    {
        if (count($params) > 1 && isset($params['sign'])) {
            $sign = $params['sign'];
            unset($params['sign']);
            if ($sign === \Kotchasan\Password::generateSign($params, self::$cfg->api_secret)) {
                return true;
            }
        }
        throw new ApiException('Invalid sign', 401);
    }

    /**
     * Validate the HTTP method.
     *
     * @param Request $request The HTTP request object.
     * @param string  $method  The expected HTTP method (e.g., POST, GET, PUT, DELETE, OPTIONS).
     *
     * @return bool True if the method is valid, otherwise throws an ApiException with a "Method not allowed" error.
     */
    public static function validateMethod(Request $request, $method)
    {
        if ($request->getMethod() === $method) {
            return true;
        }
        throw new ApiException('Method not allowed', 405);
    }

    /**
     * Validate IP address.
     *
     * @param Request $request The HTTP request object.
     *
     * @return bool True if the IP is allowed, otherwise throws an ApiException.
     */
    public static function validateIpAddress(Request $request)
    {
        $allowedIps = self::$cfg->api_ips ?? ['0.0.0.0'];

        if (in_array('0.0.0.0', $allowedIps)) {
            return true; // Allow all IPs
        }

        $clientIp = $request->getClientIp();
        if (in_array($clientIp, $allowedIps)) {
            return true;
        }

        throw new ApiException('IP not allowed', 403);
    }

    /**
     * Initialize language from request locale
     *
     * @param Request $request
     *
     * @return string Language code (e.g. 'th', 'en')
     */
    protected function initLanguage(Request $request)
    {
        $lang = strtolower($request->request('lang')->filter('a-zA-Z_-'));
        if ($lang === '') {
            $acceptableLanguages = $request->getAcceptableLanguages();
            if (!empty($acceptableLanguages)) {
                $lang = strtolower($acceptableLanguages[0]);
            }
        }
        if ($lang === '') {
            $lang = 'en';
        }
        if (($pos = strpos($lang, '-')) !== false) {
            $lang = substr($lang, 0, $pos);
        }
        if (($pos = strpos($lang, '_')) !== false) {
            $lang = substr($lang, 0, $pos);
        }
        \Kotchasan\Language::setName($lang);
        return $lang;
    }

    /**
     * Return success response
     *
     * @param mixed $data
     * @param string $message
     * @param int $code
     *
     * @return Response
     */
    protected function successResponse($data = null, $message = 'Success', $code = 200)
    {
        $response = [
            'success' => true,
            'message' => $message,
            'code' => $code,
            'data' => $data,
            'errors' => null,
            'request_id' => $this->getRequestId()
        ];

        return Response::create(json_encode($response), $code, $this->getResponseHeaders());
    }

    /**
     * Validate the CSRF token from the request header against the session.
     *
     * @param Request $request
     * @throws ApiException if the token is invalid or missing.
     */
    protected function validateCsrfToken(Request $request)
    {
        $sessionStartedHere = false;
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
            $sessionStartedHere = true;
        }

        try {
            $tokenHeader = $request->getHeaderLine('X-CSRF-TOKEN');
            // Require token in header
            if (empty($tokenHeader)) {
                throw new ApiException('CSRF Token required in X-CSRF-TOKEN header', 403);
            }

            // Check token format (64 hex characters)
            if (!preg_match('/^[a-f0-9]{64}$/', $tokenHeader)) {
                throw new ApiException('Invalid CSRF Token format', 403);
            }

            // Delegate to Request trait for unified validation
            if (!$request->validateCsrfToken($tokenHeader)) {
                throw new ApiException('Invalid CSRF Token', 403);
            }
        } finally {
            // Release session lock when this method started the session.
            if ($sessionStartedHere && session_status() == PHP_SESSION_ACTIVE) {
                session_write_close();
            }
        }

        return true;
    }

    /**
     * Create notification-only response
     *
     * @param string $message
     * @return Response
     */
    protected function notificationResponse(string $message)
    {
        return $this->successResponse([
            'actions' => [
                ['type' => 'notification', 'message' => $message]
            ]
        ], $message);
    }

    /**
     * Return form error response

     * @param array $errors
     * @param int $code
     * @param Exception $error
     *
     * @return Response
     */
    protected function formErrorResponse($errors, $code = 400, $error = null)
    {
        $response = [
            'success' => false,
            'message' => 'Validation failed',
            'data' => null,
            'errors' => $errors,
            'code' => $code,
            'request_id' => $this->getRequestId()
        ];

        if ($error && defined('DEBUG') && DEBUG == 2) {
            $response['message'] = $error->getMessage();
        }

        return Response::create(json_encode($response), $code, $this->getResponseHeaders());
    }

    /**
     * Return error response
     *
     * @param string $message
     * @param int $code
     * @param Exception $error
     *
     * @return Response
     */
    protected function errorResponse($message = 'Error', $code = 400, $error = null)
    {
        $requestId = $this->getRequestId();
        $response = [
            'success' => false,
            'message' => $message,
            'code' => $code,
            'data' => null,
            'errors' => null,
            'request_id' => $requestId
        ];

        if ($error instanceof \Throwable) {
            $this->logApiThrowable($error, (int) $code, [
                'response_message' => (string) $message
            ]);
        } elseif ((int) $code >= 500) {
            $caller = $this->getErrorCallerContext();
            Logger::apiError((int) $code, (string) $message, $this->getApiLogContext($caller));
        }

        if ($error && defined('DEBUG') && DEBUG == 2) {
            $response['errors'] = $error->getMessage();
            if ($error instanceof \Throwable) {
                $response['debug'] = [
                    'file' => $error->getFile(),
                    'line' => $error->getLine()
                ];
                $response['trace'] = $error->getTraceAsString();
            }
        } elseif ((int) $code >= 500 && defined('DEBUG') && DEBUG == 2) {
            $caller = $this->getErrorCallerContext();
            if (!empty($caller)) {
                $response['debug'] = $caller;
            }
        }

        return Response::create(json_encode($response), $code, $this->getResponseHeaders());
    }

    /**
     * Build request context for API error logs.
     *
     * @param array $context
     *
     * @return array
     */
    protected function getApiLogContext(array $context = [])
    {
        return array_merge([
            'request_id' => $this->getRequestId(),
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
            'client_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ], $context);
    }

    /**
     * Log a throwable with request-aware API context.
     *
     * @param \Throwable $error
     * @param int $code
     * @param array $context
     *
     * @return void
     */
    protected function logApiThrowable(\Throwable $error, int $code = 500, array $context = [])
    {
        Logger::logToDestination('error', 'Exception: '.$error->getMessage(), $this->getApiLogContext(array_merge([
            'http_code' => $code,
            'file' => $error->getFile(),
            'line' => $error->getLine(),
            'trace' => $error->getTraceAsString()
        ], $context)));
    }

    /**
     * Resolve the caller location that triggered errorResponse().
     *
     * @return array
     */
    protected function getErrorCallerContext()
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (($frame['class'] ?? null) === __CLASS__) {
                continue;
            }
            if (!empty($frame['file']) && $frame['file'] === __FILE__) {
                continue;
            }
            if (!empty($frame['file']) && !empty($frame['line'])) {
                return [
                    'caller_file' => $frame['file'],
                    'caller_line' => $frame['line']
                ];
            }
        }

        return [];
    }

    /**
     * Return validation error response (422).
     *
     * @param array $errors
     * @param string $message
     *
     * @return Response
     */
    protected function validationErrorResponse(array $errors, $message = 'Validation failed')
    {
        $response = [
            'success' => false,
            'message' => $message,
            'code' => 422,
            'data' => null,
            'errors' => $errors,
            'request_id' => $this->getRequestId()
        ];

        return Response::create(json_encode($response), 422, $this->getResponseHeaders());
    }

    /**
     * Convert exceptions into standardized API error responses.
     *
     * @param \Throwable $e
     * @param string $defaultMessage
     *
     * @return Response
     */
    protected function handleException(\Throwable $e, $defaultMessage = 'Failed to process request')
    {
        if ($e instanceof ApiException) {
            $code = (int) $e->getCode();
            if ($code < 400 || $code > 599) {
                $code = 400;
            }
            return $this->errorResponse($e->getMessage(), $code, $e);
        }

        if ($e instanceof \InvalidArgumentException) {
            return $this->errorResponse($e->getMessage(), 422, $e);
        }

        if ($e instanceof \DomainException) {
            return $this->errorResponse($e->getMessage(), 409, $e);
        }

        if ($e instanceof \RuntimeException) {
            return $this->errorResponse($e->getMessage(), 500, $e);
        }

        return $this->errorResponse($defaultMessage, 500, $e);
    }

    /**
     * Resolve allowed CORS origin from configuration and request.
     * In production, wildcard is not allowed and no CORS header is sent
     * unless origin matches same-host or configured allowlist.
     *
     * @param Request $request
     *
     * @return string
     */
    private function resolveAllowedOrigin(Request $request)
    {
        $configured = isset(self::$cfg->api_cors) ? trim((string) self::$cfg->api_cors) : '';
        $requestOrigin = trim((string) $request->getHeaderLine('Origin'));

        if ($requestOrigin === '') {
            return '';
        }

        if ($configured === '*') {
            // Strict fallback: only allow same-host origin.
            $webHost = parse_url(WEB_URL, PHP_URL_HOST);
            $originHost = parse_url($requestOrigin, PHP_URL_HOST);
            if (!empty($webHost) && !empty($originHost) && strcasecmp($webHost, $originHost) === 0) {
                return $requestOrigin;
            }
            return '';
        }

        if ($configured === '') {
            return '';
        }

        $allowed = array_filter(array_map('trim', explode(',', $configured)));
        return in_array($requestOrigin, $allowed, true) ? $requestOrigin : '';
    }

    /**
     * Return redirect response with actions
     * Used when API needs to redirect the client (e.g., 404, 403, or after successful operation)
     *
     * @param string $url - Redirect URL
     * @param string $message - Message to display
     * @param int $code - HTTP status code (default: 200)
     * @param int $delay - Delay in milliseconds before redirect (default: 0)
     * @param string $target - Target for redirect (default: '')
     *
     * @return Response
     */
    protected function redirectResponse($url, $message = '', $code = 200, $delay = 0, $target = '')
    {
        $actions = [
            [
                'type' => 'redirect',
                'url' => $url,
                'delay' => $delay
            ]
        ];

        if ($target !== '') {
            $actions[0]['target'] = $target;
        }

        // Add notification action if message is provided
        if (!empty($message)) {
            $actions[] = [
                'type' => 'notification',
                'message' => $message,
                'level' => $code >= 200 && $code < 300 ? 'success' : 'error'
            ];
        }

        $response = [
            'success' => $code >= 200 && $code < 300,
            'message' => $message,
            'code' => $code,
            'data' => [
                'actions' => $actions
            ],
            'errors' => null,
            'request_id' => $this->getRequestId()
        ];

        return Response::create(json_encode($response), $code, $this->getResponseHeaders());
    }

    /**
     * Extract access token from request (Authorization header / X-Access-Token / POST param)
     *
     * @param Request $request
     * @return mixed
     */
    protected function getAccessToken(Request $request)
    {
        $auth = $request->getHeaderLine('Authorization');
        if ($auth && preg_match('/Bearer\s+(.*)$/i', $auth, $m)) {
            return $m[1];
        }

        $h = $request->getHeaderLine('X-Access-Token');
        if (!empty($h)) {
            return $h;
        }

        $val = $request->request('access_token')->toString();
        if (!empty($val)) {
            return $val;
        }

        // Check for auth_token cookie (used by auth.php)
        try {
            if ($request->hasCookie('auth_token')) {
                $cookieToken = $request->cookie('auth_token')->toString();
                if (!empty($cookieToken)) {
                    return $cookieToken;
                }
            }
        } catch (\Throwable $e) {
            // ignore if cookie helpers unavailable
        }

        return null;
    }

    /**
     * Authenticate request and return user
     *
     * @param Request $request
     * @return object|null Returns user object if authenticated, null otherwise
     */
    protected function authenticateRequest(Request $request)
    {
        $accessToken = $this->getAccessToken($request);

        if (empty($accessToken)) {
            return null;
        }

        // 1. Try opaque/refresh tokens stored on the user row
        $user = \Index\Auth\Model::getUserByToken($accessToken);
        if ($user) {
            // Check token expiry if token_expires column exists
            if (isset($user->token_expires) && !empty($user->token_expires)) {
                $expiresAt = strtotime($user->token_expires);
                if ($expiresAt !== false && time() > $expiresAt) {
                    // Token has expired
                    return null;
                }
            }
            return $user;
        }

        // 2. Attempt to decode JWT access tokens when configured
        if (!empty(self::$cfg->jwt_secret)) {
            try {
                // Jwt::decode() already checks exp claim and returns null if expired
                $payload = \Kotchasan\Jwt::decode($accessToken, self::$cfg->jwt_secret);

                if (!empty($payload) && isset($payload['sub'])) {
                    $jwtUserId = (int) $payload['sub'];
                    if ($jwtUserId > 0) {
                        $jwtUser = \Index\Auth\Model::getUserById($jwtUserId);
                        if ($jwtUser) {
                            return $jwtUser;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // JWT decode failed (invalid signature, malformed, etc.)
                // Treat as unauthorized
            }
        }

        return null;
    }

    /**
     * Validate request data against rules
     *
     * @param Request $request HTTP request
     * @param array $rules Validation rules (e.g., ['email' => 'required|email', 'age' => 'integer|min:18'])
     * @return array [isValid (bool), errors (array)]
     */
    protected function validate(Request $request, array $rules): array
    {
        $errors = [];
        $parameters = $this->getRequestData($request);

        foreach ($rules as $field => $fieldRules) {
            $value = $parameters[$field] ?? null;

            // Split rules by |
            $rulesList = is_array($fieldRules) ? $fieldRules : explode('|', $fieldRules);

            foreach ($rulesList as $rule) {
                // Check if rule has parameters
                if (strpos($rule, ':') !== false) {
                    list($ruleName, $ruleParam) = explode(':', $rule, 2);
                } else {
                    $ruleName = $rule;
                    $ruleParam = null;
                }

                // Apply validation rule
                $result = $this->applyValidationRule($ruleName, $field, $value, $ruleParam, $parameters);

                if ($result !== true) {
                    $errors[$field][] = $result;
                    break; // Stop validation for this field after first error
                }
            }
        }

        return [empty($errors), $errors];
    }

    /**
     * Get request data based on HTTP method
     *
     * @param Request $request HTTP request
     * @return array
     */
    protected function getRequestData(Request $request): array
    {
        $method = strtoupper($request->getMethod());

        switch ($method) {
        case 'GET':
            return $request->getQueryParams();
        case 'POST':
        case 'PUT':
        case 'PATCH':
        case 'DELETE':
            $body = $request->getParsedBody();
            return is_array($body) ? $body : [];
        default:
            return [];
        }
    }

    /**
     * Apply a validation rule to a field
     *
     * @param string $rule Rule name
     * @param string $field Field name
     * @param mixed $value Field value
     * @param string|null $param Rule parameter
     * @param array $allValues All request values
     * @return true|string True if valid, error message otherwise
     */
    protected function applyValidationRule(string $rule, string $field, $value, ?string $param, array $allValues)
    {
        switch ($rule) {
        case 'required':
            return ($value !== null && $value !== '') ? true : "The $field field is required.";

        case 'email':
            return (filter_var($value, FILTER_VALIDATE_EMAIL) !== false) ? true : "The $field must be a valid email address.";

        case 'numeric':
            return (is_numeric($value)) ? true : "The $field must be numeric.";

        case 'integer':
            return (filter_var($value, FILTER_VALIDATE_INT) !== false) ? true : "The $field must be an integer.";

        case 'min':
            if (is_string($value)) {
                return (mb_strlen($value) >= (int) $param) ? true : "The $field must be at least $param characters.";
            }
            return ($value >= (int) $param) ? true : "The $field must be at least $param.";

        case 'max':
            if (is_string($value)) {
                return (mb_strlen($value) <= (int) $param) ? true : "The $field must not exceed $param characters.";
            }
            return ($value <= (int) $param) ? true : "The $field must not exceed $param.";

        case 'in':
            $allowedValues = explode(',', $param);
            return (in_array($value, $allowedValues)) ? true : "The $field must be one of: $param.";

        case 'date':
            return (strtotime($value) !== false) ? true : "The $field must be a valid date.";

        case 'json':
            if (!is_string($value)) {
                return "The $field must be a valid JSON string.";
            }
            json_decode($value);
            return (json_last_error() === JSON_ERROR_NONE) ? true : "The $field must be a valid JSON string.";

        case 'same':
            return ($value === ($allValues[$param] ?? null)) ? true : "The $field must match the $param field.";

        case 'regex':
            return (preg_match($param, $value)) ? true : "The $field format is invalid.";

        case 'url':
            return (filter_var($value, FILTER_VALIDATE_URL) !== false) ? true : "The $field must be a valid URL.";

        case 'ip':
            return (filter_var($value, FILTER_VALIDATE_IP) !== false) ? true : "The $field must be a valid IP address.";

        case 'array':
            return (is_array($value)) ? true : "The $field must be an array.";

        case 'boolean':
            return (is_bool($value) || in_array($value, [0, 1, '0', '1', true, false, 'true', 'false'], true)) ? true : "The $field must be boolean.";

        default:
            return true; // Unknown rule, consider valid
        }
    }

    /**
     * Sanitize and filter input data
     *
     * @param array $data Input data
     * @param array $filters Filters to apply (e.g., ['email' => 'email', 'age' => 'int'])
     * @return array Filtered data
     */
    protected function filter(array $data, array $filters): array
    {
        $filtered = [];

        foreach ($filters as $field => $filter) {
            if (!isset($data[$field])) {
                continue;
            }

            $value = $data[$field];

            switch ($filter) {
            case 'int':
                $filtered[$field] = (int) $value;
                break;

            case 'float':
                $filtered[$field] = (float) $value;
                break;

            case 'bool':
                $filtered[$field] = (bool) $value;
                break;

            case 'string':
                $filtered[$field] = (string) $value;
                break;

            case 'email':
                $filtered[$field] = filter_var($value, FILTER_SANITIZE_EMAIL);
                break;

            case 'url':
                $filtered[$field] = filter_var($value, FILTER_SANITIZE_URL);
                break;

            case 'strip_tags':
                $filtered[$field] = strip_tags((string) $value);
                break;

            case 'trim':
                $filtered[$field] = trim((string) $value);
                break;

            default:
                // If filter is a callable, use it
                if (is_callable($filter)) {
                    $filtered[$field] = $filter($value);
                } else {
                    $filtered[$field] = $value;
                }
            }
        }

        return $filtered;
    }

    /**
     * Normalize result array to include success flag consistently
     *
     * @param array $result
     * @return array
     */
    protected function normalizeResult(?array $result): array
    {
        // Handle null result (when controller action doesn't return a value)
        if ($result === null) {
            $result = [
                'success' => true,
                'code' => 200
            ];
        }

        if (!isset($result['success'])) {
            $result['success'] = empty($result['code']) || ($result['code'] >= 200 && $result['code'] < 300);
        }
        if (!array_key_exists('data', $result)) {
            $result['data'] = null;
        }
        if (!array_key_exists('errors', $result)) {
            $result['errors'] = null;
        }
        if (!isset($result['request_id'])) {
            $result['request_id'] = $this->getRequestId();
        }
        return $result;
    }

    /**
     * Get standard response headers including new CSRF token
     *
     * @return array
     */
    protected function getResponseHeaders(): array
    {
        $headers = [
            'Content-Type' => 'application/json; charset=utf-8',
            'Access-Control-Expose-Headers' => 'X-CSRF-Token, X-Request-Id',
            'X-Request-Id' => $this->getRequestId()
        ];

        $sessionStartedHere = false;

        // Generate new CSRF token and include in response header for frontend to update.
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
            $sessionStartedHere = true;
        }

        try {
            $newToken = bin2hex(random_bytes(32));
            $_SESSION[$newToken] = [
                'times' => 0,
                'expired' => time() + (defined('TOKEN_AGE') ? TOKEN_AGE : 3600),
                'created' => time()
            ];

            $headers['X-CSRF-Token'] = $newToken;
        } finally {
            if ($sessionStartedHere && session_status() == PHP_SESSION_ACTIVE) {
                session_write_close();
            }
        }

        return $headers;
    }

    /**
     * Resolve request ID from incoming header or generate one.
     *
     * @return string
     */
    protected function getRequestId()
    {
        if (!empty(self::$requestId)) {
            return self::$requestId;
        }

        $incoming = isset($_SERVER['HTTP_X_REQUEST_ID']) ? trim((string) $_SERVER['HTTP_X_REQUEST_ID']) : '';
        if ($incoming !== '') {
            self::$requestId = preg_replace('/[^a-zA-Z0-9\-_.]/', '', $incoming);
        }

        if (empty(self::$requestId)) {
            self::$requestId = bin2hex(random_bytes(8));
        }

        return self::$requestId;
    }
}
