/**
 * FileElementFactory
 *
 * Builds file upload fields with previews, drag & drop, progress reporting and
 * optional reordering. Handles two kinds of file at once: newly picked ones,
 * held in a Map on the element's private state, and **existing** files already
 * stored on the server, declared through `data-files` and rendered from their
 * URLs. Existing files can be removed through `config.actionUrl` rather than by
 * clearing the input.
 *
 * With `data-crop="true"` every image picked is opened in ImageCropper first
 * and the cropped file is what the field holds and the form sends. The input
 * itself is cleared, so the uncropped original can never be submitted instead.
 * The crop options (`data-crop-aspect`, `-width`, `-shape`, `-format`,
 * `-quality`, `-min-width`) are read when a file is picked, so a form may bind
 * them after it loads.
 */
class FileElementFactory extends ElementFactory {
  static propertyHandlers = {
    placeholder: {
      get(element) {
        return 'placeholder' in element ? element.placeholder : element.getAttribute('placeholder') || '';
      },
      set(instance, newValue) {
        const {element, config} = instance;
        if (typeof newValue === 'string' && newValue.trim()) {
          const translated = Now.translate(newValue);
          // Keep config in sync so initPlaceholder/initDragDrop can re-use it
          config.placeholder = newValue;
          // Store original template for re-translation on language change
          element.dataset.i18nPlaceholder = newValue;
          // Set on the native input element
          if ('placeholder' in element) {
            element.placeholder = translated;
          } else {
            element.setAttribute('placeholder', translated);
          }
          // Update drag-drop message text
          const dropMsg = instance.dropZone?.querySelector('.file-drop-message');
          if (dropMsg) {
            dropMsg.textContent = translated;
          }
          // Update non-drag-drop placeholder span (only while still in placeholder state)
          if (instance.placeholderElement?.classList.contains('placeholder')) {
            instance.placeholderElement.textContent = translated;
          }
        }
      }
    }
  };

