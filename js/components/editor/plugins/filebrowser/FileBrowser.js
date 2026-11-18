/**
 * FileBrowser - ระบบจัดการไฟล์แบบ Modal สำหรับการเลือกไฟล์
 * รองรับการแสดงผลเป็น 2 แท็บ: ไฟล์ที่เตรียมไว้ และ File Browser
 * มีฟังก์ชันอัปโหลด สร้างโฟลเดอร์ เปลี่ยนชื่อ และลบไฟล์
 *
 * @author Goragod Wiriya
 * @version 1.0
 */
class FileBrowser {
  constructor(options = {}) {
    this.options = {
      apiActions: {
        getPresetCategories: '/file-browser/get_preset_categories',
        getPresets: '/file-browser/get_presets',
        getFiles: '/file-browser/get_files',
        getFolderTree: '/file-browser/get_folder_tree',
        upload: '/file-browser/upload',
        createFolder: '/file-browser/create_folder',
        rename: '/file-browser/rename',
        delete: '/file-browser/delete',
        copy: '/file-browser/copy',
        move: '/file-browser/move'
      },
      requestTransformer: null,
      responseTransformer: null,
      showPresetTab: true,
      presetTabName: 'Prepared file',
      browserTabName: 'File management',
      allowedFileTypes: window.CONFIG?.FILE_UPLOAD?.ALLOWED_FILE_TYPES || 'image/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt',
      thumbnailSize: 120,
      maxFileSize: window.CONFIG?.FILE_UPLOAD?.MAX_FILE_SIZE || 5 * 1024 * 1024,
      onSelect: null,
      onClose: null,
      onError: null,
      activeTab: 1,
      multiSelect: false,
      customContextMenuItems: [],
      // Storage (server config 'storages', e.g. 'image' / 'file') to open in —
      // null = the server's default — and the storage ids to offer as tabs
      // (null = every storage the server has)
      storage: null,
      storages: null,
      ...options
    };
    // Upload filter given by the caller (e.g. 'image/*') rather than the default
    this.explicitFileTypes = Object.prototype.hasOwnProperty.call(options, 'allowedFileTypes');

    // Use the same i18n as the rest of the app (admin/designer may load FileBrowser
    // without ever constructing RichTextEditor — do not leave a no-op stub in place).
    if (typeof window.Now?.translate === 'function') {
      window.translate = (key, params) => window.Now.translate(key, params);
    } else if (!window.translate) {
      window.translate = (key) => key;
    }

    this.currentPath = '/';
    this.storage = this.options.storage || null;
    // [{id, name, extensions}] reported by the server, filtered by options.storages
    this.storageList = null;
    this.currentPresetCategory = null;
    // null = unknown, false = prepared folder missing on server (tab hidden)
    this.presetAvailable = null;
    this.selectedFiles = [];
    this.clipboardFile = null;
    this.clipboardAction = null;
    this.searchTerm = '';
    this.sortBy = 'name';
    this.sortDir = 'asc';
    this.viewMode = 'grid';
    this.isLoading = false;
    this.breadcrumbs = [{name: 'Home', path: '/'}];

    this.fileIcons = {
      'default': 'icon-file',
      'folder': 'icon-folder',
      'image': 'icon-image',
      'pdf': 'icon-pdf',
      'doc': 'icon-word',
      'docx': 'icon-word',
      'xls': 'icon-excel',
      'xlsx': 'icon-excel',
      'ppt': 'icon-ppt',
      'pptx': 'icon-ppt',
      'txt': 'icon-document',
      'zip': 'icon-zip',
      'rar': 'icon-zip',
      'mp3': 'icon-song',
      'mp4': 'icon-video',
      'mov': 'icon-video'
    };

    // Image extensions for thumbnail detection
    this.imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico', 'bmp'];

    this.bindMethods();
    this.createDOMElements();
    this.addEventListeners();
  }

  /**
   * Delay a reload so typing a word fires one request instead of one per
   * keystroke — each request lists the whole folder server-side.
   * @param {() => void} fn
   */
  debouncedReload(fn) {
    clearTimeout(this._searchTimer);
    this._searchTimer = setTimeout(fn, 250);
  }

  /**
   * Check if extension is an image type
   */
  isImageExtension(ext) {
    if (!ext) return false;
    return this.imageExtensions.includes(ext.toLowerCase().replace('.', ''));
  }

  /**
   * Escape string for safe HTML insertion
   * @param {string} str
   * @returns {string}
   */
  escapeHtml(str) {
    if (typeof str !== 'string') return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  }

