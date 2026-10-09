/**
 * Main Application
 * Now.js Framework
 */

/**
 * เลขรุ่นของไฟล์ — ?v= ที่ index.php ติดให้ js/main.js (ค่า reversion) อ่านได้ตอนสคริปต์นี้รันเท่านั้น
 * ไฟล์ที่ main.js โหลดเองภายหลังต้องติดเลขเดียวกัน (versionedUrl) เพราะ service worker ตอบ URL ที่ลงท้ายด้วย
 * .js/.css แบบ cache-first ไฟล์ที่ไม่มี ?v= จึงเป็นตัวที่เบราว์เซอร์เคยโหลดไว้ตลอดไปแม้บนเซิร์ฟเวอร์จะเปลี่ยนแล้ว
 * (ปฏิทินรวมเคยไม่มีไอคอนและคลิกแล้วไม่เปิดรายละเอียด เพราะได้ eventcalendar.min.js ตัวเก่าก่อนมี icon/clickApi)
 */
const mainScriptVersion = new URL(document.currentScript.src).searchParams.get('v') || '';

/**
 * @param {string} url
 * @returns {string} url ที่ติด ?v= ของ js/main.js (ไม่มีเลขรุ่น = url เดิม)
 */
function versionedUrl(url) {
  return mainScriptVersion ? `${url}?v=${encodeURIComponent(mainScriptVersion)}` : url;
}

