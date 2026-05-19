/**
 * RichTextEditor - Main Entry Point
 * Single file to import all components and plugins
 *
 * @author Goragod Wiriya
 * @version 1.0
 */

// Core
import RichTextEditor from './RichTextEditor.js';
import EventBus from './core/EventBus.js';
import CommandManager from './core/CommandManager.js';
import HistoryManager from './core/HistoryManager.js';
import SelectionManager from './core/SelectionManager.js';
import KeyboardManager from './core/KeyboardManager.js';

// Plugins - Media
import LinkPlugin from './plugins/media/LinkPlugin.js';
import ImagePlugin from './plugins/media/ImagePlugin.js';
import GalleryUploadPlugin from './plugins/media/GalleryUploadPlugin.js';
import TablePlugin from './plugins/media/TablePlugin.js';
import VideoPlugin from './plugins/media/VideoPlugin.js';
import IframePlugin from './plugins/media/IframePlugin.js';
import EmojiPlugin from './plugins/media/EmojiPlugin.js';
import SpecialCharsPlugin from './plugins/media/SpecialCharsPlugin.js';

// Plugins - Utility
import SourceViewPlugin from './plugins/utility/SourceViewPlugin.js';
import FullscreenPlugin from './plugins/utility/FullscreenPlugin.js';
import AutosavePlugin from './plugins/utility/AutosavePlugin.js';
import WordCountPlugin from './plugins/utility/WordCountPlugin.js';
import FindReplacePlugin from './plugins/utility/FindReplacePlugin.js';
import MaxLengthPlugin from './plugins/utility/MaxLengthPlugin.js';
import MentionPlugin from './plugins/utility/MentionPlugin.js';
import PrintPlugin from './plugins/utility/PrintPlugin.js';
import PasteCleanerPlugin from './plugins/utility/PasteCleanerPlugin.js';
import AiWriterPlugin from './plugins/utility/AiWriterPlugin.js';
import ContentCleanupPlugin from './plugins/utility/ContentCleanupPlugin.js';

// Config
import profiles from './configs/profiles.js';
import defaults, {mergeOptions} from './configs/defaults.js';

// Auto-register all plugins
const allPlugins = {
  link: LinkPlugin,
  image: ImagePlugin,
  galleryUpload: GalleryUploadPlugin,
  table: TablePlugin,
  video: VideoPlugin,
  iframe: IframePlugin,
  emoji: EmojiPlugin,
  specialChars: SpecialCharsPlugin,
  sourceView: SourceViewPlugin,
  fullscreen: FullscreenPlugin,
  autosave: AutosavePlugin,
  wordcount: WordCountPlugin,
  findReplace: FindReplacePlugin,
  maxLength: MaxLengthPlugin,
  mention: MentionPlugin,
  print: PrintPlugin,
  pasteCleaner: PasteCleanerPlugin,
  aiWriter: AiWriterPlugin,
  contentCleanup: ContentCleanupPlugin
};

// Register all plugins
Object.entries(allPlugins).forEach(([name, plugin]) => {
  RichTextEditor.registerPlugin(name, plugin);
});

/**
 * Create editor with profile
 * @param {string|HTMLElement} selector - CSS selector or element
 * @param {string|Object} profileOrOptions - Profile name or options object
 * @returns {RichTextEditor}
 */
RichTextEditor.create = function(selector, profileOrOptions = 'basic') {
  let options = {};

  if (typeof profileOrOptions === 'string') {
    // Use named profile
    const profile = profiles[profileOrOptions];
    if (!profile) {
      console.warn(`Profile "${profileOrOptions}" not found, using "basic"`);
      options = mergeOptions({}, profiles.basic.options);
      options.plugins = profiles.basic.plugins;
      options.toolbar = profiles.basic.toolbar;
    } else {
      options = mergeOptions({}, profile.options);
      options.plugins = profile.plugins;
      options.toolbar = profile.toolbar;
    }
  } else if (typeof profileOrOptions === 'object') {
    // Use custom options with optional profile base
    const profileName = profileOrOptions.profile || 'basic';
    const profile = profiles[profileName] || profiles.basic;

    options = mergeOptions(profileOrOptions, profile.options);

    // Use profile's plugins/toolbar unless overridden
    options.plugins = profileOrOptions.plugins || profile.plugins;
    options.toolbar = profileOrOptions.toolbar || profile.toolbar;
  }

  return new RichTextEditor(selector, options);
};

/**
 * Get available profiles
 * @returns {Object}
 */
RichTextEditor.getProfiles = function() {
  return {...profiles};
};

/**
 * Get default options
 * @returns {Object}
 */
RichTextEditor.getDefaults = function() {
  return {...defaults};
};

/**
 * Register custom profile
 * @param {string} name
 * @param {Object} profile
 */
RichTextEditor.registerProfile = function(name, profile) {
  profiles[name] = profile;
};

// Exports
export {
  RichTextEditor,
  EventBus,
  CommandManager,
  HistoryManager,
  SelectionManager,
  KeyboardManager,
  LinkPlugin,
  ImagePlugin,
  GalleryUploadPlugin,
  TablePlugin,
  VideoPlugin,
  IframePlugin,
  EmojiPlugin,
  SpecialCharsPlugin,
  SourceViewPlugin,
  FullscreenPlugin,
  AutosavePlugin,
  WordCountPlugin,
  FindReplacePlugin,
  MaxLengthPlugin,
  MentionPlugin,
  PrintPlugin,
  PasteCleanerPlugin,
  AiWriterPlugin,
  ContentCleanupPlugin,
  profiles,
  defaults,
  mergeOptions
};

export default RichTextEditor;
