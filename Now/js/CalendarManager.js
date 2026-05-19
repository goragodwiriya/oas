/**
 * CalendarManager - Advanced Calendar Component
 * Supports multiple view modes: month, week, day, timeline
 * Features continuous timeline view and modern UI
 */
const CalendarManager = {
  config: {
    defaultView: 'month', // 'month', 'week', 'day', 'timeline'
    locale: 'auto', // 'auto' = detect from I18nManager, or specific locale like 'th', 'en'
    timezone: 'Asia/Bangkok',
    firstDayOfWeek: 0, // 0 = Sunday, 1 = Monday
    showWeekNumbers: false,
    showToday: true,
    allowViewChange: true,
    eventHeight: 20,
    timelineHeight: 60,
    workingHours: {start: 8, end: 18},
    weekends: [0, 6], // Sunday, Saturday
    minDate: null,
    maxDate: null,
    events: [],
    // Date format patterns (uses Utils.date.format tokens)
    dateFormats: {
      day: 'D MMMM YYYY',        // Day view header
      week: 'D - ',              // Week view range (partial)
      month: 'MMMM YYYY',        // Month view header
      cell: 'YYYY-MM-DD'         // Cell data attribute (ISO format)
    },
    callbacks: {
      onDateClick: null,
      onEventClick: null,
      onViewChange: null,
      onDateChange: null
    }
  },

  state: {
    calendars: new Map(),
    currentDate: new Date(),
    currentView: 'month',
    initialized: false
  },

  init(options = {}) {
    if (this.state.initialized) return this;

    this.config = {...this.config, ...options};
    this.state.currentDate = new Date();
    this.state.currentView = this.config.defaultView;

    // Initialize any existing elements with data-calendar attribute
    document.querySelectorAll('[data-calendar]').forEach(element => {
      this.create(element);
    });

    // Listen for locale changes to re-render all calendars
    if (window.EventManager?.on) {
      EventManager.on('locale:changed', () => {
        this.state.calendars.forEach((instance) => {
          this.render(instance);
        });
      });
    }

    this.state.initialized = true;
    return this;
  },

  create(element, options = {}) {
    if (typeof element === 'string') {
      element = document.getElementById(element);
    }

    if (!element) return null;

    if (element.calendarInstance) {
      return element.calendarInstance;
    }

    const dataOptions = this.extractDataOptions(element);
    const config = {...this.config, ...dataOptions, ...options};

    const instance = {
      element,
      config,
      currentDate: new Date(),
      currentView: config.defaultView,
      events: [...config.events],
      isRendering: false
    };

    element.calendarInstance = instance;
    this.state.calendars.set(element, instance);

    this.setupCalendar(instance);
    this.render(instance);

    return instance;
  },

  extractDataOptions(element) {
    const options = {};
    const dataset = element.dataset;

    if (dataset.calendar) {
      options.defaultView = dataset.calendar;
    }

    if (dataset.calendarLocale) {
      options.locale = dataset.calendarLocale;
    }

    if (dataset.calendarFirstDay) {
      options.firstDayOfWeek = parseInt(dataset.calendarFirstDay);
    }

    if (dataset.calendarEvents) {
      try {
        options.events = JSON.parse(dataset.calendarEvents);
      } catch (e) {
        console.warn('Invalid events JSON:', dataset.calendarEvents);
      }
    }

    return options;
  },

  setupCalendar(instance) {
    const {element, config} = instance;

    element.className = `calendar-container ${element.className}`.trim();
    element.innerHTML = '';

    // Create header
    const header = document.createElement('div');
    header.className = 'calendar-header';
    element.appendChild(header);

    // Create navigation
    this.createNavigation(instance, header);

    // Create view switcher
    if (config.allowViewChange) {
      this.createViewSwitcher(instance, header);
    }

    // Create main content area
    const content = document.createElement('div');
    content.className = 'calendar-content';
    element.appendChild(content);

    instance.headerElement = header;
    instance.contentElement = content;
  },

  createNavigation(instance, container) {
    const nav = document.createElement('div');
    nav.className = 'calendar-nav';

    // Previous button
    const prevBtn = document.createElement('button');
    prevBtn.className = 'nav-btn prev';
    prevBtn.innerHTML = '‹';
    prevBtn.title = 'Previous';
    prevBtn.addEventListener('click', () => this.navigate(instance, -1));

    // Current date/period display
    const current = document.createElement('div');
    current.className = 'current-period';
    instance.currentPeriodElement = current;

    // Next button
    const nextBtn = document.createElement('button');
    nextBtn.className = 'nav-btn next';
    nextBtn.innerHTML = '›';
    nextBtn.title = 'Next';
    nextBtn.addEventListener('click', () => this.navigate(instance, 1));

    // Today button
    const todayBtn = document.createElement('button');
    todayBtn.className = 'nav-btn today';
    todayBtn.textContent = 'Today';
    todayBtn.addEventListener('click', () => this.goToToday(instance));

    nav.appendChild(prevBtn);
    nav.appendChild(current);
    nav.appendChild(nextBtn);
    if (instance.config.showToday) {
      nav.appendChild(todayBtn);
    }

    container.appendChild(nav);
  },

  createViewSwitcher(instance, container) {
    const switcher = document.createElement('div');
    switcher.className = 'view-switcher';

    const views = [
      {key: 'day', label: 'Day', icon: '📅'},
      {key: 'week', label: 'Week', icon: '📊'},
      {key: 'month', label: 'Month', icon: '🗓️'},
      {key: 'timeline', label: 'Timeline', icon: '📈'}
    ];

    views.forEach(view => {
      const btn = document.createElement('button');
      btn.className = `view-btn ${view.key}`;
      btn.innerHTML = `${view.icon} ${view.label}`;
      btn.addEventListener('click', () => this.changeView(instance, view.key));
      switcher.appendChild(btn);
    });

    container.appendChild(switcher);
    instance.viewSwitcherElement = switcher;
  },

  render(instance) {
    if (instance.isRendering) return;
    instance.isRendering = true;

    try {
      this.updateCurrentPeriod(instance);
      this.updateViewSwitcher(instance);

      switch (instance.currentView) {
        case 'day':
          this.renderDayView(instance);
          break;
        case 'week':
          this.renderWeekView(instance);
          break;
        case 'month':
          this.renderMonthView(instance);
          break;
        case 'timeline':
          this.renderTimelineView(instance);
          break;
      }

      this.renderEvents(instance);
    } finally {
      instance.isRendering = false;
    }
  },

  renderMonthView(instance) {
    const {contentElement, currentDate, config} = instance;
    contentElement.innerHTML = '';
    contentElement.className = 'calendar-content month-view';

    const table = document.createElement('table');
    table.className = 'month-table';

    // Create header row with day names
    const thead = document.createElement('thead');
    const headerRow = document.createElement('tr');

    const dayNames = this.getDayNames(config);
    for (let i = 0; i < 7; i++) {
      const dayIndex = (config.firstDayOfWeek + i) % 7;
      const th = document.createElement('th');
      th.textContent = dayNames[dayIndex];
      th.className = config.weekends.includes(dayIndex) ? 'weekend' : '';
      headerRow.appendChild(th);
    }
    thead.appendChild(headerRow);
    table.appendChild(thead);

    // Create calendar grid
    const tbody = document.createElement('tbody');
    const startOfMonth = new Date(currentDate.getFullYear(), currentDate.getMonth(), 1);
    const endOfMonth = new Date(currentDate.getFullYear(), currentDate.getMonth() + 1, 0);

    // Calculate start date (might be from previous month)
    const startDate = new Date(startOfMonth);
    const dayOfWeek = (startOfMonth.getDay() - config.firstDayOfWeek + 7) % 7;
    startDate.setDate(startDate.getDate() - dayOfWeek);

    // Create 6 weeks
    for (let week = 0; week < 6; week++) {
      const row = document.createElement('tr');

      for (let day = 0; day < 7; day++) {
        const cellDate = new Date(startDate);
        cellDate.setDate(startDate.getDate() + (week * 7) + day);

        const cell = document.createElement('td');
        cell.className = this.getCellClasses(cellDate, currentDate, config);
        cell.setAttribute('data-date', this.formatDate(cellDate, 'YYYY-MM-DD'));

        const dayNumber = document.createElement('div');
        dayNumber.className = 'day-number';
        dayNumber.textContent = cellDate.getDate();

        const eventsContainer = document.createElement('div');
        eventsContainer.className = 'day-events';

        cell.appendChild(dayNumber);
        cell.appendChild(eventsContainer);

        // Add click handler
        cell.addEventListener('click', (e) => {
          this.handleDateClick(instance, cellDate, e);
        });

        row.appendChild(cell);
      }

      tbody.appendChild(row);
    }

    table.appendChild(tbody);
    contentElement.appendChild(table);
  },

  renderWeekView(instance) {
    const {contentElement, currentDate, config} = instance;
    contentElement.innerHTML = '';
    contentElement.className = 'calendar-content week-view';

    const weekStart = this.getWeekStart(currentDate, config.firstDayOfWeek);

    // Create week header
    const weekHeader = document.createElement('div');
    weekHeader.className = 'week-header';

    for (let i = 0; i < 7; i++) {
      const date = new Date(weekStart);
      date.setDate(weekStart.getDate() + i);

      const dayCol = document.createElement('div');
      dayCol.className = `day-column ${this.getCellClasses(date, currentDate, config)}`;
      dayCol.setAttribute('data-date', this.formatDate(date, 'YYYY-MM-DD'));

      const dayName = document.createElement('div');
      dayName.className = 'day-name';
      dayName.textContent = this.getDayNames(config)[date.getDay()];

      const dayNumber = document.createElement('div');
      dayNumber.className = 'day-number';
      dayNumber.textContent = date.getDate();

      dayCol.appendChild(dayName);
      dayCol.appendChild(dayNumber);

      // Add click handler
      dayCol.addEventListener('click', (e) => {
        this.handleDateClick(instance, date, e);
      });

      weekHeader.appendChild(dayCol);
    }

    contentElement.appendChild(weekHeader);

    // Create time slots
    const timeGrid = document.createElement('div');
    timeGrid.className = 'time-grid';

    for (let hour = 0; hour < 24; hour++) {
      const timeSlot = document.createElement('div');
      timeSlot.className = 'time-slot';
      timeSlot.setAttribute('data-hour', hour);

      const timeLabel = document.createElement('div');
      timeLabel.className = 'time-label';
      timeLabel.textContent = `${hour.toString().padStart(2, '0')}:00`;

      const daySlots = document.createElement('div');
      daySlots.className = 'day-slots';

      for (let i = 0; i < 7; i++) {
        const daySlot = document.createElement('div');
        daySlot.className = 'day-slot';
        daySlots.appendChild(daySlot);
      }

      timeSlot.appendChild(timeLabel);
      timeSlot.appendChild(daySlots);
      timeGrid.appendChild(timeSlot);
    }

    contentElement.appendChild(timeGrid);
  },

  renderDayView(instance) {
    const {contentElement, currentDate, config} = instance;
    contentElement.innerHTML = '';
    contentElement.className = 'calendar-content day-view';

    // Create day header
    const dayHeader = document.createElement('div');
    dayHeader.className = 'day-header';

    const dayName = document.createElement('h2');
    dayName.textContent = this.formatDate(currentDate, 'EEEE, MMMM d, yyyy', config.locale);
    dayHeader.appendChild(dayName);

    contentElement.appendChild(dayHeader);

    // Create hourly time slots
    const timeGrid = document.createElement('div');
    timeGrid.className = 'day-time-grid';

    for (let hour = 0; hour < 24; hour++) {
      const timeSlot = document.createElement('div');
      timeSlot.className = 'hour-slot';
      timeSlot.setAttribute('data-hour', hour);

      const timeLabel = document.createElement('div');
      timeLabel.className = 'time-label';
      timeLabel.textContent = `${hour.toString().padStart(2, '0')}:00`;

      const eventArea = document.createElement('div');
      eventArea.className = 'event-area';

      timeSlot.appendChild(timeLabel);
      timeSlot.appendChild(eventArea);
      timeGrid.appendChild(timeSlot);
    }

    contentElement.appendChild(timeGrid);
  },

  renderTimelineView(instance) {
    const {contentElement, currentDate, config} = instance;
    contentElement.innerHTML = '';
    contentElement.className = 'calendar-content timeline-view';

    // Create timeline container
    const timeline = document.createElement('div');
    timeline.className = 'timeline-container';

    // Calculate date range (show 30 days)
    const startDate = new Date(currentDate);
    startDate.setDate(currentDate.getDate() - 15);

    const endDate = new Date(currentDate);
    endDate.setDate(currentDate.getDate() + 15);

    // Create continuous timeline bar
    const timelineBar = document.createElement('div');
    timelineBar.className = 'timeline-bar';

    const totalDays = Math.ceil((endDate - startDate) / (1000 * 60 * 60 * 24));

    for (let i = 0; i < totalDays; i++) {
      const date = new Date(startDate);
      date.setDate(startDate.getDate() + i);

      const daySegment = document.createElement('div');
      daySegment.className = `timeline-day ${this.getCellClasses(date, currentDate, config)}`;
      daySegment.setAttribute('data-date', this.formatDate(date, 'YYYY-MM-DD'));
      daySegment.style.width = `${100 / totalDays}%`;

      const dayLabel = document.createElement('div');
      dayLabel.className = 'day-label';
      dayLabel.innerHTML = `
        <div class="day-number">${date.getDate()}</div>
        <div class="day-name">${this.getDayNames(config)[date.getDay()].substr(0, 3)}</div>
      `;

      const eventTrack = document.createElement('div');
      eventTrack.className = 'event-track';

      daySegment.appendChild(dayLabel);
      daySegment.appendChild(eventTrack);

      // Add click handler
      daySegment.addEventListener('click', (e) => {
        this.handleDateClick(instance, date, e);
      });

      timelineBar.appendChild(daySegment);
    }

    timeline.appendChild(timelineBar);
    contentElement.appendChild(timeline);

    // Add scroll to center current date
    setTimeout(() => {
      const currentDayElement = timelineBar.querySelector('.current-day');
      if (currentDayElement) {
        currentDayElement.scrollIntoView({behavior: 'smooth', block: 'center', inline: 'center'});
      }
    }, 100);
  },

  renderEvents(instance) {
    const {events, currentView, contentElement} = instance;

    events.forEach(event => {
      this.renderEvent(instance, event);
    });
  },

  renderEvent(instance, event) {
    const {currentView, contentElement} = instance;
    const eventDate = new Date(event.date);
    const dateStr = this.formatDate(eventDate, 'YYYY-MM-DD');

    let targetElement;

    switch (currentView) {
      case 'month':
        targetElement = contentElement.querySelector(`[data-date="${dateStr}"] .day-events`);
        if (targetElement) {
          const eventEl = this.createEventElement(event, 'month');
          targetElement.appendChild(eventEl);
        }
        break;

      case 'week':
      case 'day':
        // Find appropriate time slot
        const hour = eventDate.getHours();
        targetElement = contentElement.querySelector(`[data-hour="${hour}"] .event-area`);
        if (targetElement) {
          const eventEl = this.createEventElement(event, currentView);
          targetElement.appendChild(eventEl);
        }
        break;

      case 'timeline':
        targetElement = contentElement.querySelector(`[data-date="${dateStr}"] .event-track`);
        if (targetElement) {
          const eventEl = this.createEventElement(event, 'timeline');
          targetElement.appendChild(eventEl);
        }
        break;
    }
  },

  createEventElement(event, viewType) {
    const eventEl = document.createElement('div');
    eventEl.className = `calendar-event ${event.type || ''} ${viewType}-event`;

    if (event.color) {
      eventEl.style.backgroundColor = event.color;
      eventEl.style.borderColor = event.color;
    }

    const title = document.createElement('div');
    title.className = 'event-title';
    title.textContent = event.title;

    if (viewType !== 'timeline') {
      const time = document.createElement('div');
      time.className = 'event-time';
      if (event.time) {
        time.textContent = event.time;
      }
      eventEl.appendChild(time);
    }

    eventEl.appendChild(title);

    // Add click handler
    eventEl.addEventListener('click', (e) => {
      e.stopPropagation();
      this.handleEventClick(event, e);
    });

    return eventEl;
  },

  // Navigation methods
  navigate(instance, direction) {
    const {currentView, currentDate} = instance;

    switch (currentView) {
      case 'day':
        currentDate.setDate(currentDate.getDate() + direction);
        break;
      case 'week':
        currentDate.setDate(currentDate.getDate() + (direction * 7));
        break;
      case 'month':
        currentDate.setMonth(currentDate.getMonth() + direction);
        break;
      case 'timeline':
        currentDate.setDate(currentDate.getDate() + (direction * 15));
        break;
    }

    this.render(instance);
    this.emitEvent('calendar:dateChange', {instance, date: new Date(currentDate)});
  },

  goToToday(instance) {
    instance.currentDate = new Date();
    this.render(instance);
    this.emitEvent('calendar:dateChange', {instance, date: new Date(instance.currentDate)});
  },

  changeView(instance, view) {
    instance.currentView = view;
    this.render(instance);
    this.emitEvent('calendar:viewChange', {instance, view});
  },

  // Utility methods
  getCellClasses(date, currentDate, config) {
    const classes = [];
    const today = new Date();

    if (this.isSameDay(date, today)) {
      classes.push('today');
    }

    if (this.isSameDay(date, currentDate)) {
      classes.push('current-day');
    }

    if (date.getMonth() !== currentDate.getMonth()) {
      classes.push('other-month');
    }

    if (config.weekends.includes(date.getDay())) {
      classes.push('weekend');
    }

    return classes.join(' ');
  },

  getDayNames(config) {
    const locale = this.getLocale(config);
    // Use Utils.date if available
    if (window.Utils?.date?.getDayNames) {
      return Utils.date.getDayNames(locale);
    }
    // Fallback to browser
    const names = [];
    const date = new Date(2024, 0, 7); // Start with Sunday
    for (let i = 0; i < 7; i++) {
      names.push(date.toLocaleDateString(locale, {weekday: 'short'}));
      date.setDate(date.getDate() + 1);
    }
    return names;
  },

  getWeekStart(date, firstDayOfWeek) {
    const start = new Date(date);
    const day = start.getDay();
    const diff = (day - firstDayOfWeek + 7) % 7;
    start.setDate(start.getDate() - diff);
    return start;
  },

  isSameDay(date1, date2) {
    return date1.getFullYear() === date2.getFullYear() &&
      date1.getMonth() === date2.getMonth() &&
      date1.getDate() === date2.getDate();
  },

  /**
   * Get current locale - auto-detects from I18nManager if set to 'auto'
   */
  getLocale(config) {
    if (config.locale && config.locale !== 'auto') {
      return config.locale;
    }
    // Auto-detect from I18nManager
    const i18n = window.Now?.getManager?.('i18n') || window.I18nManager;
    if (i18n?.getCurrentLocale) {
      return i18n.getCurrentLocale();
    }
    return document.documentElement?.getAttribute('lang') || 'en';
  },

  /**
   * Format date using Utils.date.format with locale support
   * @param {Date} date - Date to format
   * @param {string} format - Format pattern (Utils.date tokens)
   * @param {string} locale - Locale override (optional)
   */
  formatDate(date, format = 'YYYY-MM-DD', locale = null) {
    // Use Utils.date.format if available
    if (window.Utils?.date?.format) {
      return Utils.date.format(date, format, locale);
    }
    // Fallback for ISO format
    if (format === 'YYYY-MM-DD') {
      const y = date.getFullYear();
      const m = String(date.getMonth() + 1).padStart(2, '0');
      const d = String(date.getDate()).padStart(2, '0');
      return `${y}-${m}-${d}`;
    }
    // Fallback to browser formatting
    return date.toLocaleDateString(locale || 'en', {
      year: 'numeric',
      month: 'long',
      day: 'numeric'
    });
  },

  updateCurrentPeriod(instance) {
    const {currentDate, currentView, config} = instance;
    const locale = this.getLocale(config);
    const formats = config.dateFormats || this.config.dateFormats;
    let text = '';

    switch (currentView) {
      case 'day':
        text = this.formatDate(currentDate, formats.day || 'D MMMM YYYY', locale);
        break;
      case 'week':
        const weekStart = this.getWeekStart(currentDate, config.firstDayOfWeek);
        const weekEnd = new Date(weekStart);
        weekEnd.setDate(weekStart.getDate() + 6);
        // Format: "1 - 7 มกราคม 2569" or "1 - 7 January 2026"
        text = `${weekStart.getDate()} - ${weekEnd.getDate()} ${this.formatDate(weekEnd, 'MMMM YYYY', locale)}`;
        break;
      case 'month':
        text = this.formatDate(currentDate, formats.month || 'MMMM YYYY', locale);
        break;
      case 'timeline':
        text = `Timeline - ${this.formatDate(currentDate, formats.month || 'MMMM YYYY', locale)}`;
        break;
    }

    if (instance.currentPeriodElement) {
      instance.currentPeriodElement.textContent = text;
    }
  },

  updateViewSwitcher(instance) {
    if (!instance.viewSwitcherElement) return;

    const buttons = instance.viewSwitcherElement.querySelectorAll('.view-btn');
    buttons.forEach(btn => {
      btn.classList.toggle('active', btn.classList.contains(instance.currentView));
    });
  },

  // Event handlers
  handleDateClick(instance, date, event) {
    if (instance.config.callbacks.onDateClick) {
      instance.config.callbacks.onDateClick.call(instance.element, date, event);
    }

    this.emitEvent('calendar:dateClick', {instance, date, event});
  },

  handleEventClick(eventData, event) {
    if (this.config.callbacks.onEventClick) {
      this.config.callbacks.onEventClick.call(null, eventData, event);
    }

    this.emitEvent('calendar:eventClick', {event: eventData, domEvent: event});
  },

  // Public API
  addEvent(elementOrInstance, event) {
    const instance = typeof elementOrInstance === 'string' ?
      this.getInstance(elementOrInstance) : elementOrInstance;

    if (instance) {
      instance.events.push(event);
      this.render(instance);
    }
  },

  removeEvent(elementOrInstance, eventId) {
    const instance = typeof elementOrInstance === 'string' ?
      this.getInstance(elementOrInstance) : elementOrInstance;

    if (instance) {
      instance.events = instance.events.filter(e => e.id !== eventId);
      this.render(instance);
    }
  },

  getInstance(element) {
    if (typeof element === 'string') {
      element = document.getElementById(element);
    }
    return element ? this.state.calendars.get(element) : null;
  },

  emitEvent(eventName, data) {
    EventManager.emit(eventName, data);
  },

  destroy(instance) {
    if (typeof instance === 'string') {
      instance = this.getInstance(instance);
    }

    if (!instance) return;

    const {element} = instance;
    element.innerHTML = '';
    element.className = element.className.replace('calendar-container', '').trim();

    delete element.calendarInstance;
    this.state.calendars.delete(element);
  }
};

// Register with Now framework if available
if (window.Now?.registerManager) {
  Now.registerManager('calendar', CalendarManager);
}

// Auto-initialize when DOM is ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => {
    CalendarManager.init();
  });
} else {
  CalendarManager.init();
}

// Expose for backward compatibility
if (!window.Calendar) {
  window.Calendar = CalendarManager;
}
