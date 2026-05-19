import {defineConfig} from 'vite';
import {dirname, resolve} from 'path';
import {fileURLToPath} from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

const buildTarget = process.env.BUILD_TARGET || 'core';

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

const targetConfig = builds[buildTarget];

if (!targetConfig) {
  throw new Error(`Unknown BUILD_TARGET: ${buildTarget}`);
}

export default defineConfig({
  build: {
    emptyOutDir: buildTarget === 'core', // Only empty for core build
    lib: {
      entry: targetConfig.entry,
      name: targetConfig.libName,
      formats: ['iife'],
      cssFileName: targetConfig.css.replace(/\.css$/, ''),
      fileName: () => targetConfig.name
    },
    outDir: 'Now/dist',
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
