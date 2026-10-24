/**
 * EmbeddedDateTime
 *
 * A hand-built date/time picker that replaces the native `<input type="date">`
 * and friends, so the calendar UI looks and behaves the same across browsers.
 * Supports date, time and datetime-local modes. Instantiated once per element
 * by `DateElementFactory`; the original input is hidden but kept so the form
 * still submits its value.
 */

// Helper functions
function _pad(n) {
  return n < 10 ? '0' + n : String(n);
}

function _toDateISO(d) {
  return d.getFullYear() + '-' + _pad(d.getMonth() + 1) + '-' + _pad(d.getDate());
}

function _toTimeHM(d) {
  return _pad(d.getHours()) + ':' + _pad(d.getMinutes());
}

function _toLocalDateTime(d) {
  return _toDateISO(d) + 'T' + _toTimeHM(d);
}

function _addDays(d, days) {
  return new Date(d.getFullYear(), d.getMonth(), d.getDate() + days);
}

/**
 * Ready-made ranges offered by data-range-presets="today,7d,thismonth,..."
 * Each range() gets today (local midnight) and returns [start, end].
 */
const DATE_RANGE_PRESETS = {
  today: {label: 'Today', range: t => [t, t]},
  yesterday: {label: 'Yesterday', range: t => [_addDays(t, -1), _addDays(t, -1)]},
  '7d': {label: 'Last 7 days', range: t => [_addDays(t, -6), t]},
  '30d': {label: 'Last 30 days', range: t => [_addDays(t, -29), t]},
  thismonth: {label: 'This month', range: t => [new Date(t.getFullYear(), t.getMonth(), 1), new Date(t.getFullYear(), t.getMonth() + 1, 0)]},
  lastmonth: {label: 'Last month', range: t => [new Date(t.getFullYear(), t.getMonth() - 1, 1), new Date(t.getFullYear(), t.getMonth(), 0)]},
  thisyear: {label: 'This year', range: t => [new Date(t.getFullYear(), 0, 1), new Date(t.getFullYear(), 11, 31)]}
};

/**
 * Normalize various date/time representations into formats suitable for
 * `input[type=date]`, `input[type=datetime-local]`, and `input[type=time]`.
 * Returns null when unable to parse.
 */
function normalizeDateForInput(value, type = 'date') {
  // Prefer centralized parser if available
  try {
    const parsed = window.Utils?.date?.parse ? window.Utils.date.parse(value, type) : null;
    if (parsed instanceof Date && !Number.isNaN(parsed.getTime())) {
      if (type === 'date') return _toDateISO(parsed);
      if (type === 'datetime-local') return _toLocalDateTime(parsed).slice(0, 16);
      if (type === 'time') return _toTimeHM(parsed);
    }
  } catch (e) {
    // fall through to local heuristics
  }

  if (value == null) return null;
  let v = String(value).trim();
  if (!v) return null;

  // Fallback heuristics (keep previous robust handling)
  if (/^\d+$/.test(v)) {
    // seconds -> ms
    if (v.length === 10) v = String(parseInt(v, 10) * 1000);
    const d = new Date(Number(v));
    if (!Number.isNaN(d.getTime())) {
      if (type === 'date') return _toDateISO(d);
      if (type === 'datetime-local') return _toLocalDateTime(d).slice(0, 16);
      if (type === 'time') return _toTimeHM(d);
    }
  }

  // Common formats: YYYY-MM-DD, YYYY-MM-DDTHH:MM(:SS)?, YYYY-MM-DD HH:MM:SS
  const isoMatch = v.match(/^(\d{4}-\d{2}-\d{2})(?:[T\s](\d{2}:\d{2}(?::\d{2})?))?/);
  if (isoMatch) {
    const datePart = isoMatch[1];
    const timePart = isoMatch[2] || '';
    if (type === 'date') return datePart;
    if (type === 'datetime-local') {
      if (timePart) return `${datePart}T${timePart}`.slice(0, 16);
      return `${datePart}T00:00`;
    }
    if (type === 'time') return timePart ? timePart.split(':').slice(0, 2).join(':') : null;
  }

  // Slash-separated YYYY/MM/DD or DD/MM/YYYY (try detect)
  const slashMatch = v.match(/^(\d{2,4})[\/\-](\d{1,2})[\/\-](\d{1,4})(?:[\sT](\d{2}:\d{2}(?::\d{2})?))?/);
  if (slashMatch) {
    let a = slashMatch[1], b = slashMatch[2], c = slashMatch[3], timePart = slashMatch[4] || '';
    // Heuristic: if first segment is 4 digits -> YYYY/MM/DD
    let yyyy, mm, dd;
    if (a.length === 4) {
      yyyy = a; mm = _pad(parseInt(b, 10)); dd = _pad(parseInt(c, 10));
    } else if (c.length === 4) {
      // DD/MM/YYYY
      yyyy = c; mm = _pad(parseInt(b, 10)); dd = _pad(parseInt(a, 10));
    } else {
      // Fallback try Date.parse
      const d = new Date(v);
      if (!Number.isNaN(d.getTime())) {
        if (type === 'date') return _toDateISO(d);
        if (type === 'datetime-local') return _toLocalDateTime(d).slice(0, 16);
        if (type === 'time') return _toTimeHM(d);
      }
      return null;
    }

    const dateStr = `${yyyy}-${mm}-${dd}`;
    if (type === 'date') return dateStr;
    if (type === 'datetime-local') return `${dateStr}T${(timePart || '00:00')}`.slice(0, 16);
    if (type === 'time') return timePart ? timePart.split(':').slice(0, 2).join(':') : null;
  }

  // Fallback to Date.parse
  const d = new Date(v);
  if (!Number.isNaN(d.getTime())) {
    if (type === 'date') return _toDateISO(d);
    if (type === 'datetime-local') return _toLocalDateTime(d).slice(0, 16);
    if (type === 'time') return _toTimeHM(d);
  }

  return null;
}

/**
 * Parse a variety of input date/time strings into a local Date object.
 * Returns null when parsing fails.
 */
function parseDateFromInput(value, type = 'date') {
  if (value == null) return null;
  let v = String(value).trim();
  if (!v) return null;

  // Numeric timestamp (seconds or milliseconds)
  if (/^\d+$/.test(v)) {
    if (v.length === 10) v = String(parseInt(v, 10) * 1000);
    const d = new Date(Number(v));
    return Number.isNaN(d.getTime()) ? null : d;
  }

  // ISO-like: YYYY-MM-DD[ T]HH:MM[:SS]
  const iso = v.match(/^(\d{4})-(\d{2})-(\d{2})(?:[T\s](\d{2}):(\d{2})(?::(\d{2}))?)?/);
  if (iso) {
    const y = parseInt(iso[1], 10);
    const mo = parseInt(iso[2], 10) - 1;
    const d = parseInt(iso[3], 10);
    const hh = parseInt(iso[4] || '0', 10);
    const mm = parseInt(iso[5] || '0', 10);
    const ss = parseInt(iso[6] || '0', 10);
    return new Date(y, mo, d, hh, mm, ss, 0);
  }

  // Slash or dash ambiguous formats: try YYYY/MM/DD, DD/MM/YYYY heuristics
  const slash = v.match(/^(\d{1,4})[\/\-](\d{1,2})[\/\-](\d{1,4})(?:[\sT](\d{2}):(\d{2})(?::(\d{2}))?)?/);
  if (slash) {
    const a = slash[1], b = slash[2], c = slash[3];
    const hh = parseInt(slash[4] || '0', 10);
    const mm = parseInt(slash[5] || '0', 10);
    const ss = parseInt(slash[6] || '0', 10);
    let y, mo, day;
    if (a.length === 4) {
      y = parseInt(a, 10); mo = parseInt(b, 10) - 1; day = parseInt(c, 10);
    } else if (c.length === 4) {
      y = parseInt(c, 10); mo = parseInt(b, 10) - 1; day = parseInt(a, 10);
    } else {
      const d = new Date(v);
      return Number.isNaN(d.getTime()) ? null : d;
    }
    return new Date(y, mo, day, hh, mm, ss, 0);
  }

  // Fallback to Date.parse (may treat timezone ambiguously)
  const fallback = new Date(v);
  return Number.isNaN(fallback.getTime()) ? null : fallback;
}
/**
 * Get current locale from framework-managed state
 */
