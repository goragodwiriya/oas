<?php
/**
 * @filesource modules/index/controllers/cache.php
 *
 * Website Settings Controller
 * Endpoint for settings.html admin form
 * Only Super Admin (status = 1) can access
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Index\Cache;

use Gcms\Api as ApiController;
use Kotchasan\Http\Request;

class Controller extends ApiController
{
    /**
     * Clear all cache (static method - can be called from anywhere)
     *
     * เมื่ออัปเดทสินค้า
     * \Index\Cache\Controller::clearCache(['query']);
     *
     * เมื่อเปลี่ยน template
     * \Index\Cache\Controller::clearCache(['template']);
     *
     * เมื่ออัปเดทโค้ด PHP
     * \Index\Cache\Controller::clearCache(['opcache']);
     *
     * หลังจาก deploy ใหม่
     * \Index\Cache\Controller::clearCache(); // ล้างทั้งหมด
     *
     * @param array $types Optional array of cache types to clear: ['query', 'template', 'opcache', 'all']
     * @return array Cleared cache information
     */
    public static function clearCache(array $types = ['all']): array
    {
        $cleared = [];

        // If 'all' is specified or types is empty, clear everything
        $clearAll = empty($types) || in_array('all', $types);

        // 1. Clear Query Cache (FileCache)
        if ($clearAll || in_array('query', $types)) {
            try {
                $fileCache = new \Kotchasan\Cache\FileCache();
                $statsBefore = $fileCache->getStats();
                $fileCache->clear();
                $cleared['query_cache'] = $statsBefore['count'].' files';

                // Run Garbage Collection
                $fileCache = new \Kotchasan\Cache\FileCache(null, 3600, false);
                $removed = $fileCache->forceGc();
                $cleared['gc_removed'] = $removed.' expired files';
            } catch (\Exception $e) {
                $cleared['query_cache_error'] = $e->getMessage();
            }
        }

        // 2. Clear Template Cache
        if ($clearAll || in_array('template', $types)) {
            $templateCachePath = ROOT_PATH.DATA_FOLDER.'cache/templates/';
            if (is_dir($templateCachePath)) {
                $count = 0;
                $files = glob($templateCachePath.'*');
                if ($files) {
                    foreach ($files as $file) {
                        if (is_file($file) && @unlink($file)) {
                            $count++;
                        }
                    }
                }
                $cleared['template_cache'] = $count.' files';
            }

            // Clear other cache subdirectories (views, config)
            $otherCacheDirs = [
                ROOT_PATH.DATA_FOLDER.'cache/views/',
                ROOT_PATH.DATA_FOLDER.'cache/config/'
            ];

            $otherCount = 0;
            foreach ($otherCacheDirs as $dir) {
                if (is_dir($dir)) {
                    try {
                        $iterator = new \RecursiveIteratorIterator(
                            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                            \RecursiveIteratorIterator::CHILD_FIRST
                        );
                        foreach ($iterator as $file) {
                            if ($file->isFile() && @unlink($file->getPathname())) {
                                $otherCount++;
                            }
                        }
                    } catch (\Exception $e) {
                        // Continue even if one directory fails
                    }
                }
            }
            if ($otherCount > 0) {
                $cleared['other_cache'] = $otherCount.' files';
            }
        }

        // 3. Clear main cache directory (remaining files)
        if ($clearAll || in_array('file', $types)) {
            $cache_dir = ROOT_PATH.DATA_FOLDER.'cache/';
            if (is_dir($cache_dir)) {
                $files = glob($cache_dir.'*');
                $count = 0;
                foreach ($files as $file) {
                    if (is_file($file) && @unlink($file)) {
                        $count++;
                    }
                }
                if ($count > 0) {
                    $cleared['file_cache'] = $count.' files';
                }
            }
        }

        // 4. Clear PHP OPcache if available
        if (($clearAll || in_array('opcache', $types)) && function_exists('opcache_reset')) {
            opcache_reset();
            $cleared['opcache'] = 'reset';
        }

        return $cleared;
    }

    /**
     * Clear cache (API endpoint)
     *
     * @param Request $request
     * @return mixed
     */
    public function clear(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'POST');
            $this->validateCsrfToken($request);

            // Authentication check (required)
            $login = $this->authenticateRequest($request);
            if (!$login) {
                return $this->errorResponse('Unauthorized', 401);
            }

            // Authorization check - only admin can clear cache
            if (!ApiController::hasPermission($login, ['can_config'])) {
                return $this->errorResponse('Permission denied', 403);
            }

            // Use static method to clear cache
            $cleared = self::clearCache();

            // Log the action
            \Index\Log\Model::add(0, 'index', 'Other', 'Cache cleared: '.json_encode($cleared), $login->id);

            return $this->successResponse([
                'cleared' => $cleared,
                'timestamp' => date('Y-m-d H:i:s')
            ], 'Cache cleared successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }
}