document.addEventListener('DOMContentLoaded', async () => {
  try {
    // Detect current directory path
    const currentPath = window.location.pathname;
    const currentDir = currentPath.substring(0, currentPath.lastIndexOf('/') + 1);

    // Initialize framework
    await Now.init({
      // Environment mode: 'development' or 'production'
      environment: 'production',

      // Path configuration for templates and resources
      paths: {
        components: `${currentDir}components`,
        plugins: `${currentDir}plugins`,
        templates: `${currentDir}templates`,
        translations: `${currentDir}language`
      },

      // Enable framework-level auth so AuthManager will initialize before RouterManager
      auth: {
        enabled: true,
        autoInit: true,
        endpoints: {
          verify: 'api/index/auth/verify', // Used to check if the Token/Cookie sent by Client (such as Authorization Header or Cookie) is still correct/not expired or not.
          me: 'api/index/auth/me', // Restore the current user (Profile)
          login: 'api/index/auth/login', // Get a Creitedial (Email/Password) and reply token/session + user info.
          logout: 'api/index/auth/logout', // Cancel session /invalidates token at Server
          refresh: 'api/index/auth/refresh' // Used to ask for a new Token when the TOKEN is currently expired (if using JWT or Token-based Author)
        },

        token: {
          storageKey: 'auth_user'
        },

        // Base-relative paths — AuthManager.redirectTo() feeds these to
        // RouterManager.navigate(), which matches them against route keys
        // ('/login', '/403', ...). A full path like `${currentDir}../login`
        // never matches a route (and resolves outside /app to the public
        // landing), so keep these relative to the SPA base.
        redirects: {
          afterLogin: '/',
          afterLogout: '/login',
          unauthorized: '/login',
          forbidden: '/403'
        }
      },

      // Security configuration (CSRF token endpoint configurable here)
      security: {
        csrf: {
          enabled: true,
          tokenName: '_token',
          headerName: 'X-CSRF-Token',
          cookieName: 'XSRF-TOKEN',
          metaName: 'csrf-token',
          tokenUrl: 'api/index/auth/csrf-token' // CSRF endpoint
        }
      },

      // Internationalization settings
      i18n: {
        enabled: true,
        availableLocales: ['en', 'th']
      },

      // AppConfigManager Configuration (Theme + Site Metadata)
      config: {
        enabled: true,
        defaultTheme: 'light',
        storageKey: 'crm_theme',
        systemPreference: false, // Not use system color scheme preference

        // Smooth transitions
        transition: {
          enabled: true,
          duration: 300,
          hideOnSwitch: true
        },

        // API config - auto-load from server on init
        api: {
          enabled: true,
          configUrl: `api/index/config/frontend-settings`,  // Returns { variables, site }
          cacheResponse: true
        }
      },

      router: {
        enabled: true,
        base: currentDir,
        mode: 'history', // 'hash' or 'history'

        // Auth Configuration for Router
        auth: {
          enabled: true,
          autoGuard: true,
          defaultRequireAuth: true,
          publicPaths: ['/login', '/404'],
          guestOnlyPaths: ['/login'],
          // Targets must be BASE-RELATIVE paths (router prepends `base`
          // = currentDir when pushing to the URL). Passing a full path such
          // as `${currentDir}login` makes RouterManager.navigate() match it
          // literally against route keys ('/login', ...) — which never match,
          // so the guard lands on the 404 route instead of the login page.
          redirects: {
            unauthenticated: '/login',
            unauthorized: '/login',
            forbidden: '/403',
            afterLogin: '/',
            afterLogout: '/login'
          }
        },

        notFound: {
          behavior: 'render',
          template: '404.html',
          title: 'Page Not Found'
        },

        routes: {
          '/': {
            template: 'index.html',
            title: '{LNG_Dashboard}',
            requireAuth: false,
            requireGuest: false,
            // Guests may open the dashboard only when dashboard_guest is on
            beforeEnter: async (params, current, authManager) => {
              if (authManager?.isAuthenticated?.()) {
                return true;
              }
              return await isDashboardGuest() ? true : '/login';
            }
          },
          '/login': {
            template: 'login.html',
            title: '{LNG_Login}',
            requireGuest: true,
            requireAuth: false
          },
          '/forgot': {
            template: 'forgot.html',
            title: '{LNG_Forgot Password}',
            requireGuest: true,
            requireAuth: false
          },
          '/register': {
            template: 'register.html',
            title: '{LNG_Register}',
            requireGuest: true,
            requireAuth: false
          },
          '/reset-password': {
            template: 'reset-password.html',
            title: '{LNG_Reset Password}',
            requireGuest: true,
            requireAuth: false
          },
          '/activate': {
            template: 'activate.html',
            title: '{LNG_Activate Account}',
            requireGuest: true,
            requireAuth: false
          },
          '/logout': {
            requireAuth: false,
            beforeEnter: async (params, current, authManager) => {
              await authManager.logout();
              return '/login';
            }
          },
          '/profile': {
            template: 'profile.html',
            title: '{LNG_Edit Profile}',
            menuPath: '/users',
            requireAuth: true
          },
          '/users': {
            template: 'users.html',
            title: '{LNG_Users}',
            requireAuth: true
          },
          '/categories': {
            template: 'settings/categories.html',
            title: '{LNG_Category}',
            requireAuth: true
          },
          '/user-status': {
            template: 'settings/userstatus.html',
            title: '{LNG_Member status}',
            requireAuth: true
          },
          '/permission': {
            template: 'settings/permission.html',
            title: '{LNG_Permissions}',
            requireAuth: true
          },
          '/general-settings': {
            template: 'settings/general.html',
            title: '{LNG_General Settings}',
            requireAuth: true
          },
          '/company-settings': {
            template: 'settings/company.html',
            title: '{LNG_Company Settings}',
            requireAuth: true
          },
          '/email-settings': {
            template: 'settings/email.html',
            title: '{LNG_Email Settings}',
            requireAuth: true
          },
          '/api-settings': {
            template: 'settings/api.html',
            title: '{LNG_API Settings}',
            requireAuth: true
          },
          '/theme-settings': {
            template: 'settings/theme.html',
            title: '{LNG_Theme Settings}',
            requireAuth: true
          },
          '/line-settings': {
            template: 'settings/line.html',
            title: '{LNG_Line Settings}',
            requireAuth: true
          },
          '/telegram-settings': {
            template: 'settings/telegram.html',
            title: '{LNG_Telegram Settings}',
            requireAuth: true
          },
          '/sms-settings': {
            template: 'settings/sms.html',
            title: '{LNG_SMS Settings}',
            requireAuth: true
          },
          '/ai-settings': {
            template: 'settings/ai.html',
            title: '{LNG_AI Settings}',
            requireAuth: true
          },
          '/cookie-policy': {
            template: 'settings/cookie-policy.html',
            title: '{LNG_Cookie Policy}',
            requireAuth: true
          },
          '/languages': {
            template: 'settings/languages.html',
            title: '{LNG_Manage languages}',
            requireAuth: true
          },
          '/language': {
            template: 'settings/language.html',
            title: '{LNG_Add}/{LNG_Edit} {LNG_Language}',
            menuPath: '/languages',
            requireAuth: true
          },
          '/usage': {
            template: 'settings/usage.html',
            title: '{LNG_Usage history}',
            requireAuth: true
          },
          '/403': {
            template: '403.html',
            title: '{LNG_Access Denied}',
            requireAuth: true
          },
          '/404': {
            template: '404.html',
            title: '{LNG_Page Not Found}'
          }
        }
      },

      scroll: {
        enabled: false,
        selectors: {
          content: '.content',
        }
      },

      // Outbox for records saved while offline, flushed once back online.
      // SyncManager ships with now.core but defaults to disabled;
      // Now.init() hands this config to SyncManager.init() via initializeManagers().
      sync: {
        enabled: false,
        autoSync: true,
        syncInterval: 60000,
        retryStrategy: 'exponential',
        maxRetries: 5,
        batchSize: 10,
        concurrentRequests: 2
      },

      storage: {
        enabled: true,
        type: 'indexeddb',
        name: 'now_app_storage'
      }
    }).then(() => {
      // Load application components after framework initialization
      Utils.dom.loadResources([
        `${currentDir}js/components/sidebar.js`,
        `${currentDir}js/components/topbar.js`,
        `${currentDir}js/components/SocialLogin.js`
      ].map(versionedUrl));
    });

    // Create application instance
    const app = await Now.createApp({
      name: 'Now.js',
      version: '1.0.0'
    });

    // ---- PWA ----
    // ServiceWorkerManager is not in Now.js's core resource list (Now.js declares
    // a `serviceWorker` config key but nothing reads it), so the bundle is loaded
    // separately from index.php and init() is called here.
    //
    // The path is derived from currentDir because the app can be installed in a
    // subdirectory; the '/service-worker.js' default breaks as soon as it is.
    if (typeof ServiceWorkerManager !== 'undefined') {
      ServiceWorkerManager.init({
        enabled: true,
        serviceWorkerPath: `${currentDir}service-worker.js`,
        scope: currentDir,
        // No cacheName: service-worker.js names its cache after the scope
        notifyOnUpdate: true,
        precache: [
          currentDir,
          `${currentDir}Now/dist/now.core.min.css`,
          `${currentDir}Now/dist/now.core.min.js`,
          `${currentDir}Now/dist/now.table.min.js`,
          `${currentDir}Now/dist/now.graph.min.js`,
          `${currentDir}Now/css/fonts.css`,
          `${currentDir}Now/css/fonts/sarabun.css`,
          `${currentDir}Now/css/fonts/kanit.css`,
          `${currentDir}Now/dist/now.serviceworker.min.js`,
          `${currentDir}css/styles.css`,
          `${currentDir}js/main.js`,
          `${currentDir}js/global.js`
        ],
        cachePatterns: [
          '\\.js$',
          '\\.css$',
          '\\.html$',
          '\\.woff2?$',
          '\\.png$',
          '\\.jpe?g$',
          '\\.svg$',
          '\\.webp$'
        ],
        // Never cache responses tied to the signed-in user
        excludeFromCache: ['/api/index/auth/', '/api/index/menus'],
        strategies: {
          '/api/': 'network-first',
          // Templates are fetched without a ?v= version, so cache-first kept serving
          // the old page after a deploy until the second visit. Network-first
          // (with the no-cache header from .htaccess) picks up a new template on
          // the first load and still falls back to the cache offline.
          '/templates/': 'network-first'
        }
      });
    }

  } catch (error) {
    console.error('Application initialization failed:', error);
  }
});