  /**
   * Escape string for use inside a CSS attribute selector value.
   * Wraps in quotes and escapes backslashes and quotes.
   * @param {string} str
   * @returns {string}
   */
  cssEscape(str) {
    if (typeof str !== 'string') return '""';
    return '"' + str.replace(/\\/g, '\\\\').replace(/"/g, '\\"') + '"';
  }

  bindMethods() {
    this.open = this.open.bind(this);
    this.close = this.close.bind(this);
    this.loadFiles = this.loadFiles.bind(this);
    this.loadPresets = this.loadPresets.bind(this);
    this.loadPresetCategories = this.loadPresetCategories.bind(this);
    this.switchTab = this.switchTab.bind(this);
    this.selectFile = this.selectFile.bind(this);
    this.uploadFiles = this.uploadFiles.bind(this);
    this.createFolder = this.createFolder.bind(this);
    this.renameFile = this.renameFile.bind(this);
    this.deleteFile = this.deleteFile.bind(this);
    this.showContextMenu = this.showContextMenu.bind(this);
    this.hideContextMenu = this.hideContextMenu.bind(this);
    this.navigateToFolder = this.navigateToFolder.bind(this);
    this.search = this.search.bind(this);
    this.sort = this.sort.bind(this);
    this.confirmSelection = this.confirmSelection.bind(this);
    this.pasteFromClipboard = this.pasteFromClipboard.bind(this);
    this.changeViewMode = this.changeViewMode.bind(this);
    this.handleEscapeKey = this.handleEscapeKey.bind(this);
    this.handleDragOver = this.handleDragOver.bind(this);
    this.handleDrop = this.handleDrop.bind(this);
    this.updateStatus = this.updateStatus.bind(this);
    this.makeApiRequest = this.makeApiRequest.bind(this);
    this.uploadFilesWithApi = this.uploadFilesWithApi.bind(this);
  }

  async makeApiRequest(endpoint, data, method = 'POST') {
    try {
      let endpointUrl = this.options.apiActions[endpoint] || endpoint;
      let requestData = data;

      // Every request names the storage it works on
      if (this.storage) {
        if (method === 'GET') {
          endpointUrl += (endpointUrl.includes('?') ? '&' : '?') + 'storage=' + encodeURIComponent(this.storage);
        } else if (requestData && typeof requestData === 'object' && !Array.isArray(requestData)) {
          requestData = {storage: this.storage, ...requestData};
        }
      }

      if (typeof this.options.requestTransformer === 'function') {
        requestData = this.options.requestTransformer(endpoint, data);
      }

      const fetchOptions = {
        method,
        headers: {
          'Content-Type': 'application/json',
          // Custom header enables server-side CSRF validation for cookie auth
          'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'include'
      };

      // Include CSRF token if available
      const csrfToken = this.options.csrfToken
        || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
        || document.querySelector('input[name="token"]')?.value
        || null;
      if (csrfToken) {
        fetchOptions.headers['X-CSRF-Token'] = csrfToken;
      }

      if (method !== 'GET' && requestData) {
        fetchOptions.body = JSON.stringify(requestData);
      }

      const requestOptions = Now.applyRequestLanguage(fetchOptions);

      const response = await fetch(endpointUrl, requestOptions);

      if (response.status === 401 || response.status === 403) {
        throw new Error('Authentication required');
      }

      if (!response.ok) {
        const errorData = await response.json().catch(() => ({}));
        throw new Error(errorData.message || `Request failed with status ${response.status}`);
      }

      let result = await response.json();

      if (typeof this.options.responseTransformer === 'function') {
        result = this.options.responseTransformer(endpoint, result);
      }

      return result;
    } catch (error) {
      console.error('API request error:', error);
      this.updateStatus('Error: ' + (error.message || 'Unknown error'));
      if (this.options.onError) {
        this.options.onError(error);
      }
      throw error;
    }
  }

  async uploadFilesWithApi(files, path) {
    try {
      const validFiles = Array.from(files).filter(file => {
        if (file.size > this.options.maxFileSize) {
          this.logSecurityIssue(`File too large: ${this.sanitizeFileName(file.name)}`);
          return false;
        }
        if (!this.isAllowedFileType(file)) {
          this.logSecurityIssue(`File type not allowed: ${this.sanitizeFileName(file.name)}`);
          return false;
        }
        return true;
      });

      if (validFiles.length === 0) {
        this.updateStatus('No valid files to upload');
        return {success: false, message: 'No valid files to upload'};
      }

      const endpoint = this.options.apiActions.upload || '/file-browser/upload';
      const formData = new FormData();
      formData.append('path', path || this.currentPath);
      if (this.storage) {
        formData.append('storage', this.storage);
      }
      validFiles.forEach(file => {
        formData.append(this.options.fileFieldName || 'files[]', file);
      });

      const fetchOpts = {
        method: 'POST',
        body: formData,
        // Custom header enables server-side CSRF validation for cookie auth
        headers: {'X-Requested-With': 'XMLHttpRequest'},
        credentials: 'include'
      };

      // Include CSRF token for upload requests
      const csrfToken = this.options.csrfToken
        || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
        || document.querySelector('input[name="token"]')?.value
        || null;
      if (csrfToken) {
        fetchOpts.headers['X-CSRF-Token'] = csrfToken;
      }

      const requestOptions = Now.applyRequestLanguage(fetchOpts);

      const response = await fetch(endpoint, requestOptions);

      let result = await response.json();

      if (typeof this.options.responseTransformer === 'function') {
        result = this.options.responseTransformer('upload', result);
      }

      return result;
    } catch (error) {
      console.error('File upload error:', error);
      if (this.options.onError) {
        this.options.onError(error);
      }
      return {success: false, message: error.message || 'Upload failed'};
    }
  }

  /**
   * สร้าง DOM elements
   */
  createDOMElements() {
    this.overlay = document.createElement('div');
    this.overlay.className = 'file-browser-overlay';

    this.modal = document.createElement('div');
    this.modal.className = 'file-browser-modal';

    const header = document.createElement('div');
    header.className = 'file-browser-header';

    const title = document.createElement('h3');
    title.textContent = window.translate('Select the file');

    const closeButton = document.createElement('button');
    closeButton.type = 'button';
    closeButton.className = 'file-browser-close';
    closeButton.innerHTML = '&times;';
    closeButton.title = window.translate('Close');
    closeButton.addEventListener('click', this.close);

    header.appendChild(title);
    header.appendChild(closeButton);

    const tabNav = document.createElement('div');
    tabNav.className = 'file-browser-tabs';
    this.tabNav = tabNav;

    if (this.options.showPresetTab) {
      const presetTab = document.createElement('button');
      presetTab.type = 'button';
      presetTab.className = 'file-browser-tab active';
      presetTab.textContent = window.translate(this.options.presetTabName);
      presetTab.dataset.tab = 'preset';
      presetTab.addEventListener('click', () => this.switchTab('preset'));
      tabNav.appendChild(presetTab);
    }

    const browserTab = document.createElement('button');
    browserTab.type = 'button';
    browserTab.className = 'file-browser-tab';
    browserTab.textContent = window.translate(this.options.browserTabName);
    browserTab.dataset.tab = 'browser';
    browserTab.addEventListener('click', () => this.switchTab('browser'));
    tabNav.appendChild(browserTab);

    this.content = document.createElement('div');
    this.content.className = 'file-browser-content';

    this.presetContent = document.createElement('div');
    this.presetContent.className = 'file-browser-tab-content active file-browser-preset-readonly';
    this.presetContent.id = 'preset-content';

    const categoriesSidebar = document.createElement('div');
    categoriesSidebar.className = 'file-browser-categories';
    this.presetContent.appendChild(categoriesSidebar);

    const presetFilesContainer = document.createElement('div');
    presetFilesContainer.className = 'file-browser-files-container';

    const presetSearch = document.createElement('div');
    presetSearch.className = 'file-browser-search';

    const presetSearchInput = document.createElement('input');
    presetSearchInput.type = 'text';
    presetSearchInput.placeholder = window.translate('Search');
    presetSearchInput.addEventListener('input', (e) => {
      this.searchTerm = e.target.value.trim();
      this.debouncedReload(() => this.loadPresets());
    });

    const presetSearchIcon = document.createElement('span');
    presetSearchIcon.className = 'search-icon icon-search';

    presetSearch.appendChild(presetSearchInput);
    presetSearch.appendChild(presetSearchIcon);

    const presetToolbar = document.createElement('div');
    presetToolbar.className = 'file-browser-toolbar';

    presetToolbar.appendChild(presetSearch);

    const presetViewOptions = document.createElement('div');
    presetViewOptions.className = 'view-options';

    const gridViewButton = document.createElement('button');
    gridViewButton.type = 'button';
    gridViewButton.className = 'view-option active';
    gridViewButton.innerHTML = '<span class="icon-grid"></span>';
    gridViewButton.title = window.translate('Grid view');
    gridViewButton.addEventListener('click', () => this.changeViewMode('grid'));

    const listViewButton = document.createElement('button');
    listViewButton.type = 'button';
    listViewButton.className = 'view-option';
    listViewButton.innerHTML = '<span class="icon-listview"></span>';
    listViewButton.title = window.translate('List view');
    listViewButton.addEventListener('click', () => this.changeViewMode('list'));

    presetViewOptions.appendChild(gridViewButton);
    presetViewOptions.appendChild(listViewButton);

    presetToolbar.appendChild(presetViewOptions);

    const presetFiles = document.createElement('div');
    presetFiles.className = 'file-browser-files grid-view';

    presetFilesContainer.appendChild(presetToolbar);
    presetFilesContainer.appendChild(presetFiles);

    this.presetContent.appendChild(presetFilesContainer);

    this.browserContent = document.createElement('div');
    this.browserContent.className = 'file-browser-tab-content';
    this.browserContent.id = 'browser-content';

    const folderTreeContainer = document.createElement('div');
    folderTreeContainer.className = 'file-browser-sidebar';

    const folderTreeTitle = document.createElement('div');
    folderTreeTitle.className = 'sidebar-title';
    folderTreeTitle.textContent = window.translate('Folder');

    this.folderTree = document.createElement('div');
    this.folderTree.className = 'folder-tree';

    folderTreeContainer.appendChild(folderTreeTitle);
    folderTreeContainer.appendChild(this.folderTree);

    this.browserContent.appendChild(folderTreeContainer);

    const browserMainContent = document.createElement('div');
    browserMainContent.className = 'file-browser-main-content';

    const browserToolbar = document.createElement('div');
    browserToolbar.className = 'file-browser-toolbar';

    const breadcrumbsContainer = document.createElement('div');
    breadcrumbsContainer.className = 'file-browser-breadcrumbs';

    browserToolbar.appendChild(breadcrumbsContainer);

    const actionsContainer = document.createElement('div');
    actionsContainer.className = 'file-browser-actions';

    const uploadButton = document.createElement('button');
    uploadButton.type = 'button';
    uploadButton.className = 'action-button';
    uploadButton.innerHTML = `<span class="icon-upload"></span> ${window.translate('Upload')}`;
    uploadButton.addEventListener('click', () => {
      const fileInput = document.createElement('input');
      fileInput.type = 'file';
      fileInput.multiple = true;
      fileInput.accept = this.getAcceptTypes();
      fileInput.style.display = 'none';
      document.body.appendChild(fileInput);

      fileInput.addEventListener('change', (e) => {
        if (e.target.files.length > 0) {
          this.uploadFiles(e.target.files);
        }
        document.body.removeChild(fileInput);
      });

      fileInput.click();
    });

    const newFolderButton = document.createElement('button');
    newFolderButton.type = 'button';
    newFolderButton.className = 'action-button';
    newFolderButton.innerHTML = `<span class="icon-create-folder"></span> ${window.translate('Create a folder')}`;
    newFolderButton.addEventListener('click', this.createFolder);

    actionsContainer.appendChild(uploadButton);
    actionsContainer.appendChild(newFolderButton);

    browserToolbar.appendChild(actionsContainer);

    const searchAndViewContainer = document.createElement('div');
    searchAndViewContainer.className = 'search-view-container';

    const browserSearch = document.createElement('div');
    browserSearch.className = 'file-browser-search';

    const browserSearchInput = document.createElement('input');
    browserSearchInput.type = 'text';
    browserSearchInput.placeholder = window.translate('Search');
    browserSearchInput.addEventListener('input', (e) => {
      this.searchTerm = e.target.value.trim();
      this.debouncedReload(() => this.loadFiles());
    });

    const browserSearchIcon = document.createElement('span');
    browserSearchIcon.className = 'search-icon icon-search';

    browserSearch.appendChild(browserSearchInput);
    browserSearch.appendChild(browserSearchIcon);

    const browserViewOptions = document.createElement('div');
    browserViewOptions.className = 'view-options';

    const browserGridViewButton = document.createElement('button');
    browserGridViewButton.type = 'button';
    browserGridViewButton.className = 'view-option active';
    browserGridViewButton.innerHTML = '<span class="icon-grid"></span>';
    browserGridViewButton.title = window.translate('Grid view');
    browserGridViewButton.addEventListener('click', () => this.changeViewMode('grid'));

    const browserListViewButton = document.createElement('button');
    browserListViewButton.type = 'button';
    browserListViewButton.className = 'view-option';
    browserListViewButton.innerHTML = '<span class="icon-listview"></span>';
    browserListViewButton.title = window.translate('List view');
    browserListViewButton.addEventListener('click', () => this.changeViewMode('list'));

    browserViewOptions.appendChild(browserGridViewButton);
    browserViewOptions.appendChild(browserListViewButton);

    searchAndViewContainer.appendChild(browserSearch);
    searchAndViewContainer.appendChild(browserViewOptions);

    browserToolbar.appendChild(searchAndViewContainer);

    const dropArea = document.createElement('div');
    dropArea.className = 'file-browser-drop-area';
    dropArea.innerHTML = `<div class="drop-message"><span class="icon-upload"></span><p>${window.translate('Drag the file here to upload.')}</p></div>`;
    dropArea.addEventListener('dragover', this.handleDragOver);
    dropArea.addEventListener('drop', this.handleDrop);
    dropArea.addEventListener('dragleave', () => {
      dropArea.classList.remove('drag-over');
    });

    this.fileList = document.createElement('div');
    this.fileList.className = 'file-browser-files grid-view';

    dropArea.appendChild(this.fileList);

    browserMainContent.appendChild(browserToolbar);
    browserMainContent.appendChild(dropArea);

    this.browserContent.appendChild(browserMainContent);

    const footer = document.createElement('div');
    footer.className = 'file-browser-footer';

    this.status = document.createElement('div');
    this.status.className = 'file-browser-status';
    this.status.textContent = window.translate('Ready to use');

    const buttonsContainer = document.createElement('div');
    buttonsContainer.className = 'file-browser-buttons';

    const cancelButton = document.createElement('button');
    cancelButton.type = 'button';
    cancelButton.className = 'btn icon-reset';
    cancelButton.textContent = window.translate('Cancel');
    cancelButton.addEventListener('click', this.close);

    const selectButton = document.createElement('button');
    selectButton.type = 'button';
    selectButton.className = 'btn icon-valid select';
    selectButton.textContent = window.translate('Choose');
    selectButton.addEventListener('click', this.confirmSelection);

    buttonsContainer.appendChild(cancelButton);
    buttonsContainer.appendChild(selectButton);

    footer.appendChild(this.status);
    footer.appendChild(buttonsContainer);

    this.contextMenu = document.createElement('div');
    this.contextMenu.className = 'file-browser-context-menu';
    this.contextMenu.style.display = 'none';
    document.body.appendChild(this.contextMenu);

    this.content.appendChild(this.browserContent);
    if (this.options.showPresetTab) {
      this.content.appendChild(this.presetContent);
    }

    this.modal.appendChild(header);
    this.modal.appendChild(tabNav);
    this.modal.appendChild(this.content);
    this.modal.appendChild(footer);

    this.overlay.appendChild(this.modal);
  }

  /**
   * Re-apply UI strings (constructor may have run before Now.translate was ready).
   */
  refreshChromeTranslations() {
    const t = window.translate;
    if (typeof t !== 'function') {
      return;
    }

    const headerTitle = this.modal.querySelector('.file-browser-header h3');
    if (headerTitle) {
      headerTitle.textContent = t('Select the file');
    }
    const closeBtn = this.modal.querySelector('.file-browser-close');
    if (closeBtn) {
      closeBtn.title = t('Close');
    }

    const presetTab = this.modal.querySelector('.file-browser-tab[data-tab="preset"]');
    if (presetTab) {
      presetTab.textContent = t(this.options.presetTabName);
    }
    this.modal.querySelectorAll('.file-browser-tab[data-tab="browser"]').forEach(tab => {
      tab.textContent = t(tab.dataset.label || this.options.browserTabName);
    });

    const presetSearch = this.presetContent?.querySelector('.file-browser-search input[type="text"]');
    if (presetSearch) {
      presetSearch.placeholder = t('Search');
    }
    const browserSearch = this.browserContent?.querySelector('.file-browser-search input[type="text"]');
    if (browserSearch) {
      browserSearch.placeholder = t('Search');
    }

    const applyViewTitles = (root) => {
      if (!root) return;
      const opts = root.querySelectorAll(':scope .file-browser-toolbar .view-options .view-option');
      if (opts.length >= 1) {
        opts[0].title = t('Grid view');
      }
      if (opts.length >= 2) {
        opts[1].title = t('List view');
      }
    };
    applyViewTitles(this.presetContent);
    applyViewTitles(this.browserContent);

    const sidebarTitle = this.browserContent?.querySelector('.sidebar-title');
    if (sidebarTitle) {
      sidebarTitle.textContent = t('Folder');
    }

    const uploadBtn = this.browserContent?.querySelector('.file-browser-actions .icon-upload')?.closest('button');
    if (uploadBtn) {
      uploadBtn.innerHTML = `<span class="icon-upload"></span> ${t('Upload')}`;
    }
    const newFolderBtn = this.browserContent?.querySelector('.file-browser-actions .icon-create-folder')?.closest('button');
    if (newFolderBtn) {
      newFolderBtn.innerHTML = `<span class="icon-create-folder"></span> ${t('Create a folder')}`;
    }

    const dropMsg = this.browserContent?.querySelector('.file-browser-drop-area .drop-message p');
    if (dropMsg) {
      dropMsg.textContent = t('Drag the file here to upload.');
    }

    const cancelBtn = this.modal.querySelector('.file-browser-footer .icon-reset');
    if (cancelBtn) {
      cancelBtn.textContent = t('Cancel');
    }
    const selectBtn = this.modal.querySelector('.file-browser-footer .btn.select');
    if (selectBtn) {
      selectBtn.textContent = t('Choose');
    }
  }

  /**
   * เพิ่ม event listeners
   */
  addEventListeners() {
    if (this._documentListenersBound) return;
    this._documentListenersBound = true;

    document.addEventListener('keydown', this.handleEscapeKey);

    document.addEventListener('click', this.hideContextMenu);
  }

  /**
   * Counterpart of addEventListeners(), called from close().
   *
   * Callers build a fresh FileBrowser for every open and never call destroy(),
   * so leaving these bound left one dead keydown and one dead click handler on
   * document per open — each still holding the whole instance, and each firing
   * close()/onClose() of a dialog that is no longer on screen.
   */
  removeEventListeners() {
    if (!this._documentListenersBound) return;
    this._documentListenersBound = false;

    document.removeEventListener('keydown', this.handleEscapeKey);
    document.removeEventListener('click', this.hideContextMenu);
  }

  /**
   * จัดการกับการกด ESC key
   * @param {KeyboardEvent} e - เหตุการณ์ keydown
   */
  handleEscapeKey(e) {
    if (e.key === 'Escape') {
      if (this.contextMenu.style.display !== 'none') {
        this.hideContextMenu();
      } else {
        this.close();
      }
    }
  }

  /**
   * เปิด FileBrowser modal
   */
  open() {
    try {
      if (typeof window.Now?.translate === 'function') {
        window.translate = (key, params) => window.Now.translate(key, params);
      }
      this.refreshChromeTranslations();

      // Show modal directly (auth is handled by the PHP endpoint)
      document.body.appendChild(this.overlay);
      // Re-append context menu so it sits later in the DOM than the overlay,
      // ensuring it renders on top at equal z-index levels.
      document.body.appendChild(this.contextMenu);
      // close() unbinds these again, so reopening the same instance still works
      this.addEventListeners();

      if (this.options.activeTab === 2 || this.presetAvailable === false || !this.options.showPresetTab) {
        this.switchTab('browser');
      } else {
        this.switchTab('preset');
      }

      setTimeout(() => {
        this.overlay.classList.add('active');
        this.modal.classList.add('active');
      }, 10);

      this.selectedFiles = [];
      this.updateStatus();
    } catch (error) {
      console.error('Error opening file browser:', error);
      if (this.options.onError) {
        this.options.onError(error);
      }
    }
  }

  /**
   * ปิด FileBrowser modal
   */
  close() {
    clearTimeout(this._searchTimer);
    this._previewItem = null;
    this._lastListing = null;
    this.hidePreview();
    this.hideContextMenu();
    this.removeEventListeners();
    this.overlay.classList.remove('active');
    this.modal.classList.remove('active');

    setTimeout(() => {
      if (this.overlay.parentNode) {
        document.body.removeChild(this.overlay);
      }
      // open() puts the menu on document.body; without this it stays there
      // for the lifetime of the page, once per open.
      if (this.contextMenu?.parentNode) {
        this.contextMenu.parentNode.removeChild(this.contextMenu);
      }
      /**
       * The popup lives on document.body, so closing the dialog does not take it
       * with it — callers create a fresh FileBrowser per open and never call
       * destroy(), which would otherwise leave one popup behind every time.
       */
      if (this._previewEl?.parentNode) {
        this._previewEl.parentNode.removeChild(this._previewEl);
      }
      this._previewEl = null;
    }, 300);

    if (typeof this.options.onClose === 'function') {
      this.options.onClose();
    }
  }

  /**
   * สลับ tab ระหว่าง preset และ browser
   * @param {string} tabName - ชื่อ tab ('preset' หรือ 'browser')
   */
  switchTab(tabName) {
    const tabs = this.modal.querySelectorAll('.file-browser-tab');
    const tabContents = this.modal.querySelectorAll('.file-browser-tab-content');

    tabs.forEach(tab => {
      tab.classList.toggle('active', tab.dataset.tab === tabName
        && (tabName !== 'browser' || !tab.dataset.storage || tab.dataset.storage === this.storage));
    });

    tabContents.forEach(content => {
      content.classList.remove('active');
    });

    if (tabName === 'preset') {
      this.presetContent.classList.add('active');
      this.loadPresetCategories().catch(err => console.error('Error in loadPresetCategories:', err));
    } else {
      this.browserContent.classList.add('active');
      this.loadFiles().catch(err => console.error('Error in loadFiles:', err));
      this.loadFolderTree().catch(err => console.error('Error in loadFolderTree:', err));
    }
  }

  async loadPresetCategories() {
    try {
      this.isLoading = true;
      this.updateStatus('Loading');

      const endpoint = this.options.apiActions.getPresetCategories || '/file-browser/get_preset_categories';

      const result = await this.makeApiRequest(endpoint, null, 'GET');

      if (result.success) {
        this.applyStorageInfo(result.data);
      }

      if (result.success && result.data && result.data.available === false) {
        // Prepared folder does not exist on the server — hide the tab entirely
        this.setPresetTabVisible(false);
        this.isLoading = false;
        this.updateStatus();
        return;
      }

      if (result.success && result.data.categories) {
        this.presetAvailable = true;
        this.renderPresetCategories(result.data.categories);
      } else {
        this.isLoading = false;
        this.updateStatus('Unable to load the category');
      }
    } catch (error) {
      console.error('Error loading preset categories:', error);
      this.isLoading = false;
      this.updateStatus('There is an error in loading categories.');
    }
  }

  /**
   * แสดง/ซ่อน tab "ไฟล์ที่เตรียมไว้" (ซ่อนเมื่อโฟลเดอร์ presetStorageFolder ไม่มีบนเซิร์ฟเวอร์)
   * @param {boolean} visible
   */
  setPresetTabVisible(visible) {
    this.presetAvailable = visible;
    const presetTab = this.modal.querySelector('.file-browser-tab[data-tab="preset"]');
    if (presetTab) {
      presetTab.style.display = visible ? '' : 'none';
    }
    if (!visible) {
      this.presetContent.classList.remove('active');
      if (!this.browserContent.classList.contains('active')) {
        this.switchTab('browser');
      }
    }
  }

  /**
   * Take the storages the server reports (config 'storages'): keep those this
   * browser offers (options.storages), settle on the storage in use and show a
   * tab per storage when there is more than one.
   * @param {Object} data - response data carrying {storages, storage}
   * @returns {boolean} true when the storage in use had to change, so what was
   *   just loaded belongs to another storage
   */
  applyStorageInfo(data) {
    if (!data || !Array.isArray(data.storages) || !data.storages.length) return false;

    let list = data.storages.filter(item => item && item.id);
    if (Array.isArray(this.options.storages)) {
      const offered = list.filter(item => this.options.storages.includes(item.id));
      if (offered.length) list = offered;
    }
    if (!list.length) return false;

    const answered = data.storage || null;
    if (!list.some(item => item.id === this.storage)) {
      this.storage = list.some(item => item.id === answered) ? answered : list[0].id;
    }
    this.storageList = list;
    this.renderStorageTabs();

    return answered !== null && answered !== this.storage;
  }

  /**
   * One "browser" tab per storage (a single storage keeps the one default tab)
   */
  renderStorageTabs() {
    if (!this.tabNav || !this.storageList) return;

    const current = [...this.tabNav.querySelectorAll('.file-browser-tab[data-tab="browser"]')];
    if (this.storageList.length > 1) {
      const wanted = this.storageList.map(item => item.id).join('|');
      if (current.map(tab => tab.dataset.storage || '').join('|') !== wanted) {
        current.forEach(tab => tab.remove());
        this.storageList.forEach(item => {
          const tab = document.createElement('button');
          tab.type = 'button';
          tab.className = 'file-browser-tab';
          tab.dataset.tab = 'browser';
          tab.dataset.storage = item.id;
          tab.dataset.label = item.name || item.id;
          tab.textContent = window.translate(tab.dataset.label);
          tab.addEventListener('click', () => this.switchStorage(item.id));
          this.tabNav.appendChild(tab);
        });
      }
    }

    const browserActive = this.browserContent?.classList.contains('active');
    this.tabNav.querySelectorAll('.file-browser-tab[data-tab="browser"]').forEach(tab => {
      tab.classList.toggle('active', !!browserActive && (!tab.dataset.storage || tab.dataset.storage === this.storage));
    });
  }

  /**
   * Show another storage in the browser tab, from its root folder
   * @param {string} id - Storage id
   * @param {boolean} force - Reload even when it is the storage already shown
   */
  switchStorage(id, force = false) {
    const browserActive = this.browserContent?.classList.contains('active');
    if (!force && id === this.storage && browserActive) return;

    this.storage = id;
    this.currentPath = '/';
    this.selectedFiles = [];
    this.searchTerm = '';
    const search = this.browserContent?.querySelector('.file-browser-search input[type="text"]');
    if (search) search.value = '';
    this.updateStatus();
    this.switchTab('browser');
  }

  /**
   * Extensions the current storage accepts (lower case, no dot), or null
   * @returns {string[]|null}
   */
  getStorageExtensions() {
    const current = this.storageList?.find(item => item.id === this.storage);
    return Array.isArray(current?.extensions) && current.extensions.length ? current.extensions : null;
  }

  /**
   * accept="" for the upload picker: the caller's filter, else the storage's
   * extensions, else the default filter
   * @returns {string}
   */
  getAcceptTypes() {
    const extensions = this.getStorageExtensions();
    if (this.explicitFileTypes || !extensions) {
      return this.options.allowedFileTypes;
    }
    return extensions.map(ext => '.' + ext).join(',');
  }

  /**
   * แสดงรายการหมวดหมู่
   * @param {Array} categories - ข้อมูลหมวดหมู่
   */
  renderPresetCategories(categories) {
    if (!this.presetContent) return;

    const categoriesContainer = this.presetContent.querySelector('.file-browser-categories');
    const filesContainer = this.presetContent.querySelector('.file-browser-files');
    if (!categoriesContainer || !filesContainer) return;

    categoriesContainer.innerHTML = '';

    if (!categories.length) {
      this.currentPresetCategory = null;
      filesContainer.innerHTML = '';
      const emptyMsg = document.createElement('div');
      emptyMsg.className = 'empty-message';
      emptyMsg.textContent = window.translate(
        'No prepared subfolders yet. Create subfolders under the server prepared directory (e.g. datas/prepared).'
      );
      filesContainer.appendChild(emptyMsg);
      const sideMsg = document.createElement('div');
      sideMsg.className = 'empty-message';
      sideMsg.textContent = window.translate('No categories');
      categoriesContainer.appendChild(sideMsg);
      if (this.presetContent.classList.contains('active')) {
        this.isLoading = false;
        this.updateStatus(window.translate('No prepared categories'));
      }
      return;
    }

    const validIds = new Set(categories.map((c) => c.id));
    if (!this.currentPresetCategory || !validIds.has(this.currentPresetCategory)) {
      this.currentPresetCategory = categories[0].id;
    }

    const categoriesList = document.createElement('ul');

    categories.forEach((category) => {
      const item = document.createElement('li');
      item.className = this.currentPresetCategory === category.id ? 'active' : '';

      const rawIcon = category.icon && String(category.icon).trim();
      const iconClass = rawIcon && /^[\w-]+$/.test(rawIcon) ? rawIcon : 'icon-folder';
      const iconSpan = document.createElement('span');
      iconSpan.className = iconClass;
      item.appendChild(iconSpan);
      const rawName = category.name != null ? String(category.name) : String(category.id || '');
      const displayName =
        typeof window.Utils?.string?.humanize === 'function'
          ? window.Utils.string.humanize(rawName)
          : rawName;
      item.appendChild(document.createTextNode(' ' + window.translate(displayName)));

      if (category.description) {
        item.title = category.description;
      }

      item.addEventListener('click', () => {
        this.currentPresetCategory = category.id;
        this.loadPresets();

        categoriesList.querySelectorAll('li').forEach((li) => {
          li.classList.remove('active');
        });
        item.classList.add('active');
      });

      categoriesList.appendChild(item);
    });

    categoriesContainer.appendChild(categoriesList);

    if (this.presetContent.classList.contains('active')) {
      this.loadPresets().catch((err) => console.error('Error in loadPresets:', err));
    }
  }

  async loadPresets() {
    const filesContainer = this.presetContent.querySelector('.file-browser-files');
    filesContainer.innerHTML = '';

    if (!this.currentPresetCategory) {
      const emptyMsg = document.createElement('div');
      emptyMsg.className = 'empty-message';
      emptyMsg.textContent = window.translate(
        'Select a subfolder on the left. Prepared files are only listed inside subfolders.'
      );
      filesContainer.appendChild(emptyMsg);
      this.isLoading = false;
      this.updateStatus(window.translate('Choose a category'));
      return;
    }

    this.isLoading = true;
    this.updateStatus('Loading');

    const params = new URLSearchParams({
      category: this.currentPresetCategory,
      search: this.searchTerm,
      sort_by: this.sortBy,
      sort_dir: this.sortDir
    });

    try {
      const endpoint = this.options.apiActions.getPresets || '/file-browser/get_presets';
      const separator = endpoint.includes('?') ? '&' : '?';
      const url = `${endpoint}${separator}${params.toString()}`;

      const result = await this.makeApiRequest(url, null, 'GET');

      if (result.success) {
        this.displayFiles(filesContainer, result.data.files, 'preset');
        this.isLoading = false;
        this.updateStatus('{items} items.', {items: result.data.files.length});
      } else {
        this.isLoading = false;
        this.updateStatus('Error: {message}', {message: result.message});
      }
    } catch (error) {
      console.error('Error loading presets:', error);
      this.isLoading = false;
      this.updateStatus('Unable to load data');
    }
  }

  async loadFiles() {
    const filesContainer = this.browserContent.querySelector('.file-browser-files');
    filesContainer.innerHTML = '';

    this.isLoading = true;
    this.updateStatus('Loading');

    this.updateBreadcrumbs();

    const params = new URLSearchParams({
      path: this.currentPath,
      search: this.searchTerm,
      sort_by: this.sortBy,
      sort_dir: this.sortDir
    });

    try {
      const endpoint = this.options.apiActions.getFiles || '/file-browser/get_files';
      const separator = endpoint.includes('?') ? '&' : '?';
      const url = `${endpoint}${separator}${params.toString()}`;

      const result = await this.makeApiRequest(url, null, 'GET');

      if (result.success) {
        this.displayFiles(filesContainer, result.data.files, 'browser');
        this.isLoading = false;
        this.updateStatus('{items} items.', {items: result.data.files.length});
      } else {
        this.isLoading = false;
        this.updateStatus('Error: {message}', {message: result.message});
      }
    } catch (error) {
      console.error('Error loading files:', error);
      this.isLoading = false;
      this.updateStatus('Unable to load data');
    }
  }

  async loadFolderTree() {
    this.folderTree.textContent = '';
    const loadingDiv = document.createElement('div');
    loadingDiv.className = 'loading';
    loadingDiv.textContent = window.translate('Loading') + '...';
    this.folderTree.appendChild(loadingDiv);

    try {
      const endpoint = this.options.apiActions.getFolderTree || '/file-browser/get_folder_tree';

      const result = await this.makeApiRequest(endpoint, null, 'GET');

      if (result.success) {
        if (this.applyStorageInfo(result.data)) {
          // Opened in a storage this browser does not offer — reload in the right one
          this.switchStorage(this.storage, true);
          return;
        }
        this.displayFolderTree(result.data.folders);
      } else {
        this.folderTree.textContent = '';
        const errDiv = document.createElement('div');
        errDiv.className = 'error';
        errDiv.textContent = window.translate(result.message);
        this.folderTree.appendChild(errDiv);
      }
    } catch (error) {
      console.error('Error loading folder tree:', error);
      this.folderTree.textContent = '';
      const errDiv = document.createElement('div');
      errDiv.className = 'error';
      errDiv.textContent = window.translate('Unable to download the list of folders');
      this.folderTree.appendChild(errDiv);
    }
  }

  /**
   * แสดงรายการโฟลเดอร์แบบ tree
   * @param {Array} folders - รายการโฟลเดอร์
   * @param {HTMLElement} parent - องค์ประกอบ parent (ถ้ามี)
   */
  displayFolderTree(folders, parent = null) {
    const ul = document.createElement('ul');
    ul.className = 'folder-tree-list';

    if (!parent) {
      this.folderTree.innerHTML = '';
      this.folderTree.appendChild(ul);

      const rootItem = document.createElement('li');
      rootItem.className = 'folder-tree-item' + (this.currentPath === '/' ? ' active' : '');

      const rootLink = document.createElement('a');
      rootLink.href = '#';
      rootLink.className = 'folder-tree-link';
      rootLink.innerHTML = `<span class="icon-folder"></span> ${window.translate('Home')}`;
      rootLink.addEventListener('click', (e) => {
        e.preventDefault();
        this.navigateToFolder('/');
      });

      rootItem.appendChild(rootLink);
      ul.appendChild(rootItem);

      folders.forEach(folder => {
        const item = this.createFolderTreeItem(folder);
        ul.appendChild(item);
      });
    } else {
      folders.forEach(folder => {
        const item = this.createFolderTreeItem(folder);
        ul.appendChild(item);
      });

      if (ul.children.length > 0) {
        parent.appendChild(ul);
      }
    }
  }

  /**
   * สร้าง folder tree item
   * @param {Object} folder - ข้อมูลโฟลเดอร์
   * @returns {HTMLElement} - องค์ประกอบ li
   */
  createFolderTreeItem(folder) {
    const item = document.createElement('li');
    item.className = 'folder-tree-item';

    const isActive = this.currentPath === folder.path;
    if (isActive) {
      item.classList.add('active');
    }

    const link = document.createElement('a');
    link.href = '#';
    link.className = 'folder-tree-link';
    link.dataset.path = folder.path;

    const hasChildren = folder.children && folder.children.length > 0;
    let expandIcon = '';

    if (hasChildren) {
      const expandSpan = document.createElement('span');
      expandSpan.className = 'expand-icon';
      link.appendChild(expandSpan);
      item.classList.add('has-children');
    }

    const folderIcon = document.createElement('span');
    folderIcon.className = 'icon-folder';
    link.appendChild(folderIcon);
    link.appendChild(document.createTextNode(' ' + folder.name));

    link.addEventListener('click', (e) => {
      e.preventDefault();
      this.navigateToFolder(folder.path);
    });

    if (hasChildren) {
      link.querySelector('.expand-icon').addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();

        if (item.classList.contains('expanded')) {
          item.classList.remove('expanded');
          const subList = item.querySelector('ul');
          if (subList) {
            item.removeChild(subList);
          }
        } else {
          item.classList.add('expanded');
          this.displayFolderTree(folder.children, item);
        }
      });
    }

    item.appendChild(link);

    link.addEventListener('contextmenu', (e) => {
      e.preventDefault();

      const menuItems = [
        {label: 'Open', icon: 'icon-folder-open', action: () => this.navigateToFolder(folder.path)},
        {label: 'Create a new folder', icon: 'icon-create-folder', action: () => this.createFolder(folder.path)},
        {label: 'Rename', icon: 'icon-edit', action: () => this.renameFile(folder.path, 'folder')},
        {label: 'Delete', icon: 'icon-delete', action: () => this.deleteFile(folder.path, 'folder')}
      ];

      if (this.options.customContextMenuItems.length > 0) {
        menuItems.push({type: 'separator'});
        this.options.customContextMenuItems.forEach(customItem => {
          menuItems.push(customItem);
        });
      }

      this.showContextMenu(e, menuItems);
    });

    return item;
  }

