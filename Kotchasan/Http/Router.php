<?php

namespace Kotchasan\Http;

use Kotchasan\Exception\ConfigurationException;

/**
 * Class Router
 *
 * Routes HTTP requests to appropriate handlers.
 *
 * @package Kotchasan\Http
 */
class Router
{
    /**
     * Routes configuration.
     *
     * @var array
     */
    protected array $routes = [];

    /**
     * Base path for routes.
     *
     * @var string
     */
    protected string $basePath = '';

    /**
     * Current matched route.
     *
     * @var array|null
     */
    protected ?array $currentRoute = null;

    /**
     * Route parameters from path matching.
     *
     * @var array
     */
    protected array $params = [];

    /**
     * Constructor.
     *
     * @param string $basePath Base path for routes
     */
    public function __construct(string $basePath = '')
    {
        $this->basePath = rtrim($basePath, '/');
    }

    /**
     * Add a GET route.
     *
     * @param string $path Route path
     * @param callable|string|array $handler Route handler
     * @param array $middleware Middleware to apply
     * @return self
     */
    public function get(string $path, $handler, array $middleware = []): self
    {
        return $this->addRoute('GET', $path, $handler, $middleware);
    }

    /**
     * Add a POST route.
     *
     * @param string $path Route path
     * @param callable|string|array $handler Route handler
     * @param array $middleware Middleware to apply
     * @return self
     */
    public function post(string $path, $handler, array $middleware = []): self
    {
        return $this->addRoute('POST', $path, $handler, $middleware);
    }

    /**
     * Add a PUT route.
     *
     * @param string $path Route path
     * @param callable|string|array $handler Route handler
     * @param array $middleware Middleware to apply
     * @return self
     */
    public function put(string $path, $handler, array $middleware = []): self
    {
        return $this->addRoute('PUT', $path, $handler, $middleware);
    }

    /**
     * Add a DELETE route.
     *
     * @param string $path Route path
     * @param callable|string|array $handler Route handler
     * @param array $middleware Middleware to apply
     * @return self
     */
    public function delete(string $path, $handler, array $middleware = []): self
    {
        return $this->addRoute('DELETE', $path, $handler, $middleware);
    }

    /**
     * Add a PATCH route.
     *
     * @param string $path Route path
     * @param callable|string|array $handler Route handler
     * @param array $middleware Middleware to apply
     * @return self
     */
    public function patch(string $path, $handler, array $middleware = []): self
    {
        return $this->addRoute('PATCH', $path, $handler, $middleware);
    }

    /**
     * Add an OPTIONS route.
     *
     * @param string $path Route path
     * @param callable|string|array $handler Route handler
     * @param array $middleware Middleware to apply
     * @return self
     */
    public function options(string $path, $handler, array $middleware = []): self
    {
        return $this->addRoute('OPTIONS', $path, $handler, $middleware);
    }

    /**
     * Add a route that matches any HTTP method.
     *
     * @param string $path Route path
     * @param callable|string|array $handler Route handler
     * @param array $middleware Middleware to apply
     * @return self
     */
    public function any(string $path, $handler, array $middleware = []): self
    {
        $methods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'];
        foreach ($methods as $method) {
            $this->addRoute($method, $path, $handler, $middleware);
        }
        return $this;
    }

    /**
     * Add a route.
     *
     * @param string $method HTTP method
     * @param string $path Route path
     * @param callable|string|array $handler Route handler
     * @param array $middleware Middleware to apply
     * @return self
     */
    public function addRoute(string $method, string $path, $handler, array $middleware = []): self
    {
        $path = $this->basePath.'/'.ltrim($path, '/');

        // Convert path parameters to regex pattern
        $pattern = $this->pathToPattern($path);

        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'pattern' => $pattern,
            'handler' => $handler,
            'middleware' => $middleware
        ];

