<?php
/**
 * @filesource modules/order/controllers/dashboard.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Order\Dashboard;

use Gcms\Api as ApiController;
use Kotchasan\Currency;
use Kotchasan\Database\Sql;
use Kotchasan\Date;
use Kotchasan\Http\Request;
use Kotchasan\Http\Response;
use Order\Helper\Model as OrderHelper;

/**
 * API Order + Inventory Dashboard Controller
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends ApiController
{
    /**
     * GET /api/order/dashboard/get
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
                return $this->redirectResponse('/login', 'Unauthorized', 401);
            }

            if (!self::canAccessDashboard($login)) {
                return $this->errorResponse('Permission required', 403);
            }

            $todayStart = date('Y-m-d');
            $todayEnd = date('Y-m-d');
            $monthStart = date('Y-m-01');
            $monthEnd = date('Y-m-t');

            return $this->successResponse([
                'from' => $monthStart,
                'to' => $monthEnd,
                'period_label' => Date::format($monthStart, 'M Y'),
                'today_label' => Date::format($todayStart, 'd M Y'),
                'kpi_cards' => $this->getKpiCards($todayStart, $todayEnd, $monthStart, $monthEnd),
                'document_summary' => $this->getDocumentSummary($monthStart, $monthEnd),
                'inventory_summary' => $this->getInventorySummary($todayStart, $monthStart, $monthEnd),
                'recent_orders' => $this->getRecentOrders(),
                'low_stock_items' => $this->getLowStockItems(),
                'recent_movements' => $this->getRecentMovements(),
                'top_products' => $this->getTopProducts($monthStart, $monthEnd)
            ], 'Dashboard retrieved');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500);
        }
    }

    /**
     * @param object $login
     *
     * @return bool
     */
    private static function canAccessDashboard($login): bool
    {
        return ApiController::hasPermission($login, [
            'can_view_order',
            'can_edit_order',
            'can_manage_inventory',
            'can_config'
        ]);
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function getKpiCards(string $todayStart, string $todayEnd, string $monthStart, string $monthEnd): array
    {
        $salesTotal = $this->orderAggregate([
            ['document_type', 'RCP'],
            ['document_status', '!=', 'cancelled'],
            ['created_at', '>=', $monthStart.' 00:00:00'],
            ['created_at', '<=', $monthEnd.' 23:59:59']
        ]);
        $ordersToday = $this->orderAggregate([
            ['document_status', '!=', 'cancelled'],
            ['created_at', '>=', $todayStart.' 00:00:00'],
            ['created_at', '<=', $todayEnd.' 23:59:59']
        ]);
        $inventory = $this->getInventoryRawSummary($todayStart);

        return [
            [
                'label' => '{LNG_Sales this month}',
                'value' => Currency::format($salesTotal['total'], OrderHelper::getValueDecimals()),
                'hint' => '{LNG_Receipt} (RCP)',
                'icon' => 'icon-money',
                'url' => '/orders?document_type=RCP&from='.$monthStart.'&to='.$monthEnd
            ],
            [
                'label' => '{LNG_Documents today}',
                'value' => number_format($ordersToday['count']),
                'hint' => '{LNG_All issued documents}',
                'icon' => 'icon-documents',
                'url' => '/orders?from='.$todayStart.'&to='.$todayEnd
            ],
            [
                'label' => '{LNG_Low stock}',
                'value' => number_format($inventory['low_stock_count']),
                'hint' => '{LNG_Stockable active items}',
                'icon' => 'icon-warning',
                'url' => '/inventory-products'
            ],
            [
                'label' => '{LNG_Active products}',
                'value' => number_format($inventory['active_products']),
                'hint' => number_format($inventory['sku_count']).' SKU',
                'icon' => 'icon-product',
                'url' => '/inventory-products'
            ],
            [
                'label' => '{LNG_Stock value}',
                'value' => Currency::format($inventory['stock_value'], OrderHelper::getValueDecimals()),
                'hint' => '{LNG_Default cost}',
                'icon' => 'icon-report',
                'url' => '/inventory-cost-layers'
            ]
        ];
    }

    /**
     * @return array<string,float|int>
     */
    private function orderAggregate(array $where): array
    {
        $row = \Kotchasan\Model::createQuery()
            ->select(Sql::COUNT('*', 'count'), Sql::SUM('total', 'total'))
            ->from('order')
            ->where($where)
            ->first();

        return [
            'count' => (int) ($row->count ?? 0),
            'total' => (float) ($row->total ?? 0)
        ];
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function getDocumentSummary(string $monthStart, string $monthEnd): array
    {
        $rows = \Kotchasan\Model::createQuery()
            ->select('document_type', Sql::COUNT('*', 'count'), Sql::SUM('total', 'total'))
            ->from('order')
            ->where([
                ['document_status', '!=', 'cancelled'],
                ['created_at', '>=', $monthStart.' 00:00:00'],
                ['created_at', '<=', $monthEnd.' 23:59:59']
            ])
            ->groupBy('document_type')
            ->orderBy('document_type')
            ->fetchAll();

        $summary = [];
        foreach ($rows as $row) {
            $type = (string) ($row->document_type ?? '');
            $summary[] = [
                'document_type' => $type,
                'label' => OrderHelper::getDocumentTypeText($type),
                'count' => number_format((float) ($row->count ?? 0)),
                'total' => Currency::format($row->total ?? 0, OrderHelper::getValueDecimals()),
                'url' => '/orders?document_type='.rawurlencode($type)
            ];
        }

        return $summary;
    }

    /**
     * @return array<string,string>
     */
    private function getInventorySummary(string $todayStart, string $monthStart, string $monthEnd): array
    {
        $summary = $this->getInventoryRawSummary($todayStart);

        $monthMovements = \Kotchasan\Model::createQuery()
            ->select('movement_direction', Sql::COUNT('*', 'count'), Sql::SUM('quantity', 'quantity'))
            ->from('inventory_stock_movement')
            ->where([
                ['occurred_at', '>=', $monthStart.' 00:00:00'],
                ['occurred_at', '<=', $monthEnd.' 23:59:59']
            ])
            ->groupBy('movement_direction')
            ->fetchAll();

        $inQty = 0.0;
        $outQty = 0.0;
        foreach ($monthMovements as $row) {
            if (($row->movement_direction ?? '') === 'in') {
                $inQty += (float) ($row->quantity ?? 0);
            } elseif (($row->movement_direction ?? '') === 'out') {
                $outQty += (float) ($row->quantity ?? 0);
            }
        }

        return [
            'active_products' => number_format($summary['active_products']),
            'sku_count' => number_format($summary['sku_count']),
            'total_stock' => number_format($summary['total_stock'], OrderHelper::getValueDecimals()),
            'stock_value' => Currency::format($summary['stock_value'], OrderHelper::getValueDecimals()),
            'low_stock_count' => number_format($summary['low_stock_count']),
            'negative_stock_count' => number_format($summary['negative_stock_count']),
            'movements_today' => number_format($summary['movements_today']),
            'in_qty_month' => number_format($inQty, OrderHelper::getValueDecimals()),
            'out_qty_month' => number_format($outQty, OrderHelper::getValueDecimals())
        ];
    }

    /**
     * @return array<string,float|int>
     */
    private function getInventoryRawSummary(string $todayStart): array
    {
        $products = \Kotchasan\Model::createQuery()
            ->select(Sql::COUNT('id', 'active_products'))
            ->from('inventory')
            ->where(['inuse', 1])
            ->first();

        $items = \Kotchasan\Model::createQuery()
            ->select(
                Sql::COUNT('I.id', 'sku_count'),
                Sql::SUM('I.stock', 'total_stock'),
                Sql::create('SUM(I.`stock` * V.`cost`) stock_value'),
                Sql::create('SUM(CASE WHEN I.`stock` <= 0 THEN 1 ELSE 0 END) low_stock_count'),
                Sql::create('SUM(CASE WHEN I.`stock` < 0 THEN 1 ELSE 0 END) negative_stock_count')
            )
            ->from('inventory_items I')
            ->join('inventory V', ['V.id', 'I.inventory_id'], 'INNER')
            ->where([
                ['V.inuse', 1],
                ['V.stockable', 1]
            ])
            ->first();

        $movements = \Kotchasan\Model::createQuery()
            ->select(Sql::COUNT('id', 'movements_today'))
            ->from('inventory_stock_movement')
            ->where(['occurred_at', '>=', $todayStart.' 00:00:00'])
            ->first();

        return [
            'active_products' => (int) ($products->active_products ?? 0),
            'sku_count' => (int) ($items->sku_count ?? 0),
            'total_stock' => (float) ($items->total_stock ?? 0),
            'stock_value' => (float) ($items->stock_value ?? 0),
            'low_stock_count' => (int) ($items->low_stock_count ?? 0),
            'negative_stock_count' => (int) ($items->negative_stock_count ?? 0),
            'movements_today' => (int) ($movements->movements_today ?? 0)
        ];
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function getRecentOrders(): array
    {
        $rows = \Kotchasan\Model::createQuery()
            ->select('id', 'order_no', 'document_type', 'customer_name', 'total', 'created_at')
            ->from('order')
            ->where([['document_status', '!=', 'cancelled']])
            ->orderBy('created_at', 'DESC')
            ->limit(6)
            ->fetchAll();

        $items = [];
        foreach ($rows as $row) {
            $type = (string) ($row->document_type ?? '');
            $items[] = [
                'order_no' => (string) ($row->order_no ?? ''),
                'document_type' => $type,
                'document_type_text' => OrderHelper::getDocumentTypeText($type),
                'customer_name' => (string) ($row->customer_name ?? '-'),
                'total' => Currency::format($row->total ?? 0, OrderHelper::getValueDecimals()),
                'created_at' => empty($row->created_at) ? '' : Date::format($row->created_at, 'd M Y H:i'),
                'url' => '/order?id='.(int) ($row->id ?? 0)
            ];
        }

        return $items;
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function getLowStockItems(): array
    {
        $rows = \Kotchasan\Model::createQuery()
            ->select('V.id', 'V.topic', 'V.product_code', 'I.id inventory_item_id', 'I.sku', 'I.stock', 'I.unit')
            ->from('inventory_items I')
            ->join('inventory V', ['V.id', 'I.inventory_id'], 'INNER')
            ->where([
                ['V.inuse', 1],
                ['V.stockable', 1],
                ['I.stock', '<=', 0]
            ])
            ->orderBy('I.stock', 'ASC')
            ->limit(6)
            ->fetchAll();

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'topic' => (string) ($row->topic ?? ''),
                'product_code' => (string) ($row->product_code ?? ''),
                'sku' => (string) ($row->sku ?? ''),
                'stock' => number_format((float) ($row->stock ?? 0), OrderHelper::getValueDecimals()),
                'unit' => (string) ($row->unit ?? ''),
                'url' => '/inventory-items?id='.(int) ($row->id ?? 0)
            ];
        }

        return $items;
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function getRecentMovements(): array
    {
        $rows = \Kotchasan\Model::createQuery()
            ->select('M.inventory_id', 'V.topic', 'M.sku', 'M.movement_direction', 'M.movement_type', 'M.quantity', 'M.reference_no', 'M.occurred_at')
            ->from('inventory_stock_movement M')
            ->join('inventory V', ['V.id', 'M.inventory_id'], 'LEFT')
            ->orderBy('M.occurred_at', 'DESC')
            ->limit(6)
            ->fetchAll();

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'topic' => (string) ($row->topic ?? ''),
                'sku' => (string) ($row->sku ?? ''),
                'direction' => strtoupper((string) ($row->movement_direction ?? '')),
                'movement_type' => \Inventory\Stockmovements\Model::humanizeToken((string) ($row->movement_type ?? '')),
                'quantity' => number_format((float) ($row->quantity ?? 0), OrderHelper::getValueDecimals()),
                'reference_no' => (string) ($row->reference_no ?? ''),
                'occurred_at' => empty($row->occurred_at) ? '' : Date::format($row->occurred_at, 'd M Y H:i'),
                'url' => '/inventory-stock-movements?inventory_id='.(int) ($row->inventory_id ?? 0)
            ];
        }

        return $items;
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function getTopProducts(string $monthStart, string $monthEnd): array
    {
        $rows = \Kotchasan\Model::createQuery()
            ->select('OI.product_code', 'OI.name', Sql::SUM('OI.quantity', 'quantity'), Sql::SUM('OI.subtotal', 'total'))
            ->from('order_item OI')
            ->join('order O', ['O.id', 'OI.order_id'], 'INNER')
            ->where([
                ['O.document_type', 'RCP'],
                ['O.document_status', '!=', 'cancelled'],
                ['O.created_at', '>=', $monthStart.' 00:00:00'],
                ['O.created_at', '<=', $monthEnd.' 23:59:59']
            ])
            ->groupBy(['OI.product_code', 'OI.name'])
            ->orderBy('total', 'DESC')
            ->limit(6)
            ->fetchAll();

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'product_code' => (string) ($row->product_code ?? ''),
                'name' => (string) ($row->name ?? ''),
                'quantity' => number_format((float) ($row->quantity ?? 0), OrderHelper::getValueDecimals()),
                'total' => Currency::format($row->total ?? 0, OrderHelper::getValueDecimals())
            ];
        }

        return $items;
    }
}
