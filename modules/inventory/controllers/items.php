<?php
/**
 * @filesource modules/inventory/controllers/items.php
 */

namespace Inventory\Items;

use Gcms\Api as ApiController;
use Kotchasan\Http\Request;

class Controller extends ApiController
{
    /**
     * Handle per-row item actions.
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function action(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'POST');
            $this->validateCsrfToken($request);

            $login = $this->authenticateRequest($request);
            if (!$login) {
                return $this->errorResponse('Unauthorized', 401);
            }

            if (!ApiController::hasPermission($login, ['can_manage_inventory', 'can_config'])) {
                return $this->errorResponse('Permission required', 403);
            }

            $action = $request->post('action')->filter('a-z_0-9');

            if (!in_array($action, ['delete', 'stock_movements', 'cost_layers'])) {
                return $this->successResponse(null, '');
            }

            $row = json_decode($request->post('row')->toJson());
            $inventoryItemId = $request->post('inventory_item_id')->toInt();
            if ($inventoryItemId < 1 && is_object($row)) {
                $inventoryItemId = (int) ($row->inventory_item_id ?? 0);
            }
            $sku = $request->post('sku')->topic();
            if ($sku === '' && is_object($row)) {
                $sku = trim((string) ($row->sku ?? ''));
            }

            if ($action === 'delete') {
                if ($inventoryItemId < 1) {
                    return $this->successResponse(null, '');
                }

                $item = \Inventory\Item\Model::resolve($inventoryItemId);
                if ($item === null) {
                    return $this->errorResponse('No data available', 404);
                }

                $policies = Model::getRowPolicies((int) $item->inventory_id);
                $policy = $policies[$inventoryItemId] ?? null;
                if ($policy === null || empty($policy['can_delete'])) {
                    return $this->errorResponse((string) ($policy['delete_reason'] ?? 'This item row cannot be removed'), 400);
                }

                return $this->successResponse(null, '');
            }

            if ($inventoryItemId < 1 && $sku === '') {
                return $this->errorResponse('No data available', 404);
            }

            if ($action === 'stock_movements') {
                if ($inventoryItemId > 0) {
                    return $this->redirectResponse('/inventory-stock-movements?inventory_item_id='.$inventoryItemId);
                }

                return $this->redirectResponse('/inventory-stock-movements?sku='.rawurlencode($sku));
            }
            if ($action === 'cost_layers') {
                if ($inventoryItemId > 0) {
                    return $this->redirectResponse('/inventory-cost-layers?inventory_item_id='.$inventoryItemId);
                }

                return $this->redirectResponse('/inventory-cost-layers?sku='.rawurlencode($sku));
            }

            return $this->errorResponse('Invalid action: '.$action, 400);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }

    /**
     * Load item-row editor data.
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
                return $this->errorResponse('Permission required', 403);
            }

            $data = Model::get($request->get('id')->toInt());
            if ($data === null) {
                return $this->redirectResponse('/404', 'No data available', 404);
            }

            return $this->successResponse($data, 'Inventory item rows retrieved');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }

    /**
     * Save item rows for an product.
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
            if (!ApiController::canModify($login, ['can_manage_inventory', 'can_config'])) {
                return $this->errorResponse('Permission required', 403);
            }

            $id = $request->post('id')->toInt();
            $product = \Inventory\Product\Model::getRecord($id);
            if ($product === null) {
                return $this->errorResponse('No data available', 404);
            }

            $currentRowsById = Model::getCurrentRowsById($id);
            $rowPolicies = Model::getRowPolicies($id, array_values($currentRowsById));

            $inventoryItemIds = $request->post('inventory_item_id', [])->toInt();
            $skus = $request->post('sku', [])->topic();
            $barcodes = $request->post('barcode', [])->topic();
            $units = $request->post('unit', [])->topic();
            $prices = $request->post('price', [])->toDouble();

            $errors = [];
            $rows = [];
            $seen = [];

            foreach ($skus as $rowKey => $sku) {
                $inventoryItemId = (int) ($inventoryItemIds[$rowKey] ?? 0);
                $currentRow = $inventoryItemId > 0 ? ($currentRowsById[$inventoryItemId] ?? null) : null;
                $policy = $inventoryItemId > 0 ? ($rowPolicies[$inventoryItemId] ?? null) : null;
                $sku = trim((string) $sku);
                $barcode = trim((string) ($barcodes[$rowKey] ?? ''));
                $postedUnit = trim((string) ($units[$rowKey] ?? ''));
                $unit = !empty($policy['unit_locked']) && $currentRow !== null ? (string) ($currentRow->unit ?? '') : $postedUnit;
                $stock = $currentRow !== null ? max(0, (float) ($currentRow->stock ?? 0)) : 0.0;
                $price = max(0, (float) ($prices[$rowKey] ?? 0));

                if ($inventoryItemId < 1 && $sku === '' && $barcode === '' && $postedUnit === '' && $price <= 0) {
                    continue;
                }

                if ($sku === '') {
                    $errors['inventoryItems_sku_'.$rowKey] = 'Please fill in';
                } elseif (isset($seen[$sku])) {
                    $errors['inventoryItems_sku_'.$rowKey] = 'This SKU already exists';
                } elseif (\Inventory\Product\Model::findDuplicateSKU($sku, $id, $inventoryItemId) !== null) {
                    $errors['inventoryItems_sku_'.$rowKey] = 'This SKU already exists';
                } else {
                    $seen[$sku] = true;
                }

                if ($barcode !== '' && \Inventory\Product\Model::findDuplicateBarcode($barcode, $inventoryItemId) !== null) {
                    $errors['inventoryItems_barcode_'.$rowKey] = 'This barcode already exists';
                }

                if (!empty($policy['unit_locked']) && $currentRow !== null && $postedUnit !== '' && $postedUnit !== (string) ($currentRow->unit ?? '')) {
                    $errors['inventoryItems_unit_'.$rowKey] = 'Unit cannot be changed after inventory history exists';
                }

                if ($unit === '') {
                    $errors['inventoryItems_unit_'.$rowKey] = 'Please select';
                }

                $rows[] = [
                    'inventory_item_id' => $inventoryItemId,
                    'row_key' => $rowKey,
                    'sku' => $sku,
                    'barcode' => $barcode,
                    'unit' => $unit,
                    'stock' => $stock,
                    'price' => $price
                ];
            }

            if (empty($rows)) {
                return $this->errorResponse('Please add at least one item row', 400);
            }

            $submittedInventoryItemIds = array_values(array_filter(array_map(static function ($row) {
                return (int) ($row['inventory_item_id'] ?? 0);
            }, $rows)));

            $blockedRemovalIds = [];
            foreach ($rowPolicies as $inventoryItemId => $policy) {
                if (empty($policy['can_delete'])) {
                    $blockedRemovalIds[] = (int) $inventoryItemId;
                }
            }
            $protectedRemovals = array_diff($blockedRemovalIds, $submittedInventoryItemIds);
            if (!empty($protectedRemovals)) {
                return $this->errorResponse('Some item rows cannot be removed because stock, history, or active assignments still exist', 400);
            }

            if (!empty($errors)) {
                return $this->formErrorResponse($errors, 400);
            }

            $auditChanges = [];
            foreach ($rows as $row) {
                $inventoryItemId = (int) ($row['inventory_item_id'] ?? 0);
                if ($inventoryItemId < 1 || !isset($currentRowsById[$inventoryItemId])) {
                    continue;
                }
                $currentRow = $currentRowsById[$inventoryItemId];
                $fieldChanges = [];
                if ((string) ($currentRow->sku ?? '') !== (string) ($row['sku'] ?? '')) {
                    $fieldChanges['sku'] = [
                        'from' => (string) ($currentRow->sku ?? ''),
                        'to' => (string) ($row['sku'] ?? '')
                    ];
                }
                if ((string) ($currentRow->barcode ?? '') !== (string) ($row['barcode'] ?? '')) {
                    $fieldChanges['barcode'] = [
                        'from' => (string) ($currentRow->barcode ?? ''),
                        'to' => (string) ($row['barcode'] ?? '')
                    ];
                }
                if (!empty($fieldChanges)) {
                    $auditChanges[] = [
                        'inventory_item_id' => $inventoryItemId,
                        'changes' => $fieldChanges
                    ];
                }
            }

            $rows = array_map(static function ($row) {
                unset($row['row_key']);

                return $row;
            }, $rows);

            Model::replace($id, $rows, (int) $login->id);

            \Index\Log\Model::add($id, 'inventory', 'Save', 'Saved inventory item rows: '.$product->topic, $login->id);
            if (!empty($auditChanges)) {
                \Index\Log\Model::add($id, 'inventory', 'Update', 'Updated inventory item identifiers: '.$product->topic, $login->id, null, $auditChanges);
            }

            return $this->redirectResponse('reload', 'Saved successfully', 200, 800);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }
}
