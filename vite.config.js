import {defineConfig} from 'vite';
import {existsSync, readFileSync} from 'fs';
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
