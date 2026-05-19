/**
 * Route Groups Handler
 */
const RouteGroupsHandler = {
  /**
   * Create route group
   */
  group(prefix, options = {}) {
    return {
      prefix,
      routes: [],
      add(path, config) {
        // Merge group options with route config
        const fullConfig = {
          ...options,
          ...config,
          path: prefix + path,
          guards: [...(options.guards || []), ...(config.guards || [])]
        };

        this.routes.push(fullConfig);
        return this;
      },
      nested(childPrefix, childOptions = {}) {
        return this.add(childPrefix, {
          ...childOptions,
          children: []
        });
      }
    };
  },

  /**
   * Register group routes
   */
  registerGroup(group) {
    group.routes.forEach(route => {
      this.register(route.path, route);
    });
  },

  /**
   * Handle nested routes
   */
  async handleNestedRoutes(route, params) {
    if (!route.children) return;

    let currentRoute = route;
    let remainingPath = this.getPath();

    while (currentRoute.children) {
      const childPath = remainingPath.slice(currentRoute.path.length);
      
      // Find matching child route
      const child = currentRoute.children.find(r => 
        this.matchRoute(childPath, r.path)
      );

      if (!child) break;

      // Merge child params
      const childParams = this.paramHandler.extractParams(
        childPath, 
        child.path
      );
      Object.assign(params, childParams);

      currentRoute = child;
    }

    return currentRoute;
  }
};

// Add to RouterManager 
Object.assign(RouterManager, RouteGroupsHandler);
