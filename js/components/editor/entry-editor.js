/**
 * RichTextEditor - Vite Entry Point for Bundle
 * This file is the entry point for building the bundled version
 *
 * @author Goragod Wiriya
 * @version 1.0
 */

// Import CSS
import './RichTextEditor.css';
import './plugins/filebrowser/filebrowser.css';

// Import main module
import RichTextEditor, {
  EventBus,
  CommandManager,
  HistoryManager,
  SelectionManager,
  KeyboardManager,
  profiles,
  defaults,
  mergeOptions
} from './index.js';

// Import FileBrowser
import FileBrowser from './plugins/filebrowser/FileBrowser.js';

// Import IframePlugin for direct access
import IframePlugin from './plugins/media/IframePlugin.js';

// Expose to window for IIFE build
if (typeof window !== 'undefined') {
  window.RichTextEditor = RichTextEditor;
  window.FileBrowser = FileBrowser;
  window.IframePlugin = IframePlugin;

  // Also expose utilities on RichTextEditor
  window.RichTextEditor.EventBus = EventBus;
  window.RichTextEditor.CommandManager = CommandManager;
  window.RichTextEditor.HistoryManager = HistoryManager;
  window.RichTextEditor.SelectionManager = SelectionManager;
  window.RichTextEditor.KeyboardManager = KeyboardManager;
  window.RichTextEditor.profiles = profiles;
  window.RichTextEditor.defaults = defaults;
  window.RichTextEditor.mergeOptions = mergeOptions;
  window.RichTextEditor.FileBrowser = FileBrowser;
  window.RichTextEditor.IframePlugin = IframePlugin;
}

// Only default export for IIFE build compatibility
export default RichTextEditor;
