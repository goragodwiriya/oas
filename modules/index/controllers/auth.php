<?php
/**
 * @filesource modules/index/controllers/auth.php
 *
 * Production-grade API Authentication Controller
 *
 * Security Features:
 * - Rate limiting with exponential backoff
 * - Secure password verification (timing-safe comparison)
 * - CSRF protection
 * - httpOnly cookies for tokens
 * - Activity logging
 * - Input sanitization
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Index\Auth;

use Gcms\Api as ApiController;
use Index\AuthValidation\Model as AuthValidation;
use Kotchasan\Http\Request;

/**
 * API Authentication Controller
 *
 * Handles user authentication endpoints with production-grade security
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends ApiController
{
    /**
     * Cookie options for httpOnly cookies
     *
     * @var array
     */
    private $cookieOptions = [
        'path' => '/',
        'domain' => '',
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Lax'
    ];

    /**
     * Initialize controller
     */
    public function __construct()
    {
        // Set secure cookie in production (HTTPS)
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            $this->cookieOptions['secure'] = true;
            $this->cookieOptions['samesite'] = 'Strict';
        }
    }

    /**
     * GET /index/auth/csrf-token
     * Generate a CSRF token for the current session.
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function csrfToken(Request $request)
    {
        $sessionStartedHere = false;
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
            $sessionStartedHere = true;
        }

        $token = $request->generateCsrfToken();

        // Release session lock as early as possible for better concurrent throughput.
        if ($sessionStartedHere && session_status() == PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        return $this->successResponse([
            'csrf_token' => $token
        ], 'CSRF token generated');
    }

    /**
     * POST /index/auth/login
     * Authenticate user and return access token.
     *
     * Security measures:
     * - CSRF validation
     * - Rate limiting
     * - Secure password verification
     * - httpOnly cookie for token
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function login(Request $request)
    {
        try {
            // Validate request method
            ApiController::validateMethod($request, 'POST');

            // Validate CSRF token
            $this->validateCsrfToken($request);

            // Get credentials and client IP for logging and rate limiting
            $username = $request->post('username')->username();
            $password = $request->post('password')->password();
            $clientIp = $request->getClientIp();

            // Get intended URL for redirect after login (use topic() to allow relative paths)
            $intendedUrl = $request->post('intended_url')->url();

            // Validate input payload
            $validation = AuthValidation::validateLogin($username, $password);
            if (!$validation['valid']) {
                return $this->validationErrorResponse($validation['errors']);
            }

            // Authenticate user
            $result = Model::authenticate($username, $password, $clientIp, $this->buildDeviceContext($request, $clientIp));

            if (!$result['success']) {
                // Return appropriate error
                $statusCode = $this->getStatusCodeForError($result['code'] ?? 'AUTH_FAILED');
                return $this->errorResponse($result['message'], $statusCode);
            }

            // Set httpOnly cookie for token (protects against XSS)
            $this->setAuthCookie('auth_token', $result['token'], Model::TOKEN_EXPIRY);

            // Set refresh token cookie (longer expiry)
            if (isset($result['refresh_token'])) {
                $this->setAuthCookie('refresh_token', $result['refresh_token'], Model::REFRESH_TOKEN_EXPIRY);
            }

            // Determine redirect URL (intended URL or default)
            $redirectUrl = $this->validateRedirectUrl($intendedUrl) ?: '.';

            // Prepare response (include token for backward compatibility)
            $response = [
                'user' => $result['user'],
                'token' => $result['token'],
                'refresh_token' => $result['refresh_token'] ?? null,
                'expires_in' => $result['expires_in'],
                'token_type' => $result['token_type'],
                'actions' => [
                    ['type' => 'redirect', 'url' => $redirectUrl]
                ]
            ];

            return $this->successResponse($response, 'Login successful');
        } catch (\Throwable $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Validate redirect URL for security (same-origin only).
     *
     * @param string|null $url
     *
     * @return string|null Validated URL or null if invalid
     */
    private function validateRedirectUrl($url)
    {
        if (empty($url)) {
            return null;
        }

        // Only allow relative URLs starting with /
        if (strpos($url, '/') === 0 && strpos($url, '//') !== 0) {
            // Prevent path traversal
            $url = str_replace(['..', "\0"], '', $url);
            return $url;
        }

        return null;
    }

    /**
     * GET /index/auth/verify
     * Verify the provided access token and return user info if valid.
     *
     * @param Request $request
     *
     * @return mixed
     */
    public function verify(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'GET');

            // Get token from cookie or header
            $accessToken = $this->getAccessTokenFromRequest($request);

            if (!$accessToken) {
                return $this->errorResponse('Access token required', 401);
            }

            // Verify token and get user
            $login = Model::getUserByToken($accessToken);

            if (!$login) {
                // Clear invalid cookies
                $this->clearAuthCookies();

                return $this->errorResponse('Invalid or expired access token', 401);
            }

            // Return user data
            $userData = Model::sanitizeUserData($login);

            return $this->successResponse($userData, 'Token verified');
        } catch (\Throwable $e) {
            return $this->handleException($e);
        }
    }

    /**
     * POST /index/auth/logout
     * Invalidate the current access token.
     * If impersonating, restore to admin session instead.
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function logout(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'POST');
            $this->validateCsrfToken($request);

            // Get token from cookie or header
            $accessToken = $this->getAccessTokenFromRequest($request);

            if ($accessToken) {
                // Check if currently impersonating
                $impersonateInfo = Model::isImpersonating($accessToken);

                if ($impersonateInfo) {
                    // Restore to admin session
                    $clientIp = $request->getClientIp();
                    $result = Model::restoreAdmin($accessToken, $clientIp);

                    if ($result['success']) {
                        // Set new admin token cookie
                        $this->setAuthCookie('auth_token', $result['token'], Model::TOKEN_EXPIRY);

                        if (isset($result['refresh_token'])) {
                            $this->setAuthCookie('refresh_token', $result['refresh_token'], Model::REFRESH_TOKEN_EXPIRY);
                        }

                        // Return success with admin user data
                        return $this->successResponse([
                            'user' => $result['user'],
                            'restored' => true,
                            'actions' => [
                                ['type' => 'notification', 'message' => 'Restored to admin session', 'variant' => 'success'],
                                ['type' => 'redirect', 'url' => 'reload']
                            ]
                        ], 'Restored to admin session');
                    }
                }

                // Normal logout (not impersonating)
                $payload = Model::verifyToken($accessToken);
                $userId = $payload['sub'] ?? null;

                // Logout in database
                Model::logout($accessToken, $userId);
            }

            // Clear all auth cookies
            $this->clearAuthCookies();

            return $this->successResponse(null, 'Logout successful');
        } catch (\Kotchasan\ApiException $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode(), $e);
        } catch (\Exception $e) {
            // Best-effort cleanup while still reporting unexpected failures.
            $this->clearAuthCookies();
            \Kotchasan\Logger::exception($e, 'Logout failed unexpectedly');

            return $this->errorResponse('Failed to process request', 500, $e);
        }
    }

    /**
     * POST /index/auth/refresh
     * Refresh access token using refresh token.
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function refresh(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'POST');
            $this->validateCsrfToken($request);

            // Get refresh token from request or cookie
            $refreshToken = $request->post('refresh_token')->toString();

            if (empty($refreshToken) && $request->hasCookie('refresh_token')) {
                $refreshToken = $request->cookie('refresh_token')->toString();
            }

            if (empty($refreshToken)) {
                return $this->errorResponse('Refresh token is required', 400);
            }

            // Refresh the token
            $result = Model::refreshAccessToken($refreshToken, $this->buildDeviceContext($request, $request->getClientIp()));

            if (!$result['success']) {
                // Clear invalid cookies
                $this->clearAuthCookies();

                $statusCode = $this->getStatusCodeForError($result['code'] ?? 'INVALID_TOKEN');
                return $this->errorResponse($result['message'], $statusCode);
            }

            // Set new cookies
            $this->setAuthCookie('auth_token', $result['token'], Model::TOKEN_EXPIRY);

            if (isset($result['refresh_token'])) {
                $this->setAuthCookie('refresh_token', $result['refresh_token'], Model::REFRESH_TOKEN_EXPIRY);
            }

            // Prepare response
            $response = [
                'token' => $result['token'],
                'refresh_token' => $result['refresh_token'] ?? null,
                'expires_in' => $result['expires_in'],
                'token_type' => $result['token_type'],
                'user' => $result['user'] ?? null
            ];

            return $this->successResponse($response, 'Token refreshed successfully');
        } catch (\Throwable $e) {
            return $this->handleException($e);
        }
    }

    /**
     * GET /index/auth/me
     * Retrieve information about the currently authenticated user.
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function me(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'GET');

            // Get token from cookie or header
            $accessToken = $this->getAccessTokenFromRequest($request);

            // Verify token and get user
            $login = Model::getUserByToken($accessToken);

            // Cookie present but token rejected (expired/revoked) - clear it so the
            // browser does not keep sending dead credentials on future requests.
            if ($accessToken && !$login) {
                $this->clearAuthCookies();
            }

            // Add logo URL if exists
            $img = DATA_FOLDER.'images/logo'.self::$cfg->stored_img_type;
            if (is_file(ROOT_PATH.$img)) {
                $logo = WEB_URL.$img;
            } else {
                $logo = WEB_URL.'images/logo.svg';
            }

            if ($login) {
                // Add avatar URL if exists
                $avatar = self::getAvatarUrl($login->id) ?? WEB_URL.'images/default-avatar.webp';

                // Return sanitized user data
                $userData = Model::sanitizeUserData([
                    'id' => $login->id,
                    'name' => $login->name,
                    'username' => $login->username,
                    'avatar' => $avatar,
                    'status' => $login->status,
                    'social' => $login->social,
                    'permission' => $login->permission,
                    'metas' => $login->metas,
                    'web_title' => self::$cfg->web_title,
                    'web_description' => self::$cfg->web_description,
                    'web_url' => WEB_URL,
                    'logo' => $logo
                ]);
            } else {
                // Return Guest
                $userData = Model::sanitizeUserData([
                    'id' => 0,
                    'name' => 'Guest',
                    'username' => 'guest',
                    'avatar' => WEB_URL.'images/default-avatar.webp',
                    'status' => 0,
                    'social' => [],
                    'permission' => [],
                    'metas' => [],
                    'web_title' => self::$cfg->web_title,
                    'web_description' => self::$cfg->web_description,
                    'web_url' => WEB_URL,
                    'logo' => $logo
                ]);
            }

            // Add menus to user data
            $userData['menus'] = \Index\Menus\Controller::getMenus($login);

            return $this->successResponse($userData, 'User information retrieved');
        } catch (\Throwable $e) {
            return $this->handleException($e);
        }
    }

    /**
     * POST /index/auth/update
     * Update user profile.
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function update(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'POST');
            $this->validateCsrfToken($request);

            // Get token from cookie or header
            $accessToken = $this->getAccessTokenFromRequest($request);

            if (!$accessToken) {
                return $this->errorResponse('Access token required', 401);
            }

            // Verify token
            $payload = Model::verifyToken($accessToken);
            if (!$payload) {
                return $this->errorResponse('Invalid access token', 401);
            }

            $userId = $payload['sub'];

            // Get update data
            $name = $request->post('name')->topic();
            $password = $request->post('password')->password();
            $repassword = $request->post('repassword')->password();

            $validation = AuthValidation::validateUpdateProfile($name, $password, $repassword);
            if (!$validation['valid']) {
                return $this->validationErrorResponse($validation['errors']);
            }

            // Prepare update data
            $updateData = [
                'name' => $name,
                'phone' => $request->post('phone')->number(),
                'sex' => $request->post('sex')->filter('fmu'),
                'provinceID' => $request->post('provinceID')->toInt(),
                'address' => $request->post('address')->topic(),
                'address2' => $request->post('address2')->topic(),
                'zipcode' => $request->post('zipcode')->topic(),
                'birthday' => $request->post('birthday')->date(),
                'website' => $request->post('website')->url(),
                'company' => $request->post('company')->topic()
            ];

            // Update profile
            $result = Model::updateProfile($userId, $updateData, !empty($password) ? $password : null);

            if (!$result['success']) {
                return $this->errorResponse($result['message'], 400);
            }

            // Return success with actions
            return $this->successResponse([
                'actions' => [
                    ['type' => 'modal', 'action' => 'close'],
                    ['type' => 'notification', 'message' => 'Profile updated successfully']
                ]
            ], 'Profile updated successfully');
        } catch (\Throwable $e) {
            return $this->handleException($e);
        }
    }

    /**
     * POST /index/auth/forgot
     * Initiate password reset process.
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function forgot(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'POST');
            $this->validateCsrfToken($request);

            // Check if forgot password is enabled
            if (empty(self::$cfg->user_forgot)) {
                return $this->errorResponse('This feature is currently disabled', 403);
            }

            $identifier = $request->post('username')->username();

            $validation = AuthValidation::validateForgotPassword($identifier);
            if (!$validation['valid']) {
                return $this->validationErrorResponse($validation['errors']);
            }

            // Check rate limiting for password reset
            $clientIp = $request->getClientIp();
            $rateLimitResult = Model::checkRateLimit($identifier.'_forgot', $clientIp);

            if (!$rateLimitResult['allowed']) {
                return $this->errorResponse($rateLimitResult['message'], 429);
            }

            // Find user
            $login = Model::findUserByCredentials($identifier);

            // Always return success to prevent user enumeration
            // But only send email if user exists
            if ($login) {
                try {
                    // Get base URL by removing last path segment (e.g., /forgot)
                    $referrer = $request->server('HTTP_REFERER');
                    $baseUrl = !empty($referrer) ? substr($referrer, 0, strrpos($referrer, '/') + 1) : WEB_URL;

                    \Index\Forgot\Model::execute($login->id, $login->username, $baseUrl);
                } catch (\Exception $e) {
                    return $this->errorResponse('Failed to process request', 500, $e);
                }
            }

            // Record attempt
            Model::recordLoginAttempt($identifier.'_forgot', $clientIp, false);

            return $this->successResponse(null, 'If the account exists, a password reset email has been sent');
        } catch (\Throwable $e) {
            return $this->handleException($e);
        }
    }

    /**
     * POST /index/auth/resetpassword
     * Reset user password using token from email.
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function resetpassword(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'POST');
            $this->validateCsrfToken($request);

            // Check if forgot password is enabled
            if (empty(self::$cfg->user_forgot)) {
                return $this->errorResponse('This feature is currently disabled', 403);
            }

            // Get parameters
            $token = $request->post('token')->username();
            $userId = $request->post('uid')->toInt();
            $password = $request->post('password')->username();
            $repassword = $request->post('repassword')->username();

            $validation = AuthValidation::validateResetPassword($token, $userId, $password, $repassword);
            if (!$validation['valid']) {
                return $this->validationErrorResponse($validation['errors']);
            }

            if (!\Index\Forgot\Model::verifyResetToken($token, $userId)) {
                return $this->errorResponse('Invalid or expired reset link. Please request a new password reset.', 400);
            }

            // Reset password
            \Index\Forgot\Model::resetPassword($userId, $password);

            // Prepare response with redirect action
            $response = [
                'actions' => [
                    ['type' => 'notification', 'message' => 'Password reset successfully! You can now login with your new password.', 'variant' => 'success'],
                    ['type' => 'redirect', 'url' => '/login']
                ]
            ];

            return $this->successResponse($response, 'Password reset successfully');
        } catch (\Throwable $e) {
            return $this->handleException($e);
        }
    }

    /**
     * POST /index/auth/register
     * Register a new user account (guest registration).
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function register(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'POST');
            $this->validateCsrfToken($request);

            // Check if registration is enabled
            if (empty(self::$cfg->user_register)) {
                return $this->errorResponse('This feature is currently disabled', 403);
            }

            // Get client IP for rate limiting
            $clientIp = $request->getClientIp();

            // Check rate limiting
            if (!\Index\Register\Model::checkRegistrationRateLimit($clientIp)) {
                return $this->errorResponse('Please wait a moment before trying again', 429);
            }

            // Get and validate input
            $username = $request->post('username')->username();
            $name = $request->post('name')->topic();
            $password = $request->post('password')->password();
            $repassword = $request->post('repassword')->password();

            $validation = AuthValidation::validateRegister(
                $username,
                $name,
                $password,
                $repassword,
                self::$cfg->login_fields ?? ['username']
            );
            if (!$validation['valid']) {
                return $this->validationErrorResponse($validation['errors']);
            }

            // Get base URL by removing last path segment (e.g., /forgot)
            $referrer = $request->server('HTTP_REFERER');
            $baseUrl = !empty($referrer) ? substr($referrer, 0, strrpos($referrer, '/') + 1) : WEB_URL;

            $result = \Index\Register\Model::register([
                'username' => $username,
                'name' => $name,
                'password' => $password
            ], $baseUrl);

            if (!$result['success']) {
                return $this->errorResponse($result['message'], $result['code'] ?? 400);
            }

            // Set auth cookie if token provided (auto-login)
            if (!empty($result['token'])) {
                $this->setAuthCookie('auth_token', $result['token'], Model::TOKEN_EXPIRY);
            }

            // Prepare response
            $response = [
                'user' => $result['user'] ?? null,
                'requiresVerification' => $result['requiresVerification'] ?? false
            ];

            // Add actions for frontend
            $actions = [];
            if (!empty($result['requiresVerification'])) {
                $actions[] = ['type' => 'notification', 'message' => $result['message'], 'variant' => 'info'];
                $actions[] = ['type' => 'redirect', 'url' => '/login'];
            } elseif (!empty($result['token'])) {
                $actions[] = ['type' => 'notification', 'message' => $result['message'], 'variant' => 'success'];
                $actions[] = ['type' => 'redirect', 'url' => '/'];
            } else {
                $actions[] = ['type' => 'notification', 'message' => $result['message'], 'variant' => 'success'];
                $actions[] = ['type' => 'redirect', 'url' => 'login'];
            }

            $response['actions'] = $actions;

            return $this->successResponse($response, $result['message']);
        } catch (\Throwable $e) {
            return $this->handleException($e);
        }
    }

    /**
     * GET /index/auth/activate
     * Activate user account via email verification link.
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function activate(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'GET');

            // Get activation code from query parameter
            $code = $request->get('id')->username();

            if (empty($code)) {
                return $this->errorResponse('Invalid activation link', 400);
            }

            // Activate user
            $result = \Index\Register\Model::activateUser($code);

            if (!$result['success']) {
                return $this->errorResponse($result['message'], 400);
            }

            // Return success with redirect action
            return $this->successResponse([
                'user' => $result['user'] ?? null,
                'actions' => [
                    ['type' => 'notification', 'message' => $result['message'], 'variant' => 'success'],
                    ['type' => 'redirect', 'url' => '/login']
                ]
            ], $result['message']);
        } catch (\Throwable $e) {
            return $this->handleException($e);
        }
    }

    /**
     * GET /index/auth/settings
     * Get public auth settings (for showing/hiding register and forgot links).
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function settings(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'GET');

            $settings = [
                'user_register' => !empty(self::$cfg->user_register),
                'user_forgot' => !empty(self::$cfg->user_forgot),
                'activate_user' => !empty(self::$cfg->activate_user)
            ];

            return $this->successResponse($settings, 'Settings retrieved');
        } catch (\Throwable $e) {
            return $this->handleException($e, 'Failed to get settings');
        }
    }

    // ========== HELPER METHODS ==========

    /**
     * Get access token from request (cookie or header)
     *
     * @param Request $request
     *
     * @return string|null
     */
    private function getAccessTokenFromRequest(Request $request)
    {
        // Try cookie first (more secure)
        if ($request->hasCookie('auth_token')) {
            $token = $request->cookie('auth_token')->toString();
            if (!empty($token)) {
                return $token;
            }
        }

        // Fallback to Authorization header
        return $this->getAccessToken($request);
    }

    /**
     * Set authentication cookie
     *
     * @param string $name Cookie name
     * @param string $value Cookie value
     * @param int $expiry Expiry time in seconds
     */
    private function setAuthCookie($name, $value, $expiry)
    {
        $options = array_merge($this->cookieOptions, [
            'expires' => time() + $expiry
        ]);

        setcookie($name, $value, $options);
    }

    /**
     * Clear all authentication cookies
     */
    private function clearAuthCookies()
    {
        $options = array_merge($this->cookieOptions, [
            'expires' => time() - 3600
        ]);

        setcookie('auth_token', '', $options);
        setcookie('refresh_token', '', $options);
    }

    /**
     * Get HTTP status code for error type
     *
     * @param string $errorCode
     *
     * @return int
     */
    private function getStatusCodeForError($errorCode)
    {
        $statusCodes = [
            'INVALID_INPUT' => 422,
            'VALIDATION_FAILED' => 422,
            'AUTH_FAILED' => 401,
            'ACCOUNT_INACTIVE' => 403,
            'EMAIL_NOT_VERIFIED' => 403,
            'RATE_LIMITED' => 429,
            'USER_NOT_FOUND' => 404,
            'INVALID_TOKEN' => 401,
            'USER_INVALID' => 401,
            'ALREADY_EXISTS' => 409,
            'CONFLICT' => 409
        ];

        return $statusCodes[$errorCode] ?? 400;
    }

    /**
     * Build device context used for token hardening.
     *
     * @param Request $request
     * @param string|null $clientIp
     *
     * @return array
     */
    private function buildDeviceContext(Request $request, $clientIp = null)
    {
        $ip = (string) ($clientIp ?? $request->getClientIp() ?? '');
        $ua = (string) $request->server('HTTP_USER_AGENT');
        $fingerprint = hash('sha256', $ip.'|'.$ua);

        return [
            'ip' => $ip,
            'user_agent' => $ua,
            'fingerprint' => $fingerprint
        ];
    }
}