/**
 * โหลด EventCalendar เฉพาะเมื่อหน้ามีปฏิทิน — ปฏิทินรวมของแกน (api/index/calendar) เป็นบล็อกบนหน้าแรก
 * (ไม่มีเมนู/หน้าแยก) และหน้าของโมดูลที่วางปฏิทินไว้ในเทมเพลตเอง ไซต์ที่ไม่มีปฏิทินจึงไม่ต้องโหลด
 *
 * บล็อกบนหน้าแรกถูกวาดด้วย data-for หลัง ApiComponent ได้คำตอบ ซึ่งเกิดหลัง route:changed
 * ที่ EventCalendar ใช้สแกนหน้า จึงต้องสั่ง discoverCalendars() เองหลัง api:content-rendered
 * — สั่งซ้ำได้ไม่เสียหาย create() คืน instance เดิมถ้าปฏิทินตัวนั้นยังอยู่ในหน้า
 *
 * @param {Element|Document} root
 */
function initEventCalendars(root) {
  if (!root?.querySelector?.('[data-event-calendar]')) {
    return;
  }
  Utils.dom.loadResources(['Now/dist/eventcalendar.min.js', 'Now/dist/eventcalendar.min.css'].map(versionedUrl))
    .then(() => window.EventCalendar?.discoverCalendars(root))
    .catch(error => console.error('EventCalendar failed to load:', error));
}
EventManager.on('route:changed', () => initEventCalendars(document));
// ตัวที่ส่งมากับ EventManager คือ {instance, ...detail} — element อยู่ที่ instance.element
EventManager.on('api:content-rendered', ({instance}) => initEventCalendars(instance?.element || document));

