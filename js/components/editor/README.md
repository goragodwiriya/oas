# Rich Text Editor

A modular, plugin-based WYSIWYG rich text editor inspired by CKEditor, built with vanilla JavaScript.

## Features

- 📝 **Rich Text Formatting** - Bold, italic, underline, strikethrough, headings
- 📋 **Lists** - Ordered and unordered lists with nesting support
- 🔗 **Links** - Insert, edit, and remove hyperlinks
- 🖼️ **Images** - Insert from URL, upload, or FileBrowser integration
- 📹 **Videos** - Embed YouTube, Vimeo, or direct video URLs
- 📊 **Tables** - Insert and edit tables with context menu operations
- 🎨 **Colors** - Text color and background color
- ↩️ **Undo/Redo** - Full history management
- ⌨️ **Keyboard Shortcuts** - All common shortcuts supported
- 🔍 **Find & Replace** - Search and replace with options
- 💾 **Autosave** - Automatic saving with localStorage backup
- 📐 **Fullscreen** - Distraction-free editing mode
- 📄 **Source View** - Edit raw HTML
- 🔌 **Plugin Architecture** - Easily extensible

## Quick Start

### Basic Usage

```html
<link rel="stylesheet" href="/js/components/editor/RichTextEditor.css">
<script type="module">
  import RichTextEditor from '/js/components/editor/RichTextEditor.js';

  const editor = new RichTextEditor('#editor', {
    placeholder: 'Start typing...',
    minHeight: 300
  });
</script>

<div id="editor"></div>
```

### With Plugins

```javascript
import RichTextEditor from '/js/components/editor/RichTextEditor.js';
import LinkPlugin from '/js/components/editor/plugins/media/LinkPlugin.js';
import ImagePlugin from '/js/components/editor/plugins/media/ImagePlugin.js';
import AutosavePlugin from '/js/components/editor/plugins/utility/AutosavePlugin.js';

// Register plugins
RichTextEditor.registerPlugin('link', LinkPlugin);
RichTextEditor.registerPlugin('image', ImagePlugin);
RichTextEditor.registerPlugin('autosave', AutosavePlugin);

// Create editor with plugins
const editor = new RichTextEditor('#editor', {
  plugins: ['link', 'image', 'autosave'],
  autosave: {
    interval: 30000,
    key: 'my-editor-draft',
    saveHandler: async (content) => {
      await fetch('/api/save', {
        method: 'POST',
        body: JSON.stringify({ content })
      });
    }
  }
});
```

### With Textarea (Form Integration)

```html
<form id="myForm">
  <textarea id="editor" name="content"></textarea>
  <button type="submit">Submit</button>
</form>

<script type="module">
  import RichTextEditor from '/js/components/editor/RichTextEditor.js';

  // Editor will sync with textarea automatically
  const editor = new RichTextEditor('#editor');
</script>
```

## Configuration Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `height` | `string\|number` | `'auto'` | Editor height |
| `minHeight` | `number` | `200` | Minimum height in pixels |
| `maxHeight` | `number` | `null` | Maximum height in pixels |
| `placeholder` | `string` | `''` | Placeholder text |
| `readOnly` | `boolean` | `false` | Read-only mode |
| `toolbar` | `array` | *default* | Toolbar configuration |
| `stickyToolbar` | `boolean` | `false` | Sticky toolbar on scroll |
| `plugins` | `array` | `[]` | Plugins to load |
| `autofocus` | `boolean` | `false` | Auto-focus on init |
| `sanitize` | `boolean` | `true` | Sanitize HTML content |

## Toolbar Configuration

### Default Toolbar

```javascript
const defaultToolbar = [
  'bold', 'italic', 'underline', 'strikethrough', '|',
  'heading', '|',
  'bulletList', 'numberedList', '|',
  'alignLeft', 'alignCenter', 'alignRight', '|',
  'indent', 'outdent', '|',
  'link', 'image', '|',
  'textColor', 'backgroundColor', '|',
  'undo', 'redo', '|',
  'removeFormat', 'sourceView'
];
```

### Custom Toolbar (Groups)

```javascript
const editor = new RichTextEditor('#editor', {
  toolbar: [
    ['bold', 'italic', 'underline'],    // Group 1
    ['heading'],                          // Group 2
    ['bulletList', 'numberedList'],       // Group 3
    ['link', 'image', 'table'],           // Group 4
    ['undo', 'redo']                      // Group 5
  ]
});
```

### Available Toolbar Items

