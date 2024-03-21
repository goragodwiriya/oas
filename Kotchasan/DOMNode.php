<?php
/**
 * @filesource Kotchasan/DOMNode.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan;

/**
 * Class representing a DOM Node.
 *
 * @see https://www.kotchasan.com/
 */
class DOMNode
{
    /**
     * List of node attributes.
     *
     * @var array
     */
    public $attributes = [];

    /**
     * List of child nodes.
     * <parentNode><childNode></childNode><childNode></childNode></parentNode>
     *
     * @var array
     */
    public $childNodes;

    /**
     * Node level. The outermost level is 0.
     *
     * @var int
     */
    public $level;

    /**
     * Next sibling node. If it's the last node, it will be null.
     * <node></node><nextSibling></nextSibling>
     *
     * @var DOMNode
     */
    public $nextSibling;

    /**
     * Node name.
     *
     * @var mixed
     */
    public $nodeName;

    /**
     * Node value. It will be null if the node is a tag.
     * <node>nodeValue</node>
     *
     * @var string|null
     */
    public $nodeValue;

    /**
     * Parent node.
     * <parentNode><childNode></childNode></parentNode>
     *
     * @var DOMNode
     */
    public $parentNode;

    /**
     * Previous sibling node. If it's the first node, it will be null.
     * <previousSibling></previousSibling><node></node>
     *
     * @var DOMNode
     */
    public $previousSibling;

    /**
     * Class constructor.
     *
     * @param string       $nodeName   The tag name. If there is no tag name, it represents empty text.
     * @param DOMNode|null $parentNode The parent node. If it's the first node, it will be null.
     * @param array        $attributes The node attributes (properties).
     * @param string|null  $nodeValue  The text content of the node. It will be null if the node is a tag.
     */
    public function __construct($nodeName, $parentNode, $attributes, $nodeValue = null)
    {
        $this->nodeName = strtoupper($nodeName);
        $this->parentNode = $parentNode;
        $this->nodeValue = $nodeValue;

        // Store attributes as uppercase keys for easier access.
        foreach ($attributes as $key => $value) {
            $this->attributes[strtoupper($key)] = $value;
        }

        $this->childNodes = [];
    }

    /**
     * Check if the node has child nodes.
     *
     * @return bool True if it has child nodes, false otherwise.
     */
    public function hasChildNodes()
    {
        return !empty($this->childNodes);
    }

    /**
     * Check if the node has a specific class.
     *
     * @param string $className The class name to check.
     *
     * @return bool True if it has the class, false otherwise.
     */
    public function hasClass($className)
    {
        if (!empty($this->attributes['CLASS'])) {
            $className = strtoupper($className);
            foreach (explode(' ', strtoupper($this->attributes['CLASS'])) as $item) {
                if ($item == $className) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Check if the element is an inline element.
     *
     * @return bool True if it's an inline element, false if it's a block-level element.
     */
    public function isInlineElement()
    {
        // List of inline elements.
        $inlineElements = array(
            'B', 'BIG', 'I', 'SMALL', 'TT', 'ABBR', 'ACRONYM', 'CITE', 'CODE',
            'DFN', 'EM', 'STRONG', 'SAMP', 'TIME', 'VAR', 'A', 'BDO', 'BR', 'IMG',
            'MAP', 'OBJECT', 'Q', 'SCRIPT', 'SPAN', 'SUB', 'BUTTON', 'INPUT',
            'LABEL', 'SELECT', 'TEXTAREA'
        );

        return in_array($this->nodeName, $inlineElements);
    }

    /**
     * Get the concatenated text content of the node and its descendants.
     *
     * @return string The concatenated text content.
     */
    public function nodeText()
    {
        $txt = '';
        foreach ($this->childNodes as $node) {
            if ($node->hasChildNodes()) {
                // Recursively call nodeText() to concatenate text from child nodes.
                $txt .= $this->nodeText();
            } else {
                switch ($node->nodeName) {
                    case 'BR':
                        $txt .= "\n";
                        break;
                    case '':
                        $txt .= $node->nodeValue;
                        break;
                }
            }
        }
        return $this->unentities($txt);
    }

    /**
     * Convert HTML entities to their corresponding characters.
     *
     * @param string $html The HTML string to convert.
     *
     * @return string The converted string.
     */
    public function unentities($html)
    {
        $entities = array(
            '&nbsp;' => ' ',
            '&amp;' => '&',
            '&lt;' => '<',
            '&gt;' => '>',
            '&#39;' => "'",
            '&quot;' => '"'
        );

        return str_replace(array_keys($entities), array_values($entities), $html);
    }
}