/**
 * Whether guests may view the dashboard (config dashboard_guest).
 * The production bundle starts AppConfigManager after RouterManager, so on the
 * first page load its config is not loaded yet and is fetched here instead.
 *
 * @returns {Promise<boolean>}
 */
async function isDashboardGuest() {
  let cfg = window.AppConfigManager?.state?.lastConfig;
  if (!cfg) {
    try {
      const response = await ApiService.get('api/index/config/frontend-settings');
      cfg = response?.data?.data;
    } catch (error) {
      cfg = null;
    }
  }
  return !!cfg?.dashboard_guest;
}

function initProfile(element, data) {
  const input = element.querySelector('#birthday');
  const display = element.querySelector('.dropdown-display');

  const updateAge = () => {
    if (input.value) {
      const birth = new Date(input.value);
      const age = Math.floor((Date.now() - birth) / 31557600000);

      // Format date with standard pattern (YYYY uses locale-based year: BE for Thai, CE for others)
      const formattedDate = Utils.date.format(input.value, 'D MMMM YYYY');

      display.textContent = `${formattedDate} (${age} ${Now.translate('years')})`;
    } else {
      display.textContent = '';
    }
  };

  input.addEventListener('change', updateAge);
  updateAge();

  // Return cleanup function (optional)
  return () => {
    input.removeEventListener('change', updateAge);
  };
}

function initGeneralSettings(element, data) {
  const timezone = element.querySelector('#timezone');
  const server_time = element.querySelector('#server_time');
  const local_time = element.querySelector('#local_time');
  let intervalId = 0;

  const updateTimes = () => {
    // Update local time with selected timezone
    if (local_time && timezone?.value) {
      const options = {
        timeZone: timezone.value,
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false
      };
      local_time.textContent = new Date().toLocaleString('en-GB', options).replace(',', '');
    }

    // Update server time (add elapsed time to initial server time)
    if (server_time) {
      // Parse d/m/Y H:i:s format
      const parts = server_time.textContent.match(/(\d+)\/(\d+)\/(\d+)\s+(\d+):(\d+):(\d+)/);
      if (parts) {
        const seconds = parseInt(parts[6]) + 1;
        const serverStartTime = new Date(parts[3], parts[2] - 1, parts[1], parts[4], parts[5], seconds);
        const currentServerTime = new Date(serverStartTime.getTime());
        server_time.textContent = Utils.date.format(currentServerTime, 'DD/MM/YYYY HH:mm:ss', 'th-CE');
      }

    }
  };

  if (timezone && server_time && local_time) {
    updateTimes();
    intervalId = window.setInterval(updateTimes, 1000);
  }

  // Return cleanup function (optional)
  return () => {
    window.clearInterval(intervalId);
  };
}

