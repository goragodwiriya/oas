<?php

namespace Kotchasan\Http\Middleware;

use Kotchasan\Http\Request;
use Kotchasan\Http\Response;

/**
 * Authorization Middleware
 * Checks if the authenticated user has the required role to access the resource
 *
 * @package Kotchasan\Http\Middleware
 */
class AuthorizationMiddleware extends BaseMiddleware
{
    /**
     * @var array List of allowed roles
     */
    private $allowedRoles = [];

    /**
     * Constructor
     *
     * @param array $allowedRoles Array of roles that are allowed access
     */
    public function __construct(array $allowedRoles)
    {
        $this->allowedRoles = $allowedRoles;
    }

    /**
     * Handle the request and check authorization
     *
     * @param Request $request The request to handle
     * @param callable|null $next The next middleware
     * @return mixed Response or next middleware result
     */
    public function handle(Request $request, ?callable $next = null)
    {
        $userRoles = $this->getUserRoles($request);

        if (empty($userRoles)) {
            return $this->createForbiddenResponse('Access denied: No role assigned');
        }

        if (!$this->hasRequiredRole($userRoles)) {
            return $this->createForbiddenResponse('Access denied: Insufficient privileges');
        }

        return $this->callNext($request, $next);
    }

    /**
     * Add a role to the allowed roles
     *
     * @param string $role Role to add
     * @return self
     */
    public function addRole(string $role): self
    {
        if (!in_array($role, $this->allowedRoles)) {
            $this->allowedRoles[] = $role;
        }
        return $this;
    }

    /**
     * Add multiple roles to the allowed roles
     *
     * @param array $roles Roles to add
     * @return self
     */
    public function addRoles(array $roles): self
    {
        foreach ($roles as $role) {
            $this->addRole($role);
        }
        return $this;
    }

    /**
     * Remove a role from the allowed roles
     *
     * @param string $role Role to remove
     * @return self
     */
    public function removeRole(string $role): self
    {
        $this->allowedRoles = array_values(array_diff($this->allowedRoles, [$role]));
        return $this;
    }

    /**
     * Remove multiple roles from the allowed roles
     *
     * @param array $roles Roles to remove
     * @return self
     */
    public function removeRoles(array $roles): self
    {
        $this->allowedRoles = array_values(array_diff($this->allowedRoles, $roles));
        return $this;
    }

    /**
     * Set allowed roles (replaces existing roles)
     *
     * @param array $roles Roles to set
     * @return self
     */
    public function setRoles(array $roles): self
    {
        $this->allowedRoles = array_unique($roles);
        return $this;
    }

    /**
     * Check if a role is allowed
     *
     * @param string $role Role to check
     * @return bool
     */
    public function hasRole(string $role): bool
    {
        return in_array($role, $this->allowedRoles);
    }

    /**
     * Get all allowed roles
     *
     * @return array Array of allowed roles
     */
    public function getAllowedRoles(): array
    {
        return $this->allowedRoles;
    }

    /**
     * Get user roles from request
     *
     * @param Request $request
     * @return array
     */
    private function getUserRoles(Request $request): array
    {
        $userRole = $request->getAttribute('user_role');

        if (empty($userRole)) {
            return [];
        }

        return is_array($userRole) ? $userRole : [$userRole];
    }

    /**
     * Check if user has any of the required roles
     *
     * @param array $userRoles
     * @return bool
     */
    private function hasRequiredRole(array $userRoles): bool
    {
        return !empty(array_intersect($userRoles, $this->allowedRoles));
    }

    /**
     * Create a forbidden response
     *
     * @param string $message Error message
     * @return Response
     */
    private function createForbiddenResponse(string $message): Response
    {
        return $this->createErrorResponse(403, 'Forbidden', $message);
    }

    // ===== Static Factory Methods =====

    /**
     * Create middleware for admin access only
     *
     * @return self
     */
    public static function adminOnly(): self
    {
        return new self(['admin']);
    }

    /**
     * Create middleware for user and admin access
     *
     * @return self
     */
    public static function userAndAdmin(): self
    {
        return new self(['user', 'admin']);
    }

    /**
     * Create middleware for specific roles
     *
     * @param string ...$roles Roles to allow
     * @return self
     */
    public static function forRoles(string ...$roles): self
    {
        return new self($roles);
    }

    /**
     * Create middleware that allows any authenticated user
     *
     * @return self
     */
    public static function authenticated(): self
    {
        return new class extends AuthorizationMiddleware {
            public function __construct()
            {
                parent::__construct([]);
            }

            public function handle(Request $request, ?callable $next = null)
            {
                $user = $request->getAttribute('authenticated_user');

                if (empty($user)) {
                    return $this->createErrorResponse(401, 'Unauthorized', 'Authentication required');
                }

                return $this->callNext($request, $next);
            }
        };
    }
}
