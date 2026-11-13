import {defineConfig} from 'vite';
import {existsSync, readFileSync, readdirSync} from 'fs';
import {dirname, resolve} from 'path';
import {fileURLToPath} from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

const buildTarget = process.env.BUILD_TARGET || 'core';

// Bundles of Now.js itself, built into Now/dist. This file is the same in every
// project, so Now/ stays identical everywhere.
const builds = {
  core: {
    entry: resolve(__dirname, 'Now/core-entry.js'),
    name: 'now.core.min.js',
    css: 'now.core.min.css',
    libName: 'NowCore'
  },
  table: {
    entry: resolve(__dirname, 'Now/entry-table.js'),
    name: 'now.table.min.js',
    css: 'now.table.min.css',
    libName: 'NowTable'
  },
  graph: {
    entry: resolve(__dirname, 'Now/entry-graph.js'),
    name: 'now.graph.min.js',
    css: 'now.graph.min.css',
    libName: 'NowGraph'
  },
  serviceworker: {
    entry: resolve(__dirname, 'Now/entry-serviceworker.js'),
    name: 'now.serviceworker.min.js',
    css: 'now.serviceworker.min.css',
    libName: 'NowServiceWorker'
  },
  queue: {
    entry: resolve(__dirname, 'Now/entry-queue.js'),
    name: 'now.queue.min.js',
    css: 'now.queue.min.css',
    libName: 'NowQueue'
  },
  eventcalendar: {
    entry: resolve(__dirname, 'Now/entry-eventcalendar.js'),
    name: 'eventcalendar.min.js',
    css: 'eventcalendar.min.css',
    libName: 'EventCalendar'
  },
  editor: {
    entry: resolve(__dirname, 'js/components/editor/entry-editor.js'),
    name: 'richtext-editor.min.js',
    css: 'richtext-editor.min.css',
    libName: 'RichTextEditor'
  },
  formbuilder: {
    entry: resolve(__dirname, 'Now/entry-formbuilder.js'),
    name: 'now.formbuilder.min.js',
    css: 'now.formbuilder.min.css',
    libName: 'NowFormBuilder'
  },
  syntaxhighlighter: {
    entry: resolve(__dirname, 'Now/entry-syntaxhighlighter.js'),
    name: 'now.syntaxhighlighter.min.js',
    css: 'now.syntaxhighlighter.min.css',
    libName: 'NowSyntaxHighlighter'
  }
};

// Feature bundles: parts of Now.js that now.core.min.js loads when a page needs
// them (Now.features in Now/Now.js lists the same names). BUILD_TARGET=feature-<name>
// builds Now/features/<name>.js into Now/dist/now.<name>.min.js (+ .css).
const features = ['code', 'richtext', 'sync', 'editinplace', 'taskmonitor', 'securityconfig',
  'calendar', 'gallery', 'iconpicker', 'tabs', 'lineitems', 'sortable', 'imagecrop'];
for (const feature of features) {
  builds[`feature-${feature}`] = {
    entry: resolve(__dirname, `Now/features/${feature}.js`),
    name: `now.${feature}.min.js`,
    css: `now.${feature}.min.css`,
    libName: `NowFeature_${feature}`
  };
}

// A bundle that belongs to one project only lives in js/components/<target>/
// with a build.json ({"entry", "name", "css", "libName"}), and is built into
// js/dist/ so it never lands in Now/.
const componentBuild = resolve(__dirname, 'js/components', buildTarget, 'build.json');
if (!builds[buildTarget] && existsSync(componentBuild)) {
  const component = JSON.parse(readFileSync(componentBuild, 'utf8'));
  builds[buildTarget] = {
    ...component,
    entry: resolve(dirname(componentBuild), component.entry || 'entry.js'),
    outDir: 'js/dist'
  };
}

const targetConfig = builds[buildTarget];

// Every global Now.js puts on window (`window.Name = ...` in its sources: the
// core, the other bundles, the feature bundles and the editor). Now.resolveFunction()
// never resolves a name that starts at one of them, so a server response or a
// directive cannot call the framework's own functions (Now.frameworkGlobals).
const frameworkGlobals = (() => {
  const names = new Set();
  const scan = path => {
    if (!existsSync(path)) return;
    if (!path.endsWith('.js')) {
      for (const entry of readdirSync(path, {withFileTypes: true})) {
        if (entry.isDirectory() || entry.name.endsWith('.js')) scan(resolve(path, entry.name));
      }
      return;
    }
    for (const match of readFileSync(path, 'utf8').matchAll(/\bwindow\.([A-Za-z_$][\w$]*)\s*=(?!=)/g)) {
      names.add(match[1]);
    }
  };
  ['Now/Now.js', 'Now/js', 'Now/features', 'js/components/editor'].forEach(dir => scan(resolve(__dirname, dir)));
  return [...names].sort();
})();

if (!targetConfig) {
  throw new Error(`Unknown BUILD_TARGET: ${buildTarget}`);
}

export default defineConfig({
  define: {
    __NOW_FRAMEWORK_GLOBALS__: JSON.stringify(frameworkGlobals)
  },
  build: {
    emptyOutDir: buildTarget === 'core', // Only empty for core build
    lib: {
      entry: targetConfig.entry,
      name: targetConfig.libName,
      formats: ['iife'],
      cssFileName: targetConfig.css.replace(/\.css$/, ''),
      fileName: () => targetConfig.name
    },
    outDir: targetConfig.outDir || 'Now/dist',
    sourcemap: true,
    minify: 'terser',
    terserOptions: {
      compress: {
        drop_console: false,
        drop_debugger: false
      },
      format: {
        comments: false
      },
      keep_classnames: true,
      keep_fnames: true
    }
  }
});