function initEmailSettings(element, data) {
  const email_SMTPAuth = element.querySelector('#email_SMTPAuth');
  const test_email = element.querySelector('#test_email');

  // Security (STARTTLS/SSL) ไม่ขึ้นกับการเข้าสู่ระบบ — mail server ที่ไม่ต้อง login ก็บังคับ STARTTLS ได้
  const smtpAuthChange = () => {
    element.querySelector('#email_Username').disabled = !email_SMTPAuth.checked;
    element.querySelector('#email_Password').disabled = !email_SMTPAuth.checked;
  };
  email_SMTPAuth.addEventListener('change', smtpAuthChange);
  smtpAuthChange();

  // Test email button handler - sends to logged-in user's email
  const testEmailClick = async () => {
    // Disable button during request
    test_email.disabled = true;
    const originalText = test_email.innerHTML;
    test_email.innerHTML = '<span class="spinner"></span> ' + Now.translate('Sending...');

    try {
      const response = await ApiService.post('api/index/settings/testEmail');

      if (response.success) {
        NotificationManager.success(response.message || Now.translate('Test sent successfully'));
      } else {
        NotificationManager.error(response.message || Now.translate('Failed to send test'));
      }
    } catch (error) {
      NotificationManager.error(Now.translate('Failed to send test'));
    } finally {
      test_email.disabled = false;
      test_email.innerHTML = originalText;
    }
  };

  if (test_email) {
    test_email.addEventListener('click', testEmailClick);
  }

  // Return cleanup function
  return () => {
    email_SMTPAuth.removeEventListener('change', smtpAuthChange);
    if (test_email) {
      test_email.removeEventListener('click', testEmailClick);
    }
  };
}

