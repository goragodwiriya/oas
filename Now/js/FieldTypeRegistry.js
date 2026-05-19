/**
 * Field Type Registry
 *
 * Defines all available field types for the FormBuilder
 * Maps field types to ElementFactory classes and provides configuration
 *
 * @filesource Now/js/FieldTypeRegistry.js
 * @link https://www.kotchasan.com/
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

const FieldTypeRegistry = {
  // Layout Fields
  fieldset: {
    factory: null, // Special handling
    icon: 'icon-border_outer',
    label: 'Fieldset',
    category: 'layout',
    description: 'Group fields into fieldset',
    defaultConfig: {
      icon: 'icon-border_outer',
      title: 'Fieldset Title',
      collapsible: false,
      collapsed: false
    },
    properties: [
      {name: 'title', type: 'text', label: 'Fieldset Title', required: true},
      {name: 'description', type: 'textarea', label: 'Fieldset Description'},
      {name: 'collapsible', type: 'checkbox', label: 'Collapsible'},
      {name: 'collapsed', type: 'checkbox', label: 'Initially Collapsed'}
    ]
  },

  divider: {
    factory: null, // Special handling
    icon: 'icon-minus',
    label: 'Divider',
    category: 'layout',
    description: 'Visual separator line',
    defaultConfig: {
      style: 'solid',
      thickness: 1,
      color: '#cccccc'
    },
    properties: [
      {name: 'style', type: 'select', label: 'Line Style', options: ['solid', 'dashed', 'dotted']},
      {name: 'thickness', type: 'number', label: 'Line Thickness (px)', min: 1, max: 10},
      {name: 'color', type: 'color', label: 'Line Color'}
    ]
  },

  row2: {
    factory: null,
    icon: 'icon-block',
    label: 'Row (2 Columns)',
    category: 'layout',
    description: 'Two-column row layout',
    defaultConfig: {
      type: 'row',
      columns: [
        {width: 50, fields: []},
        {width: 50, fields: []}
      ]
    },
    properties: [
      {name: 'columns', type: 'textarea', label: 'Columns (JSON)'}
    ]
  },

  row3: {
    factory: null,
    icon: 'icon-grid',
    label: 'Row (3 Columns)',
    category: 'layout',
    description: 'Three-column row layout',
    defaultConfig: {
      type: 'row',
      columns: [
        {width: 33, fields: []},
        {width: 33, fields: []},
        {width: 33, fields: []}
      ]
    },
    properties: [
      {name: 'columns', type: 'textarea', label: 'Columns (JSON)'}
    ]
  },
  // Basic Input Fields
  text: {
    factory: 'TextElementFactory',
    icon: 'icon-edit',
    label: 'Text Input',
    category: 'basic',
    description: 'Single line text input field',
    defaultConfig: {
      type: 'text',
      icon: 'icon-edit',
      placeholder: 'Enter text...',
      maxLength: 255,
      required: false
    },
    properties: [
      {name: 'label', type: 'text', label: 'Field Label', required: true},
      {name: 'icon', type: 'icon-select', label: 'Field Icon'},
      {name: 'placeholder', type: 'text', label: 'Placeholder Text'},
      {name: 'maxLength', type: 'number', label: 'Maximum Length', min: 1},
      {name: 'pattern', type: 'text', label: 'Validation Pattern (Regex)'},
      {name: 'required', type: 'checkbox', label: 'Required Field'},
      {name: 'readonly', type: 'checkbox', label: 'Read Only'},
      {name: 'disabled', type: 'checkbox', label: 'Disabled'}
    ]
  },

  email: {
    factory: 'TextElementFactory',
    icon: 'icon-email',
    label: 'Email Input',
    category: 'basic',
    description: 'Email address input with validation',
    defaultConfig: {
      type: 'email',
      icon: 'icon-email',
      placeholder: 'Enter email address...',
      validation: {email: true},
      required: false
    },
    properties: [
      {name: 'label', type: 'text', label: 'Field Label', required: true},
      {name: 'icon', type: 'icon-select', label: 'Field Icon'},
      {name: 'placeholder', type: 'text', label: 'Placeholder Text'},
      {name: 'required', type: 'checkbox', label: 'Required Field'},
      {name: 'readonly', type: 'checkbox', label: 'Read Only'},
      {name: 'disabled', type: 'checkbox', label: 'Disabled'}
    ]
  },

  password: {
    factory: 'PasswordElementFactory',
    icon: 'icon-password',
    label: 'Password Input',
    category: 'basic',
    description: 'Password input field with masking',
    defaultConfig: {
      type: 'password',
      icon: 'icon-password',
      placeholder: 'Enter password...',
      required: false
    },
    properties: [
      {name: 'label', type: 'text', label: 'Field Label', required: true},
      {name: 'icon', type: 'icon-select', label: 'Field Icon'},
      {name: 'placeholder', type: 'text', label: 'Placeholder Text'},
      {name: 'minLength', type: 'number', label: 'Minimum Length', min: 1},
      {name: 'required', type: 'checkbox', label: 'Required Field'},
      {name: 'showStrength', type: 'checkbox', label: 'Show Password Strength'}
    ]
  },

  number: {
    factory: 'NumberElementFactory',
    icon: 'icon-number',
    label: 'Number Input',
    category: 'basic',
    description: 'Numeric input field with validation',
    defaultConfig: {
      type: 'number',
      icon: 'icon-number',
      placeholder: 'Enter number...',
      required: false
    },
    properties: [
      {name: 'label', type: 'text', label: 'Field Label', required: true},
      {name: 'icon', type: 'icon-select', label: 'Field Icon'},
      {name: 'placeholder', type: 'text', label: 'Placeholder Text'},
      {name: 'min', type: 'number', label: 'Minimum Value'},
      {name: 'max', type: 'number', label: 'Maximum Value'},
      {name: 'step', type: 'number', label: 'Step Value', min: 0.01},
      {name: 'required', type: 'checkbox', label: 'Required Field'},
      {name: 'readonly', type: 'checkbox', label: 'Read Only'},
      {name: 'disabled', type: 'checkbox', label: 'Disabled'}
    ]
  },

  tel: {
    factory: 'TextElementFactory',
    icon: 'icon-phone',
    label: 'Phone Number',
    category: 'basic',
    description: 'Phone number input field',
    defaultConfig: {
      type: 'tel',
      icon: 'icon-phone',
      placeholder: 'Enter phone number...',
      required: false
    },
    properties: [
      {name: 'label', type: 'text', label: 'Field Label', required: true},
      {name: 'icon', type: 'icon-select', label: 'Field Icon'},
      {name: 'placeholder', type: 'text', label: 'Placeholder Text'},
      {name: 'pattern', type: 'text', label: 'Validation Pattern'},
      {name: 'required', type: 'checkbox', label: 'Required Field'}
    ]
  },

  url: {
    factory: 'TextElementFactory',
    icon: 'icon-link',
    label: 'URL Input',
    category: 'basic',
    description: 'URL/website address input',
    defaultConfig: {
      type: 'url',
      placeholder: 'Enter URL...',
      validation: {url: true},
      required: false
    },
    properties: [
      {name: 'label', type: 'text', label: 'Field Label', required: true},
      {name: 'icon', type: 'icon-select', label: 'Field Icon'},
      {name: 'placeholder', type: 'text', label: 'Placeholder Text'},
      {name: 'required', type: 'checkbox', label: 'Required Field'}
    ]
  },

  hidden: {
    factory: null, // Scafold manually
    icon: 'icon-published0',
    label: 'Hidden Input',
    category: 'basic',
    description: 'Hidden input field',
    defaultConfig: {
      type: 'hidden',
      value: ''
    },
    properties: [
      {name: 'value', type: 'text', label: 'Default Value'}
    ]
  },

  // Text Areas
  textarea: {
    factory: 'TextareaElementFactory',
    icon: 'icon-file',
    label: 'Text Area',
    category: 'basic',
    description: 'Multi-line text input',
    defaultConfig: {
      icon: 'icon-file',
      placeholder: 'Enter text...',
      rows: 4,
      maxLength: 1000,
      required: false
    },
    properties: [
      {name: 'label', type: 'text', label: 'Field Label', required: true},
      {name: 'icon', type: 'icon-select', label: 'Field Icon'},
      {name: 'placeholder', type: 'text', label: 'Placeholder Text'},
      {name: 'rows', type: 'number', label: 'Number of Rows', min: 1, max: 20},
      {name: 'maxLength', type: 'number', label: 'Maximum Length', min: 1},
      {name: 'required', type: 'checkbox', label: 'Required Field'},
      {name: 'readonly', type: 'checkbox', label: 'Read Only'}
    ]
  },

  // Selection Fields
  select: {
    factory: 'SelectElementFactory',
    icon: 'icon-menus',
    label: 'Dropdown Select',
    category: 'basic',
    description: 'Single selection dropdown',
    defaultConfig: {
      options: [],
      icon: 'icon-menus',
      placeholder: 'Select an option...',
      required: false
    },
    properties: [
      {name: 'label', type: 'text', label: 'Field Label', required: true},
      {name: 'icon', type: 'icon-select', label: 'Field Icon'},
      {name: 'placeholder', type: 'text', label: 'Placeholder Text'},
      {name: 'options', type: 'options-editor', label: 'Options'},
      {name: 'dataSource', type: 'datasource-editor', label: 'Data Source'},
      {name: 'required', type: 'checkbox', label: 'Required Field'},
      {name: 'disabled', type: 'checkbox', label: 'Disabled'}
    ]
  },

  radio: {
    factory: null,
    icon: 'icon-button',
    label: 'Radio Buttons',
    category: 'basic',
    description: 'Single selection radio buttons',
    defaultConfig: {
      type: 'radio',
      options: [
        {value: 'option1', label: 'Option 1'},
        {value: 'option2', label: 'Option 2'},
        {value: 'option3', label: 'Option 3'}
      ],
      inline: false,
      required: false
    },
    properties: [
      {name: 'label', type: 'text', label: 'Field Label', required: true},
      {name: 'options', type: 'options-editor', label: 'Options'},
      {name: 'inline', type: 'checkbox', label: 'Display Inline'},
      {name: 'required', type: 'checkbox', label: 'Required Field'}
    ]
  },

  checkbox: {
    factory: null,
    icon: 'icon-check',
    label: 'Checkbox',
    category: 'basic',
    description: 'Single checkbox (switch style)',
    defaultConfig: {
      type: 'checkbox',
      value: '1',
      switch: true,
      checked: false,
      required: false
    },
    properties: [
      {name: 'label', type: 'text', label: 'Field Label', required: true},
      {name: 'value', type: 'text', label: 'Checkbox Value', default: '1'},
      {name: 'switch', type: 'checkbox', label: 'Use Switch Style', default: true},
      {name: 'checked', type: 'checkbox', label: 'Default Checked'},
      {name: 'required', type: 'checkbox', label: 'Required Field'}
    ]
  },

  // Date/Time Fields
  date: {
    factory: 'DateElementFactory',
    icon: 'icon-calendar',
    label: 'Date Picker',
    category: 'basic',
    description: 'Date selection field',
    defaultConfig: {
      type: 'date',
      icon: 'icon-calendar',
      required: false
    },
    properties: [
      {name: 'label', type: 'text', label: 'Field Label', required: true},
      {name: 'icon', type: 'icon-select', label: 'Field Icon'},
      {name: 'format', type: 'select', label: 'Date Format', options: ['YYYY-MM-DD', 'DD/MM/YYYY', 'MM/DD/YYYY']},
      {name: 'minDate', type: 'date', label: 'Minimum Date'},
      {name: 'maxDate', type: 'date', label: 'Maximum Date'},
      {name: 'defaultToday', type: 'checkbox', label: 'Default to Today'},
      {name: 'required', type: 'checkbox', label: 'Required Field'}
    ]
  },

  datetime: {
    factory: 'DateElementFactory',
    icon: 'icon-calendar',
    label: 'Date & Time',
    category: 'basic',
    description: 'Date and time selection',
    defaultConfig: {
      type: 'datetime-local',
      icon: 'icon-calendar',
      required: false
    },
    properties: [
      {name: 'label', type: 'text', label: 'Field Label', required: true},
      {name: 'icon', type: 'icon-select', label: 'Field Icon'},
      {name: 'format', type: 'select', label: 'DateTime Format', options: ['YYYY-MM-DD HH:mm', 'DD/MM/YYYY HH:mm']},
      {name: 'minDateTime', type: 'datetime-local', label: 'Minimum DateTime'},
      {name: 'maxDateTime', type: 'datetime-local', label: 'Maximum DateTime'},
      {name: 'required', type: 'checkbox', label: 'Required Field'}
    ]
  },

  time: {
    factory: 'DateElementFactory',
    icon: 'icon-clock',
    label: 'Time Picker',
    category: 'basic',
    description: 'Time selection field',
    defaultConfig: {
      type: 'time',
      icon: 'icon-clock',
      required: false
    },
    properties: [
      {name: 'label', type: 'text', label: 'Field Label', required: true},
      {name: 'icon', type: 'icon-select', label: 'Field Icon'},
      {name: 'format', type: 'select', label: 'Time Format', options: ['HH:mm', 'HH:mm:ss']},
      {name: 'minTime', type: 'time', label: 'Minimum Time'},
      {name: 'maxTime', type: 'time', label: 'Maximum Time'},
      {name: 'required', type: 'checkbox', label: 'Required Field'}
    ]
  },

  // File Upload Fields
  file: {
    factory: 'FileElementFactory',
    icon: 'icon-upload',
    label: 'File Upload',
    category: 'advanced',
    description: 'File upload field',
    defaultConfig: {
      maxFileSize: 10485760, // 10MB
      allowedTypes: ['image/*'],
      icon: 'icon-upload',
      multiple: false,
      required: false
    },
    properties: [
      {name: 'label', type: 'text', label: 'Field Label', required: true},
      {name: 'icon', type: 'icon-select', label: 'Field Icon'},
      {name: 'maxFileSize', type: 'number', label: 'Max File Size (bytes)', min: 1},
      {name: 'allowedTypes', type: 'tags', label: 'Allowed MIME Types'},
      {name: 'multiple', type: 'checkbox', label: 'Allow Multiple Files'},
      {name: 'required', type: 'checkbox', label: 'Required Field'}
    ]
  },

  range: {
    factory: 'RangeElementFactory',
    icon: 'icon-width',
    label: 'Range Slider',
    category: 'advanced',
    description: 'Range slider input',
    defaultConfig: {
      min: 0,
      max: 100,
      step: 1,
      value: 50,
      required: false
    },
    properties: [
      {name: 'label', type: 'text', label: 'Field Label', required: true},
      {name: 'min', type: 'number', label: 'Minimum Value'},
      {name: 'max', type: 'number', label: 'Maximum Value'},
      {name: 'step', type: 'number', label: 'Step Value', min: 0.01},
      {name: 'value', type: 'number', label: 'Default Value'},
      {name: 'showValue', type: 'checkbox', label: 'Show Current Value'},
      {name: 'required', type: 'checkbox', label: 'Required Field'}
    ]
  },

  multiselect: {
    factory: 'MultiSelectElementFactory',
    icon: 'icon-list',
    label: 'Multi Select',
    category: 'advanced',
    description: 'Multiple selection dropdown',
    defaultConfig: {
      options: [],
      icon: 'icon-list',
      placeholder: 'Select options...',
      multiple: true,
      required: false
    },
    properties: [
      {name: 'label', type: 'text', label: 'Field Label', required: true},
      {name: 'icon', type: 'icon-select', label: 'Field Icon'},
      {name: 'placeholder', type: 'text', label: 'Placeholder Text'},
      {name: 'options', type: 'options-editor', label: 'Options'},
      {name: 'dataSource', type: 'datasource-editor', label: 'Data Source'},
      {name: 'maxSelections', type: 'number', label: 'Maximum Selections', min: 1},
      {name: 'required', type: 'checkbox', label: 'Required Field'}
    ]
  },

  tags: {
    factory: 'TagsElementFactory',
    icon: 'icon-tags',
    label: 'Tags Input',
    category: 'advanced',
    description: 'Tag-based input with autocomplete',
    defaultConfig: {
      type: 'text',
      placeholder: 'Enter tags...',
      separator: ',',
      allowCustom: true,
      required: false
    },
    properties: [
      {name: 'label', type: 'text', label: 'Field Label', required: true},
      {name: 'icon', type: 'icon-select', label: 'Field Icon'},
      {name: 'placeholder', type: 'text', label: 'Placeholder Text'},
      {name: 'separator', type: 'text', label: 'Tag Separator'},
      {name: 'allowCustom', type: 'checkbox', label: 'Allow Custom Tags'},
      {name: 'suggestions', type: 'options-editor', label: 'Tag Suggestions'},
      {name: 'maxTags', type: 'number', label: 'Maximum Tags', min: 1},
      {name: 'required', type: 'checkbox', label: 'Required Field'}
    ]
  },

  /**
   * Get field type configuration
   *
   * @param {string} type Field type
   * @returns {Object|null} Field type configuration
   */
  getFieldType(type) {
    return this[type] || null;
  },

  /**
   * Get all field types by category
   *
   * @param {string} category Category name
   * @returns {Array} Field types in category
   */
  getFieldsByCategory(category) {
    const fields = [];
    for (const [type, config] of Object.entries(this)) {
      if (typeof config === 'object' && config.category === category) {
        fields.push({type, ...config});
      }
    }
    return fields;
  },

  /**
   * Get all categories
   *
   * @returns {Array} Available categories
   */
  getCategories() {
    const categories = new Set();
    for (const [type, config] of Object.entries(this)) {
      if (typeof config === 'object' && config.category) {
        categories.add(config.category);
      }
    }
    return Array.from(categories);
  },

  /**
   * Validate field type exists
   *
   * @param {string} type Field type
   * @returns {boolean} True if field type exists
   */
  isValidFieldType(type) {
    return this.hasOwnProperty(type) && typeof this[type] === 'object';
  }
};

// Export to global scope
window.FieldTypeRegistry = FieldTypeRegistry;