  async createFolder(parentPathOrEvent = null) {
    let path;

    if (parentPathOrEvent && typeof parentPathOrEvent === 'object' && parentPathOrEvent.preventDefault) {
      path = this.currentPath;
    } else if (typeof parentPathOrEvent === 'string') {
      path = parentPathOrEvent;
    } else {
      path = this.currentPath;
    }

    const folderName = prompt(window.translate('Please specify the name of the new folder.'));

    if (!folderName) return;

    // Same rule as the server (isValidFilename), which also accepts Thai names
    if (!this.isValidNewName(folderName)) {
      alert(window.translate('The name of the folder is incorrect. Please use the numbers, numbers, numbers and signs only.'));
      return;
    }

    this.isLoading = true;
    this.updateStatus('Creating a folder');

    const data = {
      path: path,
      name: folderName
    };

    try {
      const endpoint = this.options.apiActions.createFolder || '/file-browser/create_folder';

      const result = await this.makeApiRequest(endpoint, data);

      if (result.success) {
        this.updateStatus('Already created the folder');

        this.loadFiles();
        this.loadFolderTree();
      } else {
        this.updateStatus('Error: {message}', {message: result.message});
      }
    } catch (error) {
      console.error('Error creating folder:', error);
      this.updateStatus('Unable to create a folder');
    } finally {
      this.isLoading = false;
    }
  }

