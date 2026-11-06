<?php

namespace Kotchasan\Http;

use Kotchasan\Database;
use Kotchasan\Exception\ConfigurationException;
use Kotchasan\Http\Middleware\MiddlewareInterface;

/**
 * Class Application
 *
 * Main application class for API framework.
 *
 * @package Kotchasan\Http
 */
class Application
{
    /**
     * Router instance.
     *
     * @var Router
     */
    protected Router $router;

    /**
     * Global middleware.
     *
     * @var array
     */
    protected array $middleware = [];

    /**
     * Error handlers.
     *
     * @var array
     */
    protected array $errorHandlers = [];

    /**
     * Application configuration.
     *
     * @var array
     */
    protected array $config = [];

    /**
     * Constructor.
     *
     * @param array $config Application configuration
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->router = new Router($config['basePath'] ?? '');

        // Configure database if needed
        if (isset($config['database'])) {
            Database::config($config['database']);
        }

        // Register default error handlers
        $this->registerDefaultErrorHandlers();
    }

    /**
     * Add global middleware.
     *
     * @param MiddlewareInterface|callable $middleware
     * @return self
     */
    public function addMiddleware($middleware): self
    {
        $this->middleware[] = $middleware;
        return $this;
    }

    /**
     * Add multiple global middleware.
     *
     * @param array $middleware
     * @return self
     */
    public function addMiddlewares(array $middleware): self
    {
        $this->middleware = array_merge($this->middleware, $middleware);
        return $this;
    }

    /**
     * Get the router instance.
     *
     * @return Router
     */
    public function getRouter(): Router
    {
        return $this->router;
    }

    /**
     * Register an error handler.
     *
     * @param int $code HTTP status code
     * @param callable $handler Error handler
     * @return self
     */
    public function registerErrorHandler(int $code, callable $handler): self
    {
        $this->errorHandlers[$code] = $handler;
        return $this;
    }

    /**
     * Handle the request and return a response.
     *
     * @param Request|null $request HTTP request
     * @return Response
     */
    public function handle(?Request $request = null): Response
    {
        // Create request from globals if not provided
        $request = $request ?? Request::createFromGlobals();

        try {
            // Apply global middleware
            $response = $this->applyMiddleware($request, $this->middleware);

            // If middleware returned a response, return it
            if ($response instanceof Response) {
                return $response;
            }

            // Match and dispatch route
            if (!$this->router->match($request)) {
                return $this->handleError(404, $request);
            }

            return $this->router->dispatch($request);

        } catch (\Throwable $e) {
            return $this->handleException($e, $request);
        }
    }

    /**
     * Run the application.
     *
     * @param Request|null $request HTTP request
     * @return void
     */
    public function run(?Request $request = null): void
    {
        $response = $this->handle($request);
        $response->send();
    }

    /**
     * Apply middleware to a request.
     *
     * @param Request $request HTTP request
     * @param array $middleware Middleware to apply
     * @return Response|null
     * @throws ConfigurationException
     */
    protected function applyMiddleware(Request $request, array $middleware): ?Response
    {
        foreach ($middleware as $mw) {
            if ($mw instanceof MiddlewareInterface) {
                $response = $mw->handle($request);

                if ($response instanceof Response) {
                    return $response;
                }
            } elseif (is_callable($mw)) {
                $response = $mw($request);

                if ($response instanceof Response) {
                    return $response;
                }
            } elseif (is_string($mw) && class_exists($mw)) {
                $instance = new $mw();

                if (!$instance instanceof MiddlewareInterface) {
                    throw new ConfigurationException("Middleware class '$mw' must implement MiddlewareInterface");
                }

                $response = $instance->handle($request);

                if ($response instanceof Response) {
                    return $response;
                }
            } else {
                throw new ConfigurationException("Invalid middleware");
            }
        }

        return null;
    }

    /**
     * Handle an error.
     *
     * @param int $code HTTP status code
     * @param Request $request HTTP request
     * @param string|null $message Error message
     * @return Response
     */
    protected function handleError(int $code, Request $request, ?string $message = null): Response
    {
        // Check for registered error handler
        if (isset($this->errorHandlers[$code])) {
            return call_user_func($this->errorHandlers[$code], $request, $message);
        }

        // Default error handling
        switch ($code) {
            case 404:
                return Response::makeNotFound(['error' => $message ?? 'Not Found']);

            case 401:
                return Response::makeUnauthorized(['error' => $message ?? 'Unauthorized']);

            case 403:
                return Response::makeForbidden(['error' => $message ?? 'Forbidden']);

            case 400:
                return Response::makeBadRequest(['error' => $message ?? 'Bad Request']);

            default:
                return Response::makeServerError(['error' => $message ?? 'Internal Server Error']);
        }
    }

    /**
     * Handle an exception.
     *
     * @param \Throwable $e Exception
     * @param Request $request HTTP request
     * @return Response
     */
    protected function handleException(\Throwable $e, Request $request): Response
    {
        // Log the exception
        error_log($e->getMessage()."\n".$e->getTraceAsString());

        // Development mode: include exception details
        if ($this->config['debug'] ?? false) {
            return Response::makeServerError([
                'error' => 'Internal Server Error',
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => explode("\n", $e->getTraceAsString())
            ]);
        }

        // Production mode: don't leak information
        return Response::makeServerError(['error' => 'Internal Server Error']);
    }

    /**
     * Register default error handlers.
     *
     * @return void
     */
    protected function registerDefaultErrorHandlers(): void
    {
        // Register default handlers for common HTTP status codes
        $this->registerErrorHandler(404, function (Request $request, $message = null) {
            return Response::makeNotFound(['error' => $message ?? 'Resource not found']);
        });

        $this->registerErrorHandler(401, function (Request $request, $message = null) {
            return Response::makeUnauthorized(['error' => $message ?? 'Authentication required']);
        });

        $this->registerErrorHandler(403, function (Request $request, $message = null) {
            return Response::makeForbidden(['error' => $message ?? 'Permission required']);
        });

        $this->registerErrorHandler(400, function (Request $request, $message = null) {
            return Response::makeBadRequest(['error' => $message ?? 'Invalid request']);
        });

        $this->registerErrorHandler(500, function (Request $request, $message = null) {
            return Response::makeServerError(['error' => $message ?? 'Internal server error']);
        });
    }
}
