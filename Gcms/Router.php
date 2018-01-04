<?php
/**
 * @filesource Gcms/Login.php
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
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
    // api.php/products/<category_id>/<page>
    '/api\.php\/(products)\/([0-9]+)\/([0-9]+)/i' => array('action', 'category_id', 'page'),
    // api.php/products/<page>
    '/api\.php\/(products)\/([0-9]+)/i' => array('action', 'page'),
    // api.php/search/<q>/<page>
    '/api\.php\/(search)\/([^\/]+|$)(\/([0-9]+))?/i' => array('action', 'q', '', 'page'),
    // api.php/<action>/<id>
    '/api\.php\/([a-z]+)(\/([0-9]+))?/i' => array('action', '', 'id'),
  );
}