<?php

namespace Kotchasan\Http\Middleware;

use Kotchasan\Http\Request;

/**
 * Middleware Interface
 * Defines the contract for all middleware
 *
 * @package Kotchasan\Http\Middleware
 */
interface MiddlewareInterface
{
    /**
     * Handle the request through middleware
     *
     * @param Request $request The incoming request
     * @param callable|null $next The next middleware
     * @return mixed Response or next middleware result
     */
    public function handle(Request $request, ?callable $next = null);
}