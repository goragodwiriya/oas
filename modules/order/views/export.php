<?php
/**
 * @filesource modules/order/views/export.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Order\Export;

use Kotchasan\Currency;
use Kotchasan\Date;
use Kotchasan\Template;
use Kotchasan\Text;
use Order\Helper\Model as OrderHelper;

/**
 * Order receipt print view.
 */
class View extends \Kotchasan\KBase
{
    /**
     * @return int
     */
    private static function getOrderValueDecimals(): int
    {
        return OrderHelper::getValueDecimals();
    }

    /**
     * Resolve printable title from document type.
     *
     * @param string|null $documentType
     *
     * @return string
     */
    public static function getDocumentTitle(?string $documentType): string
    {
        switch (\Order\Helper\Model::normalizeDocumentType($documentType, 'QT')) {
        case 'QT':
            return 'ใบเสนอราคา / Quotation';
        case 'SO':
            return 'ใบสั่งขาย / Sales Order';
        case 'DN':
            return 'ใบส่งของ / Delivery Note';
        case 'INV':
            return 'ใบแจ้งหนี้ / Invoice';
        case 'RCP':
            return 'ใบเสร็จรับเงิน / Receipt';
        case 'PR':
            return 'ใบขอซื้อ / Purchase Request';
        case 'PO':
            return 'ใบสั่งซื้อ / Purchase Order';
        case 'GR':
            return 'ใบรับสินค้า / Goods Receipt';
        case 'PINV':
            return 'ใบแจ้งหนี้ซื้อ / Purchase Invoice';
        case 'RET':
            return 'ใบคืนสินค้า / Returned';
        default:
            return 'เอกสาร / Document';
        }
    }

    /**
     * Render printable receipt sheets (body HTML only, no document shell).
     *
     * @param object[] $orders
     * @param int      $itemsPerPage
     * @param array    $company
     *
     * @return string
     */
    public static function renderSheets(array $orders, int $itemsPerPage, array $company = []): string
    {
        $itemsPerPage = max(5, min(25, $itemsPerPage));
        if (empty($orders)) {
            return self::renderBlankSheet($company);
        }

        $companyTokens = self::buildCompanyTokens($company);
        $sheets = [];

        foreach ($orders as $order) {
            $sheets[] = self::renderOrder($order, $itemsPerPage, $companyTokens);
        }

        return implode('', $sheets);
    }

    /**
     * Render a single blank page when there is nothing to print.
     * The shared receipt template is still used, but order-specific sections are hidden by CSS.
     *
     * @param array $company
     *
     * @return string
     */
    private static function renderBlankSheet(array $company = []): string
    {
        return self::renderSheet('RCP', array_merge(
            self::languageTokens(),
            self::buildCompanyTokens($company),
            [
                '%SHEET_CLASS%' => ' receipt-sheet--blank',
                '%TITLE%' => self::getDocumentTitle(null),
                '%PAGE%' => '-',
                '%ORDER_NO%' => '-',
                '%REFERENCE_NO%' => '&nbsp;',
                '%REFERENCE_NO_CLASS%' => ' is-empty',
                '%CREATED%' => '&nbsp;',
                '%DUE_DATE%' => '&nbsp;',
                '%DUE_DATE_CLASS%' => ' is-empty',
                '%UPDATED%' => '&nbsp;',
                '%FULFILLMENT%' => '&nbsp;',
                '%BILL_TO_NAME%' => '&nbsp;',
                '%BILL_TO_ADDRESS%' => '&nbsp;',
                '%BILL_TO_TAX_ID%' => '&nbsp;',
                '%BILL_TO_TAX_ID_CLASS%' => ' is-empty',
                '%BILL_TO_CONTACT%' => '&nbsp;',
                '%BILL_TO_CONTACT_CLASS%' => ' is-empty',
                '%SHIP_TO_NAME%' => '&nbsp;',
                '%SHIP_TO_ADDRESS%' => '&nbsp;',
                '%SHIP_TO_CONTACT%' => '&nbsp;',
                '%SHIP_TO_CONTACT_CLASS%' => ' is-empty',
                '%SHIP_TO_TRACKING%' => '-',
                '%SHIP_TO_PAYMENTS%' => '&nbsp;',
                '%SHIP_TO_PAYMENTS_CLASS%' => ' is-empty',
                '%ITEM_ROWS%' => '&nbsp;',
                '%NOTES%' => '&nbsp;',
                '%PAGESUBTOTAL%' => '&nbsp;',
                '%ORDERSUBTOTAL%' => '&nbsp;',
                '%DISCOUNT%' => '&nbsp;',
                '%VAT%' => '&nbsp;',
                '%VATRATE%' => '&nbsp;',
                '%SHIPPING%' => '&nbsp;',
                '%GRANDTOTAL%' => '&nbsp;',
                '%GRANDTOTAL_WORDS%' => '&nbsp;',
                '%PAID%' => '&nbsp;',
                '%BALANCE%' => '&nbsp;',
                '%COMPANY_LOGO%' => '&nbsp;',
                '%COMPANY_STAMP%' => '&nbsp;',
                '%PREPARED_BY_NAME%' => '&nbsp;',
                '%PREPARED_BY_SIGNATURE%' => '&nbsp;',
                '%APPROVED_BY_NAME%' => '&nbsp;',
                '%RECEIVED_BY_NAME%' => '&nbsp;',
                '%RECEIVED_BY_SIGNATURE%' => '&nbsp;',
                '%ITEMS%' => '0'
            ]
        ));
    }

