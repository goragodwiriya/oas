<?php
/**
 * @filesource Kotchasan/DOMParser.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Kotchasan;

use \Kotchasan\DOMNode;

/**
 * คลาสสำหรับ การ Parse DOM
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class DOMParser
{
  /**
   * รายการโหนด
   *
   * @var array
   */
  protected $doms = array();

  /**
   * class constructor
   *
   * @param string $html HTML code
   * @param string $charset encoding default utf-8
   */
  public function __construct($html, $charset = 'utf-8')
  {
    $patt = array(
      '/^(.*)<body[^>]{0,}>(.*)<\/body>(.*)$/is' => '\\2',
      '#<(style|script)(.*?)>(.*?)</\\1>#is' => '',
      '@<!--.*-->@is' => '',
      '/<(\!|link|meta)[^>]+\>/i' => '',
      '@>[\s\t]{0,}[\r\n]+[\s\t]{0,}<@' => '><',
      '@[\s\t\r\n]{0,}<(\/?(br|hr|figure|figcaption|p|div|footer|article|section|blockquote|code|aside|navy|table|tr|td|th|thead|tbody|tfoot|caption|ul|ol|li|dl|dt|dd|h[1-6])[^>]{0,})>[\s\t\r\n]{0,}@i' => '<\\1>',
      '@[\s\t]{0,}[\r\n]+[\s\t]{0,}@' => ' '
    );
    $html = preg_replace(array_keys($patt), array_values($patt), $html);
    if (strtolower($charset) != 'utf-8') {
      $html = iconv('utf-8', $charset, $html);
    }
    $node = null;
    foreach (preg_split('/<(.*)>/U', $html, -1, PREG_SPLIT_DELIM_CAPTURE) as $i => $e) {
      if ($e != '') {
        if ($i % 2 == 0) {
          // text node
          if ($e != '') {
            if ($node) {
              $node->childNodes[] = new DOMNode('', $node, array(), $e);
            } else {
              $this->doms[] = new DOMNode('', $node, array(), $e);
            }
          }
        } elseif ($e[0] == '/') {
          // close tag
          $node = $node->parentNode;
        } elseif (preg_match('/^([a-zA-Z0-9]+)([\s]{0,}(.*))?$/', $e, $a2)) {
          // open tag
          $tag = strtoupper($a2[1]);
          // attributes
          $attributes = array();
          if (!empty($a2[3]) && preg_match_all('/(\\w+)\s*=\\s*("[^"]*"|\'[^\']*\'|[^"\'\\s>]*)/', $a2[3], $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
              if (($match[2][0] == '"' || $match[2][0] == "'") && $match[2][0] == $match[2][strlen($match[2]) - 1]) {
                $match[2] = substr($match[2], 1, -1);
              }
              $attributes[strtoupper($match[1])] = $match[2];
            }
          }
          if ($tag == 'BR' || $tag == 'IMG' || $tag == 'INPUT' || $tag == 'HR') {
            if ($node) {
              $node->childNodes[] = new DOMNode($tag, $node, $attributes);
            } else {
              $this->doms[] = new DOMNode($tag, $node, $attributes);
            }
          } else {
            $temp = $node;
            $node = new DOMNode($tag, $temp, $attributes);
            if ($temp) {
              $temp->childNodes[] = $node;
            } else {
              $this->doms[] = $node;
            }
          }
        }
      }
    }
    // ตรวจสอบ previousSibling และ nextSibling
    $currentNode = null;
    foreach ($this->doms as $node) {
      $node->previousSibling = $currentNode;
      $this->populate($node);
      if ($node->previousSibling) {
        $node->previousSibling->nextSibling = $node;
      }
      $currentNode = $node;
    }
  }

  /**
   * ตรวจสอบ previousSibling และ nextSibling
   *
   * @param DOMNode $node
   */
  private function populate($node)
  {
    $currentNode = null;
    foreach ($node->childNodes as $item) {
      $item->previousSibling = $currentNode;
      foreach ($item->childNodes as $child) {
        $this->populate($child);
        if ($node->previousSibling) {
          $node->previousSibling->nextSibling = $node;
        }
      }
      $currentNode = $item;
    }
  }

  /**
   * parse HTML จาก URL
   *
   * @param string $url URL ที่ต้องการ parse
   * @return \static
   */
  public static function load($url)
  {
    $obj = new static(file_get_contents($url));
    return $obj;
  }

  /**
   * คืนค่า node ทั้งหมด
   *
   * @return array
   */
  public function nodes()
  {
    return $this->doms;
  }

  /**
   * ส่งออกเป็นโค้ด HTML จากที่ parse แล้ว
   *
   * @return string
   */
  public function toHTML()
  {
    $html = '';
    foreach ($this->doms as $node) {
      $html .= $this->drawNode($node);
    }
    return $html;
  }

  /**
   *
   * @param DOMNode $node
   * @return string
   */
  private function drawNode($node)
  {
    $html = '';
    if ($node->nodeName == '') {
      $html .= $node->nodeValue;
    } else {
      $prop = array();
      foreach ($node->attributes as $k => $v) {
        $prop[] = $k.'="'.$v.'"';
      }
      if ($node->nodeName == 'BR' || $node->nodeName == 'IMG') {
        $html .= '<'.$node->nodeName.' '.implode(' ', $prop).'>';
      } else {
        $prop = empty($prop) ? '' : ' '.implode(' ', $prop);
        $html .= '<'.$node->nodeName.$prop.'>';
        if (empty($node->childNodes)) {
          $html .= $node->nodeValue;
        } else {
          foreach ($node->childNodes as $child) {
            $html .= $this->drawNode($child);
          }
        }
        if ($node->previousSibling) {
          $html .= '('.$node->previousSibling->nodeName.')';
        }
        $html .= '</'.$node->nodeName.'>';
      }
    }
    return $html;
  }
}
