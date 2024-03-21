<?php
/**
 * @filesource Kotchasan/Accordion.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan;

/**
 * Accordion class for creating HTML accordions.
 *
 * @see https://www.kotchasan.com/
 */
class Accordion
{
    /**
     * Array of accordion items.
     *
     * @var mixed
     */
    private $datas;

    /**
     * ID of the accordion. Must be unique.
     *
     * @var mixed
     */
    private $id;

    /**
     * Type of accordion (radio or checkbox).
     *
     * @var mixed
     */
    private $type;

    /**
     * Constructor.
     *
     * @param string $id     ID of the accordion (must be unique).
     * @param array  $items  Initial items array (array(array('title1' => 'detail1'), array('title2' => 'detail2'))).
     * @param bool   $onetab true to allow opening only one tab at a time, false (default) to allow opening and closing tabs independently.
     */
    public function __construct($id, $items = [], $onetab = false)
    {
        $this->id = $id;
        $this->datas = empty($items) ? [] : $items;
        $this->type = $onetab ? 'radio' : 'checkbox';
    }

    /**
     * Add an item to the accordion.
     *
     * @param string $title      Title of the item.
     * @param string $detail     Details of the item.
     * @param bool   $select     true to display this item, false (default) otherwise.
     * @param string $className  CSS class for the item's content container. Use "article" as default if not specified.
     */
    public function add($title, $detail, $select = false, $className = 'article')
    {
        $this->datas[$title] = array(
            'detail' => $detail,
            'select' => $select,
            'className' => $className
        );
    }

    /**
     * Generate the HTML code for the accordion.
     *
     * @return string HTML code for the accordion.
     */
    public function render()
    {
        $html = '<div class="accordion">';
        $n = 1;
        foreach ($this->datas as $title => $item) {
            $html .= '<div class="item">';
            $html .= '<input id="'.$this->id.$n.'" name="'.$this->id.'" type="'.$this->type.'"'.($item['select'] ? ' checked' : '').'>';
            $html .= '<label for="'.$this->id.$n.'">'.$title.'</label>';
            $html .= '<div class="body"><div class="'.$item['className'].'">'.$item['detail'].'</div></div>';
            $html .= '</div>';
            ++$n;
        }
        return $html.'</div>';
    }
}