    /**
     * Render all pages for a single order using receipt.html as the sheet template.
     *
     * @param object $order
     * @param int    $itemsPerPage
     * @param array  $companyTokens
     *
     * @return string
     */
    private static function renderOrder(object $order, int $itemsPerPage, array $companyTokens): string
    {
        $items = is_array($order->items ?? null) ? $order->items : [];
        $chunks = array_chunk($items, $itemsPerPage);
        if (empty($chunks)) {
            $chunks = [[]];
        }

        $itemCount = count($items);
        $totalPages = count($chunks);
        $orderTokens = self::buildOrderTokens($order, $itemCount);
        $summaryTokens = self::buildSummaryTokens($order);
        $partyTokens = self::buildPartyTokens($order);
        $orderNote = self::pickFirst([
            $order->note ?? '',
            $order->internal_note ?? ''
        ]);

        $pages = [];
        foreach ($chunks as $pageIndex => $pageItems) {
            $pageNumber = $pageIndex + 1;
            $pages[] = self::renderSheet($order->document_type, array_merge(
                self::languageTokens(),
                $companyTokens,
                $orderTokens,
                $summaryTokens,
                $partyTokens,
                [
                    '%SHEET_CLASS%' => '',
                    '%PAGE%' => $pageNumber.'/'.$totalPages,
                    '%ITEM_ROWS%' => self::renderItemRows($pageItems, $pageIndex, $itemsPerPage),
                    '%PAGESUBTOTAL%' => self::money(self::sumItems($pageItems)),
                    '%NOTES%' => self::multiline($pageNumber < $totalPages
                            ? '{LNG_There is a continuation on the next page.}'
                            : self::pickFirst([$orderNote], '{LNG_This document shows a complete summary of items and totals.}'))
                ]
            ));
        }

        return implode('', $pages);
    }

    /**
     * @param string $documentType
     * @param array $tokens
     *
     * @return string
     */
    private static function renderSheet(string $documentType, array $tokens): string
    {
        $file = ROOT_PATH.'modules/order/views/'.$documentType.'.html';
        if (!file_exists($file)) {
            $file = ROOT_PATH.'modules/order/views/print.html';
        }
        $template = Template::createFromFile($file);
        $template->add(self::toTemplateMap($tokens));

        return $template->render();
    }

