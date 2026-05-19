<?php
/**
 * @filesource modules/customer/models/customer.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Customer\Customer;

/**
 * API Customer Model
 *
 * CRM Customer with comprehensive metrics
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * Customer type
     *
     * @var array
     */
    public static $types = [
        "customer" => "{LNG_Customer}",
        "supplier" => "{LNG_Supplier}"
    ];

    /**
     * Get customer by ID
     * $id = 0 returns empty customer object for new customer form
     *
     * @param int $id
     * @param string $type
     *
     * @return object|null
     */
    public static function get($id, $type)
    {
        if ($id === 0) {
            return (object) [
                'id' => 0,
                'type' => $type === 'supplier' ? 'supplier' : 'customer',
                'is_active' => 1
            ];
        }

        return static::createQuery()
            ->select()
            ->from('customer')
            ->where(['id', $id])
            ->first();
    }

    /**
     * Save customer data
     * Return customer ID
     *
     * @param int $id customer ID (0 for new customer)
     * @param array $save Data to save
     *
     * @return int customer ID
     */
    public static function save($id, $save)
    {
        if (isset($save['type']) && $save['type'] === 'supplier') {
            $formatCode = self::$cfg->supplier_format_code ?? 'SUP%04d';
        } else {
            $save['type'] = 'customer';
            $formatCode = self::$cfg->customer_format_code ?? 'CUS%04d';
        }

        if (empty($save['code'])) {
            $save['code'] = \Index\Number\Model::get($save['id'], $formatCode, 'customer', 'code');
        }

        $db = \Kotchasan\DB::create();

        if ($id === 0) {
            return $db->insert('customer', $save);
        } else {
            $db->update('customer', ['id', $id], $save);
            return $id;
        }
    }

    /**
     * Get one customer/supplier record by ID.
     *
     * @param int $id
     *
     * @return object|null
     */
    public static function getRecord(int $id)
    {
        if ($id <= 0) {
            return null;
        }

        return static::createQuery()
            ->select()
            ->from('customer')
            ->where(['id', $id])
            ->first();
    }

    /**
     * Get customer type options
     *
     * @return array
     */
    public static function getCustomerTypeOptions()
    {
        return \Gcms\Controller::arrayToOptions(self::$types);
    }

    /**
     * Customer options for select inputs.
     *
     * @return array
     */
    public static function getCustomerOptions(): array
    {
        return self::getPartyOptions('customer');
    }

    /**
     * Supplier options for select inputs.
     *
     * @return array
     */
    public static function getSupplierOptions(): array
    {
        return self::getPartyOptions('supplier');
    }

    /**
     * Load active customer/supplier options by type.
     *
     * @param string $type
     *
     * @return array
     */
    protected static function getPartyOptions(string $type): array
    {
        $result = [
            ['value' => '', 'text' => '{LNG_Please select}']
        ];

        foreach (static::createQuery()
            ->select('id', 'name')
            ->from('customer')
            ->where([
                ['type', $type],
                ['is_active', 1]
            ])
            ->orderBy('name')
            ->cacheOn()
            ->fetchAll() as $item) {
            $result[] = [
                'value' => (string) $item->id,
                'text' => $item->name
            ];
        }

        return $result;
    }
}
