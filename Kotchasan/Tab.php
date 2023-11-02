<?php
/**
 * @filesource Kotchasan/Tab.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan;

/**
 * Tab class to handle the creation and rendering of tabs.
 *
 * @see https://www.kotchasan.com/
 */
class Tab
{
    /**
     * @var array $datas Array of tab items
     */
    private $datas;

    /**
     * @var string $id Tab ID (must be unique)
     */
    private $id;

    /**
     * @var string $select Selected tab ID
     */
    private $select;

    /**
     * @var array $urls Array of URL components
     */
    private $urls;

    /**
     * Constructor
     *
     * @param string $id    Tab ID (must be unique)
     * @param string $url   URL of the current page (used as the default URL for the menu)
     * @param array  $items Array of initial tab items
     */
    public function __construct($id, $url, $items = [])
    {
        $this->id = $id;
        $this->urls = explode('?', $url);
        if (count($this->urls) == 1) {
            $this->urls[1] = '';
        } else {
            $this->urls[1] = str_replace(['&', '&amp;amp;'], '&amp;', $this->urls[1]);
        }
        $this->datas = empty($items) ? [] : $items;
    }

    /**
     * Add a tab item
     *
     * @param string      $id     Tab ID (used for selection)
     * @param string      $title  Text of the tab menu
     * @param string|null $url    URL to be opened when the tab is clicked
     * @param string|null $target Target attribute for the tab link
     */
    public function add($id, $title, $url = '', $target = null)
    {
        $this->datas[] = [
            'title' => $title,
            'url' => $url,
            'id' => $id,
            'target' => $target
        ];
    }

    /**
     * Get the ID of the selected tab
     *
     * @return string
     */
    public function getSelect()
    {
        return $this->select;
    }

    /**
     * Generate the HTML code for rendering the tabs
     *
     * @param string $select ID of the selected tab (if empty, the first item will be selected)
     *
     * @return string
     */
    public function render($select = '')
    {
        $html = '<div class="inline"><div class="writetab"><ul id="'.$this->id.'">';
        foreach ($this->datas as $i => $item) {
            $prop = [];
            if (empty($item['url'])) {
                if (isset($item['id'])) {
                    if ($this->urls[1] === '') {
                        $prop[] = 'href="'.$this->urls[0].'?tab='.$item['id'].'"';
                    } else {
                        $prop[] = 'href="'.$this->urls[0].'?'.$this->urls[1].'&amp;tab='.$item['id'].'"';
                    }
                    $prop[] = 'id="tab_'.$item['id'].'"';
                }
            } else {
                $prop[] = 'href="'.$item['url'].'"';
            }
            if (!empty($item['target'])) {
                $prop[] = 'target="'.$item['target'].'"';
            }
            if ($select === $item['id'] || ($i === 0 && $select === '')) {
                $sel = ' class=select';
                $this->select = $item['id'];
            } else {
                $sel = '';
            }
            $html .= '<li'.$sel.'><a '.implode(' ', $prop).'>'.$item['title'].'</a></li>';
        }
        return $html.'</ul></div></div>';
    }
}
