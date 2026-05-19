<?php
/**
 * @filesource modules/customer/models/autocomplete.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Customer\Autocomplete;

/**
 * Customer Autocomplete Model — CRUD for customer autocomplete
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * Hydrate order form fields from a selected customer.
     *
     * @param int $customerId
     *
     * @return array<string, mixed>
     */
    public static function getCustomerFormData(int $customerId): array
    {
        if ($customerId < 1) {
            return [];
        }

        $customer = \Customer\Customer\Model::get($customerId, '');
        if ($customer === null) {
            return [];
        }

        $customerName = trim((string) ($customer->name ?? ''));

        return [
            'customer_id' => (int) $customer->id,
            'customer' => self::buildCustomerOption($customer),
            'customer_name' => $customerName,
            'customer_phone' => (string) ($customer->phone ?? ''),
            'customer_tax_id' => (string) ($customer->tax_id ?? ''),
            'customer_address' => (string) ($customer->address ?? '')
        ];
    }

    /**
     * Build autocomplete option payload for customer fields.
     *
     * @param object $customer
     *
     * @return array<string, int|string>
     */
    public static function buildCustomerOption(object $customer): array
    {
        return [
            'value' => (int) $customer->id,
            'text' => self::buildCustomerLabel($customer)
        ];
    }

    /**
     * Build customer search label for autocomplete display.
     *
     * @param object $customer
     *
     * @return string
     */
    private static function buildCustomerLabel(object $customer): string
    {
        $label = trim((string) ($customer->name ?? ''));
        if (!empty($customer->code)) {
            $label = trim($customer->code.' '.$label);
        }

        $meta = [];
        if (!empty($customer->phone)) {
            $meta[] = $customer->phone;
        }
        if (!empty($customer->company)) {
            $meta[] = $customer->company;
        } elseif (!empty($customer->contact)) {
            $meta[] = $customer->contact;
        } elseif (!empty($customer->email)) {
            $meta[] = $customer->email;
        }

        if (!empty($meta)) {
            $label .= ' ('.implode(', ', $meta).')';
        }

        return $label;
    }
}
