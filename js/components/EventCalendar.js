/**
 * EventCalendar - Full-featured Event Calendar Component
 *
 * Features:
 * - Multi-day/week/month spanning events displayed as continuous bars
 * - Month, Week, Day views
 * - Max 3 events per day cell with "+N more" overflow
 * - Mobile-friendly with tap-to-view modals
 * - API integration for loading events
 * - ResponseHandler integration for event actions
 * - i18n support (Thai/English)
 *
 * @requires Utils
 * @requires Modal
 * @requires ResponseHandler
 * @requires I18nManager (optional)
 * @requires ApiService (optional)
 */
const EventCalendar = {
  config: {
    defaultView: 'month',
    locale: 'auto',
    timezone: 'local', // 'local' or 'UTC' or specific timezone (future)
    scheduleMode: 'continuous',
    firstDayOfWeek: 0, // 0=Sunday, 1=Monday
    maxEventsPerDay: 3,
    showNavigation: true,
    showToday: true,
    showViewSwitcher: true,
    showPeriodPicker: false,
    views: ['month', 'week', 'day'],
    api: null,
    apiMethod: 'GET',
    eventDataPath: 'data',
    minDate: null,
    maxDate: null,
    yearRangeBefore: 10,
    yearRangeAfter: 10,
    eventColors: [
      '#4285F4', // Blue
      '#EA4335', // Red
      '#FBBC04', // Yellow
      '#34A853', // Green
      '#8E24AA', // Purple
      '#E91E63', // Pink
      '#00ACC1', // Cyan
      '#FF7043'  // Orange
    ],
    onDateClick: null,
    onEventClick: null,
    onEventClickApi: null
  },

  state: {
    instances: new Map(),
    initialized: false,
    lifecycleBound: false,
    cleanupHandlers: new WeakMap() // Store cleanup functions for each instance
  },

  // i18n strings
  i18n: {
    th: {
      today: 'วันนี้',
      month: 'เดือน',
      week: 'สัปดาห์',
      day: 'วัน',
      moreEvents: '+{count} เพิ่มเติม',
      noEvents: 'ไม่มีกิจกรรม',
      allDay: 'ทั้งวัน',
      loading: 'กำลังโหลด...',
      error: 'เกิดข้อผิดพลาด',
      retry: 'ลองใหม่',
      previous: 'ก่อนหน้า',
      next: 'ถัดไป',
      year: 'ปี',
      selectMonth: 'เลือกเดือน',
      selectYear: 'เลือกปี',
      monthNames: [
        'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน',
        'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม',
        'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'
      ],
      monthNamesShort: [
        'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.',
        'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.',
        'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'
      ],
      dayNames: ['อาทิตย์', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์'],
      dayNamesShort: ['อา', 'จ', 'อ', 'พ', 'พฤ', 'ศ', 'ส']
    },
    en: {
      today: 'Today',
      month: 'Month',
      week: 'Week',
      day: 'Day',
      moreEvents: '+{count} more',
      noEvents: 'No events',
      allDay: 'All day',
      loading: 'Loading...',
      error: 'Error occurred',
      retry: 'Retry',
      previous: 'Previous',
      next: 'Next',
      year: 'Year',
      selectMonth: 'Select month',
      selectYear: 'Select year',
      monthNames: [
        'January', 'February', 'March', 'April',
        'May', 'June', 'July', 'August',
        'September', 'October', 'November', 'December'
      ],
      monthNamesShort: [
        'Jan', 'Feb', 'Mar', 'Apr',
        'May', 'Jun', 'Jul', 'Aug',
        'Sep', 'Oct', 'Nov', 'Dec'
      ],
      dayNames: ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
      dayNamesShort: ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']
    }
  },

  /**
   * Initialize EventCalendar
   */
  init(options = {}) {
    this.config = {...this.config, ...options};

    if (!this.state.lifecycleBound) {
      this.bindLifecycleHandlers();
      this.state.lifecycleBound = true;
    }

    this.discoverCalendars();

    this.state.initialized = true;
    return this;
  },

  /**
   * Discover calendar elements in the current DOM tree.
   */
  discoverCalendars(container = document) {
    if (!container) return;

    const elements = [];

    if (typeof container.matches === 'function' && container.matches('[data-event-calendar]')) {
      elements.push(container);
    }

    if (typeof container.querySelectorAll === 'function') {
      container.querySelectorAll('[data-event-calendar]').forEach(element => {
        elements.push(element);
      });
    }

    elements.forEach(element => {
      this.create(element);
    });
  },

  /**
   * Re-scan calendars after SPA content updates.
   */
  bindLifecycleHandlers() {
    const autoInit = () => {
      this.discoverCalendars();
    };

    const refreshLocale = () => {
      this.state.instances.forEach(instance => {
        this.syncInstanceLocale(instance);
        instance.segmentCache.clear();
        this.render(instance);
      });
    };

    if (window.EventManager?.on) {
      EventManager.on('route:changed', autoInit);
      EventManager.on('modal:shown', autoInit);
      EventManager.on('page:loaded', autoInit);
      EventManager.on('locale:changed', refreshLocale);
      EventManager.on('i18n:updated', refreshLocale);
    } else {
      document.addEventListener('route:changed', autoInit);
      document.addEventListener('modal:shown', autoInit);
      document.addEventListener('page:loaded', autoInit);
      document.addEventListener('locale:changed', refreshLocale);
      document.addEventListener('i18n:updated', refreshLocale);
    }

    // Recover from long-idle / visibility changes: re-discover calendars and re-render
    const visibilityHandler = () => {
      if (document.visibilityState === 'visible') {
        try {
          autoInit();
          this.state.instances.forEach(inst => {
            try {this.render(inst);} catch (e) {console.error('[EventCalendar] render error on visibility', e);}
          });
        } catch (e) {
          console.error('[EventCalendar] visibility handler error', e);
        }
      }
    };

    // pageshow covers bfcache restorations in some browsers
    window.addEventListener('pageshow', autoInit);
    document.addEventListener('visibilitychange', visibilityHandler);
  },

  /**
   * Cleanup an instance: remove listeners and detach from registry.
   */
  cleanupInstance(instance) {
    if (!instance) return;
    try {
      if (Array.isArray(instance.eventListeners)) {
        instance.eventListeners.forEach(l => {
          try {l.element.removeEventListener(l.event, l.handler, l.options);} catch (e) {}
        });
      }
    } catch (e) {
      // ignore
    }
    try {
      if (instance.element && instance.element._eventCalendar) {
        try {delete instance.element._eventCalendar;} catch (e) {instance.element._eventCalendar = undefined;}
      }
    } catch (e) {}
    try {this.state.instances.delete(instance.element);} catch (e) {}
  },

  /**
   * Validate configuration
   */
  validateConfig(config) {
    const errors = [];

    // Validate views
    if (!Array.isArray(config.views) || config.views.length === 0) {
      errors.push('views must be a non-empty array');
    } else {
      const validViews = ['month', 'week', 'day'];
      const invalidViews = config.views.filter(v => !validViews.includes(v));
      if (invalidViews.length > 0) {
        errors.push(`Invalid view(s): ${invalidViews.join(', ')}. Valid views are: ${validViews.join(', ')}`);
      }
    }

    // Validate defaultView
    if (!config.views.includes(config.defaultView)) {
      errors.push(`defaultView '${config.defaultView}' is not in views array`);
    }

    // Validate maxEventsPerDay
    if (!Number.isFinite(config.maxEventsPerDay) || config.maxEventsPerDay < 1) {
      errors.push('maxEventsPerDay must be a positive number');
    }

    // Validate firstDayOfWeek
    if (!Number.isInteger(config.firstDayOfWeek) || config.firstDayOfWeek < 0 || config.firstDayOfWeek > 6) {
      errors.push('firstDayOfWeek must be a number between 0 (Sunday) and 6 (Saturday)');
    }

    // Validate API URL if provided
    if (config.api && typeof config.api !== 'string') {
      errors.push('api must be a string URL');
    }

    // Validate apiMethod
    const validMethods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'];
    if (config.apiMethod && !validMethods.includes(config.apiMethod.toUpperCase())) {
      errors.push(`apiMethod must be one of: ${validMethods.join(', ')}`);
    }

    // Validate event colors
    if (!Array.isArray(config.eventColors) || config.eventColors.length === 0) {
      errors.push('eventColors must be a non-empty array');
    }

    const validScheduleModes = this.getSupportedScheduleModes();
    if (config.scheduleMode && !validScheduleModes.includes(config.scheduleMode)) {
      errors.push(`scheduleMode must be one of: ${validScheduleModes.join(', ')}`);
    }

    if (config.minDate && (!(config.minDate instanceof Date) || isNaN(config.minDate.getTime()))) {
      errors.push('minDate must be a valid date or YYYY-MM string');
    }

    if (config.maxDate && (!(config.maxDate instanceof Date) || isNaN(config.maxDate.getTime()))) {
      errors.push('maxDate must be a valid date or YYYY-MM string');
    }

    if (config.minDate && config.maxDate && config.minDate > config.maxDate) {
      errors.push('minDate must not be after maxDate');
    }

    if (!Number.isInteger(config.yearRangeBefore) || config.yearRangeBefore < 0) {
      errors.push('yearRangeBefore must be a non-negative integer');
    }

    if (!Number.isInteger(config.yearRangeAfter) || config.yearRangeAfter < 0) {
      errors.push('yearRangeAfter must be a non-negative integer');
    }

    // Log errors
    if (errors.length > 0) {
      console.error('[EventCalendar] Configuration errors:', errors);
      return false;
    }

    return true;
  },

  /**
   * Normalize instance configuration.
   */
  normalizeConfig(config) {
    return {
      ...config,
      scheduleMode: this.normalizeScheduleType(config.scheduleMode, 'continuous'),
      maxEventsPerDay: Number.isFinite(Number(config.maxEventsPerDay)) ? Math.max(1, parseInt(config.maxEventsPerDay, 10)) : 3,
      firstDayOfWeek: Number.isFinite(Number(config.firstDayOfWeek)) ? parseInt(config.firstDayOfWeek, 10) : 0,
      minDate: this.normalizeBoundaryDate(config.minDate, 'start'),
      maxDate: this.normalizeBoundaryDate(config.maxDate, 'end'),
      yearRangeBefore: Number.isFinite(Number(config.yearRangeBefore)) ? Math.max(0, parseInt(config.yearRangeBefore, 10)) : 10,
      yearRangeAfter: Number.isFinite(Number(config.yearRangeAfter)) ? Math.max(0, parseInt(config.yearRangeAfter, 10)) : 10
    };
  },

  /**
   * Supported event scheduling semantics.
   */
  getSupportedScheduleModes() {
    return ['continuous', 'recurring-slot'];
  },

  /**
   * Normalize a schedule mode value.
   */
  normalizeScheduleType(value, fallback = 'continuous') {
    const normalizedValue = String(value || '').trim().toLowerCase();
    return this.getSupportedScheduleModes().includes(normalizedValue) ? normalizedValue : fallback;
  },

  /**
   * Normalize a time-like input to HH:mm.
   */
  normalizeTimeValue(value) {
    if (value == null || value === '') {
      return null;
    }

    if (value instanceof Date) {
      return `${String(value.getHours()).padStart(2, '0')}:${String(value.getMinutes()).padStart(2, '0')}`;
    }

    const trimmed = String(value).trim();
    const timeMatch = trimmed.match(/^(\d{1,2}):(\d{2})(?::\d{2})?$/);
    if (timeMatch) {
      return `${timeMatch[1].padStart(2, '0')}:${timeMatch[2]}`;
    }

    const parsedDate = this.resolveDateValue(trimmed, trimmed.includes(':') ? 'datetime-local' : 'date');
    if (parsedDate instanceof Date && !isNaN(parsedDate.getTime())) {
      return `${String(parsedDate.getHours()).padStart(2, '0')}:${String(parsedDate.getMinutes()).padStart(2, '0')}`;
    }

    return null;
  },

  /**
   * Parse a normalized HH:mm string.
   */
  parseTimeParts(value) {
    const normalizedValue = this.normalizeTimeValue(value);
    if (!normalizedValue) {
      return null;
    }

    const [hours, minutes] = normalizedValue.split(':').map(Number);
    if (!Number.isInteger(hours) || !Number.isInteger(minutes)) {
      return null;
    }

    return {hours, minutes};
  },

  /**
   * Return the start of a calendar day.
   */
  getStartOfDay(date) {
    const result = this.resolveDateValue(date, 'date') || new Date();
    result.setHours(0, 0, 0, 0);
    return result;
  },

  /**
   * Return the end of a calendar day.
   */
  getEndOfDay(date) {
    const result = this.resolveDateValue(date, 'date') || new Date();
    result.setHours(23, 59, 59, 999);
    return result;
  },

  /**
   * Apply an HH:mm time to a specific date.
   */
  setTimeOnDate(date, timeValue) {
    const baseDate = this.resolveDateValue(date, 'date');
    const parts = this.parseTimeParts(timeValue);
    if (!baseDate || !parts) {
      return null;
    }

    baseDate.setHours(parts.hours, parts.minutes, 0, 0);
    return baseDate;
  },

  /**
   * Check whether a date string includes explicit timezone data.
   */
  hasExplicitTimezone(value) {
    return typeof value === 'string' && /(Z|[+-]\d{2}:?\d{2})$/i.test(value);
  },

  /**
   * Parse a date input safely across browsers.
   */
  resolveDateValue(value, type = 'datetime-local') {
    if (value == null || value === '') {
      return null;
    }

    if (value instanceof Date) {
      return isNaN(value.getTime()) ? null : new Date(value.getTime());
    }

    if (typeof value === 'number') {
      const date = new Date(value);
      return isNaN(date.getTime()) ? null : date;
    }

    const normalizedInput = typeof value === 'string' ? value.trim() : value;

    if (this.hasExplicitTimezone(normalizedInput)) {
      const timezoneAwareDate = new Date(normalizedInput);
      if (!isNaN(timezoneAwareDate.getTime())) {
        return timezoneAwareDate;
      }
    }

    if (window.Utils?.date?.parse) {
      const parsedDate = Utils.date.parse(normalizedInput, type);
      if (parsedDate instanceof Date && !isNaN(parsedDate.getTime())) {
        return parsedDate;
      }
    }

    const fallbackDate = new Date(normalizedInput);
    return isNaN(fallbackDate.getTime()) ? null : fallbackDate;
  },

  /**
   * Normalize min/max boundary values.
   */
  normalizeBoundaryDate(value, boundary = 'start') {
    if (!value) return null;

    if (value instanceof Date) {
      const date = new Date(value);
      if (isNaN(date.getTime())) {
        return null;
      }
      if (boundary === 'start') {
        date.setHours(0, 0, 0, 0);
      } else {
        date.setHours(23, 59, 59, 999);
      }
      return date;
    }

    if (typeof value === 'string') {
      const trimmed = value.trim();
      if (trimmed === '') {
        return null;
      }

      let date;
      if (/^\d{4}-\d{2}$/.test(trimmed)) {
        const [year, month] = trimmed.split('-').map(Number);
        date = boundary === 'start'
          ? new Date(year, month - 1, 1)
          : new Date(year, month, 0);
      } else if (/^\d{4}-\d{2}-\d{2}$/.test(trimmed)) {
        const [year, month, day] = trimmed.split('-').map(Number);
        date = new Date(year, month - 1, day);
      } else {
        date = this.resolveDateValue(trimmed, trimmed.includes(':') ? 'datetime-local' : 'date');
      }

      if (!(date instanceof Date) || isNaN(date.getTime())) {
        return null;
      }

      if (boundary === 'start') {
        date.setHours(0, 0, 0, 0);
      } else {
        date.setHours(23, 59, 59, 999);
      }

      return date;
    }

    return null;
  },

  /**
   * Create calendar instance
   */
  create(element, options = {}) {
    if (typeof element === 'string') {
      element = document.querySelector(element);
    }

    if (!element) {
      console.error('[EventCalendar] Element not found');
      return null;
    }

    // Return existing instance
    // Return existing instance when still valid; otherwise cleanup and recreate
    if (element._eventCalendar) {
      const existing = element._eventCalendar;
      try {
        if (document.contains(element) && existing && existing.contentElement && element.contains(existing.contentElement)) {
          return existing;
        }
      } catch (e) {
        // fall through to cleanup
      }
      // Element may have been removed/replaced or instance incomplete — cleanup stale instance
      try {this.cleanupInstance(existing);} catch (e) {}
    }

    // Extract data attributes
    const dataOptions = this.extractDataOptions(element);
    const config = this.normalizeConfig({...this.config, ...dataOptions, ...options});

    // Validate configuration
    if (!this.validateConfig(config)) {
      console.error('[EventCalendar] Failed to create calendar due to invalid configuration');
      return null;
    }

    // Determine locale
    config.localePreference = config.locale;
    config.locale = this.resolveLocale(config.localePreference);

    const instance = {
      element,
      config,
      currentDate: new Date(),
      currentView: config.defaultView,
      events: [],
      eventSegments: [],
      isLoading: false,
      error: null,
      // Event listeners cleanup tracking
      eventListeners: [],
      // Performance caching
      segmentCache: new Map()
    };

    instance.currentDate = this.clampDateForView(instance, instance.currentDate, instance.currentView);

    element._eventCalendar = instance;
    this.state.instances.set(element, instance);

    // Setup DOM structure
    this.setupCalendar(instance);

    // Load events if API configured
    if (config.api) {
      this.loadEvents(instance);
    } else {
      this.render(instance);
    }

    return instance;
  },

  /**
   * Extract options from data attributes
   */
  extractDataOptions(element) {
    const dataset = element.dataset;
    const options = {};

    if (dataset.view) options.defaultView = dataset.view;
    if (dataset.locale) options.locale = dataset.locale;
    if (dataset.scheduleMode) options.scheduleMode = dataset.scheduleMode;
    if (dataset.firstDay) options.firstDayOfWeek = parseInt(dataset.firstDay);
    if (dataset.maxEvents) options.maxEventsPerDay = parseInt(dataset.maxEvents);
    if (dataset.api) options.api = dataset.api;
    if (dataset.apiMethod) options.apiMethod = dataset.apiMethod;
    if (dataset.eventDataPath) options.eventDataPath = dataset.eventDataPath;
    if (dataset.onDateClick) options.onDateClick = dataset.onDateClick;
    if (dataset.onEventClick) options.onEventClick = dataset.onEventClick;
    if (dataset.onEventClickApi) options.onEventClickApi = dataset.onEventClickApi;
    if (dataset.minDate) options.minDate = dataset.minDate;
    if (dataset.maxDate) options.maxDate = dataset.maxDate;
    if (dataset.yearRangeBefore) options.yearRangeBefore = parseInt(dataset.yearRangeBefore, 10);
    if (dataset.yearRangeAfter) options.yearRangeAfter = parseInt(dataset.yearRangeAfter, 10);
    if (dataset.showNavigation !== undefined) options.showNavigation = dataset.showNavigation !== 'false';
    if (dataset.showToday !== undefined) options.showToday = dataset.showToday !== 'false';
    if (dataset.showViewSwitcher !== undefined) options.showViewSwitcher = dataset.showViewSwitcher !== 'false';
    if (dataset.showPeriodPicker !== undefined) options.showPeriodPicker = dataset.showPeriodPicker !== 'false';
    if (dataset.views) options.views = dataset.views.split(',').map(v => v.trim());

    // Parse inline events
    if (dataset.events) {
      try {
        options.events = JSON.parse(dataset.events);
      } catch (e) {
        console.warn('[EventCalendar] Invalid events JSON');
      }
    }

    return options;
  },

  /**
   * Add event listener with cleanup tracking
   */
  addTrackedListener(instance, element, event, handler, options) {
    element.addEventListener(event, handler, options);
    instance.eventListeners.push({element, event, handler, options});
  },

  /**
   * Setup event delegation for dynamic content
   */
  setupEventDelegation(instance) {
    const {element} = instance;

    // Delegate click events
    const delegateHandler = (e) => {
      const target = e.target;

      // Event bar clicks
      if (target.closest('.ec-event-bar')) {
        e.stopPropagation();
        const bar = target.closest('.ec-event-bar');
        const eventId = bar.dataset.eventId;
        if (eventId) {
          const event = instance.events.find(ev => ev.id === eventId);
          if (event) {
            this.handleEventClick(instance, event, e);
          }
        }
        return;
      }

      // Month cell event clicks
      if (target.closest('.ec-day-event')) {
        e.stopPropagation();
        const eventEl = target.closest('.ec-day-event');
        const eventId = eventEl.dataset.eventId;
        if (eventId) {
          const event = instance.events.find(ev => ev.id === eventId);
          if (event) {
            this.handleEventClick(instance, event, e);
          }
        }
        return;
      }

      // Timed event clicks
      if (target.closest('.ec-timed-event')) {
        e.stopPropagation();
        const eventEl = target.closest('.ec-timed-event');
        const eventId = eventEl.dataset.eventId;
        if (eventId) {
          const event = instance.events.find(ev => ev.id === eventId);
          if (event) {
            this.handleEventClick(instance, event, e);
          }
        }
        return;
      }

      // All-day event clicks
      if (target.closest('.ec-allday-event')) {
        const eventEl = target.closest('.ec-allday-event');
        const eventId = eventEl.dataset.eventId;
        if (eventId) {
          const event = instance.events.find(ev => ev.id === eventId);
          if (event) {
            this.handleEventClick(instance, event, e);
          }
        }
        return;
      }

      // Hour slot clicks (for week/day view)
      if (target.closest('.ec-hour-slot')) {
        const slot = target.closest('.ec-hour-slot');
        const hour = parseInt(slot.dataset.hour);
        const column = slot.closest('.ec-day-column');
        if (column) {
          const dateStr = column.dataset.date;
          const clickDate = this.resolveDateValue(dateStr, 'date') || new Date(instance.currentDate);
          clickDate.setHours(hour);
          this.handleDateClick(instance, clickDate, e);
        }
        return;
      }

      // Hour area clicks (day view)
      if (target.closest('.ec-hour-area')) {
        const area = target.closest('.ec-hour-area');
        const slot = area.closest('.ec-hour-slot');
        const hour = parseInt(slot.dataset.hour);
        const clickDate = new Date(instance.currentDate);
        clickDate.setHours(hour);
        this.handleDateClick(instance, clickDate, e);
        return;
      }
    };

    this.addTrackedListener(instance, element, 'click', delegateHandler);
  },

  /**
   * Setup keyboard navigation
   */
  setupKeyboardNavigation(instance) {
    const {element} = instance;

    // Make calendar focusable
    if (!element.hasAttribute('tabindex')) {
      element.setAttribute('tabindex', '0');
    }

    const keyboardHandler = (e) => {
      const {currentDate, currentView} = instance;

      switch (e.key) {
        case 'ArrowLeft':
          e.preventDefault();
          this.navigate(instance, -1);
          break;

        case 'ArrowRight':
          e.preventDefault();
          this.navigate(instance, 1);
          break;

        case 'Home':
          e.preventDefault();
          this.goToToday(instance);
          break;

        case 't':
        case 'T':
          if (!e.ctrlKey && !e.metaKey) {
            e.preventDefault();
            this.goToToday(instance);
          }
          break;

        case 'm':
        case 'M':
          if (!e.ctrlKey && !e.metaKey && instance.config.views.includes('month')) {
            e.preventDefault();
            this.changeView(instance, 'month');
          }
          break;

        case 'w':
        case 'W':
          if (!e.ctrlKey && !e.metaKey && instance.config.views.includes('week')) {
            e.preventDefault();
            this.changeView(instance, 'week');
          }
          break;

        case 'd':
        case 'D':
          if (!e.ctrlKey && !e.metaKey && instance.config.views.includes('day')) {
            e.preventDefault();
            this.changeView(instance, 'day');
          }
          break;
      }
    };

    this.addTrackedListener(instance, element, 'keydown', keyboardHandler);
  },

  /**
   * Setup calendar DOM structure
   */
  setupCalendar(instance) {
    const {element, config} = instance;

    element.classList.add('event-calendar');
    element.setAttribute('role', 'application');
    element.setAttribute('aria-label', 'Event Calendar');
    element.innerHTML = '';

    // Setup event delegation for dynamically created elements
    this.setupEventDelegation(instance);

    // Setup keyboard navigation
    this.setupKeyboardNavigation(instance);

    // Create header
    const header = document.createElement('div');
    header.className = 'ec-header';

    // Navigation
    if (config.showNavigation) {
      const nav = this.createNavigation(instance);
      header.appendChild(nav);
    }

    // View switcher
    if (config.showViewSwitcher && config.views.length > 1) {
      const switcher = this.createViewSwitcher(instance);
      header.appendChild(switcher);
    }

    element.appendChild(header);
    instance.headerElement = header;

    // Content area
    const content = document.createElement('div');
    content.className = 'ec-content';
    element.appendChild(content);
    instance.contentElement = content;
  },

  /**
   * Create navigation controls
   */
  createNavigation(instance) {
    const {config} = instance;
    const strings = this.getLocaleStrings(config.locale);

    const nav = document.createElement('div');
    nav.className = 'ec-nav';

    // Previous button
    const prevBtn = document.createElement('button');
    prevBtn.className = 'ec-nav-btn ec-prev';
    prevBtn.setAttribute('aria-label', strings.previous || 'Previous');
    prevBtn.setAttribute('type', 'button');
    prevBtn.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z"/></svg>';
    this.addTrackedListener(instance, prevBtn, 'click', () => this.navigate(instance, -1));
    instance.prevButtonElement = prevBtn;

    // Current period display
    const current = document.createElement('div');
    current.className = 'ec-current-period';
    current.setAttribute('role', 'status');
    current.setAttribute('aria-live', 'polite');

    const currentLabel = document.createElement('span');
    currentLabel.className = 'ec-current-period-label';
    current.appendChild(currentLabel);

    instance.currentPeriodElement = current;
    instance.currentPeriodLabelElement = currentLabel;

    if (config.showPeriodPicker) {
      current.appendChild(this.createPeriodPicker(instance));
    }

    // Next button
    const nextBtn = document.createElement('button');
    nextBtn.className = 'ec-nav-btn ec-next';
    nextBtn.setAttribute('aria-label', strings.next || 'Next');
    nextBtn.setAttribute('type', 'button');
    nextBtn.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M8.59 16.59L10 18l6-6-6-6-1.41 1.41L13.17 12z"/></svg>';
    this.addTrackedListener(instance, nextBtn, 'click', () => this.navigate(instance, 1));
    instance.nextButtonElement = nextBtn;

    nav.appendChild(prevBtn);
    nav.appendChild(current);
    nav.appendChild(nextBtn);

    // Today button
    if (config.showToday) {
      const todayBtn = document.createElement('button');
      todayBtn.className = 'ec-today-btn';
      todayBtn.setAttribute('type', 'button');
      todayBtn.setAttribute('aria-label', strings.today);
      todayBtn.textContent = strings.today;
      this.addTrackedListener(instance, todayBtn, 'click', () => this.goToToday(instance));
      nav.appendChild(todayBtn);
      instance.todayButtonElement = todayBtn;
    }

    return nav;
  },

  /**
   * Create period picker controls for month view.
   */
  createPeriodPicker(instance) {
    const {config} = instance;
    const strings = this.getLocaleStrings(config.locale);

    const picker = document.createElement('div');
    picker.className = 'ec-period-picker';
    picker.hidden = true;

    const monthSelect = document.createElement('select');
    monthSelect.className = 'ec-period-select ec-period-month';
    monthSelect.setAttribute('aria-label', strings.selectMonth || strings.month);
    this.addTrackedListener(instance, monthSelect, 'change', () => this.handlePeriodPickerChange(instance));

    const yearSelect = document.createElement('select');
    yearSelect.className = 'ec-period-select ec-period-year';
    yearSelect.setAttribute('aria-label', strings.selectYear || strings.year);
    this.addTrackedListener(instance, yearSelect, 'change', () => this.handleYearPickerChange(instance));

    picker.appendChild(monthSelect);
    picker.appendChild(yearSelect);

    instance.periodPickerElement = picker;
    instance.monthSelectElement = monthSelect;
    instance.yearSelectElement = yearSelect;

    return picker;
  },

  /**
   * Create view switcher
   */
  createViewSwitcher(instance) {
    const {config} = instance;
    const strings = this.getLocaleStrings(config.locale);

    const switcher = document.createElement('div');
    switcher.className = 'ec-view-switcher';

    const viewLabels = {
      month: strings.month,
      week: strings.week,
      day: strings.day
    };

    config.views.forEach(view => {
      const btn = document.createElement('button');
      btn.className = `ec-view-btn ${view === instance.currentView ? 'active' : ''}`;
      btn.dataset.view = view;
      btn.textContent = viewLabels[view] || view;
      this.addTrackedListener(instance, btn, 'click', () => this.changeView(instance, view));
      switcher.appendChild(btn);
    });

    instance.viewSwitcherElement = switcher;
    return switcher;
  },

  /**
   * Update header labels when locale changes.
   */
  updateHeaderText(instance) {
    const strings = this.getLocaleStrings(instance.config.locale);

    if (instance.prevButtonElement) {
      instance.prevButtonElement.setAttribute('aria-label', strings.previous || 'Previous');
    }

    if (instance.nextButtonElement) {
      instance.nextButtonElement.setAttribute('aria-label', strings.next || 'Next');
    }

    if (instance.todayButtonElement) {
      instance.todayButtonElement.setAttribute('aria-label', strings.today);
      instance.todayButtonElement.textContent = strings.today;
    }

    if (instance.monthSelectElement) {
      instance.monthSelectElement.setAttribute('aria-label', strings.selectMonth || strings.month);
    }

    if (instance.yearSelectElement) {
      instance.yearSelectElement.setAttribute('aria-label', strings.selectYear || strings.year);
    }

    if (instance.viewSwitcherElement) {
      const viewLabels = {
        month: strings.month,
        week: strings.week,
        day: strings.day
      };

      instance.viewSwitcherElement.querySelectorAll('.ec-view-btn').forEach(btn => {
        btn.textContent = viewLabels[btn.dataset.view] || btn.dataset.view;
      });
    }
  },

  /**
   * Load events from API
   */
  async loadEvents(instance) {
    const {config} = instance;

    if (!config.api) return;

    instance.isLoading = true;
    this.renderLoading(instance);

    try {
      const viewRange = this.getViewDateRange(instance);
      const params = new URLSearchParams({
        start: this.formatDateISO(viewRange.start),
        end: this.formatDateISO(viewRange.end)
      });

      const url = config.api.includes('?')
        ? `${config.api}&${params}`
        : `${config.api}?${params}`;

      let response;

      if (window.ApiService && typeof ApiService.get === 'function') {
        response = await ApiService.get(url);
      } else {
        const requestOptions = Now.applyRequestLanguage({method: config.apiMethod});
        const res = await fetch(url, requestOptions);
        response = await res.json();
      }

      const events = this.extractEventsFromResponse(response, config.eventDataPath);
      instance.events = this.normalizeEvents(events, config);
      instance.segmentCache.clear();
      instance.error = null;

    } catch (error) {
      console.error('[EventCalendar] Failed to load events:', error);
      instance.error = error.message;
      instance.events = [];
    } finally {
      instance.isLoading = false;
      this.render(instance);
    }
  },

  /**
   * Parse date with timezone awareness
   */
  parseDate(dateString, config) {
    if (!dateString) return new Date();

    const normalizedInput = typeof dateString === 'string' ? dateString.trim() : dateString;
    const parseType = typeof normalizedInput === 'string' && normalizedInput.includes(':') ? 'datetime-local' : 'date';
    const date = this.resolveDateValue(normalizedInput, parseType) || new Date();

    // If timezone is UTC and date string doesn't include timezone info
    if (
      config.timezone === 'UTC' &&
      typeof normalizedInput === 'string' &&
      !this.hasExplicitTimezone(normalizedInput)
    ) {
      // Treat as UTC
      return new Date(date.getTime() + (date.getTimezoneOffset() * 60000));
    }

    return date;
  },

  /**
   * Format date for API (ISO format)
   */
  formatDateForAPI(date, config) {
    if (config.timezone === 'UTC') {
      return date.toISOString();
    }
    return this.formatDateISO(date);
  },

  /**
   * Normalize events data
   */
  normalizeEvents(events, config = this.config) {
    // Handle non-array input
    if (!Array.isArray(events)) {
      console.warn('[EventCalendar] Events data is not an array, received:', typeof events);
      return [];
    }

    return events.map((event, index) => {
      // Validate required fields
      if (!event) {
        console.warn('[EventCalendar] Null event at index', index);
        return null;
      }

      // Parse dates safely with timezone awareness
      let startDate, endDate;
      try {
        startDate = event.start ? this.parseDate(event.start, config) : new Date();
        if (isNaN(startDate.getTime())) {
          console.warn('[EventCalendar] Invalid start date for event:', event);
          startDate = new Date();
        }
      } catch (e) {
        console.warn('[EventCalendar] Error parsing start date:', e);
        startDate = new Date();
      }

      try {
        endDate = event.end ? this.parseDate(event.end, config) : new Date(startDate);
        if (isNaN(endDate.getTime())) {
          console.warn('[EventCalendar] Invalid end date for event:', event);
          endDate = new Date(startDate);
        }
      } catch (e) {
        console.warn('[EventCalendar] Error parsing end date:', e);
        endDate = new Date(startDate);
      }

      // Ensure end is not before start
      if (endDate < startDate) {
        console.warn('[EventCalendar] End date is before start date, swapping');
        [startDate, endDate] = [endDate, startDate];
      }

      const scheduleType = this.normalizeScheduleType(
        event.scheduleType || event.scheduleMode,
        config.scheduleMode || 'continuous'
      );

      let rangeStart = this.normalizeBoundaryDate(
        event.startDate || event.rangeStart || event.start || startDate,
        'start'
      ) || this.getStartOfDay(startDate);
      let rangeEnd = this.normalizeBoundaryDate(
        event.endDate || event.rangeEnd || event.end || endDate,
        'end'
      ) || this.getEndOfDay(endDate);

      if (rangeEnd < rangeStart) {
        [rangeStart, rangeEnd] = [rangeEnd, rangeStart];
      }

      const slotStartTime = scheduleType === 'recurring-slot'
        ? (this.normalizeTimeValue(event.slotStartTime) || this.normalizeTimeValue(startDate))
        : this.normalizeTimeValue(event.slotStartTime);
      const slotEndTime = scheduleType === 'recurring-slot'
        ? (this.normalizeTimeValue(event.slotEndTime) || this.normalizeTimeValue(endDate))
        : this.normalizeTimeValue(event.slotEndTime);

      return {
        id: event.id || `event-${index}-${Date.now()}`,
        title: event.title || 'Untitled',
        start: startDate,
        end: endDate,
        allDay: scheduleType === 'recurring-slot' ? false : event.allDay !== false,
        color: event.color || config.eventColors[index % config.eventColors.length],
        scheduleType,
        rangeStart,
        rangeEnd,
        slotStartTime,
        slotEndTime,
        category: event.category || null,
        description: event.description || null,
        location: event.location || null,
        data: event // Keep original data
      };
    }).filter(event => event !== null); // Remove null events
  },

  /**
   * Main render function
   */
  render(instance) {
    this.syncInstanceLocale(instance);
    this.updateHeaderText(instance);

    // Show error state if any
    if (instance.error) {
      this.renderError(instance);
      return;
    }

    // Show loading state
    if (instance.isLoading) {
      this.renderLoading(instance);
      return;
    }

    this.updateCurrentPeriod(instance);
    this.updateNavigationState(instance);
    this.updateViewSwitcher(instance);

    switch (instance.currentView) {
      case 'month':
        this.renderMonthView(instance);
        break;
      case 'week':
        this.renderWeekView(instance);
        break;
      case 'day':
        this.renderDayView(instance);
        break;
    }
  },

  /**
   * Render error state
   */
  renderError(instance) {
    const {contentElement, config, error} = instance;
    const strings = this.getLocaleStrings(config.locale);

    contentElement.innerHTML = `
      <div class="ec-error" role="alert">
        <div class="ec-error-icon">⚠️</div>
        <div class="ec-error-title">${strings.error}</div>
        <div class="ec-error-message">${this.escapeHtml(error)}</div>
        <button type="button" class="ec-retry-btn">${strings.retry || 'Retry'}</button>
      </div>
    `;

    // Add retry handler
    const retryBtn = contentElement.querySelector('.ec-retry-btn');
    if (retryBtn) {
      this.addTrackedListener(instance, retryBtn, 'click', () => {
        instance.error = null;
        if (instance.config.api) {
          this.loadEvents(instance);
        } else {
          this.render(instance);
        }
      });
    }
  },

  /**
   * Render loading state
   */
  renderLoading(instance) {
    const {contentElement, config} = instance;
    const strings = this.getLocaleStrings(config.locale);

    contentElement.innerHTML = `
      <div class="ec-loading">
        <div class="ec-spinner"></div>
        <span>${strings.loading}</span>
      </div>
    `;
  },

  /**
   * Render month view
   */
  renderMonthView(instance) {
    const {contentElement, currentDate, config, events} = instance;
    const strings = this.getLocaleStrings(config.locale);
    const monthViewMetrics = this.getMonthViewLayoutMetrics(instance);

    // Use DocumentFragment for better performance
    const fragment = document.createDocumentFragment();
    contentElement.className = 'ec-content ec-month-view';

    // Create grid container
    const grid = document.createElement('div');
    grid.className = 'ec-month-grid';
    grid.setAttribute('role', 'grid');
    grid.setAttribute('aria-label', 'Calendar month view');

    // Day headers - use DocumentFragment
    const headerRow = document.createElement('div');
    headerRow.className = 'ec-weekday-header';
    const headerFragment = document.createDocumentFragment();

    for (let i = 0; i < 7; i++) {
      const dayIndex = (config.firstDayOfWeek + i) % 7;
      const header = document.createElement('div');
      header.className = 'ec-weekday';
      header.textContent = strings.dayNamesShort[dayIndex];
      headerFragment.appendChild(header);
    }
    headerRow.appendChild(headerFragment);
    grid.appendChild(headerRow);

    // Calculate grid dates
    const monthStart = new Date(currentDate.getFullYear(), currentDate.getMonth(), 1);

    // Grid start (might be previous month)
    const gridStart = new Date(monthStart);
    const startDayOffset = (monthStart.getDay() - config.firstDayOfWeek + 7) % 7;
    gridStart.setDate(gridStart.getDate() - startDayOffset);

    // Get spanning event segments for this view
    const segments = this.calculateSpanningSegments(instance, gridStart, 42);

    // Create 6 week rows
    for (let week = 0; week < 6; week++) {
      const weekRow = document.createElement('div');
      weekRow.className = 'ec-week-row';

      const weekStart = new Date(gridStart);
      weekStart.setDate(gridStart.getDate() + (week * 7));
      const weekSegments = this.getWeekSegments(segments, weekStart);
      const weekSpacing = this.getMonthViewWeekSpacing(instance, weekSegments, monthViewMetrics);
      const hiddenSpanningCounts = this.getHiddenSpanningCountsByDayOffset(weekSegments, weekSpacing.maxRows);

      // Event container for spanning events
      const eventLayer = document.createElement('div');
      eventLayer.className = 'ec-event-layer';

      // Day cells
      const dayLayer = document.createElement('div');
      dayLayer.className = 'ec-day-layer';
      const dayFragment = document.createDocumentFragment();

      for (let day = 0; day < 7; day++) {
        const cellDate = new Date(gridStart);
        cellDate.setDate(gridStart.getDate() + (week * 7) + day);

        const cell = this.createDayCell(instance, cellDate, monthStart.getMonth(), {
          hiddenSpanningCount: hiddenSpanningCounts.get(day) || 0
        });
        dayFragment.appendChild(cell);
      }
      dayLayer.appendChild(dayFragment);

      // Render spanning events for this week
      weekRow.style.setProperty('--ec-month-bar-space', `${weekSpacing.spacer}px`);
      this.renderWeekEventBars(instance, eventLayer, weekSegments, monthViewMetrics, weekSpacing.maxRows);

      weekRow.appendChild(eventLayer);
      weekRow.appendChild(dayLayer);
      grid.appendChild(weekRow);
    }

    fragment.appendChild(grid);

    // Clear and update DOM once
    contentElement.innerHTML = '';
    contentElement.appendChild(fragment);
  },

  /**
   * Create a day cell for month view
   */
  createDayCell(instance, date, currentMonth, options = {}) {
    const {config, events} = instance;
    const today = new Date();
    const hiddenSpanningCount = Math.max(0, Number(options.hiddenSpanningCount) || 0);

    const cell = document.createElement('div');
    cell.className = 'ec-day-cell';
    cell.dataset.date = this.formatDateISO(date);
    cell.setAttribute('role', 'gridcell');
    cell.setAttribute('aria-label', this.formatDate(date, config.locale));
    cell.setAttribute('tabindex', '-1');

    // Add classes
    if (this.isSameDay(date, today)) {
      cell.classList.add('ec-today');
    }
    if (date.getMonth() !== currentMonth) {
      cell.classList.add('ec-other-month');
    }
    if (date.getDay() === 0 || date.getDay() === 6) {
      cell.classList.add('ec-weekend');
    }

    // Day number
    const dayNum = document.createElement('div');
    dayNum.className = 'ec-day-number';
    dayNum.textContent = date.getDate();
    cell.appendChild(dayNum);

    // Count events for this day
    const dayEvents = this.getEventsForDate(events, date);
    const visibleEvents = this.getMonthInlineEventsForDate(dayEvents, date);
    const visibleEventsContainer = document.createElement('div');
    visibleEventsContainer.className = 'ec-day-events';
    const inlineEvents = visibleEvents.slice(0, config.maxEventsPerDay);

    if (dayEvents.length > 0) {
      cell.classList.add('has-events');
    }

    inlineEvents.forEach(event => {
      visibleEventsContainer.appendChild(this.createDayEventElement(instance, event, date));
    });

    if (visibleEventsContainer.childNodes.length > 0) {
      cell.appendChild(visibleEventsContainer);
    }

    const inlineShownCount = inlineEvents.length;
    const overflowCount = Math.max(0, visibleEvents.length - inlineShownCount) + hiddenSpanningCount;

    if (overflowCount > 0) {
      const moreIndicator = document.createElement('div');
      moreIndicator.className = 'ec-more-events';
      moreIndicator.textContent = this.formatMoreEventsLabel(config.locale, overflowCount);
      const moreHandler = (e) => {
        e.stopPropagation();
        this.showEventsModal(instance, date, dayEvents);
      };
      this.addTrackedListener(instance, moreIndicator, 'click', moreHandler);
      cell.appendChild(moreIndicator);
    }

    // Click handler
    const cellClickHandler = (e) => {
      if (e.target.closest('.ec-day-event')) {
        return;
      }
      this.handleDateClick(instance, date, e);
    };
    this.addTrackedListener(instance, cell, 'click', cellClickHandler);

    // Mobile: tap to show day detail
    const isMobile = window.Utils?.browser?.isMobile?.() ||
      /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
    if (isMobile) {
      const mobileClickHandler = (e) => {
        if (e.target.closest('.ec-day-event')) {
          return;
        }
        if (dayEvents.length > 0) {
          e.stopPropagation();
          this.showDayDetailModal(instance, date, dayEvents);
        }
      };
      this.addTrackedListener(instance, cell, 'click', mobileClickHandler);
    }

    return cell;
  },

  /**
   * Create a month cell event item for single-day timed events.
   */
  createDayEventElement(instance, event, date = null) {
    const eventEl = document.createElement('div');
    eventEl.className = 'ec-day-event';
    eventEl.dataset.eventId = event.id;
    eventEl.style.setProperty('--ec-day-event-color', event.color || this.config.eventColors[0]);
    eventEl.classList.add(`ec-schedule-${event.scheduleType || 'continuous'}`);

    const titleEl = document.createElement('span');
    titleEl.className = 'ec-day-event-title';
    titleEl.textContent = this.getMonthCellEventLabel(event, date);

    eventEl.title = this.getMonthCellEventTitle(event, date, instance.config.locale);
    eventEl.appendChild(titleEl);

    return eventEl;
  },

  /**
   * Determine whether an event uses recurring slot semantics.
   */
  isRecurringSlotEvent(event) {
    return event?.scheduleType === 'recurring-slot';
  },

  /**
   * Get normalized inclusive date bounds for an event.
   */
  getEventRangeBounds(event) {
    if (!event) {
      return {
        start: null,
        end: null
      };
    }

    return {
      start: this.getStartOfDay(event.rangeStart || event.start),
      end: this.getEndOfDay(event.rangeEnd || event.end)
    };
  },

  /**
   * Check whether an event occurs on a specific date.
   */
  occursOnDate(event, date) {
    const bounds = this.getEventRangeBounds(event);
    if (!bounds.start || !bounds.end) {
      return false;
    }

    const checkDate = this.getStartOfDay(date);
    return checkDate >= bounds.start && checkDate <= bounds.end;
  },

  /**
   * Determine whether an event should render inline inside a month cell.
   */
  shouldRenderMonthInlineEvent(event, date) {
    if (!event || event.allDay || !this.occursOnDate(event, date)) {
      return false;
    }

    if (this.isRecurringSlotEvent(event)) {
      return true;
    }

    return this.isSameDay(event.start, event.end);
  },

  /**
   * Get the inline month events for a specific date.
   */
  getMonthInlineEventsForDate(events, date) {
    return events
      .filter(event => this.shouldRenderMonthInlineEvent(event, date))
      .sort((eventA, eventB) => {
        const occurrenceA = this.getTimedOccurrenceForDate(eventA, date, 'month');
        const occurrenceB = this.getTimedOccurrenceForDate(eventB, date, 'month');
        const timeA = occurrenceA?.start?.getTime() ?? eventA.start.getTime();
        const timeB = occurrenceB?.start?.getTime() ?? eventB.start.getTime();

        if (timeA !== timeB) {
          return timeA - timeB;
        }

        return String(eventA.title || '').localeCompare(String(eventB.title || ''));
      });
  },

  /**
   * Format the compact label shown in a month cell.
   */
  getMonthCellEventLabel(event, date = null) {
    const title = String(event?.title || '');
    const occurrence = date ? this.getTimedOccurrenceForDate(event, date, 'month') : null;
    const timeLabel = occurrence ? this.formatTime(occurrence.start) : '';

    return timeLabel && !title.includes(timeLabel)
      ? `${timeLabel} ${title}`.trim()
      : title;
  },

  /**
   * Build the title/tooltip text for a month-cell event item.
   */
  getMonthCellEventTitle(event, date = null, locale = 'en') {
    const title = String(event?.title || '');
    const timeText = this.getEventDisplayTimeText(event, locale, date);
    return timeText ? `${title} (${timeText})` : title;
  },

  /**
   * Determine whether an event should occupy the all-day lane.
   */
  shouldRenderAllDayLaneEvent(event) {
    return Boolean(event) && (event.allDay || (!this.isRecurringSlotEvent(event) && !this.isSameDay(event.start, event.end)));
  },

  /**
   * Resolve the timed occurrence of an event for a specific date.
   */
  getTimedOccurrenceForDate(event, date, view = 'week') {
    if (!event || event.allDay || !this.occursOnDate(event, date)) {
      return null;
    }

    if (this.isRecurringSlotEvent(event)) {
      const occurrenceStart = this.setTimeOnDate(date, event.slotStartTime) || new Date(event.start);
      let occurrenceEnd = this.setTimeOnDate(date, event.slotEndTime) || new Date(event.end);

      if (occurrenceEnd <= occurrenceStart) {
        occurrenceEnd = new Date(occurrenceStart);
        occurrenceEnd.setMinutes(occurrenceEnd.getMinutes() + 30);
      }

      return {
        event,
        start: occurrenceStart,
        end: occurrenceEnd,
        scheduleType: event.scheduleType
      };
    }

    if (this.isSameDay(event.start, event.end)) {
      return {
        event,
        start: new Date(event.start),
        end: new Date(event.end),
        scheduleType: event.scheduleType
      };
    }

    if (this.isSameDay(event.start, date)) {
      return {
        event,
        start: new Date(event.start),
        end: this.getEndOfDay(date),
        scheduleType: event.scheduleType,
        isStartSlice: true
      };
    }

    if (this.isSameDay(event.end, date)) {
      return {
        event,
        start: this.getStartOfDay(date),
        end: new Date(event.end),
        scheduleType: event.scheduleType,
        isEndSlice: true
      };
    }

    return view === 'day'
      ? null
      : null;
  },

  /**
   * Resolve all timed occurrences visible on a specific date.
   */
  getTimedOccurrencesForDate(events, date, view = 'week') {
    return events
      .map(event => this.getTimedOccurrenceForDate(event, date, view))
      .filter(Boolean)
      .sort((occurrenceA, occurrenceB) => occurrenceA.start.getTime() - occurrenceB.start.getTime());
  },

  /**
   * Assign columns to overlapping timed occurrences.
   */
  layoutTimedOccurrences(occurrences) {
    if (!Array.isArray(occurrences) || occurrences.length === 0) {
      return [];
    }

    const sortedOccurrences = occurrences
      .map((occurrence, index) => ({
        ...occurrence,
        _layoutIndex: index,
        _startMs: occurrence.start.getTime(),
        _endMs: occurrence.end.getTime()
      }))
      .sort((occurrenceA, occurrenceB) => {
        if (occurrenceA._startMs !== occurrenceB._startMs) {
          return occurrenceA._startMs - occurrenceB._startMs;
        }

        return occurrenceB._endMs - occurrenceA._endMs;
      });

    const laidOutOccurrences = [];
    let cluster = [];
    let clusterEnd = -Infinity;

    const finalizeCluster = () => {
      if (cluster.length === 0) {
        return;
      }

      const columns = [];
      let columnCount = 0;

      cluster.forEach(occurrence => {
        let columnIndex = 0;

        while (columns[columnIndex] && columns[columnIndex] > occurrence._startMs) {
          columnIndex++;
        }

        columns[columnIndex] = occurrence._endMs;
        occurrence._columnIndex = columnIndex;
        columnCount = Math.max(columnCount, columnIndex + 1);
      });

      cluster.forEach(occurrence => {
        laidOutOccurrences.push({
          ...occurrence,
          _columnCount: columnCount
        });
      });

      cluster = [];
      clusterEnd = -Infinity;
    };

    sortedOccurrences.forEach(occurrence => {
      if (cluster.length === 0 || occurrence._startMs < clusterEnd) {
        cluster.push(occurrence);
        clusterEnd = Math.max(clusterEnd, occurrence._endMs);
      } else {
        finalizeCluster();
        cluster = [occurrence];
        clusterEnd = occurrence._endMs;
      }
    });

    finalizeCluster();

    return laidOutOccurrences.sort((occurrenceA, occurrenceB) => occurrenceA._layoutIndex - occurrenceB._layoutIndex);
  },

  /**
   * Check whether an event should be rendered as a spanning bar.
   */
  shouldRenderSpanningEvent(event) {
    return this.shouldRenderAllDayLaneEvent(event);
  },

  /**
   * Calculate spanning event segments
   */
  calculateSpanningSegments(instance, gridStart, totalDays) {
    const {events, config, segmentCache} = instance;

    // Create cache key based on events and date range
    const cacheKey = `${this.formatDateISO(gridStart)}-${totalDays}-${events.length}`;

    // Check cache
    if (segmentCache.has(cacheKey)) {
      return segmentCache.get(cacheKey);
    }

    const segments = [];

    const gridEnd = new Date(gridStart);
    gridEnd.setDate(gridStart.getDate() + totalDays - 1);

    events.forEach(event => {
      if (!this.shouldRenderSpanningEvent(event)) return;

      const eventBounds = this.getEventRangeBounds(event);
      if (!eventBounds.start || !eventBounds.end) return;

      // Skip events outside view range
      if (eventBounds.end < gridStart || eventBounds.start > gridEnd) return;

      // Split into week segments
      const eventSegments = this.splitEventIntoWeekSegments(
        event,
        gridStart,
        gridEnd,
        config.firstDayOfWeek
      );
      segments.push(...eventSegments);
    });

    // Assign row positions
    const result = this.assignRowPositions(segments, config.firstDayOfWeek);

    // Cache result (limit cache size)
    if (segmentCache.size > 10) {
      const firstKey = segmentCache.keys().next().value;
      segmentCache.delete(firstKey);
    }
    segmentCache.set(cacheKey, result);

    return result;
  },

  /**
   * Split event into weekly segments
   */
  splitEventIntoWeekSegments(event, viewStart, viewEnd, firstDayOfWeek) {
    const segments = [];
    const eventBounds = this.getEventRangeBounds(event);

    // Clamp to view range
    const eventStart = new Date(Math.max(eventBounds.start.getTime(), viewStart.getTime()));
    const eventEnd = new Date(Math.min(eventBounds.end.getTime(), viewEnd.getTime()));

    let current = new Date(eventStart);

    while (current <= eventEnd) {
      // Find end of week
      const weekEnd = this.getEndOfWeek(current, firstDayOfWeek);
      const segmentEnd = new Date(Math.min(weekEnd.getTime(), eventEnd.getTime()));

      // Calculate span in days
      const spanDays = Math.floor((segmentEnd - current) / (1000 * 60 * 60 * 24)) + 1;

      // Calculate day offset within week
      const dayOffset = (current.getDay() - firstDayOfWeek + 7) % 7;

      segments.push({
        event: event,
        eventId: event.id,
        start: new Date(current),
        end: new Date(segmentEnd),
        isStart: this.isSameDay(current, eventBounds.start),
        isEnd: this.isSameDay(segmentEnd, eventBounds.end),
        spanDays: spanDays,
        dayOffset: dayOffset,
        row: -1 // Will be assigned later
      });

      // Move to next week
      current = new Date(weekEnd);
      current.setDate(current.getDate() + 1);
    }

    return segments;
  },

  /**
   * Get end of week for a given date
   */
  getEndOfWeek(date, firstDayOfWeek) {
    const result = new Date(date);
    const dayOfWeek = result.getDay();
    const daysUntilEnd = (6 - dayOfWeek + firstDayOfWeek) % 7;
    result.setDate(result.getDate() + daysUntilEnd);
    return result;
  },

  /**
   * Assign row positions to avoid overlapping
   */
  assignRowPositions(segments, firstDayOfWeek) {
    // Group by week
    const weekGroups = new Map();

    segments.forEach(segment => {
      const weekKey = this.getWeekKey(segment.start, firstDayOfWeek);
      if (!weekGroups.has(weekKey)) {
        weekGroups.set(weekKey, []);
      }
      weekGroups.get(weekKey).push(segment);
    });

    // Assign rows within each week
    weekGroups.forEach(weekSegments => {
      // Sort by start date, then by duration (longer first)
      weekSegments.sort((a, b) => {
        if (a.dayOffset !== b.dayOffset) return a.dayOffset - b.dayOffset;
        return b.spanDays - a.spanDays;
      });

      const rows = []; // Each row is an array of occupied day ranges

      weekSegments.forEach(segment => {
        let rowIndex = 0;
        let placed = false;

        while (!placed) {
          if (!rows[rowIndex]) {
            rows[rowIndex] = [];
          }

          // Check if this row has space for the segment
          const hasConflict = rows[rowIndex].some(occupied => {
            const occStart = occupied.dayOffset;
            const occEnd = occupied.dayOffset + occupied.spanDays - 1;
            const segStart = segment.dayOffset;
            const segEnd = segment.dayOffset + segment.spanDays - 1;

            return !(segEnd < occStart || segStart > occEnd);
          });

          if (!hasConflict) {
            rows[rowIndex].push(segment);
            segment.row = rowIndex;
            placed = true;
          } else {
            rowIndex++;
          }
        }
      });
    });

    return segments;
  },

  /**
   * Get week key for grouping
   */
  getWeekKey(date, firstDayOfWeek) {
    const weekStart = this.getStartOfWeek(date, firstDayOfWeek);
    return this.formatDateISO(weekStart);
  },

  /**
   * Get start of week
   */
  getStartOfWeek(date, firstDayOfWeek) {
    const result = new Date(date);
    const dayOfWeek = result.getDay();
    const diff = (dayOfWeek - firstDayOfWeek + 7) % 7;
    result.setDate(result.getDate() - diff);
    return result;
  },

  /**
   * Get segments for a specific week
   */
  getWeekSegments(segments, weekStart) {
    const weekEnd = new Date(weekStart);
    weekEnd.setDate(weekStart.getDate() + 6);

    return segments.filter(segment => {
      return segment.start >= weekStart && segment.start <= weekEnd;
    });
  },

  /**
   * Parse a pixel value safely.
   */
  parsePixelValue(value, fallback = 0) {
    const parsed = parseFloat(value);
    return Number.isFinite(parsed) ? parsed : fallback;
  },

  /**
   * Measure the current month-view row metrics from rendered styles.
   */
  getMonthViewLayoutMetrics(instance) {
    const fallbackRowHeight = this.parsePixelValue(
      window.getComputedStyle?.(instance.element)?.getPropertyValue('--ec-event-height'),
      22
    );
    const fallback = {
      baseTop: 24,
      rowHeight: fallbackRowHeight,
      gap: 2,
      rowStride: fallbackRowHeight + 2
    };

    if (!instance.contentElement || typeof window.getComputedStyle !== 'function') {
      return fallback;
    }

    const probe = document.createElement('div');
    probe.setAttribute('aria-hidden', 'true');
    probe.style.position = 'absolute';
    probe.style.visibility = 'hidden';
    probe.style.pointerEvents = 'none';
    probe.style.left = '-9999px';
    probe.style.top = '0';
    probe.style.width = '240px';

    probe.innerHTML = `
      <div class="ec-week-row">
        <div class="ec-event-layer">
          <div class="ec-event-bar ec-event-start ec-event-end">Sample</div>
        </div>
        <div class="ec-day-layer">
          <div class="ec-day-cell">
            <div class="ec-day-number">1</div>
            <div class="ec-day-events">
              <div class="ec-day-event">Sample</div>
            </div>
          </div>
        </div>
      </div>
    `;

    instance.contentElement.appendChild(probe);

    const dayNumberElement = probe.querySelector('.ec-day-number');
    const dayEventsElement = probe.querySelector('.ec-day-events');
    const dayEventElement = probe.querySelector('.ec-day-event');
    const eventBarElement = probe.querySelector('.ec-event-bar');
    const dayNumberStyle = dayNumberElement ? window.getComputedStyle(dayNumberElement) : null;
    const dayEventsStyle = dayEventsElement ? window.getComputedStyle(dayEventsElement) : null;
    const dayNumberHeight = dayNumberElement?.getBoundingClientRect().height
      || this.parsePixelValue(dayNumberStyle?.height, 22);
    const paddingTop = this.parsePixelValue(dayEventsStyle?.paddingTop, 0);
    const gap = this.parsePixelValue(dayEventsStyle?.rowGap, this.parsePixelValue(dayEventsStyle?.gap, 0));
    const rowHeight = Math.max(
      dayEventElement?.getBoundingClientRect().height || 0,
      eventBarElement?.getBoundingClientRect().height || 0,
      fallback.rowHeight
    );

    probe.remove();

    return {
      baseTop: dayNumberHeight + paddingTop,
      rowHeight,
      gap,
      rowStride: rowHeight + gap
    };
  },

  /**
   * Measure the all-day lane used by week view.
   */
  getAllDayLaneLayoutMetrics(instance) {
    const calendarStyle = typeof window.getComputedStyle === 'function'
      ? window.getComputedStyle(instance.element)
      : null;
    const fallbackRowHeight = this.parsePixelValue(calendarStyle?.getPropertyValue('--ec-event-height'), 22);
    const fallbackGap = this.parsePixelValue(calendarStyle?.getPropertyValue('--ec-event-gap'), 2);
    const fallback = {
      baseTop: 0,
      rowHeight: fallbackRowHeight,
      gap: fallbackGap,
      rowStride: fallbackRowHeight + fallbackGap
    };

    if (!instance.contentElement || typeof window.getComputedStyle !== 'function') {
      return fallback;
    }

    const probe = document.createElement('div');
    probe.setAttribute('aria-hidden', 'true');
    probe.style.position = 'absolute';
    probe.style.visibility = 'hidden';
    probe.style.pointerEvents = 'none';
    probe.style.left = '-9999px';
    probe.style.top = '0';
    probe.innerHTML = '<div class="ec-allday-events"><div class="ec-event-bar ec-event-start ec-event-end">Sample</div></div>';

    instance.contentElement.appendChild(probe);
    const eventBarElement = probe.querySelector('.ec-event-bar');
    const rowHeight = Math.max(eventBarElement?.getBoundingClientRect().height || 0, fallbackRowHeight);
    probe.remove();

    return {
      baseTop: 0,
      rowHeight,
      gap: fallbackGap,
      rowStride: rowHeight + fallbackGap
    };
  },

  /**
   * Count the visible spanning rows for a segment list.
   */
  getVisibleSegmentRowCount(segments, maxRows) {
    if (!Array.isArray(segments) || segments.length === 0) {
      return 0;
    }

    return Math.min(
      maxRows,
      segments.reduce((maxRow, segment) => Math.max(maxRow, segment.row), -1) + 1
    );
  },

  /**
   * Determine whether month view should use the compact mobile bar lane.
   */
  isCompactMonthView(instance) {
    return Boolean(instance)
      && typeof window !== 'undefined'
      && typeof window.matchMedia === 'function'
      && window.matchMedia('(max-width: 480px)').matches;
  },

  /**
   * Get the spanning bar row limit for month view.
   */
  getMonthViewBarRowLimit(instance) {
    const defaultLimit = Math.max(1, instance?.config?.maxEventsPerDay || 1);
    return this.isCompactMonthView(instance) ? 1 : defaultLimit;
  },

  /**
   * Count hidden spanning segments for each day column in a month-view week row.
   */
  getHiddenSpanningCountsByDayOffset(segments, maxRows) {
    const hiddenCounts = new Map();

    if (!Array.isArray(segments) || segments.length === 0) {
      return hiddenCounts;
    }

    segments.forEach(segment => {
      if (segment.row < maxRows) {
        return;
      }

      const segmentEnd = Math.min(6, segment.dayOffset + segment.spanDays - 1);
      for (let dayOffset = segment.dayOffset; dayOffset <= segmentEnd; dayOffset++) {
        hiddenCounts.set(dayOffset, (hiddenCounts.get(dayOffset) || 0) + 1);
      }
    });

    return hiddenCounts;
  },

  /**
   * Calculate inline spacing needed for month-view spanning bars.
   */
  getMonthViewWeekSpacing(instance, segments, layoutMetrics = null) {
    const maxRows = this.getMonthViewBarRowLimit(instance);

    if (!Array.isArray(segments) || segments.length === 0) {
      return {
        visibleRows: 0,
        maxRows,
        spacer: 0
      };
    }

    const metrics = layoutMetrics || this.getMonthViewLayoutMetrics(instance);
    const visibleRows = this.getVisibleSegmentRowCount(segments, maxRows);

    return {
      visibleRows,
      maxRows,
      spacer: visibleRows > 0
        ? (metrics.rowHeight + metrics.gap) * visibleRows
        : 0
    };
  },

  /**
   * Calculate the reserved height for week-view all-day rows.
   */
  getWeekAllDaySpacing(instance, segments, layoutMetrics = null) {
    const metrics = layoutMetrics || this.getAllDayLaneLayoutMetrics(instance);
    const visibleRows = this.getVisibleSegmentRowCount(segments, instance.config.maxEventsPerDay);

    return {
      visibleRows,
      spacer: visibleRows > 0
        ? (visibleRows * metrics.rowHeight) + (Math.max(0, visibleRows - 1) * metrics.gap)
        : 0
    };
  },

  /**
   * Render spanning event bars for a week
   */
  renderWeekEventBars(instance, container, segments, layoutMetrics = null, maxRows = null) {
    const resolvedMaxRows = Math.max(1, maxRows || instance.config.maxEventsPerDay);
    const baseTop = layoutMetrics?.baseTop ?? 22;
    const rowStride = layoutMetrics?.rowStride ?? 24;

    // Sort by row
    segments.sort((a, b) => a.row - b.row);

    segments.forEach(segment => {
      if (segment.row >= resolvedMaxRows) return; // Hide if exceeds max

      const bar = document.createElement('div');
      bar.className = 'ec-event-bar';
      bar.dataset.eventId = segment.event.id; // Add event ID for delegation
      bar.style.setProperty('--ec-day-event-color', segment.event.color || this.config.eventColors[0]);
      bar.style.left = `${(segment.dayOffset / 7) * 100}%`;
      bar.style.width = `${(segment.spanDays / 7) * 100}%`;
      bar.style.top = `${baseTop + (segment.row * rowStride)}px`;
      bar.title = segment.event.title;

      // Add start/end classes
      if (segment.isStart) bar.classList.add('ec-event-start');
      if (segment.isEnd) bar.classList.add('ec-event-end');

      // Title
      const title = document.createElement('span');
      title.className = 'ec-event-title';
      title.textContent = segment.event.title;
      bar.appendChild(title);

      container.appendChild(bar);
    });
  },

  /**
   * Render week view
   */
  renderWeekView(instance) {
    const {contentElement, currentDate, config, events} = instance;
    const strings = this.getLocaleStrings(config.locale);

    contentElement.innerHTML = '';
    contentElement.className = 'ec-content ec-week-view';

    const weekStart = this.getStartOfWeek(currentDate, config.firstDayOfWeek);

    // Create week container
    const weekContainer = document.createElement('div');
    weekContainer.className = 'ec-week-container';

    // All-day events section
    const allDaySection = document.createElement('div');
    allDaySection.className = 'ec-allday-section';

    const allDayLabel = document.createElement('div');
    allDayLabel.className = 'ec-allday-label';
    allDayLabel.textContent = strings.allDay;
    allDaySection.appendChild(allDayLabel);

    const allDayGrid = document.createElement('div');
    allDayGrid.className = 'ec-allday-grid';

    // Day columns header
    for (let i = 0; i < 7; i++) {
      const dayDate = new Date(weekStart);
      dayDate.setDate(weekStart.getDate() + i);

      const header = document.createElement('div');
      header.className = 'ec-week-day-header';
      if (this.isSameDay(dayDate, new Date())) {
        header.classList.add('ec-today');
      }

      header.innerHTML = `
        <div class="ec-week-dayname">${strings.dayNamesShort[dayDate.getDay()]}</div>
        <div class="ec-week-daynum">${dayDate.getDate()}</div>
      `;
      allDayGrid.appendChild(header);
    }

    allDaySection.appendChild(allDayGrid);

    // Render all-day spanning events
    const segments = this.calculateSpanningSegments(instance, weekStart, 7);
    const allDayEvents = document.createElement('div');
    allDayEvents.className = 'ec-allday-events';
    const allDayMetrics = this.getAllDayLaneLayoutMetrics(instance);
    const allDaySpacing = this.getWeekAllDaySpacing(instance, segments, allDayMetrics);
    if (allDaySpacing.visibleRows > 0) {
      allDayEvents.style.setProperty('--ec-week-allday-space', `${allDaySpacing.spacer}px`);
    }
    this.renderWeekEventBars(instance, allDayEvents, segments, allDayMetrics);
    allDaySection.appendChild(allDayEvents);

    weekContainer.appendChild(allDaySection);

    // Time grid
    const timeGrid = document.createElement('div');
    timeGrid.className = 'ec-time-grid';

    // Time labels column
    const timeLabels = document.createElement('div');
    timeLabels.className = 'ec-time-labels';

    for (let hour = 0; hour < 24; hour++) {
      const label = document.createElement('div');
      label.className = 'ec-time-label';
      label.textContent = `${hour.toString().padStart(2, '0')}:00`;
      timeLabels.appendChild(label);
    }
    timeGrid.appendChild(timeLabels);

    // Day columns
    const dayColumns = document.createElement('div');
    dayColumns.className = 'ec-day-columns';

    for (let i = 0; i < 7; i++) {
      const dayDate = new Date(weekStart);
      dayDate.setDate(weekStart.getDate() + i);

      const column = document.createElement('div');
      column.className = 'ec-day-column';
      column.dataset.date = this.formatDateISO(dayDate);

      // Hour slots
      for (let hour = 0; hour < 24; hour++) {
        const slot = document.createElement('div');
        slot.className = 'ec-hour-slot';
        slot.dataset.hour = hour;

        // Click handler now handled by delegation

        column.appendChild(slot);
      }

      // Render timed events
      const timedOccurrences = this.getTimedOccurrencesForDate(events, dayDate, 'week');
      this.renderTimedEvents(instance, column, timedOccurrences, dayDate);

      dayColumns.appendChild(column);
    }

    timeGrid.appendChild(dayColumns);
    weekContainer.appendChild(timeGrid);
    contentElement.appendChild(weekContainer);
  },

  /**
   * Render timed events in week/day view
   */
  renderTimedEvents(instance, container, occurrences, date) {
    this.layoutTimedOccurrences(occurrences).forEach(occurrence => {
      const {event, start, end, scheduleType} = occurrence;
      const startHour = start.getHours() + start.getMinutes() / 60;
      const endHour = end.getHours() + end.getMinutes() / 60;
      const duration = Math.max(endHour - startHour, 0.5);
      const columnCount = Math.max(1, occurrence._columnCount || 1);
      const columnIndex = Math.max(0, occurrence._columnIndex || 0);
      const widthPercent = 100 / columnCount;
      const leftPercent = widthPercent * columnIndex;

      const eventEl = document.createElement('div');
      eventEl.className = 'ec-timed-event';
      eventEl.dataset.eventId = event.id; // Add event ID for delegation
      eventEl.style.setProperty('--ec-day-event-color', event.color || this.config.eventColors[0]);
      eventEl.style.top = `${startHour * 48}px`; // 48px per hour
      eventEl.style.height = `${duration * 48}px`;
      eventEl.style.left = `calc(${leftPercent}% + 2px)`;
      eventEl.style.width = `calc(${widthPercent}% - 4px)`;
      eventEl.style.right = 'auto';
      eventEl.style.zIndex = String(10 + columnIndex);
      eventEl.title = event.title;

      if (scheduleType) {
        eventEl.classList.add(`ec-schedule-${scheduleType}`);
      }
      if (occurrence.isStartSlice || occurrence.isEndSlice) {
        eventEl.classList.add('ec-continuation-slice');
      }

      eventEl.innerHTML = `
        <div class="ec-event-time">${this.formatTime(start)} - ${this.formatTime(end)}</div>
        <div class="ec-event-title">${this.escapeHtml(event.title)}</div>
      `;

      // Click handler now handled by delegation

      container.appendChild(eventEl);
    });
  },

  /**
   * Render day view
   */
  renderDayView(instance) {
    const {contentElement, currentDate, config, events} = instance;
    const strings = this.getLocaleStrings(config.locale);

    contentElement.innerHTML = '';
    contentElement.className = 'ec-content ec-day-view';

    // Day header
    const header = document.createElement('div');
    header.className = 'ec-day-header';
    header.innerHTML = `
      <span class="ec-day-name">${strings.dayNames[currentDate.getDay()]}</span>
      <span class="ec-day-date">${currentDate.getDate()}</span>
      <span class="ec-day-month">${strings.monthNames[currentDate.getMonth()]}</span>
    `;
    contentElement.appendChild(header);

    // All-day events
    const dayEvents = this.getEventsForDate(events, currentDate);
    const allDayEvents = dayEvents.filter(event => this.shouldRenderAllDayLaneEvent(event));
    const timedOccurrences = this.getTimedOccurrencesForDate(events, currentDate, 'day');

    if (allDayEvents.length > 0) {
      const allDaySection = document.createElement('div');
      allDaySection.className = 'ec-day-allday';

      allDayEvents.forEach(event => {
        const eventEl = document.createElement('div');
        eventEl.className = 'ec-allday-event';
        eventEl.dataset.eventId = event.id; // Add event ID for delegation
        eventEl.style.backgroundColor = event.color;
        eventEl.innerHTML = `<div class="ec-event-title">${this.escapeHtml(event.title)}</div>`;
        eventEl.title = event.title;
        // Click handler now handled by delegation
        allDaySection.appendChild(eventEl);
      });

      contentElement.appendChild(allDaySection);
    }

    // Time grid
    const timeGrid = document.createElement('div');
    timeGrid.className = 'ec-day-time-grid';

    for (let hour = 0; hour < 24; hour++) {
      const slot = document.createElement('div');
      slot.className = 'ec-hour-slot';
      slot.dataset.hour = hour;

      const label = document.createElement('div');
      label.className = 'ec-hour-label';
      label.textContent = `${hour.toString().padStart(2, '0')}:00`;

      const area = document.createElement('div');
      area.className = 'ec-hour-area';

      // Click handler now handled by delegation

      slot.appendChild(label);
      slot.appendChild(area);
      timeGrid.appendChild(slot);
    }

    const eventsLayer = document.createElement('div');
    eventsLayer.className = 'ec-day-events-layer';
    timeGrid.appendChild(eventsLayer);

    // Render timed events
    this.renderTimedEvents(instance, eventsLayer, timedOccurrences, currentDate);

    contentElement.appendChild(timeGrid);
  },

  // ========== Event Handlers ==========

  /**
   * Handle date click
   */
  handleDateClick(instance, date, domEvent) {
    const {config} = instance;

    // Emit event
    this.emitEvent('eventcalendar:dateClick', {
      date,
      instance,
      element: instance.element
    });

    // Call callback if configured
    if (config.onDateClick) {
      this.invokeConfiguredFunction(config.onDateClick, date, instance);
    }
  },

  /**
   * Handle event click
   */
  handleEventClick(instance, eventData, domEvent) {
    domEvent?.stopPropagation?.();

    const {config} = instance;

    // Emit event
    this.emitEvent('eventcalendar:eventClick', {
      event: eventData,
      instance,
      element: instance.element
    });

    if (config.onEventClickApi) {
      this.processEventClickApi(instance, eventData);
    }

    // Custom callback
    if (config.onEventClick) {
      this.invokeConfiguredFunction(config.onEventClick, eventData, instance);
      return;
    }

    if (!config.onEventClickApi) {
      this.showEventDetailModal(instance, eventData);
    }
  },

  /**
   * Execute the configured event click API and pass the payload to ResponseHandler.
   */
  async processEventClickApi(instance, eventData) {
    try {
      const url = this.buildEventActionUrl(instance.config.onEventClickApi, eventData);
      const context = {
        element: instance.element,
        data: eventData,
        event: eventData,
        instance
      };

      let response;
      if (window.httpAction?.get) {
        response = await window.httpAction.get(url, {}, context);
      } else if (window.ApiService && typeof ApiService.get === 'function') {
        response = await ApiService.get(url);
        if (window.ResponseHandler) {
          await ResponseHandler.process(this.extractResponsePayload(response), context);
        }
      } else {
        const requestOptions = Now.applyRequestLanguage({method: 'GET'});
        const res = await fetch(url, requestOptions);
        response = await res.json();

        if (window.ResponseHandler) {
          await ResponseHandler.process(this.extractResponsePayload(response), context);
        }
      }

      const payload = this.extractResponsePayload(response);
      if (!this.responseHasActions(payload) && !instance.config.onEventClick) {
        const mergedEvent = payload?.data && typeof payload.data === 'object' && !Array.isArray(payload.data)
          ? {
            ...eventData,
            ...payload.data,
            data: {
              ...(eventData.data || {}),
              ...(payload.data.data || payload.data)
            }
          }
          : eventData;

        this.showEventDetailModal(instance, mergedEvent);
      }
    } catch (error) {
      console.error('[EventCalendar] Event click API error:', error);

      if (!instance.config.onEventClick) {
        this.showEventDetailModal(instance, eventData);
      }
    }
  },

  /**
   * Show day detail modal (for mobile)
   */
  showDayDetailModal(instance, date, events) {
    const {config} = instance;
    const strings = this.getLocaleStrings(config.locale);

    const eventListHtml = events.map(event => this.buildModalEventItem(instance, event, {
      clickable: true,
      localeStrings: strings,
      context: 'day-list',
      referenceDate: date
    })).join('');

    const content = events.length > 0 ? eventListHtml : `<div class="ec-no-events">${strings.noEvents}</div>`;

    this.showModal(content, {
      title: `${strings.dayNames[date.getDay()]} ${this.formatDate(date, config.locale)}`,
      onEventClick: (eventId) => {
        const event = events.find(e => e.id === eventId);
        if (event) {
          this.handleEventClick(instance, event, {stopPropagation: () => {}});
        }
      }
    });
  },

  /**
   * Show a detail modal for a single event.
   */
  showEventDetailModal(instance, event) {
    const {config} = instance;
    const strings = this.getLocaleStrings(config.locale);
    const eventDate = this.resolveDateValue(event.start, 'date') || new Date();
    const detailMarkup = this.buildModalEventItem(instance, event, {
      clickable: false,
      localeStrings: strings,
      includeDescription: true,
      referenceDate: eventDate
    });

    const content = `
      <div class="ec-day-modal ec-event-detail-modal">
        <div class="ec-day-modal-date">
          <span class="ec-day-name">${strings.dayNames[eventDate.getDay()]}</span>
          <span class="ec-day-num">${eventDate.getDate()}</span>
          <span class="ec-month-name">${strings.monthNames[eventDate.getMonth()]}</span>
        </div>
        <div class="ec-modal-events">${detailMarkup}</div>
      </div>
    `;

    this.showModal(content, {
      title: event.title || ''
    });
  },

  /**
   * Show events modal (for "+more" click)
   */
  showEventsModal(instance, date, events) {
    this.showDayDetailModal(instance, date, events);
  },

  /**
   * Show modal using Modal component
   */
  showModal(content, options = {}) {
    if (window.Modal) {
      const modal = new Modal({
        title: options.title || '',
        titleClass: 'icon-calendar',
        content: content,
        backdrop: true,
        onHidden() {
          if (typeof options.onHidden === 'function') {
            options.onHidden.call(this);
          }
          this.destroy();
        }
      });
      modal.show();

      // Bind event click handlers
      const modalElement = modal.modal || modal.element || null;
      if (options.onEventClick && modalElement) {
        modalElement.querySelectorAll('.ec-modal-event[data-event-id]').forEach(el => {
          el.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();

            const eventId = el.dataset.eventId;
            modal.hide();

            window.setTimeout(() => {
              options.onEventClick(eventId);
            }, modal.options.animation ? 170 : 0);
          });
        });
      }
    } else {
      // Fallback: simple dialog
      if (window.DialogManager) {
        DialogManager.show({
          title: options.title || '',
          content: content,
          buttons: [{text: 'Close', primary: true}]
        });
      }
    }
  },

  // ========== Navigation ==========

  /**
   * Navigate calendar
   */
  navigate(instance, direction) {
    const nextDate = this.getNavigatedDate(instance.currentDate, instance.currentView, direction);

    this.setCurrentDate(instance, nextDate, 'eventcalendar:navigate', {
      direction,
      view: instance.currentView
    });
  },

  /**
   * Go to today
   */
  goToToday(instance) {
    this.setCurrentDate(instance, new Date(), 'eventcalendar:today', {
      view: instance.currentView
    });
  },

  /**
   * Change view
   */
  changeView(instance, view) {
    if (!instance.config.views.includes(view)) return;

    instance.currentView = view;
    instance.currentDate = this.clampDateForView(instance, instance.currentDate, view);
    this.render(instance);

    this.emitEvent('eventcalendar:viewChange', {
      view,
      date: new Date(instance.currentDate),
      instance
    });
  },

  /**
   * Handle year picker change.
   */
  handleYearPickerChange(instance) {
    const year = parseInt(instance.yearSelectElement?.value, 10);
    const preferredMonth = parseInt(instance.monthSelectElement?.value, 10);

    if (Number.isNaN(year)) {
      return;
    }

    this.populateMonthOptions(
      instance,
      year,
      Number.isNaN(preferredMonth) ? instance.currentDate.getMonth() : preferredMonth
    );
    this.handlePeriodPickerChange(instance);
  },

  /**
   * Handle month/year picker change.
   */
  handlePeriodPickerChange(instance) {
    const year = parseInt(instance.yearSelectElement?.value, 10);
    const month = parseInt(instance.monthSelectElement?.value, 10);

    if (Number.isNaN(year) || Number.isNaN(month)) {
      return;
    }

    this.setCurrentDate(instance, new Date(year, month, 1), 'eventcalendar:periodChange', {
      year,
      month: month + 1,
      view: instance.currentView
    });
  },

  /**
   * Update current period display
   */
  updateCurrentPeriod(instance) {
    const {currentDate, currentView, config} = instance;
    let text = '';

    switch (currentView) {
      case 'day':
        text = this.formatDate(currentDate, config.locale);
        break;
      case 'week':
        const weekStart = this.getStartOfWeek(currentDate, config.firstDayOfWeek);
        const weekEnd = new Date(weekStart);
        weekEnd.setDate(weekStart.getDate() + 6);
        text = this.formatWeekPeriod(weekStart, weekEnd, config.locale);
        break;
      case 'month':
        text = this.formatMonthYear(currentDate, config.locale);
        break;
    }

    if (instance.currentPeriodLabelElement) {
      instance.currentPeriodLabelElement.textContent = text;
    }

    if (instance.periodPickerElement) {
      const showPicker = config.showPeriodPicker && currentView === 'month';
      instance.periodPickerElement.hidden = !showPicker;
      if (instance.currentPeriodLabelElement) {
        instance.currentPeriodLabelElement.hidden = showPicker;
      }

      if (showPicker) {
        this.updatePeriodPicker(instance);
      }
    }
  },

  /**
   * Update month/year picker options.
   */
  updatePeriodPicker(instance) {
    if (!instance.periodPickerElement || instance.currentView !== 'month') {
      return;
    }

    const selectedYear = instance.currentDate.getFullYear();
    const selectedMonth = instance.currentDate.getMonth();

    this.populateYearOptions(instance, selectedYear);
    this.populateMonthOptions(instance, selectedYear, selectedMonth);

    if (instance.yearSelectElement) {
      instance.yearSelectElement.value = String(selectedYear);
    }
    if (instance.monthSelectElement) {
      instance.monthSelectElement.value = String(selectedMonth);
    }
  },

  /**
   * Populate year picker options.
   */
  populateYearOptions(instance, selectedYear) {
    const yearSelect = instance.yearSelectElement;
    if (!yearSelect) return;

    const years = this.getYearOptions(instance, selectedYear);
    yearSelect.innerHTML = '';

    years.forEach(year => {
      const option = document.createElement('option');
      option.value = String(year);
      option.textContent = this.formatDisplayYear(new Date(year, 0, 1), instance.config.locale);
      yearSelect.appendChild(option);
    });
  },

  /**
   * Populate month picker options.
   */
  populateMonthOptions(instance, year, preferredMonth = instance.currentDate.getMonth()) {
    const monthSelect = instance.monthSelectElement;
    if (!monthSelect) return;

    const strings = this.getLocaleStrings(instance.config.locale);
    const allowedMonths = [];
    monthSelect.innerHTML = '';

    for (let month = 0; month < 12; month++) {
      const option = document.createElement('option');
      option.value = String(month);
      option.textContent = strings.monthNames[month];

      if (!this.isMonthAllowed(instance, year, month)) {
        option.disabled = true;
      } else {
        allowedMonths.push(month);
      }

      monthSelect.appendChild(option);
    }

    const nextMonth = this.getNearestAllowedValue(allowedMonths, preferredMonth);
    monthSelect.disabled = allowedMonths.length === 0;
    if (nextMonth !== null) {
      monthSelect.value = String(nextMonth);
    }
  },

  /**
   * Return year options for the picker.
   */
  getYearOptions(instance, selectedYear) {
    const {config} = instance;
    const currentYear = Number.isInteger(selectedYear) ? selectedYear : instance.currentDate.getFullYear();
    const startYear = config.minDate ? config.minDate.getFullYear() : currentYear - config.yearRangeBefore;
    const endYear = config.maxDate ? config.maxDate.getFullYear() : currentYear + config.yearRangeAfter;
    const years = [];

    for (let year = startYear; year <= endYear; year++) {
      years.push(year);
    }

    return years;
  },

  /**
   * Check whether a month is allowed by min/max bounds.
   */
  isMonthAllowed(instance, year, month) {
    const range = this.getPeriodRangeForDate(new Date(year, month, 1), 'month', instance.config);
    return this.isRangeWithinBounds(range, instance.config);
  },

  /**
   * Update navigation control states.
   */
  updateNavigationState(instance) {
    if (instance.prevButtonElement) {
      instance.prevButtonElement.disabled = !this.canNavigate(instance, -1);
    }

    if (instance.nextButtonElement) {
      instance.nextButtonElement.disabled = !this.canNavigate(instance, 1);
    }

    if (instance.todayButtonElement) {
      instance.todayButtonElement.disabled = !this.canUseDate(instance, new Date(), instance.currentView);
    }
  },

  /**
   * Check whether navigation is possible in a direction.
   */
  canNavigate(instance, direction) {
    const nextDate = this.getNavigatedDate(instance.currentDate, instance.currentView, direction);
    return this.canUseDate(instance, nextDate, instance.currentView);
  },

  /**
   * Check whether a date can be shown in the current bounds.
   */
  canUseDate(instance, date, view = instance.currentView) {
    const range = this.getPeriodRangeForDate(date, view, instance.config);
    return this.isRangeWithinBounds(range, instance.config);
  },

  /**
   * Calculate the next date when navigating.
   */
  getNavigatedDate(date, view, direction) {
    const nextDate = new Date(date);

    switch (view) {
      case 'day':
        nextDate.setDate(nextDate.getDate() + direction);
        break;
      case 'week':
        nextDate.setDate(nextDate.getDate() + (direction * 7));
        break;
      case 'month':
        nextDate.setMonth(nextDate.getMonth() + direction);
        break;
    }

    return nextDate;
  },

  /**
   * Clamp a date to the nearest visible period allowed by the bounds.
   */
  clampDateForView(instance, date, view = instance.currentView) {
    const candidate = new Date(date);
    const {config} = instance;
    const range = this.getPeriodRangeForDate(candidate, view, config);

    if (config.minDate && range.end < config.minDate) {
      return view === 'month'
        ? new Date(config.minDate.getFullYear(), config.minDate.getMonth(), 1)
        : new Date(config.minDate);
    }

    if (config.maxDate && range.start > config.maxDate) {
      return view === 'month'
        ? new Date(config.maxDate.getFullYear(), config.maxDate.getMonth(), 1)
        : new Date(config.maxDate);
    }

    return candidate;
  },

  /**
   * Commit a date change, clamping it to the configured bounds first.
   */
  setCurrentDate(instance, date, eventName = null, extra = {}) {
    const nextDate = this.clampDateForView(instance, date, instance.currentView);
    const currentKey = this.getPeriodKey(instance.currentDate, instance.currentView, instance.config);
    const nextKey = this.getPeriodKey(nextDate, instance.currentView, instance.config);

    if (currentKey === nextKey) {
      this.updateNavigationState(instance);
      this.updateCurrentPeriod(instance);
      return false;
    }

    instance.currentDate = nextDate;

    if (instance.config.api) {
      this.loadEvents(instance);
    } else {
      this.render(instance);
    }

    if (eventName) {
      this.emitEvent(eventName, {
        date: new Date(nextDate),
        instance,
        ...extra
      });
    }

    return true;
  },

  /**
   * Update view switcher
   */
  updateViewSwitcher(instance) {
    if (!instance.viewSwitcherElement) return;

    instance.viewSwitcherElement.querySelectorAll('.ec-view-btn').forEach(btn => {
      btn.classList.toggle('active', btn.dataset.view === instance.currentView);
    });
  },

  // ========== Utilities ==========

  /**
   * Get events for a specific date
   */
  getEventsForDate(events, date) {
    return events.filter(event => this.occursOnDate(event, date));
  },

  /**
   * Get view date range
   */
  getViewDateRange(instance) {
    const {currentDate, currentView, config} = instance;
    let start, end;

    switch (currentView) {
      case 'day':
        start = new Date(currentDate);
        start.setHours(0, 0, 0, 0);
        end = new Date(currentDate);
        end.setHours(23, 59, 59, 999);
        break;
      case 'week':
        start = this.getStartOfWeek(currentDate, config.firstDayOfWeek);
        end = new Date(start);
        end.setDate(start.getDate() + 6);
        end.setHours(23, 59, 59, 999);
        break;
      case 'month':
        start = new Date(currentDate.getFullYear(), currentDate.getMonth(), 1);
        end = new Date(currentDate.getFullYear(), currentDate.getMonth() + 1, 0);
        end.setHours(23, 59, 59, 999);
        // Extend to include visible days from adjacent months
        const startOffset = (start.getDay() - config.firstDayOfWeek + 7) % 7;
        start.setDate(start.getDate() - startOffset);
        const endOffset = (6 - end.getDay() + config.firstDayOfWeek) % 7;
        end.setDate(end.getDate() + endOffset);
        break;
    }

    return {start, end};
  },

  /**
   * Get the visible range for a specific date and view.
   */
  getPeriodRangeForDate(date, view, config) {
    let start;
    let end;

    switch (view) {
      case 'day':
        start = new Date(date);
        start.setHours(0, 0, 0, 0);
        end = new Date(date);
        end.setHours(23, 59, 59, 999);
        break;
      case 'week':
        start = this.getStartOfWeek(date, config.firstDayOfWeek);
        end = new Date(start);
        end.setDate(start.getDate() + 6);
        end.setHours(23, 59, 59, 999);
        break;
      case 'month':
      default:
        start = new Date(date.getFullYear(), date.getMonth(), 1);
        end = new Date(date.getFullYear(), date.getMonth() + 1, 0);
        end.setHours(23, 59, 59, 999);
        break;
    }

    return {start, end};
  },

  /**
   * Check if a range is within the configured bounds.
   */
  isRangeWithinBounds(range, config) {
    if (config.minDate && range.end < config.minDate) {
      return false;
    }

    if (config.maxDate && range.start > config.maxDate) {
      return false;
    }

    return true;
  },

  /**
   * Return a stable key for comparing visible periods.
   */
  getPeriodKey(date, view, config) {
    const range = this.getPeriodRangeForDate(date, view, config);
    return `${view}:${this.formatDateISO(range.start)}`;
  },

  /**
   * Find the nearest allowed option value.
   */
  getNearestAllowedValue(values, preferredValue) {
    if (!Array.isArray(values) || values.length === 0) {
      return null;
    }

    return values.reduce((closest, value) => {
      if (Math.abs(value - preferredValue) < Math.abs(closest - preferredValue)) {
        return value;
      }
      return closest;
    }, values[0]);
  },

  /**
   * Check if two dates are the same day
   */
  isSameDay(date1, date2) {
    return date1.getFullYear() === date2.getFullYear() &&
      date1.getMonth() === date2.getMonth() &&
      date1.getDate() === date2.getDate();
  },

  /**
   * Format date as ISO string (YYYY-MM-DD)
   */
  formatDateISO(date) {
    const d = this.resolveDateValue(date, 'date');
    if (!d) {
      return '';
    }
    const year = d.getFullYear();
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
  },

  /**
   * Extract the event array from either direct fetch JSON or ApiService responses.
   */
  extractEventsFromResponse(response, path = 'data') {
    const roots = [
      response,
      response?.data,
      response?.data?.data,
      response?.data?.data?.data
    ];

    for (const root of roots) {
      if (!root) {
        continue;
      }

      const fromPath = window.Utils?.object?.get?.(root, path);
      if (Array.isArray(fromPath)) {
        return fromPath;
      }

      if (Array.isArray(root?.[path])) {
        return root[path];
      }

      if (Array.isArray(root?.data)) {
        return root.data;
      }

      if (Array.isArray(root)) {
        return root;
      }
    }

    return [];
  },

  /**
   * Extract the ResponseHandler payload from fetch/http wrappers.
   */
  extractResponsePayload(response) {
    return response?.data?.data ?? response?.data ?? response ?? null;
  },

  /**
   * Format the "+N more" label with runtime interpolation.
   */
  formatMoreEventsLabel(locale, count) {
    return this.translateComponentString('moreEvents', locale, {count});
  },

  /**
   * Check whether a payload contains server-driven actions.
   */
  responseHasActions(payload) {
    if (!payload || !payload.actions) {
      return false;
    }

    return Array.isArray(payload.actions) ? payload.actions.length > 0 : true;
  },

  /**
   * Resolve a configured callback from a function or window path.
   */
  resolveConfiguredFunction(callback) {
    if (typeof callback === 'function') {
      return {
        fn: callback,
        context: null
      };
    }

    if (typeof callback !== 'string' || callback.trim() === '') {
      return null;
    }

    const parts = callback.split('.');
    let context = window;

    for (let index = 0; index < parts.length - 1; index++) {
      context = context?.[parts[index]];
      if (!context) {
        return null;
      }
    }

    const fn = context?.[parts[parts.length - 1]];
    if (typeof fn !== 'function') {
      return null;
    }

    return {fn, context};
  },

  /**
   * Invoke a configured callback if available.
   */
  invokeConfiguredFunction(callback, ...args) {
    const resolved = this.resolveConfiguredFunction(callback);
    if (!resolved) {
      return undefined;
    }

    return resolved.fn.apply(resolved.context || window, args);
  },

  /**
   * Replace placeholders in event action URLs with event data.
   */
  buildEventActionUrl(template, eventData) {
    return String(template || '').replace(/\{([^}]+)\}/g, (match, key) => {
      const sources = [eventData, eventData?.data];

      for (const source of sources) {
        if (!source) {
          continue;
        }

        const value = window.Utils?.object?.get?.(source, key) ?? source[key];
        if (value !== undefined && value !== null && value !== '') {
          return encodeURIComponent(String(value));
        }
      }

      return match;
    });
  },

  /**
   * Build a standard modal item for event listings/details.
   */
  buildModalEventItem(instance, event, options = {}) {
    const locale = instance.config.locale;
    const title = this.escapeHtml(event.title || '');
    const timeText = this.getEventDisplayTimeText(event, locale, options.referenceDate || null);
    const description = options.includeDescription && event.description
      ? `<div class="ec-modal-event-description">${this.escapeHtml(event.description)}</div>`
      : '';
    const staticClass = options.clickable === false ? ' is-static' : '';
    const eventIdAttr = options.clickable === false ? '' : ` data-event-id="${this.escapeHtml(String(event.id))}"`;

    return `
      <div class="ec-modal-event${staticClass}"${eventIdAttr} style="border-left-color: ${event.color || this.config.eventColors[0]}">
        <div class="ec-modal-event-title">${title}</div>
        ${timeText ? `<div class="ec-modal-event-time">${this.escapeHtml(timeText)}</div>` : ''}
        ${description}
      </div>
    `;
  },

  /**
   * Format the user-facing time text for an event.
   */
  getEventDisplayTimeText(event, locale = 'en', referenceDate = null) {
    if (!event) {
      return '';
    }

    if (event.allDay) {
      return this.translateComponentString('allDay', locale);
    }

    if (referenceDate) {
      const occurrence = this.getTimedOccurrenceForDate(event, referenceDate, 'day');
      if (occurrence) {
        return `${this.formatTime(occurrence.start)} - ${this.formatTime(occurrence.end)}`;
      }
    }

    if (this.isRecurringSlotEvent(event)) {
      const startText = event.slotStartTime || this.formatTime(event.start);
      const endText = event.slotEndTime || this.formatTime(event.end);
      return `${startText} - ${endText}`;
    }

    if (this.isSameDay(event.start, event.end)) {
      return `${this.formatTime(event.start)} - ${this.formatTime(event.end)}`;
    }

    return `${this.formatDate(event.start, locale)} ${this.formatTime(event.start)} - ${this.formatDate(event.end, locale)} ${this.formatTime(event.end)}`;
  },

  /**
   * Get the framework i18n manager if available.
   */
  getI18nManager() {
    return window.Now?.getManager?.('i18n') || window.I18nManager || null;
  },

  /**
   * Resolve the effective locale for an instance.
   */
  resolveLocale(locale = 'auto') {
    if (locale && locale !== 'auto') {
      return locale;
    }

    return this.getI18nManager()?.getCurrentLocale?.()
      || document.documentElement?.getAttribute('lang')
      || navigator.language
      || 'en';
  },

  /**
   * Sync an instance locale with the current system locale.
   */
  syncInstanceLocale(instance) {
    if (!instance?.config) {
      return 'en';
    }

    instance.config.locale = this.resolveLocale(instance.config.localePreference || instance.config.locale);
    return instance.config.locale;
  },

  /**
   * Get the component fallback bundle for a locale.
   */
  getFallbackLocaleBundle(locale = 'en') {
    const normalizedLocale = String(locale || 'en').trim();
    const localeKey = normalizedLocale.toLowerCase();
    const baseLocale = localeKey.split('-')[0];

    return this.i18n[localeKey] || this.i18n[baseLocale] || this.i18n.en;
  },

  /**
   * Replace placeholders in fallback strings.
   */
  interpolateText(text, params = {}) {
    return String(text).replace(/\{([^}]+)\}/g, (match, key) => {
      return params[key] !== undefined ? params[key] : match;
    });
  },

  /**
   * Translate a component string using framework i18n first, then component fallback.
   */
  translateComponentString(key, locale = 'en', params = {}) {
    const fallbackBundle = this.getFallbackLocaleBundle(locale);
    const englishValue = this.i18n.en[key] ?? key;
    const fallbackValue = fallbackBundle[key] ?? englishValue;
    const i18nManager = this.getI18nManager();

    if (i18nManager?.translate) {
      const translated = i18nManager.translate(englishValue, params, locale);
      if (translated && translated !== englishValue) {
        return translated;
      }
    }

    return this.interpolateText(fallbackValue, params);
  },

  /**
   * Build localized month names using the active system locale.
   */
  getLocalizedMonthNames(locale = 'en', width = 'long') {
    try {
      const formatter = new Intl.DateTimeFormat(locale, {
        month: width,
        timeZone: 'UTC'
      });

      return Array.from({length: 12}, (_, month) => formatter.format(new Date(Date.UTC(2024, month, 1))));
    } catch (error) {
      const fallbackBundle = this.getFallbackLocaleBundle(locale);
      return width === 'short' ? fallbackBundle.monthNamesShort : fallbackBundle.monthNames;
    }
  },

  /**
   * Build localized weekday names using the active system locale.
   */
  getLocalizedDayNames(locale = 'en', width = 'long') {
    try {
      const formatter = new Intl.DateTimeFormat(locale, {
        weekday: width,
        timeZone: 'UTC'
      });

      return Array.from({length: 7}, (_, index) => formatter.format(new Date(Date.UTC(2024, 0, index + 7))));
    } catch (error) {
      const fallbackBundle = this.getFallbackLocaleBundle(locale);
      return width === 'short' ? fallbackBundle.dayNamesShort : fallbackBundle.dayNames;
    }
  },

  /**
   * Resolve i18n strings for the active locale.
   */
  getLocaleStrings(locale = 'en') {
    const resolvedLocale = this.resolveLocale(locale);
    const fallbackBundle = this.getFallbackLocaleBundle(resolvedLocale);

    return {
      ...fallbackBundle,
      today: this.translateComponentString('today', resolvedLocale),
      month: this.translateComponentString('month', resolvedLocale),
      week: this.translateComponentString('week', resolvedLocale),
      day: this.translateComponentString('day', resolvedLocale),
      moreEvents: this.translateComponentString('moreEvents', resolvedLocale, {count: '{count}'}),
      noEvents: this.translateComponentString('noEvents', resolvedLocale),
      allDay: this.translateComponentString('allDay', resolvedLocale),
      loading: this.translateComponentString('loading', resolvedLocale),
      error: this.translateComponentString('error', resolvedLocale),
      retry: this.translateComponentString('retry', resolvedLocale),
      previous: this.translateComponentString('previous', resolvedLocale),
      next: this.translateComponentString('next', resolvedLocale),
      year: this.translateComponentString('year', resolvedLocale),
      selectMonth: this.translateComponentString('selectMonth', resolvedLocale),
      selectYear: this.translateComponentString('selectYear', resolvedLocale),
      monthNames: this.getLocalizedMonthNames(resolvedLocale, 'long'),
      monthNamesShort: this.getLocalizedMonthNames(resolvedLocale, 'short'),
      dayNames: this.getLocalizedDayNames(resolvedLocale, 'long'),
      dayNamesShort: this.getLocalizedDayNames(resolvedLocale, 'short')
    };
  },

  /**
   * Format a localized year value.
   */
  formatDisplayYear(date, locale = 'en') {
    const safeDate = this.resolveDateValue(date, 'date');
    if (!safeDate) {
      return '';
    }

    if (window.Utils?.date?.format) {
      const formattedYear = Utils.date.format(safeDate, 'YYYY', locale);
      if (formattedYear) {
        return formattedYear;
      }
    }

    return String(safeDate.getFullYear());
  },

  /**
   * Format a localized month/year label.
   */
  formatMonthYear(date, locale = 'en') {
    const safeDate = this.resolveDateValue(date, 'date');
    if (!safeDate) {
      return '';
    }

    const strings = this.getLocaleStrings(locale);
    const fallback = `${strings.monthNames[safeDate.getMonth()]} ${this.formatDisplayYear(safeDate, locale)}`;

    if (window.Utils?.date?.format) {
      const formattedValue = Utils.date.format(safeDate, 'MMMM YYYY', locale);
      if (formattedValue) {
        return formattedValue;
      }
    }

    return fallback;
  },

  /**
   * Format a localized week range label.
   */
  formatWeekPeriod(startDate, endDate, locale = 'en') {
    const start = this.resolveDateValue(startDate, 'date');
    const end = this.resolveDateValue(endDate, 'date');
    if (!start || !end) {
      return '';
    }

    if (window.Utils?.date?.format) {
      if (start.getFullYear() === end.getFullYear() && start.getMonth() === end.getMonth()) {
        return `${Utils.date.format(start, 'D', locale)} - ${Utils.date.format(end, 'D MMMM YYYY', locale)}`;
      }

      if (start.getFullYear() === end.getFullYear()) {
        return `${Utils.date.format(start, 'D MMMM', locale)} - ${Utils.date.format(end, 'D MMMM YYYY', locale)}`;
      }

      return `${Utils.date.format(start, 'D MMMM YYYY', locale)} - ${Utils.date.format(end, 'D MMMM YYYY', locale)}`;
    }

    const strings = this.getLocaleStrings(locale);
    const startYear = this.formatDisplayYear(start, locale);
    const endYear = this.formatDisplayYear(end, locale);

    if (start.getFullYear() === end.getFullYear() && start.getMonth() === end.getMonth()) {
      return `${start.getDate()} - ${end.getDate()} ${strings.monthNames[end.getMonth()]} ${endYear}`;
    }

    if (start.getFullYear() === end.getFullYear()) {
      return `${start.getDate()} ${strings.monthNames[start.getMonth()]} - ${end.getDate()} ${strings.monthNames[end.getMonth()]} ${endYear}`;
    }

    return `${start.getDate()} ${strings.monthNames[start.getMonth()]} ${startYear} - ${end.getDate()} ${strings.monthNames[end.getMonth()]} ${endYear}`;
  },

  /**
   * Format date for display
   */
  formatDate(date, locale = 'en') {
    const safeDate = this.resolveDateValue(date, 'date');
    if (!safeDate) {
      return '';
    }

    const strings = this.getLocaleStrings(locale);
    const fallback = `${safeDate.getDate()} ${strings.monthNames[safeDate.getMonth()]} ${this.formatDisplayYear(safeDate, locale)}`;

    if (window.Utils?.date?.format) {
      const formattedValue = Utils.date.format(safeDate, 'D MMMM YYYY', locale);
      if (formattedValue) {
        return formattedValue;
      }
    }

    return fallback;
  },

  /**
   * Format time
   */
  formatTime(date) {
    const hours = date.getHours().toString().padStart(2, '0');
    const minutes = date.getMinutes().toString().padStart(2, '0');
    return `${hours}:${minutes}`;
  },

  /**
   * Escape HTML entities
   */
  escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  },

  /**
   * Emit event
   */
  emitEvent(eventName, data) {
    if (window.EventManager?.emit) {
      EventManager.emit(eventName, data);
    }

    // Also dispatch DOM event
    const event = new CustomEvent(eventName, {
      detail: data,
      bubbles: true
    });
    document.dispatchEvent(event);
  },

  // ========== Public API ==========

  /**
   * Add event
   */
  addEvent(elementOrInstance, event) {
    const instance = this.getInstance(elementOrInstance);
    if (!instance) return;

    const normalizedEvent = this.normalizeEvents([event], instance.config)[0];
    instance.events.push(normalizedEvent);
    instance.segmentCache.clear(); // Clear cache when events change
    this.render(instance);

    this.emitEvent('eventcalendar:eventAdd', {
      event: normalizedEvent,
      instance
    });
  },

  /**
   * Remove event
   */
  removeEvent(elementOrInstance, eventId) {
    const instance = this.getInstance(elementOrInstance);
    if (!instance) return;

    instance.events = instance.events.filter(e => e.id !== eventId);
    instance.segmentCache.clear(); // Clear cache when events change
    this.render(instance);

    this.emitEvent('eventcalendar:eventRemove', {
      eventId,
      instance
    });
  },

  /**
   * Update event
   */
  updateEvent(elementOrInstance, eventId, data) {
    const instance = this.getInstance(elementOrInstance);
    if (!instance) return;

    const index = instance.events.findIndex(e => e.id === eventId);
    if (index !== -1) {
      const normalizedEvent = this.normalizeEvents([
        {...instance.events[index], ...data}
      ], instance.config)[0];
      instance.events[index] = normalizedEvent;
      instance.segmentCache.clear(); // Clear cache when events change
      this.render(instance);

      this.emitEvent('eventcalendar:eventUpdate', {
        event: instance.events[index],
        instance
      });
    }
  },

  /**
   * Get events
   */
  getEvents(elementOrInstance, start = null, end = null) {
    const instance = this.getInstance(elementOrInstance);
    if (!instance) return [];

    let events = [...instance.events];

    if (start && end) {
      events = events.filter(e =>
        e.start <= end && e.end >= start
      );
    }

    return events;
  },

  /**
   * Refresh events from API
   */
  refreshEvents(elementOrInstance) {
    const instance = this.getInstance(elementOrInstance);
    if (instance && instance.config.api) {
      this.loadEvents(instance);
    }
  },

  /**
   * Set events directly
   */
  setEvents(elementOrInstance, events) {
    const instance = this.getInstance(elementOrInstance);
    if (!instance) return;

    instance.events = this.normalizeEvents(events, instance.config);
    instance.segmentCache.clear(); // Clear cache when events change
    this.render(instance);
  },

  /**
   * Get instance
   */
  getInstance(elementOrInstance) {
    if (elementOrInstance && elementOrInstance.element) {
      return elementOrInstance; // Already an instance
    }

    if (typeof elementOrInstance === 'string') {
      elementOrInstance = document.querySelector(elementOrInstance);
    }

    return elementOrInstance?._eventCalendar || null;
  },

  /**
   * Destroy instance
   */
  destroy(elementOrInstance) {
    const instance = this.getInstance(elementOrInstance);
    if (!instance) return;

    const {element} = instance;

    // Remove all tracked event listeners
    if (instance.eventListeners && instance.eventListeners.length > 0) {
      instance.eventListeners.forEach(({element: el, event, handler, options}) => {
        el.removeEventListener(event, handler, options);
      });
      instance.eventListeners = [];
    }

    // Clear intervals/timeouts if any
    if (instance.updateTimer) {
      clearInterval(instance.updateTimer);
    }

    // Clear DOM
    element.innerHTML = '';
    element.classList.remove('event-calendar');

    // Remove references
    delete element._eventCalendar;
    this.state.instances.delete(element);

    // Emit destroy event
    this.emitEvent('eventcalendar:destroy', {element});
  }
};

// Register with Now framework if available
if (window.Now?.registerManager) {
  Now.registerManager('eventCalendar', EventCalendar);
}

// Expose globally (but don't auto-init - let app decide)
window.EventCalendar = EventCalendar;

// Auto-initialize when DOM is ready (only if not already initialized)
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => {
    EventCalendar.init();
  });
} else {
  EventCalendar.init();
}