function initAiSettings(element, data) {
  const payload = data && typeof data === 'object' ? data : {};
  const state = payload.data && typeof payload.data === 'object' && !Array.isArray(payload.data)
    ? payload.data
    : payload.state && typeof payload.state === 'object'
      ? payload.state
      : payload;

  const ai_provider = element.querySelector('#ai_provider');
  const ai_edit_provider = element.querySelector('#ai_edit_provider');
  const ai_model = element.querySelector('#ai_model');
  const ai_custom_model_field = element.querySelector('#ai_custom_model_field');
  const ai_custom_model = element.querySelector('#ai_custom_model');
  const ai_api_key = element.querySelector('#ai_api_key');
  const ai_api_url = element.querySelector('#ai_api_url');
  const ai_max_tokens = element.querySelector('#ai_max_tokens');
  const ai_temperature = element.querySelector('#ai_temperature');
  const settingsForm = element.matches('form') ? element : element.querySelector('form[data-form="settings"]');
  const test_ai = element.querySelector('#test_ai');

  const providerDefaults = state && typeof state.ai_provider_defaults === 'object' ? state.ai_provider_defaults : {};
  const providerState = state && typeof state.ai_connections === 'object'
    ? JSON.parse(JSON.stringify(state.ai_connections))
    : {};

  const initialProvider = ai_provider?.value || state?.ai_provider || 'openai';
  if (ai_edit_provider && ai_edit_provider.value !== initialProvider) {
    ai_edit_provider.value = initialProvider;
  }
  let currentProvider = initialProvider;

  const getProviderMeta = (provider) => provider && providerDefaults[provider] ? providerDefaults[provider] : {};

  const getProviderModels = (provider) => {
    const meta = getProviderMeta(provider);
    return Array.isArray(meta.models) ? meta.models : [];
  };

  const defaultValue = (value, fallback) => (value !== undefined && value !== null && value !== '' ? String(value) : String(fallback));

  const rememberProviderState = (provider) => {
    if (!provider) {
      return;
    }
    const meta = getProviderMeta(provider);
    let apiUrl = ai_api_url ? ai_api_url.value.trim() : '';
    if (apiUrl === (meta.default_api_url || '')) {
      apiUrl = '';
    }
    providerState[provider] = {
      model: ai_model && ai_model.value !== '__custom__' ? ai_model.value : '',
      model_option: ai_model ? ai_model.value : '',
      custom_model: ai_custom_model ? ai_custom_model.value.trim() : '',
      use_custom_model: ai_model && ai_model.value === '__custom__' ? 1 : 0,
      api_key: ai_api_key ? ai_api_key.value : '',
      api_url: apiUrl,
      max_tokens: ai_max_tokens ? ai_max_tokens.value : '',
      temperature: ai_temperature ? ai_temperature.value : ''
    };
  };

  const normalizeProviderState = (provider) => {
    const meta = getProviderMeta(provider);
    const models = getProviderModels(provider);
    const draft = providerState[provider] || {};
    let modelOption = draft.model_option || '';
    let customModel = draft.custom_model || '';

    if (!modelOption) {
      if (draft.use_custom_model && customModel) {
        modelOption = '__custom__';
      } else if (draft.model && models.includes(draft.model)) {
        modelOption = draft.model;
      } else if (draft.model) {
        modelOption = '__custom__';
        customModel = draft.model;
      } else {
        modelOption = meta.default_model || models[0] || '__custom__';
      }
    }

    if (modelOption !== '__custom__' && modelOption && !models.includes(modelOption)) {
      customModel = modelOption;
      modelOption = '__custom__';
    }

    if (modelOption === '__custom__' && !customModel && draft.model && !models.includes(draft.model)) {
      customModel = draft.model;
    }

    return {
      api_key: draft.api_key || '',
      api_url: draft.api_url || meta.default_api_url || '',
      model_option: modelOption,
      custom_model: customModel,
      max_tokens: defaultValue(draft.max_tokens, state?.ai_max_tokens ?? 1024),
      temperature: defaultValue(draft.temperature, state?.ai_temperature ?? 0.7)
    };
  };

  const renderModelOptions = (provider, selectedValue) => {
    if (!ai_model) {
      return;
    }

    const meta = getProviderMeta(provider);
    const models = getProviderModels(provider);
    const options = models.map((model) => ({
      value: model,
      text: model === meta.default_model ? `${model} (${Now.translate('Default')})` : model
    }));
    options.push({value: '__custom__', text: Now.translate('Custom')});

    SelectElementFactory.updateOptions(ai_model, options, false);

    const fallback = meta.default_model || models[0] || '__custom__';
    ai_model.value = selectedValue && (selectedValue === '__custom__' || models.includes(selectedValue)) ? selectedValue : fallback;
  };

  const renderModelGuidance = (provider) => {
    const meta = getProviderMeta(provider);

    if (ai_api_key) {
      ai_api_key.placeholder = meta.local ? Now.translate('Not required for local models') : '';
    }
    if (ai_api_url) {
      ai_api_url.placeholder = meta.default_api_url || '';
    }
    if (ai_custom_model) {
      ai_custom_model.placeholder = meta.default_model || 'Enter exact model ID';
    }
  };

  const toggleCustomModel = () => {
    const showCustom = ai_model && ai_model.value === '__custom__';
    if (ai_custom_model_field) {
      ai_custom_model_field.classList.toggle('hidden', !showCustom);
    }
    if (ai_custom_model) {
      ai_custom_model.disabled = !showCustom;
    }
    if (!showCustom && settingsForm && window.FormManager && typeof FormManager.getInstanceByElement === 'function') {
      const fmInst = FormManager.getInstanceByElement(settingsForm);
      if (fmInst?.state?.apiFieldErrors) {
        delete fmInst.state.apiFieldErrors.ai_custom_model;
      }
      if (window.FormError) {
        FormError.clearFieldError('ai_custom_model');
      }
    }
  };

  const applyProviderState = (provider) => {
    const current = normalizeProviderState(provider);

    renderModelOptions(provider, current.model_option);
    if (ai_api_key) {
      ai_api_key.value = current.api_key;
    }
    if (ai_api_url) {
      ai_api_url.value = current.api_url;
    }
    if (ai_custom_model) {
      ai_custom_model.value = current.custom_model;
    }
    if (ai_max_tokens) {
      ai_max_tokens.value = current.max_tokens;
    }
    if (ai_temperature) {
      ai_temperature.value = current.temperature;
    }

    renderModelGuidance(provider);
    toggleCustomModel();
  };

  const providerChange = () => {
    rememberProviderState(currentProvider);
    currentProvider = ai_edit_provider?.value || 'openai';
    applyProviderState(currentProvider);
  };

  applyProviderState(currentProvider);

  const modelChange = () => {
    toggleCustomModel();
  };

  if (ai_edit_provider) {
    ai_edit_provider.addEventListener('change', providerChange);
  }
  if (ai_model) {
    ai_model.addEventListener('change', modelChange);
  }

  const testAiClick = async () => {
    if (!test_ai) {
      return;
    }

    const provider = ai_edit_provider?.value || currentProvider || '';
    const model = ai_model?.value || '';
    const customModel = ai_custom_model?.value.trim() || '';

    if (model === '__custom__' && !customModel) {
      NotificationManager.error('{LNG_Please fill in} {LNG_Custom Model}');
      return;
    }

    rememberProviderState(currentProvider);

    const api_key = ai_api_key?.value || '';
    const api_url = ai_api_url?.value || '';
    const max_tokens = ai_max_tokens?.value || '';
    const temperature = ai_temperature?.value || '';

    test_ai.disabled = true;
    const originalText = test_ai.innerHTML;
    test_ai.innerHTML = '<span class="spinner"></span> ' + Now.translate('{LNG_Testing}...');

    try {
      const response = await ApiService.post('api/index/settings/testAi', {
        ai_provider: ai_provider?.value || provider,
        ai_edit_provider: provider,
        ai_api_key: api_key,
        ai_api_url: api_url,
        ai_model: model,
        ai_custom_model: customModel,
        ai_max_tokens: max_tokens,
        ai_temperature: temperature
      });

      if (response.success) {
        NotificationManager.success(response.message || Now.translate('AI connection test successful'));
      } else {
        NotificationManager.error(response.message || Now.translate('AI connection test failed'));
      }
    } catch (error) {
      NotificationManager.error(Now.translate('AI connection test failed'));
    } finally {
      test_ai.disabled = false;
      test_ai.innerHTML = originalText;
    }
  };

  if (test_ai) {
    test_ai.addEventListener('click', testAiClick);
  }

  return () => {
    if (ai_edit_provider) {
      ai_edit_provider.removeEventListener('change', providerChange);
    }
    if (ai_model) {
      ai_model.removeEventListener('change', modelChange);
    }
    if (test_ai) {
      test_ai.removeEventListener('click', testAiClick);
    }
  };
}