  /**
   * แสดงรายการไฟล์
   * @param {HTMLElement} container - องค์ประกอบสำหรับแสดงไฟล์
   * @param {Array} files - รายการไฟล์
   * @param {string} mode - โหมดการแสดง ('preset' หรือ 'browser')
   */
  displayFiles(container, files, mode) {
    container.innerHTML = '';
    // Kept so changeViewMode() can redraw without listing the folder again —
    // that request is close to a megabyte of JSON on a large folder.
    this._lastListing = {container, files, mode};

    if (files.length === 0) {
      const emptyMessage = document.createElement('div');
      emptyMessage.className = 'empty-message';
      emptyMessage.textContent = window.translate('Not found files');
      container.appendChild(emptyMessage);
      return;
    }

    /**
     * No client-side re-sort: the server already ordered the listing with
     * strnatcasecmp (models/files.php sortItems), which a raw `a > b` compare
     * here would undo — it is case-sensitive and puts "img10" before "img9".
     */
    const fragment = document.createDocumentFragment();
    files.forEach(file => {
      fragment.appendChild(this.createFileItem(file, mode));
    });
    container.appendChild(fragment);

    this.attachListHandlers(container);
  }

  /**
   * Bind the file list's behaviour — selection, navigation, context menu and
   * the hover preview — once per container instead of once per row, so a folder
   * of a few thousand items costs a handful of listeners rather than four per
   * item. The popup image is still only fetched once the pointer settles.
   * @param {HTMLElement} container
   */
  attachListHandlers(container) {
    if (container.dataset.hoverPreviewReady === '1') return;
    container.dataset.hoverPreviewReady = '1';

    /**
     * Selection, navigation and the context menu are delegated too, for the
     * same reason as the preview: one listener per container instead of four
     * per row. Each row carries its own data in item._fbFile.
     */
    container.addEventListener('click', event => {
      const item = event.target.closest('.file-item');
      if (!item || !container.contains(item) || !item._fbFile) return;
      const file = item._fbFile;
      const mode = item.dataset.mode;
      if (file.type === 'folder' && mode === 'browser') {
        this.navigateToFolder(file.path);
        return;
      }
      this.selectFile(file, mode, event.ctrlKey || event.metaKey);
    });

    container.addEventListener('dblclick', event => {
      const item = event.target.closest('.file-item');
      if (!item || !container.contains(item) || !item._fbFile) return;
      const file = item._fbFile;
      const mode = item.dataset.mode;
      if (file.type === 'folder' && mode === 'browser') {
        this.navigateToFolder(file.path);
        return;
      }
      this.selectedFiles = [{...file, mode}];
      this.confirmSelection();
    });

    container.addEventListener('keydown', event => {
      if (event.key !== 'Enter' && event.key !== ' ') return;
      const item = event.target.closest('.file-item');
      if (!item || !container.contains(item) || !item._fbFile) return;
      event.preventDefault();
      const file = item._fbFile;
      const mode = item.dataset.mode;
      if (file.type === 'folder' && mode === 'browser') {
        this.navigateToFolder(file.path);
        return;
      }
      this.selectFile(file, mode, event.ctrlKey || event.metaKey);
    });

    container.addEventListener('contextmenu', event => {
      const item = event.target.closest('.file-item');
      if (!item || !container.contains(item) || !item._fbFile) return;
      event.preventDefault();
      const file = item._fbFile;
      const mode = item.dataset.mode;
      if (!item.classList.contains('selected')) {
        this.selectFile(file, mode, false);
      }
      this.showFileContextMenu(event, file, mode);
    });

    container.addEventListener('mouseover', event => {
      const item = event.target.closest('.file-item');
      if (!item || !container.contains(item)) return;
      // Moving between children of the same item must not restart the timer
      if (item === this._previewItem) return;
      this._previewItem = item;
      this.hidePreview();

      const url = item.dataset.previewUrl;
      if (!url) return;
      clearTimeout(this._previewTimer);
      this._previewTimer = setTimeout(() => this.showPreview(item, url), 350);
    });

    container.addEventListener('mouseout', event => {
      const item = event.target.closest('.file-item');
      if (!item) return;
      // Ignore moves that stay inside the same item
      if (event.relatedTarget && item.contains(event.relatedTarget)) return;
      this._previewItem = null;
      this.hidePreview();
    });

    // Scrolling the list must not leave a popup floating over unrelated files
    container.addEventListener('scroll', () => {
      this._previewItem = null;
      this.hidePreview();
    }, {passive: true});
  }

