<?php
/**
 * @filesource modules/inventory/controllers/detail.php
 *
 * api/inventory/detail/get|save|remove-image — รายละเอียดเพิ่มเติม + รูปสินค้า
 * ระบบเดิมคือ module=inventory-write&tab=detail
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Inventory\Detail;

use Gcms\Api as ApiController;
use Kotchasan\File;
use Kotchasan\Http\Request;
use Kotchasan\Http\Response;
use Kotchasan\Language;

/**
 * API รายละเอียดเพิ่มเติมของสินค้า
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends ApiController
{
    /**
     * GET api/inventory/detail/get?id=N
     *
     * @param Request $request
     *
     * @return Response
     */
    public function get(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'GET');

            $login = $this->authenticateRequest($request);
            if (!$login) {
                return $this->errorResponse('Unauthorized', 401);
            }
            if (!ApiController::hasPermission($login, 'can_manage_inventory')) {
                return $this->errorResponse('Permission required', 403);
            }

            $id = $request->get('id')->toInt();
            $product = \Inventory\Product\Model::get($id);
            if ($product === null || $id <= 0) {
                return $this->errorResponse('No data available', 404);
            }

            // ช่องอัปโหลดของแกนอ่านไฟล์เดิมจากอาเรย์ {url, name} (แบบเดียวกับหน้าโปรไฟล์)
            $url = Model::imageUrl($id);
            $product['image'] = [[
                'url' => $url === null ? WEB_URL.'images/no-image.webp' : $url,
                'name' => $url === null ? 'Choose file' : Model::imageName($id)
            ]];

            // ข้อความบอกชนิดไฟล์ ประกอบฝั่งเซิร์ฟเวอร์เพราะต้องแทนค่า :type
            $product['image_comment'] = Language::replace(
                'Browse image uploaded, type :type',
                [':type' => implode(', ', (array) self::$cfg->img_typies)]
            ).' ('.Language::get('resized automatically').')';

            return $this->successResponse(['data' => $product], 'OK');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    /**
     * POST api/inventory/detail/save
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

            $login = $this->authenticateRequest($request);
            if (!$login) {
                return $this->errorResponse('Unauthorized', 401);
            }
            if (!ApiController::canModify($login, 'can_manage_inventory')) {
                return $this->errorResponse('Permission required', 403);
            }

            $id = $request->post('id')->toInt();
            $product = \Inventory\Product\Model::get($id);
            if ($product === null || $id <= 0) {
                return $this->errorResponse('No data available', 404);
            }

            $errors = [];
            $dir = Model::imageDir();

            foreach ($request->getUploadedFiles() as $item => $file) {
                if ($item !== 'image' || !$file->hasUploadFile()) {
                    if ($item === 'image' && ($err = $file->getErrorMessage())) {
                        $errors['image'] = $err;
                    }
                    continue;
                }
                if (!File::makeDirectory($dir)) {
                    $errors['image'] = Language::replace(
                        'Directory %s cannot be created or is read-only.', DATA_FOLDER.'inventory/');
                    continue;
                }
                try {
                    $file->resizeImage(self::$cfg->img_typies, $dir,
                        Model::imageName($id), self::$cfg->stored_img_size);
                } catch (\Exception $exc) {
                    $errors['image'] = Language::get($exc->getMessage());
                }
            }

            if (!empty($errors)) {
                return $this->formErrorResponse($errors, 400);
            }

            Model::save($id, [
                'description' => $request->post('description')->topic(),
                'detail' => $request->post('detail')->textarea()
            ]);

            \Index\Log\Model::add($id, 'inventory', 'Save',
                '{LNG_Other details} ID : '.$id, $login->id);

            return $this->redirectResponse('reload',
                Language::get('Saved successfully'), 200, 1000);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    /**
     * POST api/inventory/detail/remove-image — ลบรูปสินค้า
     *
     * ช่องอัปโหลดของแกนยิงมาที่ data-action-url เมื่อผู้ใช้กดลบไฟล์เดิม
     *
     * @param Request $request
     *
     * @return Response
     */
    public function removeImage(Request $request)
    {
        try {
            $this->validateCsrfToken($request);

            $login = $this->authenticateRequest($request);
            if (!$login) {
                return $this->errorResponse('Unauthorized', 401);
            }
            if (!ApiController::canModify($login, 'can_manage_inventory')) {
                return $this->errorResponse('Permission required', 403);
            }

            $id = $request->request('id')->toInt();
            $product = \Inventory\Product\Model::get($id);
            if ($product === null || $id <= 0) {
                return $this->errorResponse('No data available', 404);
            }

            Model::removeImage($id);
            \Index\Log\Model::add($id, 'inventory', 'Delete',
                '{LNG_Image} ID : '.$id, $login->id);

            return $this->successResponse([], Language::get('Deleted successfully'));
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }
}