| Item | Description |
|------|-------------|
| `bold` | Bold text |
| `italic` | Italic text |
| `underline` | Underline text |
| `strikethrough` | Strikethrough text |
| `heading` | Heading dropdown (H1-H6) |
| `fontFamily` | Font family dropdown (see [Fonts](#fonts)) |
| `bulletList` | Unordered list |
| `numberedList` | Ordered list |
| `alignLeft` | Align left |
| `alignCenter` | Align center |
| `alignRight` | Align right |
| `alignJustify` | Justify |
| `indent` | Increase indent |
| `outdent` | Decrease indent |
| `link` | Insert/edit link — can upload a file or pick one from the FileBrowser |
| `image` | Insert image |
| `video` | Embed video |
| `table` | Insert table |
| `horizontalRule` | Horizontal line |
| `blockquote` | Block quote |
| `codeBlock` | Code block |
| `textColor` | Text color picker |
| `backgroundColor` | Background color picker |
| `undo` | Undo |
| `redo` | Redo |
| `removeFormat` | Clear formatting |
| `sourceView` | Toggle source view |
| `fullscreen` | Toggle fullscreen |
| `findReplace` | Find and replace |
| `\|` or `separator` | Visual separator |

## Fonts

The `fontFamily` toolbar item lists the fonts of the `fontFamily.fonts` option.
Without it, the list holds the commonly used Thai Google Fonts (Sarabun, Prompt,
Kanit, Mitr, Noto Sans Thai, IBM Plex Sans Thai, Anuphan, Chakra Petch, …) and a
few system fonts. The full catalog is `RichTextEditor.fonts.googleFonts`
(`configs/fonts.js`).

```javascript
RichTextEditor.create('#editor', {
  toolbar: [['bold', 'italic'], ['heading', 'fontFamily']],
  fontFamily: {
    fonts: [
      'Sarabun', 'Kanit',                        // catalog name → loaded from Google Fonts
      'Tahoma, sans-serif',                      // any CSS font stack
      {label: 'Roboto', google: true},           // Google font outside the catalog
      {label: 'Brand', value: "'Brand Font', serif"}
    ],
    loadGoogleFonts: true                        // false = the page loads the fonts itself
  }
});

// Every editor on the page
RichTextEditor.defaults.fontFamily.fonts = ['Sarabun', 'Prompt', 'Tahoma, sans-serif'];
```

Per textarea: `<textarea data-rte-fonts="Sarabun|Kanit|Tahoma, sans-serif">` (or a JSON array).

The font is written as `<span style="font-family: …">`. The editor loads the
Google fonts of the list when the dropdown first opens, and those used by the
content when it is set. The page that shows the content must load them too
(GCMS does this for its front end in `Gcms/GoogleFonts.php`).

## Links to files

The link dialog uploads a file (button or drag & drop) to the FileBrowser
upload endpoint, or picks one with **Browse files**. It uses `link.fileBrowser`
/ `link.uploadUrl`, falling back to the `image` settings. Optional
`link.uploadPath`, `link.maxFileSize`, `link.allowedFileTypes` (FileBrowser
filter, default `*`); the server's `allowedExtensions` still decides what is
accepted.

## FileBrowser storages

`php/config.php` → `storages` keeps kinds of files in separate folders under
`DATA_FOLDER`, each shown as its own FileBrowser tab (the first is the default):

```php
'storages' => [
    'image' => ['folder' => 'image', 'name' => 'Images',
                'allowedExtensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico']],
    'file' => ['folder' => 'file', 'name' => 'Files']
],
```

Requests name the storage with a `storage` parameter. FileBrowser options:
`storage` (tab to open in) and `storages` (ids to offer, e.g. `['image']` for an
image picker). The image dialog uses `image` only; the link dialog browses and
uploads to `file`. Without `storages` everything stays in one folder and one tab.

## API Methods

### Content

```javascript
// Get HTML content
const html = editor.getContent();

// Set HTML content
editor.setContent('<p>Hello World</p>');

// Get plain text
const text = editor.getTextContent();

// Check if empty
const isEmpty = editor.isEmpty();

// Clear content
editor.clear();
```

### Focus & Selection

```javascript
// Focus editor
editor.focus();

// Blur editor
editor.blur();

// Check if focused
const hasFocus = editor.hasFocus();
```

### Commands

```javascript
// Execute command
editor.execute('bold');
editor.execute('heading', 2); // H2
editor.execute('foreColor', '#ff0000');
```

### Events

```javascript
// Listen for events
editor.on('content:change', (html) => {
  console.log('Content changed:', html);
});

editor.on('editor:focus', () => {
  console.log('Editor focused');
});

editor.on('editor:blur', () => {
  console.log('Editor blurred');
});
```

### State

```javascript
// Read-only mode
editor.setReadOnly(true);
const isReadOnly = editor.isReadOnly();

// Word/character count
const words = editor.getWordCount();
const chars = editor.getCharacterCount();
```

### Cleanup

```javascript
// Destroy editor
editor.destroy();
```

## Events

| Event | Description |
|-------|-------------|
| `editor:init` | Editor initialized |
| `editor:ready` | Editor ready for use |
| `editor:destroy` | Editor destroyed |
| `editor:focus` | Editor focused |
| `editor:blur` | Editor blurred |
| `content:change` | Content changed |
| `content:set` | Content set programmatically |
| `selection:change` | Selection changed |
| `command:execute` | Command executed |
| `mode:change` | Mode changed (wysiwyg/source) |

## Keyboard Shortcuts

| Shortcut | Action |
|----------|--------|
| `Ctrl+B` | Bold |
| `Ctrl+I` | Italic |
| `Ctrl+U` | Underline |
| `Ctrl+Z` | Undo |
| `Ctrl+Y` / `Ctrl+Shift+Z` | Redo |
| `Ctrl+K` | Insert link |
| `Ctrl+Shift+L` | Bullet list |
| `Ctrl+Shift+O` | Numbered list |
| `Ctrl+F` | Find |
| `Ctrl+H` | Find & Replace |
| `Tab` | Indent |
| `Shift+Tab` | Outdent |
| `F11` | Toggle fullscreen |
| `Escape` | Exit fullscreen / Close dialog |

## Plugins

### Available Plugins

| Plugin | File | Description |
|--------|------|-------------|
| `link` | `plugins/media/LinkPlugin.js` | Link management |
| `image` | `plugins/media/ImagePlugin.js` | Image insertion |
| `video` | `plugins/media/VideoPlugin.js` | Video embedding |
| `table` | `plugins/media/TablePlugin.js` | Table editor |
| `sourceView` | `plugins/utility/SourceViewPlugin.js` | HTML source view |
| `fullscreen` | `plugins/utility/FullscreenPlugin.js` | Fullscreen mode |
| `autosave` | `plugins/utility/AutosavePlugin.js` | Auto-save |
| `wordcount` | `plugins/utility/WordCountPlugin.js` | Word/char count |
| `findReplace` | `plugins/utility/FindReplacePlugin.js` | Find & replace |

### Creating Custom Plugins

```javascript
import PluginBase from './plugins/PluginBase.js';

class MyPlugin extends PluginBase {
  static pluginName = 'myPlugin';

  init() {
    super.init();

    // Register command
    this.registerCommand('myCommand', {
      execute: () => this.doSomething()
    });

    // Register shortcut
    this.registerShortcut('ctrl+m', () => this.doSomething());

    // Listen for events
    this.subscribe('content:change', () => {
      console.log('Content changed!');
    });
  }

  doSomething() {
    this.insertHtml('<p>Hello from plugin!</p>');
  }
}

// Register and use
RichTextEditor.registerPlugin('myPlugin', MyPlugin);
```

## File Structure

```
/js/components/editor/
├── RichTextEditor.js          # Main entry point
├── RichTextEditor.css         # Styles
├── README.md                  # Documentation
├── core/
│   ├── EventBus.js            # Event system
│   ├── CommandManager.js      # Command execution
│   ├── HistoryManager.js      # Undo/Redo
│   ├── SelectionManager.js    # Selection handling
│   └── KeyboardManager.js     # Keyboard shortcuts
├── ui/
│   ├── Toolbar.js             # Toolbar component
│   ├── ContentArea.js         # Content editable area
│   └── dialogs/
│       └── BaseDialog.js      # Base dialog class
└── plugins/
    ├── PluginBase.js          # Base plugin class
    ├── media/
    │   ├── LinkPlugin.js
    │   ├── ImagePlugin.js
    │   ├── VideoPlugin.js
    │   └── TablePlugin.js
    └── utility/
        ├── SourceViewPlugin.js
        ├── FullscreenPlugin.js
        ├── AutosavePlugin.js
        ├── WordCountPlugin.js
        └── FindReplacePlugin.js
```

## Browser Support

- Chrome (latest)
- Firefox (latest)
- Safari (latest)
- Edge (latest)

## License

MIT License
