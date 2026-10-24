/**
 * RichTextElementFactory - Integrates RichTextEditor with ElementManager
 *
 * Usage:
 *   <textarea name="detail" data-element="richtext" data-attr="value:detail"></textarea>
 *
 * Options (via data-* attributes):
 *   data-rte-profile="basic|full|minimal|comment"  - editor profile (default: basic)
 *   data-rte-height="400"                          - editor height in px
 *   data-rte-min-height="200"                      - min height in px
 *   data-rte-placeholder="..."                     - placeholder text
 *   data-rte-sticky="true"                         - sticky toolbar
 *   data-rte-allow-style="true"                    - allow <style> tags in content
 *   data-rte-allow-script="true"                   - allow <script> tags in content (stored only, not executed in WYSIWYG)
 *   data-rte-allow-iframe="false"                  - allow/disallow <iframe> tags in content
 *   data-rte-allow-interactive-tags="button|select" - allow specific interactive tags in content (supports true for all)
 */
class RichTextElementFactory extends ElementFactory {
  // Class of the "media unavailable" boxes older versions injected next to images and
  // iframes. They are no longer created — the editor keeps a broken <img> clickable and
  // shows iframes as placeholders itself — but may still sit in saved content.
  static MEDIA_FALLBACK_CLASS = 'rte-media-fallback';

  static config = {
    ...ElementFactory.config,
    profile: 'full',
    height: 'auto',
    minHeight: 250,
    maxHeight: null,
    placeholder: '',
    stickyToolbar: false,
    readOnly: false,
    allowStyle: false,
    allowScript: false,
    allowIframe: true,
    allowInteractiveTags: ''
  };

  static propertyHandlers = {
    value: {
      get(element) {
        // Read from the textarea (kept in sync by RichTextEditor on CONTENT_CHANGE)
        return element.value || '';
      },
      set(instance, newValue) {
        const html = RichTextElementFactory._cleanValue(newValue ?? '');
        if (instance._rteInstance) {
          instance._rteInstance.setContent(html);
        } else {
          // Editor not ready yet — queue the value
          instance._pendingValue = html;
        }
      }
    }
  };

  /**
   * Read the editor options a single element declares through its `data-rte-*` attributes.
   *
   * Anything not declared falls back to the value in `def`, and `placeholder` also
   * falls back to the element's own `placeholder` attribute.
   *
   * @param {HTMLElement} element - The textarea the editor replaces.
   * @param {Object} def - Default configuration for this element type.
   * @param {DOMStringMap} dataset - The element's `data-*` attributes.
   * @returns {Object} - Editor configuration for this element.
   */
  static extractCustomConfig(element, def, dataset) {
    const config = {
      profile: dataset.rteProfile || def.profile,
      height: dataset.rteHeight ? parseInt(dataset.rteHeight) : def.height,
      minHeight: dataset.rteMinHeight ? parseInt(dataset.rteMinHeight) : def.minHeight,
      maxHeight: dataset.rteMaxHeight ? parseInt(dataset.rteMaxHeight) : def.maxHeight,
      placeholder: dataset.rtePlaceholder || element.getAttribute('placeholder') || def.placeholder,
      stickyToolbar: dataset.rteSticky === 'true' || def.stickyToolbar,
      readOnly: dataset.readOnly === 'true' || element.hasAttribute('readonly') || def.readOnly
    };

    if (dataset.rteAllowStyle !== undefined) {
      config.allowStyle = dataset.rteAllowStyle === 'true';
    }
    if (dataset.rteAllowScript !== undefined) {
      config.allowScript = dataset.rteAllowScript === 'true';
    }
    if (dataset.rteAllowIframe !== undefined) {
      config.allowIframe = dataset.rteAllowIframe === 'true';
    }
    if (dataset.rteAllowInteractiveTags !== undefined) {
      config.allowInteractiveTags = dataset.rteAllowInteractiveTags === 'true'
        ? true
        : dataset.rteAllowInteractiveTags;
    }

    return config;
  }

