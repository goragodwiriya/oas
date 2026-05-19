<?php
/**
 * @filesource modules/inventory/controllers/helper.php
 */

namespace Inventory\Helper;

class Controller extends \Gcms\Controller
{
    /**
     * @var array
     */
    public static $trackingMode = [
        'none' => '{LNG_None}',
        'barcode' => '{LNG_Barcode}',
        'serial' => '{LNG_Serial}',
        'batch' => '{LNG_Batch}'
    ];

    /**
     * @var array
     */
    public static $productType = [
        'stock' => '{LNG_Stock}',
        'service' => '{LNG_Service}',
        'asset' => '{LNG_Asset}'
    ];

    /**
     * Cached inventory galleries for the current request.
     *
     * @var array
     */
    protected static $inventoryGalleryCache = [];

    /**
     * Get inventory gallery images.
     *
     * @param int $inventoryId
     *
     * @return array
     */
    public static function getInventoryGallery(int $inventoryId): array
    {
        if ($inventoryId <= 0) {
            return [];
        }

        if (!array_key_exists($inventoryId, self::$inventoryGalleryCache)) {
            $files = \Download\Index\Controller::getAttachments($inventoryId, 'inventory', self::$cfg->img_typies);
            self::$inventoryGalleryCache[$inventoryId] = array_values(array_filter($files, static function ($file) {
                return !empty($file['is_image']);
            }));
        }

        return self::$inventoryGalleryCache[$inventoryId];
    }

    /**
     * Get the first inventory image URL.
     *
     * @param int $inventoryId
     *
     * @return string|null
     */
    public static function getInventoryFirstImageUrl(int $inventoryId): ?string
    {
        $gallery = self::getInventoryGallery($inventoryId);

        return $gallery[0]['url'] ?? null;
    }

    /**
     * @param array $beforeItems
     */
    public static function getTrackingModeOptions($beforeItems = [])
    {
        $datas = $beforeItems + self::$trackingMode;

        return \Gcms\Controller::arrayToOptions($datas);
    }

    /**
     * @param array $beforeItems
     */
    public static function getProductTypeOptions($beforeItems = [])
    {
        $datas = $beforeItems + self::$productType;

        return \Gcms\Controller::arrayToOptions($datas);
    }

    public static function getInuseOptions()
    {
        return [
            ['value' => '1', 'text' => '{LNG_Active}'],
            ['value' => '0', 'text' => '{LNG_Inactive}']
        ];
    }

    public static function getInventoryOptions()
    {
        return [];
    }

    public static function getItemOptions()
    {
        return [];
    }

    /**
     * @return int
     */
    public static function getValueDecimals(): int
    {
        return \Order\Helper\Model::getValueDecimals();
    }

    /**
     * @return float
     */
    public static function getMinimumQuantity(): float
    {
        return \Order\Helper\Model::getMinimumQuantity();
    }

    /**
     * @param int|null $decimals
     *
     * @return string
     */
    public static function getStepValue(?int $decimals = null): string
    {
        return \Order\Helper\Model::getStepValue($decimals);
    }

    /**
     * @param mixed $value
     *
     * @return float
     */
    public static function roundValue($value): float
    {
        return round((float) $value, self::getValueDecimals());
    }

    /**
     * @return array<int,array<string,string|int>>
     */
    public static function getValueDecimalOptions(): array
    {
        return \Order\Helper\Model::getValueDecimalOptions();
    }
}
