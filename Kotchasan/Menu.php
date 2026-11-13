<?php
namespace Kotchasan;

/**
 * Kotchasan Menu Class
 *
 * This class provides methods for rendering a menu from an array of items.
 * It supports nested submenus and allows for customization of menu items.
 *
 * @package Kotchasan
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
                } elseif (isset($values['submenus']) && !empty($values['submenus'])) {
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
            // Reject dangerous URL schemes (javascript:, data:, vbscript:) and
            // HTML-encode the href to prevent attribute-context XSS.
            $url = (string) $item['url'];
            if (preg_match('/^\s*(javascript|data|vbscript)\s*:/i', $url)) {
                $url = '#';
            }
            $a = ['href="'.htmlspecialchars($url, ENT_QUOTES, 'UTF-8').'"'];
            if (!empty($item['target'])) {
                $a[] = 'target="'.htmlspecialchars((string) $item['target'], ENT_QUOTES, 'UTF-8').'"';
            }
        }
        if (!empty($item['text'])) {
            $a[] = 'title="'.htmlspecialchars((string) $item['text'], ENT_QUOTES, 'UTF-8').'"';
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