  /**
   * Attach a RichTextEditor to the element and keep it in sync with the form.
   *
   * The editor bundle is loaded separately, so this retries every 100 ms until
   * `window.RichTextEditor` exists rather than failing when it is not ready yet.
   *
   * @param {Object} instance - Element instance carrying `element` and `config`.
   * @returns {void}
   */
  static setupElement(instance) {
    const {element, config} = instance;

    // Wait for RichTextEditor to be available (loaded via richtext-editor.min.js)
    const initEditor = () => {
      if (!window.RichTextEditor) {
        // Retry until the bundle is loaded
        setTimeout(initEditor, 100);
        return;
      }

      const profileConfig = window.RichTextEditor.getProfiles?.()?.[config.profile] || null;
      const profileOptions = profileConfig?.options || {};
      const editorConfig = {
        height: config.height,
        minHeight: config.minHeight,
        maxHeight: config.maxHeight,
        placeholder: config.placeholder,
        stickyToolbar: config.stickyToolbar,
        readOnly: config.readOnly,
        // FileBrowser integration — resolve base path relative to admin/index.html
        image: {
          fileBrowser: {
            enabled: true,
            options: {
              apiActions: RichTextElementFactory._getFileBrowserApiActions(),
              auth: {
                type: 'token',
                getToken: () => window.AuthManager?.getToken?.() || null,
                headerName: 'Authorization',
                headerFormat: 'Bearer {token}',
                credentials: 'include'
              }
            }
          }
        }
      };

      if (profileConfig) {
        editorConfig.profile = config.profile;
        editorConfig.plugins = profileConfig.plugins;
        editorConfig.toolbar = profileConfig.toolbar;
      }

      Object.keys(profileOptions).forEach(key => {
        if (editorConfig[key] === undefined) {
          editorConfig[key] = profileOptions[key];
        }
      });

      // The editor reads its initial content from the textarea
      if ('value' in element) {
        element.value = RichTextElementFactory._cleanValue(element.value);
      }

      const editor = window.RichTextEditor.create(element, editorConfig);

      instance._rteInstance = editor;

      // Apply any value that was set before the editor was ready
      if (instance._pendingValue !== undefined) {
        editor.setContent(instance._pendingValue);
        delete instance._pendingValue;
      }

      // Expose convenience methods on instance
      instance.setValue = (html) => editor.setContent(RichTextElementFactory._cleanValue(html ?? ''));
      instance.getValue = () => RichTextElementFactory._cleanValue(editor.getContent());
      instance.focus = () => editor.focus();
      instance.blur = () => editor.blur();
      instance.clear = () => editor.clear();
      instance.setReadOnly = (flag) => editor.setReadOnly(flag);
      instance.destroy = () => {
        editor.destroy();
        instance._rteInstance = null;
      };
    };

    // Add setValue immediately so FormManager can call it during data binding
    instance.setValue = (html) => {
      html = RichTextElementFactory._cleanValue(html ?? '');
      if (instance._rteInstance) {
        instance._rteInstance.setContent(html);
      } else {
        instance._pendingValue = html;
      }
    };

    // Kick off initialisation
    initEditor();

    return instance;
  }

  /**
   * Build FileBrowser API endpoint URLs relative to current page location
   * admin/index.html → ../js/components/editor/php/filebrowser.php
   */
  static _getFileBrowserApiActions() {
    // Resolve the filebrowser.php path relative to the current admin page
    // admin/ → ../ → project root → js/components/editor/php/filebrowser.php
    const scriptPath = window.location.pathname.replace(/\/[^/]*$/, '/');
    const base = scriptPath + '../js/components/editor/php/filebrowser.php';

    return {
      getPresetCategories: `${base}?action=get_preset_categories`,
      getPresets: `${base}?action=get_presets`,
      getFiles: `${base}?action=get_files`,
      getFolderTree: `${base}?action=get_folder_tree`,
      upload: `${base}?action=upload`,
      createFolder: `${base}?action=create_folder`,
      rename: `${base}?action=rename`,
      delete: `${base}?action=delete`,
      copy: `${base}?action=copy`,
      move: `${base}?action=move`
    };
  }

  /**
   * Strip media-fallback artifacts that older versions left in saved content:
   * injected fallback boxes, leftover data-rte-fallback-* flags, and the
   * display:none they put on the media they replaced. Applied to content going
   * into the editor as well as coming out, so old articles open clean.
   * @param {string} html
   * @returns {string}
   */
  static _cleanValue(html) {
    if (!html || html.indexOf('rte-fallback') === -1 && html.indexOf(RichTextElementFactory.MEDIA_FALLBACK_CLASS) === -1) {
      return html;
    }

    const template = document.createElement('template');
    template.innerHTML = html;

    const unhide = (el) => {
      if (el?.style && el.style.display === 'none') {
        el.style.display = '';
        if (!el.getAttribute('style')) {
          el.removeAttribute('style');
        }
      }
    };

    template.content
      .querySelectorAll('.' + RichTextElementFactory.MEDIA_FALLBACK_CLASS + ', [data-rte-media-fallback]')
      .forEach(el => {
        // The box was inserted right after the media it stood in for
        const media = el.previousElementSibling;
        if (media && /^(IMG|IFRAME)$/.test(media.tagName)) unhide(media);
        el.remove();
      });

    template.content
      .querySelectorAll('[data-rte-fallback-bound], [data-rte-fallback-loaded]')
      .forEach(el => {
        el.removeAttribute('data-rte-fallback-bound');
        el.removeAttribute('data-rte-fallback-loaded');
        unhide(el);
      });

    return template.innerHTML;
  }

  /**
   * Destroy the editor attached to this element and release the reference.
   *
   * Safe to call when no editor was created, and errors thrown by the editor's
   * own destroy are swallowed so teardown of the surrounding form still finishes.
   *
   * @param {Object} instance - The element instance being torn down.
   * @returns {void}
   */
  static cleanup(instance) {
    if (instance._rteInstance) {
      try {
        instance._rteInstance.destroy();
      } catch (e) {
        // ignore
      }
      instance._rteInstance = null;
    }
    super.cleanup?.(instance);
  }
}

// Register with ElementManager
if (window.ElementManager) {
  ElementManager.registerElement('richtext', RichTextElementFactory);
}

// Expose globally
window.RichTextElementFactory = RichTextElementFactory;
