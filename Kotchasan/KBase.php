<?php

namespace Kotchasan;

/**
 * Kotchasan KBase Class
 *
 * This class serves as a base class for Kotchasan applications.
 * It provides properties for configuration and request handling.
 *
 * @package Kotchasan
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
