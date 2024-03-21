<?php
/**
 * @filesource Gcms/Router.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Gcms;

/**
 * Router Class สำหรับ GCMS
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Router extends \Kotchasan\Router
{
    /**
     * กฏของ Router สำหรับการแยกหน้าเว็บไซต์
     *
     * @var array
     */
    protected $rules = array(
        // api.php/<modules>/<method>/get/<id>
        '/api\.php\/([a-z0-9]+)\/([a-z]+)\/(get)\/([0-9]+)/i' => array('module', 'method', 'action', 'id'),
        // api.php/<modules>/<method>/search/<q>
        '/^api\.php\/([a-z0-9]+)\/([a-z]+)\/(search)\/([^\/]+)(\/([0-9]+))?$/i' => array('module', 'method', 'action', 'q', '', 'page'),
        // api.php/<modules>/<method>/<action>/<category_id>/<page>
        '/api\.php\/([a-z0-9]+)\/([a-z]+)\/([a-z]+)(\/([0-9]+))?(\/([0-9]+))?/i' => array('module', 'method', 'action', '', 'category_id', '', 'page')
    );
}
