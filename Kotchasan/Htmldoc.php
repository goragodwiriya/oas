<?php
/**
 * @filesource Kotchasan/Htmldoc.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan;

/**
 * Convert HTML to MS Word file
 *
 * @see https://www.kotchasan.com/
 */
class Htmldoc
{
    /**
     * Document file name
     *
     * @var string
     */
    private $docFile;

    /**
     * HTML body
     *
     * @var string
     */
    private $htmlBody;

    /**
     * HTML header
     *
     * @var string
     */
    private $htmlHead;

    /**
     * Class Constructor
     */
    public function __construct()
    {
        $this->htmlHead = '';
        $this->htmlBody = '';
        $this->docFile = 'Untitled.doc';
    }

    /**
     * Create a MS Word document from HTML
     *
     * @param string $html HTML content
     * @param string $file Document file name
     */
    public function createDoc($html, $file = '')
    {
        // Parse the HTML document
        $this->parseHtml($html, $file);

        // Export as a Word document
        $response = new \Kotchasan\Http\Response();
        $response->withHeaders(array(
            'Content-type' => 'application/vnd.ms-word',
            'Content-Disposition' => 'attachment;Filename='.$this->docFile
        ))
            ->withContent($this->render())
            ->send();
    }

    /**
     * Set the document file name
     *
     * @param string $docfile
     * @return $this
     */
    public function setDocFileName($docfile)
    {
        $this->docFile = $docfile;

        // If the file name doesn't end with ".doc", append it
        if (!preg_match('/\.doc$/i', $this->docFile)) {
            $this->docFile .= '.doc';
        }

        return $this;
    }

    /**
     * Parse HTML source
     *
     * @param string $html HTML content
     * @param string $file Document file name
     */
    private function parseHtml($html, $file)
    {
        // Remove script tags from HTML
        $html = preg_replace('/<script((.|\n)*?)>((.|\n)*?)<\/script>/ims', '', $html);

        // Extract the HTML head
        if (preg_match('/<head>(.*)<\/head>/isU', $html, $matches)) {
            $this->htmlHead = preg_replace('/<title>(.*)<\/title>/isU', '', $matches[1]);
        }

        // Set the document file name based on the HTML title tag
        if ($file == '' && preg_match('/<title>(.*)<\/title>/isU', $html, $matches)) {
            $this->setDocFileName($matches[1].'.doc');
        }

        // Extract the HTML body and process special spans
        if (preg_match('/<body[^>]+>(.*)<\/body>/isU', $html, $matches)) {
            $html = $matches[1];
        }
        $this->htmlBody = preg_replace_callback('/<span[^>]+class="line([0-9]{0,})">([^>]+)<\/span>/isuU', function ($items) {
            $datas = array(0 => 20, 1 => 40, 2 => 60, 3 => 80, 4 => 100);
            $text = trim(str_replace('&nbsp;', ' ', $items[2]));
            $len = ($datas[(int) $items[1]] - mb_strlen($text)) / 2;
            for ($i = 0; $i < $len; ++$i) {
                $text = '.'.$text.'.';
            }
            return ' <span> '.$text.' </span> ';
        }, $html);
    }

    /**
     * Render the Word document
     *
     * @return string
     */
    private function render()
    {
        return <<<EOH
<html xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns:m="http://schemas.microsoft.com/office/2004/12/omml" xmlns="http://www.w3.org/TR/REC-html40">
<head>
    <style>
        v\:* {behavior:url(#default#VML);}
        o\:* {behavior:url(#default#VML);}
        w\:* {behavior:url(#default#VML);}
        .shape {behavior:url(#default#VML);}
    </style>
    <style>
        @page {
            size: 21cm 29.7cm;
            margin: 1cm 1cm 1cm 1cm;
            mso-page-orientation: portrait;
        }
        @page WordSection1 {
            mso-title-page: no;
            mso-paper-source:0;
            mso-header-margin: 0;
            mso-footer-margin: 0;
        }
        div.WordSection1 {
            page:WordSection1;
            mso-header-margin: 0;
            mso-footer-margin: 0;
        }
    </style>
    $this->htmlHead
</head>
<body>
    $this->htmlBody
</body>
</html>
EOH;
    }
}
