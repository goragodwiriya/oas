<?php
/**
 * @filesource modules/index/controllers/system.php
 *
 * Standard system endpoints for monitoring and deployment.
 */

namespace Index\System;

use Gcms\Api as ApiController;
use Kotchasan\Http\Request;

class Controller extends ApiController
{
    /**
     * GET /index/system/health
     * Lightweight liveness probe.
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function health(Request $request)
    {
        ApiController::validateMethod($request, 'GET');

        return $this->successResponse([
            'status' => 'ok'
        ], 'Service is healthy');
    }

    /**
     * GET /index/system/readiness
     * Readiness probe with basic dependency checks.
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function readiness(Request $request)
    {
        ApiController::validateMethod($request, 'GET');

        $checks = [
            'config_loaded' => !empty(self::$cfg->version),
            'storage_writable' => is_dir(ROOT_PATH.DATA_FOLDER.'cache/') && is_writable(ROOT_PATH.DATA_FOLDER.'cache/')
        ];

        try {
            \Kotchasan\DB::create()->first('user', [['id', 0]], ['id']);
            $checks['database'] = true;
        } catch (\Throwable $e) {
            $checks['database'] = false;
        }

        $ready = !in_array(false, $checks, true);

        if (!$ready) {
            return $this->errorResponse('Service is not ready', 503);
        }

        return $this->successResponse([
            'status' => 'ready',
            'checks' => $checks
        ], 'Service is ready');
    }

    /**
     * GET /index/system/version
     * Application version information.
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function version(Request $request)
    {
        ApiController::validateMethod($request, 'GET');

        return $this->successResponse([
            'version' => (string) (self::$cfg->version ?? 'unknown'),
            'reversion' => (string) (self::$cfg->reversion ?? ''),
            'environment' => defined('DEBUG') && DEBUG == 2 ? 'development' : 'production'
        ], 'Version info');
    }

    /**
     * GET /index/system/time
     * Server time endpoint.
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function time(Request $request)
    {
        ApiController::validateMethod($request, 'GET');

        return $this->successResponse([
            'unix' => time(),
            'iso8601' => gmdate('c'),
            'timezone' => date_default_timezone_get()
        ], 'Server time');
    }

    /**
     * GET /index/system/features
     * Public feature flags used by clients.
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function features(Request $request)
    {
        ApiController::validateMethod($request, 'GET');

        return $this->successResponse([
            'user_register' => !empty(self::$cfg->user_register),
            'user_forgot' => !empty(self::$cfg->user_forgot),
            'activate_user' => !empty(self::$cfg->activate_user),
            'auth_hardening' => [
                'refresh_rotation' => true,
                'revoke_list' => true,
                'session_device_tracking' => true
            ]
        ], 'Feature flags');
    }
}
