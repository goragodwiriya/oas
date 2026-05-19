/**
 * RichTextEditor - Default Configuration
 *
 * @author Goragod Wiriya
 * @version 1.0
 */

export const defaults = {
  // Container
  height: 'auto',
  minHeight: 200,
  maxHeight: null,
  placeholder: '',
  readOnly: false,
  autofocus: false,
  sanitize: true,
  allowIframe: true,
  allowStyle: false,
  allowScript: false,
  allowInteractiveTags: '',

  // Toolbar
  toolbar: [
    'bold', 'italic', 'underline', '|',
    'heading', '|',
    'bulletList', 'numberedList', '|',
    'alignLeft', 'alignCenter', 'alignRight', '|',
    'link', 'image', '|',
    'textColor', 'backgroundColor', '|',
    'undo', 'redo', '|',
    'removeFormat'
  ],
  stickyToolbar: false,

  // Plugins
  plugins: [],

  // Plugin options
  autosave: {
    interval: 30000,
    key: 'rte-autosave',
    saveHandler: null,
    useLocalStorage: true,
    showIndicator: true
  },

  wordcount: {
    showWords: true,
    showCharacters: true,
    showCharactersWithoutSpaces: false
  },

  maxLength: {
    maxLength: 10000,
    maxWords: null,
    countMode: 'characters',
    showCounter: true,
    showWarning: true,
    warningThreshold: 0.9,
    enforceLimit: true
  },

  mention: {
    trigger: '@',
    minChars: 1,
    maxSuggestions: 10,
    debounceTime: 200,
    dataSource: null,
    renderItem: null,
    insertTemplate: null,
    linkTemplate: null
  },

  image: {
    uploadUrl: null,
    fileBrowser: {
      enabled: true,
      options: {
        apiActions: {
          getPresetCategories: '../../js/components/editor/php/filebrowser.php?action=get_preset_categories',
          getPresets: '../../js/components/editor/php/filebrowser.php?action=get_presets',
          getFiles: '../../js/components/editor/php/filebrowser.php?action=get_files',
          getFolderTree: '../../js/components/editor/php/filebrowser.php?action=get_folder_tree',
          upload: '../../js/components/editor/php/filebrowser.php?action=upload',
          createFolder: '../../js/components/editor/php/filebrowser.php?action=create_folder',
          rename: '../../js/components/editor/php/filebrowser.php?action=rename',
          delete: '../../js/components/editor/php/filebrowser.php?action=delete',
          copy: '../../js/components/editor/php/filebrowser.php?action=copy',
          move: '../../js/components/editor/php/filebrowser.php?action=move'
        }
      }
    },
    maxFileSize: 5 * 1024 * 1024,
    allowedTypes: ['image/jpeg', 'image/png', 'image/gif', 'image/webp']
  },

  video: {
    allowedProviders: ['youtube', 'vimeo'],
    responsive: true
  },

  findReplace: {
    matchCase: false,
    wholeWord: false
  },

  aiWriter: {
    endpoint: '',
    maxContextLength: 12000,
    allowedClasses: [
      'left', 'center', 'right', 'justify',
      'top', 'bottom', 'middle', 'baseline',
      'float-left', 'float-right', 'float-center',
      'block', 'inline', 'inline-block'
    ],
    defaultGeneratePrompt: '',
    defaultRewritePrompt: 'Rewrite this content in new words while preserving the important facts.',
    defaultImagePrompt: '',
    defaultImageSize: '1024x1024',
    cleanupAiOutput: true
  },

  contentCleanup: {
    allowedClasses: [
      'left', 'center', 'right', 'justify',
      'top', 'bottom', 'middle', 'baseline',
      'float-left', 'float-right', 'float-center',
      'block', 'inline', 'inline-block'
    ],
    removeHorizontalRules: true,
    removeIds: true,
    removeStyles: true,
    cleanClasses: true,
    cleanTables: true
  },

  // Theme (CSS variables override)
  theme: {
    primaryColor: null,
    borderRadius: null,
    fontFamily: null
  }
};

/**
 * Merge user options with defaults
 * @param {Object} userOptions
 * @param {Object} profileOptions
 * @returns {Object}
 */
export function mergeOptions(userOptions = {}, profileOptions = {}) {
  const merged = {...defaults};

  // Merge profile options
  Object.keys(profileOptions).forEach(key => {
    if (typeof profileOptions[key] === 'object' && !Array.isArray(profileOptions[key])) {
      merged[key] = {...merged[key], ...profileOptions[key]};
    } else {
      merged[key] = profileOptions[key];
    }
  });

  // Merge user options (highest priority)
  Object.keys(userOptions).forEach(key => {
    if (typeof userOptions[key] === 'object' && !Array.isArray(userOptions[key]) && merged[key]) {
      merged[key] = {...merged[key], ...userOptions[key]};
    } else {
      merged[key] = userOptions[key];
    }
  });

  return merged;
}

export default defaults;