  /**
   * @param {HTMLElement} item the hovered .file-item
   * @param {string} url image URL to show
   */
  showPreview(item, url) {
    if (!this._previewEl) {
      this._previewEl = document.createElement('div');
      this._previewEl.className = 'file-preview-popup';
      const img = document.createElement('img');
      img.alt = '';
      this._previewEl.appendChild(img);
      /**
       * document.body, never the modal: .file-browser-modal carries
       * `transform: scale()`, which makes it the containing block for
       * position:fixed children (so "centre of the screen" would become centre
       * of the dialog), and its `overflow: hidden` would clip the popup.
       */
      document.body.appendChild(this._previewEl);
    }

    const popup = this._previewEl;
    const img = popup.querySelector('img');
    const name = item.getAttribute('aria-label') || '';
    img.alt = name;
    // Only swap src when the file changed — avoids a flash re-decoding the same image
    if (img.dataset.src !== url) {
      img.dataset.src = url;
      img.src = url;
    }

    // Centred by CSS — measuring here would read the popup before the image has
    // loaded, so the size is stale or zero and the placement lands wrong.
    popup.classList.add('visible');
  }

  hidePreview() {
    clearTimeout(this._previewTimer);
    this._previewEl?.classList.remove('visible');
  }

  /**
   * สร้างองค์ประกอบแสดงไฟล์
   * @param {Object} file - ข้อมูลไฟล์
   * @param {string} mode - โหมดการแสดง ('preset' หรือ 'browser')
   * @returns {HTMLElement} - องค์ประกอบแสดงไฟล์
   */
  createFileItem(file, mode) {
    const item = document.createElement('div');
    item.className = 'file-item';
    item.dataset.path = file.path;
    item.dataset.type = file.type;
    item.dataset.mode = mode;
    /**
     * The row's own data, read back by the delegated handlers in
     * attachListHandlers(). Binding click/dblclick/contextmenu/keydown per item
     * cost four listeners each, which is ~10,600 on the 2,649-file folder this
     * was reported against.
     */
    item._fbFile = file;

    // Keyboard accessibility
    item.setAttribute('tabindex', '0');
    item.setAttribute('role', 'option');
    item.setAttribute('aria-label', file.name);

    const isSelected = this.selectedFiles.some(selectedFile =>
      selectedFile.path === file.path && selectedFile.mode === mode);

    if (isSelected) {
      item.classList.add('selected');
    }

    const thumbnail = document.createElement('div');
    thumbnail.className = 'file-thumbnail';

    // Check if it's an image using mimeType, type, extension or thumbnail
    const isImage = file.thumbnail ||
      (file.mimeType && file.mimeType.startsWith('image/')) ||
      (file.type && file.type.startsWith('image/')) ||
      this.isImageExtension(file.extension);

    if (file.type === 'folder') {
      const folderIcon = document.createElement('span');
      folderIcon.className = 'icon-folder';
      thumbnail.appendChild(folderIcon);
    } else if (isImage && (file.thumbnail || file.url)) {
      /**
       * A real <img>, not a CSS background: background-image needs the URL
       * inside url(), where an unquoted space ends the token and the browser
       * drops the whole declaration — 376 of the 2,649 legacy files here have a
       * space in the name and showed no preview at all. src takes the URL as
       * given, lazy-loads natively, and can report a failure.
       */
      const img = document.createElement('img');
      img.loading = 'lazy';
      img.decoding = 'async';
      img.alt = '';
      // Falls back to the full-size file once, then to the generic icon
      img.addEventListener('error', () => {
        if (file.url && img.src !== file.url && img.dataset.fallback !== '1') {
          img.dataset.fallback = '1';
          img.src = file.url;
          return;
        }
        img.replaceWith(this.createFileIcon(file));
      });
      img.src = file.thumbnail || file.url;
      thumbnail.appendChild(img);
      // Read back by attachListHandlers to show the full image on hover
      item.dataset.previewUrl = file.url || file.thumbnail;
    } else {
      thumbnail.appendChild(this.createFileIcon(file));
    }

    const info = document.createElement('div');
    info.className = 'file-info';

    const name = document.createElement('div');
    name.className = 'file-name';
    name.textContent = file.name;
    name.title = file.name;

    info.appendChild(name);

    if (this.viewMode === 'list') {
      const details = document.createElement('div');
      details.className = 'file-details';

      if (file.type !== 'folder') {
        const size = document.createElement('span');
        size.className = 'file-size';
        size.textContent = this.formatFileSize(file.size);
        details.appendChild(size);
      }

      const date = document.createElement('span');
      date.className = 'file-date';
      date.textContent = this.formatDate(file.modified);
      details.appendChild(date);

      info.appendChild(details);
    }

    item.appendChild(thumbnail);
    item.appendChild(info);

    return item;
  }

