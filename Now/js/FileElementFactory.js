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
    onError: null
  };

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

  static handleFileDrop(event, element) {
    const files = Array.from(event.dataTransfer.files);
    const dt = new DataTransfer();
    files.forEach(file => dt.items.add(file));
    element.files = dt.files;
    element.dispatchEvent(new Event('change'));
  }

  static async processFiles(instance, files) {
    const {element, config} = instance;
    const privateState = ElementFactory._privateState.get(element);
    const previousFiles = new Map(privateState.files || new Map());
    const newFiles = config.multiple ? new Map(previousFiles) : new Map();
    const errors = [];

    if (!files || files.length === 0) {
      return;
    }

    if (privateState) {
      privateState.error = null;
      privateState.valid = true;
    }

    for (const file of files) {
      try {
        await this.validateFile(file, config);
        newFiles.set(file.name, file);
      } catch (error) {
        errors.push(`${file.name}: ${error.message || error}`);
      }
    }

    if (errors.length === 0) {
      privateState.files = newFiles;
      FormError.clearFieldError(element.id);
      this.showPreviews(instance);
      this.updatePlaceholderVisibility(instance);

      if (typeof config.onChange === 'function') {
        config.onChange(Array.from(newFiles.values()), element);
      }

      EventManager.emit('file:change', {
        elementId: element.id,
        files: newFiles
      });
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

  static async validateFile(file, config) {
    if (file.size > config.maxFileSize) {
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

    privateState.files.forEach(file => {
      const preview = document.createElement('div');
      preview.className = 'preview-item';
      preview.dataset.fileName = file.name;

      const icon = document.createElement('span');
      icon.className = 'image-preview';

      if (file.type.startsWith('image/')) {
        const reader = new FileReader();
        reader.onload = (e) => {
          icon.style.backgroundImage = `url(${e.target.result})`;
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

      previewContainer.appendChild(preview);
    });
  }

  static showExistingFiles(instance, files) {
    const {element, previewContainer, config} = instance;
    if (!previewContainer) return;

    const isDisabled = element.disabled || (typeof element.matches === 'function' && element.matches(':disabled'));
    const canRemoveExisting = !isDisabled && !element.readOnly && config.allowRemoveExisting && config.actionUrl;

    // Collect all image URLs for gallery view
    const imageUrls = files
      .filter(f => {
        const fileInfo = this.getUrlFileInfo(f.url);
        return fileInfo && ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(fileInfo.ext);
      })
      .map(f => f.url);

    files.forEach((file, index) => {
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

      if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(fileInfo.ext)) {
        // Find index of this image in the filtered imageUrls array
        const imageIndex = imageUrls.indexOf(file.url);

        imageContainer = document.createElement('div');
        imageContainer.style.backgroundImage = `url(${file.url})`;
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

  static removeFile(instance, file) {
    const {element, config} = instance;
    const privateState = ElementFactory._privateState.get(element);

    privateState.files.delete(file.name);
    this.showPreviews(instance);
    this.updatePlaceholderVisibility(instance);

    if (privateState.files.size === 0) {
      element.value = '';
    }

    if (config.onChange) {
      config.onChange(element, Array.from(privateState.files.values()));
    }
  }

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

  static initSortable(container, config) {
    if (typeof Sortable === 'undefined') {
      console.warn('Sortable.js is required for drag & drop sorting');
      return;
    }

    new Sortable(container, {
      animation: 150,
      handle: '.drag-handle',
      draggable: '.preview-item',
      ghostClass: 'sortable-ghost',
      chosenClass: 'sortable-chosen',
      dragClass: 'sortable-drag',
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

  static updateProgressBar(container, percent) {
    const bar = container.querySelector('.progress-bar');
    const text = container.querySelector('.progress-text');
    if (bar) bar.style.width = percent + '%';
    if (text) text.textContent = percent + '%';
  }

  static camelCase(str) {
    return str.replace(/-([a-z])/g, (g) => g[1].toUpperCase());
  }

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

  static formatFileSize(bytes) {
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    if (bytes === 0) return '0 Bytes';
    const i = parseInt(Math.log(bytes) / Math.log(1024));
    return `${Math.round(bytes / Math.pow(1024, i), 2)} ${sizes[i]}`;
  }

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
