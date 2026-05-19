<?php
/**
 * @filesource modules/export/controllers/export.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Export\Export;

use Kotchasan\Http\Response;
use Kotchasan\Template;

/**
 * Export Base Controller
 *
 * Centralized renderer for all export types.
 * Calling controllers prepare data and delegate here for final output.
 *
 * Usage (print):
 *   return \Export\Export\Controller::printHtml($title, $content, $options);
 *
 * Usage (CSV):
 *   \Export\Export\Controller::csv($filename, $headers, $rows); // exits
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends \Kotchasan\ApiController
{
    /**
     * Render a complete HTML print page and return as a Response.
     *
     * The caller prepares content (body HTML: sheets, tables, etc.) and
     * passes module-specific CSS via $options['extra_head'] so the shared
     * print.css stays generic and module CSS stays with its own module.
     *
     * @param array  $contents
     * @param array  $options {
     *   paper:        string  A4 (default) | A5 | letter | receipt80 | receipt58
     *   orientation:  string  portrait (default) | landscape
     *   lang:         string  HTML lang attribute (default: 'th')
     * }
     *
     * @return Response
     */
    public static function printHtml($contents, $options = []): Response
    {
        $allowed_papers = ['A4', 'A5', 'letter', 'receipt80', 'receipt58'];
        $allowed_orientations = ['portrait', 'landscape'];

        $contents['/%PAPER%/'] = in_array($options['paper'] ?? '', $allowed_papers, true)
            ? $options['paper'] : 'A4';
        $contents['/%ORIENTATION%/'] = in_array($options['orientation'] ?? '', $allowed_orientations, true)
            ? $options['orientation'] : 'portrait';
        $contents['/%LANG%/'] = \Kotchasan\Language::name();
        $contents['/{LNG_([^}]+)}/e'] = '\Kotchasan\Language::parse(array(1=>"$1"))';
        $contents['/%WEB_URL%/'] = WEB_URL;
        $contents['/%COMPANY_NAME%/'] = self::$cfg->company['name'] ?? '';
        $contents['/%COMPANY_NAME_EN%/'] = self::$cfg->company['name_en'] ?? '';
        $contents['/%COMPANY_ADDRESS%/'] = self::$cfg->company['address'] ?? '';
        $contents['/%COMPANY_PHONE%/'] = self::$cfg->company['phone'] ?? '';
        $contents['/%COMPANY_FAX%/'] = self::$cfg->company['fax'] ?? '';
        $contents['/%COMPANY_EMAIL%/'] = self::$cfg->company['email'] ?? '';
        $contents['/%COMPANY_TAX_ID%/'] = self::$cfg->company['tax_id'] ?? '';

        // template หลักสำหรับการพิมพ์
        $template = Template::createFromFile(ROOT_PATH.'modules/export/views/print.html');
        $template->add($contents);
        $html = $template->render();

        return (new Response())->html($html);
    }

    /**
     * Send a CSV file download and exit.
     *
     * @param string   $filename Filename without .csv extension
     * @param string[] $headers  Column header labels
     * @param array[]  $rows     Data rows (arrays of scalar values)
     */
    public static function csv(string $filename, array $headers, array $rows): void
    {
        \Kotchasan\Csv::send($filename, $headers, $rows);
        exit;
    }
}
