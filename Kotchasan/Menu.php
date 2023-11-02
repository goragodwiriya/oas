<?php
/**
 * @filesource Kotchasan/Menu.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan;

/**
 * This class is responsible for rendering the standard menu of Kotchasan.
 *
 * @see https://www.kotchasan.com/
 */
class Menu
{
    /**
     * Renders the menu.
     *
     * @param array  $items  The menu items.
     * @param string $select The selected menu item.
     * @return string The rendered menu HTML.
     */
    public static function render($items, $select)
    {
        $menus = [];
        foreach ($items as $alias => $values) {
            if (isset($values['text']) && $values['text'] !== null) {
                if (isset($values['url'])) {
                    $menus[] = self::getItem($alias, $values, false, $select).'</li>';
                } elseif (isset($values['submenus'])) {
                    $menus[] = self::getItem($alias, $values, true, $select).'<ul>';
                    $menus[] = self::render($values['submenus'], $select);
                    $menus[] = '</ul>';
                }
            }
        }
        return implode('', $menus);
    }

    /**
     * Converts an item to a menu item and returns the HTML.
     *
     * @param string|int $name   The menu name.
     * @param array      $item   The menu item data array.
     * @param bool       $arrow  True to show arrow for menus with submenus.
     * @param string     $select The selected menu name.
     * @return string The HTML of the menu item.
     */
    protected static function getItem($name, $item, $arrow, $select)
    {
        if (empty($name) && !is_int($name)) {
            $c = '';
        } else {
            $c = [$name];
            if ($name === $select) {
                $c[] = 'select';
            }
            $c = ' class="'.implode(' ', $c).'"';
        }
        if (!empty($item['url'])) {
            $a = ['href="'.$item['url'].'"'];
            if (!empty($item['target'])) {
                $a[] = 'target="'.$item['target'].'"';
            }
        }
        if (!empty($item['text'])) {
            $a[] = 'title="'.$item['text'].'"';
        }
        if ($arrow) {
            $a[] = 'class=menu-arrow';
        }
        $a = isset($a) ? ' '.implode(' ', $a) : '';
        if (empty($item['url'])) {
            return '<li'.$c.'><span '.$a.'><span>'.(empty($item['text']) ? '&nbsp;' : strip_tags(htmlspecialchars_decode($item['text']))).'</span></span>';
        } else {
            return '<li'.$c.'><a'.$a.'><span>'.(empty($item['text']) ? '&nbsp;' : strip_tags(htmlspecialchars_decode($item['text']))).'</span></a>';
        }
    }
}