  /**
   * Generic icon element for a file that has no usable preview.
   * @param {Object} file
   * @returns {HTMLElement}
   */
  createFileIcon(file) {
    const ext = file.extension ? String(file.extension).toLowerCase().replace('.', '') : 'default';
    const iconSpan = document.createElement('span');
    iconSpan.className = this.fileIcons[ext] || this.fileIcons['default'];
    return iconSpan;
  }

  /**
   * Context menu for one file row.
   * @param {MouseEvent} event
   * @param {Object} file
   * @param {string} mode
   */
  showFileContextMenu(event, file, mode) {
    const menuItems = [];

    if (file.type === 'folder' && mode === 'browser') {
      menuItems.push(
        {label: 'Open', icon: 'icon-folder-open', action: () => this.navigateToFolder(file.path)},
        {label: 'Create a new folder', icon: 'icon-create-folder', action: () => this.createFolder(file.path)}
      );
    } else {
      menuItems.push(
        {label: 'Choose', icon: 'icon-valid', action: () => this.confirmSelection()}
      );
    }

    if (mode === 'browser') {
      menuItems.push(
        {label: 'Rename', icon: 'icon-edit', action: () => this.renameFile(file.path, file.type)},
        {label: 'Delete', icon: 'icon-delete', action: () => this.deleteFile(file.path, file.type)},
        {type: 'separator'},
        {label: 'Copy', icon: 'icon-copy', action: () => this.copyToClipboard(file, 'copy')},
        {label: 'Cut', icon: 'icon-cut', action: () => this.copyToClipboard(file, 'cut')}
      );

      if (this.clipboardFile) {
        menuItems.push(
          {
            label: 'Paste', icon: 'icon-clip', action: () => this.pasteFromClipboard(
              file.type === 'folder' ? file.path : this.currentPath
            )
          }
        );
      }
    }

    if (this.options.customContextMenuItems.length > 0) {
      menuItems.push({type: 'separator'});
      this.options.customContextMenuItems.forEach(customItem => {
        menuItems.push(customItem);
      });
    }

    this.showContextMenu(event, menuItems);
  }

  /**
   * Remember a file for the next Paste.
   * @param {Object} file
   * @param {'copy'|'cut'} action
   */
  copyToClipboard(file, action) {
    this.clipboardFile = file;
    this.clipboardAction = action;
    this.updateStatus(action === 'cut' ? 'Cut {name}' : 'Copied {name}', {name: file.name});
  }

  /**
   * เลือกไฟล์
   * @param {Object} file - ข้อมูลไฟล์
   * @param {string} mode - โหมดการแสดง ('preset' หรือ 'browser')
   * @param {boolean} multiSelect - เป็นการเลือกหลายไฟล์หรือไม่
   */
  selectFile(file, mode, multiSelect = false) {
    if (multiSelect && !this.options.multiSelect) {
      multiSelect = false;
    }

    const fileWithMode = {...file, mode};

    if (multiSelect) {
      const index = this.selectedFiles.findIndex(selectedFile =>
        selectedFile.path === file.path && selectedFile.mode === mode);

      if (index !== -1) {
        this.selectedFiles.splice(index, 1);
      } else {
        this.selectedFiles.push(fileWithMode);
      }
    } else {
      this.selectedFiles = [fileWithMode];
    }

    this.updateFileSelection();
    this.updateStatus();
  }

  /**
   * อัปเดทการแสดงผลไฟล์ที่เลือก
   */
  updateFileSelection() {
    const allFileItems = this.modal.querySelectorAll('.file-item');
    allFileItems.forEach(item => item.classList.remove('selected'));

    this.selectedFiles.forEach(file => {
      const selector = `.file-item[data-path=${this.cssEscape(file.path)}][data-mode=${this.cssEscape(file.mode)}]`;
      const fileItem = this.modal.querySelector(selector);
      if (fileItem) {
        fileItem.classList.add('selected');
      }
    });
  }