function _getCurrentLocale() {
  const i18n = window.Now?.getManager?.('i18n') || window.I18nManager;
  if (i18n?.getCurrentLocale) {
    return i18n.getCurrentLocale();
  }

  if (window.LanguageManager?.getCurrentLanguage) {
    return window.LanguageManager.getCurrentLanguage();
  }

  return document.documentElement?.getAttribute('lang') || 'en';
}

/**
 * Get localized day names (narrow form)
 * Uses Utils.date.getDayNamesNarrow for consistency
 */
function _getDayNames(locale = null) {
  const loc = locale || _getCurrentLocale();
  // Use Utils.date if available, fallback to inline
  if (window.Utils?.date?.getDayNamesNarrow) {
    return Utils.date.getDayNamesNarrow(loc);
  }
  // Fallback
  const baseLocale = loc.split('-')[0];
  if (baseLocale === 'th') {
    return ['อ', 'จ', 'อ', 'พ', 'พ', 'ศ', 'ส'];
  }
  return ['S', 'M', 'T', 'W', 'T', 'F', 'S'];
}

/**
 * Get localized month names (full form)
 * Uses Utils.date.getMonthNames for consistency
 */
function _getMonthNames(locale = null) {
  const loc = locale || _getCurrentLocale();
  // Use Utils.date if available, fallback to inline
  if (window.Utils?.date?.getMonthNames) {
    return Utils.date.getMonthNames(loc);
  }
  // Fallback
  const baseLocale = loc.split('-')[0];
  if (baseLocale === 'th') {
    return [
      'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน',
      'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'
    ];
  }
  return [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December'
  ];
}

/**
 * Get localized month names (short form)
 * Uses Utils.date.getMonthNamesShort for consistency
 */
function _getMonthNamesShort(locale = null) {
  const loc = locale || _getCurrentLocale();
  // Use Utils.date if available, fallback to inline
  if (window.Utils?.date?.getMonthNamesShort) {
    return Utils.date.getMonthNamesShort(loc);
  }
  // Fallback
  const baseLocale = loc.split('-')[0];
  if (baseLocale === 'th') {
    return [
      'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.',
      'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'
    ];
  }
  return [
    'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
    'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'
  ];
}

/**
 * Custom Date Picker - Complete calendar implementation without native inputs
 */
class EmbeddedDateTime {
  constructor (originalElement) {
    this._original = originalElement;
    if (!this._original) {
      throw new Error('EmbeddedDateTime: Original element is required');
    }

    this.type = (this._original.getAttribute('type') || 'date').toLowerCase();
    this.name = this._original.getAttribute('name') || '';
    this.id = this._original.id || ('gdatetime-' + Math.random().toString(36).slice(2, 8));

    // Get current locale
    this.locale = _getCurrentLocale();

    // Format options (using standard tokens)
    // data-format: output format for database (always use 'th-CE' for CE year in database)
    // data-display-format: display format (auto locale-based BE/CE)
    //
    // Standard tokens: YYYY, MM, DD, MMMM, MMM, etc.
    // Locale determines BE/CE: 'th' = BE, 'th-CE' = CE, 'en' = CE
    //
    // Examples:
    //   data-format="YYYY-MM-DD" with locale="th-CE" → "2000-05-15" (CE for database)
    //   data-display-format="D MMMM YYYY" with locale="th" → "15 พฤษภาคม 2543" (BE for display)
    //   data-display-format="D MMMM YYYY" with locale="en" → "15 May 2000" (CE for display)
    this.outputFormat = this._original.getAttribute('data-format') || 'YYYY-MM-DD';
    this.displayFormat = this._original.getAttribute('data-display-format') || 'D MMM YYYY';

    // Output always uses CE for database compatibility (force 'th-CE' or 'en')
    this.outputLocale = this.locale.startsWith('th') ? 'th-CE' : 'en';
    // Display uses current locale (BE for Thai, CE for others)
    this.displayLocale = this.locale;

    // Initialize state
    this.selectedDate = null;
    this.selectedTime = null; // For datetime-local and time types
    this.currentMonth = new Date().getMonth();
    this.currentYear = new Date().getFullYear();
    this.currentView = 'day'; // 'year', 'month', or 'day'
    this.isOpen = false;

    // Get constraints
    this.min = this._original.getAttribute('min') || null;
    this.max = this._original.getAttribute('max') || null;
    this.required = this._original.hasAttribute('required');
    this.disabled = this._original.hasAttribute('disabled');
    this.readonly = this._original.hasAttribute('readonly');

    // Range mode (date only): this input holds the start of the range and
    // data-range-end names the input that holds the end. One picker sets both;
    // either side may stay empty.
    const rangeEnd = this.type === 'date' ? (this._original.getAttribute('data-range-end') || '').trim() : '';
    this.range = rangeEnd ? {
      endName: rangeEnd,
      endInput: null,
      start: null,
      end: null,
      side: 'start',
      preview: null,
      maxDays: parseInt(this._original.getAttribute('data-range-max-days'), 10) || 0,
      presets: (this._original.getAttribute('data-range-presets') || '')
        .split(',').map(key => key.trim()).filter(key => DATE_RANGE_PRESETS[key]),
      committed: {start: '', end: ''}
    } : null;

    this._initializeElements();
    this._setupEventListeners();
    this._replaceOriginalElement();

    if (this.range) {
      this.range.endInput = this._resolveRangeEndInput(this.range.endName);
      this._translateRangeUi();
      this.setRange(this._original.value || this._original.getAttribute('value') || '', this.range.endInput.value || '');
      return;
    }

    // Set initial value if exists
    const initialValue = this._original.value || this._original.getAttribute('value');
    if (initialValue) {
      this.setValue(initialValue);
    }
  }

  /**
   * The input that holds the end of a range: the field of that name in the
   * same form, or a hidden input created beside the start input.
   *
   * @param {string} name - Name of the end input
   * @returns {HTMLInputElement}
   */
  _resolveRangeEndInput(name) {
    const form = this._original.form || this._original.closest('form');
    const named = form?.elements?.namedItem(name);
    if (named instanceof HTMLInputElement) {
      return named;
    }

    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = name;
    this.wrapper.appendChild(input);
    return input;
  }

