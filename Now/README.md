# Now.js Framework

A modern, lightweight JavaScript framework for building dynamic web applications.

## 🚀 Quick Start

### Using the real source files (recommended)

Copy the whole `Now/dist` folder from the source into your site. The CDN is not the real source and can lag behind it, so use it only for quick experiments.

```html
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <title>Now.js App</title>

  <!-- Core CSS -->
  <link rel="stylesheet" href="/Now/dist/now.core.min.css">
</head>
<body>
  <div id="app"></div>

  <!-- Core JS -->
  <script src="/Now/dist/now.core.min.js"></script>

  <script>
    Now.init({
      environment: 'production',
      debug: false
    });
  </script>
</body>
</html>
```

## 📦 Installation

### Build from Source

The bundles in `Now/dist` ship prebuilt. Now.js is developed and built in
[adminframework](https://github.com/goragodwiriya/adminframework); every project that uses it
receives `Now/` from there and does not build Now.js itself.

```bash
# Clone the repository where Now.js is built
git clone https://github.com/goragodwiriya/adminframework.git
cd adminframework

# Install dependencies
npm install

# Build production bundles into Now/dist
npm run build
```

## 📚 Available Bundles

### Core (Required)
- `now.core.min.js` (607 KB, 150 KB gzipped)
- `now.core.min.css` (92 KB, 18 KB gzipped)

### Optional Modules
- `now.table.min.js` (65 KB) - Data table management
- `now.media.min.js` (8.6 KB + CSS) - Media viewer
- `now.graph.min.js` (46 KB) - Chart and graph rendering
- `now.tabs.min.js` (6.7 KB + CSS) - Tab component
- `now.sortable.min.js` (7.8 KB) - Drag & drop functionality
- `now.serviceworker.min.js` (9.9 KB) - PWA support
- `now.queue.min.js` (16.8 KB) - Task queue management

## 🔧 Usage

### Basic Initialization

```javascript
Now.init({
  environment: 'production',
  debug: false,
  i18n: {
    enabled: true,
    defaultLanguage: 'th'
  }
});
```

### Lazy Loading Optional Modules

```javascript
// Load table module when needed
async function loadTableModule() {
  const script = document.createElement('script');
  script.src = '/Now/dist/now.table.min.js';
  await new Promise(resolve => {
    script.onload = resolve;
    document.head.appendChild(script);
  });
  return window.TableManager;
}
```

## 📖 Documentation

- [Website](https://nowjs.net) Official Website
- [Documentation](https://docs.nowjs.net) Comprehensive Guides

## 🛠️ Development

In adminframework:

```bash
# Development mode (auto-reload)
npm run dev

# Build all bundles (the core build empties Now/dist first, the others rebuild into it)
npm run build

# Rebuild one bundle; never run build:core alone, it empties Now/dist
npm run build:table
npm run build:features
```

## 📄 License

MIT License - see LICENSE file for details

## 👥 Author

Goragod Wiriya (https://github.com/goragodwiriya)

## 🔗 Links

- [GitHub Repository](https://github.com/goragodwiriya/nowjs)
- [Issues](https://github.com/goragodwiriya/nowjs/issues)
