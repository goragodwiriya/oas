<?php
/**
 * @filesource modules/order/controllers/settings.php
 */

namespace Order\Settings;

use Gcms\Api as ApiController;
use Gcms\Config;
use Kotchasan\Http\Request;

class Controller extends ApiController
{
    /**
     * Document type -> config key mapping.
     *
     * @var array<string,array{prefix:string,number:string}>
     */
    private const DOCUMENT_SETTING_MAP = [
        'QT' => ['prefix' => 'inventory_qt_prefix', 'number' => 'inventory_qt_no'],
        'SO' => ['prefix' => 'inventory_so_prefix', 'number' => 'inventory_so_no'],
        'DN' => ['prefix' => 'inventory_dn_prefix', 'number' => 'inventory_dn_no'],
        'INV' => ['prefix' => 'inventory_inv_prefix', 'number' => 'inventory_inv_no'],
        'RCP' => ['prefix' => 'inventory_rcp_prefix', 'number' => 'inventory_rcp_no']
    ];

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

            $data = [
                'company_authorized' => (string) (self::$cfg->company_authorized ?? ''),
                'inventory_bank_name' => (string) (self::$cfg->inventory_bank_name ?? ''),
                'inventory_bank_account_name' => (string) (self::$cfg->inventory_bank_account_name ?? ''),
                'inventory_bank_account_no' => (string) (self::$cfg->inventory_bank_account_no ?? '')
            ];
            foreach (\Order\Helper\Model::$salesDocumentTypes as $type => $label) {
                $order_no = self::$cfg->order_no[$type] ?? [];
                $data['numbers'][] = [
                    'type' => $type,
                    'label' => $label,
                    'prefix' => (string) ($order_no['prefix'] ?? $type.'%Y%M-'),
                    'no' => (string) ($order_no['no'] ?? '%04d')
                ];
            }
            foreach (\Order\Helper\Model::$purchaseDocumentTypes as $type => $label) {
                $order_no = self::$cfg->order_no[$type] ?? [];
                $data['numbers'][] = [
                    'type' => $type,
                    'label' => $label,
                    'prefix' => (string) ($order_no['prefix'] ?? $type.'%Y%M-'),
                    'no' => (string) ($order_no['no'] ?? '%04d')
                ];
            }

            return $this->successResponse([
                'data' => (object) $data,
                'options' => [
                    'users' => \Index\Users\Model::toOptions()
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

            $config = Config::load(ROOT_PATH.'settings/config.php');

            $config->company_authorized = $request->post('company_authorized')->toInt();
            $config->inventory_bank_name = $request->post('inventory_bank_name')->topic();
            $config->inventory_bank_account_name = $request->post('inventory_bank_account_name')->topic();
            $config->inventory_bank_account_no = $request->post('inventory_bank_account_no')->topic();

            $inventoryPrefix = $request->post('inventory_prefix', [])->topic();
            $inventoryNo = $request->post('inventory_no', [])->topic();

            foreach (\Order\Helper\Model::$salesDocumentTypes as $type => $label) {
                $config->order_no[$type]['prefix'] = $inventoryPrefix[$type] ?? $type.'%Y%M-';
                $config->order_no[$type]['no'] = $inventoryNo[$type] ?? '%04d';
            }
            foreach (\Order\Helper\Model::$purchaseDocumentTypes as $type => $label) {
                $config->order_no[$type]['prefix'] = $inventoryPrefix[$type] ?? $type.'%Y%M-';
                $config->order_no[$type]['no'] = $inventoryNo[$type] ?? '%04d';
            }

            if (Config::save($config, ROOT_PATH.'settings/config.php')) {
                \Index\Log\Model::add(0, 'inventory', 'Save', 'Saved inventory settings', $login->id);

                return $this->redirectResponse('reload', 'Saved successfully', 200, 1000);
            }

            return $this->errorResponse('Failed to save settings', 500);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }
}