  /**
   * Extensions of existing (server) files shown as an image thumbnail and
   * opened in the gallery. Anything else renders as a download icon.
   */
  static imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'svg', 'ico'];

  static config = {
    ...ElementFactory.config,
    debug: false,
    maxFileSize: 10 * 1024 * 1024,
    allowedMimeTypes: [
      'image/*',
      'application/pdf',
      'application/msword',
      'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      'application/vnd.ms-excel',
      'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      'text/plain',
      'application/zip',
      'application/x-zip-compressed'
    ],
    previewContainer: null,
    placeholder: null,
    onChange: null,
    onCrop: null,
    onError: null
  };

  /**
   * Read the upload options a single element declares.
   *
   * Covers preview, download, drag & drop and the `fileReference` key used to
   * identify existing files (defaults to `id`).
   *
   * @param {HTMLElement} element - The file input being configured.
   * @param {Object} def - Default configuration for this element type.
   * @param {DOMStringMap} dataset - The element's `data-*` attributes.
   * @returns {Object} - Configuration for this element.
   */
  static extractCustomConfig(element, def, dataset) {
    return {
      multiple: element.multiple === true || def.preview === true,
      preview: dataset.preview === 'true' || def.preview === true,
      previewContainer: dataset.previewContainer || def.previewContainer || this.config.previewContainer,
      downloadEnabled: dataset.allowDownload === 'true' || def.downloadEnabled === true,
      fileReference: dataset.fileReference || def.fileReference || 'id',
      maxFileSize: parseInt(dataset.maxFileSize) || def.maxFileSize || this.config.maxFileSize,
      dragDrop: dataset.dragDrop === 'true' || def.dragDrop === true || false,
      actionUrl: dataset.actionUrl || def.actionUrl,
      actionParams: (() => {
        const raw = dataset.actionParams || '';
        if (!raw) return def.actionParams || {};
        const trimmed = raw.trim();
        if (trimmed.startsWith('{')) {
          try {return JSON.parse(trimmed);} catch (e) {return {};}
        }
        // Comma-separated URL query keys -> resolve from current URL
        const currentQuery = new URLSearchParams(window.location.search);
        const result = {};
        trimmed.split(',').map(k => k.trim()).filter(Boolean).forEach(key => {
          const value = currentQuery.get(key);
          if (value !== null) result[key] = value;
        });
        return result;
      })(),
      sortable: dataset.sortable === 'true' || def.sortable === true || false,
      allowRemoveExisting: dataset.allowRemoveExisting === 'true' || def.allowRemoveExisting === true || false,
      placeholder: dataset.placeholder || element.placeholder || def.placeholder || this.config.placeholder,
      existingFiles: this.parseExistingFiles(element) || def.existingFiles || []
    };
  }

  /**
   * Prepare a file field and its preview area.
   *
   * Picked files live in a Map on the element's private ElementFactory state,
   * keyed by filename, so re-picking the same name replaces rather than
   * duplicates it.
   *
   * @param {Object} instance - Element instance carrying `element` and `config`.
   * @returns {void}
   */
  static setupElement(instance) {
    const {element, config} = instance;

    const privateState = ElementFactory._privateState.get(element);
    if (!privateState.files) {
      privateState.files = new Map();
    }

    if (config.multiple) {
      element.multiple = true;
    }

    if (config.accept) {
      element.accept = config.accept;
    }

    // If accept wasn't provided via config but the element (HTML) has an accept attribute,
    // copy it into the config so validation uses the same rules.
    if (!config.accept && element.accept) {
      config.accept = element.accept;
    }

    let dropZone = null;
    if (config.dragDrop) {
      dropZone = this.initDragDrop(instance);
    } else {
      dropZone = element.parentElement?.parentElement;
      this.initPlaceholder(instance);
    }
    instance.dropZone = dropZone;

    let previewContainer;
    if (config.preview) {
      if (config.previewContainer) {
        previewContainer = document.querySelector(config.previewContainer);
      }
      if (!previewContainer && dropZone) {
        previewContainer = document.createElement('div');
        previewContainer.className = 'file-preview';
        dropZone.appendChild(previewContainer);
      }
      instance.previewContainer = previewContainer;

      if (previewContainer) {
        previewContainer.innerHTML = '';
      }
    }

    if (config.sortable && instance.previewContainer) {
      this.initSortable(instance.previewContainer, config);
    }

    if (config.existingFiles && config.existingFiles.length > 0) {
      this.showExistingFiles(instance, config.existingFiles);
    }

    instance.clearFiles = function() {
      return FileElementFactory.clearFiles(this);
    };

    instance.upload = function(options) {
      return FileElementFactory.upload(this, options);
    };

    instance.validateFiles = function() {
      return FileElementFactory.validateFormField(this);
    };

    return instance;
  }

  /**
   * Bind the change handler that validates and previews newly picked files.
   *
   * @param {Object} instance - Element instance to bind.
   * @returns {void}
   */
  static setupEventListeners(instance) {
    const {element, config} = instance;

    const parentHandlers = {};

    const changeHandler = (event) => {
      const files = Array.from(element.files);
      this.processFiles(instance, files);
    };

    const clickHandler = () => {
      // Allow re-selecting the same file without forcing a reset on error state
      element.value = '';
    };

    let dragHandlers = {};
    if (instance.dropZone && config.dragDrop) {
      dragHandlers = {
        dragEnter: (event) => {
          event.preventDefault();
          instance.dropZone.classList.add('drag-over');
        },

        dragLeave: (event) => {
          event.preventDefault();
          instance.dropZone.classList.remove('drag-over');
        },

        dragOver: (event) => {
          event.preventDefault();
        },

        drop: (event) => {
          event.preventDefault();
          instance.dropZone.classList.remove('drag-over');
          this.handleFileDrop(event.originalEvent, element);
        }
      };

      EventSystemManager.addHandler(instance.dropZone, 'dragenter', dragHandlers.dragEnter);
      EventSystemManager.addHandler(instance.dropZone, 'dragleave', dragHandlers.dragLeave);
      EventSystemManager.addHandler(instance.dropZone, 'dragover', dragHandlers.dragOver);
      EventSystemManager.addHandler(instance.dropZone, 'drop', dragHandlers.drop);
    }

    EventSystemManager.addHandler(element, 'change', changeHandler);
    EventSystemManager.addHandler(element, 'click', clickHandler);

    return {
      ...parentHandlers,
      change: changeHandler,
      click: clickHandler,
      ...dragHandlers
    };
  }

  /**
   * Turn the field's wrapper into a drop zone.
   *
   * @param {Object} instance - Element instance to enable dropping on.
   * @returns {void}
   */
  static initDragDrop(instance) {
    const {element, config} = instance;

    let dropZone = element.parentElement;
    if (dropZone && dropZone.classList.contains('form-control')) {
      dropZone.classList.add('file-drop-zone');
    } else {
      dropZone = document.createElement('div');
      dropZone.className = 'file-drop-zone';
      element.parentNode.insertBefore(dropZone, element);
      dropZone.appendChild(element);
    }

    const dropContent = document.createElement('div');
    dropContent.className = 'file-drop-content';

    const dropMessage = document.createElement('div');
    dropMessage.className = 'file-drop-message';
    dropMessage.textContent = Now.translate(config.placeholder || 'Drag files here or click to browse');

    dropContent.appendChild(dropMessage);
    dropZone.appendChild(dropContent);

    return dropZone;
  }

  /**
   * Get or create the placeholder shown when no file is selected.
   *
   * @param {Object} instance - Element instance to attach it to.
   * @returns {void}
   */
  static initPlaceholder(instance) {
    const {element, config} = instance;
    let placeholderElement = element.parentElement?.querySelector('.placeholder');
    if (!placeholderElement) {
      placeholderElement = document.createElement('div');
      placeholderElement.className = 'file-display placeholder';
      element.parentElement.appendChild(placeholderElement);
    }

    placeholderElement.textContent = Now.translate(config.placeholder || 'Choose file');
    instance.placeholderElement = placeholderElement;

    this.updatePlaceholderVisibility(instance);

    return placeholderElement;
  }

  /**
   * Show or hide the placeholder based on whether any file is selected.
   *
   * @param {Object} instance - Element instance to update.
   * @returns {void}
   */
  static updatePlaceholderVisibility(instance) {
    const {element, placeholderElement, config} = instance;
    if (!placeholderElement) return;

    const privateState = ElementFactory._privateState.get(element);
    const filesArray = Array.from(privateState.files?.values() || []);

    if (filesArray.length === 0) {
      // Show initial placeholder
      placeholderElement.textContent = Now.translate(config.placeholder || 'Choose file');
      placeholderElement.classList.add('placeholder');
    } else if (filesArray.length === 1) {
      // Show single file name
      placeholderElement.textContent = filesArray[0].name;
      placeholderElement.classList.remove('placeholder');
    } else {
      // Show files count
      placeholderElement.textContent = Now.translate('{count} files selected', {count: filesArray.length});
      placeholderElement.classList.remove('placeholder');
    }
  }

  /**
   * Read the existing-files list declared in `data-files`.
   *
   * JSON is tried first, with a fallback for the plainer formats the attribute
   * also accepts.
   *
   * @param {HTMLElement} element - The file input.
   * @param {Object} [contextData=null] - Surrounding data used to resolve the field.
   * @returns {Array|null} - Existing file records, or null when none are declared.
   */
  static parseExistingFiles(element, contextData = null) {
    try {
      const filesData = element.dataset.files;
      if (!filesData) return null;

      // Try to parse as JSON first (original format)
      try {
        const files = JSON.parse(filesData);
        const result = Array.isArray(files) ? files : [files];
        return result;
      } catch (jsonError) {
        // Not JSON - treat as field name reference
        // If context data is provided, try to resolve the field
        if (contextData) {
          const fieldData = this.resolveFieldData(contextData, filesData);
          if (fieldData) {
            return Array.isArray(fieldData) ? fieldData : [fieldData];
          }
        }

        // Return field reference indicator for later resolution
        return {fieldRef: filesData};
      }
    } catch (error) {
      console.warn('Invalid files data attribute:', error);
      return null;
    }
  }

  /**
   * Find a named field inside a possibly-nested data object.
   *
   * Looks at the object itself and at `data.data`, so an API response that
   * wraps its payload one level deep still resolves.
   *
   * @param {Object} data - Data to search.
   * @param {string} fieldName - Field to find.
   * @returns {*} - The field value, or null.
   */
  static resolveFieldData(data, fieldName) {
    if (!data || !fieldName) return null;

    // Handle nested data structure (e.g., data.data.avatar)
    const searchTargets = [data];
    if (data.data) {
      searchTargets.unshift(data.data);
      if (data.data.data) {
        searchTargets.unshift(data.data.data);
      }
    }

    for (const target of searchTargets) {
      if (target && typeof target === 'object' && fieldName in target) {
        return target[fieldName];
      }
    }

    return null;
  }

  /**
   * Populate existing files from surrounding context data.
   *
   * Used when `data-files` names a field rather than carrying the list inline.
   *
   * @param {Object} instance - Element instance to populate.
   * @param {Object} contextData - Data to resolve the field from.
   * @returns {void}
   */
  static setExistingFilesFromContext(instance, contextData) {
    const {element, config} = instance;
    const filesData = element.dataset.files;

    if (!filesData) return;

    // Check if we have a pending field reference
    if (config.existingFiles && config.existingFiles.fieldRef) {
      const fieldData = this.resolveFieldData(contextData, config.existingFiles.fieldRef);
      if (fieldData) {
        const files = Array.isArray(fieldData) ? fieldData : [fieldData];
        config.existingFiles = files;
        this.showExistingFiles(instance, files);
      } else {
        config.existingFiles = [];
      }
    }
  }

  /**
   * Move dropped files onto the input and fire a change event.
   *
   * Files are copied through a `DataTransfer` because `input.files` cannot be
   * assigned a plain array; the synthetic change event then runs the normal
   * pick path.
   *
   * @param {DragEvent} event - The drop event.
   * @param {HTMLInputElement} element - The file input.
   * @returns {void}
   */
  static handleFileDrop(event, element) {
    const files = Array.from(event.dataTransfer.files);
    const dt = new DataTransfer();
    files.forEach(file => dt.items.add(file));
    element.files = dt.files;
    element.dispatchEvent(new Event('change'));
  }

  /**
   * Validate a batch of picked files and merge them into the selection.
   *
   * In multiple mode the new files are added to what was already picked; in
   * single mode they replace it. Files that fail validation are collected and
   * reported rather than aborting the whole batch.
   *
   * @param {Object} instance - Element instance receiving the files.
   * @param {Array<File>} files - Files picked or dropped.
   * @returns {Promise<void>}
   */
  static async processFiles(instance, files) {
    const {element, config} = instance;
    const privateState = ElementFactory._privateState.get(element);
    const previousFiles = new Map(privateState.files || new Map());
    const newFiles = config.multiple ? new Map(previousFiles) : new Map();
    const errors = [];
    const crop = this.getCropOptions(instance);
    let taken = 0;

    if (!files || files.length === 0) {
      return;
    }

    if (privateState) {
      privateState.error = null;
      privateState.valid = true;
    }

    for (const file of files) {
      try {
        let picked = file;
        if (crop && this.isCroppable(file)) {
          // The size limit applies to the cropped file: a large photo usually crops to a small one
          await this.validateFile(file, config, {size: false});
          picked = await this.cropFile(file, crop);
          if (!picked) continue;
          this.notifyCrop(instance, picked, file);
        }
        await this.validateFile(picked, config);
        newFiles.set(picked.name, picked);
        taken++;
      } catch (error) {
        errors.push(`${file.name}: ${error.message || error}`);
      }
    }

    if (crop) {
      // The input still holds the uncropped originals: a form must never fall back to them
      element.value = '';
      // Every crop was cancelled: the selection stays as it was
      if (taken === 0 && errors.length === 0) return;
    }

    if (errors.length === 0) {
      privateState.files = newFiles;
      FormError.clearFieldError(element.id);
      this.showPreviews(instance);
      this.updatePlaceholderVisibility(instance);
      this.notifyChange(instance);
    } else {
      const errorMessage = errors.join('<br>');
      if (privateState) {
        privateState.error = errorMessage;
        privateState.valid = false;
      }
      FormError.showFieldError(element.id, errorMessage);

      privateState.files = new Map(previousFiles);
      this.showPreviews(instance);
      this.updatePlaceholderVisibility(instance);
    }
  }

  /**
   * Check one file against the size and type limits.
   *
   * @param {File} file - File to check.
   * @param {Object} config - Element config, read for `maxFileSize` and `accept`.
   * @param {Object} [options={}]
   * @param {boolean} [options.size=true] - False to check the type only (a file about to be cropped).
   * @returns {Promise<void>}
   * @throws {Error} - With a translated message when the file is rejected.
   */
  static async validateFile(file, config, {size = true} = {}) {
    if (size && file.size > config.maxFileSize) {
      const message = Now.translate('File size cannot exceed {maxsize}', {maxsize: this.formatFileSize(config.maxFileSize)});
      throw new Error(message);
    }

    if (config.accept) {
      const accepts = config.accept.split(',').map(x => x.trim());
      const valid = this.isValidFileType(file, accepts);

      if (!valid) {
        throw new Error(Now.translate('File type not allowed'));
      }
    } else if (config.allowedMimeTypes && config.allowedMimeTypes.length > 0) {
      const valid = this.isValidFileType(file, config.allowedMimeTypes);

      if (!valid) {
        throw new Error(Now.translate('File type not allowed'));
      }
    }

    return true;
  }

  /**
   * Whether a file matches one of the accepted type patterns.
   *
   * Accepts both extension form (`.csv`) and MIME form, including wildcards
   * such as `image/*`.
   *
   * @param {File} file - File to check.
   * @param {Array<string>} acceptedTypes - Patterns from the `accept` config.
   * @returns {boolean} - True when the file is an accepted type.
   */
  static isValidFileType(file, acceptedTypes) {
    return acceptedTypes.some(rawType => {
      const type = (rawType || '').trim();

      // Extension form: .csv
      if (type.startsWith('.')) {
        return file.name.toLowerCase().endsWith(type.toLowerCase());
      }

      // Wildcard extension like *.csv
      if (type.startsWith('*')) {
        // strip leading '*' characters, ensure we have a leading dot
        let ext = type.replace(/^\*+/, '');
        if (ext && !ext.startsWith('.')) ext = '.' + ext;
        if (ext) return file.name.toLowerCase().endsWith(ext.toLowerCase());
      }

      // Wildcard mime like image/*
      if (type.includes('/*')) {
        const mainType = type.split('/')[0];
        return (file.type || '').startsWith(mainType + '/');
      }

      // Exact mime match. If file.type is empty or unreliable, fall back to extension comparison
      if (file.type) {
        if (file.type === type) return true;
      } else {
        // No MIME type available — fall back to extension-only comparison
        const dotIndex = file.name.lastIndexOf('.');
        if (dotIndex !== -1) {
          const fileExt = file.name.slice(dotIndex).toLowerCase();
          if (fileExt === (type.startsWith('.') ? type.toLowerCase() : ('.' + type.toLowerCase()))) {
            return true;
          }
        }
      }

      return false;
    });
  }

  /**
   * Render previews for the currently picked files.
   *
   * Does nothing when the element has no preview container configured.
   *
   * @param {Object} instance - Element instance to render for.
   * @returns {void}
   */
  static showPreviews(instance) {
    const {previewContainer, element} = instance;
    const privateState = ElementFactory._privateState.get(element);

    if (!previewContainer) return;

    const existingPreviews = [...previewContainer.querySelectorAll('.preview-item[data-existing="true"]')];

    previewContainer.innerHTML = '';

    existingPreviews.forEach(preview => {
      previewContainer.appendChild(preview);
    });

    if (!privateState.files || !(privateState.files instanceof Map)) {
      console.warn('privateState.files is not a valid Map:', privateState.files);
      return;
    }

    const crop = this.getCropOptions(instance);

    privateState.files.forEach(file => {
      const preview = document.createElement('div');
      preview.className = 'preview-item';
      preview.dataset.fileName = file.name;

      const icon = document.createElement('span');
      icon.className = 'image-preview';

      if (file.type.startsWith('image/')) {
        const reader = new FileReader();
        reader.onload = (e) => {
          icon.style.backgroundImage = SecurityManager.cssUrl(e.target.result);
        };
        reader.readAsDataURL(file);
      } else {
        icon.classList.add(this.getFileIcon(file.name.toLowerCase()));
      }
      preview.appendChild(icon);

      const info = document.createElement('div');
      info.className = 'file-info';
      info.textContent = `${file.name} (${this.formatFileSize(file.size)})`;
      preview.appendChild(info);

      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'icon-delete';
      remove.title = Now.translate('Delete file');
      remove.onclick = (e) => {
        e.stopPropagation();
        e.preventDefault();
        this.removeFile(instance, file);
      };
      preview.appendChild(remove);

      if (crop && this.isCroppable(file) && !element.disabled && !element.readOnly) {
        const edit = document.createElement('button');
        edit.type = 'button';
        edit.className = 'icon-crop';
        edit.title = Now.translate('Crop image');
        edit.onclick = (e) => {
          e.stopPropagation();
          e.preventDefault();
          this.recropFile(instance, file);
        };
        preview.appendChild(edit);
      }

      previewContainer.appendChild(preview);
    });
  }

  /**
   * Render previews for files already stored on the server.
   *
   * The remove control only appears when the field is enabled, not read-only,
   * `allowRemoveExisting` is on **and** an `actionUrl` exists to delete
   * through — otherwise removal would have nowhere to go.
   *
   * @param {Object} instance - Element instance to render for.
   * @param {Array} files - Existing file records.
   * @returns {void}
   */
  static showExistingFiles(instance, files) {
    const {element, previewContainer, config} = instance;
    if (!previewContainer) return;

    const isDisabled = element.disabled || (typeof element.matches === 'function' && element.matches(':disabled'));
    const canRemoveExisting = !isDisabled && !element.readOnly && config.allowRemoveExisting && config.actionUrl;

    // Collect all image URLs for gallery view
    const imageUrls = files
      .filter(f => {
        const fileInfo = this.getUrlFileInfo(f.url);
        return fileInfo && this.imageExtensions.includes(fileInfo.ext) &&
          !!SecurityManager.sanitizeUrl(String(f.url), {image: true});
      })
      .map(f => f.url);

    files.forEach((file, index) => {
      // The list comes from an API: a URL sanitizeUrl refuses is not shown at all
      if (!SecurityManager.sanitizeUrl(String(file?.url ?? ''), {image: true})) return;
      const preview = document.createElement('div');
      preview.className = 'preview-item';
      preview.dataset.existing = "true";

      const referenceKey = config.fileReference || 'id';
      const referenceValue = file[referenceKey];
      if (referenceValue) {
        preview.dataset[this.camelCase(referenceKey)] = referenceValue;
      }

      if (config.sortable) {
        const dragHandle = document.createElement('span');
        dragHandle.className = 'drag-handle';
        dragHandle.title = Now.translate('Drag to reorder');
        preview.appendChild(dragHandle);
      }

      let imageContainer;
      const fileInfo = this.getUrlFileInfo(file.url);
      if (!fileInfo) return;

      if (this.imageExtensions.includes(fileInfo.ext)) {
        // Find index of this image in the filtered imageUrls array
        const imageIndex = imageUrls.indexOf(file.url);

        imageContainer = document.createElement('div');
        imageContainer.style.backgroundImage = SecurityManager.cssUrl(file.url);
        imageContainer.onclick = (e) => {
          e.stopPropagation();
          e.preventDefault();
          // Show gallery with all images, starting from clicked one
          this.getImageModal().show(imageUrls, imageIndex >= 0 ? imageIndex : 0);
        };
      } else {
        imageContainer = document.createElement('a');
        imageContainer.className = this.getFileIcon(fileInfo.ext);
        imageContainer.href = file.url;
        imageContainer.download = fileInfo.name;
      }
      imageContainer.classList.add('image-preview');
      preview.appendChild(imageContainer);

      if (canRemoveExisting) {
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'icon-delete';
        remove.title = Now.translate('Delete file');
        remove.onclick = (e) => {
          e.stopPropagation();
          e.preventDefault();
          this.deleteFile(file, preview, config);
        };
        preview.appendChild(remove);
      }

      const info = document.createElement('div');
      info.className = 'file-info';
      const displayName = file.name || fileInfo.name;
      if (file.size) {
        info.textContent = `${displayName} (${this.formatFileSize(file.size)})`;
      } else {
        info.textContent = displayName;
      }
      preview.appendChild(info);
      previewContainer.appendChild(preview);
    });
  }

  /**
   * Remove one picked file and re-render the previews.
   *
   * Files are keyed by name, so this removes the entry matching `file.name`.
   *
   * @param {Object} instance - Element instance to remove from.
   * @param {File} file - File to remove.
   * @returns {void}
   */
  static removeFile(instance, file) {
    const {element, config} = instance;
    const privateState = ElementFactory._privateState.get(element);

    privateState.files.delete(file.name);
    this.showPreviews(instance);
    this.updatePlaceholderVisibility(instance);

    if (privateState.files.size === 0) {
      element.value = '';
    }

    this.notifyChange(instance);
  }

  /**
   * Report the current selection through `onChange` and `file:change`.
   *
   * @param {Object} instance - Element instance whose selection changed.
   * @returns {void}
   */
  static notifyChange(instance) {
    const {element, config} = instance;
    const privateState = ElementFactory._privateState.get(element);

    if (typeof config.onChange === 'function') {
      config.onChange(Array.from(privateState.files.values()), element);
    }

    EventManager.emit('file:change', {
      elementId: element.id,
      files: privateState.files
    });
  }

  /**
   * The crop options in force for a field, or null when it does not crop.
   *
   * Read from the `data-crop-*` attributes each time, not once at setup, so a
   * form that binds them after loading (`data-attr="data-crop-aspect:aspect"`)
   * is obeyed. The attributes win over a `crop` object passed in JS.
   *
   * @param {Object} instance - Element instance.
   * @returns {Object|null} - Options for ImageCropper.open(), or null.
   */
  static getCropOptions(instance) {
    const {element, config} = instance;
    const dataset = element.dataset;
    const enabled = dataset.crop !== undefined ? dataset.crop === 'true' : !!config.crop;
    if (!enabled) return null;

    const options = config.crop && typeof config.crop === 'object' ? {...config.crop} : {};
    const attributes = {
      aspect: 'cropAspect',
      width: 'cropWidth',
      shape: 'cropShape',
      format: 'cropFormat',
      quality: 'cropQuality',
      minWidth: 'cropMinWidth'
    };
    Object.entries(attributes).forEach(([key, attribute]) => {
      if (dataset[attribute] !== undefined && dataset[attribute] !== '') {
        options[key] = dataset[attribute];
      }
    });
    return options;
  }

  /**
   * Whether a picked file goes through the cropper: raster images only, an
   * SVG would lose what makes it an SVG.
   *
   * @param {File} file - Picked file.
   * @returns {boolean}
   */
  static isCroppable(file) {
    const type = file?.type || '';
    return type.startsWith('image/') && type !== 'image/svg+xml';
  }

  /**
   * Open a file in ImageCropper, loading its feature bundle first if needed.
   *
   * @param {File} file - Image to crop.
   * @param {Object} options - Crop options.
   * @returns {Promise<File|null>} - The cropped file, or null when cancelled.
   */
  static async cropFile(file, options) {
    if (typeof ImageCropper === 'undefined') {
      await Now.require('ImageCropper');
    }
    return ImageCropper.open(file, options);
  }

  /**
   * Report a finished crop through `onCrop` and `file:crop`.
   *
   * @param {Object} instance - Element instance.
   * @param {File} file - The cropped file.
   * @param {File} source - The file the cropper was given.
   * @returns {void}
   */
  static notifyCrop(instance, file, source) {
    const {element, config} = instance;

    if (typeof config.onCrop === 'function') {
      config.onCrop(file, source, element);
    }

    EventManager.emit('file:crop', {
      elementId: element.id,
      file,
      source
    });
  }

  /**
   * Crop a picked file again, from its original image at its previous crop,
   * and put the result where it was in the selection.
   *
   * @param {Object} instance - Element instance.
   * @param {File} file - A file in the selection.
   * @returns {Promise<void>}
   */
  static async recropFile(instance, file) {
    const {element, config} = instance;
    const crop = this.getCropOptions(instance);
    if (!crop) return;

    try {
      const cropped = await this.cropFile(file, crop);
      if (!cropped) return;
      await this.validateFile(cropped, config);
      this.notifyCrop(instance, cropped, file);

      const privateState = ElementFactory._privateState.get(element);
      const files = new Map();
      privateState.files.forEach((value, key) => {
        if (value === file) {
          files.set(cropped.name, cropped);
        } else if (key !== cropped.name) {
          files.set(key, value);
        }
      });
      privateState.files = files;
      privateState.error = null;
      privateState.valid = true;

      FormError.clearFieldError(element.id);
      this.showPreviews(instance);
      this.updatePlaceholderVisibility(instance);
      this.notifyChange(instance);
    } catch (error) {
      FormError.showFieldError(element.id, `${file.name}: ${error.message || error}`);
    }
  }

  /**
   * Clear every picked file and reset the input.
   *
   * Existing server-side files are not affected; use `deleteFile` for those.
   *
   * @param {Object} instance - Element instance to clear.
   * @returns {void}
   */
  static clearFiles(instance) {
    const {element, previewContainer} = instance;
    const privateState = ElementFactory._privateState.get(element);

    element.value = '';
    privateState.files.clear();

    if (previewContainer) {
      const existingPreviews = previewContainer.querySelectorAll('.preview-item[data-existing="true"]');
      if (existingPreviews.length === 0) {
        previewContainer.innerHTML = '';
      } else {
        Array.from(previewContainer.querySelectorAll('.preview-item:not([data-existing="true"])')).forEach(el => el.remove());
      }
    }

    this.updatePlaceholderVisibility(instance);
  }

  /**
   * Enable drag-to-reorder on the preview list.
   *
   * Sortable is a feature bundle: when it is not loaded yet this loads it and
   * starts then; when it cannot be loaded it warns and the field still works
   * without sorting.
   *
   * @param {HTMLElement} container - Preview container.
   * @param {Object} config - Element config.
   * @returns {void}
   */
  static initSortable(container, config) {
    if (typeof Sortable === 'undefined') {
      // Sortable is a feature bundle: load it, then start
      if (window.Now?.require) {
        Now.require('Sortable')
          .then(() => this.initSortable(container, config))
          .catch(() => console.warn('Sortable.js is required for drag & drop sorting'));
      } else {
        console.warn('Sortable.js is required for drag & drop sorting');
      }
      return;
    }

    new Sortable(container, {
      handle: '.drag-handle',
      draggable: '.preview-item',
      onStart: (evt) => {
        container.classList.add('sorting');
      },
      onEnd: async (evt) => {
        container.classList.remove('sorting');

        if (!config.actionUrl) return;

        const referenceKey = config.fileReference || 'id';
        const items = Array.from(container.querySelectorAll('.preview-item[data-existing="true"]'));
        const order = items.map(item => {
          const referenceValue = item.dataset[this.camelCase(referenceKey)];
          return {
            [referenceKey]: referenceValue,
            position: items.indexOf(item)
          };
        }).filter(item => item[referenceKey]);

        if (order.length === 0) return;

        try {
          const payload = {
            action: 'sort',
            order: order,
            ...(config.actionParams || {})
          };

          const apiService = window.ApiService || window.Now?.getManager?.('api');
          let result;
          if (apiService?.post) {
            result = await apiService.post(config.actionUrl, payload);
          } else {
            result = await simpleFetch.post(config.actionUrl, payload);
          }

          if (result.status === 200) {
            if (window.NotificationManager) {
              NotificationManager.success('Saved successfully');
            }
          }
        } catch (error) {
          ErrorManager.handle(error, {
            context: 'FileElementFactory.initSortable',
            type: 'error:file',
            data: {container, config},
            notify: true
          });
          this.revertOrder(container, evt.oldIndex, evt.newIndex);
        }
      }
    });
  }

  /**
   * Put a dragged preview back where it started.
   *
   * Used when persisting the new order fails, so the UI does not show an order
   * the server did not accept.
   *
   * @param {HTMLElement} container - Preview container.
   * @param {number} oldIndex - Index the item came from.
   * @param {number} newIndex - Index it was dropped at.
   * @returns {void}
   */
  static revertOrder(container, oldIndex, newIndex) {
    const items = Array.from(container.querySelectorAll('.preview-item[data-existing="true"]'));
    const item = items[newIndex];

    if (oldIndex < newIndex) {
      container.insertBefore(item, items[oldIndex]);
    } else {
      const target = items[oldIndex + 1];
      container.insertBefore(item, target);
    }
  }

  /**
   * Delete an existing server-side file, after asking the user to confirm.
   *
   * @param {Object} file - The existing file record.
   * @param {HTMLElement} preview - Its preview element, removed on success.
   * @param {Object} config - Element config, read for `actionUrl`.
   * @returns {Promise<boolean>} - True when the file was deleted.
   */
  static async deleteFile(file, preview, config) {
    try {
      const confirmed = await DialogManager.confirm(
        Now.translate('Are you sure you want to delete this item?'),
        Now.translate('Confirm Delete')
      );
      if (!confirmed) return;

      const referenceValue = file[config.fileReference];
      if (!referenceValue) {
        throw new Error(`Missing reference value: ${config.fileReference}`);
      }

      const payload = {
        action: 'delete',
        [config.fileReference]: referenceValue,
        ...(config.actionParams || {})
      };

      const apiService = window.ApiService || window.Now?.getManager?.('api');
      let result;
      if (apiService?.post) {
        result = await apiService.post(config.actionUrl, payload);
      } else {
        result = await simpleFetch.post(config.actionUrl, payload);
      }

      // Check for success (status 2xx or success flag)
      const isSuccess = (result.success !== false) &&
        (result.status >= 200 && result.status < 300);

      if (isSuccess) {
        // 1. First, remove the preview element
        preview.remove();

        // 2. Trigger onRemoveExisting callback if defined
        if (typeof config.onRemoveExisting === 'function') {
          config.onRemoveExisting(file);
        }

        // 3. Check if response has actions for ResponseHandler (e.g., redirect, notification)
        const responseData = result.data?.data || result.data || result;
        if (responseData.actions && Array.isArray(responseData.actions)) {
          await ResponseHandler.process(responseData);
        } else {
          // 4. No actions from server - show default notification
          NotificationManager.success(result.message || 'Item deleted successfully');
        }
      } else {
        // Handle error response
        NotificationManager.error(result.message || 'Failed to delete item');
      }
    } catch (error) {
      ErrorManager.handle(error, {
        context: 'FileElementFactory.deleteFile',
        type: 'error:file',
        data: {file, preview, config},
        notify: true
      });
    }
  }

  /**
   * Validate the field for form submission.
   *
   * A required field counts as filled when the picked-files Map has entries,
   * not when `input.value` is set — the visible input is cleared once files are
   * taken into the Map.
   *
   * @param {Object} instance - Element instance to validate.
   * @returns {boolean|string} - True when valid, or the message to show.
   */
  static validateFormField(instance) {
    const {element} = instance;
    const privateState = ElementFactory._privateState.get(element);

    if (element.required && (!privateState.files || privateState.files.size === 0)) {
      const message = Now.translate('File is required');
      FormError.showFieldError(element.id, message);
      return false;
    }

    return true;
  }

  /**
   * Upload the selected files.
   *
   * Returns a failure result rather than throwing when nothing is selected, so
   * a caller can treat "nothing to do" the same as any other outcome.
   *
   * @param {Object} instance - Element instance whose files to upload.
   * @param {Object} [options={}] - Per-call upload options.
   * @returns {Promise<Object>} - `{success, message, ...}` describing the result.
   */
  static async upload(instance, options = {}) {
    const {element, config} = instance;
    const privateState = ElementFactory._privateState.get(element);

    if (privateState.files.size === 0 && element.files.length === 0) {
      return {success: false, message: Now.translate('No files selected')};
    }

    try {
      const formData = new FormData();

      if (privateState.files.size > 0) {
        Array.from(privateState.files.values()).forEach((file, index) => {
          formData.append(`file${index}`, file);
        });
      } else {
        Array.from(element.files).forEach((file, index) => {
          formData.append(`file${index}`, file);
        });
      }

      if (options.data) {
        Object.entries(options.data).forEach(([key, value]) => {
          formData.append(key, value);
        });
      }

      const url = options.url || config.actionUrl;
      if (!url) {
        throw new Error('Upload URL not specified');
      }

      const progressContainer = this.createProgressElement(element);
      progressContainer.style.display = 'block';

      const xhr = new XMLHttpRequest();
      xhr.upload.addEventListener('progress', (e) => {
        if (e.lengthComputable) {
          const percent = Math.round((e.loaded / e.total) * 100);
          this.updateProgressBar(progressContainer, percent);
        }
      });

      return new Promise((resolve, reject) => {
        xhr.onload = () => {
          progressContainer.style.display = 'none';
          if (xhr.status >= 200 && xhr.status < 300) {
            try {
              const result = JSON.parse(xhr.responseText);
              resolve(result);
            } catch (e) {
              resolve({success: true, message: 'Upload completed'});
            }
          } else {
            reject(new Error('Upload failed'));
          }
        };

        xhr.onerror = () => {
          progressContainer.style.display = 'none';
          reject(new Error('Network error'));
        };

        xhr.open('POST', url);
        Now.applyRequestLanguageToXhr(xhr);
        xhr.send(formData);
      });

    } catch (error) {
      ErrorManager.handle(error, {
        context: 'FileElementFactory.upload',
        type: 'error:file',
        data: {instance, options},
        notify: true
      });
      return {success: false, message: error.message || 'Upload failed'};
    }
  }

  /**
   * Get or create the upload progress bar next to the field.
   *
   * Reuses an existing bar when one is already there, so repeated uploads do
   * not stack progress elements.
   *
   * @param {HTMLElement} element - The file input.
   * @returns {HTMLElement} - The progress container.
   */
  static createProgressElement(element) {
    let progressContainer = element.parentElement.querySelector('.upload-progress');
    if (!progressContainer) {
      progressContainer = document.createElement('div');
      progressContainer.className = 'upload-progress';
      progressContainer.innerHTML = `
        <div class="progress">
          <div class="progress-bar" style="width:0%"></div>
        </div>
        <div class="progress-text">0%</div>
      `;
      element.parentElement.appendChild(progressContainer);
    }
    return progressContainer;
  }

  /**
   * Set the progress bar's width and label.
   *
   * @param {HTMLElement} container - Progress container from `createProgressElement`.
   * @param {number} percent - Completion percentage.
   * @returns {void}
   */
  static updateProgressBar(container, percent) {
    const bar = container.querySelector('.progress-bar');
    const text = container.querySelector('.progress-text');
    if (bar) bar.style.width = percent + '%';
    if (text) text.textContent = percent + '%';
  }

  /**
   * Convert a dashed attribute name to its camelCase `dataset` key.
   *
   * @param {string} str - Dashed name, e.g. `preview-container`.
   * @returns {string} - camelCase name, e.g. `previewContainer`.
   */
  static camelCase(str) {
    return str.replace(/-([a-z])/g, (g) => g[1].toUpperCase());
  }

  /**
   * Derive a filename and extension from a URL.
   *
   * Query strings and fragments are stripped before parsing.
   *
   * @param {string} url - File URL.
   * @returns {Object|null} - File info, or null when the URL is unusable.
   */
  static getUrlFileInfo(url) {
    if (typeof url !== 'string' || url.trim() === '') {
      return null;
    }

    const cleanUrl = url.split('#')[0].split('?')[0];
    const name = cleanUrl.split('/').pop();
    if (!name) {
      return null;
    }

    const dotIndex = name.lastIndexOf('.');
    const ext = dotIndex > -1 ? name.slice(dotIndex + 1).toLowerCase() : '';

    return {
      cleanUrl,
      name,
      ext
    };
  }

  /**
   * Pick an icon class from a filename's extension.
   *
   * Query strings and fragments are stripped first, so a URL such as
   * `doc.pdf?v=2` still resolves to the PDF icon.
   *
   * @param {string} file - Filename or URL.
   * @returns {string} - Icon class name.
   */
  static getFileIcon(file) {
    const ext = (typeof file === 'string' ? file : '').split('#')[0].split('?')[0].split('.').pop().toLowerCase();
    const icons = {
      pdf: 'icon-pdf',
      doc: 'icon-word',
      docx: 'icon-word',
      xls: 'icon-excel',
      xlsx: 'icon-excel',
      zip: 'icon-zip',
      rar: 'icon-zip'
    };
    return icons[ext] || 'icon-file';
  }

  /**
   * Render a byte count as human-readable text.
   *
   * @param {number} bytes - Size in bytes.
   * @returns {string} - e.g. `2 MB`; `0 Bytes` for zero.
   */
  static formatFileSize(bytes) {
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    if (bytes === 0) return '0 Bytes';
    const i = parseInt(Math.log(bytes) / Math.log(1024));
    return `${Math.round(bytes / Math.pow(1024, i), 2)} ${sizes[i]}`;
  }

  /**
   * The shared MediaViewer used to preview images full-size.
   *
   * Created lazily and reused, so every file field on the page shares one
   * viewer instance.
   *
   * @returns {Object} - The MediaViewer instance.
   */
  static getImageModal() {
    if (!this._imageModal) {
      this._imageModal = new MediaViewer({
        thumbnails: {
          enabled: true,
        }
      });
    }
    return this._imageModal;
  }

  /**
   * Tear the field down: unbind handlers and remove the drop zone.
   *
   * @param {Object} instance - Element instance being torn down.
   * @returns {void}
   */
  static cleanup(instance) {
    const {element, dropZone} = instance;

    EventSystemManager.removeElementHandlers(element);

    if (dropZone) {
      EventSystemManager.removeElementHandlers(dropZone);
    }

    return super.cleanup(instance);
  }
}

ElementManager.registerElement('file', FileElementFactory);

// Expose globally
window.FileElementFactory = FileElementFactory;
