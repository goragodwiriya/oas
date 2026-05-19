/**
 * Main Application
 * Now.js Framework
 */
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
          tokenUrl: 'api/index/auth/csrf-token' // CSRF endpoin
        }
      },

      // Internationalization settings
      i18n: {
        enabled: true,
        availableLocales: ['en', 'th']
      },

      // Application Configuration (Theme + Site Metadata)
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

        // API config - auto-load theme + site metadata from server on init
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
            requireGuest: false,
            requireAuth: true
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
          '/profile': {
            template: 'profile.html',
            title: '{LNG_Profile}',
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
      }
    }).then(() => {
      // Load application components after framework initialization
      const scripts = [
        `${currentDir}js/components/sidebar.js`,
        `${currentDir}js/components/topbar.js`,
        `${currentDir}js/components/SocialLogin.js`
      ];

      // Dynamically load all component scripts
      scripts.forEach(src => {
        const script = document.createElement('script');
        script.src = src;
        document.head.appendChild(script);
      });
    });

    // Create application instance
    const app = await Now.createApp({
      name: 'Now.js',
      version: '1.0.0'
    });

  } catch (error) {
    console.error('Application initialization failed:', error);
  }
});

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

  const smtpAuthChange = () => {
    element.querySelector('#email_SMTPSecure').disabled = !email_SMTPAuth.checked;
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

      const suggestion = result?.data?.suggestion || null;
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

function initForbiddenPage(element, data) {
  const messageElement = element.querySelector('[data-forbidden-message]');
  if (!messageElement) {
    return;
  }

  const message = data?.query?.message;

  if (typeof message === 'string' && message.trim() !== '') {
    messageElement.textContent = message.trim();
    messageElement.hidden = false;
    return;
  }

  messageElement.textContent = '';
  messageElement.hidden = true;
}

/**
 * Format with options status
 */
function formatTableOptionStatus(cell, rawValue, rowData, attributes) {
  const opts = attributes.lookupOptions || attributes.tableDataOptions || attributes.tableFilterOptions;

  // Normalizer: build a map value->text
  const makeMap = (options) => {
    if (!options) return new Map();
    if (Array.isArray(options)) {
      // [{value,text}, ...]
      return new Map(options.map(o => [String(o.value), o.text]));
    }
    // object map {val: label, ...}
    return new Map(Object.entries(options).map(([k, v]) => [String(k), v]));
  };

  const map = makeMap(opts);

  const key = rawValue === null || rawValue === undefined ? '' : String(rawValue);
  const label = map.has(key) ? map.get(key) : (rawValue && rawValue.text) ? rawValue.text : key;
  const index = map.has(key) ? Array.from(map.keys()).indexOf(key) : -1;


  cell.innerHTML = `<span class="status${index}" data-i18n>${label}</span>`;
}

function formatStarStatus(cell, rawValue, rowData, attributes) {
  if (rawValue === 'active' || parseInt(rawValue) === 1) {
    cell.innerHTML = '<span class="icon-star2 color-primary"></span>';
  } else {
    cell.innerHTML = '<span class="icon-star0 color-silver"></span>';
  }
}

function formatActiveStatus(cell, rawValue, rowData, attributes) {
  if (rawValue === 'active' || parseInt(rawValue) === 1) {
    cell.innerHTML = '<span class="icon-valid color-red" title="' + Now.translate('Active') + '"></span>';
  } else {
    cell.innerHTML = '<span class="icon-invalid color-silver" title="' + Now.translate('Inactive') + '"></span>';
  }
}

function formatLink(cell, rawValue, rowData, attributes) {
  if (!rawValue) {
    cell.innerHTML = '-';
    return;
  }

  const value = String(rawValue).trim();

  // Simple recognizers
  const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  const phoneRegex = /^\+?[0-9()\s\-./]{6,}$/;
  const urlProtocolRegex = /^https?:\/\//i;

  const makeLink = (href, text, iconClass) => {
    const a = document.createElement('a');
    a.href = href;
    // Open http(s) links in new tab, others (mailto/tel) in same
    if (/^https?:\/\//i.test(href)) {
      a.target = '_blank';
      a.rel = 'noopener';
    }
    if (iconClass) a.className = iconClass;
    a.textContent = text;
    cell.innerHTML = '';
    cell.appendChild(a);
  };

  if (/^mailto:/i.test(value)) {
    makeLink(value, value.replace(/^mailto:/i, ''), 'icon-mail');
    return;
  }

  if (/^tel:/i.test(value)) {
    makeLink(value, value.replace(/^tel:/i, ''), 'icon-phone');
    return;
  }

  if (emailRegex.test(value)) {
    makeLink('mailto:' + value, value, 'icon-mail');
    return;
  }

  if (phoneRegex.test(value)) {
    // Normalize phone for href (keep leading + if present)
    const telHref = 'tel:' + value.replace(/[^\d+]/g, '');
    makeLink(telHref, value, 'icon-phone');
    return;
  }

  // Fallback: treat as URL
  let href = value;
  if (!urlProtocolRegex.test(href)) href = 'http://' + href;
  const displayUrl = href.replace(/^https?:\/\//, '').replace(/\/$/, '');
  makeLink(href, displayUrl, 'icon-world');
}

function formatImage(cell, rawValue, rowData, attributes) {
  if (rawValue) {
    cell.innerHTML = '<div class="thumbnail" style="background-image: url(' + rawValue + ')"></div>';
  } else {
    cell.innerHTML = '';
  }
}

function copyToClipboard(cell, rawValue, rowData, attributes) {
  if (rawValue) {
    const link = document.createElement('a');
    link.className = 'icon-copy';
    link.textContent = rawValue;
    link.style.cursor = 'pointer';
    link.addEventListener('click', () => Utils.dom.copyToClipboard(String(rawValue)));
    cell.innerHTML = '';
    cell.appendChild(link);
  } else {
    cell.textContent = '';
  }
}
