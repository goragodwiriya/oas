<?php
/**
 * FileBrowser API Controller
 * Secure API endpoints for file management
 *
 * @author Goragod Wiriya
 * @version 1.0
 */

namespace Modules\FileBrowser\Controllers;

use Kotchasan\ApiController;
use Kotchasan\Http\Request;
use Modules\FileBrowser\Models\Files;

class Filebrowser extends ApiController
{
    /**
     * Files model instance
     * @var Files
     */
    private $filesModel;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->filesModel = new Files();
    }

    /**
     * Get preset categories
     * GET /file-browser/get_preset_categories
     */
    public function get_preset_categories(Request $request)
    {
        try {
            // Authenticate
            $user = $this->authenticateRequest($request);
            if (!$user) {
                return $this->errorResponse('Unauthorized', 401);
            }

            $categories = $this->filesModel->getPresetCategories();

            return $this->successResponse([
                'categories' => $categories
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Get presets by category
     * GET /file-browser/get_presets
     */
    public function get_presets(Request $request)
    {
        try {
            // Authenticate
            $user = $this->authenticateRequest($request);
            if (!$user) {
                return $this->errorResponse('Unauthorized', 401);
            }

            // Validate input
            list($valid, $errors) = $this->validate($request, [
                'category' => 'required'
            ]);

            if (!$valid) {
                return $this->formErrorResponse($errors);
            }

            $category = $request->get('category')->filter('a-zA-Z0-9_-');
            $result = $this->filesModel->getPresets($category);

            return $this->successResponse($result);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Get files in a directory
     * GET /file-browser/get_files
     */
    public function get_files(Request $request)
    {
        try {
            // Authenticate
            $user = $this->authenticateRequest($request);
            if (!$user) {
                return $this->errorResponse('Unauthorized', 401);
            }

            $path = $request->get('path', '/')->toString();
            $sanitizedPath = $this->filesModel->sanitizePath($path);

            if ($sanitizedPath === false) {
                return $this->errorResponse('Invalid path', 400);
            }

            $result = $this->filesModel->getFiles($sanitizedPath);

            if (isset($result['error'])) {
                return $this->errorResponse($result['error'], 400);
            }

            return $this->successResponse($result);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Get folder tree structure
     * GET /file-browser/get_folder_tree
     */
    public function get_folder_tree(Request $request)
    {
        try {
            // Authenticate
            $user = $this->authenticateRequest($request);
            if (!$user) {
                return $this->errorResponse('Unauthorized', 401);
            }

            $path = $request->get('path', '/')->toString();
            $depth = $request->get('depth', 3)->toInt();

            $sanitizedPath = $this->filesModel->sanitizePath($path);
            if ($sanitizedPath === false) {
                return $this->errorResponse('Invalid path', 400);
            }

            $tree = $this->filesModel->getFolderTree($sanitizedPath, min($depth, 5));

            return $this->successResponse([
                'tree' => $tree
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Upload file
     * POST /file-browser/upload
     */
    public function upload(Request $request)
    {
        try {
            // Validate method
            $this->validateMethod($request, 'POST');

            // Validate CSRF
            $this->validateCsrfToken($request);

            // Authenticate
            $user = $this->authenticateRequest($request);
            if (!$user) {
                return $this->errorResponse('Unauthorized', 401);
            }

            // Check permission (optional - customize as needed)
            // if (!$this->hasPermission($user, 'file_upload')) {
            //     return $this->errorResponse('Permission denied', 403);
            // }

            // Get destination path
            $destPath = $request->post('path', '/')->toString();
            $sanitizedPath = $this->filesModel->sanitizePath($destPath);

            if ($sanitizedPath === false) {
                return $this->errorResponse('Invalid destination path', 400);
            }

            // Get uploaded file
            $file = $request->getUploadedFile('file');
            if (!$file) {
                return $this->errorResponse('No file uploaded', 400);
            }

            // Upload file
            $result = $this->filesModel->upload([
                'name' => $file->getClientFilename(),
                'type' => $file->getClientMediaType(),
                'tmp_name' => $file->getStream()->getMetadata('uri'),
                'error' => $file->getError(),
                'size' => $file->getSize()
            ], $sanitizedPath);

            if (isset($result['error'])) {
                return $this->errorResponse($result['error'], 400);
            }

            return $this->successResponse($result);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Create folder
     * POST /file-browser/create_folder
     */
    public function create_folder(Request $request)
    {
        try {
            // Validate method
            $this->validateMethod($request, 'POST');

            // Validate CSRF
            $this->validateCsrfToken($request);

            // Authenticate
            $user = $this->authenticateRequest($request);
            if (!$user) {
                return $this->errorResponse('Unauthorized', 401);
            }

            // Validate input
            list($valid, $errors) = $this->validate($request, [
                'path' => 'required',
                'name' => 'required'
            ]);

            if (!$valid) {
                return $this->formErrorResponse($errors);
            }

            $parentPath = $request->post('path')->toString();
            $name = $request->post('name')->toString();

            $sanitizedPath = $this->filesModel->sanitizePath($parentPath);
            if ($sanitizedPath === false) {
                return $this->errorResponse('Invalid path', 400);
            }

            $result = $this->filesModel->createFolder($sanitizedPath, $name);

            if (isset($result['error'])) {
                return $this->errorResponse($result['error'], 400);
            }

            return $this->successResponse($result);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Rename file or folder
     * POST /file-browser/rename
     */
    public function rename(Request $request)
    {
        try {
            // Validate method
            $this->validateMethod($request, 'POST');

            // Validate CSRF
            $this->validateCsrfToken($request);

            // Authenticate
            $user = $this->authenticateRequest($request);
            if (!$user) {
                return $this->errorResponse('Unauthorized', 401);
            }

            // Validate input
            list($valid, $errors) = $this->validate($request, [
                'path' => 'required',
                'name' => 'required'
            ]);

            if (!$valid) {
                return $this->formErrorResponse($errors);
            }

            $path = $request->post('path')->toString();
            $newName = $request->post('name')->toString();

            $sanitizedPath = $this->filesModel->sanitizePath($path);
            if ($sanitizedPath === false) {
                return $this->errorResponse('Invalid path', 400);
            }

            $result = $this->filesModel->rename($sanitizedPath, $newName);

            if (isset($result['error'])) {
                return $this->errorResponse($result['error'], 400);
            }

            return $this->successResponse($result);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Delete file or folder
     * POST /file-browser/delete
     */
    public function delete(Request $request)
    {
        try {
            // Validate method
            $this->validateMethod($request, 'POST');

            // Validate CSRF
            $this->validateCsrfToken($request);

            // Authenticate
            $user = $this->authenticateRequest($request);
            if (!$user) {
                return $this->errorResponse('Unauthorized', 401);
            }

            // Validate input
            list($valid, $errors) = $this->validate($request, [
                'path' => 'required'
            ]);

            if (!$valid) {
                return $this->formErrorResponse($errors);
            }

            $path = $request->post('path')->toString();

            $sanitizedPath = $this->filesModel->sanitizePath($path);
            if ($sanitizedPath === false) {
                return $this->errorResponse('Invalid path', 400);
            }

            $result = $this->filesModel->delete($sanitizedPath);

            if (isset($result['error'])) {
                return $this->errorResponse($result['error'], 400);
            }

            return $this->successResponse($result);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Copy file or folder
     * POST /file-browser/copy
     */
    public function copy(Request $request)
    {
        try {
            // Validate method
            $this->validateMethod($request, 'POST');

            // Validate CSRF
            $this->validateCsrfToken($request);

            // Authenticate
            $user = $this->authenticateRequest($request);
            if (!$user) {
                return $this->errorResponse('Unauthorized', 401);
            }

            // Validate input
            list($valid, $errors) = $this->validate($request, [
                'source' => 'required',
                'destination' => 'required'
            ]);

            if (!$valid) {
                return $this->formErrorResponse($errors);
            }

            $source = $request->post('source')->toString();
            $dest = $request->post('destination')->toString();

            $sanitizedSource = $this->filesModel->sanitizePath($source);
            $sanitizedDest = $this->filesModel->sanitizePath($dest);

            if ($sanitizedSource === false || $sanitizedDest === false) {
                return $this->errorResponse('Invalid path', 400);
            }

            $result = $this->filesModel->copy($sanitizedSource, $sanitizedDest);

            if (isset($result['error'])) {
                return $this->errorResponse($result['error'], 400);
            }

            return $this->successResponse($result);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Move file or folder
     * POST /file-browser/move
     */
    public function move(Request $request)
    {
        try {
            // Validate method
            $this->validateMethod($request, 'POST');

            // Validate CSRF
            $this->validateCsrfToken($request);

            // Authenticate
            $user = $this->authenticateRequest($request);
            if (!$user) {
                return $this->errorResponse('Unauthorized', 401);
            }

            // Validate input
            list($valid, $errors) = $this->validate($request, [
                'source' => 'required',
                'destination' => 'required'
            ]);

            if (!$valid) {
                return $this->formErrorResponse($errors);
            }

            $source = $request->post('source')->toString();
            $dest = $request->post('destination')->toString();

            $sanitizedSource = $this->filesModel->sanitizePath($source);
            $sanitizedDest = $this->filesModel->sanitizePath($dest);

            if ($sanitizedSource === false || $sanitizedDest === false) {
                return $this->errorResponse('Invalid path', 400);
            }

            $result = $this->filesModel->move($sanitizedSource, $sanitizedDest);

            if (isset($result['error'])) {
                return $this->errorResponse($result['error'], 400);
            }

            return $this->successResponse($result);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }
}