function firstMessage(...candidates) {
  for (const candidate of candidates) {
    if (typeof candidate === 'string' && candidate.trim() !== '') {
      return candidate.trim();
    }
  }
  return '';
}

function setBusyButton(button, busyText, busy) {
  if (!button) {
    return;
  }
  if (busy) {
    button.dataset.originalText = button.innerHTML;
    button.disabled = true;
    button.innerHTML = '<span class="spinner"></span> ' + busyText;
  } else {
    button.disabled = false;
    button.innerHTML = button.dataset.originalText || button.innerHTML;
  }
}

function unwrapApiResponse(response) {
  const raw = response && typeof response === 'object' ? response.data : null;
  const payload = raw && typeof raw === 'object' && !Array.isArray(raw) ? raw : null;

  let dataObject = raw;
  if (payload && payload.data !== undefined) {
    dataObject = payload.data;
  }

  const payloadSuccess = payload && typeof payload.success === 'boolean' ? payload.success : null;
  const responseSuccess = Boolean(response?.success);

  return {
    success: payloadSuccess === null ? responseSuccess : (responseSuccess && payloadSuccess),
    message: firstMessage(
      payload?.message,
      payload?.error,
      typeof raw === 'string' ? raw : '',
      response?.statusText,
      response?.message
    ),
    data: dataObject,
    raw
  };
}