  /**
   * อัปเดท breadcrumbs
   */
  updateBreadcrumbs() {
    const breadcrumbsContainer = this.browserContent.querySelector('.file-browser-breadcrumbs');
    if (!breadcrumbsContainer) return;

    breadcrumbsContainer.innerHTML = '';

    const paths = this.currentPath.split('/').filter(p => p);
    let currentPath = '/';

    const homeItem = document.createElement('a');
    homeItem.href = '#';
    homeItem.className = 'breadcrumb-item';
    homeItem.innerHTML = '<span class="icon-home"></span>';
    homeItem.title = window.translate('Home');
    homeItem.addEventListener('click', (e) => {
      e.preventDefault();
      this.navigateToFolder('/');
    });

    breadcrumbsContainer.appendChild(homeItem);

    const separator = document.createElement('span');
    separator.className = 'breadcrumb-separator';
    separator.textContent = '/';
    breadcrumbsContainer.appendChild(separator.cloneNode(true));

    paths.forEach((path, index) => {
      /**
       * No trailing slash: the folder tree and the listing both address folders
       * as "/a/b", so a breadcrumb pointing at "/a/b/" highlighted nothing in
       * the tree and made the server build paths holding a double slash.
       */
      currentPath = (currentPath === '/' ? '' : currentPath) + '/' + path;

      const item = document.createElement('a');
      item.href = '#';
      item.className = 'breadcrumb-item';
      item.textContent = path;
      item.dataset.path = currentPath;
      item.addEventListener('click', (e) => {
        e.preventDefault();
        this.navigateToFolder(currentPath);
      });

      breadcrumbsContainer.appendChild(item);

      if (index < paths.length - 1) {
        breadcrumbsContainer.appendChild(separator.cloneNode(true));
      }
    });
  }

  /**
   * นำทางไปยังโฟลเดอร์
   * @param {string} path - path ของโฟลเดอร์
   */
  navigateToFolder(path) {
    this.currentPath = path;
    this.loadFiles();

    const folderItems = this.folderTree.querySelectorAll('.folder-tree-item');
    folderItems.forEach(item => {
      item.classList.remove('active');
      const link = item.querySelector('.folder-tree-link');
      if (link && link.dataset.path === path) {
        item.classList.add('active');
      }
    });
  }

  /**
   * อัปโหลดไฟล์
   * @param {FileList} files - รายการไฟล์ที่จะอัปโหลด
   */
  async uploadFiles(files) {
    if (files.length === 0) return;

    this.isLoading = true;
    this.updateStatus('Uploading 0/{length}', {length: files.length});

    try {
      const result = await this.uploadFilesWithApi(files, this.currentPath);

      if (result.success) {
        this.updateStatus(`Uploading {uploaded}/{total} files`, {
          uploaded: result.uploaded,
          total: result.total
        });

        if (window.Editor && window.Editor.showNotification) {
          window.Editor.showNotification('The upload is finished.', 'success');
        }

        setTimeout(async () => {
          await this.loadFiles();

          if (result.file && result.file.path) {
            this.selectFile(result.file, 'browser');

            const selector = `.file-item[data-path=${this.cssEscape(result.file.path)}][data-mode="browser"]`;
            const fileItem = this.modal.querySelector(selector);
            if (fileItem) {
              fileItem.scrollIntoView({block: 'nearest'});
            }
          }
        }, 1000);
      } else {
        this.updateStatus('Unable to upload files');

        if (window.Editor && window.Editor.showNotification) {
          window.Editor.showNotification('Unable to upload files', 'error');
        }
      }
    } catch (error) {
      console.error('Error uploading files:', error);
      this.updateStatus('Unable to upload files');

      if (window.Editor && window.Editor.showNotification) {
        window.Editor.showNotification('Unable to upload files', 'error');
      }
    } finally {
      this.isLoading = false;
    }
  }

  /**
   * บันทึกปัญหาความปลอดภัย
   * @param {string} message - ข้อความแจ้งเตือน
   */
  logSecurityIssue(message) {
    console.warn('Security Issue:', message);
  }

  /**
   * ตรวจสอบประเภทไฟล์
   * @param {File} file - ไฟล์ที่ต้องการตรวจสอบ
   * @returns {boolean} - ผลการตรวจสอบ
   */
  isAllowedFileType(file) {
    // The storage's own list decides; a caller's filter (e.g. image/*) narrows it
    const extensions = this.getStorageExtensions();
    if (extensions) {
      const ext = (file.name.split('.').pop() || '').toLowerCase();
      if (!extensions.includes(ext)) return false;
      if (!this.explicitFileTypes) return true;
    }

    const allowedTypes = this.options.allowedFileTypes.split(',');
    const fileName = file.name.toLowerCase();
    const fileType = file.type;

    let isAllowed = false;

    for (const type of allowedTypes) {
      const cleanType = type.trim();

      if (cleanType === '*') {
        isAllowed = true;
        break;
      }

      if (cleanType === fileType) {
        isAllowed = true;
        break;
      }

      if (cleanType.startsWith('.')) {
        const ext = '.' + fileName.split('.').pop();
        if (ext === cleanType.toLowerCase()) {
          isAllowed = true;
          break;
        }
      }

      if (cleanType.endsWith('/*')) {
        const category = cleanType.replace('/*', '');
        if (fileType.startsWith(category + '/')) {
          isAllowed = true;
          break;
        }
      }
    }

    return isAllowed;
  }

  /**
   * ทำความสะอาดชื่อไฟล์สำหรับการแสดงผล
   * @param {string} filename - ชื่อไฟล์
   * @returns {string} - ชื่อไฟล์ที่ทำความสะอาดแล้ว
   */
  sanitizeFileName(filename) {
    if (filename.length > 30) {
      return filename.substring(0, 15) + '...' + filename.substring(filename.length - 10);
    }
    return filename;
  }