  _initializeElements() {
    // Create main wrapper
    const existingFormControl = this._original.closest('.form-control');
    if (existingFormControl) {
      this.wrapper = existingFormControl;
    } else {
      this.wrapper = document.createElement('div');
    }
    this.wrapper.classList.add('custom-datepicker');
    this.wrapper.id = this.id + '_wrapper';

    // Create display button
    this.displayButton = document.createElement('button');
    this.displayButton.type = 'button';
    this.displayButton.className = 'dropdown-button';
    this.wrapper.appendChild(this.displayButton);

    // Create selected items display
    const display = document.createElement('span');
    display.className = 'dropdown-display';
    display.textContent = Now.translate(this._original.placeholder || 'Choose a date');
    this.displayButton.appendChild(display);

    // Create dropdown arrow
    const arrow = document.createElement('span');
    arrow.className = 'dropdown-arrow';
    this.displayButton.appendChild(arrow);

    // hiddenInput will be assigned in _replaceOriginalElement() as the original element
    this.hiddenInput = null;

    // Create calendar dropdown (will be moved to DropdownPanel)
    this.dropdown = document.createElement('div');
    this.dropdown.className = 'datepicker-dropdown';

    // Get DropdownPanel singleton
    this.dropdownPanel = DropdownPanel.getInstance();

    this._createCalendarStructure();
  }

  _createCalendarStructure() {
    if (this.range) {
      this._createRangeTabs();
    }

    // Calendar header
    this.calendarHeader = document.createElement('div');
    this.calendarHeader.className = 'calendar-header';

    // Previous button
    this.prevButton = document.createElement('button');
    this.prevButton.type = 'button';
    this.prevButton.className = 'calendar-nav prev';
    this.prevButton.innerHTML = '‹';
    this.calendarHeader.appendChild(this.prevButton);

    // Month/Year display container
    const displayContainer = document.createElement('div');
    displayContainer.className = 'month-year-container';

    // Month display (clickable)
    this.monthDisplay = document.createElement('button');
    this.monthDisplay.type = 'button';
    this.monthDisplay.className = 'month-display';
    displayContainer.appendChild(this.monthDisplay);

    // Year display (clickable)
    this.yearDisplay = document.createElement('button');
    this.yearDisplay.type = 'button';
    this.yearDisplay.className = 'year-display';
    displayContainer.appendChild(this.yearDisplay);

    this.calendarHeader.appendChild(displayContainer);

    // Next button
    this.nextButton = document.createElement('button');
    this.nextButton.type = 'button';
    this.nextButton.className = 'calendar-nav next';
    this.nextButton.innerHTML = '›';
    this.calendarHeader.appendChild(this.nextButton);

    this.dropdown.appendChild(this.calendarHeader);

    // Days of week header (only for day view)
    this.daysHeader = document.createElement('div');
    this.daysHeader.className = 'days-header';
    _getDayNames(this.locale).forEach(day => {
      const dayElement = document.createElement('div');
      dayElement.className = 'day-name';
      dayElement.textContent = day;
      this.daysHeader.appendChild(dayElement);
    });
    this.dropdown.appendChild(this.daysHeader);

    // Calendar grid (multi-purpose: days/months/years)
    this.calendarGrid = document.createElement('div');
    this.calendarGrid.className = 'calendar-grid';
    this.dropdown.appendChild(this.calendarGrid);

    if (this.range) {
      // Leaving the grid ends the hover preview of the range being picked
      this.calendarGrid.addEventListener('mouseleave', () => {
        if (this.range.preview) {
          this.range.preview = null;
          this._refreshRangeDays();
        }
      });
      this._createRangePresets();
    }

    // Time picker section (for datetime-local and time types)
    if (this.type === 'datetime-local') {
      this._createTimePicker();
    }
  }

