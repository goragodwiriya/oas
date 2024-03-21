<?php
/**
 * @filesource Kotchasan/ApiController.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan;

use Kotchasan\Http\Request;

/**
 * API Controller base class for handling API requests.
 *
 * @see https://www.kotchasan.com/
 */
class ApiController extends \Kotchasan\KBase
{
    /**
     * API Controller index action.
     *
     * @param Request $request The HTTP request object.
     */
    public function index(Request $request)
    {
        $headers = array('Content-type' => 'application/json; charset=UTF-8');
        if (empty(self::$cfg->api_token) || empty(self::$cfg->api_ips)) {
            // Token or IP authorization not set up
            $result = array(
                'code' => 503,
                'message' => 'Unavailable API'
            );
        } elseif (in_array('0.0.0.0', self::$cfg->api_ips) || in_array($request->getClientIp(), self::$cfg->api_ips)) {
            try {
                // Get values from the router
                $module = $request->get('module')->filter('a-z0-9');
                $method = $request->get('method')->filter('a-z');
                $action = $request->get('action')->filter('a-z');
                // Convert to class name for the model, e.g., api.php/v1/user/create becomes V1\User\Model::create
                $className = ucfirst($module).'\\'.ucfirst($method).'\\Model';
                // Check if method exists
                if (method_exists($className, $action)) {
                    // Instantiate class and call method
                    $result = createClass($className)->$action($request);
                    // CORS
                    if (!empty(self::$cfg->api_cors)) {
                        $headers['Access-Control-Allow-Origin'] = self::$cfg->api_cors;
                        $headers['Access-Control-Allow-Headers'] = 'origin, x-requested-with, content-type';
                    }
                } else {
                    // Error: class or method not found
                    $result = array(
                        'code' => 404,
                        'message' => 'Object Not Found'
                    );
                }
            } catch (ApiException $e) {
                // API Error
                $result = array(
                    'code' => $e->getCode(),
                    'message' => $e->getMessage()
                );
            }
        } else {
            // IP not allowed
            $result = array(
                'code' => 403,
                'message' => 'Forbidden'
            );
        }
        // Return JSON response based on $result
        $response = new \Kotchasan\Http\Response();
        $response->withHeaders($headers)
            ->withStatus(empty($result['code']) ? 200 : $result['code'])
            ->withContent(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
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
        if (self::$cfg->api_token === $token) {
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
        if (preg_match('/^Bearer\s'.self::$cfg->api_token.'$/', $request->getHeaderLine('Authorization'))) {
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
        throw new ApiException('Invalid sign', 403);
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
}
