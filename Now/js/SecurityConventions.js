/**
 * SecurityConventions - Convention-based security rules management system
 */
const SecurityConventions = {
  /**
   * Default security conventions
   */
  defaultConventions: {
    // Authentication patterns
    '/auth/*': {
      public: true,
      guestOnly: true,
      description: 'Authentication pages - public access only for guests'
    },
    '/login': {
      public: true,
      guestOnly: true,
      description: 'Login page'
    },
    '/register': {
      public: true,
      guestOnly: true,
      description: 'Registration page'
    },
    '/forgot-password': {
      public: true,
      guestOnly: true,
      description: 'Password reset page'
    },
    '/reset-password': {
      public: true,
      guestOnly: true,
      description: 'Password reset confirmation'
    },
    '/logout': {
      requiresAuth: true,
      description: 'Logout endpoint'
    },

    // Admin area
    '/admin/*': {
      requiresAuth: true,
      roles: ['admin', 'super_admin'],
      description: 'Administrative area'
    },
    '/admin/users/*': {
      requiresAuth: true,
      roles: ['admin', 'super_admin'],
      permissions: ['user.manage'],
      description: 'User management'
    },
    '/admin/settings/*': {
      requiresAuth: true,
      roles: ['super_admin'],
      description: 'System settings'
    },

    // User areas
    '/profile': {
      requiresAuth: true,
      description: 'User profile'
    },
    '/profile/*': {
      requiresAuth: true,
      description: 'User profile sections'
    },
    '/account/*': {
      requiresAuth: true,
      description: 'Account management'
    },
    '/dashboard': {
      requiresAuth: true,
      description: 'User dashboard'
    },
    '/dashboard/*': {
      requiresAuth: true,
      description: 'Dashboard sections'
    },

    // API routes (usually skip router)
    '/api/*': {
      skipRouter: true,
      description: 'API endpoints'
    },

    // Static assets
    '/assets/*': {
      public: true,
      skipRouter: true,
      description: 'Static assets'
    },
    '/static/*': {
      public: true,
      skipRouter: true,
      description: 'Static files'
    },
    '/uploads/*': {
      public: true,
      description: 'User uploads'
    },

    // Public pages
    '/': {
      public: true,
      description: 'Homepage'
    },
    '/home': {
      public: true,
      description: 'Homepage'
    },
    '/about': {
      public: true,
      description: 'About page'
    },
    '/contact': {
      public: true,
      description: 'Contact page'
    },
    '/privacy': {
      public: true,
      description: 'Privacy policy'
    },
    '/terms': {
      public: true,
      description: 'Terms of service'
    },

    // Error pages
    '/404': {
      public: true,
      description: 'Page not found'
    },
    '/403': {
      public: true,
      description: 'Access forbidden'
    },
    '/500': {
      public: true,
      description: 'Server error'
    }
  },

  /**
   * Apply conventions to route path
   */
  applyConventions(path, existingMetadata = {}, customConventions = {}) {
    const conventions = {...this.defaultConventions, ...customConventions};
    const result = {...existingMetadata};

    // Find matching conventions (order matters - more specific first)
    const matches = this.findMatchingConventions(path, conventions);

    // Apply conventions in order of specificity
    for (const match of matches) {
      this.mergeConvention(result, match.convention, match.specificity);
    }

    return result;
  },

  /**
   * Find all matching conventions for a path
   */
  findMatchingConventions(path, conventions) {
    const matches = [];

    for (const [pattern, convention] of Object.entries(conventions)) {
      if (this.matchesPattern(path, pattern)) {
        const specificity = this.calculateSpecificity(pattern);
        matches.push({
          pattern,
          convention,
          specificity
        });
      }
    }

    // Sort by specificity (most specific first)
    return matches.sort((a, b) => b.specificity - a.specificity);
  },

  /**
   * Calculate pattern specificity for proper ordering
   */
  calculateSpecificity(pattern) {
    let specificity = 0;

    // Exact match gets highest priority
    if (!pattern.includes('*')) {
      specificity += 1000;
    }

    // Longer patterns are more specific
    specificity += pattern.length;

    // Fewer wildcards are more specific
    const wildcardCount = (pattern.match(/\*/g) || []).length;
    specificity -= wildcardCount * 100;

    // Path depth adds specificity
    const depth = pattern.split('/').length;
    specificity += depth * 10;

    return specificity;
  },

  /**
   * Check if path matches pattern
   */
  matchesPattern(path, pattern) {
    // Handle exact matches
    if (!pattern.includes('*')) {
      return path === pattern;
    }

    // Convert pattern to regex
    const regexPattern = pattern
      .replace(/\./g, '\\.')
      .replace(/\*/g, '.*')
      .replace(/\//g, '\\/');

    const regex = new RegExp(`^${regexPattern}$`);
    return regex.test(path);
  },

  /**
   * Merge convention into existing metadata
   */
  mergeConvention(target, convention, specificity) {
    // Skip certain properties
    if (convention.description) {
      target._conventionDescription = convention.description;
    }

    // Merge security properties
    for (const [key, value] of Object.entries(convention)) {
      if (key === 'description') continue;

      switch (key) {
        case 'roles':
        case 'permissions':
          // Merge arrays
          if (!target[key]) target[key] = [];
          if (Array.isArray(value)) {
            target[key] = [...new Set([...target[key], ...value])];
          } else if (value) {
            target[key] = [...new Set([...target[key], value])];
          }
          break;

        case 'requiresAuth':
        case 'public':
        case 'guestOnly':
        case 'skipRouter':
          // Boolean properties - later conventions override
          if (target[key] === undefined) {
            target[key] = value;
          }
          break;

        default:
          // Other properties
          if (target[key] === undefined) {
            target[key] = value;
          }
          break;
      }
    }

    // Track applied conventions for debugging
    if (!target._appliedConventions) {
      target._appliedConventions = [];
    }
    target._appliedConventions.push({
      pattern: convention.pattern,
      specificity,
      description: convention.description
    });
  },

  /**
   * Generate security report for all routes
   */
  generateSecurityReport(routes, conventions = {}) {
    const report = {
      totalRoutes: routes.size,
      securitySummary: {
        public: 0,
        requiresAuth: 0,
        guestOnly: 0,
        hasRoles: 0,
        hasPermissions: 0,
        skipRouter: 0
      },
      routes: [],
      conventions: Object.keys({...this.defaultConventions, ...conventions}),
      issues: []
    };

    for (const [path, route] of routes) {
      const metadata = route.metadata || {};
      const appliedConventions = this.applyConventions(path, {}, conventions);

      const routeInfo = {
        path,
        security: {
          public: metadata.public || appliedConventions.public || false,
          requiresAuth: metadata.requiresAuth !== undefined ?
            metadata.requiresAuth :
            appliedConventions.requiresAuth || false,
          guestOnly: metadata.guestOnly || appliedConventions.guestOnly || false,
          roles: [...(metadata.roles || []), ...(appliedConventions.roles || [])],
          permissions: [...(metadata.permissions || []), ...(appliedConventions.permissions || [])],
          skipRouter: metadata.skipRouter || appliedConventions.skipRouter || false
        },
        appliedConventions: appliedConventions._appliedConventions || [],
        hasCustomSecurity: this.hasCustomSecurity(metadata)
      };

      // Update summary
      if (routeInfo.security.public) report.securitySummary.public++;
      if (routeInfo.security.requiresAuth) report.securitySummary.requiresAuth++;
      if (routeInfo.security.guestOnly) report.securitySummary.guestOnly++;
      if (routeInfo.security.roles.length > 0) report.securitySummary.hasRoles++;
      if (routeInfo.security.permissions.length > 0) report.securitySummary.hasPermissions++;
      if (routeInfo.security.skipRouter) report.securitySummary.skipRouter++;

      // Check for security issues
      const issues = this.checkSecurityIssues(routeInfo);
      if (issues.length > 0) {
        report.issues.push({
          path,
          issues
        });
      }

      report.routes.push(routeInfo);
    }

    return report;
  },

  /**
   * Check if route has custom security settings
   */
  hasCustomSecurity(metadata) {
    const securityProps = ['requiresAuth', 'public', 'guestOnly', 'roles', 'permissions'];
    return securityProps.some(prop => metadata[prop] !== undefined);
  },

  /**
   * Check for potential security issues
   */
  checkSecurityIssues(routeInfo) {
    const issues = [];
    const {security} = routeInfo;

    // Conflicting settings
    if (security.public && security.requiresAuth) {
      issues.push({
        type: 'conflict',
        message: 'Route is marked as both public and requiring authentication'
      });
    }

    if (security.guestOnly && security.requiresAuth) {
      issues.push({
        type: 'conflict',
        message: 'Route is marked as guest-only but requires authentication'
      });
    }

    if (security.guestOnly && (security.roles.length > 0 || security.permissions.length > 0)) {
      issues.push({
        type: 'conflict',
        message: 'Guest-only route should not have role or permission requirements'
      });
    }

    // Potential security holes
    if (!security.public && !security.requiresAuth &&
      security.roles.length === 0 && security.permissions.length === 0) {
      issues.push({
        type: 'warning',
        message: 'Route has no explicit security configuration'
      });
    }

    // Admin routes without proper protection
    if (routeInfo.path.includes('/admin') && !security.roles.includes('admin')) {
      issues.push({
        type: 'warning',
        message: 'Admin route without admin role requirement'
      });
    }

    return issues;
  },

  /**
   * Get convention suggestions for a path
   */
  suggestConventions(path) {
    const suggestions = [];

    // Auth pages
    if (/\/(login|register|forgot|reset|auth)/i.test(path)) {
      suggestions.push({
        type: 'auth',
        suggestion: 'Consider making this a guest-only public route',
        convention: {public: true, guestOnly: true}
      });
    }

    // Admin pages
    if (/\/admin/i.test(path)) {
      suggestions.push({
        type: 'admin',
        suggestion: 'Admin routes should require authentication and admin role',
        convention: {requiresAuth: true, roles: ['admin']}
      });
    }

    // User profile/account pages
    if (/\/(profile|account|dashboard)/i.test(path)) {
      suggestions.push({
        type: 'user',
        suggestion: 'User area routes should require authentication',
        convention: {requiresAuth: true}
      });
    }

    // API routes
    if (/\/api\//i.test(path)) {
      suggestions.push({
        type: 'api',
        suggestion: 'API routes should typically skip router handling',
        convention: {skipRouter: true}
      });
    }

    return suggestions;
  },

  /**
   * Validate convention configuration
   */
  validateConventions(conventions) {
    const errors = [];
    const warnings = [];

    for (const [pattern, convention] of Object.entries(conventions)) {
      // Check pattern validity
      if (!this.isValidPattern(pattern)) {
        errors.push(`Invalid pattern: ${pattern}`);
        continue;
      }

      // Check for conflicting rules
      if (convention.public && convention.requiresAuth) {
        errors.push(`Pattern ${pattern}: Cannot be both public and require auth`);
      }

      if (convention.guestOnly && convention.requiresAuth) {
        errors.push(`Pattern ${pattern}: Cannot be guest-only and require auth`);
      }

      if (convention.guestOnly && (convention.roles || convention.permissions)) {
        errors.push(`Pattern ${pattern}: Guest-only routes cannot have role/permission requirements`);
      }

      // Check for redundant patterns
      const similarPatterns = this.findSimilarPatterns(pattern, conventions);
      if (similarPatterns.length > 0) {
        warnings.push(`Pattern ${pattern} may conflict with: ${similarPatterns.join(', ')}`);
      }
    }

    return {errors, warnings};
  },

  /**
   * Check if pattern is valid
   */
  isValidPattern(pattern) {
    // Basic validation
    if (typeof pattern !== 'string' || pattern.length === 0) {
      return false;
    }

    // Must start with /
    if (!pattern.startsWith('/')) {
      return false;
    }

    // Check for invalid characters
    if (/[<>"|\\]/.test(pattern)) {
      return false;
    }

    return true;
  },

  /**
   * Find similar patterns that might conflict
   */
  findSimilarPatterns(pattern, conventions) {
    const similar = [];

    for (const otherPattern of Object.keys(conventions)) {
      if (otherPattern === pattern) continue;

      // Check if patterns overlap
      if (this.patternsOverlap(pattern, otherPattern)) {
        similar.push(otherPattern);
      }
    }

    return similar;
  },

  /**
   * Check if two patterns overlap
   */
  patternsOverlap(pattern1, pattern2) {
    // Simple overlap detection
    const normalized1 = pattern1.replace(/\*/g, '');
    const normalized2 = pattern2.replace(/\*/g, '');

    return normalized1.includes(normalized2) || normalized2.includes(normalized1);
  }
};

window.SecurityConventions = SecurityConventions;