    /**
     * @param array $company
     *
     * @return array
     */
    private static function buildCompanyTokens(array $company): array
    {
        $subtitle = trim((string) ($company['name_en'] ?? ''));
        $address = trim((string) ($company['address'] ?? ''));
        $phone = trim((string) ($company['phone'] ?? ''));
        $fax = trim((string) ($company['fax'] ?? ''));
        $email = trim((string) ($company['email'] ?? ''));
        $taxId = trim((string) ($company['tax_id'] ?? ''));
        $authorizedMemberId = (int) ($company['authorized_member_id'] ?? 0);
        $authorizedName = trim((string) ($company['authorized_name'] ?? ''));

        return [
            '%COMPANY_NAME%' => self::escape((string) ($company['name'] ?? 'Order Receipt')),
            '%COMPANY_LOGO%' => self::buildImageHtml('company_logo'),
            '%COMPANY_SUBTITLE%' => self::escape($subtitle),
            '%COMPANY_SUBTITLE_CLASS%' => self::emptyClass($subtitle),
            '%COMPANY_ADDRESS%' => self::multilineOrEmpty($address),
            '%COMPANY_ADDRESS_CLASS%' => self::emptyClass($address),
            '%COMPANY_PHONE%' => self::escape($phone),
            '%COMPANY_PHONE_CLASS%' => self::emptyClass($phone),
            '%COMPANY_FAX%' => self::escape($fax),
            '%COMPANY_FAX_CLASS%' => self::emptyClass($fax),
            '%COMPANY_EMAIL%' => self::escape($email),
            '%COMPANY_EMAIL_CLASS%' => self::emptyClass($email),
            '%COMPANY_TAX_ID%' => self::escape($taxId),
            '%COMPANY_TAX_ID_CLASS%' => self::emptyClass($taxId),
            '%APPROVED_BY_NAME%' => self::escape($authorizedName),
            '%APPROVED_BY_SIGNATURE%' => self::buildMemberSignature($authorizedMemberId)
        ];
    }

    /**
     * Build HTML image tag for a given type or return empty string
     *
     * @param string $type
     *
     * @return string
     */
    private static function buildImageHtml(string $type): string
    {
        $imageType = self::getStoredImageType();
        if (file_exists(ROOT_PATH.DATA_FOLDER.'images/'.$type.$imageType)) {
            return '<img src="'.WEB_URL.DATA_FOLDER.'images/'.$type.$imageType.'" alt="'.self::escape(str_replace('_', ' ', $type)).'">';
        }
        return '';
    }

    /**
     * @param object $order
     * @param int    $itemCount
     *
     * @return array
     */
    private static function buildOrderTokens(object $order, int $itemCount): array
    {
        return [
            '%TITLE%' => self::escape(self::getDocumentTitle($order->document_type ?? null)),
            '%ORDER_NO%' => self::escape((string) ($order->order_no ?? '-')),
            '%REFERENCE_NO%' => self::escape((string) ($order->reference_document_no ?? '')),
            '%REFERENCE_NO_CLASS%' => self::emptyClass((string) ($order->reference_document_no ?? '')),
            '%CREATED%' => Date::format($order->created_at, 'd M Y'),
            '%DUE_DATE%' => self::formatDate($order->due_date ?? ''),
            '%DUE_DATE_CLASS%' => self::emptyClass((string) ($order->due_date ?? '')),
            '%UPDATED%' => Date::format($order->updated_at, 'd M Y'),
            '%ITEMS%' => (string) $itemCount
        ];
    }

    /**
     * @param object $order
     *
     * @return array
     */
    private static function buildPartyTokens(object $order): array
    {
        $billingName = self::pickFirst([
            $order->customer_company ?? '',
            $order->customer_name ?? ''
        ], '-');
        $billingAddress = self::joinParts([
            trim((string) ($order->customer_address ?? '')),
            trim((string) ($order->customer_province ?? '')),
            trim((string) ($order->customer_zipcode ?? ''))
        ], ' ');
        $billingTaxId = $order->customer_tax_id ?? '';
        $contactLine = self::joinParts([
            trim((string) ($order->customer_phone ?? '')),
            trim((string) ($order->customer_contact ?? '')),
            trim((string) ($order->customer_email ?? ''))
        ]);

        return [
            '%BILL_TO_NAME%' => self::escape($billingName),
            '%BILL_TO_ADDRESS%' => self::multiline($billingAddress),
            '%BILL_TO_TAX_ID%' => self::escape($billingTaxId),
            '%BILL_TO_TAX_ID_CLASS%' => self::emptyClass($billingTaxId),
            '%BILL_TO_CONTACT%' => self::escape($contactLine),
            '%BILL_TO_CONTACT_CLASS%' => self::emptyClass($contactLine),
            '%PREPARED_BY_NAME%' => self::escape($order->salesperson_name),
            '%PREPARED_BY_SIGNATURE%' => self::buildMemberSignature($order->member_id),
            '%COMPANY_STAMP%' => self::buildImageHtml('company_stamp')
        ];
    }

