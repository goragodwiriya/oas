<?php
/**
 * @filesource modules/order/models/shipping.php
 */

namespace Order\Shipping;

class Model extends \Kotchasan\Model
{
    /**
     * @return mixed
     */
    public static function toOptions(): array
    {
        $options = [
            ['value' => '', 'text' => '{LNG_Please select}']
        ];

        foreach (static::createQuery()
            ->select('id', 'name')
            ->from('shipping_method')
            ->where(['is_active', 1])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->fetchAll() as $item) {
            $options[] = [
                'value' => (string) ($item->id ?? ''),
                'text' => (string) ($item->name ?? '')
            ];
        }

        return $options;
    }
}