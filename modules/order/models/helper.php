<?php
/**
 * @filesource modules/order/models/helper.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Order\Helper;

/**
 * Shared order document rules.
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{

    /**
     * Sales-side documents.
     *
     * @var string[]
     */
    public static $salesDocumentTypes = [
        'QT' => '{LNG_Quotation}',
        'RCP' => '{LNG_Receipt}'
    ];

    /**
     * Minimal document statuses. Documents are issued by default and remain editable.
     *
     * @var array<string,string>
     */
    public static $documentStatuses = [
        'issued' => '{LNG_Issued}',
        'cancelled' => '{LNG_Cancelled}'
    ];

    /**
     * Purchase-side documents.
     *
     * @var string[]
     */
    public static $purchaseDocumentTypes = [
        'PO' => '{LNG_Purchase Order}',
        'RET' => '{LNG_Returned}',
        'GR' => '{LNG_Goods Receipt}'
    ];

    /**
     * Stock effect by document type.
     *
     * @var array<string,string>
     */
    private static $stockDirections = [
        'RCP' => 'out',
        'GR' => 'in',
        'RET' => 'in'
    ];

    /**
     * Normalize document type from input.
     *
     * @param string|null $documentType
     * @param string      $default
     *_
     * @return string
     */
    public static function normalizeDocumentType(?string $documentType, string $default = 'QT'): string
    {
        $documentType = strtoupper(trim((string) $documentType));
        if (isset(self::$purchaseDocumentTypes[$documentType])) {
            return $documentType;
        } else if (isset(self::$salesDocumentTypes[$documentType])) {
            return $documentType;
        }

        return $default;
    }

    /**
     * Normalize document status from input.
     *
     * @param string|null $documentStatus
     * @param string      $default
     *
     * @return string
     */
    public static function normalizeDocumentStatus(?string $documentStatus, string $default = 'issued'): string
    {
        $documentStatus = strtolower(trim((string) $documentStatus));
        $default = strtolower(trim($default));

        return isset(self::$documentStatuses[$documentStatus]) ? $documentStatus : (isset(self::$documentStatuses[$default]) ? $default : 'issued');
    }

    /**
     * Returns a selection of document types that are of the same type as the specified document.
     *
     * @param string|null $type null returns all types.
     *
     * @return array
     */
    public static function getDocumentType($type = null): array
    {
        if ($type === null) {
            return self::$purchaseDocumentTypes + self::$salesDocumentTypes;
        } elseif (isset(self::$purchaseDocumentTypes[$type])) {
            return self::$purchaseDocumentTypes;
        } elseif (isset(self::$salesDocumentTypes[$type])) {
            return self::$salesDocumentTypes;
        }

        return [];
    }

    /**
     * Returns a selection of document types that are of the same type as the specified document.
     *
     * @param string|null $type null returns all types.
     *
     * @return array
     */
    public static function getDocumentTypeOptions($type = null): array
    {
        $types = self::getDocumentType($type);

        return \Gcms\Controller::arrayToOptions($types);
    }

    /**
     * @return array<int,array<string,string>>
     */
    public static function getDocumentStatusOptions(): array
    {
        return \Gcms\Controller::arrayToOptions(self::$documentStatuses);
    }

    /**
     * @param string|null $documentType
     *
     * @return string
     */
    public static function getDocumentTypeText(?string $documentType): string
    {
        $documentType = strtoupper(trim((string) $documentType));
        if (isset(self::$purchaseDocumentTypes[$documentType])) {
            return self::$purchaseDocumentTypes[$documentType];
        } else if (isset(self::$salesDocumentTypes[$documentType])) {
            return self::$salesDocumentTypes[$documentType];
        }

        return $documentType;
    }

    /**
     * @param string|null $documentStatus
     *
     * @return string
     */
    public static function getDocumentStatusText(?string $documentStatus): string
    {
        $documentStatus = self::normalizeDocumentStatus($documentStatus);

        return self::$documentStatuses[$documentStatus] ?? $documentStatus;
    }

    /**
     * Return "out", "in", or an empty string when the document should not touch stock.
     *
     * @param string|null $documentType
     *
     * @return string
     */
    public static function getStockDirection(?string $documentType): string
    {
        $documentType = self::normalizeDocumentType($documentType);

        return self::$stockDirections[$documentType] ?? '';
    }

    /**
     * @param string|null $documentType
     *
     * @return array<string,mixed>
     */
    public static function getDocumentProfile(?string $documentType): array
    {
        $documentType = self::normalizeDocumentType($documentType, 'QT');
        $isPurchase = isset(self::$purchaseDocumentTypes[$documentType]);

        return [
            'document_type' => $documentType,
            'family' => $isPurchase ? 'purchase' : 'sales',
            'party_type' => $isPurchase ? 'supplier' : 'customer',
            'party_label' => $isPurchase ? '{LNG_Supplier}' : '{LNG_Customer}',
            'party_search_label' => $isPurchase ? '{LNG_Search} {LNG_Supplier}' : '{LNG_Search} {LNG_Customer}',
            'party_manage_label' => $isPurchase ? '{LNG_Add}/{LNG_Edit} {LNG_Supplier}' : '{LNG_Add}/{LNG_Edit} {LNG_Customer}',
            'party_help_text' => $isPurchase
                ? '{LNG_Select an existing supplier to autofill this document.}'
                : '{LNG_Optional. Select an existing customer to autofill this order.}'
        ];
    }

    /**
     * @return int
     */
    public static function getValueDecimals(): int
    {
        return self::$cfg->value_decimals ?? 2;
    }

    /**
     * @return float
     */
    public static function getMinimumQuantity(): float
    {
        return (float) ('0.'.str_repeat('0', self::getValueDecimals() - 1).'1');
    }

    /**
     * @param int|null $decimals
     *
     * @return string
     */
    public static function getStepValue(?int $decimals = null): string
    {
        $valueDecimals = $decimals ?? self::getValueDecimals();

        return number_format((float) ('0.'.str_repeat('0', $valueDecimals - 1).'1'), $valueDecimals, '.', '');
    }

    /**
     * @return array<int,array<string,string|int>>
     */
    public static function getValueDecimalOptions(): array
    {
        return [
            ['value' => 2, 'text' => '0.00'],
            ['value' => 4, 'text' => '0.0000']
        ];
    }
}