    /**
     * Build member signature image HTML from member id if available
     *
     * @param mixed $memberId
     *
     * @return string
     */
    private static function buildMemberSignature($memberId): string
    {
        $memberId = (int) $memberId;
        if ($memberId <= 0) {
            return '&nbsp;';
        }

        $imageType = self::getStoredImageType();
        if (file_exists(ROOT_PATH.DATA_FOLDER.'signature/'.$memberId.$imageType)) {
            return '<img src="'.WEB_URL.DATA_FOLDER.'signature/'.$memberId.$imageType.'" alt="signature">';
        }

        return '&nbsp;';
    }

    /**
     * @return string
     */
    private static function getStoredImageType(): string
    {
        return (string) (self::$cfg->stored_img_type ?? '.webp');
    }

    /**
     * @param object $order
     *
     * @return array
     */
    private static function buildSummaryTokens(object $order): array
    {
        $grandTotal = (float) ($order->total ?? 0);
        $paidAmount = (float) ($order->paid_amount ?? 0);

        return [
            '%ORDERSUBTOTAL%' => self::money($order->subtotal ?? 0),
            '%DISCOUNT%' => self::money($order->discount_amount ?? 0),
            '%VAT%' => self::money($order->tax_amount ?? 0),
            '%VATRATE%' => self::formatNumber($order->tax_rate ?? 0, self::getOrderValueDecimals()).'%',
            '%SHIPPING%' => self::money($order->shipping_cost ?? 0),
            '%GRANDTOTAL%' => self::money($grandTotal),
            '%GRANDTOTAL_WORDS%' => Currency::bahtThai(round($grandTotal, 2)),
            '%PAID%' => self::money($paidAmount),
            '%BALANCE%' => self::money(max(0, $grandTotal - $paidAmount))
        ];
    }

    /**
     * @param object[] $items
     * @param int      $pageIndex
     * @param int      $itemsPerPage
     *
     * @return string
     */
    private static function renderItemRows(array $items, int $pageIndex, int $itemsPerPage): string
    {
        $rows = [];
        foreach ($items as $itemIndex => $item) {
            $sequence = ($pageIndex * $itemsPerPage) + $itemIndex + 1;
            $rows[] = '<tr>'
            .'<td class="col-index">'.$sequence.'</td>'
            .'<td class="col-code">'.self::escape((string) ($item->product_code ?? $item->sku ?? '')).'</td>'
            .'<td class="col-item"><span class="item-title">'.self::escape($item->name ?? '-').'</span>'
            .self::renderItemNote((string) ($item->note ?? '')).'</td>'
            .'<td class="col-qty">'.self::formatQuantity($item->qty ?? $item->quantity ?? 0).'</td>'
            .'<td class="col-unit">'.self::escape((string) ($item->unit ?? '')).'</td>'
            .'<td class="col-price">'.self::money($item->price ?? $item->unit_price ?? 0).'</td>'
            .'<td class="col-discount">'.self::money($item->discount_amount ?? 0).'</td>'
            .'<td class="col-total">'.self::money($item->total ?? $item->subtotal ?? 0).'</td>'
                .'</tr>';
        }

        for ($i = count($items); $i < $itemsPerPage; ++$i) {
            $rows[] = '<tr class="placeholder">'
                .'<td class="col-index">&nbsp;</td>'
                .'<td class="col-code">&nbsp;</td>'
                .'<td class="col-item">&nbsp;</td>'
                .'<td class="col-qty">&nbsp;</td>'
                .'<td class="col-unit">&nbsp;</td>'
                .'<td class="col-price">&nbsp;</td>'
                .'<td class="col-discount">&nbsp;</td>'
                .'<td class="col-total">&nbsp;</td>'
                .'</tr>';
        }

        return implode('', $rows);
    }

