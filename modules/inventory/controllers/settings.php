<?php
/**
 * @filesource modules/inventory/controllers/settings.php
 */

namespace Inventory\Settings;

use Gcms\Api as ApiController;
use Gcms\Config;
use Kotchasan\Http\Request;

class Controller extends ApiController
{
    /**
     * Get module settings.
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function get(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'GET');

            $login = $this->authenticateRequest($request);
            if (!$login) {
                return $this->errorResponse('Unauthorized', 401);
            }
            if (!ApiController::hasPermission($login, ['can_manage_inventory', 'can_config'])) {
                return $this->errorResponse('Forbidden', 403);
            }

            return $this->successResponse([
                'data' => (object) [
                    'inventory_sku_no' => (string) (self::$cfg->inventory_sku_no ?? ''),
                    'customer_format_code' => (string) (self::$cfg->customer_format_code ?? ''),
                    'supplier_format_code' => (string) (self::$cfg->supplier_format_code ?? ''),
                    'inventory_w' => (int) (self::$cfg->inventory_w ?? self::$cfg->stored_img_size ?? 800)
                ]
            ], 'Settings retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }

    /**
     * Save module settings.
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
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
            if (!ApiController::canModify($login, 'can_config')) {
                return $this->errorResponse('Permission required', 403);
            }

            $skuNo = $request->post('inventory_sku_no')->topic();
            $customerFormatCode = $request->post('customer_format_code')->topic();
            $supplierFormatCode = $request->post('supplier_format_code')->topic();

            $config = Config::load(ROOT_PATH.'settings/config.php');

            $config->inventory_sku_no = $skuNo === '' ? 'SKU%04d' : $skuNo;
            $config->customer_format_code = $customerFormatCode === '' ? 'CUS%04d' : $customerFormatCode;
            $config->supplier_format_code = $supplierFormatCode === '' ? 'SUP%04d' : $supplierFormatCode;
            $config->inventory_w = max(200, $request->post('inventory_w')->toInt());

            if (Config::save($config, ROOT_PATH.'settings/config.php')) {
                \Index\Log\Model::add(0, 'inventory', 'Save', 'Saved inventory settings', $login->id);

                return $this->redirectResponse('reload', 'Saved successfully', 200, 1000);
            }

            return $this->errorResponse('Failed to save settings', 500);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }

    /**
     * @param string $value
     *
     * @return string
     */
    private static function sanitizePrefix(string $value): string
    {
        $value = strtoupper(trim($value));
        $value = preg_replace('/[^A-Z0-9%\/_\-]/', '', $value);

        return substr((string) $value, 0, 50);
    }

    /**
     * @param string $value
     * @param string $fallback
     *
     * @return string
     */
    private static function sanitizeRunningFormat(string $value, string $fallback): string
    {
        $value = trim($value);
        $value = preg_replace('/[^A-Za-z0-9%\/_\-]/', '', $value);

        if ($value === '') {
            return $fallback;
        }

        return substr((string) $value, 0, 50);
    }
}
