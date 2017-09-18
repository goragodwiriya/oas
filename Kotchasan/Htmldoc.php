<?php
/**
 * @filesource Kotchasan/Htmldoc.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Kotchasan;

/**
 * Convert HTML to MS Word file
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Htmldoc
{
  /**
   * ชื่อไฟล์
   *
   * @var string
   */
  private $docFile;
  /**
   * title
   *
   * @var string
   */
  private $title;
  /**
   * HTML header
   *
   * @var string
   */
  private $htmlHead;
  /**
   * เนื้อหา
   *
   * @var string
   */
  private $htmlBody;

  /**
   * Class Constructor
   */
  public function __construct()
  {
    $this->title = 'Untitled';
    $this->htmlHead = '';
    $this->htmlBody = '';
    $this->docFile = '';
  }

  /**
   * สร้างเอกสาร MS Word จาก HTML
   *
   * @param string $html HTML Content
   * @param string $file Document File Name
   */
  public function createDoc($html, $file = '')
  {
    // parse เอกสาร
    $this->parseHtml($html, $file);
    // ส่งออกเป็น word
    $response = new \Kotchasan\Http\Response();
    $response->withHeaders(array(
        'Content-type' => 'application/vnd.ms-word',
        'Content-Disposition' => 'attachment;Filename='.$this->docFile
      ))
      ->withContent($this->render())
      ->send();
  }

  /**
   * กำหนดชื่อเอกสาร
   *
   * @param string $docfile
   */
  public function setDocFileName($docfile)
  {
    $this->docFile = $docfile;
    if (!preg_match('/\.doc$/i', $this->docFile)) {
      $this->docFile .= '.doc';
    }
    return $this;
  }

  /**
   * สร้างเอกสาร DOC
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

  /**
   * Parse HTML source
   *
   * @param string $html HTML Content
   * @param string $file Document File Name
   */
  private function parseHtml($html, $file)
  {
    // remove script
    $html = preg_replace('/<script((.|\n)*?)>((.|\n)*?)<\/script>/ims', '', $html);
    // head
    if (preg_match('/<head>(.*)<\/head>/isU', $html, $matches)) {
      $this->htmlHead = preg_replace('/<title>(.*)<\/title>/isU', '', $matches[1]);
    }
    // file name
    if ($file == '' && preg_match('/<title>(.*)<\/title>/isU', $html, $matches)) {
      $this->setDocFileName($matches[1].'.doc');
    }
    // body
    if (preg_match('/<body[^>]+>(.*)<\/body>/isU', $html, $matches)) {
      // <span class="line"></span>
      $this->htmlBody = preg_replace_callback('/<span[^>]+class="line([0-9]{0,})">([^>]+)<\/span>/isuU', function($items) {
        $datas = array(0 => 20, 1 => 40, 2 => 60, 3 => 80, 4 => 100);
        $text = trim(str_replace('&nbsp;', ' ', $items[2]));
        $len = ($datas[(int)$items[1]] - mb_strlen($text)) / 2;
        for ($i = 0; $i < $len; $i++) {
          $text = '.'.$text.'.';
        }
        return ' <span> '.$text.' </span> ';
      }, $matches[1]);
    }
  }
}