function extractApiErrorMessage(error, fallback) {
  const responseData = error?.response?.data;
  const localData = error?.data;

  const message = firstMessage(
    responseData?.message,
    responseData?.error,
    typeof responseData === 'string' ? responseData : '',
    localData?.message,
    localData?.error,
    typeof localData === 'string' ? localData : '',
    error?.response?.message,
    error?.response?.statusText,
    error?.message,
    fallback
  );

  return message || fallback;
}

function initThemeSettingsAssistant(element, data) {
  const promptInput = element.querySelector('#theme_prompt');
  const suggestButton = element.querySelector('#ai_suggest_theme');
  const colorFields = [
    'ColorBackground',
    'ColorText',
    'ColorPrimary',
    'ColorInfo',
    'HeaderColorBackground',
    'HeaderColorText',
    'SidebarColorBackground',
    'SidebarColorText',
    'MenuHighlightBg',
    'MenuHighlightText',
    'FooterColorBackground',
    'FooterColorText'
  ];

  if (!suggestButton) {
    return () => {};
  }

  const applyColorsToForm = (colors) => {
    colorFields.forEach((field) => {
      const input = element.querySelector(`#${field}`);
      const value = colors && typeof colors === 'object' ? colors[field] : '';

      if (input && value != null) {
        const nextValue = typeof value === 'string' ? value.trim() : value;
        input.value = nextValue;

        if (nextValue === '') {
          input.removeAttribute('value');
        } else {
          input.setAttribute('value', nextValue);
        }

        input.dispatchEvent(new Event('input', {bubbles: true}));
        input.dispatchEvent(new Event('change', {bubbles: true}));
      }
    });
  };

  const suggestThemeClick = async () => {
    const prompt = promptInput?.value?.trim() || '';
    if (prompt === '') {
      NotificationManager.error(Now.translate('Please fill in') + ' ' + Now.translate('Design Brief'));
      return;
    }

    setBusyButton(suggestButton, Now.translate('{LNG_Generating}…'), true);
    try {
      const response = await ApiService.post('api/index/settings/suggestTheme', {
        theme_prompt: prompt
      });
      const result = unwrapApiResponse(response);

      if (!result.success) {
        const errMsg = result.message || Now.translate('Failed to suggest theme');
        NotificationManager.error(errMsg);
        return;
      }

      const suggestion = result?.data?.data?.suggestion || result?.data?.suggestion || null;
      if (!suggestion || typeof suggestion !== 'object') {
        const errMsg = Now.translate('Theme suggestion response is missing suggestion data');
        NotificationManager.error(errMsg);
        return;
      }

      applyColorsToForm(suggestion.colors || {});

      const successMessage = result.message || Now.translate('Theme suggestion generated. Review the colors and click Save.');
      const suggestionSummary = [suggestion.name, suggestion.description].filter((value) => typeof value === 'string' && value.trim() !== '').join(' - ');

      NotificationManager.success(suggestionSummary ? `${successMessage} ${suggestionSummary}` : successMessage);
    } catch (error) {
      NotificationManager.error(extractApiErrorMessage(error, Now.translate('Failed to suggest theme')));
    } finally {
      setBusyButton(suggestButton, '', false);
    }
  };

  suggestButton.addEventListener('click', suggestThemeClick);

  return () => {
    suggestButton.removeEventListener('click', suggestThemeClick);
  };
}