  /**
   * The From / To tabs above a range calendar. The active tab is the side the
   * next day clicked goes to; × empties that side only.
   *
   * @returns {void}
   */
  _createRangeTabs() {
    this.dropdown.classList.add('daterange');
    const tabs = document.createElement('div');
    tabs.className = 'daterange-tabs';
    this.range.tabs = {};

    ['start', 'end'].forEach(side => {
      const tab = document.createElement('div');
      tab.className = 'daterange-tab';
      tab.dataset.side = side;

      const pick = document.createElement('button');
      pick.type = 'button';
      pick.className = 'daterange-pick';
      const label = document.createElement('span');
      label.className = 'daterange-label';
      const value = document.createElement('span');
      value.className = 'daterange-value';
      pick.append(label, value);

      const clear = document.createElement('button');
      clear.type = 'button';
      clear.className = 'daterange-clear';
      clear.textContent = '×';

      pick.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        this.range.side = side;
        const date = this.range[side];
        if (date) {
          this.currentMonth = date.getMonth();
          this.currentYear = date.getFullYear();
        }
        this.currentView = 'day';
        this._renderCurrentView();
        this._refreshRangeTabs();
      });

      clear.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        this.range[side] = null;
        this.range.side = side;
        this._writeRangeInputs();
        this._renderCurrentView();
      });

      tab.append(pick, clear);
      tabs.appendChild(tab);
      this.range.tabs[side] = {tab, label, value, clear};
    });

    this.dropdown.appendChild(tabs);
  }

  /**
   * Buttons for the ranges named in data-range-presets. Picking one sets both
   * sides and closes the picker.
   *
   * @returns {void}
   */
  _createRangePresets() {
    if (this.range.presets.length === 0) return;

    this.dropdown.classList.add('has-presets');
    const box = document.createElement('div');
    box.className = 'daterange-presets';
    this.range.presetButtons = [];

    this.range.presets.forEach(key => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'daterange-preset';
      button.dataset.preset = key;
      button.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        this.applyRangePreset(key);
      });
      box.appendChild(button);
      this.range.presetButtons.push(button);
    });

    this.dropdown.appendChild(box);
  }

  /**
   * Sets the range to a preset (see DATE_RANGE_PRESETS) and closes the picker.
   *
   * @param {string} key - Preset name, e.g. "7d" or "thismonth"
   * @returns {void}
   */
  applyRangePreset(key) {
    const preset = DATE_RANGE_PRESETS[key];
    if (!this.range || !preset) return;

    const now = new Date();
    const [start, end] = preset.range(new Date(now.getFullYear(), now.getMonth(), now.getDate()));
    this.range.start = start;
    this.range.end = end;
    this.range.preview = null;
    this._writeRangeInputs();

    if (this.isOpen) {
      this.close();
    } else {
      this._commitRange();
    }
  }

  /**
   * Texts of the range UI, set again when the language changes.
   *
   * @returns {void}
   */
  _translateRangeUi() {
    const {tabs, presetButtons} = this.range;
    if (tabs) {
      tabs.start.label.textContent = Now.translate('From');
      tabs.end.label.textContent = Now.translate('To');
      tabs.start.clear.setAttribute('aria-label', Now.translate('Clear'));
      tabs.end.clear.setAttribute('aria-label', Now.translate('Clear'));
    }
    (presetButtons || []).forEach(button => {
      button.textContent = Now.translate(DATE_RANGE_PRESETS[button.dataset.preset].label);
    });
  }

  _createTimePicker() {
    const timePickerSection = document.createElement('div');
    timePickerSection.className = 'time-picker-section';

    const timeInputs = document.createElement('div');
    timeInputs.className = 'time-inputs';

    // Hours
    const hoursGroup = document.createElement('div');
    hoursGroup.className = 'time-group';

    const hoursInput = document.createElement('input');
    hoursInput.type = 'number';
    hoursInput.className = 'time-input hours';
    hoursInput.min = '0';
    hoursInput.max = '23';
    hoursInput.value = '00';
    hoursInput.placeholder = 'HH';
    this.hoursInput = hoursInput;

    hoursGroup.appendChild(hoursInput);
    timeInputs.appendChild(hoursGroup);

    // Separator
    const separator1 = document.createElement('span');
    separator1.className = 'time-separator';
    separator1.textContent = ':';
    timeInputs.appendChild(separator1);

    // Minutes
    const minutesGroup = document.createElement('div');
    minutesGroup.className = 'time-group';

    const minutesInput = document.createElement('input');
    minutesInput.type = 'number';
    minutesInput.className = 'time-input minutes';
    minutesInput.min = '0';
    minutesInput.max = '59';
    minutesInput.value = '00';
    minutesInput.placeholder = 'MM';
    this.minutesInput = minutesInput;

    minutesGroup.appendChild(minutesInput);
    timeInputs.appendChild(minutesGroup);

    // Seconds (optional - can be enabled via data-show-seconds)
    const showSeconds = this._original.getAttribute('data-show-seconds') === 'true';
    if (showSeconds) {
      const separator2 = document.createElement('span');
      separator2.className = 'time-separator';
      separator2.textContent = ':';
      timeInputs.appendChild(separator2);

      const secondsGroup = document.createElement('div');
      secondsGroup.className = 'time-group';

      const secondsInput = document.createElement('input');
      secondsInput.type = 'number';
      secondsInput.className = 'time-input seconds';
      secondsInput.min = '0';
      secondsInput.max = '59';
      secondsInput.value = '00';
      secondsInput.placeholder = 'SS';
      this.secondsInput = secondsInput;

      secondsGroup.appendChild(secondsInput);
      timeInputs.appendChild(secondsGroup);
    }

    timePickerSection.appendChild(timeInputs);
    this.dropdown.appendChild(timePickerSection);

    // Setup time input events
    this._setupTimeInputEvents();
  }

  _setupTimeInputEvents() {
    const validateAndFormat = (input, max) => {
      let value = parseInt(input.value) || 0;
      if (value < 0) value = 0;
      if (value > max) value = max;
      input.value = _pad(value);
    };

    if (this.hoursInput) {
      this.hoursInput.addEventListener('input', (e) => {
        if (e.target.value.length >= 2) {
          validateAndFormat(e.target, 23);
          if (this.minutesInput) this.minutesInput.focus();
        }
      });

      this.hoursInput.addEventListener('blur', (e) => {
        validateAndFormat(e.target, 23);
      });
    }

    if (this.minutesInput) {
      this.minutesInput.addEventListener('input', (e) => {
        if (e.target.value.length >= 2) {
          validateAndFormat(e.target, 59);
          if (this.secondsInput) this.secondsInput.focus();
        }
      });

      this.minutesInput.addEventListener('blur', (e) => {
        validateAndFormat(e.target, 59);
      });
    }

    if (this.secondsInput) {
      this.secondsInput.addEventListener('input', (e) => {
        if (e.target.value.length >= 2) {
          validateAndFormat(e.target, 59);
        }
      });

      this.secondsInput.addEventListener('blur', (e) => {
        validateAndFormat(e.target, 59);
      });
    }
  }

  _setupEventListeners() {
    // Display button click
    this.displayButton.addEventListener('click', (e) => {
      e.preventDefault();
      if (this.disabled || this.readonly) return;
      this.toggle();
    });

    // Month display click - go to month picker
    this.monthDisplay.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      this.currentView = 'month';
      this._renderCurrentView();
    });

    // Year display click - go to year picker
    this.yearDisplay.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation(); // Prevent dropdown from closing
      this.currentView = 'year';
      this._renderCurrentView();
    });

    // Navigation buttons
    this.prevButton.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation(); // Prevent dropdown from closing
      this._navigatePrevious();
    });

    this.nextButton.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation(); // Prevent dropdown from closing
      this._navigateNext();
    });

    // Keyboard navigation
    this.wrapper.addEventListener('keydown', (e) => {
      if (this.disabled || this.readonly) return;

      switch (e.key) {
        case 'Backspace':
          if (this.range) {
            // Empties both sides of the range
            if (this.range.start || this.range.end) {
              e.preventDefault();
              this.range.start = null;
              this.range.end = null;
              this._writeRangeInputs();
              if (this.isOpen) {
                this._renderCurrentView();
              } else {
                this._commitRange();
              }
            }
            break;
          }
          if (this.hiddenInput?.value || this.selectedDate || this.selectedTime) {
            e.preventDefault();
            this.setValue('');

            const changeEvent = new Event('change', {bubbles: true});
            this.hiddenInput.dispatchEvent(changeEvent);

            this.wrapper.dispatchEvent(new CustomEvent('gdatetime:change', {
              detail: {
                type: this.type,
                value: this.hiddenInput.value,
                date: this.selectedDate
              }
            }));
          }
          break;
        case 'Enter':
        case ' ':
          e.preventDefault();
          this.toggle();
          break;
        case 'Escape':
          e.preventDefault();
          this.close();
          break;
      }
    });

    // Copy/Paste support
    this.wrapper.addEventListener('copy', (e) => {
      if (this.hiddenInput.value) {
        e.preventDefault();
        e.clipboardData.setData('text/plain', this.hiddenInput.value);
      }
    });

    this.wrapper.addEventListener('paste', (e) => {
      if (this.disabled || this.readonly) return;
      e.preventDefault();
      const pastedText = e.clipboardData.getData('text/plain').trim();
      if (pastedText) {
        this.setValue(pastedText);
      }
    });

    // Listen for locale changes to re-render with new language
    this._localeChangeHandler = () => {
      this._updateLocale();
    };
    if (window.EventManager?.on) {
      EventManager.on('locale:changed', this._localeChangeHandler);
    }
  }

  /**
   * Update locale and re-render UI when language changes
   */
  _updateLocale() {
    // Update locale
    const newLocale = _getCurrentLocale();
    if (newLocale === this.locale) return;

    this.locale = newLocale;
    this.outputLocale = this.locale.startsWith('th') ? 'th-CE' : 'en';
    this.displayLocale = this.locale;

    // Update days header
    this.daysHeader.innerHTML = '';
    _getDayNames(this.locale).forEach(day => {
      const dayElement = document.createElement('div');
      dayElement.className = 'day-name';
      dayElement.textContent = day;
      this.daysHeader.appendChild(dayElement);
    });

    if (this.range) {
      this._translateRangeUi();
      this._writeRangeInputs();
    } else if (this.selectedDate) {
      // Update display value if date is selected
      const displayValue = Utils.date.format(this.selectedDate, this.displayFormat, this.displayLocale);
      if (this.type === 'datetime-local' && this.hoursInput && this.minutesInput) {
        const hours = parseInt(this.hoursInput.value) || 0;
        const minutes = parseInt(this.minutesInput.value) || 0;
        this.displayButton.firstChild.textContent = displayValue + ' ' + _pad(hours) + ':' + _pad(minutes);
      } else {
        this.displayButton.firstChild.textContent = displayValue;
      }
    }

    // Re-render current view if open
    if (this.isOpen) {
      this._renderCurrentView();
    }
  }

  _navigatePrevious() {
    switch (this.currentView) {
      case 'day':
        this.previousMonth();
        break;
      case 'month':
        this.currentYear--;
        this._renderCurrentView();
        break;
      case 'year':
        this.currentYear -= 12;
        this._renderCurrentView();
        break;
    }
  }

  _navigateNext() {
    switch (this.currentView) {
      case 'day':
        this.nextMonth();
        break;
      case 'month':
        this.currentYear++;
        this._renderCurrentView();
        break;
      case 'year':
        this.currentYear += 12;
        this._renderCurrentView();
        break;
    }
  }

  _renderCurrentView() {
    switch (this.currentView) {
      case 'year':
        this._renderYearPicker();
        break;
      case 'month':
        this._renderMonthPicker();
        break;
      case 'day':
      default:
        this._renderCalendar();
        break;
    }
  }

  _renderYearPicker() {
    const startYear = this.currentYear - (this.currentYear % 12);
    const endYear = startYear + 11;

    // Use BE for Thai locale, CE for others
    const useBE = this.displayLocale === 'th';
    const displayStartYear = useBE ? startYear + 543 : startYear;
    const displayEndYear = useBE ? endYear + 543 : endYear;

    this.yearDisplay.textContent = `${displayStartYear} - ${displayEndYear}`;
    this.yearDisplay.style.flex = '1';
    this.yearDisplay.style.display = null;
    this.monthDisplay.textContent = '';
    this.monthDisplay.style.display = 'none';

    // Hide days header
    this.daysHeader.style.display = 'none';

    // Clear and setup grid for years
    this.calendarGrid.innerHTML = '';
    this.calendarGrid.className = 'calendar-grid year-grid';

    // Create year buttons (12 years)
    for (let i = 0; i < 12; i++) {
      const year = startYear + i;
      const yearButton = document.createElement('button');
      yearButton.type = 'button';
      yearButton.className = 'calendar-year';

      // Show year BE or CE according to locale
      const displayYear = useBE ? year + 543 : year;
      yearButton.textContent = displayYear;

      // Highlight current year
      if (year === new Date().getFullYear()) {
        yearButton.classList.add('today');
      }

      if (year === this.currentYear) {
        yearButton.classList.add('selected');
      }

      yearButton.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation(); // Prevent dropdown from closing
        this.currentYear = year;
        this.currentView = 'month'; // Go to month view
        this._renderCurrentView();
      });

      this.calendarGrid.appendChild(yearButton);
    }
  }

  _renderMonthPicker() {
    // Use BE for Thai locale, CE for others
    const useBE = this.displayLocale === 'th';
    const displayYear = useBE ? this.currentYear + 543 : this.currentYear;

    this.yearDisplay.textContent = displayYear;
    this.yearDisplay.style.flex = '1';
    this.monthDisplay.textContent = '';
    this.monthDisplay.style.display = 'none';

    // Hide days header
    this.daysHeader.style.display = 'none';

    // Clear and setup grid for months
    this.calendarGrid.innerHTML = '';
    this.calendarGrid.className = 'calendar-grid month-grid';

    // Create month buttons (12 months)
    for (let i = 0; i < 12; i++) {
      const monthButton = document.createElement('button');
      monthButton.type = 'button';
      monthButton.className = 'calendar-month';
      monthButton.textContent = _getMonthNamesShort(this.locale)[i];

      // Highlight current month
      const now = new Date();
      if (i === now.getMonth() && this.currentYear === now.getFullYear()) {
        monthButton.classList.add('today');
      }

      if (i === this.currentMonth) {
        monthButton.classList.add('selected');
      }

      monthButton.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation(); // Prevent dropdown from closing
        this.currentMonth = i;
        this.currentView = 'day'; // Go to day view
        this._renderCurrentView();
      });

      this.calendarGrid.appendChild(monthButton);
    }
  }

  _renderCalendar() {
    // Use BE for Thai locale, CE for others
    const useBE = this.displayLocale === 'th';
    const displayYear = useBE ? this.currentYear + 543 : this.currentYear;

    this.monthDisplay.textContent = _getMonthNames(this.locale)[this.currentMonth];
    this.monthDisplay.style.display = '';
    this.yearDisplay.textContent = displayYear;
    this.monthDisplay.style.flex = '';
    this.yearDisplay.style.flex = '';

    // Show days header
    this.daysHeader.style.display = 'grid';

    // Reset grid class
    this.calendarGrid.className = 'calendar-grid';

    // Clear calendar grid
    this.calendarGrid.innerHTML = '';

    // Get first day of month and number of days
    const firstDay = new Date(this.currentYear, this.currentMonth, 1);
    const lastDay = new Date(this.currentYear, this.currentMonth + 1, 0);
    const startDate = new Date(firstDay);
    startDate.setDate(startDate.getDate() - firstDay.getDay());

    // Create 6 weeks of days (42 days total)
    for (let i = 0; i < 42; i++) {
      const currentDate = new Date(startDate);
      currentDate.setDate(startDate.getDate() + i);

      const dayElement = document.createElement('button');
      dayElement.type = 'button';
      dayElement.className = 'calendar-day';
      dayElement.textContent = currentDate.getDate();

      // Add classes for styling
      if (currentDate.getMonth() !== this.currentMonth) {
        dayElement.classList.add('other-month');
      }

      if (this._isSameDate(currentDate, new Date())) {
        dayElement.classList.add('today');
      }

      if (this.range) {
        dayElement.dataset.date = _toDateISO(currentDate);
        this._decorateRangeDay(dayElement);
      } else if (this.selectedDate && this._isSameDate(currentDate, this.selectedDate)) {
        dayElement.classList.add('selected');
      }

      // Check if date is disabled
      if (this._isDateDisabled(currentDate)) {
        dayElement.disabled = true;
        dayElement.classList.add('disabled');
      } else {
        dayElement.addEventListener('click', (e) => {
          e.preventDefault();
          e.stopPropagation(); // Prevent dropdown from closing
          if (this.range) {
            this._pickRangeDate(currentDate);
          } else {
            this.selectDate(currentDate);
          }
        });
        if (this.range) {
          dayElement.addEventListener('mouseenter', () => {
            if (this.range.side === 'end' && this.range.start && !this.range.end) {
              this.range.preview = dayElement.dataset.date;
              this._refreshRangeDays();
            }
          });
        }
      }

      this.calendarGrid.appendChild(dayElement);
    }
  }

  _isSameDate(date1, date2) {
    return date1.getFullYear() === date2.getFullYear() &&
      date1.getMonth() === date2.getMonth() &&
      date1.getDate() === date2.getDate();
  }

  _isDateDisabled(date) {
    // Compare calendar days, not instants: new Date('2026-10-16') is midnight
    // UTC, which east of UTC is later than the local midnight of the day
    // cell, so the min day itself used to be disabled
    const day = _toDateISO(date);
    const min = this.min ? normalizeDateForInput(this.min, 'date') : null;
    if (min && day < min) return true;

    const max = this.max ? normalizeDateForInput(this.max, 'date') : null;
    if (max && day > max) return true;

    // data-range-max-days: picking the end, only days within that many of the start
    if (this.range?.maxDays > 0 && this.range.side === 'end' && this.range.start) {
      const distance = Math.round((date - this.range.start) / 86400000);
      if (Math.abs(distance) >= this.range.maxDays) return true;
    }

    return false;
  }

  /**
   * Range classes of one day cell: range-start, range-end, in-range, and
   * in-range-preview while the end is being chosen with the pointer.
   *
   * @param {HTMLElement} dayElement - Day cell carrying data-date
   * @returns {void}
   */
  _decorateRangeDay(dayElement) {
    const day = dayElement.dataset.date;
    const start = this.range.start ? _toDateISO(this.range.start) : null;
    const end = this.range.end ? _toDateISO(this.range.end) : null;
    const preview = !end && start ? this.range.preview : null;
    const low = preview && preview < start ? preview : start;
    const high = preview && preview > start ? preview : start;

    dayElement.classList.toggle('range-start', day === start);
    dayElement.classList.toggle('range-end', day === end);
    dayElement.classList.toggle('in-range', !!(start && end && day > start && day < end));
    dayElement.classList.toggle('in-range-preview', !!(preview && day !== start && day >= low && day <= high));
  }

  /**
   * Re-applies the range classes to the days on screen, without re-rendering.
   *
   * @returns {void}
   */
  _refreshRangeDays() {
    this.calendarGrid.querySelectorAll('.calendar-day[data-date]').forEach(dayElement => this._decorateRangeDay(dayElement));
  }

  /**
   * Shows each side's date on its tab and marks the side being picked.
   *
   * @returns {void}
   */
  _refreshRangeTabs() {
    const {tabs} = this.range;
    if (!tabs) return;
    ['start', 'end'].forEach(side => {
      const date = this.range[side];
      tabs[side].value.textContent = date ? Utils.date.format(date, this.displayFormat, this.displayLocale) : '—';
      tabs[side].clear.hidden = !date;
      tabs[side].tab.classList.toggle('active', this.range.side === side);
    });
  }

  /**
   * A day clicked in range mode. On the From side it becomes the start and the
   * To side is next; on the To side it becomes the end and the picker closes.
   * A day before the start becomes the start, the old start the end. An end
   * that no longer fits (before the new start, or past data-range-max-days)
   * is dropped.
   *
   * @param {Date} date - Day clicked
   * @returns {void}
   */
  _pickRangeDate(date) {
    const day = new Date(date.getFullYear(), date.getMonth(), date.getDate());
    const range = this.range;
    const pickedEnd = range.side === 'end';

    if (!pickedEnd) {
      range.start = day;
      if (range.end && (range.end < day || this._rangeTooLong(day, range.end))) {
        range.end = null;
      }
      range.side = 'end';
    } else if (range.start && day < range.start) {
      range.end = range.start;
      range.start = day;
    } else {
      range.end = day;
    }

    range.preview = null;
    this._writeRangeInputs();

    if (pickedEnd) {
      this.close();
    } else {
      this._renderCurrentView();
    }
  }

  _rangeTooLong(start, end) {
    return this.range.maxDays > 0 && Math.round((end - start) / 86400000) >= this.range.maxDays;
  }

  /**
   * Writes both sides into their inputs and refreshes the button text and the
   * tabs. No event is sent; _commitRange() does that.
   *
   * @returns {void}
   */
  _writeRangeInputs() {
    const {start, end, endInput} = this.range;
    const startValue = start ? Utils.date.format(start, this.outputFormat, this.outputLocale) : '';
    const endValue = end ? Utils.date.format(end, this.outputFormat, this.outputLocale) : '';

    this.hiddenInput.value = startValue;
    this.hiddenInput.setAttribute('value', startValue);
    if (endInput) {
      endInput.value = endValue;
      endInput.setAttribute('value', endValue);
    }

    const format = date => Utils.date.format(date, this.displayFormat, this.displayLocale);
    let text;
    if (start && end) {
      text = `${format(start)} – ${format(end)}`;
    } else if (start) {
      text = `${Now.translate('From')} ${format(start)}`;
    } else if (end) {
      text = `${Now.translate('To')} ${format(end)}`;
    } else {
      text = Now.translate(this._original.placeholder || 'Choose a date range');
    }
    this.displayButton.firstChild.textContent = text;
    this.wrapper.classList.toggle('has-value', !!(start || end));

    this._refreshRangeTabs();
  }

  /**
   * Announces a range that differs from the last one announced: change on the
   * end input when it changed, change on the start input, then
   * gdatetime:rangechange. A form or a table filter therefore reloads once
   * per range, not once per click.
   *
   * @returns {void}
   */
  _commitRange() {
    const range = this.getRange();
    const last = this.range.committed;
    if (range.start === last.start && range.end === last.end) return;

    this.range.committed = {start: range.start, end: range.end};

    if (range.end !== last.end && this.range.endInput) {
      this.range.endInput.dispatchEvent(new Event('change', {bubbles: true}));
    }
    this.hiddenInput.dispatchEvent(new Event('change', {bubbles: true}));

    this.wrapper.dispatchEvent(new CustomEvent('gdatetime:rangechange', {
      bubbles: true,
      detail: {
        start: range.start,
        end: range.end,
        names: {start: this.hiddenInput.name, end: this.range.endInput?.name || this.range.endName}
      }
    }));
  }

  /**
   * The two sides of a range picker.
   *
   * @returns {{start: string, end: string}|null} Values as submitted ('' for an
   *   empty side), or null when the picker is not in range mode
   */
  getRange() {
    if (!this.range) return null;
    return {
      start: this.hiddenInput?.value || '',
      end: this.range.endInput?.value || ''
    };
  }

  /**
   * Sets both sides of a range picker without announcing a change (loading
   * saved state is not an edit). An end before the start is swapped.
   *
   * @param {string|Date|null} start - Start date, or empty
   * @param {string|Date|null} end - End date, or empty
   * @returns {void}
   */
  setRange(start, end) {
    if (!this.range) return;

    const toDay = value => {
      if (value === null || value === undefined || value === '') return null;
      const date = value instanceof Date ? value : parseDateFromInput(value, 'date');
      return date && !Number.isNaN(date.getTime()) ? new Date(date.getFullYear(), date.getMonth(), date.getDate()) : null;
    };

    let startDate = toDay(start);
    let endDate = toDay(end);
    if (startDate && endDate && endDate < startDate) {
      [startDate, endDate] = [endDate, startDate];
    }

    this.range.start = startDate;
    this.range.end = endDate;
    this.range.preview = null;

    const shown = startDate || endDate;
    if (shown) {
      this.currentMonth = shown.getMonth();
      this.currentYear = shown.getFullYear();
    }

    this._writeRangeInputs();
    this.range.committed = this.getRange();

    if (this.isOpen) {
      this._renderCurrentView();
    }
  }

  /**
   * Open the picker dropdown.
   *
   * Ignored while the field is disabled, read-only, or already open. For a
   * time-only picker the calendar grid is hidden and only the time controls
   * show.
   *
   * @returns {void}
   */
  open() {
    if (this.disabled || this.readonly || this.isOpen) return;

    this.isOpen = true;

    // Hide/show calendar elements based on type
    if (this.type === 'time') {
      // For time picker, hide calendar elements
      this.calendarHeader.style.display = 'none';
      this.daysHeader.style.display = 'none';
      this.calendarGrid.style.display = 'none';
    } else {
      // Show calendar elements for date/datetime-local
      this.calendarHeader.style.display = '';
      this.daysHeader.style.display = '';
      this.calendarGrid.style.display = '';
      this.currentView = 'day'; // Start at day view
      if (this.range) {
        // A start without an end is continued; otherwise a new range begins
        this.range.side = this.range.start && !this.range.end ? 'end' : 'start';
        this.range.preview = null;
        const shown = this.range.start || this.range.end;
        if (shown) {
          this.currentMonth = shown.getMonth();
          this.currentYear = shown.getFullYear();
        }
        this._refreshRangeTabs();
      }
      this._renderCurrentView();
    }

    // Show dropdown in DropdownPanel
    this.dropdownPanel.show(this.wrapper, this.dropdown, {
      align: 'left',
      offsetY: 5,
      onClose: () => {
        this.isOpen = false;
        if (this.range) {
          // Closing (a day on the To side, a preset, Esc or a click outside) settles the range
          this._commitRange();
        }
        // Dispatch close event
        this.wrapper.dispatchEvent(new CustomEvent('gdatetime:close', {
          detail: {type: this.type, value: this.selectedDate}
        }));
      }
    });

    // Focus time input if time type
    if (this.type === 'time' && this.hoursInput) {
      setTimeout(() => {
        this.hoursInput.focus();
        this.hoursInput.select();
      }, 50);
    }

    // Dispatch event
    this.wrapper.dispatchEvent(new CustomEvent('gdatetime:open', {
      detail: {type: this.type, value: this.selectedDate}
    }));
  }

  /**
   * Close the picker dropdown.
   *
   * Delegates to the shared DropdownPanel, which triggers the picker's own
   * close callback.
   *
   * @returns {void}
   */
  close() {
    if (!this.isOpen) return;

    // Hide the DropdownPanel (will trigger onClose callback)
    this.dropdownPanel.hide();
  }

  /**
   * Open the picker if closed, close it if open.
   *
   * @returns {void}
   */
  toggle() {
    if (this.isOpen) {
      this.close();
    } else {
      this.open();
    }
  }

  /**
   * Choose a date, combining it with the current time for datetime-local mode.
   *
   * @param {Date|string} date - Date to select.
   * @returns {void}
   */
  selectDate(date) {
    this.selectedDate = new Date(date);

    let outputValue, displayValue;

    if (this.type === 'datetime-local') {
      // Get time from inputs or use current time
      const hours = this.hoursInput ? parseInt(this.hoursInput.value) || 0 : this.selectedDate.getHours();
      const minutes = this.minutesInput ? parseInt(this.minutesInput.value) || 0 : this.selectedDate.getMinutes();
      const seconds = this.secondsInput ? parseInt(this.secondsInput.value) || 0 : 0;

      this.selectedDate.setHours(hours, minutes, seconds, 0);

      // Format: YYYY-MM-DDTHH:MM:SS or YYYY-MM-DDTHH:MM
      outputValue = _toLocalDateTime(this.selectedDate);
      if (this.secondsInput) {
        outputValue += ':' + _pad(seconds);
      }

      // Display format
      displayValue = Utils.date.format(this.selectedDate, this.displayFormat, this.displayLocale) + ' ' +
        _pad(hours) + ':' + _pad(minutes);
      if (this.secondsInput) {
        displayValue += ':' + _pad(seconds);
      }

    } else if (this.type === 'time') {
      // Time only
      const hours = this.hoursInput ? parseInt(this.hoursInput.value) || 0 : 0;
      const minutes = this.minutesInput ? parseInt(this.minutesInput.value) || 0 : 0;
      const seconds = this.secondsInput ? parseInt(this.secondsInput.value) || 0 : 0;

      outputValue = _pad(hours) + ':' + _pad(minutes);
      if (this.secondsInput) {
        outputValue += ':' + _pad(seconds);
      }
      displayValue = outputValue;

    } else {
      // Date only - output uses CE year for database, display uses locale (BE/CE)
      outputValue = Utils.date.format(this.selectedDate, this.outputFormat, this.outputLocale);
      displayValue = Utils.date.format(this.selectedDate, this.displayFormat, this.displayLocale);
    }

    this.hiddenInput.value = outputValue;
    this.hiddenInput.setAttribute('value', outputValue);
    this.displayButton.firstChild.textContent = displayValue;

    // Don't auto-close for datetime-local (let user set time)
    if (this.type !== 'datetime-local') {
      this.close();
    }

    // Focus to wrapper
    setTimeout(() => {
      this.wrapper.focus();
    }, 0);

    // Dispatch events
    const changeEvent = new Event('change', {bubbles: true});
    this.hiddenInput.dispatchEvent(changeEvent);

    this.wrapper.dispatchEvent(new CustomEvent('gdatetime:change', {
      detail: {
        type: this.type,
        value: this.hiddenInput.value,
        date: this.selectedDate
      }
    }));
  }

  /**
   * Step the visible calendar back one month, rolling over into the previous year.
   *
   * @returns {void}
   */
  previousMonth() {
    this.currentMonth--;
    if (this.currentMonth < 0) {
      this.currentMonth = 11;
      this.currentYear--;
    }
    this._renderCurrentView();
  }

  /**
   * Advance the visible calendar by one month, rolling over into the next year.
   *
   * @returns {void}
   */
  nextMonth() {
    this.currentMonth++;
    if (this.currentMonth > 11) {
      this.currentMonth = 0;
      this.currentYear++;
    }
    this._renderCurrentView();
  }

  /**
   * Set the picker's value programmatically.
   *
   * An empty value clears both the date and time state, not just the display.
   *
   * @param {string|Date|null} value - Value to set, or a falsy value to clear.
   * @returns {void}
   */
  setValue(value) {
    if (this.range) {
      // The value of a range picker is its start; the end input keeps its own
      this.setRange(value, this.range.endInput?.value || '');
      return;
    }

    if (!value || value === '') {
      this.selectedDate = null;
      this.selectedTime = null;
      this.hiddenInput.value = '';
      this.hiddenInput.setAttribute('value', '');

      const placeholder = this.type === 'time' ? 'Choose time' :
        this.type === 'datetime-local' ? 'Choose date and time' :
          'Choose a date';
      this.displayButton.firstChild.textContent = Now.translate(this._original.placeholder || placeholder);

      // Reset time inputs
      if (this.hoursInput) this.hoursInput.value = '00';
      if (this.minutesInput) this.minutesInput.value = '00';
      if (this.secondsInput) this.secondsInput.value = '00';
      return;
    }

    if (this.type === 'time') {
      // Parse time value (HH:MM or HH:MM:SS)
      const timeParts = value.split(':');
      if (timeParts.length >= 2) {
        const hours = parseInt(timeParts[0]) || 0;
        const minutes = parseInt(timeParts[1]) || 0;
        const seconds = timeParts[2] ? parseInt(timeParts[2]) || 0 : 0;

        if (this.hoursInput) this.hoursInput.value = _pad(hours);
        if (this.minutesInput) this.minutesInput.value = _pad(minutes);
        if (this.secondsInput) this.secondsInput.value = _pad(seconds);

        this.hiddenInput.value = value;
        this.hiddenInput.setAttribute('value', value);
        this.displayButton.firstChild.textContent = value;
        return;
      }
    }

    // Parse date or datetime-local value using centralized Utils parser if available
    const parsed = window.Utils?.date?.parse ? window.Utils.date.parse(value, this.type) : parseDateFromInput(value, this.type);
    if (!parsed) {
      console.warn('Invalid date value:', value);
      return;
    }
    const date = parsed;

    this.selectedDate = date;
    this.currentMonth = date.getMonth();
    this.currentYear = date.getFullYear();

    if (this.type === 'datetime-local') {
      // Extract time components
      const hours = date.getHours();
      const minutes = date.getMinutes();
      const seconds = date.getSeconds();

      if (this.hoursInput) this.hoursInput.value = _pad(hours);
      if (this.minutesInput) this.minutesInput.value = _pad(minutes);
      if (this.secondsInput) this.secondsInput.value = _pad(seconds);

      // Format output
      let outputValue = _toLocalDateTime(date);
      if (this.secondsInput) {
        outputValue += ':' + _pad(seconds);
      }
      this.hiddenInput.value = outputValue;
      this.hiddenInput.setAttribute('value', outputValue);

      // Format display using current locale
      const displayValue = Utils.date.format(date, this.displayFormat, this.displayLocale) + ' ' +
        _pad(hours) + ':' + _pad(minutes);
      this.displayButton.firstChild.textContent = displayValue + (this.secondsInput ? ':' + _pad(seconds) : '');

    } else {
      // Date only - output uses CE year for database, display uses locale (BE/CE)
      const outputValue = Utils.date.format(this.selectedDate, this.outputFormat, this.outputLocale);
      this.hiddenInput.value = outputValue;
      this.hiddenInput.setAttribute('value', outputValue);

      const displayValue = Utils.date.format(this.selectedDate, this.displayFormat, this.displayLocale);
      this.displayButton.firstChild.textContent = displayValue;
    }
  }

  /**
   * The value currently stored for form submission.
   *
   * @returns {string|null} - The formatted value, or null when nothing is selected.
   */
  getValue() {
    return this.hiddenInput.value || null;
  }

  /**
   * The currently selected date.
   *
   * @returns {Date|null} - The selected date, or null when nothing is selected.
   */
  getDate() {
    return this.selectedDate;
  }

  /**
   * Tear the picker down: close it if open and remove its locale listener.
   *
   * @returns {void}
   */
  destroy() {
    // Close dropdown if open
    if (this.isOpen) {
      this.close();
    }

    // Remove locale change listener
    if (this._localeChangeHandler && window.EventManager?.off) {
      EventManager.off('locale:changed', this._localeChangeHandler);
    }

    if (this.wrapper && this.wrapper.parentNode) {
      this.wrapper.parentNode.removeChild(this.wrapper);
    }
  }

  /**
   * The wrapper element this picker rendered into.
   *
   * @returns {HTMLElement} - The wrapper.
   */
  getElement() {
    return this.wrapper;
  }

  _replaceOriginalElement() {
    if (this._original.parentNode) {
      // Hide original element but don't remove it, so form can still read value
      this._original.type = 'hidden';
      this._original.style.display = 'none';

      // Use original element as hiddenInput
      this.hiddenInput = this._original;

      // Insert wrapper before original element only if wrapper is not already the parent
      if (this._original.parentNode !== this.wrapper) {
        this._original.parentNode.insertBefore(this.wrapper, this._original);
      }
    }
  }
}

