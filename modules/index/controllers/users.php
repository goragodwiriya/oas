<?php
/**
 * @filesource modules/index/controllers/users.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Users;

use Gcms\Api as ApiController;
use Kotchasan\Http\Request;
use Kotchasan\Http\Response;

/**
 * API Users Controller
 *
 * Handles user management endpoints
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Gcms\Table
{
    /**
     * @var array Social login types
     */
    public static $socialTypes = [
        0 => 'Registered',
        1 => 'Facebook',
        2 => 'Google',
        3 => 'LINE',
        4 => 'Telegram'
    ];

    /**
     * @var array Social icons
     */
    public static $socialIcons = [
        1 => 'icon-facebook',
        2 => 'icon-google',
        3 => 'icon-line',
        4 => 'icon-telegram'
    ];

    /**
     * Allowed sort columns for SQL injection prevention
     *
     * @var array
     */
    protected $allowedSortColumns = ['id', 'name', 'department', 'active', 'created_at', 'status'];

    /**
     * Check authorization for user management
     * Only admins can access, demo mode is blocked
     *
     * @param Request $request
     * @param $login
     *
     * @return mixed
     */
    protected function checkAuthorization(Request $request, $login)
    {
        // Check if user is admin
        if (!ApiController::isSuperAdmin($login)) {
            return $this->errorResponse('Forbidden', 403);
        }

        return true;
    }

    /**
     * Get custom parameters for users table
     *
     * @param Request $request
     * @param object $login
     *
     * @return array
     */
    protected function getCustomParams(Request $request, $login): array
    {
        return [
            'status' => $request->get('status')->number(),
            'department' => $request->get('department')->topic()
        ];
    }

    /**
     * Query data to send to DataTable
     *
     * @param array $params
     * @param object $login
     *
     * @return \Kotchasan\QueryBuilder\QueryBuilderInterface
     */
    protected function toDataTable($params, $login = null)
    {
        return \Index\Users\Model::toDataTable($params);
    }

    /**
     * Format user list with additional display fields
     *
     * @param array $datas
     * @param object $login
     *
     * @return array
     */
    protected function formatDatas(array $datas, $login = null): array
    {
        $data = [];
        foreach ($datas as $row) {
            $row->status_text = self::$cfg->member_status[$row->status] ?? $row->status;
            $row->initial_name = self::getInitialName($row->name);

            if (file_exists(ROOT_PATH.DATA_FOLDER.'avatar/'.$row->id.self::$cfg->stored_img_type)) {
                $row->avatar = WEB_URL.DATA_FOLDER.'avatar/'.$row->id.self::$cfg->stored_img_type;
            } else {
                $row->avatar = null;
            }

            $data[] = $row;
        }
        return $data;
    }

    /**
     * Get filters for table response
     *
     * @param array $params
     * @param object $login
     *
     * @return array
     */
    protected function getFilters($params, $login = null)
    {
        return [
            'status' => \Gcms\Controller::getUserStatusOptions(),
            'department' => \Gcms\Category::init()->toOptions('department')
        ];
    }

    /**
     * Handle delete action
     *
     * @param Request $request
     * @param object $login
     *
     * @return array
     */
    protected function handleDeleteAction(Request $request, $login)
    {
        if (!ApiController::isSuperAdmin($login)) {
            return $this->errorResponse('Failed to process request', 403);
        }

        $ids = $request->request('ids', [])->toInt();
        if (empty($ids)) {
            return $this->errorResponse('No items selected', 400);
        }
        $removeCount = \Index\Users\Model::remove($ids);

        if (empty($removeCount)) {
            return $this->errorResponse('Delete action failed', 400);
        }

        \Index\Log\Model::add(0, 'index', 'Delete', 'Delete User ID(s) : '.implode(', ', $ids), $login->id);

        return $this->redirectResponse('reload', 'Deleted '.$removeCount.' user(s) successfully', 200, 0, 'table');
    }

    /**
     * Handle edit action
     *
     * @param Request $request
     * @param object $login
     *
     * @return array
     */
    protected function handleEditAction(Request $request, $login)
    {
        $id = $request->post('id')->toInt();

        if (!ApiController::isSuperAdmin($login) && $id !== $login->id) {
            return $this->errorResponse('Failed to process request', 403);
        }

        return $this->redirectResponse('/profile?id='.$id);
    }

    /**
     * Handle send password reset link action (sendpassword)
     *
     * @param Request $request
     * @param object $login
     *
     * @return Response
     */
    protected function handleSendpasswordAction(Request $request, $login)
    {
        if (!ApiController::isAdmin($login)) {
            return $this->errorResponse('Failed to process request', 403);
        }

        $ids = $request->post('ids', [])->toInt();

        if (empty($ids)) {
            return $this->errorResponse('No users selected', 400);
        }

        $sentCount = 0;
        $errors = [];

        // Get base URL from referer
        $referrer = $request->server('HTTP_REFERER');
        $baseUrl = !empty($referrer) ? substr($referrer, 0, strrpos($referrer, '/') + 1) : WEB_URL;

        foreach ($ids as $id) {
            if ($id == 1) {
                continue;
            }

            $user = \Index\Profile\Model::get($id);
            if ($user && !empty($user->username)) {
                $result = \Index\Forgot\Model::execute($user->id, $user->username, $baseUrl);
                if (empty($result)) {
                    $sentCount++;
                } else {
                    $errors[] = $user->username.': '.$result;
                }
            }
        }

        if ($sentCount > 0) {
            $message = 'Sent password reset link to '.$sentCount.' user(s)';
            if (!empty($errors)) {
                $message .= '. Errors: '.implode(', ', $errors);
            }
            return $this->redirectResponse('reload', $message, 200, 0, 'table');
        }

        return $this->errorResponse('Failed to send password reset links: '.implode(', ', $errors), 400);
    }

    /**
     * Handle inactive action - Unable to log in
     *
     * @param Request $request
     * @param object $login
     *
     * @return Response
     */
    protected function handleSendactivationAction(Request $request, $login)
    {
        if (!ApiController::isAdmin($login)) {
            return $this->errorResponse('Failed to process request', 403);
        }

        // Get selected user IDs
        $ids = $request->post('ids', [])->toInt();

        if (empty($ids)) {
            return $this->errorResponse('No users selected', 400);
        }

        $users = \Kotchasan\DB::create()->select('user', [
            ['id', $ids],
            ['id', '!=', 1]
        ], [], ['id', 'username', 'name']);

        if (empty($users)) {
            return $this->errorResponse('No users selected', 400);
        }

        // Get base URL from referer
        $referrer = $request->server('HTTP_REFERER');
        $baseUrl = !empty($referrer) ? substr($referrer, 0, strrpos($referrer, '/') + 1) : WEB_URL;

        $editCount = 0;
        foreach ($users as $user) {
            $activatecode = md5($user->username.uniqid().time());
            \Kotchasan\DB::create()->update('user', [['id', $user->id]], ['activatecode' => $activatecode]);
            $url = $baseUrl.'activate?id='.$activatecode;
            \Index\Email\Model::sendActivation($user->username, $url, $user->name);
            $editCount++;
        }

        // Log the action
        \Index\Log\Model::add(0, 'index', 'Auth', 'Send activation link to: '.implode(',', $ids), $login->id);

        // Redirect to the same page with a success message
        return $this->redirectResponse('reload', 'Send activation link to '.$editCount.' user(s)', 200, 0, 'table');
    }

    /**
     * Handle activate action - Accept member verification request
     *
     * @param Request $request
     * @param object $login
     *
     * @return Response
     */
    protected function handleActivateAction(Request $request, $login)
    {
        if (!ApiController::isAdmin($login)) {
            return $this->errorResponse('Failed to process request', 403);
        }

        // Get selected user IDs
        $ids = $request->post('ids', [])->toInt();

        if (empty($ids)) {
            return $this->errorResponse('No users selected', 400);
        }

        // Update users
        $editCount = \Kotchasan\DB::create()
            ->update('user', [
                ['id', $ids],
                ['id', '!=', 1]
            ], ['active' => 1, 'activatecode' => '']);

        // Log the action
        \Index\Log\Model::add(0, 'index', 'Auth', 'Accept verification: '.implode(',', $ids), $login->id);

        // Redirect to the same page with a success message
        return $this->redirectResponse('reload', 'Accept verification '.$editCount.' user(s)', 200, 0, 'table');
    }

    /**
     * Handle inactive action - send login approval notification
     *
     * @param Request $request
     * @param object $login
     *
     * @return Response
     */
    protected function handleApprovalAction(Request $request, $login)
    {
        if (!ApiController::isAdmin($login)) {
            return $this->errorResponse('Failed to process request', 403);
        }

        // Get selected user IDs
        $ids = $request->post('ids', [])->toInt();

        if (empty($ids)) {
            return $this->errorResponse('No users selected', 400);
        }

        // Get base URL from referer
        $referrer = $request->server('HTTP_REFERER');
        $baseUrl = !empty($referrer) ? substr($referrer, 0, strrpos($referrer, '/') + 1) : WEB_URL;

        // Send activation email to selected users
        $editCount = \Index\Email\Model::sendActive($ids, $baseUrl);

        // Log the action
        \Index\Log\Model::add(0, 'index', 'Auth', 'Activate user: '.implode(',', $ids), $login->id);

        // Redirect to the same page with a success message
        return $this->redirectResponse('reload', 'Approved and notified '.$editCount.' user(s)', 200, 0, 'table');
    }

    /**
     * Handle inactive action - no send member confirmation message
     *
     * @param Request $request
     * @param object $login
     *
     * @return Response
     */
    protected function handleActiveAction(Request $request, $login)
    {
        if (!ApiController::isAdmin($login)) {
            return $this->errorResponse('Failed to process request', 403);
        }

        $db = \Kotchasan\DB::create();

        // Get selected user IDs
        $id = $request->post('id')->toInt();
        $user = $db->first('user', ['id', $id]);
        if (!$user) {
            return $this->errorResponse('User not found', 404);
        }

        $active = $user->active == 1 ? 0 : 1;
        $db->update('user', ['id', $id], ['active' => $active]);

        // Log the action
        $msg = $active ? 'Activated user: '.$user->name : 'Deactivated user: '.$user->name;
        \Index\Log\Model::add($login->id, 'index', 'Auth', $msg, $login->id);

        // Redirect to the same page with a success message
        return $this->redirectResponse('reload', $msg, 200, 0, 'table');
    }

    /**
     * Handle inactivate action - Send member confirmation message (resend activation email)
     *
     * @param Request $request
     * @param object $login
     *
     * @return Response
     */
    protected function handleDeactivatedAction(Request $request, $login)
    {
        if (!ApiController::isAdmin($login)) {
            return $this->errorResponse('Failed to process request', 403);
        }

        // Get selected user IDs
        $ids = $request->post('ids', [])->toInt();

        if (empty($ids)) {
            return $this->errorResponse('No users selected', 400);
        }

        // Update users
        $editCount = \Kotchasan\DB::create()->update('user', [
            ['id', $ids],
            ['id', '!=', 1],
            ['active', 1]
        ], ['active' => 0]);

        // Log the action
        \Index\Log\Model::add(0, 'index', 'Auth', 'Deactivate user: '.implode(',', $ids), $login->id);

        // Redirect to the same page with a success message
        return $this->redirectResponse('reload', 'Deactivated '.$editCount.' user(s)', 200, 0, 'table');
    }

    /**
     * Handle impersonate action - Login as user (SuperAdmin only)
     *
     * @param Request $request
     * @param object $login
     *
     * @return Response
     */
    protected function handleImpersonateAction(Request $request, $login)
    {
        // Only SuperAdmin can impersonate
        if (!ApiController::isSuperAdmin($login)) {
            return $this->errorResponse('Only SuperAdmin can impersonate users', 403);
        }

        // Get target user ID
        $id = $request->post('id')->toInt();

        if (empty($id)) {
            return $this->errorResponse('User ID required', 400);
        }

        // Cannot impersonate yourself
        if ($id == $login->id) {
            return $this->errorResponse('Cannot impersonate yourself', 400);
        }

        // Get client IP
        $clientIp = $request->getClientIp();

        // Call impersonate function
        $result = \Index\Auth\Model::impersonateUser($login->id, $id, $clientIp);

        if (!$result['success']) {
            return $this->errorResponse($result['message'], 400);
        }

        // Set new token cookie (same pattern as auth controller login)
        $this->setAuthCookie('auth_token', $result['token'], \Index\Auth\Model::TOKEN_EXPIRY);

        // Return success with user data and redirect
        return $this->successResponse([
            'user' => $result['user'],
            'impersonating' => true,
            'actions' => [
                ['type' => 'notification', 'message' => 'Login as '.$result['user']['name'], 'variant' => 'info'],
                ['type' => 'redirect', 'url' => '/']
            ]
        ], 'Impersonation started');
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
        $options = [
            'expires' => time() + $expiry,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax'
        ];

        // Enable secure cookie if HTTPS
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            $options['secure'] = true;
            $options['samesite'] = 'Strict';
        }

        setcookie($name, $value, $options);
    }

    /**
     * Get initial name from full name
     *
     * @param string $name
     *
     * @return string
     */
    protected static function getInitialName($name)
    {
        if (preg_match_all('/([a-zA-Zก-ฮ]{1}).*?\s.*?([a-zA-Zก-ฮ]{1})/u', trim($name), $matches)) {
            return $matches[1][0].$matches[2][0];
        }
        return mb_substr($name, 0, 2, 'utf-8');
    }
}
