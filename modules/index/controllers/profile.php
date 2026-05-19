<?php
/**
 * @filesource modules/index/controllers/profile.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Profile;

use Gcms\Api as ApiController;
use Index\UserRepository\Model as UserRepository;
use Kotchasan\File;
use Kotchasan\Http\Request;
use Kotchasan\Http\Response;
use Kotchasan\Language;
use Kotchasan\Validator;

/**
 * API Profile Controller
 *
 * Handles user profile endpoints
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends ApiController
{
    /**
     * GET /api/index/profile/get
     * Get user details by ID
     *
     * @param Request $request
     *
     * @return Response
     */
    public function get(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'GET');

            // Authentication check (required)
            $login = $this->authenticateRequest($request);
            if (!$login) {
                return $this->errorResponse('Unauthorized', 401);
            }

            $isSuperAdmin = ApiController::isSuperAdmin($login);
            if (!$isSuperAdmin) {
                $id = $login->id;
            } else {
                $id = $request->get('id', $login->id)->toInt();
            }
            $user = \Index\Profile\Model::get($id);
            if (!$user) {
                return $this->redirectResponse('/404', 'No data available', 404);
            }

            foreach (self::$cfg->member_images as $key => $label) {
                // Avatar image
                $avatar = self::getAvatarUrl($user->id, $key);
                if ($avatar !== null) {
                    $user->$key = [
                        [
                            'url' => $avatar,
                            'name' => $user->id.self::$cfg->stored_img_type
                        ]
                    ];
                } else {
                    $user->$key = [
                        [
                            'url' => WEB_URL.'images/no-image.webp',
                            'name' => 'Choose file'
                        ]
                    ];
                }
            }

            $user->provinceID = empty($user->provinceID) ? null : $user->provinceID;
            $user->isSuperAdmin = $isSuperAdmin;

            // Return user details with options
            return $this->successResponse([
                'data' => self::sanitizeUserData($user),
                'options' => [
                    'sex' => \Gcms\Controller::getGenderOptions(),
                    'province' => \Gcms\Controller::getProvinceOptions(),
                    'status' => \Gcms\Controller::getUserStatusOptions(),
                    'active' => \Gcms\Controller::getUserActiveOptions(),
                    'permission' => \Gcms\Controller::getPermissionOptions(),
                    'department' => \Gcms\Category::init()->toOptions('department')
                ]
            ], 'User details retrieved');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }

    /**
     * POST /api/index/profile/save
     * Save user details (create or update)
     *
     * @param Request $request
     *
     * @return Response
     */
    public function save(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'POST');
            $this->validateCsrfToken($request);

            // Authentication check (required)
            $login = $this->authenticateRequest($request);
            if (!$login) {
                return $this->redirectResponse('/login', 'Unauthorized', 401);
            }

            // Super admin (Can edit everything)
            $isSuperAdmin = ApiController::isSuperAdmin($login);

            // Parse input data
            $save = $this->parseInput($request);

            // Check if user exists first
            $user = \Index\Profile\Model::get($request->post('id')->toInt());
            if (!$user) {
                return $this->errorResponse('No data available', 404);
            }

            if ($user->id === 0) {
                // New user
                $save['social'] = 'user';
                $save['created_at'] = date('Y-m-d H:i:s');
            }

            // Admin-only fields
            if ($isSuperAdmin) {
                $permission = $request->post('permission', [])->filter('a-z0-9_');
                $save['permission'] = empty($permission) ? '' : ','.implode(',', $permission).',';
            } elseif ($login->id != $user->id) {
                // Not an admin, can only edit myself.
                return $this->errorResponse('You can only edit your own profile', 403);
            } else {
                // Not an admin cannot update these fields
                $save['username'] = $user->username;
                $save['permission'] = $user->permission;
                $save['status'] = $user->status;
                $save['active'] = $user->active;
                $save['metas'] = $user->metas;
            }

            $db = \Kotchasan\DB::create();

            // Validate
            $errors = $this->validateFields($request, $save, $user, $db);

            if ($user->id == 0 && empty($save['password'])) {
                //Register must have a password.
                $errors['password'] = 'Password is required';
            }

            if (empty($errors)) {
                if ($user->id > 0) {
                    // Update
                    $save['id'] = $user->id;
                } else {
                    // New
                    $save['id'] = $db->nextId('user');
                }

                // File storage directory
                $dir = ROOT_PATH.DATA_FOLDER;
                // Upload file
                foreach ($request->getUploadedFiles() as $item => $file) {
                    // Name of file to upload
                    if (isset(self::$cfg->member_images[$item])) {
                        $image = $save['id'].self::$cfg->stored_img_type;
                        if (!File::makeDirectory($dir.$item.'/')) {
                            // The directory cannot be created.
                            $errors[$item] = Language::replace('Directory %s cannot be created or is read-only.', DATA_FOLDER.$item.'/');
                        } elseif ($file->hasUploadFile()) {
                            try {
                                if ($item === 'avatar') {
                                    $file->cropImage(self::$cfg->member_img_typies, $dir.$item.'/'.$image, self::$cfg->member_img_size, self::$cfg->member_img_size);
                                } else {
                                    $file->resizeImage(self::$cfg->img_typies, $dir.$item.'/', $image, self::$cfg->stored_img_size);
                                }
                            } catch (\Exception $exc) {
                                // Unable to upload
                                $errors[$item] = Language::get($exc->getMessage());
                            }
                        } elseif ($err = $file->getErrorMessage()) {
                            // Upload error
                            $errors[$item] = $err;
                        }
                    }
                }
            }

            if (empty($errors)) {
                // Save user
                \Index\Profile\Model::save($db, $user->id, $save);

                // Log
                \Index\Log\Model::add($save['id'], 'index', 'Save', 'Edit user: '.$save['id'], $login->id);

                // Redirect to previous page
                return $this->redirectResponse('reload', 'Saved successfully', 200, 1000);
            }

            // Error response
            return $this->formErrorResponse($errors, 400);
        } catch (\Exception $e) {
            // Error response
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }

    /**
     * Remove avatar
     *
     * Called from FileElementFactory with:
     * - action: 'delete'
     * - url: file URL (when data-file-reference="url")
     *
     * @param Request $request
     *
     * @return Response
     */
    public function removeAvatar(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'POST');
            $this->validateCsrfToken($request);

            // Authentication check (required)
            $login = $this->authenticateRequest($request);
            if (!$login) {
                return $this->redirectResponse('/login', 'Unauthorized', 401);
            }

            // Validate action from FileElementFactory
            $action = $request->post('action')->filter('a-z');
            if ($action !== 'delete') {
                return $this->errorResponse('Invalid action', 400);
            }

            // Extract user ID from URL (format: .../avatar/123.webp)
            $fileUrl = $request->post('url')->url();
            if (empty($fileUrl)) {
                return $this->errorResponse('File URL is required', 400);
            }

            // Parse user ID from filename
            if (preg_match('/avatar\/(\d+)'.preg_quote(self::$cfg->stored_img_type, '/').'$/', $fileUrl, $matches)) {
                $userId = (int) $matches[1];
            } else {
                return $this->errorResponse('Invalid file URL format', 400);
            }

            $user = \Index\Profile\Model::get($userId);

            if (!$user) {
                return $this->errorResponse('No data available', 404);
            }

            $isAdmin = \Kotchasan\Login::isAdmin($login);

            // Permission check: Admin can delete any, user can only delete own
            if (!$isAdmin && $login->id != $user->id) {
                return $this->errorResponse('You can only edit your own profile', 403);
            }

            // Remove avatar file
            $dir = ROOT_PATH.DATA_FOLDER;
            $image = $dir.'avatar/'.$user->id.self::$cfg->stored_img_type;
            if (file_exists($image)) {
                unlink($image);

                // Log
                \Index\Log\Model::add($user->id, 'index', 'Delete', 'Remove avatar: '.$user->id, $login->id);

                return $this->successResponse('Removed successfully');
            }

            return $this->errorResponse('File not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }

    /**
     * Parse user input from request
     *
     * @param Request $request
     * @return array
     */
    protected function parseInput(Request $request): array
    {
        $department = $request->post('department')->topic();

        $save = [
            'username' => $request->post('username')->username(),
            'status' => $request->post('status')->toInt(),
            'active' => $request->post('active')->toInt(),
            'name' => $request->post('name')->topic(),
            'sex' => $request->post('sex')->filter('fm'),
            'birthday' => $request->post('birthday')->date(),
            'id_card' => $request->post('id_card')->number(),
            'tax_id' => $request->post('tax_id')->number(),
            'phone' => $request->post('phone')->phone(),
            'phone1' => $request->post('phone1')->phone(),
            'website' => $request->post('website')->url(),
            'company' => $request->post('company')->topic(),
            'address' => $request->post('address')->textarea(),
            'address2' => $request->post('address2')->textarea(),
            'provinceID' => $request->post('provinceID')->toInt(),
            'zipcode' => $request->post('zipcode')->number(),
            'metas' => [
                'department' => $department === '' ? [] : [$department]
            ]
        ];

        // Optional fields
        if ($request->post('line_uid')->exists()) {
            $save['line_uid'] = $request->post('line_uid')->filter('Ua-z0-9');
        }
        if ($request->post('telegram_id')->exists()) {
            $save['telegram_id'] = $request->post('telegram_id')->number();
        }

        return $save;
    }

    /**
     * Validate user fields for duplicates and required fields
     *
     * @param array &$save Save data (modified by reference)
     * @param object $user Existing user
     * @param object $db Database connection
     */
    protected function validateFields($request, &$save, $user, $db)
    {
        $errors = [];
        // Check login information
        $checking = [];
        foreach (self::$cfg->login_fields as $field) {
            $k = $field == 'email' || $field === 'username' ? 'username' : $field;
            if (empty($save[$k])) {
                if (isset($user->{$k})) {
                    $save[$k] = $user->{$k};
                }
            } else {
                if ($field == 'email' && !in_array('username', self::$cfg->login_fields)) {
                    if (!Validator::email($save[$k])) {
                        $errors[$k] = 'Invalid email';
                    }
                }
                if (!isset($errors[$k])) {
                    $checking[$k] = $save[$k];
                    $search = $db->first('user', [[$k, $save[$k]]]);
                    if ($search && $search->id != $user->id) {
                        $errors[$k] = 'Already exist';
                    }
                }
            }
        }

        $save['id_card'] = $save['id_card'] === '' ? null : $save['id_card'];

        if (empty($checking) && $user->active === 1) {
            $k = reset(self::$cfg->login_fields);
            $errors[$k] = 'Please fill in';
        }

        // Validate password (if provided)
        if ($request->post('password')->exists()) {
            $password = $request->post('password')->password();
            $repassword = $request->post('repassword')->password();
            if ($password !== '' || $repassword !== '') {
                if (mb_strlen($password) < 8) {
                    $errors['password'] = 'Password must be at least 8 characters';
                } elseif ($password !== $repassword) {
                    $errors['repassword'] = 'Password does not match';
                } else {
                    // Hash password before saving (same method as auth.php)
                    $passwordData = \Index\Auth\Model::hashPassword($password);
                    $save['password'] = $passwordData['hash'];
                    $save['salt'] = $passwordData['salt'];
                }
            }
        }

        // Validate name (required)
        if (empty($save['name'])) {
            $errors['name'] = 'Please fill in';
        }

        // Validate phone uniqueness (outside login_fields)
        if (empty($save['phone'])) {
            $save['phone'] = null;
        } else {
            if (!UserRepository::isFieldUnique('phone', $save['phone'], $user->id)) {
                $errors['phone'] = 'Already exist';
            }
        }

        return $errors;
    }

    /**
     * Sanitize user data for response (remove sensitive fields)
     *
     * @param object $user
     *
     * @return array
     */
    public static function sanitizeUserData($user)
    {
        $data = (array) $user;

        // Remove sensitive fields
        unset(
            $data['password'],
            $data['salt'],
            $data['token'],
            $data['token_expires'],
            $data['activatecode']
        );

        return $data;
    }
}
