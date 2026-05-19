<?php

namespace Kotchasan;

/**
 * Kotchasan Controller Class
 *
 * This class serves as a base controller for the Kotchasan framework.
 *
 * @package Kotchasan
 */
class Controller extends \Kotchasan\KBase
{
    /**
     * create class
     *
     * @return static
     */
    public static function create()
    {
        return new static();
    }
}
