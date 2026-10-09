/**
 * Enhanced Route Parameters Handler
 */
const RouteParamsHandler = {
  /**
   * Extract parameters from path
   */
  extractParams(path, pattern) {
    // Support both named parameters (:id) and wildcard patterns (**)
    const paramNames = [];
    const regexPattern = pattern.replace(/:(\w+)/g, (_, name) => {
      paramNames.push(name);
      return '([^/]+)';
    }).replace(/\*\*/g, () => {
      paramNames.push('wildcard');
      return '(.*)';
    });

    const matches = path.match(new RegExp(`^${regexPattern}$`));
    if (!matches) return null;

    // Create params object
    const params = {};
    matches.slice(1).forEach((value, index) => {
      params[paramNames[index]] = value;
    });

    return params;
  },

  /**
   * Validate parameter values
   */
  validateParams(params, constraints) {
    if (!constraints) return true;

    for (const [key, value] of Object.entries(params)) {
      const constraint = constraints[key];
      if (!constraint) continue;

      // Type validation
      if (constraint.type) {
        const type = typeof value;
        if (type !== constraint.type) return false;
      }

      // Pattern validation
      if (constraint.pattern) {
        const regex = new RegExp(constraint.pattern);
        if (!regex.test(value)) return false;
      }

      // Custom validation
      if (constraint.validate && !constraint.validate(value)) {
        return false;
      }
    }

    return true;
  },

  /**
   * Build path with parameters
   */
  buildPath(pattern, params) {
    return pattern.replace(/:(\w+)/g, (_, name) => {
      const value = params[name];
      if (value === undefined) {
        throw new Error(`Missing parameter: ${name}`);
      }
      return encodeURIComponent(value);
    });
  }
};

// Add to RouterManager
RouterManager.paramHandler = RouteParamsHandler;
