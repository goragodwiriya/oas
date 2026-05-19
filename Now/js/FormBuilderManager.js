/**
 * FormBuilder Manager
 *
 * Manages dynamic form creation and editing using the Now.js framework
 * Integrates with existing ElementManager and provides drag & drop functionality
 *
 * @filesource Now/js/FormBuilderManager.js
 * @link https://www.kotchasan.com/
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

const FormBuilderManager = {
  config: {
    propertyPanelSelector: '#property-panel',
    apiEndpoint: 'api/formbuilder',
    fieldTypes: {
      // Map to existing ElementFactory classes
      text: 'TextElementFactory',
      email: 'TextElementFactory',
      password: 'PasswordElementFactory',
      number: 'NumberElementFactory',
      tel: 'TextElementFactory',
      url: 'TextElementFactory',
      select: 'SelectElementFactory',
      multiselect: 'MultiSelectElementFactory',
      tags: 'TagsElementFactory',
      date: 'DateElementFactory',
      datetime: 'DateElementFactory',
      file: 'FileElementFactory',
      image: 'FileElementFactory',
      textarea: 'TextareaElementFactory',
      html: 'TextElementFactory',
      search: 'SearchElementFactory',
      range: 'RangeElementFactory'
    }
  },

  state: {
    initialized: false,
    currentSchema: null,
    selectedField: null,
    draggedField: null
  },

  /**
   * Initialize FormBuilder Manager
   *
   * @param {Object} options Configuration options
   * @returns {Object} FormBuilderManager instance
   */
  async init(options = {}) {
    // Check for required dependencies
    if (!window.ElementFactory) {
      console.error('ElementFactory not found. Make sure now.core.min.js is loaded first.');
      throw new Error('Missing dependency: ElementFactory');
    }

    // Allow re-initialization with new config
    if (this.state.initialized && Object.keys(options).length > 0) {
      this.state.initialized = false;
    }

    if (this.state.initialized) return this;    // Merge options into config
    this.config = {...this.config, ...options};

    // Initialize drag & drop only if canvas/dropZone provided
    if (this.config.canvas || this.config.dropZone) {
      await this.initDragDrop();
    } else {
      console.warn('FormBuilderManager: No canvas or dropZone provided. Drag & drop disabled.');
    }

    // Register with ElementManager
    if (window.ElementManager) {
      this.registerWithElementManager();
    }

    // Setup event listeners
    this.setupEventListeners();

    // Wire property panel if provided in config and no external editor is supplied
    if (this.config.propertyPanelSelector && !this.config.onFieldSelect) {
      try {
        this.propertyPanel = document.querySelector(this.config.propertyPanelSelector);
        // when a field is selected, open property panel
        document.addEventListener('formbuilder:fieldSelected', (ev) => {
          const d = ev.detail || {};
          if (d.fieldId) this.openPropertyPanel(d.fieldId);
        });
      } catch (e) {
        console.warn('Invalid propertyPanelSelector:', this.config.propertyPanelSelector);
      }
    }
    // Warn if Modal (required for preview) is not available
    if (!window.Modal) {
      console.warn('FormBuilderManager: Modal component not found. Preview will not work. Ensure Modal.js is loaded before FormBuilderManager.');
    }

    this.state.initialized = true;

    return this;
  },

  /**
   * Create a new form field
   *
   * @param {string} type Field type
   * @param {Object} config Field configuration
   * @returns {HTMLElement} Created field wrapper element
   */
  /**
   * Create a new form field
   *
   * @param {string} type Field type
   * @param {Object} config Field configuration
   * @param {Object} options Options {clean: boolean}
   * @returns {HTMLElement} Created field wrapper element
   */
  createField(type, config = {}, options = {}) {
    // Normalize type to ensure we hit the manual creators for checkbox/radio
    const normalizedType = (type || '').toString().toLowerCase();

    if (normalizedType === 'checkbox') {
      return this.createCheckbox(config, options);
    } else if (normalizedType === 'radio') {
      return this.createRadio(config, options);
    } else if (normalizedType === 'time') {
      return this.createInput(normalizedType, config, options);
    } else if (normalizedType === 'hidden') {
      return this.createInput(normalizedType, config, options);
    } else if (normalizedType === 'fieldset') {
      return this.createFieldset(config, options);
    } else if (normalizedType === 'divider') {
      return this.createDivider(config, options);
    }

    const factoryClass = this.config.fieldTypes[type];
    if (!factoryClass) {
      console.error(`No factory mapping for field type: ${type}`);
      throw new Error(`Unknown field type: ${type}`);
    }

    if (!window[factoryClass]) {
      console.error(`Factory class not found: ${factoryClass} for type: ${type}`);
      throw new Error(`Unknown field type: ${type}`);
    }

    // Generate unique field ID if not provided
    if (!config.id) {
      config.id = this.generateFieldId(type);
    }

    // Set configuration
    const fieldConfig = {
      // wrapper should be a tag name (e.g., 'div') so ElementFactory.createWrapper creates a real element
      wrapper: config.wrapper !== undefined ? config.wrapper : 'div',
      ...config
    };

    // Create field using appropriate ElementFactory
    const fieldInstance = window[factoryClass].create(fieldConfig);

    // Get the wrapper element (for display in FormBuilder)
    // Priority: wrapper (div/label) > container > element
    const wrapperElement = fieldInstance.wrapper || fieldInstance.container || fieldInstance.element;
    const inputElement = fieldInstance.element;

    // Check if we got a valid element
    if (!wrapperElement || !(wrapperElement instanceof HTMLElement)) {
      console.error('Factory did not return a valid HTMLElement:', fieldInstance);
      throw new Error(`Failed to create field element for type: ${type}`);
    }

    // Store reference to the actual input element for later access
    wrapperElement._fbInputElement = inputElement;
    wrapperElement._fbInstance = fieldInstance;

    // Add FormBuilder specific attributes to wrapper (only if not clean)
    if (!options.clean) {
      wrapperElement.setAttribute('data-fb-type', type);
      wrapperElement.setAttribute('data-fb-id', fieldConfig.id);
    }

    // Also add to input element for easy identification (only if not clean)
    if (inputElement !== wrapperElement && !options.clean) {
      inputElement.setAttribute('data-fb-type', type);
      inputElement.setAttribute('data-fb-id', fieldConfig.id);
    }

    // Make field selectable and draggable in builder mode
    if (!options.clean && !fieldConfig._fbNoInteractive) {
      this.makeFieldInteractive(wrapperElement, fieldConfig);
    }

    return wrapperElement;
  },

  createCheckbox(config = {}, options = {}) {
    // Generate unique field ID if not provided
    if (!config.id) {
      config.id = this.generateFieldId('checkbox');
    }

    const wrapperElement = document.createElement('div');
    const inputElement = document.createElement('input');
    inputElement.type = 'checkbox';
    inputElement.className = 'switch';
    inputElement.id = config.id || `checkbox-${Math.random().toString(36).substr(2, 9)}`;
    inputElement.name = config.name || inputElement.id;
    if (config.value !== undefined) inputElement.value = config.value;
    if (config.checked !== undefined) inputElement.checked = !!config.checked;
    wrapperElement.appendChild(inputElement);
    const label = document.createElement('label');
    label.htmlFor = config.id;
    label.textContent = config.label || 'Checkbox Field';
    wrapperElement.appendChild(label);

    // Comment/help text
    if (config.comment) {
      const commentEl = document.createElement('div');
      commentEl.className = 'comment';
      commentEl.id = `result_${config.id}`;
      commentEl.textContent = config.comment;
      if (config.i18nComment) {
        commentEl.setAttribute('data-i18n', config.i18nComment === true ? '' : config.i18nComment);
      }
      wrapperElement.appendChild(commentEl);
    }

    // Store reference to the actual input element for later access
    wrapperElement._fbInputElement = inputElement;

    if (!options.clean) {
      // Add FormBuilder specific attributes to wrapper
      wrapperElement.setAttribute('data-fb-type', 'checkbox');
      wrapperElement.setAttribute('data-fb-id', config.id);

      // Make field selectable and draggable in builder mode
      if (!config._fbNoInteractive) {
        this.makeFieldInteractive(wrapperElement, config);
      }
    }

    return wrapperElement;
  },

  createRadio(config = {}, options = {}) {
    // Generate unique field ID if not provided
    if (!config.id) {
      config.id = this.generateFieldId('radio');
    }

    const groupName = config.name || config.id;
    const optionsList = Array.isArray(config.options) && config.options.length > 0
      ? config.options
      : [
        {value: 'option1', label: 'Option 1', checked: true},
        {value: 'option2', label: 'Option 2'},
        {value: 'option3', label: 'Option 3'}
      ];

    // Create wrapper
    const wrapperElement = document.createElement('div');
    wrapperElement.className = 'btn-group';

    const radioInputs = [];

    optionsList.forEach((option, index) => {
      const radioId = `${config.id}-${index}`;
      const inputElement = document.createElement('input');
      inputElement.type = 'radio';
      inputElement.id = radioId;
      inputElement.name = groupName;
      inputElement.value = option.value ?? option;
      if (option.checked || (index === 0 && !optionsList.some(opt => opt.checked))) {
        inputElement.checked = true;
      }

      const label = document.createElement('label');
      label.htmlFor = radioId;
      label.className = 'btn';
      label.textContent = option.label ?? option.value ?? option;

      // Append input then label (as requested structure)
      wrapperElement.appendChild(inputElement);
      wrapperElement.appendChild(label);

      radioInputs.push(inputElement);
    });

    // Store reference to first input element for compatibility
    wrapperElement._fbInputElement = radioInputs[0];
    wrapperElement._fbInputs = radioInputs;

    // Comment/help text
    if (config.comment) {
      const commentEl = document.createElement('div');
      commentEl.className = 'comment';
      commentEl.id = `result_${config.id}`;
      commentEl.textContent = config.comment;
      if (config.i18nComment) {
        commentEl.setAttribute('data-i18n', config.i18nComment === true ? '' : config.i18nComment);
      }
      wrapperElement.appendChild(commentEl);
    }

    if (!options.clean) {
      // Add FormBuilder specific attributes to wrapper
      wrapperElement.setAttribute('data-fb-type', 'radio');
      wrapperElement.setAttribute('data-fb-id', config.id);

      // Make field selectable and draggable in builder mode
      if (!config._fbNoInteractive) {
        this.makeFieldInteractive(wrapperElement, config);
      }
    }

    return wrapperElement;
  },

  createIconSelect(config = {}, options = {}) {
    // Generate unique field ID if not provided
    if (!config.id) {
      config.id = this.generateFieldId('icon-select');
    }

    const wrapperElement = document.createElement('div');
    wrapperElement.className = 'icon-select-group';

    // Label
    if (config.label) {
      const labelEl = document.createElement('label');
      labelEl.textContent = config.label;
      wrapperElement.appendChild(labelEl);
    }

    const radioGroup = document.createElement('div');
    radioGroup.className = 'icon-radios';

    const iconOptions = config.icons || [
      'icon-email', 'icon-user', 'icon-customer', 'icon-password', 'icon-phone', 'icon-address',
      'icon-calendar', 'icon-event', 'icon-clock', 'icon-search', 'icon-edit', 'icon-number',
      'icon-link', 'icon-menus', 'icon-upload', 'icon-image', 'icon-thumbnail', 'icon-gallery',
      'icon-file', 'icon-tags', 'icon-star0', 'icon-star2', 'icon-heart', 'icon-settings',
      'icon-cog', 'icon-home', 'icon-office', 'icon-barcode', 'icon-product', 'icon-cart',
      'icon-addtocart', 'icon-billing', 'icon-payment', 'icon-money', 'icon-shipping', 'icon-wallet'
    ];

    iconOptions.forEach((iconClass, index) => {
      const radioId = `${config.id}_${index}`;
      const radioWrapper = document.createElement('div');
      radioWrapper.className = 'icon-radio-item';

      const input = document.createElement('input');
      input.type = 'radio';
      input.name = config.name || config.id;
      input.id = radioId;
      input.value = iconClass;
      if (config.value === iconClass) input.checked = true;

      const label = document.createElement('label');
      label.htmlFor = radioId;
      label.className = `icon-label ${iconClass}`;
      label.title = iconClass;

      radioWrapper.appendChild(input);
      radioWrapper.appendChild(label);
      radioGroup.appendChild(radioWrapper);
    });

    wrapperElement.appendChild(radioGroup);

    // Store reference to inputs for value retrieval
    wrapperElement._fbInputs = Array.from(radioGroup.querySelectorAll('input[type="radio"]'));
    // Helper to get value
    wrapperElement._fbGetValue = () => {
      const checked = wrapperElement.querySelector('input:checked');
      return checked ? checked.value : '';
    };

    return wrapperElement;
  },

  createInput(type, config = {}, options = {}) {
    // Generate unique field ID if not provided
    if (!config.id) {
      config.id = this.generateFieldId(type);
    }

    // Handle hidden input specifically
    let inputType = type;
    if (type === 'hidden') {
      if (options.clean) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = config.name || config.id;
        input.id = config.id;
        if (config.value !== undefined) input.value = config.value;
        return input;
      } else {
        // In builder mode, show as text input so it can be seen and edited
        inputType = 'text';
        if (!config.placeholder) config.placeholder = 'Hidden Value';
        if (!config.icon) config.icon = 'icon-hide';
      }
    }

    const wrapperElement = document.createElement('div');

    // Label
    const labelEl = document.createElement('label');
    labelEl.htmlFor = config.id;
    labelEl.textContent = config.label || this.generateLabel(type);
    if (config.i18nLabel) {
      labelEl.setAttribute('data-i18n', config.i18nLabel === true ? '' : config.i18nLabel);
    }
    wrapperElement.appendChild(labelEl);

    // Input wrapper with icon class (allow override)
    const controlSpan = document.createElement('span');
    const iconClass = config.icon ? `${config.icon}` : '';
    controlSpan.className = `form-control ${iconClass}`;

    const inputElement = document.createElement('input');
    inputElement.type = inputType; // Use local inputType (handles generic vs hidden-as-text)
    inputElement.id = config.id || `${type}-${Math.random().toString(36).substr(2, 9)}`;
    inputElement.name = config.name || inputElement.id;
    if (config.value) {
      inputElement.value = config.value;
    }

    controlSpan.appendChild(inputElement);
    wrapperElement.appendChild(controlSpan);

    // Comment/help text
    if (config.comment) {
      const commentEl = document.createElement('div');
      commentEl.className = 'comment';
      commentEl.id = `result_${config.id}`;
      commentEl.textContent = config.comment;
      if (config.i18nComment) {
        commentEl.setAttribute('data-i18n', config.i18nComment === true ? '' : config.i18nComment);
      }
      wrapperElement.appendChild(commentEl);
    }

    // Store reference to the actual input element for later access
    wrapperElement._fbInputElement = inputElement;

    if (!options.clean) {
      // Add FormBuilder specific attributes to wrapper
      wrapperElement.setAttribute('data-fb-type', type);
      wrapperElement.setAttribute('data-fb-id', config.id);

      // Make field selectable and draggable in builder mode
      if (!config._fbNoInteractive) {
        this.makeFieldInteractive(wrapperElement, config);
      }
    }

    return wrapperElement;
  },

  createFieldset(config = {}, options = {}) {
    // Generate unique field ID if not provided
    if (!config.id) {
      config.id = this.generateFieldId('fieldset');
    }

    const fieldsetElement = document.createElement('fieldset');
    // In clean mode, only usage fb-fieldset if it's styled for the theme, otherwise usage standard fieldset
    // Assuming fb-fieldset might be builder specific, but often fieldsets are styled.
    // If clean, let's keep the class if it's design-related, or remove if builder-related.
    // Given the request "no class ... used during design", I'll try to stick to standard tags where possible,
    // but fieldset styling usually needs a class. Let's keep it for now unless requested to remove.
    // However, the prompt says "form that is clean, no class or tag used during design".
    // "fb-fieldset" sounds like FormBuilder Fieldset.
    // I will remove it in clean mode if it looks builder-y.
    if (!options.clean) {
      fieldsetElement.className = 'fb-fieldset';
    } else {
      // Optional: Add a standard class if needed, or leave empty.
      // fieldsetElement.className = 'fieldset'; // or similar if needed.
    }

    // Title/Legend
    if (config.title) {
      const legend = document.createElement('legend');
      legend.textContent = config.title;
      fieldsetElement.appendChild(legend);
    }

    // Description
    if (config.description) {
      const desc = document.createElement('p');
      desc.className = 'fieldset-description';
      desc.textContent = config.description;
      fieldsetElement.appendChild(desc);
    }

    // Content container for fields to be dropped inside
    // In clean mode, we do NOT use a wrapper div unless necessary.
    // The example.html shows fields directly inside <fieldset> (after legend).
    let contentElement = fieldsetElement;
    if (!options.clean) {
      contentElement = document.createElement('div');
      contentElement.className = 'fieldset-content';
      contentElement.setAttribute('data-drop-zone', 'true');
      fieldsetElement.appendChild(contentElement);
    }

    if (!options.clean) {
      // Add FormBuilder specific attributes
      fieldsetElement.setAttribute('data-fb-type', 'fieldset');
      fieldsetElement.setAttribute('data-fb-id', config.id);

      // Store config reference
      fieldsetElement._fbConfig = config;

      // Make field selectable and draggable in builder mode
      if (!config._fbNoInteractive) {
        this.makeFieldInteractive(fieldsetElement, config);
        // Enable drop zone for fieldset content
        this.initFieldsetDropZone(contentElement);
      }
    }

    return fieldsetElement;
  },

  /**
   * Initialize drop zone for fieldset content
   */
  initFieldsetDropZone(contentElement) {
    // Prevent default drag behavior
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
      contentElement.addEventListener(eventName, (e) => {
        e.preventDefault();
        e.stopPropagation();
      });
    });

    // Visual feedback for drag over
    contentElement.addEventListener('dragenter', () => {
      contentElement.classList.add('drag-over');
    });

    contentElement.addEventListener('dragleave', (e) => {
      if (e.target === contentElement) {
        contentElement.classList.remove('drag-over');
      }
    });

    contentElement.addEventListener('dragover', (e) => {
      e.dataTransfer.dropEffect = this.state.draggedField ? 'move' : 'copy';
    });

    // Handle drop event
    contentElement.addEventListener('drop', (e) => {
      contentElement.classList.remove('drag-over');

      try {
        const dataText = e.dataTransfer.getData('text/plain');
        const data = JSON.parse(dataText);

        // If dropping into a row column inside this fieldset, prefer that column
        const targetColumn = e.target.closest('.fb-row-col');

        if (data.isNew) {
          // New field from palette
          this.handleFieldsetFieldDrop(data.type, e, contentElement, targetColumn || null);
        } else if (data.fieldId) {
          // Existing field being moved
          this.handleFieldMoveToFieldset(data.fieldId, e, contentElement, targetColumn || null);
        }
      } catch (error) {
        console.error('Failed to handle fieldset drop:', error);
      }
    });
  },

  /**
   * Handle drop of new field into fieldset
   */
  handleFieldsetFieldDrop(type, event, contentElement, targetColumn = null) {
    try {
      const fieldTypeConfig = window.FieldTypeRegistry?.[type];
      if (!fieldTypeConfig) {
        console.error(`Unknown field type: ${type}`);
        return;
      }

      // Disallow placing a fieldset inside another fieldset: fieldset allowed only in top-level container
      if (type === 'fieldset') {
        console.warn('Fieldset cannot be dropped inside a fieldset. Place fieldset only in the main container.');
        if (window.NotificationManager && typeof NotificationManager.show === 'function') {
          NotificationManager.show('Fieldset can only be placed in the main container', 'warning');
        }
        return;
      }

      const defaultConfig = fieldTypeConfig.defaultConfig || {};
      // If this is a row-like type (row2/row3 or defaultConfig.type === 'row'),
      // create a row and insert it into the fieldset content
      if (defaultConfig.type === 'row' || type === 'row2' || type === 'row3' || type === 'row') {
        const rowConfig = {
          id: this.generateFieldId('row'),
          type: 'row',
          columns: Array.isArray(defaultConfig.columns) ? defaultConfig.columns.map(col => ({
            width: col.width || col.size || col.col || 100,
            fields: Array.isArray(col.fields) ? col.fields.slice() : []
          })) : []
        };

        const addedRow = this.addField(rowConfig);
        const rowElement = this.renderRow(addedRow, this.state.currentSchema?.fields || []);
        if (!rowElement) return;

        const insertBeforeRow = this.getInsertionPoint(event, contentElement, '.fb-field, .fb-row');
        if (insertBeforeRow) {
          contentElement.insertBefore(rowElement, insertBeforeRow);
        } else {
          contentElement.appendChild(rowElement);
        }

        // Sync fieldset children ordering to include the row
        this._syncFieldsetFromDOM(contentElement);
        this.selectField(rowElement, addedRow);
        return;
      }
      const fieldId = this.generateFieldId(type);
      const config = {
        type: type,
        label: fieldTypeConfig.label || type,
        name: fieldId,
        id: fieldId,
        icon: fieldTypeConfig.icon || undefined,
        comment: fieldTypeConfig.comment || undefined,
        wrapper: 'div'
      };

      // Merge default config
      for (const key in defaultConfig) {
        const val = defaultConfig[key];
        if (typeof val !== 'function' && typeof val !== 'object') {
          config[key] = val;
        } else if (Array.isArray(val)) {
          config[key] = val.slice();
        }
      }

      // Add to schema
      this.addField(config);

      // Create field element
      const fieldElement = this.createField(type, config);

      // If a specific row column target was provided, insert into that column
      if (targetColumn) {
        const insertBefore = this.getInsertionPoint(event, targetColumn, '.fb-field');
        if (insertBefore) {
          targetColumn.insertBefore(fieldElement, insertBefore);
        } else {
          targetColumn.appendChild(fieldElement);
        }

        // Sync row/column metadata from DOM
        this._syncRowColumnFromDOM(targetColumn);
        this._syncFieldsetFromDOM(contentElement);
      } else {
        // Insert into fieldset content
        const insertBefore = this.getInsertionPoint(event, contentElement, '.fb-field');
        if (insertBefore) {
          contentElement.insertBefore(fieldElement, insertBefore);
        } else {
          contentElement.appendChild(fieldElement);
        }
      }

      // Sync fieldset children ordering based on DOM
      this._syncFieldsetFromDOM(contentElement);

      this.selectField(fieldElement, config);
    } catch (error) {
      console.error('Failed to add field to fieldset:', error);
    }
  },

  /**
   * Handle move of existing field into fieldset
   */
  handleFieldMoveToFieldset(fieldId, event, contentElement, targetColumn = null) {
    const fieldElement = document.querySelector(`[data-fb-id="${fieldId}"]`);
    if (!fieldElement || !contentElement) return;

    // Disallow moving an existing fieldset into a fieldset
    const movingType = fieldElement.getAttribute('data-fb-type');
    if (movingType === 'fieldset') {
      console.warn('Cannot move a fieldset into another fieldset.');
      if (window.NotificationManager && typeof NotificationManager.show === 'function') {
        NotificationManager.show('Cannot move a fieldset into another fieldset', 'warning');
      }
      return;
    }

    // Remove from any previous fieldset mappings
    this._removeFieldFromFieldsets(fieldId);

    // If moving into a specific row column, insert there
    if (targetColumn) {
      const insertBefore = this.getInsertionPoint(event, targetColumn, '.fb-field');
      if (insertBefore && insertBefore !== fieldElement) {
        targetColumn.insertBefore(fieldElement, insertBefore);
      } else if (!insertBefore) {
        targetColumn.appendChild(fieldElement);
      }

      // Sync row column info
      this._syncRowColumnFromDOM(targetColumn);
    } else {
      // allow inserting before rows or fields inside the fieldset content
      const insertBefore = this.getInsertionPoint(event, contentElement, '.fb-field, .fb-row');
      if (insertBefore && insertBefore !== fieldElement) {
        contentElement.insertBefore(fieldElement, insertBefore);
      } else if (!insertBefore) {
        contentElement.appendChild(fieldElement);
      }
    }

    this._removeFieldFromRows(fieldId);
    this._syncFieldsetFromDOM(contentElement);
    this.triggerEvent('fieldReordered', {fieldId});
  },


  createDivider(config = {}, options = {}) {
    // Generate unique field ID if not provided
    if (!config.id) {
      config.id = this.generateFieldId('divider');
    }

    const dividerElement = document.createElement('hr');
    dividerElement.className = 'fb-divider';

    // Apply style
    const style = config.style || 'solid';
    const thickness = config.thickness || 1;
    const color = config.color || '#cccccc';

    dividerElement.style.borderStyle = style;
    dividerElement.style.borderWidth = `${thickness}px 0 0 0`;
    dividerElement.style.borderColor = color;
    dividerElement.style.margin = '16px 0';

    if (!options.clean) {
      // Add FormBuilder specific attributes
      dividerElement.setAttribute('data-fb-type', 'divider');
      dividerElement.setAttribute('data-fb-id', config.id);

      // Store config reference
      dividerElement._fbConfig = config;

      // Make field selectable and draggable in builder mode
      if (!config._fbNoInteractive) {
        this.makeFieldInteractive(dividerElement, config);
      }
    }

    return dividerElement;
  },

  /**
   * Render complete form from schema
   *
   * @param {Object} schema Form schema
   * @param {HTMLElement} container Container element
   * @param {Object} options Options {clean: boolean}
   * @returns {HTMLElement} Rendered form element
   */
  renderForm(schema, container, options = {}) {
    if (!schema || !schema.fields) {
      throw new Error('Invalid schema provided');
    }

    // Ensure schema is tracked for editing in builder mode (skip in clean mode)
    if (!options.clean && !this.state.currentSchema) {
      try {
        this.state.currentSchema = JSON.parse(JSON.stringify(schema));
      } catch (e) {
        this.state.currentSchema = schema;
      }
    }

    const formElement = document.createElement('form');
    // In clean mode, only set necessary form attributes
    if (options.clean) {
      if (schema.metadata?.id) formElement.id = schema.metadata.id;
    } else {
      formElement.setAttribute('data-form', schema.metadata?.id || 'dynamic-form');
      formElement.className = 'formbuilder-form';
    }

    // Apply form settings
    if (schema.settings) {
      this.applyFormSettings(formElement, schema.settings);
    }

    const rowFieldIds = new Set();
    (schema.fields || []).forEach(field => {
      if (field.type === 'row' && Array.isArray(field.columns)) {
        field.columns.forEach(col => {
          if (col.fieldId) rowFieldIds.add(col.fieldId);
          if (Array.isArray(col.fields)) {
            col.fields.forEach(fieldId => rowFieldIds.add(fieldId));
          }
        });
      }
    });

    // Track any fields that are children of fieldsets to avoid rendering them twice at root level
    const fieldsetChildIds = new Set();
    (schema.fields || []).forEach(field => {
      if (field.type === 'fieldset' && Array.isArray(field.fields)) {
        field.fields.forEach(fid => fieldsetChildIds.add(fid));
      }
    });

    // Render fields into form
    schema.fields.forEach(fieldConfig => {
      if (fieldConfig.type !== 'row' && rowFieldIds.has(fieldConfig.id)) {
        return;
      }

      // Skip any field that is already rendered as a child of a fieldset
      if (fieldConfig.type !== 'fieldset' && fieldsetChildIds.has(fieldConfig.id)) {
        return;
      }

      // Fieldset: create fieldset element and render its child fields inside
      if (fieldConfig.type === 'fieldset') {
        const fsElement = this.createField('fieldset', fieldConfig, options);
        try {
          const contentEl = fsElement.querySelector('.fieldset-content') || fsElement;
          if (fieldConfig.fields && Array.isArray(fieldConfig.fields)) {
            fieldConfig.fields.forEach(childId => {
              const childCfg = (schema.fields || []).find(f => f.id === childId);
              if (!childCfg) return;
              // Skip row children handled separately
              if (childCfg.type === 'row' || childCfg.columns) {
                const rowEl = this.renderRow(childCfg, schema.fields, options);
                if (rowEl && contentEl) contentEl.appendChild(rowEl);
              } else {
                const childEl = this.createField(childCfg.type, childCfg, options);
                if (contentEl && childEl) contentEl.appendChild(childEl);
              }
            });
          }
        } catch (e) {}
        formElement.appendChild(fsElement);
        return;
      }

      if (fieldConfig.type === 'row' || fieldConfig.columns) {
        const rowElement = this.renderRow(fieldConfig, schema.fields, options);
        if (rowElement) formElement.appendChild(rowElement);
      } else {
        const fieldElement = this.createField(fieldConfig.type, fieldConfig, options);
        formElement.appendChild(fieldElement);
      }
    });

    // In clean/preview mode, automatically add a submit section
    if (options.clean) {
      const submitFieldset = document.createElement('fieldset');
      submitFieldset.className = 'submit';

      const saveBtn = document.createElement('button');
      saveBtn.type = 'submit';
      saveBtn.className = 'btn btn-primary icon-save';
      saveBtn.textContent = 'Save'; // Default text, data-i18n will handle translation if present
      saveBtn.setAttribute('data-i18n', '');

      submitFieldset.appendChild(saveBtn);
      formElement.appendChild(submitFieldset);
    }

    container.appendChild(formElement);

    // Initialize with FormManager if available
    if (window.FormManager) {
      FormManager.initForm(formElement);
    }

    return formElement;
  },

  /**
   * Render a row layout (form-group with width-based columns)
   *
   * @param {Object} rowConfig Row configuration
   * @param {Array} allFields All available fields
   * @param {Object} options Options {clean: boolean}
   * @returns {HTMLElement|null} Rendered row element
   */
  renderRow(rowConfig, allFields, options = {}) {
    const columns = rowConfig.columns || [];
    if (!Array.isArray(columns) || columns.length === 0) return null;

    const rowElement = document.createElement('div');
    // In clean mode, usage standard form-group. fb-row is likely builder specific flex/grid.
    // Assuming the theme handles 'form-group' with 'widthXX' children correctly.
    // If 'item' is the standard class for rows in this framework, we might want to check.
    // But 'form-group' is what was there.
    rowElement.className = options.clean ? 'form-group' : 'form-group fb-row';

    const rowId = rowConfig.id || this.generateFieldId('row');
    rowConfig.id = rowId;

    if (!options.clean) {
      rowElement.setAttribute('data-row-id', rowId);
      rowElement.setAttribute('data-fb-id', rowId);
      rowElement.setAttribute('data-fb-type', 'row');

      // Make field selectable and draggable in builder mode (Fix for deletion issue)
      this.makeFieldInteractive(rowElement, rowConfig);
    }

    columns.forEach((col, index) => {
      const width = col.width || col.size || col.col || '100';
      const widthClass = String(width).startsWith('width') ? String(width) : `width${width}`;
      const colElement = document.createElement('div');

      // fb-row-col is builder specific (drag target).
      colElement.className = options.clean ? widthClass : `${widthClass} fb-row-col`;

      if (!options.clean) {
        colElement.setAttribute('data-row-id', rowId);
        colElement.setAttribute('data-col-index', index);
      }

      let fieldConfig = col.field || null;
      if (!fieldConfig && col.fieldId) {
        fieldConfig = allFields.find(f => f.id === col.fieldId);
      }
      if (!fieldConfig && col.id) {
        fieldConfig = allFields.find(f => f.id === col.id);
      }

      if (fieldConfig) {
        const fieldElement = this.createField(fieldConfig.type, fieldConfig, options);
        colElement.appendChild(fieldElement);
      }

      if (Array.isArray(col.fields)) {
        col.fields.forEach(fieldId => {
          const fieldCfg = allFields.find(f => f.id === fieldId);
          if (fieldCfg) {
            const fieldEl = this.createField(fieldCfg.type, fieldCfg, options);
            colElement.appendChild(fieldEl);
          }
        });
      }

      rowElement.appendChild(colElement);
    });

    return rowElement;
  },

  /**
   * Generate unique field ID
   *
   * @param {string} type Field type
   * @returns {string} Unique field ID
   */
  generateFieldId(type) {
    const timestamp = Date.now();
    const random = Math.floor(Math.random() * 1000);
    return `${type}_${timestamp}_${random}`;
  },

  /**
   * Make field interactive in builder mode
   *
   * @param {HTMLElement} fieldElement Field element
   * @param {Object} config Field configuration
   */
  makeFieldInteractive(fieldElement, config) {
    // Add selection capability
    fieldElement.addEventListener('click', (e) => {
      e.stopPropagation();
      this.selectField(fieldElement, config);
    });

    // Add drag capability
    fieldElement.draggable = true;
    fieldElement.addEventListener('dragstart', (e) => {
      this.handleFieldDragStart(e, fieldElement, config);
    });
    fieldElement.addEventListener('dragend', () => {
      try {
        fieldElement.classList.remove('dragging');
        this.state.draggedField = null;
      } catch (e) {}
    });
  },

  /**
   * Select a field in the builder
   *
   * @param {HTMLElement} fieldElement Field element
   * @param {Object} config Field configuration
   */
  selectField(fieldElement, config) {
    // Remove previous selection
    document.querySelectorAll('.fb-field-selected').forEach(el => {
      el.classList.remove('fb-field-selected');
    });

    // Select current field
    fieldElement.classList.add('fb-field-selected');

    // Store only the field ID (avoid circular references)
    this.state.selectedField = {
      fieldId: fieldElement.getAttribute('data-fb-id'),
      fieldType: config.type,
      fieldLabel: config.label
    };

    // Trigger field selection event (serializable data only)
    this.triggerEvent('fieldSelected', {
      fieldId: fieldElement.getAttribute('data-fb-id'),
      fieldType: config.type,
      fieldLabel: config.label
    });

    // If an external editor is provided, call it with the schema field
    if (typeof this.config.onFieldSelect === 'function') {
      const schema = this.getSchema();
      const fieldId = fieldElement.getAttribute('data-fb-id');
      const field = (schema.fields || []).find(f => f.id === fieldId);
      if (field) this.config.onFieldSelect(field);
    }
  },

  /**
   * Open property panel for a field id (renders inputs based on FieldTypeRegistry)
   * @param {string} fieldId
   */
  openPropertyPanel(fieldId) {
    if (!this.propertyPanel) return;

    // find field in current schema
    const schema = this.getSchema();
    const field = (schema.fields || []).find(f => f.id === fieldId) || null;
    if (!field) return;

    this.propertyPanel.dataset.fieldId = fieldId;
    this.propertyPanel.innerHTML = '';

    const meta = window.FieldTypeRegistry && window.FieldTypeRegistry.getFieldType ? window.FieldTypeRegistry.getFieldType(field.type) : null;
    const properties = (meta && meta.properties) ? meta.properties : [];

    const title = document.createElement('h3');
    title.textContent = meta?.label || field.type;
    this.propertyPanel.appendChild(title);

    const isLayoutType = ['row', 'fieldset', 'divider'].includes(field.type);
    if (!isLayoutType) {
      const nameRow = document.createElement('div');
      nameRow.className = 'fb-prop-row';
      const nameLabel = document.createElement('label');
      nameLabel.textContent = 'Field Name';
      const nameInput = document.createElement('input');
      nameInput.type = 'text';
      nameInput.value = field.name || field.id || '';
      nameInput.dataset.propName = 'name';
      nameInput.classList.add('fb-prop-input');
      nameInput.addEventListener('input', (e) => this._onPropertyInput(e));
      nameInput.addEventListener('change', (e) => this._onPropertyInput(e));
      nameRow.appendChild(nameLabel);
      nameRow.appendChild(nameInput);
      this.propertyPanel.appendChild(nameRow);
    }

    properties.forEach(prop => {
      const currentVal = (field[prop.name] !== undefined)
        ? field[prop.name]
        : (field.config && field.config[prop.name] !== undefined ? field.config[prop.name] : (prop.default !== undefined ? prop.default : ''));

      const fieldEl = this._buildPropertyField(prop, currentVal, fieldId);
      if (fieldEl) this.propertyPanel.appendChild(fieldEl);
    });

    // delete button
    const del = document.createElement('button');
    del.type = 'button';
    del.className = 'fb-prop-delete';
    del.textContent = 'Delete Field';
    del.addEventListener('click', () => {
      this.removeField(fieldId);
    });
    this.propertyPanel.appendChild(del);
  },

  /**
   * Open the schema (form) level property panel
   * Renders inputs for editing form metadata and settings
   */
  openSchemaPanel() {
    if (!this.propertyPanel) return;

    // Clear field selection marker
    try {delete this.propertyPanel.dataset.fieldId;} catch (e) {}
    this.propertyPanel.innerHTML = '';

    const titleText = '{LNG_Form} {LNG_Settings}';
    const title = document.createElement('h3');
    title.textContent = Now.translate(titleText);
    title.setAttribute('data-i18n', titleText);
    this.propertyPanel.appendChild(title);

    const schema = this.getSchema();

    // Helper to build a labeled input row
    const buildRow = (labelText, inputEl) => {
      const row = document.createElement('div');
      row.className = 'fb-prop-row';
      if (labelText) {
        const label = document.createElement('label');
        label.textContent = Now.translate(labelText);
        label.setAttribute('data-i18n', labelText);
        row.appendChild(label);
      }
      inputEl.classList.add('fb-prop-input');
      row.appendChild(inputEl);
      return row;
    };

    // Form Name
    const nameInput = document.createElement('input');
    nameInput.type = 'text';
    nameInput.value = this.state.schemaName || schema.metadata?.title || '';
    nameInput.addEventListener('input', (e) => {
      this.state.schemaName = e.target.value;
      if (!this.state.currentSchema) this.state.currentSchema = this.createEmptySchema();
      this.state.currentSchema.metadata = this.state.currentSchema.metadata || {};
      this.state.currentSchema.metadata.title = e.target.value;
    });
    this.propertyPanel.appendChild(buildRow('Form Name', nameInput));

    // Description
    const descEl = document.createElement('textarea');
    descEl.rows = 3;
    descEl.value = schema.metadata?.description || '';
    descEl.addEventListener('input', (e) => {
      if (!this.state.currentSchema) this.state.currentSchema = this.createEmptySchema();
      this.state.currentSchema.metadata = this.state.currentSchema.metadata || {};
      this.state.currentSchema.metadata.description = e.target.value;
    });
    this.propertyPanel.appendChild(buildRow('Description', descEl));

    // Submission method select
    const methodSelect = document.createElement('select');
    ['POST', 'GET'].forEach(m => {
      const opt = document.createElement('option');
      opt.value = m;
      opt.textContent = m;
      methodSelect.appendChild(opt);
    });
    methodSelect.value = (schema.settings?.submission?.method || 'POST').toUpperCase();
    methodSelect.addEventListener('change', (e) => {
      this.state.currentSchema = this.state.currentSchema || this.createEmptySchema();
      this.state.currentSchema.settings = this.state.currentSchema.settings || {};
      this.state.currentSchema.settings.submission = this.state.currentSchema.settings.submission || {};
      this.state.currentSchema.settings.submission.method = e.target.value;
    });
    this.propertyPanel.appendChild(buildRow('Method', methodSelect));

    // Form ID
    const endpointInput = document.createElement('input');
    endpointInput.type = 'hidden';
    endpointInput.value = schema.metadata?.id || 0;
    this.propertyPanel.appendChild(buildRow(null, endpointInput));

    // Save button
    const saveBtn = document.createElement('button');
    saveBtn.type = 'button';
    saveBtn.className = 'fb-prop-save';
    saveBtn.textContent = 'Save Form Settings';
    saveBtn.addEventListener('click', async () => {
      const name = this.state.schemaName || (this.state.currentSchema && this.state.currentSchema.metadata && this.state.currentSchema.metadata.title) || '';
      const description = this.state.currentSchema?.metadata?.description || '';
      await this.saveSchema(name, description, 'draft');
    });
    this.propertyPanel.appendChild(saveBtn);
  },

  _buildPropertyField(prop, currentVal, fieldId) {
    const propType = prop.type || 'text';
    let fieldType = 'text';

    if (propType === 'number') fieldType = 'number';
    else if (propType === 'checkbox') fieldType = 'checkbox';
    else if (propType === 'select') fieldType = 'select';
    else if (propType === 'textarea' || propType === 'options-editor' || propType === 'datasource-editor' || propType === 'tags') fieldType = 'textarea';
    else if (propType === 'icon-select') fieldType = 'icon-select';

    const config = {
      id: `prop_${fieldId}_${prop.name}`,
      label: prop.label || prop.name,
      placeholder: prop.placeholder || '',
      value: currentVal,
      options: prop.options || [],
      // For icon-select specific options if passed
      name: `prop_${fieldId}_${prop.name}`, // Group name for radios
      _fbNoInteractive: true
    };

    if (propType === 'checkbox') {
      config.checked = !!currentVal;
      if (prop.default !== undefined && currentVal === '') config.checked = !!prop.default;
    }

    let fieldElement;
    try {
      if (fieldType === 'textarea') {
        fieldElement = this.createField('textarea', config);
      } else if (fieldType === 'select') {
        fieldElement = this.createField('select', config);
      } else if (fieldType === 'checkbox') {
        fieldElement = this.createCheckbox(config);
      } else if (fieldType === 'number') {
        fieldElement = this.createField('number', config);
      } else if (fieldType === 'icon-select') {
        fieldElement = this.createIconSelect(config);
      } else {
        fieldElement = this.createField('text', config);
      }
    } catch (e) {
      return null;
    }

    fieldElement.classList.add('fb-prop-field');

    // Handle special listeners for icon-select
    if (fieldType === 'icon-select') {
      const inputs = fieldElement._fbInputs || [];
      inputs.forEach(input => {
        input.dataset.propName = prop.name;
        input.addEventListener('change', (e) => this._onPropertyInput(e));
      });
      return fieldElement;
    }

    const inputEl = fieldElement._fbInputElement || fieldElement.querySelector('input, textarea, select');
    if (inputEl) {
      inputEl.dataset.propName = prop.name;

      if (propType === 'textarea' || propType === 'options-editor' || propType === 'datasource-editor' || propType === 'tags') {
        inputEl.value = (typeof currentVal === 'object') ? JSON.stringify(currentVal, null, 2) : (currentVal ?? '');
      } else if (inputEl.type === 'checkbox') {
        inputEl.checked = !!currentVal;
      } else if (currentVal !== undefined && currentVal !== null) {
        inputEl.value = currentVal;
      }

      if (prop.min !== undefined) inputEl.min = prop.min;
      if (prop.max !== undefined) inputEl.max = prop.max;
      if (prop.step !== undefined) inputEl.step = prop.step;

      if (propType === 'color') {
        try {inputEl.type = 'color';} catch (e) {}
      }

      inputEl.addEventListener('input', (e) => this._onPropertyInput(e));
      inputEl.addEventListener('change', (e) => this._onPropertyInput(e));
    }

    return fieldElement;
  },

  _onPropertyInput(e) {
    const input = e.target;
    const propName = input.dataset.propName;
    const fieldId = this.propertyPanel && this.propertyPanel.dataset.fieldId;
    if (!propName || !fieldId) return;

    // read current schema field
    const schema = this.getSchema();
    const idx = (schema.fields || []).findIndex(f => f.id === fieldId);
    if (idx === -1) return;

    let newVal;
    if (input.type === 'checkbox') newVal = input.checked;
    else if (input.type === 'radio') {
      // For icon-select (radio), we take value of this specific radio if checked,
      // but typically we just want the value of the group.
      // Since _onPropertyInput triggers on 'change' of a specific radio, this.value is the new icon class.
      newVal = input.value;
    }
    else if (input.tagName === 'TEXTAREA') {
      const s = input.value.trim();
      if ((s.startsWith('{') || s.startsWith('['))) {
        try {newVal = JSON.parse(s);} catch (err) {newVal = s;}
      } else if (s.includes(',')) {
        newVal = s.split(',').map(x => x.trim()).filter(Boolean);
      } else newVal = s;
    } else if (input.type === 'number') {
      newVal = input.value === '' ? null : Number(input.value);
    } else newVal = input.value;

    // apply to field structure: prefer top-level property if exists, otherwise use config
    const field = schema.fields[idx];
    if (propName === 'name') {
      const normalized = this._sanitizeFieldName(newVal);
      if (!normalized) return;
      field.name = normalized;
      // call updateField to persist and update DOM (will also align id)
      this.updateField({id: fieldId, name: normalized});
      if (this.propertyPanel) this.propertyPanel.dataset.fieldId = normalized;
      return;
    }

    if (field.hasOwnProperty(propName)) {
      field[propName] = newVal;
    } else {
      field.config = field.config || {};
      field.config[propName] = newVal;
    }

    // call updateField to persist and update DOM
    this.updateField(field);
  },

  /**
   * Initialize drag and drop functionality
   */
  async initDragDrop() {
    const canvas = this.config.canvas;
    const dropZone = this.config.dropZone;

    if (!canvas && !dropZone) {
      console.warn('No canvas or dropZone provided for drag & drop');
      return;
    }

    const dropTarget = dropZone || canvas;

    // Prevent default drag behavior on drop target
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
      dropTarget.addEventListener(eventName, (e) => {
        e.preventDefault();
        e.stopPropagation();
      });
    });

    // Visual feedback for drag over
    dropTarget.addEventListener('dragenter', (e) => {
      dropTarget.classList.add('drag-over');
    });

    dropTarget.addEventListener('dragleave', (e) => {
      if (e.target === dropTarget) {
        dropTarget.classList.remove('drag-over');
      }
    });

    dropTarget.addEventListener('dragover', (e) => {
      e.dataTransfer.dropEffect = this.state.draggedField ? 'move' : 'copy';
    });

    // Handle drop event
    dropTarget.addEventListener('drop', (e) => {
      dropTarget.classList.remove('drag-over');

      try {
        const dataText = e.dataTransfer.getData('text/plain');

        const data = JSON.parse(dataText);
        const targetColumn = e.target.closest('.fb-row-col');

        if (data.isNew) {
          // New field from palette
          this.handleNewFieldDrop(data.type, e, targetColumn);
        } else if (data.fieldId) {
          // Existing field being reordered
          if (targetColumn) {
            this.handleFieldMoveToColumn(data.fieldId, e, targetColumn);
          } else {
            this.handleFieldReorder(data.fieldId, e);
          }
        }
      } catch (error) {
        console.error('Failed to handle drop:', error);
      }
    });
  },  /**
   * Handle drop of new field from palette
   *
   * @param {string} type Field type
   * @param {DragEvent} event Drop event
   */
  handleNewFieldDrop(type, event, targetColumn = null) {
    try {
      // Validate: fieldset cannot be dropped into row columns
      if (targetColumn && type === 'fieldset') {
        console.warn('Fieldset cannot be dropped into row columns');
        if (window.NotificationManager && typeof NotificationManager.show === 'function') {
          NotificationManager.show('Fieldset cannot be dropped into row columns', 'warning');
        }
        return;
      }

      // Enforce Root Constraint: Only fieldset allowed at root level (if not in a column)
      // Note: Drops inside fieldsets are handled by handleFieldsetFieldDrop, so this only catches root drops.
      if (!targetColumn && type !== 'fieldset') {
        console.warn('Only Fieldset allowed at root level');
        if (window.NotificationManager && typeof NotificationManager.show === 'function') {
          NotificationManager.show('Please place this field inside a Fieldset', 'warning');
        }
        return;
      }

      // Get field type configuration
      const fieldTypeConfig = window.FieldTypeRegistry?.[type];
      if (!fieldTypeConfig) {
        console.error(`Unknown field type: ${type}`);
        return;
      }

      // Create default configuration (only safe primitive values)
      const defaultConfig = fieldTypeConfig.defaultConfig || {};
      const id = this.generateFieldId(type);
      const config = {
        type: type,
        label: fieldTypeConfig.label || type,
        name: id,
        id: id,
        icon: fieldTypeConfig.icon || undefined,
        comment: fieldTypeConfig.comment || undefined,
        wrapper: 'div'
      };

      // Safely copy only serializable properties from defaultConfig
      for (const key in defaultConfig) {
        const value = defaultConfig[key];
        // Skip functions and complex objects
        if (typeof value !== 'function' && typeof value !== 'object') {
          config[key] = value;
        } else if (Array.isArray(value)) {
          // Simple array copy (for options array)
          config[key] = value.slice();
        }
      }

      // Handle row creation (2-3 columns)
      if (defaultConfig.type === 'row' || config.type === 'row2' || config.type === 'row3' || config.type === 'row') {
        const rowConfig = {
          id: this.generateFieldId('row'),
          type: 'row',
          columns: Array.isArray(defaultConfig.columns) ? defaultConfig.columns.map(col => ({
            width: col.width || col.size || col.col || 100,
            fields: Array.isArray(col.fields) ? col.fields.slice() : []
          })) : []
        };

        const addedRow = this.addField(rowConfig);
        const rowElement = this.renderRow(addedRow, this.state.currentSchema?.fields || []);

        const canvas = this.config.canvas || this.config.dropZone;
        const dropZone = this.config.dropZone;
        if (!canvas || !rowElement) return;

        if (dropZone) {
          const placeholder = dropZone.querySelector('.drop-placeholder');
          if (placeholder) placeholder.style.display = 'none';
          dropZone.classList.remove('empty');
        }

        const insertBefore = this.getInsertionPoint(event, dropZone || canvas, '.fb-field, .fb-row');
        if (insertBefore) {
          (dropZone || canvas).insertBefore(rowElement, insertBefore);
        } else {
          (dropZone || canvas).appendChild(rowElement);
        }

        return;
      }

      // Create field element
      // Add to schema first so manager knows about the field
      const addedField = this.addField(config);
      const fieldElement = this.createField(type, config);

      // Get drop target
      const canvas = this.config.canvas || this.config.dropZone;
      const dropZone = this.config.dropZone;

      if (!canvas) {
        console.error('No canvas or dropZone available');
        return;
      }

      // Hide empty placeholder if exists
      if (dropZone) {
        const placeholder = dropZone.querySelector('.drop-placeholder');
        if (placeholder) {
          placeholder.style.display = 'none';
        }
        dropZone.classList.remove('empty');
      }

      // Find insertion point based on drop position
      if (targetColumn) {
        const insertBefore = this.getInsertionPoint(event, targetColumn, '.fb-field');
        if (insertBefore) {
          targetColumn.insertBefore(fieldElement, insertBefore);
        } else {
          targetColumn.appendChild(fieldElement);
        }
        this._removeFieldFromRows(addedField.id || config.id);
        this._syncRowColumnFromDOM(targetColumn);
      } else {
        const insertBefore = this.getInsertionPoint(event, dropZone || canvas, '.fb-field, .fb-row');
        if (insertBefore) {
          (dropZone || canvas).insertBefore(fieldElement, insertBefore);
        } else {
          (dropZone || canvas).appendChild(fieldElement);
        }
      }

      // Select the new field
      this.selectField(fieldElement, addedField || config);
    } catch (error) {
      console.error('Failed to add field:', error);
    }
  },

  /**
   * Handle field reordering
   *
   * @param {string} fieldId Field ID to reorder
   * @param {DragEvent} event Drop event
   */
  handleFieldReorder(fieldId, event) {
    const fieldElement = document.querySelector(`[data-fb-id="${fieldId}"]`);
    if (!fieldElement) return;

    const dropTarget = this.config.dropZone || this.config.canvas;
    if (!dropTarget) return;

    // Enforce Root Constraint: Only fieldset allowed at root level
    const fieldType = fieldElement.getAttribute('data-fb-type');
    if (fieldType !== 'fieldset') {
      console.warn('Only Fieldset allowed at root level');
      if (window.NotificationManager && typeof NotificationManager.show === 'function') {
        NotificationManager.show('Please place this field inside a Fieldset', 'warning');
      }
      return;
    }

    const originFsContent = fieldElement.closest('.fieldset-content');

    // Find insertion point
    const insertBefore = this.getInsertionPoint(event, dropTarget, '.fb-field, .fb-row');
    const safeInsertBefore = insertBefore && insertBefore.parentNode === dropTarget ? insertBefore : null;

    if (safeInsertBefore && safeInsertBefore !== fieldElement) {
      dropTarget.insertBefore(fieldElement, safeInsertBefore);
      this._removeFieldFromFieldsets(fieldId);
      this._removeFieldFromRows(fieldId);
      if (originFsContent) this._syncFieldsetFromDOM(originFsContent);
      this.triggerEvent('fieldReordered', {fieldId: fieldId});
    } else if (!safeInsertBefore) {
      dropTarget.appendChild(fieldElement);
      this._removeFieldFromFieldsets(fieldId);
      this._removeFieldFromRows(fieldId);
      if (originFsContent) this._syncFieldsetFromDOM(originFsContent);
      this.triggerEvent('fieldReordered', {fieldId: fieldId});
    }
  },

  /**
   * Get insertion point for dropped field
   *
   * @param {DragEvent} event Drop event
   * @param {HTMLElement} container Container element
   * @returns {HTMLElement|null} Element to insert before, or null for append
   */
  getInsertionPoint(event, container, selector = '.fb-field') {
    const fields = [...container.querySelectorAll(selector)];
    const mouseY = event.clientY;

    for (let field of fields) {
      const rect = field.getBoundingClientRect();
      const midpoint = rect.top + rect.height / 2;

      if (mouseY < midpoint) {
        return field;
      }
    }

    return null;
  },

  handleFieldMoveToColumn(fieldId, event, targetColumn) {
    const fieldElement = document.querySelector(`[data-fb-id="${fieldId}"]`);
    if (!fieldElement || !targetColumn) return;

    // Validate: fieldset cannot be moved into row columns
    const fieldType = fieldElement.getAttribute('data-fb-type');
    if (fieldType === 'fieldset') {
      console.warn('Fieldset cannot be moved into row columns');
      if (window.NotificationManager && typeof NotificationManager.show === 'function') {
        NotificationManager.show('Fieldset cannot be moved into row columns', 'warning');
      }
      return;
    }

    // If this field was previously tracked as a direct child of a fieldset, remove that mapping
    this._removeFieldFromFieldsets(fieldId);

    const rowElement = targetColumn.closest('.fb-row');
    if (!rowElement) return;

    const insertBefore = this.getInsertionPoint(event, targetColumn, '.fb-field');
    if (insertBefore && insertBefore !== fieldElement) {
      targetColumn.insertBefore(fieldElement, insertBefore);
    } else if (!insertBefore) {
      targetColumn.appendChild(fieldElement);
    }

    const fsContent = targetColumn.closest('.fb-fieldset')?.querySelector('.fieldset-content');
    if (fsContent) this._syncFieldsetFromDOM(fsContent);
    this._removeFieldFromRows(fieldId);
    this._syncRowColumnFromDOM(targetColumn);

    this.triggerEvent('fieldReordered', {fieldId: fieldId});
  },

  _removeFieldFromRows(fieldId) {
    const schema = this.getSchema();
    (schema.fields || []).forEach(field => {
      if (field.type === 'row' && Array.isArray(field.columns)) {
        field.columns.forEach(col => {
          if (col.fieldId === fieldId) {
            delete col.fieldId;
          }
          if (Array.isArray(col.fields)) {
            col.fields = col.fields.filter(id => id !== fieldId);
          }
        });
      }
    });
  },

  _sanitizeFieldName(name) {
    return String(name || '')
      .trim()
      .replace(/\s+/g, '_')
      .replace(/[^a-zA-Z0-9_-]/g, '');
  },

  _ensureUniqueFieldId(desiredId, excludeId = null) {
    if (!desiredId) return desiredId;
    const schema = this.getSchema();
    const exists = (id) => (schema.fields || []).some(f => f.id === id && f.id !== excludeId);
    if (!exists(desiredId)) return desiredId;
    let i = 1;
    let nextId = `${desiredId}_${i}`;
    while (exists(nextId)) {
      i += 1;
      nextId = `${desiredId}_${i}`;
    }
    return nextId;
  },

  _applyFieldIdentityForNewField(field) {
    const base = this._sanitizeFieldName(field.name || field.id || '') || field.id || this.generateFieldId(field.type);
    const uniqueId = this._ensureUniqueFieldId(base);
    field.id = uniqueId;
    field.name = uniqueId;
    return field;
  },

  _applyFieldIdentityForExistingField(field, oldId) {
    const base = this._sanitizeFieldName(field.name || field.id || '') || oldId;
    const uniqueId = this._ensureUniqueFieldId(base, oldId);
    if (uniqueId && uniqueId !== oldId) {
      this._renameFieldId(oldId, uniqueId);
    }
    field.id = uniqueId || oldId;
    field.name = field.id;
    return field;
  },

  _renameFieldId(oldId, newId) {
    if (!oldId || !newId || oldId === newId) return;
    const schema = this.getSchema();

    // Update main fields list
    (schema.fields || []).forEach(field => {
      if (field.id === oldId) {
        field.id = newId;
        field.name = newId;
      }
    });

    // Update fieldset child references
    (schema.fields || []).forEach(field => {
      if (field.type === 'fieldset' && Array.isArray(field.fields)) {
        field.fields = field.fields.map(id => (id === oldId ? newId : id));
      }
    });

    // Update row column references
    (schema.fields || []).forEach(field => {
      if (field.type === 'row' && Array.isArray(field.columns)) {
        field.columns.forEach(col => {
          if (col.fieldId === oldId) col.fieldId = newId;
          if (Array.isArray(col.fields)) {
            col.fields = col.fields.map(id => (id === oldId ? newId : id));
          }
        });
      }
    });

    // Update DOM attributes
    try {
      const nodes = document.querySelectorAll(`[data-fb-id="${oldId}"]`);
      nodes.forEach(node => {
        node.setAttribute('data-fb-id', newId);

        // update input element if present
        const inputEl = node._fbInputElement || node.querySelector('input, textarea, select');
        if (inputEl) {
          inputEl.name = newId;
          if (inputEl.id) inputEl.id = newId;
          inputEl.setAttribute('data-fb-id', newId);
          const label = node.querySelector('label');
          if (label && label.htmlFor) label.htmlFor = newId;
        }

        // handle radio groups
        const radioInputs = node._fbInputs || node.querySelectorAll('input[type="radio"]');
        if (radioInputs && radioInputs.length) {
          radioInputs.forEach((radioEl, index) => {
            const newRadioId = `${newId}-${index}`;
            radioEl.id = newRadioId;
            radioEl.name = newId;
            radioEl.setAttribute('data-fb-id', newId);
            const label = radioEl.nextElementSibling;
            if (label && label.tagName === 'LABEL') label.htmlFor = newRadioId;
          });
        }
      });
    } catch (e) {}

    if (this.propertyPanel && this.propertyPanel.dataset.fieldId === oldId) {
      this.propertyPanel.dataset.fieldId = newId;
    }
    if (this.state.selectedField && this.state.selectedField.fieldId === oldId) {
      this.state.selectedField.fieldId = newId;
    }
  },

  /**
   * Remove a field id from any fieldset.fields arrays in the schema
   * @param {string} fieldId
   */
  _removeFieldFromFieldsets(fieldId) {
    const schema = this.getSchema();
    (schema.fields || []).forEach(field => {
      if (field.type === 'fieldset' && Array.isArray(field.fields)) {
        field.fields = field.fields.filter(id => id !== fieldId);
      }
    });
  },

  /**
   * Sync a fieldset's fields array from the DOM order inside its contentElement
   * @param {HTMLElement} contentElement - the .fieldset-content element
   */
  _syncFieldsetFromDOM(contentElement) {
    if (!contentElement) return;
    const fieldsetEl = contentElement.closest('.fb-fieldset');
    if (!fieldsetEl) return;
    const fieldsetId = fieldsetEl.getAttribute('data-fb-id');
    if (!fieldsetId) return;

    const schema = this.getSchema();
    const fsCfg = (schema.fields || []).find(f => f.id === fieldsetId && f.type === 'fieldset');
    if (!fsCfg) return;

    const orderedChildIds = [];
    Array.from(contentElement.children || []).forEach(child => {
      if (child.classList.contains('fb-row')) {
        const rowId = child.getAttribute('data-row-id') || child.getAttribute('data-fb-id');
        if (rowId) orderedChildIds.push(rowId);
      } else if (child.classList.contains('fb-field')) {
        const fid = child.getAttribute('data-fb-id');
        if (fid) orderedChildIds.push(fid);
      }
    });

    fsCfg.fields = orderedChildIds;
  },

  /**
   * Add a field id to a fieldset's fields array in the schema
   * @param {string} fieldId
   * @param {string} fieldsetId
   */
  _addFieldToFieldset(fieldId, fieldsetId) {
    if (!fieldsetId) return;
    const schema = this.getSchema();
    const fs = (schema.fields || []).find(f => f.id === fieldsetId && f.type === 'fieldset');
    if (!fs) return;
    fs.fields = fs.fields || [];
    if (!fs.fields.includes(fieldId)) fs.fields.push(fieldId);
  },

  _syncRowColumnFromDOM(targetColumn) {
    const rowId = targetColumn.getAttribute('data-row-id');
    const colIndex = parseInt(targetColumn.getAttribute('data-col-index'), 10);
    if (!rowId || Number.isNaN(colIndex)) return;

    const schema = this.getSchema();
    const rowConfig = (schema.fields || []).find(f => f.id === rowId && f.type === 'row');
    if (!rowConfig) return;

    rowConfig.columns = rowConfig.columns || [];
    if (!rowConfig.columns[colIndex]) {
      rowConfig.columns[colIndex] = {width: 100, fields: []};
    }

    rowConfig.columns[colIndex].fields = Array.from(targetColumn.querySelectorAll('.fb-field'))
      .map(el => el.getAttribute('data-fb-id'))
      .filter(Boolean);
  },

  /**
   * Handle field drag start
   *
   * @param {DragEvent} event Drag event
   * @param {HTMLElement} element Field element
   * @param {Object} config Field configuration
   */
  handleFieldDragStart(event, element, config) {
    this.state.draggedField = {element, config};

    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', JSON.stringify({
      isNew: false,
      fieldId: config.id
    }));

    element.classList.add('dragging');
  },

  /**
   * Register with ElementManager
   */
  registerWithElementManager() {
    if (window.ElementManager && window.ElementManager.register) {
      window.ElementManager.register('FormBuilderManager', this);
    }
  },

  /**
   * Setup event listeners
   */
  setupEventListeners() {
    // Listen for keyboard shortcuts
    document.addEventListener('keydown', (e) => {
      if (e.ctrlKey || e.metaKey) {
        switch (e.key) {
          case 's':
            e.preventDefault();
            this.saveSchema();
            break;
        }
      }
    });

    // Delegate clicks for delete/edit actions on fields (global handler)
    document.addEventListener('click', (e) => {
      const del = e.target.closest('[data-fb-action="delete"]');
      if (del) {
        const wrapper = del.closest('[data-fb-id]');
        const id = wrapper && wrapper.getAttribute('data-fb-id');
        if (id) {
          this.removeField(id);
          if (wrapper && wrapper.parentNode) wrapper.parentNode.removeChild(wrapper);
        }
        return;
      }

      const edit = e.target.closest('[data-fb-action="edit"]');
      if (edit) {
        const wrapper = edit.closest('[data-fb-id]');
        const id = wrapper && wrapper.getAttribute('data-fb-id');
        if (id) this.openPropertyPanel(id);
      }
    });
  },

  /**
   * Apply form settings to form element
   *
   * @param {HTMLElement} formElement Form element
   * @param {Object} settings Form settings
   */
  applyFormSettings(formElement, settings) {
    if (settings.submission) {
      if (settings.submission.method) {
        formElement.method = settings.submission.method;
      }
      if (settings.submission.endpoint) {
        formElement.action = settings.submission.endpoint;
      }
    }
  },

  /**
   * Make section collapsible
   *
   * @param {HTMLElement} sectionElement Section element
   * @param {boolean} collapsed Initial collapsed state
   */
  makeCollapsible(sectionElement, collapsed = false) {
    const header = sectionElement.querySelector('.formbuilder-section-title');
    const content = sectionElement.querySelector('.formbuilder-section-content');

    if (!header || !content) return;

    header.classList.add('collapsible');
    header.addEventListener('click', () => {
      const isCollapsed = content.style.display === 'none';
      content.style.display = isCollapsed ? 'block' : 'none';
      header.classList.toggle('collapsed', !isCollapsed);
    });

    // Set initial state
    if (collapsed) {
      content.style.display = 'none';
      header.classList.add('collapsed');
    }
  },

  /**
   * Save current schema
   */
  async saveSchema() {
    // Allow callers to pass through name/description/status by calling
    // FormBuilderManager.saveSchema(name, description, status)
    if (!this.state.currentSchema) return {success: false, message: 'No schema to save'};

    // Collect parameters from arguments or DOM or stored state
    const args = Array.from(arguments);
    let name = args[0];
    if (name == null) {
      name = this.state.schemaName || (document.getElementById('form-name') && document.getElementById('form-name').value) || '';
    }

    let description = args[1];
    if (description == null) {
      description = this.state.schemaDescription || (document.getElementById('form-description') && document.getElementById('form-description').value) || '';
    }

    const status = args[2] || 'draft';

    // Basic validation: require a name (match UI behaviour in modules/formbuilder/script.js)
    if (!name || String(name).trim() === '') {
      if (window.NotificationManager && typeof NotificationManager.show === 'function') {
        NotificationManager.show('Please enter a form name', 'warning');
      } else {
        console.warn('Please enter a form name');
      }
      return {success: false, message: 'Missing form name'};
    }

    try {
      const result = await this.saveSchemaToServer(name, description, status);

      if (result && result.success) {
        // Persist friendly name/description locally
        this.state.schemaName = name;
        this.state.schemaDescription = description;

        // Notify user
        if (window.NotificationManager && typeof NotificationManager.show === 'function') {
          NotificationManager.show(result.data?.message || 'Form saved', 'success');
        }

        // If a new ID was returned and we don't have it yet, update URL so subsequent saves edit
        if (result.data && result.data.id) {
          this.state.schemaId = result.data.id;
          try {
            const currentParams = new URLSearchParams(window.location.search);
            if (!currentParams.get('id')) {
              window.history.replaceState({}, '', `${window.location.pathname}?id=${result.data.id}`);
            }
          } catch (e) {
            // ignore history errors
          }
        }
      } else {
        if (window.NotificationManager && typeof NotificationManager.show === 'function') {
          NotificationManager.show(result.message || 'Save failed', 'error');
        } else {
          console.error('Save failed', result);
        }
      }

      return result;
    } catch (error) {
      console.error('Failed to save schema:', error);
      if (window.NotificationManager && typeof NotificationManager.show === 'function') {
        NotificationManager.show('An error occurred while saving', 'error');
      }
      return {success: false, message: error.message};
    }
  },

  /**
   * Show a preview of the current form schema in a dialog/modal.
   * Uses DialogManager.createDialog when available, otherwise falls back to Modal.
   * @param {Object} options Optional: { title, template, customClass }
   * @returns {HTMLElement|Modal|null} dialog element or Modal instance
   */
  showPreview(options = {}) {
    try {
      const schema = this.getSchema();

      // Build preview container and render form into it
      const previewContainer = document.createElement('div');
      previewContainer.className = 'fb-preview-container';
      // Render a fresh form instance (renderForm will create DOM nodes)
      // Use clean: true to generate production-ready HTML without builder artifacts
      this.renderForm(schema, previewContainer, {clean: true});

      // No need to manually remove artifacts as renderForm(..., {clean: true}) handles it.

      const title = options.title || (schema.metadata && schema.metadata.title) || 'Form Preview';

      // Use Modal exclusively for preview (no DialogManager fallback)
      if (window.Modal) {
        const modal = new Modal({
          title,
          content: '',
          className: options.customClass || 'fb-preview-modal'
        });
        modal.setContent(previewContainer);
        modal.show();
        return modal;
      }

      console.error('Modal component not available. Cannot show preview.');
      return null;
    } catch (error) {
      console.error('FormBuilderManager.showPreview failed:', error);
      return null;
    }
  },

  /**
   * Show a pretty JSON view of the current schema with copy support.
   */
  showSchemaJson(options = {}) {
    try {
      const schema = this.getSchema();
      const pretty = JSON.stringify(schema, null, 2);

      const wrapper = document.createElement('div');
      wrapper.className = 'fb-schema-json';

      const toolbar = document.createElement('div');
      toolbar.className = 'fb-schema-json-toolbar';

      const copyBtn = document.createElement('button');
      copyBtn.type = 'button';
      copyBtn.className = 'btn icon-copy';
      copyBtn.textContent = options.copyLabel || 'Copy';

      const pre = document.createElement('pre');
      pre.className = 'fb-schema-json-pre';
      pre.textContent = pretty;

      copyBtn.addEventListener('click', async () => {
        try {
          if (navigator.clipboard && navigator.clipboard.writeText) {
            await navigator.clipboard.writeText(pretty);
          } else {
            const range = document.createRange();
            range.selectNodeContents(pre);
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
            document.execCommand('copy');
            selection.removeAllRanges();
          }
          if (window.NotificationManager && typeof NotificationManager.show === 'function') {
            NotificationManager.show('Schema copied', 'success');
          }
        } catch (err) {
          if (window.NotificationManager && typeof NotificationManager.show === 'function') {
            NotificationManager.show('Copy failed', 'error');
          }
        }
      });

      toolbar.appendChild(copyBtn);
      wrapper.appendChild(toolbar);
      wrapper.appendChild(pre);

      // Inline styles to keep readable without extra CSS file
      wrapper.style.display = 'flex';
      wrapper.style.flexDirection = 'column';
      wrapper.style.gap = '8px';
      pre.style.background = '#0f172a';
      pre.style.color = '#e2e8f0';
      pre.style.padding = '12px';
      pre.style.borderRadius = '8px';
      pre.style.maxHeight = '60vh';
      pre.style.overflow = 'auto';
      pre.style.fontSize = '12px';
      pre.style.lineHeight = '1.4';
      pre.style.whiteSpace = 'pre';

      const title = options.title || 'Schema JSON';

      if (window.Modal) {
        const modal = new Modal({
          title,
          content: '',
          className: options.customClass || 'fb-schema-json-modal'
        });
        modal.setContent(wrapper);
        modal.show();
        return modal;
      }

      console.error('Modal component not available. Cannot show schema JSON.');
      return null;
    } catch (error) {
      console.error('FormBuilderManager.showSchemaJson failed:', error);
      return null;
    }
  },

  /**
   * Trigger custom event
   *
   * @param {string} eventName Event name
   * @param {Object} detail Event detail
   */
  triggerEvent(eventName, detail = {}) {
    const event = new CustomEvent(`formbuilder:${eventName}`, {detail});
    document.dispatchEvent(event);
  },


  /**
   * Load schema from server or object
   *
   * @param {Object|number} schema Schema object or ID
   */
  async loadSchema(schema) {
    if (!schema) return;

    let schemaData = schema.schema_json;

    // If numeric, fetch from API
    if (typeof schema === 'number' || (typeof schema === 'string' && !isNaN(schema))) {
      try {
        const requestOptions = Now.applyRequestLanguage({method: 'GET'});
        const response = await fetch(`${this.config.apiEndpoint}/schema?id=${schema}`, requestOptions);
        const result = await response.json();
        if (result.success) {
          schemaData = result.data;
        } else {
          throw new Error(result.message || 'Failed to load schema');
        }
      } catch (error) {
        console.error('Failed to load schema:', error);
        return false;
      }
    }

    // Handle direct schema object (has fields array) or wrapped object (has schema_json)
    if (schemaData.fields && Array.isArray(schemaData.fields)) {
      this.state.currentSchema = schemaData;
      this.state.schemaId = schemaData.id || schemaData.metadata?.id || null;
      this.state.schemaName = schemaData.name || schemaData.metadata?.title || '';
    } else {
      // Parse schema_json if string
      if (typeof schemaData.schema_json === 'string') {
        try {
          schemaData.schema_json = JSON.parse(schemaData.schema_json);
        } catch (e) {
          console.error('Failed to parse schema_json string:', e);
        }
      }
      this.state.currentSchema = schemaData.schema_json;
      this.state.schemaId = schemaData.id || null;
      this.state.schemaName = schemaData.name || '';
    }

    // Ensure state.currentSchema exists before proceeding
    if (!this.state.currentSchema) {
      console.error('Invalid schema data loaded:', schemaData);
      return false;
    }

    // Ensure at least one fieldset exists if fields array is empty
    if (!this.state.currentSchema.fields || this.state.currentSchema.fields.length === 0) {
      if (!this.state.currentSchema.fields) this.state.currentSchema.fields = [];

      const defaultFieldset = {
        id: this.generateFieldId('fieldset'),
        type: 'fieldset',
        title: 'Section 1',
        fields: []
      };

      this.state.currentSchema.fields.push(defaultFieldset);
    }

    this.triggerEvent('schemaLoaded', {schema: this.state.currentSchema});

    // Render the loaded schema to the canvas
    if (this.config.canvas) {
      this.renderCanvas();
    }

    return true;
  },

  /**
   * Render the current schema to the canvas
   */
  renderCanvas() {
    const container = this.config.dropZone || this.config.canvas;
    if (!container) return;

    this.clearCanvas(container);

    const fields = this.state.currentSchema?.fields || [];
    if (fields.length === 0) return;

    const childIds = this._getAllChildIds(fields);
    const topLevelFields = fields.filter(f => !childIds.has(f.id));

    if (topLevelFields.length > 0) {
      container.classList.remove('empty');
      const placeholder = container.querySelector('.drop-placeholder');
      if (placeholder) placeholder.style.display = 'none';
    }

    topLevelFields.forEach(field => {
      try {
        if (field.type === 'row' || field.type === 'row2' || field.type === 'row3') {
          const rowEl = this.renderRow(field, fields);
          if (rowEl) container.appendChild(rowEl);
        } else if (field.type === 'fieldset') {
          const fsEl = this.createField('fieldset', field);
          this.renderFieldsetContent(fsEl, field, fields);
          container.appendChild(fsEl);
        } else {
          // Standard field
          const fieldEl = this.createField(field.type, field);
          container.appendChild(fieldEl);
          // We don't auto-select every field during load, it's annoying.
          // But we need to ensure they are interactive. createField does that.
        }
      } catch (e) {
        console.error('Error rendering field:', field, e);
      }
    });
  },

  /**
   * Helper to get all IDs that are children of other fields
   */
  _getAllChildIds(fields) {
    const ids = new Set();
    fields.forEach(f => {
      if (f.type === 'row' && f.columns) {
        f.columns.forEach(c => {
          if (c.fieldId) ids.add(c.fieldId);
          if (c.fields) c.fields.forEach(id => ids.add(id));
        });
      }
      if (f.type === 'fieldset' && f.fields) {
        f.fields.forEach(id => ids.add(id));
      }
    });
    return ids;
  },

  /**
   * Render content of a fieldset
   */
  renderFieldsetContent(fieldsetEl, fieldsetCfg, allFields) {
    const contentEl = fieldsetEl.querySelector('.fieldset-content');
    if (!contentEl || !fieldsetCfg.fields) return;

    fieldsetCfg.fields.forEach(childId => {
      const child = allFields.find(f => f.id === childId);
      if (!child) return;

      try {
        if (child.type === 'row' || child.type === 'row2' || child.type === 'row3') {
          const rowEl = this.renderRow(child, allFields);
          if (rowEl) contentEl.appendChild(rowEl);
        } else {
          // We don't support nested fieldsets inside fieldsets (as per validation rules)
          // But standard fields are fine
          const fieldEl = this.createField(child.type, child);
          contentEl.appendChild(fieldEl);
        }
      } catch (e) {
        console.error('Error rendering child field:', childId, e);
      }
    });
  },

  /**
   * Sync schema structure (order and nesting) from DOM elements
   * Updates existing fields in state to match the visual layout
   */
  syncSchemaFromDOM() {
    const rootContainer = this.config.dropZone || this.config.canvas;
    if (!rootContainer) return;

    // 1. Create a map of existing fields for lookup
    const fieldMap = new Map();
    if (this.state.currentSchema && Array.isArray(this.state.currentSchema.fields)) {
      this.state.currentSchema.fields.forEach(f => {
        if (f && f.id) fieldMap.set(f.id, f);
      });
    }

    // 2. Traverse DOM to rebuild flat fields list and update nesting references
    const orderedFields = this._traverseDOM(rootContainer, fieldMap);

    // 3. Update schema
    if (!this.state.currentSchema) {
      this.state.currentSchema = this.createEmptySchema();
    }
    this.state.currentSchema.fields = orderedFields;
  },

  /**
   * Recursive DOM traversal to gather fields and update container references
   *
   * @param {HTMLElement} container - The container to traverse
   * @param {Map} fieldMap - Map of existing field configs
   * @returns {Array} - Flat array of field configurations found in this subtree
   */
  _traverseDOM(container, fieldMap) {
    const result = [];

    // Iterate over direct children that have a field ID (this covers fields, rows, fieldsets, dividers)
    const children = Array.from(container.children).filter(el =>
      el.hasAttribute('data-fb-id')
    );

    children.forEach(el => {
      const id = el.getAttribute('data-fb-id');
      if (!id || !fieldMap.has(id)) return;

      const fieldConfig = fieldMap.get(id);
      result.push(fieldConfig);

      // Handle containers
      if (fieldConfig.type === 'fieldset') {
        const contentEl = el.querySelector('.fieldset-content');
        if (contentEl) {
          // Identify direct children IDs for the fieldset config (structure only)
          const directChildren = Array.from(contentEl.children).filter(child =>
            child.hasAttribute('data-fb-id')
          );
          fieldConfig.fields = directChildren.map(child => child.getAttribute('data-fb-id'));

          // Recursively get all descendants for the flat schema list
          const childFields = this._traverseDOM(contentEl, fieldMap);
          result.push(...childFields);
        } else {
          fieldConfig.fields = [];
        }
      } else if (fieldConfig.type === 'row') {
        const colElements = Array.from(el.querySelectorAll('.fb-row-col'));
        // Preserve existing column widths if possible
        const oldColumns = fieldConfig.columns || [];

        fieldConfig.columns = colElements.map((colEl, index) => {
          const colResult = this._traverseDOM(colEl, fieldMap);
          // Add child fields to the flat result list
          result.push(...colResult);

          // Try to keep existing width config, or fallback
          const existingCol = oldColumns[index] || {};
          return {
            width: existingCol.width || existingCol.size || 100, // preserve or default
            fields: Array.from(colEl.children).filter(c => c.hasAttribute('data-fb-id')).map(c => c.getAttribute('data-fb-id'))
          };
        });
      }
    });

    return result;
  },

  /**
   * Get current schema
   *
   * @returns {Object} Current schema
   */
  getSchema() {
    // Always sync from DOM before returning to ensure WYSIWYG
    this.syncSchemaFromDOM();
    return this.state.currentSchema || this.createEmptySchema();
  },

  /**
   * Create empty schema structure
   *
   * @returns {Object} Empty schema
   */
  createEmptySchema() {
    return {
      version: '1.0',
      metadata: {
        title: '',
        description: ''
      },
      fields: [
        {
          id: this.generateFieldId('fieldset'),
          type: 'fieldset',
          title: 'Section 1',
          fields: []
        }
      ],
      settings: {
        submission: {method: 'POST'}
      }
    };
  },

  /**
   * Add field to schema
   *
   * @param {Object} fieldConfig Field configuration
   * @param {number} index Position to insert (-1 for end)
   */
  addField(fieldConfig, index = -1) {
    if (!this.state.currentSchema) {
      this.state.currentSchema = this.createEmptySchema();
    }

    // Ensure field has required properties
    const field = {
      id: fieldConfig.id || this.generateFieldId(fieldConfig.type),
      type: fieldConfig.type,
      label: fieldConfig.label || this.generateLabel(fieldConfig.type),
      ...fieldConfig
    };

    // Ensure name/id are aligned for submissions
    this._applyFieldIdentityForNewField(field);

    if (index === -1 || index >= this.state.currentSchema.fields.length) {
      this.state.currentSchema.fields.push(field);
    } else {
      this.state.currentSchema.fields.splice(index, 0, field);
    }

    this.triggerEvent('fieldAdded', {field, index});
    return field;
  },

  /**
   * Remove field from schema
   *
   * @param {string} fieldId Field ID to remove
   */
  removeField(fieldId) {
    if (!this.state.currentSchema?.fields) return false;

    const index = this.state.currentSchema.fields.findIndex(f => f.id === fieldId);
    if (index === -1) return false;

    const removed = this.state.currentSchema.fields.splice(index, 1)[0];

    this.triggerEvent('fieldRemoved', {field: removed, fieldId});

    // Clear selection if removed field was selected
    if (this.state.selectedField?.fieldId === fieldId) {
      this.state.selectedField = null;
    }

    // Also remove DOM element if present
    try {
      const dom = document.querySelector(`[data-fb-id="${fieldId}"]`);
      if (dom && dom.parentNode) dom.parentNode.removeChild(dom);
    } catch (e) {
      // ignore
    }

    // Clear property panel if showing this field
    try {
      if (this.propertyPanel && this.propertyPanel.dataset.fieldId === fieldId) {
        this.propertyPanel.innerHTML = '';
        delete this.propertyPanel.dataset.fieldId;
      }
    } catch (e) {}

    return true;
  },

  /**
   * Update field in schema
   *
   * @param {Object} fieldConfig Updated field configuration
   */
  updateField(fieldConfig) {
    if (!this.state.currentSchema?.fields || !fieldConfig.id) return false;

    const index = this.state.currentSchema.fields.findIndex(f => f.id === fieldConfig.id);
    if (index === -1) return false;

    const current = this.state.currentSchema.fields[index];
    const merged = {
      ...current,
      ...fieldConfig
    };

    // Ensure name/id are aligned (rename if needed)
    this._applyFieldIdentityForExistingField(merged, current.id);

    const newIndex = this.state.currentSchema.fields.findIndex(f => f.id === merged.id);
    if (newIndex !== -1) {
      this.state.currentSchema.fields[newIndex] = {
        ...this.state.currentSchema.fields[newIndex],
        ...merged
      };
    } else {
      this.state.currentSchema.fields[index] = merged;
    }

    this.triggerEvent('fieldUpdated', {field: this.state.currentSchema.fields[index]});

    // Update DOM representation if present
    try {
      const dom = document.querySelector(`[data-fb-id="${fieldConfig.id}"]`);
      if (dom) {
        // update label if exists
        const labelEl = dom.querySelector('label, .fb-field-label');
        const labelText = fieldConfig.label || this.state.currentSchema.fields[index].label;
        if (labelEl && labelText !== undefined) labelEl.textContent = labelText;

        // update input attributes if element stored
        const inputEl = dom._fbInputElement || dom.querySelector('input, textarea, select');
        if (inputEl) {
          if (fieldConfig.placeholder !== undefined) inputEl.placeholder = fieldConfig.placeholder;
          if (fieldConfig.maxLength !== undefined && inputEl.setAttribute) inputEl.setAttribute('maxlength', fieldConfig.maxLength);
          if (fieldConfig.min !== undefined && inputEl.setAttribute) inputEl.setAttribute('min', fieldConfig.min);
          if (fieldConfig.max !== undefined && inputEl.setAttribute) inputEl.setAttribute('max', fieldConfig.max);
          if (fieldConfig.required !== undefined) inputEl.required = !!fieldConfig.required;
          if (fieldConfig.readonly !== undefined) inputEl.readOnly = !!fieldConfig.readonly;
          if (fieldConfig.disabled !== undefined) inputEl.disabled = !!fieldConfig.disabled;
          if (fieldConfig.name !== undefined) inputEl.name = fieldConfig.name;
          if (fieldConfig.id !== undefined) inputEl.id = fieldConfig.id;
          if (fieldConfig.value !== undefined) {
            try {inputEl.value = fieldConfig.value;} catch (e) {}
          }
        }
      }
    } catch (e) {
      // ignore DOM update errors
    }
    return true;
  },

  /**
   * Move field to new position
   *
   * @param {string} fieldId Field ID to move
   * @param {number} newIndex New position index
   */
  moveField(fieldId, newIndex) {
    if (!this.state.currentSchema?.fields) return false;

    const currentIndex = this.state.currentSchema.fields.findIndex(f => f.id === fieldId);
    if (currentIndex === -1) return false;

    const [field] = this.state.currentSchema.fields.splice(currentIndex, 1);
    this.state.currentSchema.fields.splice(newIndex, 0, field);

    this.triggerEvent('fieldMoved', {field, fromIndex: currentIndex, toIndex: newIndex});
    return true;
  },

  /**
   * Generate label from field type
   *
   * @param {string} type Field type
   * @returns {string} Generated label
   */
  generateLabel(type) {
    return type.charAt(0).toUpperCase() + type.slice(1) + ' Field';
  },

  /**
   * Initialize drop zone for form canvas
   *
   * @param {HTMLElement} dropZone Drop zone element
   */
  initDropZone(dropZone) {
    if (!dropZone) return;

    dropZone.addEventListener('dragover', (e) => {
      e.preventDefault();
      e.dataTransfer.dropEffect = 'copy';
      dropZone.classList.add('drag-over');
    });

    dropZone.addEventListener('dragleave', (e) => {
      if (!dropZone.contains(e.relatedTarget)) {
        dropZone.classList.remove('drag-over');
      }
    });

    dropZone.addEventListener('drop', (e) => {
      e.preventDefault();
      dropZone.classList.remove('drag-over');

      try {
        const data = JSON.parse(e.dataTransfer.getData('text/plain'));

        if (data.isNew) {
          // New field from palette
          const field = this.addField({type: data.type});
          this.renderFieldToCanvas(field, dropZone);
        } else if (data.id) {
          // Existing field being moved
          const targetIndex = this.getDropIndex(e, dropZone);
          this.moveField(data.id, targetIndex);
        }
      } catch (error) {
        console.error('Drop error:', error);
      }
    });
  },

  /**
   * Get drop index based on mouse position
   *
   * @param {DragEvent} e Drag event
   * @param {HTMLElement} container Container element
   * @returns {number} Drop index
   */
  getDropIndex(e, container) {
    const fields = container.querySelectorAll('.fb-field');
    let index = fields.length;

    for (let i = 0; i < fields.length; i++) {
      const rect = fields[i].getBoundingClientRect();
      if (e.clientY < rect.top + rect.height / 2) {
        index = i;
        break;
      }
    }

    return index;
  },

  /**
   * Render field to canvas
   *
   * @param {Object} fieldConfig Field configuration
   * @param {HTMLElement} container Container element
   */
  renderFieldToCanvas(fieldConfig, container) {
    const fieldElement = this.createField(fieldConfig.type, fieldConfig);

    // Remove empty placeholder if exists
    const placeholder = container.querySelector('.drop-placeholder');
    if (placeholder) {
      placeholder.remove();
      container.classList.remove('empty');
    }

    container.appendChild(fieldElement);
    this.selectField(fieldElement, fieldConfig);
  },

  /**
   * Clear canvas
   *
   * @param {HTMLElement} container Container element
   */
  clearCanvas(container) {
    if (!container) return;

    container.innerHTML = `
      <div class="drop-placeholder">
        <span class="icon-new"></span>
        <p>Drag & drop fields here to build your form</p>
      </div>
    `;
    container.classList.add('empty');
  },

  /**
   * Save schema to server
   *
   * @param {string} status Schema status (draft/active)
   * @returns {Object} Save result
   */
  async saveSchemaToServer(name, description, status = 'draft') {
    const schema = this.getSchema();

    try {
      const requestOptions = Now.applyRequestLanguage({
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
          id: this.state.schemaId || null,
          name: name,
          description: description,
          status: status,
          schema_json: schema
        })
      });

      const response = await fetch(`${this.config.apiEndpoint}/schema`, requestOptions);

      const result = await response.json();

      if (result.success) {
        this.state.schemaId = result.data.id;
        this.triggerEvent('schemaSaved', {id: result.data.id});
      }

      return result;
    } catch (error) {
      console.error('Failed to save schema:', error);
      return {success: false, message: error.message};
    }
  },

  /**
   * Evaluate conditional logic for a field
   *
   * @param {Object} field Field with conditional config
   * @param {Object} formData Current form data
   * @returns {boolean} Whether field should be visible
   */
  evaluateConditional(field, formData) {
    if (!field.conditional) return true;

    const {show, hide} = field.conditional;

    if (show) {
      return this.checkCondition(show, formData);
    }

    if (hide) {
      return !this.checkCondition(hide, formData);
    }

    return true;
  },

  /**
   * Check a single condition
   *
   * @param {Object} condition Condition object
   * @param {Object} formData Form data
   * @returns {boolean} Condition result
   */
  checkCondition(condition, formData) {
    const {field, operator, value} = condition;
    const actualValue = formData[field];

    switch (operator) {
      case 'equals':
      case '==':
        return actualValue == value;
      case 'notEquals':
      case '!=':
        return actualValue != value;
      case 'isEmpty':
        return !actualValue || actualValue === '';
      case 'isNotEmpty':
        return actualValue && actualValue !== '';
      case 'contains':
        return String(actualValue).includes(value);
      case 'greaterThan':
      case '>':
        return Number(actualValue) > Number(value);
      case 'lessThan':
      case '<':
        return Number(actualValue) < Number(value);
      case 'in':
        return Array.isArray(value) && value.includes(actualValue);
      default:
        return true;
    }
  },

  /**
   * Apply conditional logic to form
   *
   * @param {HTMLElement} formElement Form element
   * @param {Object} formData Current form data
   */
  applyConditionalLogic(formElement, formData) {
    const fields = formElement.querySelectorAll('[data-conditional]');

    fields.forEach(fieldWrapper => {
      try {
        const condition = JSON.parse(fieldWrapper.dataset.conditional);
        const isVisible = this.evaluateConditional({conditional: condition}, formData);

        fieldWrapper.style.display = isVisible ? '' : 'none';

        // Disable hidden field inputs
        const inputs = fieldWrapper.querySelectorAll('input, select, textarea');
        inputs.forEach(input => {
          input.disabled = !isVisible;
        });
      } catch (e) {
        console.error('Failed to evaluate conditional:', e);
      }
    });
  },

  /**
   * Export schema as JSON file
   *
   * @param {string} filename Filename
   */
  exportSchema(filename = 'schema.json') {
    const schema = this.getSchema();
    const json = JSON.stringify(schema, null, 2);
    const blob = new Blob([json], {type: 'application/json'});
    const url = URL.createObjectURL(blob);

    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  },

  /**
   * Import schema from file
   *
   * @param {File} file JSON file
   * @returns {Promise<boolean>} Success status
   */
  async importSchema(file) {
    return new Promise((resolve, reject) => {
      const reader = new FileReader();

      reader.onload = (e) => {
        try {
          const schema = JSON.parse(e.target.result);
          this.state.currentSchema = schema;
          this.state.schemaId = null;
          this.triggerEvent('schemaImported', {schema});
          resolve(true);
        } catch (error) {
          reject(new Error('Invalid JSON file'));
        }
      };

      reader.onerror = () => reject(new Error('Failed to read file'));
      reader.readAsText(file);
    });
  }
};

window.FormBuilderManager = FormBuilderManager;
