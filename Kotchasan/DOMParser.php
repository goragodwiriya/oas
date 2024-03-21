<?php
/**
 * @filesource Kotchasan/DOMParser.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan;

/**
 * Class for DOM Parsing.
 *
 * @see https://www.kotchasan.com/
 */
class DOMParser
{
    /**
     * Array of nodes.
     *
     * @var array
     */
    protected $doms = [];

    /**
     * Class constructor.
     *
     * @param string $html    HTML code.
     * @param string $charset Encoding (default utf-8).
     */
    public function __construct($html, $charset = 'utf-8')
    {
        // Remove unnecessary elements and normalize the HTML code.
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

        // Convert the HTML code to the specified charset.
        if (strtolower($charset) != 'utf-8') {
            $html = iconv('utf-8', $charset, $html);
        }

        $node = null;
        foreach (preg_split('/<(.*)>/U', $html, -1, PREG_SPLIT_DELIM_CAPTURE) as $i => $e) {
            if ($e != '') {
                if ($i % 2 == 0) {
                    // Text node
                    if ($e != '') {
                        if ($node) {
                            $node->childNodes[] = new DOMNode('', $node, [], $e);
                        } else {
                            $this->doms[] = new DOMNode('', $node, [], $e);
                        }
                    }
                } elseif ($e[0] == '/') {
                    // Close tag
                    $node = $node->parentNode;
                } elseif (preg_match('/^([a-zA-Z0-9]+)([\s]{0,}(.*))?$/', $e, $a2)) {
                    // Open tag
                    $tag = strtoupper($a2[1]);
                    // Attributes
                    $attributes = [];
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

        // Check previousSibling and nextSibling for each node.
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
     * Parse HTML from URL.
     *
     * @param string $url URL to parse.
     *
     * @return static
     */
    public static function load($url)
    {
        $obj = new static(file_get_contents($url));
        return $obj;
    }

    /**
     * Get all nodes.
     *
     * @return array
     */
    public function nodes()
    {
        return $this->doms;
    }

    /**
     * Export the parsed HTML code as a string.
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
     * Draw the node and its descendants recursively.
     *
     * @param DOMNode $node The node to draw.
     *
     * @return string The HTML representation of the node.
     */
    private function drawNode($node)
    {
        $html = '';
        if ($node->nodeName == '') {
            $html .= $node->nodeValue;
        } else {
            $prop = [];
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

    /**
     * Populate previousSibling and nextSibling for each node recursively.
     *
     * @param DOMNode $node The node to populate.
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
}