        return $this;
    }

    /**
     * Add a group of routes with a common prefix.
     *
     * @param string $prefix Group prefix
     * @param callable $callback Callback to define routes
     * @param array $middleware Middleware to apply to all routes in group
     * @return self
     */
    public function group(string $prefix, callable $callback, array $middleware = []): self
    {
        // Save current base path
        $previousBasePath = $this->basePath;

        // Update base path for the group
        $this->basePath = $previousBasePath.'/'.ltrim($prefix, '/');

        // Execute the callback to define routes in this group
        $callback($this);

        // Apply middleware to all routes in this group
        if (!empty($middleware)) {
            $count = count($this->routes);
            for ($i = 0; $i < $count; $i++) {
                if (strpos($this->routes[$i]['path'], $this->basePath) === 0) {
                    $this->routes[$i]['middleware'] = array_merge(
                        $middleware,
                        $this->routes[$i]['middleware']
                    );
                }
            }
        }

        // Restore previous base path
        $this->basePath = $previousBasePath;

        return $this;
    }

    /**
     * Match a request to a route.
     *
     * @param Request $request HTTP request
     * @return bool Whether a route was matched
     */
    public function match(Request $request): bool
    {
        $method = $request->getMethod();
        $path = parse_url($request->getUri(), PHP_URL_PATH) ?: '/';

        foreach ($this->routes as $route) {
            // Check method match
            if ($route['method'] !== $method) {
                continue;
            }

            // Check path match
            if (preg_match($route['pattern'], $path, $matches)) {
                $this->currentRoute = $route;

                // Extract params
                $this->params = [];
                foreach ($matches as $key => $value) {
                    if (is_string($key)) {
                        $this->params[$key] = $value;
                    }
                }

                return true;
            }
        }

        return false;
    }

    /**
     * Dispatch the current route.
     *
     * @param Request $request HTTP request
     * @return Response
     * @throws ConfigurationException
     */
    public function dispatch(Request $request): Response
    {
        if (!$this->currentRoute) {
            if (!$this->match($request)) {
                return Response::makeNotFound(['error' => 'Route not found']);
            }
        }

        // Apply middleware
        $response = $this->applyMiddleware($request, $this->currentRoute['middleware']);
        if ($response instanceof Response) {
            return $response;
        }

        // Execute handler
        $handler = $this->currentRoute['handler'];

        if (is_callable($handler)) {
            // Callable handler
            $result = call_user_func_array($handler, [$request, $this->params]);
        } elseif (is_string($handler) && strpos($handler, '@') !== false) {
            // Controller@method handler
            list($controller, $method) = explode('@', $handler, 2);

            if (!class_exists($controller)) {
                throw new ConfigurationException("Controller class '$controller' not found");
            }

            $instance = new $controller();

            if (!method_exists($instance, $method)) {
                throw new ConfigurationException("Method '$method' not found in controller '$controller'");
            }

            $result = call_user_func_array([$instance, $method], [$request, $this->params]);
        } elseif (is_array($handler) && count($handler) === 2) {
            // [Controller, method] handler
            list($controller, $method) = $handler;

            if (is_string($controller)) {
                if (!class_exists($controller)) {
                    throw new ConfigurationException("Controller class '$controller' not found");
                }

                $instance = new $controller();
            } else {
                $instance = $controller;
            }

            if (!method_exists($instance, $method)) {
                throw new ConfigurationException("Method '$method' not found in controller");
            }

            $result = call_user_func_array([$instance, $method], [$request, $this->params]);
        } else {
            throw new ConfigurationException("Invalid route handler");
        }

        // Convert result to Response if needed
        if ($result instanceof Response) {
            return $result;
        }

        return new Response($result);
    }

    /**
     * Apply middleware to a request.
     *
     * @param Request $request HTTP request
     * @param array $middleware Middleware to apply
     * @return Response|null Response if middleware short-circuits, null otherwise
     * @throws ConfigurationException
     */
    protected function applyMiddleware(Request $request, array $middleware): ?Response
    {
        foreach ($middleware as $mw) {
            if (is_callable($mw)) {
                $result = $mw($request);
            } elseif (is_string($mw)) {
                if (!class_exists($mw)) {
                    throw new ConfigurationException("Middleware class '$mw' not found");
                }

                $instance = new $mw();

                if (!method_exists($instance, 'handle')) {
                    throw new ConfigurationException("Method 'handle' not found in middleware '$mw'");
                }

                $result = $instance->handle($request);
            } else {
                throw new ConfigurationException("Invalid middleware");
            }

            // If middleware returns a Response, short-circuit
            if ($result instanceof Response) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Convert a path with parameters to a regex pattern.
     *
     * @param string $path Route path
     * @return string Regex pattern
     */
    protected function pathToPattern(string $path): string
    {
        // Replace named parameters with regex patterns
        $pattern = preg_replace('/{([a-zA-Z0-9_]+)}/', '(?<$1>[^/]+)', $path);

        // Replace optional parameters
        $pattern = preg_replace('/{([a-zA-Z0-9_]+)\?}/', '(?<$1>[^/]*)?', $pattern);

        // Replace wildcard pattern
        $pattern = str_replace('*', '.*', $pattern);

        // Escape forward slashes
        $pattern = str_replace('/', '\/', $pattern);

        return '/^'.$pattern.'$/';
    }

    /**
     * Get route parameters.
     *
     * @return array
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * Get a specific route parameter.
     *
     * @param string $name Parameter name
     * @param mixed $default Default value if parameter does not exist
     * @return mixed
     */
    public function getParam(string $name, $default = null)
    {
        return $this->params[$name] ?? $default;
    }

    /**
     * Get all routes.
     *
     * @return array
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    /**
     * Get current route.
     *
     * @return array|null
     */
    public function getCurrentRoute(): ?array
    {
        return $this->currentRoute;
    }
}