/**
 * DateElementFactory - Factory for creating custom date picker elements
 */
class DateElementFactory extends ElementFactory {
  static config = {
    ...ElementFactory.config,
    type: 'date'
  };

  // Override property handler for `value` to normalize incoming date/time
  static propertyHandlers = {
    value: {
      get(element) {
        return element.value;
      },
      set(instance, newValue) {
        const el = instance.element;
        // Prefer the datepicker instance type (original input type) if available
        const declaredType = (instance?.gdatetime?.type) || (el.getAttribute('type') || el.type || 'date');
        const inputKind = declaredType === 'datetime-local' ? 'datetime-local' : declaredType === 'time' ? 'time' : 'date';

        // Normalize to input-acceptable format when possible
        try {
          const normalized = normalizeDateForInput(newValue, inputKind);
          if (normalized !== null) {
            if (typeof instance.setValue === 'function') {
              instance.setValue(normalized);
            } else {
              el.value = normalized;
              el.setAttribute('value', normalized);
            }
            return;
          }
        } catch (e) {
          // ignore and fallback to raw
        }

        // Fallback: set raw value (for non-standard strings or when normalization failed)
        if (typeof instance.setValue === 'function') {
          instance.setValue(newValue);
        } else {
          el.value = newValue ?? '';
          if (newValue != null) el.setAttribute('value', newValue);
          else el.removeAttribute('value');
        }
      }
    }
  };

