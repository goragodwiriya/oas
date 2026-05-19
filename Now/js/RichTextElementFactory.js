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
        const html = newValue ?? '';
        if (instance._rteInstance) {
          instance._rteInstance.setContent(html);
        } else {
          // Editor not ready yet — queue the value
          instance._pendingValue = html;
        }
      }
    }
  };

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

      const editor = window.RichTextEditor.create(element, editorConfig);

      instance._rteInstance = editor;

      const syncMediaFallbacks = () => {
        const contentElement = editor.contentArea?.getElement?.();
        if (contentElement) {
          RichTextElementFactory._decorateMediaFallbacks(contentElement);
        }
      };

      instance._syncMediaFallbacks = syncMediaFallbacks;

      editor.events?.on?.('content:set', syncMediaFallbacks);
      editor.events?.on?.('content:change', syncMediaFallbacks);

      // Apply any value that was set before the editor was ready
      if (instance._pendingValue !== undefined) {
        editor.setContent(instance._pendingValue);
        delete instance._pendingValue;
      }

      syncMediaFallbacks();

      // Expose convenience methods on instance
      instance.setValue = (html) => editor.setContent(html ?? '');
      instance.getValue = () => editor.getContent();
      instance.focus = () => editor.focus();
      instance.blur = () => editor.blur();
      instance.clear = () => editor.clear();
      instance.setReadOnly = (flag) => editor.setReadOnly(flag);
      instance.destroy = () => {
        editor.destroy();
        instance._rteInstance = null;
        instance._syncMediaFallbacks = null;
      };
    };

    // Add setValue immediately so FormManager can call it during data binding
    instance.setValue = (html) => {
      if (instance._rteInstance) {
        instance._rteInstance.setContent(html ?? '');
      } else {
        instance._pendingValue = html ?? '';
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

  static _decorateMediaFallbacks(root) {
    if (!root?.querySelectorAll) {
      return;
    }

    root.querySelectorAll('img').forEach(img => {
      RichTextElementFactory._attachImageFallback(img);
    });

    root.querySelectorAll('iframe').forEach(iframe => {
      RichTextElementFactory._attachIframeFallback(iframe);
    });
  }

  static _attachImageFallback(img) {
    if (!img || img.dataset.rteFallbackBound === 'true') {
      return;
    }

    img.dataset.rteFallbackBound = 'true';

    const showFallback = () => {
      const fallback = RichTextElementFactory._ensureMediaFallback(img, {
        kind: 'image',
        label: img.getAttribute('alt') || img.getAttribute('title') || img.getAttribute('src') || 'Image content'
      });

      img.style.display = 'none';
      fallback.hidden = false;
      fallback.setAttribute('aria-hidden', 'false');
    };

    const hideFallback = () => {
      const fallback = RichTextElementFactory._getMediaFallback(img);
      img.style.display = '';
      if (fallback) {
        fallback.hidden = true;
        fallback.setAttribute('aria-hidden', 'true');
      }
    };

    img.addEventListener('error', showFallback);
    img.addEventListener('load', hideFallback);

    if (img.complete) {
      if (img.naturalWidth > 0) {
        hideFallback();
      } else {
        showFallback();
      }
    }
  }

  static _attachIframeFallback(iframe) {
    if (!iframe || iframe.dataset.rteFallbackBound === 'true') {
      return;
    }

    iframe.dataset.rteFallbackBound = 'true';

    const fallback = RichTextElementFactory._ensureMediaFallback(iframe, {
      kind: 'embed',
      label: iframe.getAttribute('title') || iframe.getAttribute('src') || 'Embedded content'
    });

    const markLoaded = () => {
      iframe.dataset.rteFallbackLoaded = 'true';
      fallback.hidden = true;
      fallback.setAttribute('aria-hidden', 'true');
      iframe.style.display = '';
    };

    const showFallback = () => {
      if (iframe.dataset.rteFallbackLoaded === 'true') {
        return;
      }
      iframe.style.display = 'none';
      fallback.hidden = false;
      fallback.setAttribute('aria-hidden', 'false');
    };

    iframe.addEventListener('load', markLoaded, {once: true});
    setTimeout(showFallback, 1800);
  }

  static _ensureMediaFallback(mediaElement, {kind, label}) {
    let fallback = RichTextElementFactory._getMediaFallback(mediaElement);
    if (!fallback) {
      fallback = document.createElement('div');
      fallback.className = RichTextElementFactory.MEDIA_FALLBACK_CLASS;
      fallback.setAttribute('contenteditable', 'false');
      fallback.setAttribute('data-rte-media-fallback', kind);
      fallback.hidden = true;
      mediaElement.insertAdjacentElement('afterend', fallback);
    }

    fallback.innerHTML = `
      <div style="border:1px dashed #cbd5e1;border-radius:8px;padding:12px 14px;background:#f8fafc;color:#475569;font-size:13px;line-height:1.4;display:flex;flex-direction:column;gap:4px;">
        <strong style="color:#0f172a;">${kind === 'image' ? 'Image unavailable' : 'Embedded content unavailable'}</strong>
        <span>There is media content here${label ? `: ${RichTextElementFactory._escapeHtml(label)}` : ''}</span>
      </div>
    `;

    return fallback;
  }

  static _getMediaFallback(mediaElement) {
    const next = mediaElement?.nextElementSibling;
    if (next?.classList?.contains(RichTextElementFactory.MEDIA_FALLBACK_CLASS)) {
      return next;
    }
    return null;
  }

  static _escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  static cleanup(instance) {
    if (instance._rteInstance) {
      try {
        instance._rteInstance.destroy();
      } catch (e) {
        // ignore
      }
      instance._rteInstance = null;
    }
    instance._syncMediaFallbacks = null;
    super.cleanup?.(instance);
  }
}

// Register with ElementManager
if (window.ElementManager) {
  ElementManager.registerElement('richtext', RichTextElementFactory);
}

// Expose globally
window.RichTextElementFactory = RichTextElementFactory;
