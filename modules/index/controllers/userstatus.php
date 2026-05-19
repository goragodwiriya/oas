<?php
/**
 * @filesource modules/index/controllers/userstatus.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Userstatus;

use Gcms\Api as ApiController;
use Gcms\Config;
use Kotchasan\Http\Request;
use Kotchasan\Http\Response;

/**
 * API Userstatus Controller
 *
 * Handles User Status endpoints
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends ApiController
{
    /**
     * GET /api/index/userstatus/get
     * Get User Status details by ID
     *
     * @param Request $request
     *
     * @return Response
     */
    public function get(Request $request)
    {
        try {
            // Validate request method (GET request doesn't need CSRF token)
            ApiController::validateMethod($request, 'GET');

            // Read user from token (Bearer /X-Access-Token param)
            $login = $this->authenticateRequest($request);

            // Check authentication first (token missing or expired
            if (!$login) {
                return $this->errorResponse('Unauthorized', 401);
            }

            // Authorization check
            if (!ApiController::hasPermission($login, ['can_config'])) {
                return $this->errorResponse('Forbidden', 403);
            }

            $user_status = [];
            foreach (self::$cfg->member_status as $key => $value) {
                $user_status[] = [
                    'id' => $key,
                    'status' => $key,
                    'color' => isset(self::$cfg->color_status[$key]) ? self::$cfg->color_status[$key] : '#000000',
                    'topic' => $value
                ];
            }

            return $this->successResponse([
                'userstatus' => $user_status
            ], 'User status retrieved');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }

    /**
     * POST /api/index/userstatus/save
     * Save userstatus (create or update)
     *
     * @param Request $request
     * @return Response
     */
    public function save(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'POST');
            $this->validateCsrfToken($request);
            $login = $this->authenticateRequest($request);

            if (!$login) {
                return $this->redirectResponse('/login', 'Unauthorized', 401);
            }

            // Authorization for saving
            if (!ApiController::canModify($login)) {
                return $this->errorResponse('Permission required', 403);
            }

            // Data from request
            $topics = $request->post('topic', [])->topic();
            $colors = $request->post('color', [])->topic();

            // Load config
            $config = Config::load(ROOT_PATH.'settings/config.php');

            $member_status = [];
            $color_status = [];
            $status = 0;
            foreach ($topics as $key => $value) {
                $member_status[$key] = $value === '' && isset($config->member_status[$key]) ? $config->member_status[$key] : $value;
                $color_status[$key] = empty($colors[$key]) ? '#000000' : $colors[$key];
                $status++;
            }

            if (count($member_status) < 3) {
                return $this->errorResponse('Minimum 2 user status', 500);
            }

            $config->member_status = $member_status;
            $config->color_status = $color_status;

            // Save config
            if (Config::save($config, ROOT_PATH.'settings/config.php')) {
                // Log
                \Index\Log\Model::add(0, 'index', 'Save', 'Save User Status', $login->id);

                // Reload page
                return $this->redirectResponse('reload', 'Saved successfully');
            }
            // Error save settings
            return $this->errorResponse('Failed to save settings', 500);
        } catch (\Kotchasan\ApiException $e) {
            // Keep original HTTP code (e.g. 403 CSRF, 405 method)
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to save settings: '.$e->getMessage(), 500);
        }
    }
}
