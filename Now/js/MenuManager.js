const MenuManager = {
  config: {
    breakpoint: 768,
    animationDuration: 100,
    touchThreshold: 100,

    accessibility: {
      enabled: true,
      announceChanges: true,
      smoothFocus: true,
      focusableSelectors: 'a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])',
      focusFirstItemOnOpen: true,
      returnFocusOnClose: true,
      horizontalNavigation: {
        arrowKeysOpenSubmenu: true,
        arrowKeysNavigateSiblings: true
      }
    },

    performance: {
      useRequestAnimationFrame: true,
      touchOptimization: true,
    },

    observerOptions: {
      childList: true,
      subtree: true,
      attributes: true,
      attributeFilter: ['class', 'style']
    },

    hover: {
      openDelay: 100,
      closeDelay: 300
    }
  },

  state: {
    initialized: false,
    menus: new Map(),
    handlers: new Map(),
    observer: null,
    announcer: null
  },

  /**
   * Initializes the manager: mounts the menus already in the document, starts
   * the DOM observer, the toggle delegation, the API data and i18n listeners,
   * and hooks route changes to refresh the active item and close open menus.
   *
   * Calling it again once initialized is a no-op.
   *
   * @param {Object} [options={}] - Configuration merged over the defaults
   * @returns {Promise<Object>} The manager instance
   * @throws {Error} When initialization fails
   */
  async init(options = {}) {
    try {
      if (this.state.initialized) return this;

      this.config = Now.mergeConfig(this.config, options);

      this.state = {
        initialized: false,
        menus: new Map(),
        handlers: new Map(),
        observer: null,
        announcer: null
      };

      this.initializeExistingMenus();
      this.setupMutationObserver();
      this.setupToggleListeners();
      this.setupApiDataListener();
      this.setupI18nListeners();

      EventManager.on('route:changed', () => {
        try {
          // Clean up stale menus whose elements are no longer in the DOM
          this.state.menus.forEach((menu, id) => {
            if (!menu.element.isConnected) {
              this.destroyMenu(id);
            }
          });

          this.updateActiveMenu();

          // Close all submenus and mobile menus on route change
          this.state.menus.forEach(menu => {
            // Close all submenus first (clears sidemenu-sub-expanded)
            this.closeAllSubmenus(menu);

            // Close mobile menus
            if (menu.isMobile && this.isMenuOpen(menu)) {
              this.closeMenu(menu);
            }
          });
        } catch (error) {
          ErrorManager.handle('Route change handler failed', {
            context: 'MenuManager.routeHandler',
            data: {error}
          })
        }
      });

      this.state.initialized = true;
      return this;
    } catch (error) {
      throw ErrorManager.handle('MenuManager initialization failed', {
        context: 'MenuManager.init',
        data: {error}
      })
    }
  },

  /**
   * Setup i18n listeners for automatic translation updates
   * Menus will automatically re-translate when:
   * - Translations are loaded for the first time
   * - User changes locale
   */
  setupI18nListeners() {
    // Listen for locale changes
    EventManager.on('locale:changed', () => {
      this.updateAllMenuTranslations();
    });
  },

  /**
   * Update translations for all menu text elements
   * This translates static text in toggle buttons and menu labels
   */
  updateAllMenuTranslations() {
    this.state.menus.forEach(menu => {
      // Update toggle button labels
      if (menu.elements.toggleButtons) {
        const isOpen = this.isMenuOpen(menu);
        menu.elements.toggleButtons.forEach(toggle => {
          const label = isOpen ? 'Close menu' : 'Open menu';
          toggle.setAttribute('aria-label', I18nManager.translate(label));
        });
      }

      // Update submenu labels
      if (menu.elements.submenus) {
        menu.elements.submenus.forEach(submenu => {
          if (submenu.content) {
            submenu.content.setAttribute('aria-label', I18nManager.translate('Submenu'));
          }
        });
      }

      // Update container label
      if (menu.elements.container) {
        menu.elements.container.setAttribute('aria-label', I18nManager.translate('Main navigation'));
      }

      // Translate menu item text with data-i18n attributes
      if (menu.element) {
        I18nManager.updateElements(menu.element);
      }
    });
  },

  /**
   * Setup listener for API data to render menus with data-source attribute
   * Menus with data-component="menu" and data-source="path.to.menus" will auto-render
   */
  setupApiDataListener() {
    // Guard against double-attachment: this runs eagerly at script-load time
    // (see bottom of file) AND from init(), because ApiComponent auto-fetches
    // (autoload: true) the moment the bundle parses -- before Now.init() ever
    // reaches MenuManager.init(). A fast/cached response can dispatch
    // api:loaded before init() would have attached this listener, and the
    // event is a one-shot CustomEvent with no replay, so it would be lost.
    if (this._apiDataListenerAttached) return;
    this._apiDataListenerAttached = true;

    // Listen for api:loaded events from ApiComponent
    document.addEventListener('api:loaded', (e) => {
      // Use e.detail.element as fallback when event is dispatched directly on document
      // (happens when API element is disconnected from DOM during SPA navigation)
      const apiElement = e.target === document ? e.detail?.element : e.target;
      const actualData = e.detail?.data;

      if (!apiElement || !actualData) return;

      // Wrap data as {data: actualData} for consistency with TemplateManager
      // This allows using data.menus format which matches data-text="data.xxx"
      const data = {data: actualData};

      // Use requestAnimationFrame to ensure DOM is ready
      requestAnimationFrame(() => {
        // Find menu elements with data-source inside this API container
        const menuElements = apiElement.querySelectorAll('[data-component="menu"][data-source]');

        menuElements.forEach(menuEl => {
          const sourcePath = menuEl.dataset.source;
          if (!sourcePath) return;

          // Get data from stored data using path (e.g., "data.menus")
          const menuData = this.getValueByPath(data, sourcePath);

          if (menuData && Array.isArray(menuData)) {
            // Always re-render - destroy existing menu first if it exists
            if (menuEl.dataset.menuId) {
              this.destroyMenu(menuEl.dataset.menuId);
              delete menuEl.dataset.menuId;
            }
            this.renderFromData(menuEl, menuData);
          }
        });
      });
    });
  },

  /**
   * Get value from object by dot-notation path
   * @param {Object} obj - Source object
   * @param {string} path - Dot-notation path
   * @returns {*} Value at path
   */
  getValueByPath(obj, path) {
    if (!path || !obj) return undefined;
    return path.split('.').reduce((o, p) => o?.[p], obj);
  },

  /**
   * Check if a menu is open by reading DOM state (single source of truth)
   * @param {Object} menu - Menu instance
   * @returns {boolean}
   */
  isMenuOpen(menu) {
    if (menu.element.classList.contains('sidemenu')) {
      return document.body.classList.contains('sidemenu-close');
    }
    return document.body.classList.contains('topmenu-open');
  },

  /**
   * Check if a submenu button is open by reading aria-expanded (single source of truth)
   * @param {HTMLElement} button - Submenu toggle button
   * @returns {boolean}
   */
  isSubmenuOpen(button) {
    return button.getAttribute('aria-expanded') === 'true';
  },

  /**
   * Creates a menu instance for every [data-component="menu"] element in the
   * document, then refreshes the active item.
   *
   * Submenus are opened by click (vertical menus: sidemenu, and topmenu on
   * mobile) or by the hover handlers setupHoverHandlers() binds on the <li>
   * of a desktop topmenu. Nothing binds hover on the toggle button itself:
   * the button's mouseleave fires as soon as the pointer moves down into the
   * submenu it just opened, which closed the submenu before its items could
   * be clicked and made a click on the button toggle an already-hover-opened
   * submenu shut.
   *
   * @returns {void}
   */
  initializeExistingMenus() {
    // Only initialize elements explicitly marked as menu components
    document.querySelectorAll('[data-component="menu"]').forEach(element => {
      this.createMenu(element);
    });
    this.updateActiveMenu();
  },

  /**
   * Watches the document for menu components being added or removed, mounting
   * the new ones and destroying the instances whose elements left the DOM.
   *
   * @returns {void}
   */
  setupMutationObserver() {
    // Observer only watches for menu components, not toggle buttons
    this.observer = new MutationObserver((mutations) => {
      let hasMenuComponent = false;

      mutations.forEach((mutation) => {
        // Handle removed nodes
        mutation.removedNodes.forEach(node => {
          if (node.nodeType === 1) {
            // Check if the node itself is a menu
            const menuId = node.dataset?.menuId;
            if (menuId && this.state.menus.has(menuId)) {
              setTimeout(() => {
                if (!node.isConnected) {
                  this.destroyMenu(menuId);
                }
              }, 0);
            }

            // Also check for menu elements nested inside the removed node
            // Only search if there are menus to clean up
            if (node.querySelectorAll && this.state.menus.size > 0) {
              const nestedMenus = node.querySelectorAll('[data-menu-id]');
              nestedMenus.forEach(menuEl => {
                const nestedMenuId = menuEl.dataset?.menuId;
                if (nestedMenuId && this.state.menus.has(nestedMenuId)) {
                  setTimeout(() => {
                    if (!menuEl.isConnected) {
                      this.destroyMenu(nestedMenuId);
                    }
                  }, 0);
                }
              });
            }
          }
        });

        // Check for new menu components
        mutation.addedNodes.forEach(node => {
          if (node.nodeType !== 1) return;
          if (!hasMenuComponent) {
            if (node.matches?.('[data-component="menu"]') ||
              node.querySelector?.('[data-component="menu"]')) {
              hasMenuComponent = true;
            }
          }
        });
      });

      if (hasMenuComponent) {
        this.initializeExistingMenus();
      }
    });

    this.observer.observe(document.body, this.config.observerOptions);
  },

  /**
   * Setup event delegation for toggle buttons
   * This handles all toggle buttons regardless of when they're added to DOM
   */
  setupToggleListeners() {
    // Use event delegation - listen once at document level
    document.addEventListener('click', (e) => {
      const toggle = e.target.closest('.menu-toggle');
      if (!toggle) return;

      // Prevent default button behavior
      e.stopPropagation();

      // Determine which menu type this toggle controls
      let menuLevel = null;
      if (toggle.classList.contains('topmenu-toggle')) {
        menuLevel = 'topmenu';
      } else if (toggle.classList.contains('sidemenu-toggle')) {
        menuLevel = 'sidemenu';
      }

      if (!menuLevel) return;

      // Clean up stale (disconnected) menus and toggle the first connected match
      let toggled = false;
      this.state.menus.forEach((menu, id) => {
        // Remove orphaned menus whose element is no longer in the DOM
        if (!menu.element.isConnected) {
          this.destroyMenu(id);
          return;
        }
        if (toggled) return;
        const isMatch = (menuLevel === 'topmenu' && menu.element.classList.contains('topmenu')) ||
          (menuLevel === 'sidemenu' && menu.element.classList.contains('sidemenu'));
        if (isMatch) {
          toggled = true;
          this.toggleMenu(menu);
        }
      });
    });
  },

  /**
   * Marks the menu links matching the current location as active and expands
   * their ancestors, emitting menu:activeChanged when the selection moved.
   *
   * The path comes from the argument, then the menuPath of the current route,
   * then the address bar; the router base path is stripped from it.
   *
   * @param {string} [path] - Path to match against; detected when omitted
   * @returns {Element[]} Links that are now active
   */
  updateActiveMenu(path) {
    // If path not specified, check for menuPath in current route config, then fall back to pathname
    const currentRoute = window.RouterManager?.state?.current;
    let currentPath = path || currentRoute?.menuPath || (window.location.pathname + window.location.search);
    const currentHash = window.location.hash;

    // Strip base path from current path if RouterManager is available
    const basePath = window.RouterManager?.config?.base || '';
    if (basePath && basePath !== '/' && currentPath.startsWith(basePath)) {
      currentPath = currentPath.slice(basePath.length) || '/';
    }

    // Keep the original state for comparison
    const previousActive = document.querySelectorAll('.sidemenu .active, .topmenu .active');
    let activeChanged = false;

    const utils = {
      cleanUrl: (url) => {
        let clean = url.split('?')[0].split('#')[0];
        clean = clean.replace(/\/+$/, '');
        return clean;
      },

      comparePaths: (linkPath, currentPath) => {
        // If the link has a query string, do a query-aware comparison
        if (linkPath.includes('?')) {
          const cleanLinkPath = utils.cleanUrl(linkPath);
          const cleanCurrentPath = utils.cleanUrl(currentPath);
          if (cleanLinkPath !== cleanCurrentPath) return false;
          // Compare query params (order-independent)
          const linkQuery = new URLSearchParams(linkPath.split('?')[1] || '');
          const currentQuery = new URLSearchParams((currentPath.split('?')[1]) || '');
          const sortedLink = new URLSearchParams([...linkQuery.entries()].sort()).toString();
          const sortedCurrent = new URLSearchParams([...currentQuery.entries()].sort()).toString();
          return sortedLink === sortedCurrent;
        }
        const cleanLinkPath = utils.cleanUrl(linkPath);
        const cleanCurrentPath = utils.cleanUrl(currentPath);
        return cleanLinkPath === cleanCurrentPath;
      },

      setActiveState: (link, isActive = true) => {
        const wasActive = link.classList.contains('active');

        if (isActive) {
          if (!wasActive) activeChanged = true;
          link.classList.add('active');
          link.setAttribute('aria-current', 'page');

          //Open Parent Submenus
          let parent = link.parentElement;
          while (parent && parent !== document.body) {
            if (parent.tagName === 'UL') {
              // Expand the submenu
              parent.setAttribute('aria-hidden', 'false');

              // If this is a sidemenu submenu, add sidemenu-sub-expanded
              const sidemenu = parent.closest('.sidemenu');
              if (sidemenu && parent !== sidemenu.querySelector(':scope > ul')) {
                document.body.classList.add('sidemenu-sub-expanded');
              }

              // If this is a topmenu submenu, add topmenu-sub-expanded
              const topmenu = parent.closest('.topmenu');
              if (topmenu && parent !== topmenu.querySelector(':scope > ul')) {
                document.body.classList.add('topmenu-sub-expanded');
              }

              // If this is a submenu, find its toggle button
              const toggle = parent.toggle || parent.parentElement?.querySelector(':scope > button[aria-haspopup="true"]');
              if (toggle) {
                toggle.setAttribute('aria-expanded', 'true');
              }
            }
            parent = parent.parentElement;
          }
        } else {
          if (wasActive) activeChanged = true;
          link.classList.remove('active');
          link.removeAttribute('aria-current');
        }

        return isActive;
      }
    };

    // Wash all the original Active State
    previousActive.forEach(link => {
      utils.setActiveState(link, false);
    });

    // Server-rendered menus emit absolute URLs (`http://site/download`) while
    // currentPath is a pathname, so compare same-origin links by their path.
    // Links to another origin keep their raw href and never match.
    // A fragment-only href keeps its raw form: resolving it would yield the
    // current pathname and mark every in-page anchor active.
    const pathOf = (link, href) => !href.startsWith('#') && link.origin === window.location.origin
      ? link.pathname + link.search
      : href;

    // Find a links that match the current path.
    const activeLinks = [];
    document.querySelectorAll('.sidemenu a[href], .topmenu a[href]').forEach(link => {
      const href = link.getAttribute('href');
      if (!href) return;

      // Check the hash first.
      if (currentHash && href.includes('#')) {
        const linkHash = href.split('#')[1];
        const currentHashValue = currentHash.substring(1);
        if (linkHash === currentHashValue) {
          if (utils.setActiveState(link)) {
            activeLinks.push(link);
          }
          return;
        }
      }

      // Check the path
      if (utils.comparePaths(pathOf(link, href), currentPath)) {
        if (utils.setActiveState(link)) {
          activeLinks.push(link);
        }
      }
    });

    // If no exact match is found, find the best match
    if (activeLinks.length === 0) {
      let bestMatch = null;
      let bestMatchLength = 0;

      document.querySelectorAll('.sidemenu a[href], .topmenu a[href]').forEach(link => {
        const href = link.getAttribute('href');
        if (!href) return;

        const cleanHref = utils.cleanUrl(pathOf(link, href));
        const cleanCurrentPath = utils.cleanUrl(currentPath);

        if (cleanCurrentPath.startsWith(cleanHref) && cleanHref.length > bestMatchLength) {
          bestMatch = link;
          bestMatchLength = cleanHref.length;
        }
      });

      if (bestMatch) {
        utils.setActiveState(bestMatch);
        activeLinks.push(bestMatch);
      }
    }

    // Notify Event when there is a change.
    if (activeChanged) {
      Now.emit('menu:activeChanged', {
        path: currentPath,
        hash: currentHash,
        activeLinks,
        previousActive: Array.from(previousActive)
      });
    }

    return activeLinks;
  },

  /**
   * Builds a menu instance for an element: assigns its id, resolves its parts,
   * applies the ARIA structure, binds the handlers and sets the responsive
   * state.
   *
   * Elements already mounted, and data-source elements whose items have not
   * arrived yet, are skipped.
   *
   * @param {HTMLElement} element - Element carrying data-component="menu"
   * @returns {Promise<Object|null>} The instance, or null when skipped or failed
   */
  async createMenu(element) {
    try {
      if (element.dataset.menuId) return null;

      // Skip elements with data-source that don't have content yet
      // These will be rendered by setupApiDataListener when data arrives
      if (element.dataset.source && !element.querySelector('ul')) {
        return null;
      }

      // Also skip if no ul exists at all (empty menu)
      if (!element.querySelector('ul')) {
        return null;
      }

      const menuId = Utils.generateUUID();
      element.dataset.menuId = menuId;
      const menu = this.initializeMenuInstance(element, menuId);
      this.setupMenuElements(menu);

      // Only continue if container was found
      if (menu.elements.container) {
        this.setupAriaForNestedMenu(menu, menu.elements.container);
        this.setupMenuHandlers(menu);
        this.setupAccessibility(menu);
        this.checkResponsiveState(menu, true);
      }

      this.state.menus.set(menuId, menu);

      return menu;

    } catch (error) {
      this.handleError('Menu creation failed', error, 'error:menu');
      return null;
    }
  },

  /**
   * Creates the instance record of a menu, reading its side and whether it is
   * a sidebar from the classes of the element.
   *
   * @param {HTMLElement} element - Menu element
   * @param {string} menuId - Generated id stored in data-menu-id
   * @returns {Object} Instance record, before its parts are resolved
   */
  initializeMenuInstance(element, menuId) {
    return {
      id: menuId,
      element: element,
      isMobile: false,
      position: element.classList.contains('menu-left') ? 'left' : 'right',
      isSidebar: element.classList.contains('sidemenu'),
      touchData: null,
      elements: {},
      handlers: {}
    };
  },

  /**
   * Resolves the parts of a menu: its list container, its toggle buttons and
   * its backdrop, creating the toggle for a responsive topmenu when the page
   * supplies none.
   *
   * Leaves elements.container null when the element holds no list.
   *
   * @param {Object} menu - Menu instance
   * @returns {void}
   */
  setupMenuElements(menu) {
    const container = menu.element.querySelector('ul');
    const menuParent = menu.element.parentNode;

    // Check if container exists
    if (!container) {
      menu.elements.container = null;
      menu.elements.submenus = [];
      return;
    }

    menu.elements.container = container;
    menu.elements.submenus = [];
    container.setAttribute('role', 'menubar');

    let menuLevel = null;
    if (menu.element.classList.contains('topmenu')) {
      menuLevel = menu.element.classList.contains('responsive-menu') ? 'topmenu' : null;
    } else if (menu.element.classList.contains('sidemenu')) {
      menuLevel = 'sidemenu';
    }

    if (menuLevel) {
      container.setAttribute('aria-label', I18nManager.translate('Main navigation'));

      const nav = document.querySelector(`.${menuLevel}`);
      if (nav) {
        // Find toggle buttons for ARIA setup only (event handling via delegation)
        let toggleButtons = document.querySelectorAll(`.${menuLevel}-toggle`);

        // If no toggle buttons found and it's topmenu, create one
        if (toggleButtons.length === 0 && menuLevel === 'topmenu') {
          const close = document.createElement('button');
          close.type = 'button';
          close.className = `menu-toggle ${menuLevel}-toggle runtime`;
          close.innerHTML = `
          <span class="toggle-icon">
            <span class="toggle-bar"></span>
            <span class="toggle-bar"></span>
            <span class="toggle-bar"></span>
          </span>`;
          container.parentElement.parentElement.insertBefore(close, menu.element);
          toggleButtons = [close];
        }

        // Set ARIA attributes for all toggle buttons
        if (toggleButtons.length > 0) {
          menu.elements.toggleButtons = Array.from(toggleButtons);

          const isOpen = this.isMenuOpen(menu);

          toggleButtons.forEach(toggle => {
            toggle.setAttribute('aria-haspopup', 'true');
            toggle.setAttribute('aria-controls', menu.element.id || `menu-${menu.id}`);
            toggle.setAttribute('role', 'menuitem');
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            toggle.setAttribute('aria-label', I18nManager.translate(isOpen ? 'Close menu' : 'Open menu'));
          });

          // Keep first one as primary toggle for ARIA updates
          menu.elements.toggle = toggleButtons[0];
        }
      }

      const backdrop = document.createElement('div');
      backdrop.className = 'menu-backdrop';
      menuParent.insertBefore(backdrop, menu.element);
      menu.elements.backdrop = backdrop;
    }

    if (!menu.element.id) {
      menu.element.id = `menu-${menu.id}`;
    }
  },

  /**
   * Applies the ARIA menu structure to a list and its nested lists, linking
   * each toggle button to the submenu it controls and collecting the buttons
   * into elements.submenus.
   *
   * @param {Object} menu - Menu instance
   * @param {HTMLElement} ul - List to process
   * @returns {void}
   */
  setupAriaForNestedMenu(menu, ul) {
    const menuItems = ul.querySelectorAll(':scope > li');
    menuItems.forEach((menuItem) => {
      menuItem.setAttribute('role', 'none');

      const submenu = menuItem.querySelector(':scope > ul');
      if (submenu) {
        const menuId = submenu.id || `submenu-${Utils.generateUUID()}`;
        submenu.id = menuId;
        submenu.setAttribute('role', 'menu');
        submenu.setAttribute('aria-label', I18nManager.translate('Submenu'));
        submenu.setAttribute('aria-orientation', 'vertical');
        submenu.setAttribute('aria-hidden', 'true');

        const button = menuItem.querySelector('button');
        if (button) {
          button.setAttribute('role', 'menuitem');
          button.setAttribute('aria-haspopup', 'true');
          button.setAttribute('aria-expanded', 'false');
          button.setAttribute('aria-controls', menuId);
          button.content = submenu;
          menu.elements.submenus.push(button);
          submenu.toggle = button;
        }

        this.setupAriaForNestedMenu(menu, submenu);
      } else {
        const link = menuItem.querySelector('a');
        if (link) {
          link.setAttribute('role', 'menuitem');
        }
      }
    });
  },

  /**
   * Shifts a submenu back inside the viewport when it would overflow to the
   * right or below.
   *
   * @param {HTMLElement} submenu - Submenu element to reposition
   * @returns {void}
   */
  checkAndAdjustPosition(submenu) {
    const rect = submenu.getBoundingClientRect();
    const viewportWidth = window.innerWidth;
    const viewportHeight = window.innerHeight;

    if (rect.right > viewportWidth) {
      submenu.style.left = 'auto';
      submenu.style.right = '100%';
    } else {
      submenu.style.left = '';
      submenu.style.right = '';
    }

    if (rect.bottom > viewportHeight) {
      const overflowAmount = rect.bottom - viewportHeight;
      submenu.style.top = `${-overflowAmount}px`;
    } else {
      submenu.style.top = '';
    }
  },

  /**
   * Binds the handlers of a menu instance: backdrop, keyboard navigation,
   * resize and focus handling. Toggle buttons are handled by delegation in
   * setupToggleListeners().
   *
   * @param {Object} menu - Menu instance
   * @returns {void}
   */
  setupMenuHandlers(menu) {
    // Use event delegation: attach a small set of handlers on the menu root
    try {
      menu.handlers.toggle = (e) => {
        e.stopPropagation();
        this.toggleMenu(menu);
      };

      if (menu.elements) {
        // Toggle buttons use event delegation in setupToggleListeners()
        // Only backdrop needs direct binding here
        if (menu.elements.backdrop) {
          menu.elements.backdrop.addEventListener('click', menu.handlers.toggle);
        }

        menu.elements.submenus.forEach(submenu => {
          submenu.addEventListener('click', (e) => {
            const btn = e.target.closest('button');
            if (btn && btn.getAttribute('aria-haspopup') === 'true') {
              e.preventDefault();
              e.stopPropagation();
              if (menu.elements.container.getAttribute('aria-orientation') === 'vertical') {
                this.toggleSubmenu(menu, submenu, false);
              }
            }
          });
        });
      }

      const touchHandlers = {
        start: (e) => {
          menu.touchData = {
            startX: e.touches[0].clientX,
            startY: e.touches[0].clientY,
            startTime: Date.now(),
            isScrolling: null
          };
        },

        move: (e) => {
          if (!menu.touchData || !this.isMenuOpen(menu)) return;

          const touchData = menu.touchData;
          const touch = e.touches[0];
          const deltaX = touch.clientX - touchData.startX;
          const deltaY = touch.clientY - touchData.startY;

          if (touchData.isScrolling === null) {
            touchData.isScrolling = Math.abs(deltaY) > Math.abs(deltaX);
          }

          if (touchData.isScrolling) return;

          if ((menu.position === 'right' && deltaX > 0) ||
            (menu.position === 'left' && deltaX < 0)) {
            requestAnimationFrame(() => {
              menu.element.style.transform = `translateX(${deltaX}px)`;
            });
          }
        },

        end: (e) => {
          if (!menu.touchData) return;

          const touchData = menu.touchData;
          const touch = e.changedTouches[0];
          const deltaX = touch.clientX - touchData.startX;
          const deltaTime = Date.now() - touchData.startTime;
          const velocity = Math.abs(deltaX) / deltaTime;

          menu.element.style.transform = '';

          if (!touchData.isScrolling &&
            (Math.abs(deltaX) > this.config.touchThreshold ||
              velocity > 0.3)) {
            this.closeMenu(menu);
          }

          menu.touchData = null;
        },

        cancel: () => {
          if (menu.touchData) {
            menu.element.style.transform = '';
            menu.touchData = null;
          }
        }
      };

      menu.element.addEventListener('touchstart', touchHandlers.start, {
        passive: true
      });

      menu.element.addEventListener('touchmove', touchHandlers.move, {
        passive: true
      });

      menu.element.addEventListener('touchend', touchHandlers.end, {
        passive: true
      });

      menu.element.addEventListener('touchcancel', touchHandlers.cancel, {
        passive: true
      });

      menu.handlers.touch = touchHandlers;

      menu.handlers.keydown = (e) => this.handleKeyboardNavigation(menu, e);
      document.addEventListener('keydown', menu.handlers.keydown);

      if (!menu.isMobile && menu.element.classList.contains('topmenu')) {
        this.setupHoverHandlers(menu);
      }

      menu.handlers.resize = () => this.checkResponsiveState(menu);
      window.addEventListener('resize', menu.handlers.resize);

      if (menu.element.classList.contains('topmenu')) {
        menu.handlers.focusout = (e) => {
          requestAnimationFrame(() => {
            const currentFocus = document.activeElement;
            const currentTopLi = currentFocus?.closest('.topmenu > ul > li');
            const originalTopLi = e.target?.closest('.topmenu > ul > li');
            if (!currentTopLi || currentTopLi !== originalTopLi) {
              this.closeAllSubmenus(menu);
            }
          });
        };

        menu.element.addEventListener('focusout', menu.handlers.focusout);
      }
    } catch (error) {
      ErrorManager.handle('Failed to setup menu handlers', {
        context: 'MenuManager.setupMenuHandlers',
        data: {
          menuId: menu.id,
          error
        },
      });
    }
  },

  /**
   * Opens the menu when it is closed, closes it when it is open.
   *
   * @param {Object} menu - Menu instance
   * @returns {void}
   */
  toggleMenu(menu) {
    const isOpen = this.isMenuOpen(menu);
    if (isOpen) {
      this.closeMenu(menu);
    } else {
      this.openMenu(menu);
    }
  },

  /**
   * Opens a menu: collapses its submenus first when it is a sidemenu, makes its
   * items focusable, updates the toggle ARIA state, announces the item count,
   * and emits menu:opened.
   *
   * @param {Object} menu - Menu instance
   * @returns {Promise<void>}
   */
  async openMenu(menu) {
    if (this.isMenuOpen(menu)) {
      return;
    }

    // Close expanded submenus before collapsing the sidemenu
    if (menu.element.classList.contains('sidemenu') && menu.elements) {
      const openSubs = menu.elements.submenus.filter(s => this.isSubmenuOpen(s));
      for (const submenu of openSubs) {
        await this.closeSubmenu(menu, submenu);
      }
    }

    const focusableElements = menu.element.querySelectorAll(this.config.accessibility.focusableSelectors);
    focusableElements.forEach(el => {
      el.removeAttribute('tabindex');
    });

    if (menu.element.classList.contains('sidemenu')) {
      document.body.classList.add('sidemenu-close');
    } else {
      document.body.classList.add('topmenu-open');
    }
    if (menu.elements?.toggle) {
      menu.elements.toggle.setAttribute('aria-expanded', 'true');
      menu.elements.toggle.setAttribute('aria-label', I18nManager.translate('Close menu'));
    }

    if (this.config.accessibility.announceChanges) {
      const topLevelItems = menu.element.querySelector(':scope > ul')
        ?.querySelectorAll(':scope > li > [role="menuitem"]').length || 0;
      this.announce('{Open menu}, There are {count} items in total', {count: topLevelItems});
    }

    if (menu.touchData) {
      menu.touchData = null;
      menu.element.style.transform = '';
    }

    EventManager.emit('menu:opened', {menu});
  },

  /**
   * Closes a menu: collapses its submenus, takes its items out of the tab
   * order, returns focus to the toggle, and emits menu:closed.
   *
   * In mobile-menu mode the submenu state is left alone, since the menu is only
   * hidden rather than reset.
   *
   * @param {Object} menu - Menu instance
   * @returns {Promise<void>}
   */
  async closeMenu(menu) {
    if (!this.isMenuOpen(menu)) {
      return;
    }

    // Only close submenus when NOT in mobile-menu mode
    // In mobile-menu mode, we just hide/show without changing submenu state
    const isMobileMenuMode = document.body.classList.contains('mobile-menu');

    if (menu.elements && !isMobileMenuMode) {
      for (const submenu of menu.elements.submenus) {
        await this.closeSubmenu(menu, submenu);
      }
    }

    if (menu.element && !isMobileMenuMode) {
      const focusableElements = menu.element.querySelectorAll(this.config.accessibility.focusableSelectors);
      focusableElements.forEach(el => {
        el.setAttribute('tabindex', '-1');
      });
    }

    if (menu.elements?.toggle) {
      menu.elements.toggle.focus();
    }

    await new Promise(resolve => requestAnimationFrame(resolve));

    if (menu.element?.classList.contains('sidemenu')) {
      document.body.classList.remove('sidemenu-close');
    } else if (menu.element?.classList.contains('topmenu')) {
      document.body.classList.remove('topmenu-open');
    }

    if (menu.elements?.toggle) {
      menu.elements.toggle.setAttribute('aria-expanded', 'false');
      menu.elements.toggle.setAttribute('aria-label', I18nManager.translate('Open menu'));
    }

    if (menu.touchData) {
      menu.element.style.transform = '';
      menu.touchData = null;
    }

    if (this.config.accessibility.announceChanges) {
      this.announce('Close menu');
    }

    Now.emit('menu:closed', {menu});
  },

  /**
   * Opens the submenu when it is closed, closes it when it is open.
   *
   * @param {Object} menu - Menu instance
   * @param {HTMLElement} submenu - Toggle button of the submenu
   * @returns {void}
   */
  toggleSubmenu(menu, submenu) {
    if (this.isSubmenuOpen(submenu)) {
      this.closeSubmenu(menu, submenu);
    } else {
      this.openSubmenu(menu, submenu);
    }
  },

  /**
   * Opens a submenu, closing its siblings at the same level first.
   *
   * A collapsed sidemenu is expanded temporarily and marked so closeSubmenu()
   * can collapse it back. The submenu is repositioned to stay in the viewport,
   * and focus moves into it when the request came from the keyboard.
   *
   * @param {Object} menu - Menu instance
   * @param {HTMLElement} button - Toggle button whose content is the submenu
   * @param {Object} [options={}] - Open options
   * @param {boolean} [options.useKeyboard] - Move focus to the first item
   * @returns {Promise<void>}
   */
  async openSubmenu(menu, button, options = {}) {
    if (!button.content) return;

    const currentLevel = button.parentElement.parentElement;
    menu.elements.submenus.forEach(other => {
      if (other !== button && other.parentElement.parentElement === currentLevel) {
        this.closeSubmenu(menu, other);
      }
    });

    // State is set via aria-expanded attribute below
    button.setAttribute('aria-expanded', 'true');
    button.content.setAttribute('aria-hidden', 'false');

    if (menu.element.classList.contains('sidemenu')) {
      // Only temporarily expand sidemenu when NOT in mobile-menu mode
      // On mobile, sidemenu-close keeps the menu visible, so we must not remove it
      const isMobileMenuMode = document.body.classList.contains('mobile-menu');

      if (!isMobileMenuMode && document.body.classList.contains('sidemenu-close')) {
        document.body.classList.remove('sidemenu-close');
        // Mark that we temporarily expanded it, so we can collapse it back later
        button.dataset.tempExpanded = 'true';
      }
      document.body.classList.add('sidemenu-sub-expanded');
    } else if (menu.element.classList.contains('topmenu')) {
      document.body.classList.add('topmenu-sub-expanded');
    }

    const focusableElements = button.content.querySelectorAll(this.config.accessibility.focusableSelectors);
    focusableElements.forEach(el => {
      el.removeAttribute('tabindex');
    });

    if (!menu.isMobile) {
      requestAnimationFrame(() => {
        this.checkAndAdjustPosition(button.content);
      });
    }

    if (options.useKeyboard) {
      const firstItem = button.content.querySelector('[role="menuitem"]');
      if (firstItem) {
        await new Promise(resolve => setTimeout(resolve, 50));
        firstItem.focus();
      }
    }

    if (this.config.accessibility.announceChanges) {
      const submenuItems = button.content.querySelectorAll(':scope > li > [role="menuitem"]').length;
      this.announce('{Open submenu}, There are {count} items in total', {count: submenuItems});
    }

    Now.emit('submenu:opened', {
      menu,
      button,
      submenu: button.content,
      previousFocus: document.activeElement
    });
  },

  /**
   * Closes a submenu and everything nested inside it.
   *
   * Focus is moved back to the button before aria-hidden is set, so the browser
   * never reports focus inside a hidden subtree, and a sidemenu that was only
   * expanded to show this submenu is collapsed again.
   *
   * @param {Object} menu - Menu instance
   * @param {HTMLElement} button - Toggle button whose content is the submenu
   * @returns {Promise<void>}
   */
  async closeSubmenu(menu, button) {
    if (!button.content) return;

    const currentFocus = document.activeElement;
    let shouldFocusButton = false;

    const childSubmenus = button.content.querySelectorAll('[aria-expanded="true"]');
    for (const child of childSubmenus) {
      await this.closeSubmenu(menu, child);
    }

    const focusableElements = button.content.querySelectorAll(this.config.accessibility.focusableSelectors);
    focusableElements.forEach(el => {
      el.setAttribute('tabindex', '-1');
    });

    // Move focus to button BEFORE hiding to avoid aria-hidden focus warning
    if (currentFocus && button.content.contains(currentFocus)) {
      shouldFocusButton = true;
      // Move focus immediately before any state changes
      button.focus();
      // Wait for focus to actually move before setting aria-hidden
      await new Promise(resolve => requestAnimationFrame(resolve));
    }

    // State is set via aria-expanded attribute above
    button.setAttribute('aria-expanded', 'false');
    button.content.setAttribute('aria-hidden', 'true');

    // If this submenu caused a temporary expansion, check if we should collapse it back
    if (button.dataset.tempExpanded === 'true') {
      // Check if any other submenus are still open
      const hasOtherOpenSubmenus = menu.elements.submenus.some(submenu => this.isSubmenuOpen(submenu));
      if (!hasOtherOpenSubmenus) {
        document.body.classList.add('sidemenu-close');
      }
      // Clean up the dataset attribute
      delete button.dataset.tempExpanded;
    }

    // Check if any other submenus are open before removing the class
    const hasOpenSubmenu = menu.elements.submenus.some(submenu => this.isSubmenuOpen(submenu));
    if (!hasOpenSubmenu) {
      if (menu.element.classList.contains('sidemenu')) {
        document.body.classList.remove('sidemenu-sub-expanded');
      } else if (menu.element.classList.contains('topmenu')) {
        document.body.classList.remove('topmenu-sub-expanded');
      }
    }


    // Focus already moved before aria-hidden was set

    if (!menu.isMobile) {
      button.content.style.left = '';
      button.content.style.right = '';
      button.content.style.top = '';
    }

    if (this.config.accessibility.announceChanges) {
      this.announce('Close submenu');
    }

    Now.emit('submenu:closed', {
      menu,
      button,
      submenu: button.content,
      currentFocus
    });
  },

  /**
   * Closes every expanded submenu of a menu.
   *
   * @param {Object} menu - Menu instance
   * @returns {void}
   */
  closeAllSubmenus(menu) {
    menu.elements?.submenus.forEach(submenu => {
      if (submenu.getAttribute('aria-expanded') === 'true') {
        this.closeSubmenu(menu, submenu);
      }
    });
  },

  /**
   * Reports whether a menu instance is registered under the id.
   *
   * @param {string} menuId - Menu id from data-menu-id
   * @returns {boolean} True when the instance exists
   */
  hasMenu(menuId) {
    return this.state.menus.has(menuId);
  },

  /**
   * Returns a menu instance by id.
   *
   * @param {string} menuId - Menu id from data-menu-id
   * @returns {Object|null} Instance, or null when absent
   */
  getMenu(menuId) {
    return this.state.menus.get(menuId) || null;
  },

  /**
   * Adds the accessibility scaffolding of a menu: its live region, the
   * navigation role and label, and the screen-reader keyboard hint.
   *
   * @param {Object} menu - Menu instance
   * @returns {void}
   */
  setupAccessibility(menu) {
    const liveRegion = document.createElement('div');
    liveRegion.setAttribute('aria-live', 'polite');
    liveRegion.setAttribute('aria-atomic', 'true');
    liveRegion.className = 'sr-only';
    menu.element.appendChild(liveRegion);
    menu.liveRegion = liveRegion;

    menu.element.setAttribute('role', 'navigation');
    menu.element.setAttribute('aria-label', I18nManager.translate('Main navigation'));

    const keyboardHint = document.createElement('div');
    keyboardHint.className = 'sr-only';
    keyboardHint.textContent = I18nManager.translate('Keyboard navigation instructions');
    menu.element.insertBefore(keyboardHint, menu.element.firstChild);
  },

  /**
   * Binds hover opening and closing for the submenus of a desktop topmenu, each
   * delayed by the configured hover interval so a pointer passing over an item
   * does not open it.
   *
   * @param {Object} menu - Menu instance
   * @returns {void}
   */
  setupHoverHandlers(menu) {
    menu.hoverTimeouts = new Map();

    menu.elements.submenus.forEach(submenu => {

      const mouseEnterHandler = () => {
        const menuItem = submenu.closest('li');

        if (!menu.isMobile && menu.element.classList.contains('topmenu') && !this.isSubmenuOpen(submenu) && menuItem) {
          if (menu.hoverTimeouts?.has(submenu)) {
            clearTimeout(menu.hoverTimeouts.get(submenu));
          }

          menu.hoverTimeouts.set(submenu, setTimeout(() => {
            this.closeOtherSubmenus(menu, submenu);
            this.openSubmenu(menu, submenu);
          }, this.config.hover.openDelay));
        }
      };

      const mouseLeaveHandler = (e) => {
        if (!submenu.isConnected) return;

        if (menu.hoverTimeouts?.has(submenu)) {
          clearTimeout(menu.hoverTimeouts.get(submenu));
        }

        menu.hoverTimeouts?.set(submenu, setTimeout(() => {
          if (submenu.isConnected &&
            !menu.isMobile &&
            menu.element.classList.contains('topmenu')) {
            this.closeSubmenu(menu, submenu);
          }
        }, this.config.hover.closeDelay));
      };

      const menuItem = submenu.closest('li');
      menuItem.addEventListener('mouseenter', mouseEnterHandler);
      menuItem.addEventListener('mouseleave', mouseLeaveHandler);

      submenu.hoverHandlers = {
        enter: mouseEnterHandler,
        leave: mouseLeaveHandler
      };
    });
  },

  /**
   * Applies the viewport breakpoint to a menu: sets the list orientation,
   * repositions its submenus, closes a desktop topmenu that was open on mobile,
   * and binds or removes the hover handlers.
   *
   * @param {Object} menu - Menu instance
   * @param {boolean} [initial=false] - Treat this as the first pass, so the
   *   handlers are applied even though nothing changed
   * @returns {void}
   */
  checkResponsiveState(menu, initial = false) {
    const wasMobile = menu.isMobile;
    // `<=`, to match `@media (max-width: 768px)` in Now/css/menu.css. With `<`
    // a viewport of exactly 768px got the fixed mobile drawer from CSS while
    // JS still called it desktop, so body never gained .mobile-menu and
    // `body:not(.mobile-menu) .topmenu-toggle {display: none}` hid the only
    // control that could open it — the main menu was unreachable.
    const isMobile = window.innerWidth <= this.config.breakpoint;
    menu.elements.container.setAttribute('aria-orientation', isMobile || menu.isSidebar ? 'vertical' : 'horizontal');

    menu.elements.submenus.forEach(submenu => {
      if (submenu.content) {
        this.checkAndAdjustPosition(submenu.content);
      }
    });

    let stateChanged = initial;
    if (isMobile !== wasMobile) {
      stateChanged = true;
      menu.isMobile = isMobile;

      if (!isMobile && this.isMenuOpen(menu) && !menu.isSidebar) {
        this.closeMenu(menu);
      }

      if (isMobile) {
        document.body.classList.add('mobile-menu');
      } else {
        document.body.classList.remove('mobile-menu');
      }
    }

    if (stateChanged && menu.element.classList.contains('topmenu')) {
      if (!isMobile) {
        this.setupHoverHandlers(menu);
      } else if (!initial) {
        this.removeHoverHandlers(menu);
      }
    }
  },

  /**
   * Reports whether the pointer moved to somewhere still inside the submenu or
   * one of its ancestors, rather than out of the menu.
   *
   * Nothing currently calls this.
   *
   * @param {HTMLElement} submenu - Submenu being left
   * @param {MouseEvent} e - mouseleave event
   * @returns {boolean} True when the pointer is still within the menu
   */
  isHoveringSubmenu(submenu, e) {
    const currentItem = submenu.closest('li');
    const relatedTarget = e.relatedTarget;

    if (!relatedTarget) return false;

    if (currentItem.contains(relatedTarget)) {
      return true;
    }

    const parentUl = currentItem.closest('ul');
    if (parentUl && parentUl.contains(relatedTarget)) {
      return true;
    }

    let parent = parentUl?.parentElement;
    while (parent) {
      if (parent.contains(relatedTarget)) {
        return true;
      }
      parent = parent.closest('li')?.parentElement;
    }

    return false;
  },

  /**
   * Walks up from an item to the top-level item that contains it.
   *
   * @param {Element} current - Item to start from
   * @returns {Element|null} Top-level menu item, or null
   */
  findTopLevelMenuItem(current) {
    let parent = current.closest('li');
    while (parent) {
      const parentUl = parent.parentElement;
      if (!parentUl || !parentUl.closest('li')) {
        return parent.querySelector(':scope > [role="menuitem"]');
      }
      parent = parentUl.closest('li');
    }
    return null;
  },

  /**
   * Returns the top-level links and toggle buttons of a menu.
   *
   * @param {Object} menu - Menu instance
   * @returns {NodeList} Top-level menu items
   */
  getTopLevelMenuItems(menu) {
    return menu.elements.container.querySelectorAll(':scope > li > a, :scope > li:not(.menu-close) > button');
  },

  /**
   * Implements keyboard navigation inside a menu: arrows move between items and
   * in and out of submenus according to the orientation, Escape closes the
   * current level, Home and End jump to the ends, and Tab leaves the menu.
   *
   * @param {Object} menu - Menu instance
   * @param {KeyboardEvent} e - Key event
   * @returns {void}
   */
  handleKeyboardNavigation(menu, e) {
    if (!menu.element.contains(e.target)) return;

    const current = document.activeElement;
    const isTopLevel = current.closest('ul') === menu.elements.container;
    const submenu = current.closest('[aria-haspopup="true"]');
    const isHorizontalMenu = menu.elements.container.getAttribute('aria-orientation') === 'horizontal';

    switch (e.key) {
      case 'Escape':
        e.preventDefault();
        if (!isTopLevel) {
          const parentButton = current.closest('ul').parentElement.querySelector('[aria-haspopup="true"]');
          if (parentButton) {
            this.closeSubmenu(menu, parentButton);
            parentButton.focus();
          }
        } else if (this.isMenuOpen(menu)) {
          this.closeAllSubmenus(menu);
          this.closeMenu(menu);
          if (menu.elements?.toggle) {
            menu.elements.toggle.focus();
          }
        }
        break;

      case 'Tab':
        if (!isTopLevel) {
          const topLevelItem = this.findTopLevelMenuItem(current);
          const topLevelItems = this.getTopLevelMenuItems(menu);
          const index = Array.from(topLevelItems).indexOf(topLevelItem);

          let nextItem;
          if (e.shiftKey) {
            nextItem = topLevelItems[index - 1];
          } else {
            nextItem = topLevelItems[index + 1];
          }

          if (!nextItem) {
            this.closeMenu(menu);
            if (menu.elements?.toggle) {
              menu.elements.toggle.focus();
            }
            return;
          }
          e.preventDefault();
          nextItem.focus();
          if (nextItem.hasAttribute('aria-haspopup')) {
            this.openSubmenu(menu, nextItem, {useKeyboard: true});
          }
        }
        break;

      case 'Enter':
      case ' ':
        if (current.hasAttribute('aria-haspopup')) {
          e.preventDefault();
          this.openSubmenu(menu, current, {useKeyboard: true});
        }
        break;

      case 'Escape':
        e.preventDefault();
        if (submenu) {
          this.closeSubmenu(menu, submenu);
          submenu.focus();
        } else if (this.isMenuOpen(menu)) {
          this.closeMenu(menu);
          menu.elements.toggle?.focus();
        }
        break;

      case 'ArrowDown':
        e.preventDefault();
        if (current.hasAttribute('aria-haspopup') &&
          current.getAttribute('aria-expanded') === 'false') {
          this.openSubmenu(menu, current, {useKeyboard: true});
        } else {
          const items = Array.from(current.closest('ul').querySelectorAll(':scope > li > [role="menuitem"]'));
          const nextItem = this.getNextFocusableItem(items, current);
          if (nextItem) nextItem.focus();
        }
        break;

      case 'ArrowUp':
        e.preventDefault();
        const items = Array.from(current.closest('ul').querySelectorAll(':scope > li > [role="menuitem"]'));
        const prevItem = this.getPreviousFocusableItem(items, current);
        if (prevItem) prevItem.focus();
        break;

      case 'ArrowRight':
        if (isHorizontalMenu && isTopLevel) {
          e.preventDefault();
          const items = this.getTopLevelMenuItems(menu);
          const nextItem = this.getNextFocusableItem(items, current);
          if (nextItem) {
            nextItem.focus();
            if (nextItem.hasAttribute('aria-haspopup')) {
              this.openSubmenu(menu, nextItem, {useKeyboard: true});
            }
          }
        } else if (current.hasAttribute('aria-haspopup')) {
          e.preventDefault();
          this.openSubmenu(menu, current, {useKeyboard: true});
        }
        break;

      case 'ArrowLeft':
        if (isHorizontalMenu && isTopLevel) {
          e.preventDefault();
          const items = this.getTopLevelMenuItems(menu);
          const prevItem = this.getPreviousFocusableItem(items, current);
          if (prevItem) {
            prevItem.focus();
            if (prevItem.hasAttribute('aria-haspopup')) {
              this.openSubmenu(menu, prevItem);
            }
          }
        } else {
          e.preventDefault();
          const parentButton = current.closest('ul')?.parentElement?.querySelector('[aria-haspopup="true"]');
          if (parentButton) {
            this.closeSubmenu(menu, parentButton);
            parentButton.focus();
          }
        }
        break;

      case 'Home':
        e.preventDefault();
        const firstItem = this.getFirstFocusableItem(menu);
        if (firstItem) firstItem.focus();
        break;

      case 'End':
        e.preventDefault();
        const lastItem = this.getLastFocusableItem(menu);
        if (lastItem) lastItem.focus();
        break;
    }
  },

  /**
   * Returns the first enabled item of a menu.
   *
   * @param {Object} menu - Menu instance
   * @returns {Element|null} First focusable item
   */
  getFirstFocusableItem(menu) {
    return menu.elements.container.querySelector('[role="menuitem"]:not([disabled])');
  },

  /**
   * Returns the last enabled item of a menu.
   *
   * @param {Object} menu - Menu instance
   * @returns {Element|undefined} Last focusable item
   */
  getLastFocusableItem(menu) {
    const items = menu.elements.container.querySelectorAll('[role="menuitem"]:not([disabled])');
    return items[items.length - 1];
  },

  /**
   * Finds the submenu a toggle button controls.
   *
   * @param {Object} menu - Menu instance
   * @param {Element} toggle - Toggle button
   * @returns {Element|undefined} Matching submenu entry
   */
  findSubmenuByToggle(menu, toggle) {
    return menu.elements.submenus.find(
      submenu => submenu.toggle === toggle
    );
  },

  /**
   * Returns the item after the current one, wrapping to the first.
   *
   * @param {NodeList|Element[]} items - Items to move through
   * @param {Element} current - Item holding focus
   * @returns {Element} Item to focus next
   */
  getNextFocusableItem(items, current) {
    const currentIndex = Array.from(items).indexOf(current);
    return items[currentIndex + 1] || items[0];
  },

  /**
   * Returns the item before the current one, wrapping to the last.
   *
   * @param {NodeList|Element[]} items - Items to move through
   * @param {Element} current - Item holding focus
   * @returns {Element} Item to focus next
   */
  getPreviousFocusableItem(items, current) {
    const currentIndex = Array.from(items).indexOf(current);
    return items[currentIndex - 1] || items[items.length - 1];
  },

  /**
   * Announces a message to screen readers through a shared live region,
   * creating it on first use and translating the message.
   *
   * @param {string} message - Message key or text
   * @param {Object} [params={}] - Values for the translation placeholders
   * @returns {void}
   */
  announce(message, params = {}) {
    if (!this.announcer) {
      this.announcer = document.createElement('div');
      this.announcer.setAttribute('aria-live', 'polite');
      this.announcer.setAttribute('role', 'status');
      this.announcer.setAttribute('aria-atomic', 'true');
      this.announcer.className = 'sr-only';
      document.body.appendChild(this.announcer);
    }

    this.announcer.setAttribute('lang', window.I18nManager?.getCurrentLocale() || 'en');

    this.announcer.textContent = I18nManager.translate(message, params);
  },

  /**
   * Reports a menu error and attempts recovery.
   *
   * A failure inside recovery is marked so it is reported without recursing,
   * and falls back to forceCleanup().
   *
   * @param {string} message - Description of what failed
   * @param {Error} error - Error that was caught
   * @param {string} type - Method name, used to build the context
   * @param {boolean} [canRecover=true] - Attempt recovery
   * @returns {void}
   */
  handleError(message, error, type, canRecover = true) {
    if (error.isRecoveryError) {
      ErrorManager.handle(error, {
        context: `MenuManager.${type}`,
        type: 'error:menu',
        notify: true
      });
      return;
    }

    ErrorManager.handle(message, {
      context: `MenuManager.${type}`,
      type: 'error:menu',
      data: {canRecover},
      notify: true
    });

    if (canRecover) {
      try {
        this.recoverFromError();
      } catch (recoveryError) {
        recoveryError.isRecoveryError = true;

        ErrorManager.handle(recoveryError, {
          context: `MenuManager.recovery.${type}`,
          type: 'error:menu',
          notify: true
        });

        this.forceCleanup();
      }
    }
  },

  /**
   * Returns every menu to a known state: closes it, collapses its submenus,
   * clears its transform and re-applies its responsive state.
   *
   * @returns {void}
   */
  recoverFromError() {
    this.state.menus.forEach(menu => {
      if (this.isMenuOpen(menu)) {
        this.closeMenu(menu);
      }
      this.closeAllSubmenus(menu);
      this.resetMenuState(menu);
      this.checkResponsiveState(menu);
    });
  },

  /**
   * Last-resort reset when recovery itself failed: clears the touch state and
   * transform of every menu, collapses their toggles and removes their open
   * body class.
   *
   * @returns {void}
   */
  forceCleanup() {
    try {
      this.state.menus.forEach(menu => {
        menu.touchData = null;
        menu.element.style.transform = '';

        if (menu.elements?.toggle) {
          menu.elements.toggle.setAttribute('aria-expanded', 'false');
        }

        if (menu.element.classList.contains('sidemenu')) {
          document.body.classList.remove('sidemenu-close');
        } else {
          document.body.classList.remove('topmenu-open');
        }
      });

    } catch (error) {
      ErrorManager.handle(error, {
        context: 'MenuManager.forceCleanup'
      });
    }
  },

  /**
   * Clears the touch state and transform of one menu and removes its open body
   * class.
   *
   * @param {Object} menu - Menu instance
   * @returns {void}
   */
  resetMenuState(menu) {
    menu.touchData = null;
    menu.element.style.transform = '';
    if (menu.element && menu.element.classList.contains('sidemenu')) {
      document.body.classList.remove('sidemenu-close');
    } else {
      document.body.classList.remove('topmenu-open');
    }
  },

  /**
   * Destroys one menu instance and forgets it.
   *
   * @param {string} menuId - Menu id from data-menu-id
   * @returns {void}
   */
  destroyMenu(menuId) {
    const menu = this.state.menus.get(menuId);
    if (!menu) return;

    this.cleanup(menu);

    if (menu.element) {
      delete menu.element.dataset.menuId;
    }

    this.state.menus.delete(menuId);
  },

  /**
   * Releases everything a menu instance holds: its listeners, hover timers, the
   * backdrop and any toggle this manager created, then nulls its references.
   *
   * @param {Object} menu - Menu instance
   * @returns {void}
   */
  cleanup(menu) {
    if (!menu) return;

    if (menu.handlers) {
      if (menu.handlers.toggle) {
        menu.elements.toggle?.removeEventListener('click', menu.handlers.toggle);
      }
      if (menu.handlers.close) {
        menu.elements.close?.removeEventListener('click', menu.handlers.close);
      }
      if (menu.handlers.backdrop) {
        menu.elements.backdrop?.removeEventListener('click', menu.handlers.backdrop);
      }
      if (menu.handlers.keydown) {
        document.removeEventListener('keydown', menu.handlers.keydown);
      }
      if (menu.handlers.resize) {
        window.removeEventListener('resize', menu.handlers.resize);
      }
      if (menu.handlers.focusout) {
        menu.element.removeEventListener('focusout', menu.handlers.focusout);
      }

      if (menu.handlers.touch) {
        menu.element.removeEventListener('touchstart', menu.handlers.touch.start);
        menu.element.removeEventListener('touchmove', menu.handlers.touch.move);
        menu.element.removeEventListener('touchend', menu.handlers.touch.end);
        menu.element.removeEventListener('touchcancel', menu.handlers.touch.cancel);
      }
    }

    this.removeHoverHandlers(menu);

    if (menu.hoverTimeouts) {
      menu.hoverTimeouts.forEach(timeout => clearTimeout(timeout));
      menu.hoverTimeouts.clear();
    }

    if (menu.elements) {
      if (menu.elements.backdrop) {
        menu.elements.backdrop.remove();
      }
      if (menu.elements.close) {
        menu.elements.close.remove();
      }
      if (menu.elements.toggle?.classList.contains('runtime')) {
        menu.elements.toggle.remove();
      }
    }

    menu.touchData = null;
    menu.element.style.transform = '';
    if (menu.element && menu.element.classList.contains('sidemenu')) {
      document.body.classList.remove('sidemenu-close');
    } else {
      document.body.classList.remove('topmenu-open');
    }

    menu.element = null;
    menu.elements = null;
    menu.handlers = null;
    menu.hoverTimeouts = null;
    menu.touchData = null;
  },

  /**
   * Removes the hover listeners and pending hover timers of a menu, used when
   * it switches to mobile.
   *
   * @param {Object} menu - Menu instance
   * @returns {void}
   */
  removeHoverHandlers(menu) {
    if (!menu?.elements?.submenus) return;

    menu.elements.submenus.forEach(submenu => {
      if (submenu?.hoverHandlers) {
        const menuItem = submenu.closest('li');
        if (menuItem) {
          menuItem.removeEventListener('mouseenter', submenu.hoverHandlers.enter);
          menuItem.removeEventListener('mouseleave', submenu.hoverHandlers.leave);
        }
        delete submenu.hoverHandlers;
      }
    });

    if (menu.hoverTimeouts) {
      menu.hoverTimeouts.forEach(clearTimeout);
      menu.hoverTimeouts.clear();
      delete menu.hoverTimeouts;
    }
  },

  /**
   * Destroys every menu, disconnects the DOM observer, removes the live region
   * and emits menumanager:destroyed.
   *
   * The per-menu elements are released by destroyMenu(), which runs cleanup()
   * for each of them.
   *
   * @returns {void}
   */
  destroy() {
    this.state.menus.forEach((menu) => {
      this.destroyMenu(menu.id);
    });

    this.state.menus.clear();

    if (this.observer) {
      this.observer.disconnect();
      this.observer = null;
    }

    if (this.announcer) {
      this.announcer.remove();
      this.announcer = null;
    }

    this.state.initialized = false;

    Now.emit('menumanager:destroyed');
  },

  /**
   * Finds the menu instance mounted on an element.
   *
   * Nothing currently calls this.
   *
   * @param {HTMLElement} element - Menu element
   * @returns {Object|null} Instance, or null
   */
  findMenuByElement(element) {
    for (const menu of this.state.menus.values()) {
      if (menu.element === element) {
        return menu;
      }
    }
    return null;
  },

  /**
   * Closes the submenus that sit at the same level as the given one, so only
   * one branch stays open.
   *
   * @param {Object} menu - Menu instance
   * @param {HTMLElement} currentSubmenu - Submenu to keep open
   * @returns {void}
   */
  closeOtherSubmenus(menu, currentSubmenu) {
    const currentItem = currentSubmenu.closest('li');
    const parentUl = currentItem?.closest('ul');

    if (parentUl) {
      menu.elements.submenus.forEach(other => {
        const otherItem = other.closest('li');
        if (other !== currentSubmenu &&
          otherItem &&
          otherItem.closest('ul') === parentUl) {
          this.closeSubmenu(menu, other);
        }
      });
    }
  },

  /**
   * Reports whether a submenu and its content are both still in the DOM.
   *
   * Nothing currently calls this.
   *
   * @param {HTMLElement} submenu - Toggle button of the submenu
   * @returns {boolean} True when the submenu can be operated on
   */
  isSubmenuReady(submenu) {
    return submenu &&
      submenu.isConnected &&
      submenu.content &&
      submenu.content.isConnected;
  },

  /**
   * Render menu from data array
   * @param {HTMLElement|string} container - Menu container element or selector
   * @param {Array} data - Menu items array
   * @param {Object} options - Render options
   * @returns {HTMLElement} The rendered menu container
   *
   * @example
   * MenuManager.renderFromData(nav, [
   *   {title: 'Dashboard', url: '/', icon: 'icon-dashboard'},
   *   {title: 'Settings', icon: 'icon-settings', children: [
   *     {title: 'Profile', url: '/settings/profile'},
   *     {title: 'Security', url: '/settings/security'}
   *   ]}
   * ]);
   */
  renderFromData(container, data, options = {}) {
    // Resolve container
    if (typeof container === 'string') {
      container = document.querySelector(container);
    }

    if (!container) {
      ErrorManager.handle('MenuManager.renderFromData: Container not found', {
        context: 'MenuManager.renderFromData',
        data: {container, options}
      });
      return null;
    }

    if (!Array.isArray(data)) {
      ErrorManager.handle('MenuManager.renderFromData: Data must be an array', {
        context: 'MenuManager.renderFromData',
        data: {data}
      });
      return container;
    }

    // Destroy existing menu if already initialized
    const existingMenuId = container.dataset.menuId;
    if (existingMenuId && this.state.menus.has(existingMenuId)) {
      this.destroyMenu(existingMenuId);
    }

    // Create ul container
    const ul = document.createElement('ul');

    // Render each menu item
    data.forEach((item, index) => {
      const li = this.createMenuItem(item, 0, options);
      ul.appendChild(li);
    });

    // Clear and append
    container.innerHTML = '';
    container.appendChild(ul);

    // Ensure data-component="menu" is set for initialization
    if (!container.hasAttribute('data-component')) {
      container.setAttribute('data-component', 'menu');
    }

    // Initialize the menu
    this.createMenu(container);

    // Update active state
    this.updateActiveMenu();

    // Emit event
    Now.emit('menu:rendered', {
      container,
      itemCount: data.length
    });

    return container;
  },

  /**
   * Create a single menu item element
   * @param {Object} item - Menu item data
   * @param {number} level - Nesting level (0 = top level)
   * @param {Object} options - Render options
   * @returns {HTMLElement} The li element
   */
  createMenuItem(item, level = 0, options = {}) {
    const li = document.createElement('li');
    li.setAttribute('role', 'none');

    // Check if has children
    const hasChildren = item.children && Array.isArray(item.children) && item.children.length > 0;

    if (hasChildren) {
      // Create submenu toggle button
      const button = document.createElement('button');
      button.type = 'button';
      button.setAttribute('role', 'menuitem');
      button.setAttribute('aria-haspopup', 'true');
      button.setAttribute('aria-expanded', 'false');

      // Icon
      if (item.icon) {
        const icon = document.createElement('i');
        icon.className = item.icon;
        button.appendChild(icon);
      }

      // Title
      const title = item.title || '';
      const span = document.createElement('span');
      span.textContent = I18nManager.translate(title);
      span.setAttribute('data-i18n', title);
      button.appendChild(span);

      li.appendChild(button);

      // Create submenu
      const submenuId = `submenu-${Utils.generateUUID()}`;
      const submenu = document.createElement('ul');
      submenu.id = submenuId;
      submenu.setAttribute('role', 'menu');
      submenu.setAttribute('aria-label', I18nManager.translate('Submenu'));
      submenu.setAttribute('aria-hidden', 'true');

      button.setAttribute('aria-controls', submenuId);

      // Recursively add children (max 3 levels)
      if (level < 2) {
        item.children.forEach(child => {
          const childLi = this.createMenuItem(child, level + 1, options);
          submenu.appendChild(childLi);
        });
      }

      li.appendChild(submenu);

    } else {
      // Create link
      const link = document.createElement('a');
      link.href = item.url || '#';
      link.setAttribute('role', 'menuitem');

      // Icon
      if (item.icon) {
        const icon = document.createElement('i');
        icon.className = item.icon;
        link.appendChild(icon);
      }

      // Title
      const title = item.title || '';
      const span = document.createElement('span');
      span.textContent = I18nManager.translate(title);
      span.setAttribute('data-i18n', title);
      link.appendChild(span);

      // Badge (optional)
      if (item.badge) {
        const badge = document.createElement('span');
        badge.className = item.badgeClass || 'badge';
        badge.textContent = item.badge;
        badge.setAttribute('data-i18n', item.badge || '');
        link.appendChild(badge);
      }

      li.appendChild(link);
    }

    // Additional attributes
    if (item.id) {
      li.id = item.id;
    }
    if (item.class) {
      li.className = item.class;
    }
    if (item.data) {
      Object.entries(item.data).forEach(([key, value]) => {
        li.dataset[key] = value;
      });
    }

    return li;
  },

  /**
   * Set menu data and render (alias for renderFromData)
   * @param {HTMLElement|string} container - Menu container
   * @param {Array} data - Menu data
   * @param {Object} options - Options
   */
  setData(container, data, options = {}) {
    return this.renderFromData(container, data, options);
  }
};

if (window.Now?.registerManager) {
  Now.registerManager('menu', MenuManager);
}

window.MenuManager = MenuManager;

// Attach the api:loaded listener now, not only from init(). ApiComponent
// auto-fetches as soon as its own script block runs (autoload: true), which
// can be before Now.init() ever calls MenuManager.init() -- see the comment
// in setupApiDataListener() for why a late listener silently misses the event.
MenuManager.setupApiDataListener();