  /**
   * Get or create the element's instance state, same as the base ElementFactory.
   *
   * @param {HTMLElement} element - Element the state belongs to.
   * @param {Object} [config={}] - Config merged in when first created.
   * @returns {Object} - The element's state object.
   */
  static createInstance(element, config = {}) {
    const instance = super.createInstance(element, config);
    return instance;
  }

  /**
   * Create a date field from a definition (ElementManager.create()).
   *
   * ElementFactory.create() gives the input the type in `def.type` and falls
   * back to text, while the picker takes its mode from that type — so a field
   * created as 'date', 'datetime-local' or 'daterange' gets its type here.
   *
   * @param {Object} def - Field definition.
   * @returns {Object} - The instance created for the field.
   */
  static create(def) {
    if (!def.type) {
      def.type = def.elementType === 'datetime-local' ? 'datetime-local' : 'date';
    }
    return super.create(def);
  }

  /**
   * Replace the native date input with an EmbeddedDateTime picker.
   *
   * A definition given to ElementManager.create() reaches the picker as the
   * attributes it reads: `rangeEnd` (data-range-end), `rangePresets`,
   * `rangeMaxDays`, `min` and `max`. The type 'daterange' is a range picker;
   * without `rangeEnd` its end input is named after the field plus `_to`.
   *
   * @param {Object} instance - Element instance carrying `element` and `config`.
   * @returns {void}
   */
  static setupElement(instance) {
    const {element, config = {}} = instance;

    try {
      const attributes = {
        'data-range-end': config.rangeEnd || (config.elementType === 'daterange' ? `${element.name || element.id}_to` : ''),
        'data-range-presets': Array.isArray(config.rangePresets) ? config.rangePresets.join(',') : config.rangePresets,
        'data-range-max-days': config.rangeMaxDays,
        min: config.min,
        max: config.max
      };
      Object.entries(attributes).forEach(([name, value]) => {
        if (value !== undefined && value !== null && value !== '' && !element.hasAttribute(name)) {
          element.setAttribute(name, value);
        }
      });

      const gdatetime = new EmbeddedDateTime(element);

      // A created field keeps the wrapper ElementFactory built around the
      // picker (it holds the label); an enhanced one is the picker itself
      const pickerWrapper = gdatetime.getElement();
      if (!(config.wrapper && instance.wrapper && instance.wrapper !== pickerWrapper && instance.wrapper.contains(pickerWrapper))) {
        instance.wrapper = pickerWrapper;
      }
      instance.gdatetime = gdatetime;

      // Bind methods
      instance.getValue = () => gdatetime.getValue();
      instance.setValue = (value) => gdatetime.setValue(value);
      instance.getDate = () => gdatetime.getDate();
      instance.getRange = () => gdatetime.getRange();
      instance.setRange = (start, end) => gdatetime.setRange(start, end);
      instance.open = () => gdatetime.open();
      instance.close = () => gdatetime.close();
      instance.toggle = () => gdatetime.toggle();

      // onChange from the definition, as a select calls it (TableManager filters
      // rely on it). A range picker sends one change per range, on its start input.
      if (typeof config.onChange === 'function') {
        EventSystemManager.addHandler(element, 'change', () => {
          try {
            config.onChange(element, gdatetime.getValue());
          } catch (err) {
            console.warn('DateElementFactory onChange handler error', err);
          }
        });
      }

      // Update element reference
      element.wrapper = instance.wrapper;

      // Setup event forwarding
      if (gdatetime.hiddenInput) {
        gdatetime.hiddenInput.addEventListener('change', (e) => {
          // Prevent infinite loop: don't re-dispatch if event came from element itself
          if (e.target === element) return;

          // Dispatch standard change event
          const changeEvent = new Event('change', {bubbles: true});
          element.dispatchEvent(changeEvent);

          // Emit to event system
          EventManager.emit('element:change', {
            elementId: element.id,
            value: gdatetime.getValue(),
            type: 'date'
          });
        });
      }

      // Cleanup override
      const originalCleanup = instance.cleanup;
      instance.cleanup = function() {
        if (this.gdatetime) {
          this.gdatetime.destroy();
          this.gdatetime = null;
        }
        if (originalCleanup) {
          originalCleanup.call(this);
        }
        return this;
      };

      return instance;

    } catch (error) {
      console.error('DateElementFactory: Failed to setup element:', error);
      return instance;
    }
  }
}

// Register with ElementManager
ElementManager.registerElement('date', DateElementFactory);
ElementManager.registerElement('datetime-local', DateElementFactory);
ElementManager.registerElement('daterange', DateElementFactory);

// Expose globally
window.DateElementFactory = DateElementFactory;