    /**
     * @param object[] $items
     *
     * @return float
     */
    private static function sumItems(array $items): float
    {
        $total = 0.0;
        foreach ($items as $item) {
            $total += (float) ($item->total ?? $item->subtotal ?? 0);
        }

        return round($total, self::getOrderValueDecimals());
    }

    /**
     * @return array
     */
    private static function languageTokens(): array
    {
        return [
            '{LNG_([^}]+)}' => '\\Kotchasan\\Language::parse(array(1=>"$1"))'
        ];
    }

    /**
     * Convert raw template tokens into regex replacement keys expected by Template::add().
     *
     * @param array $tokens
     *
     * @return array
     */
    private static function toTemplateMap(array $tokens): array
    {
        $result = [];
        foreach ($tokens as $key => $value) {
            if ($key === '{LNG_([^}]+)}') {
                $result['/{LNG_([^}]+)}/e'] = $value;
            } else {
                $result['/'.preg_quote($key, '/').'/'] = self::escapeReplacement((string) $value);
            }
        }

        return $result;
    }

    /**
     * @param string $value
     *
     * @return string
     */
    private static function escapeReplacement(string $value): string
    {
        return strtr($value, [
            '\\' => '\\\\',
            '$' => '\\$'
        ]);
    }

    /**
     * @param string $value
     *
     * @return string
     */
    private static function escape(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '&nbsp;';
        }

        return Text::htmlspecialchars($value, true);
    }

    /**
     * @param mixed $value
     *
     * @return string
     */
    private static function money($value): string
    {
        return Currency::format($value, self::getOrderValueDecimals());
    }

    /**
     * @param mixed $value
     * @param int   $decimals
     *
     * @return string
     */
    private static function formatNumber($value, int $decimals = 2): string
    {
        return number_format((float) $value, $decimals);
    }

    /**
     * @param mixed $value
     *
     * @return string
     */
    private static function formatQuantity($value): string
    {
        $formatted = number_format((float) $value, self::getOrderValueDecimals());

        return rtrim(rtrim($formatted, '0'), '.');
    }

    /**
     * @param mixed $value
     *
     * @return string
     */
    private static function formatDate($value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '&nbsp;';
        }

        return Date::format($value, 'd M Y');
    }

    /**
     * @param mixed $value
     *
     * @return string
     */
    private static function humanize($value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '-';
        }
        if (ctype_digit($value)) {
            return $value;
        }

        return ucwords(str_replace('_', ' ', $value));
    }

    /**
     * @param string $value
     *
     * @return string
     */
    private static function formatDateTime(string $value): string
    {
        if ($value === '') {
            return '-';
        }

        try {
            $date = new \DateTime($value);
            $year = (int) $date->format('Y') + 543;

            return $date->format('d/m/').$year.' '.$date->format('H:i');
        } catch (\Exception $e) {
            return $value;
        }
    }

    /**
     * @param string $value
     *
     * @return string
     */
    private static function multiline(string $value): string
    {
        $value = trim($value);
        return $value === '' ? '&nbsp;' : nl2br(self::escape($value));
    }

    /**
     * @param string $value
     *
     * @return string
     */
    private static function multilineOrEmpty(string $value): string
    {
        $value = trim($value);
        return $value === '' ? '&nbsp;' : nl2br(self::escape($value));
    }

    /**
     * @param string $value
     *
     * @return string
     */
    private static function emptyClass(string $value): string
    {
        return trim($value) === '' ? ' is-empty' : '';
    }

    /**
     * @param array       $values
     * @param string|null $fallback
     *
     * @return string
     */
    private static function pickFirst(array $values, string $fallback = ''): string
    {
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }

        return $fallback;
    }

    /**
     * @param array  $values
     * @param string $separator
     *
     * @return string
     */
    private static function joinParts(array $values, string $separator = '  |  '): string
    {
        $result = [];
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $result[] = $value;
            }
        }

        return implode($separator, $result);
    }

    /**
     * @param string $note
     *
     * @return string
     */
    private static function renderItemNote(string $note): string
    {
        $note = trim($note);

        return $note === '' ? '' : '<span class="item-note">'.self::escape($note).'</span>';
    }
}
