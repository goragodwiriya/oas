<?php

namespace Kotchasan\Http\Middleware;

use Kotchasan\Http\Request;
use Kotchasan\Http\Response;

/**
 * Base Middleware Class
 * Provides common functionality for all middleware
 *
 * @package Kotchasan\Http\Middleware
 */
abstract class BaseMiddleware implements MiddlewareInterface
{
    /**
     * Handle the request through middleware
     *
     * @param Request $request The incoming request
     * @param callable|null $next The next middleware
     * @return mixed Response or next middleware result
     */
    abstract public function handle(Request $request, ?callable $next = null);

    /**
     * Call the next middleware in the chain
     *
     * @param Request $request
     * @param callable|null $next
     * @return mixed
     */
    protected function callNext(Request $request, ?callable $next = null)
    {
        return $next ? $next($request) : $request;
    }

    /**
     * Create a JSON error response
     *
     * @param int $statusCode HTTP status code
     * @param string $error Error type
     * @param string $message Error message
     * @param array $headers Additional headers
     * @return Response
     */
    protected function createErrorResponse(int $statusCode, string $error, string $message, array $headers = []): Response
    {
        $data = [
            'error' => $error,
            'message' => $message
        ];

        switch ($statusCode) {
            case 400:
                $response = Response::makeBadRequest($data);
                break;
            case 401:
                $response = Response::makeUnauthorized($data);
                break;
            case 403:
                $response = Response::makeForbidden($data);
                break;
            case 404:
                $response = Response::makeNotFound($data);
                break;
            case 500:
                $response = Response::makeServerError($data);
                break;
            default:
                $response = Response::makeJson($data, $statusCode);
                break;
        }

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}