  /**
   * Same rule as FileBrowserFiles::isValidFilename() — the server rejects
   * anything this misses, so it only has to spare the user a round trip.
   * @param {string} name
   * @returns {boolean}
   */
  isValidNewName(name) {
    if (typeof name !== 'string') return false;
    const trimmed = name.trim();
    if (trimmed === '' || trimmed === '.' || trimmed === '..') return false;
    if (name.length > 255) return false;
    // Path separators, Windows-reserved characters and control characters
    // eslint-disable-next-line no-control-regex
    if (/[\u0000-\u001F\u007F/\\:*?"<>|]/.test(name)) return false;
    if (name.includes('..')) return false;
    if (name.startsWith('.')) return false;
    if (/[. \t]$/.test(name)) return false;
    return true;
  }

  async renameFile(path, type) {
    const name = path.split('/').pop();
    const newName = prompt(window.translate('Please specify a new name for {type}', {type: type === 'folder' ? 'folder' : 'file'}), name);

    if (!newName || newName === name) return;

    /**
     * Mirrors FileBrowserFiles::isValidFilename() on the server: a deny list,
     * not the old ASCII whitelist, which refused every Thai name and every name
     * holding a space or a parenthesis — i.e. most of the existing files.
     */
    if (!this.isValidNewName(newName)) {
      alert(window.translate('Incorrect name Please use the numbers, numbers, numbers and signs only.'));
      return;
    }

    this.isLoading = true;
    this.updateStatus('Renaming');

    const data = {
      path: path,
      new_name: newName
    };

    try {
      const endpoint = this.options.apiActions.rename || '/file-browser/rename';

      const result = await this.makeApiRequest(endpoint, data, 'POST');

      if (result.success) {
        this.updateStatus('The name has been changed.');

        this.loadFiles();
        if (type === 'folder') {
          this.loadFolderTree();
        }
      } else {
        this.updateStatus('Error: {message}', {message: result.message});
      }
    } catch (error) {
      console.error('Error renaming:', error);
      this.updateStatus('Unable to change the name');
    } finally {
      this.isLoading = false;
    }
  }

  async deleteFile(path, type) {
    const name = path.split('/').pop();
    const isFolder = type === 'folder';

    let message = window.translate('Want to delete {type} "{name}" or not?', {
      type: isFolder ? 'folder' : 'file',
      name
    });
    if (isFolder) {
      message += `\n\n${window.translate('Warning: Deleting the folder will delete all files in the folder as well.')}`;
    }

    if (!confirm(message)) {
      return;
    }

    this.isLoading = true;
    this.updateStatus('Delete');

    const data = {
      path: path
    };

    try {
      const endpoint = this.options.apiActions.delete || '/file-browser/delete';

      const result = await this.makeApiRequest(endpoint, data, 'POST');

      if (result.success) {
        this.updateStatus('Already deleted');

        this.loadFiles();
        if (isFolder) {
          this.loadFolderTree();
        }
      } else {
        this.updateStatus('Error: {message}', {message: result.message});
      }
    } catch (error) {
      console.error('Error deleting:', error);
      this.updateStatus('Unable to delete');
    } finally {
      this.isLoading = false;
    }
  }

  /**
   * วางไฟล์หรือโฟลเดอร์จาก clipboard
   * @param {string} destination - path ปลายทาง
   */
  async pasteFromClipboard(destination = null) {
    if (!this.clipboardFile) return;

    const dest = destination || this.currentPath;

    this.isLoading = true;
    this.updateStatus('Operating');

    const data = {
      source: this.clipboardFile.path,
      destination: dest
    };

    try {
      /**
       * The action lives in the endpoint, as it does for every other call.
       * This used to post to a bare '/file-browser' with the action in the
       * body — a URL no route answers, so Paste always failed with a 404.
       */
      const endpoint = this.clipboardAction === 'cut'
        ? (this.options.apiActions.move || '/file-browser/move')
        : (this.options.apiActions.copy || '/file-browser/copy');

      const result = await this.makeApiRequest(endpoint, data);

      if (result.success) {
        this.updateStatus(this.clipboardAction === 'cut' ? 'Already moved' : 'Already copied');

        if (this.clipboardAction === 'cut') {
          this.clipboardFile = null;
          this.clipboardAction = null;
        }

        this.loadFiles();
        this.loadFolderTree();
      } else {
        this.updateStatus('Error: {message}', {message: result.message});
      }
    } catch (error) {
      console.error('Error pasting:', error);
      this.updateStatus('Cannot be placed');
    } finally {
      this.isLoading = false;
    }
  }

  /**
   * แสดง context menu
   * @param {MouseEvent} event - เหตุการณ์ mousedown
   * @param {Array} items - รายการเมนู
   */
  showContextMenu(event, items) {
    this.contextMenu.innerHTML = '';

    items.forEach(item => {
      if (item.type === 'separator') {
        const separator = document.createElement('div');
        separator.className = 'context-menu-separator';
        this.contextMenu.appendChild(separator);
        return;
      }

      const menuItem = document.createElement('div');
      menuItem.className = 'context-menu-item';

      if (item.icon) {
        const icon = document.createElement('span');
        icon.className = item.icon;
        menuItem.appendChild(icon);
      }

      const label = document.createElement('span');
      label.className = 'context-menu-label';
      label.textContent = window.translate(item.label);
      menuItem.appendChild(label);

      if (item.disabled) {
        menuItem.classList.add('disabled');
      } else {
        menuItem.addEventListener('click', () => {
          this.hideContextMenu();
          if (typeof item.action === 'function') {
            item.action();
          }
        });
      }

      this.contextMenu.appendChild(menuItem);
    });

    this.contextMenu.style.display = 'block';
    this.contextMenu.style.left = `${event.pageX}px`;
    this.contextMenu.style.top = `${event.pageY}px`;

    const menuRect = this.contextMenu.getBoundingClientRect();
    const windowWidth = window.innerWidth;
    const windowHeight = window.innerHeight;

    if (menuRect.right > windowWidth) {
      this.contextMenu.style.left = `${windowWidth - menuRect.width - 5}px`;
    }

    if (menuRect.bottom > windowHeight) {
      this.contextMenu.style.top = `${event.pageY - menuRect.height}px`;
    }
  }

  /**
   * ซ่อน context menu
   */
  hideContextMenu() {
    this.contextMenu.style.display = 'none';
  }

  /**
   * เปลี่ยนโหมดการแสดงผล
   * @param {string} mode - โหมดการแสดงผล ('grid' หรือ 'list')
   */
  changeViewMode(mode) {
    this.viewMode = mode;

    const gridButtons = this.modal.querySelectorAll('.view-option:first-child');
    const listButtons = this.modal.querySelectorAll('.view-option:last-child');

    gridButtons.forEach(button => {
      button.classList.toggle('active', mode === 'grid');
    });

    listButtons.forEach(button => {
      button.classList.toggle('active', mode === 'list');
    });

    const fileContainers = this.modal.querySelectorAll('.file-browser-files');
    fileContainers.forEach(container => {
      container.className = `file-browser-files ${mode}-view`;
    });

    /**
     * Redraw from the listing already in hand. Re-requesting it only to toggle
     * grid/list meant a full folder listing per click — nearly a megabyte of
     * JSON on the folder this was reported against.
     */
    const activeMode = this.presetContent.classList.contains('active') ? 'preset' : 'browser';
    if (this._lastListing && this._lastListing.mode === activeMode) {
      const {container, files, mode: listMode} = this._lastListing;
      this.displayFiles(container, files, listMode);
      this.updateFileSelection();
    } else if (this.presetContent.classList.contains('active')) {
      this.loadPresets();
    } else {
      this.loadFiles();
    }
  }

  /**
   * ค้นหาไฟล์
   * @param {string} term - คำค้นหา
   */
  search(term) {
    this.searchTerm = term;

    if (this.presetContent.classList.contains('active')) {
      this.loadPresets();
    } else {
      this.loadFiles();
    }
  }

  /**
   * เรียงลำดับไฟล์
   * @param {string} by - เรียงตามอะไร ('name', 'size', 'modified')
   * @param {string} direction - ทิศทาง ('asc', 'desc')
   */
  sort(by, direction = null) {
    if (!direction) {
      direction = by === this.sortBy && this.sortDir === 'asc' ? 'desc' : 'asc';
    }

    this.sortBy = by;
    this.sortDir = direction;

    if (this.presetContent.classList.contains('active')) {
      this.loadPresets();
    } else {
      this.loadFiles();
    }
  }

  /**
   * Replace {name} placeholders (works even when window.translate ignores params).
   * @param {string} text
   * @param {Record<string, string|number>|undefined} params
   * @returns {string}
   */
  interpolatePlaceholders(text, params) {
    if (text == null) return '';
    let out = String(text);
    if (!params || typeof params !== 'object') {
      return out;
    }
    return out.replace(/\{(\w+)\}/g, (match, key) => {
      if (Object.prototype.hasOwnProperty.call(params, key) && params[key] !== undefined && params[key] !== null) {
        return String(params[key]);
      }
      return match;
    });
  }

  /**
   * อัปเดทสถานะ
   * @param {string} message - ข้อความสถานะ
   */
  updateStatus(message = null, params) {
    // Show or hide loading spinner based on isLoading state
    const spinnerHtml = this.isLoading ? '<span class="fb-spinner"></span>' : '';

    if (message) {
      let translated = window.translate(message, params);
      if (typeof translated !== 'string') {
        translated = String(message);
      }
      const text = this.interpolatePlaceholders(translated, params);
      this.status.innerHTML = spinnerHtml + this.escapeHtml(text);
    } else {
      if (this.selectedFiles.length > 0) {
        let t = window.translate('Select {items}', {items: this.selectedFiles.length});
        if (typeof t !== 'string') {
          t = 'Select {items}';
        }
        const text = this.interpolatePlaceholders(t, {items: this.selectedFiles.length});
        this.status.innerHTML = spinnerHtml + this.escapeHtml(text);
      } else {
        this.status.innerHTML = spinnerHtml + this.escapeHtml(window.translate('Ready to use'));
      }
    }
  }

  /**
   * ยืนยันการเลือกไฟล์
   */
  confirmSelection() {
    if (this.selectedFiles.length === 0) {
      alert(window.translate('Please select the file.'));
      return;
    }

    if (typeof this.options.onSelect === 'function') {
      const files = this.selectedFiles.map(file => ({
        name: file.name,
        path: file.path,
        url: file.url,
        type: file.type,
        size: file.size,
        extension: file.extension,
        thumbnail: file.thumbnail
      }));

      this.options.onSelect(this.options.multiSelect ? files : files[0]);
    }

    this.close();
  }

  /**
   * จัดการ dragover event
   * @param {DragEvent} e - เหตุการณ์ dragover
   */
  handleDragOver(e) {
    e.preventDefault();
    e.stopPropagation();
    e.dataTransfer.dropEffect = 'copy';

    const dropArea = e.currentTarget;
    dropArea.classList.add('drag-over');
  }

  /**
   * จัดการ drop event
   * @param {DragEvent} e - เหตุการณ์ drop
   */
  handleDrop(e) {
    e.preventDefault();
    e.stopPropagation();

    const dropArea = e.currentTarget;
    dropArea.classList.remove('drag-over');

    if (e.dataTransfer.files.length > 0) {
      this.uploadFiles(e.dataTransfer.files);
    }
  }

  /**
   * จัดรูปแบบขนาดไฟล์
   * @param {number} bytes - ขนาดไฟล์ในไบต์
   * @returns {string} - ขนาดไฟล์ที่จัดรูปแบบแล้ว
   */
  formatFileSize(bytes) {
    if (bytes === 0) return '0 B';

    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(1024));

    return parseFloat((bytes / Math.pow(1024, i)).toFixed(2)) + ' ' + units[i];
  }

  /**
   * จัดรูปแบบวันที่
   * @param {string|number} date - วันที่
   * @returns {string} - วันที่ที่จัดรูปแบบแล้ว
   */
  formatDate(date) {
    if (!date) return '';

    /**
     * The API sends `modified` as a Unix timestamp in seconds (PHP filemtime),
     * but the Date constructor reads a number as milliseconds — every row in
     * list view used to read as a date in January 1970. Anything below ~1e11 is
     * far too small to be a millisecond timestamp of a real file.
     */
    const d = new Date(typeof date === 'number' && date < 1e11 ? date * 1000 : date);
    if (isNaN(d.getTime())) return '';

    return `${d.getDate().toString().padStart(2, '0')}/${(d.getMonth() + 1).toString().padStart(2, '0')}/${d.getFullYear()} ${d.getHours().toString().padStart(2, '0')}:${d.getMinutes().toString().padStart(2, '0')}`;
  }

  /**
   * ทำความสะอาดเมื่อเลิกใช้งาน
   */
  destroy() {
    this.removeEventListeners();

    clearTimeout(this._searchTimer);
    clearTimeout(this._previewTimer);
    if (this._previewEl && this._previewEl.parentNode) {
      this._previewEl.parentNode.removeChild(this._previewEl);
    }
    this._previewEl = null;

    if (this.contextMenu && this.contextMenu.parentNode) {
      this.contextMenu.parentNode.removeChild(this.contextMenu);
    }

    if (this.overlay && this.overlay.parentNode) {
      this.overlay.parentNode.removeChild(this.overlay);
    }
  }
}
export default FileBrowser;
