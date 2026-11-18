/**
 * Route Metadata Handler - Manage route metadata and conventions
 */
const RouteMetadataHandler = {
  /**
   * Extract metadata from route configuration
   */
  extractMetadata(config, path, conventions = {}) {
    const metadata = {
      // Basic metadata extraction (existing code)
      requiresAuth: config.requiresAuth !== undefined ? config.requiresAuth : null,
      public: config.public || false,
      guestOnly: config.guestOnly || false,
      roles: Array.isArray(config.roles) ? config.roles :
        (config.roles ? [config.roles] : []),
      permissions: Array.isArray(config.permissions) ? config.permissions :
        (config.permissions ? [config.permissions] : []),
      beforeEnter: config.beforeEnter || null,
      beforeLeave: config.beforeLeave || null,
      onUnauthorized: config.onUnauthorized || null,
      onForbidden: config.onForbidden || null,
      validate: config.validate || null,
      redirectOnAuth: config.redirectOnAuth || null,
      preserveQuery: config.preserveQuery !== false,
      showLoading: config.showLoading !== false,
      loadingMessage: config.loadingMessage || null
    };

    // Apply security conventions if path is provided
    if (path) {
      const conventionMetadata = SecurityConventions.applyConventions(
        path,
        metadata,
        conventions
      );
      return conventionMetadata;
    }

    return metadata;
  },

  /**
   * Apply conventions based on route path
   */
  applyConventions(path, metadata, conventions) {
    if (!conventions) return metadata;

    const applied = {...metadata};

    // Check each convention pattern
    for (const [pattern, rules] of Object.entries(conventions)) {
      if (this.matchesPattern(path, pattern)) {
        // Merge convention rules
        Object.assign(applied, rules);

        // Handle arrays properly
        if (rules.roles) {
          applied.roles = [...(applied.roles || []), ...rules.roles];
        }
        if (rules.permissions) {
          applied.permissions = [...(applied.permissions || []), ...rules.permissions];
        }
      }
    }

    return applied;
  },

  /**
   * Apply conventions with detailed logging
   */
  applyConventionsWithLogging(path, metadata, conventions) {
    const result = SecurityConventions.applyConventions(path, metadata, conventions);

    // Add debugging information
    result._conventionDebug = {
      originalMetadata: {...metadata},
      appliedConventions: result._appliedConventions || [],
      finalResult: {
        requiresAuth: result.requiresAuth,
        public: result.public,
        guestOnly: result.guestOnly,
        roles: result.roles,
        permissions: result.permissions
      }
    };

    return result;
  },

  /**
   * Check if path matches pattern
   */
  matchesPattern(path, pattern) {
    // Convert pattern to regex
    const regexPattern = pattern
      .replace(/\*/g, '.*')
      .replace(/\//g, '\\/')
      .replace(/\?/g, '\\?');

    const regex = new RegExp(`^${regexPattern}$`);
    return regex.test(path);
  },

  /**
   * Validate metadata configuration
   */
  validateMetadata(metadata, path) {
    const errors = [];

    // Check for conflicting settings
    if (metadata.public && metadata.requiresAuth) {
      errors.push(`Route ${path}: Cannot be both public and require authentication`);
    }

    if (metadata.guestOnly && metadata.requiresAuth) {
      errors.push(`Route ${path}: Cannot be guest-only and require authentication`);
    }

    if (metadata.guestOnly && (metadata.roles.length > 0 || metadata.permissions.length > 0)) {
      errors.push(`Route ${path}: Guest-only routes cannot have role/permission requirements`);
    }

    // Validate function types
    if (metadata.beforeEnter && typeof metadata.beforeEnter !== 'function') {
      errors.push(`Route ${path}: beforeEnter must be a function`);
    }

    if (metadata.beforeLeave && typeof metadata.beforeLeave !== 'function') {
      errors.push(`Route ${path}: beforeLeave must be a function`);
    }

    if (metadata.validate && typeof metadata.validate !== 'function') {
      errors.push(`Route ${path}: validate must be a function`);
    }

    return errors;
  },

  /**
   * Determine authentication requirement based on metadata
   */
  getAuthRequirement(metadata, defaultRequireAuth = false) {
    // Explicit settings take priority
    if (metadata.public) return false;
    if (metadata.guestOnly) return false;
    if (metadata.requiresAuth !== null) return metadata.requiresAuth;

    // Role/permission requirements imply auth requirement
    if (metadata.roles.length > 0 || metadata.permissions.length > 0) {
      return true;
    }

    // Use default setting
    return defaultRequireAuth;
  },

  /**
   * Create route summary for debugging
   */
  createSummary(path, metadata) {
    const authReq = this.getAuthRequirement(metadata);

    return {
      path,
      requiresAuth: authReq,
      public: metadata.public,
      guestOnly: metadata.guestOnly,
      roles: metadata.roles,
      permissions: metadata.permissions,
      hasGuards: !!(metadata.beforeEnter || metadata.beforeLeave),
      hasValidation: !!metadata.validate
    };
  }
};

window.RouteMetadataHandler = RouteMetadataHandler;