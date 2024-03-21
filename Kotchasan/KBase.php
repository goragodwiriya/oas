<?php
/**
 * @filesource Kotchasan/KBase.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 * @author Goragod Wiriya <admin@goragod.com>
 * @package Kotchasan
 */

namespace Kotchasan;

/**
 * The base class for Kotchasan framework.
 *
 * @see https://www.kotchasan.com/
 */
#[\AllowDynamicProperties]
class KBase
{
    /**
     * Config class instance.
     *
     * @var object
     */
    protected static $cfg;

    /**
     * Server request class instance.
     *
     * @var \Kotchasan\Http\Request
     */
    protected static $request;
}
