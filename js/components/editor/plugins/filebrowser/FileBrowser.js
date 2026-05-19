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
      ...options
    };

    if (!window.translate) {
      window.translate = (key) => {return key};
    }

    this.currentPath = '/';
    this.currentPresetCategory = null;
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
      const endpointUrl = this.options.apiActions[endpoint] || endpoint;
      let requestData = data;

      if (typeof this.options.requestTransformer === 'function') {
        requestData = this.options.requestTransformer(endpoint, data);
      }

      const fetchOptions = {
        method,
        headers: {'Content-Type': 'application/json'},
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
      validFiles.forEach(file => {
        formData.append(this.options.fileFieldName || 'files[]', file);
      });

      const fetchOpts = {
        method: 'POST',
        body: formData,
        credentials: 'include'
      };

      // Include CSRF token for upload requests
      const csrfToken = this.options.csrfToken
        || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
        || document.querySelector('input[name="token"]')?.value
        || null;
      if (csrfToken) {
        fetchOpts.headers = {'X-CSRF-Token': csrfToken};
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
    this.presetContent.className = 'file-browser-tab-content active';
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
      this.loadPresets();
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
      fileInput.accept = this.options.allowedFileTypes;
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
      this.loadFiles();
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
    cancelButton.className = 'btn icon-reset width100';
    cancelButton.textContent = window.translate('Cancel');
    cancelButton.addEventListener('click', this.close);

    const selectButton = document.createElement('button');
    selectButton.type = 'button';
    selectButton.className = 'btn select';
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

    this.content.appendChild(this.presetContent);
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
   * เพิ่ม event listeners
   */
  addEventListeners() {
    document.addEventListener('keydown', this.handleEscapeKey);

    document.addEventListener('click', this.hideContextMenu);
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
      // Show modal directly (auth is handled by the PHP endpoint)
      document.body.appendChild(this.overlay);
      // Re-append context menu so it sits later in the DOM than the overlay,
      // ensuring it renders on top at equal z-index levels.
      document.body.appendChild(this.contextMenu);

      if (this.options.activeTab === 2) {
        this.switchTab('browser');
      } else {
        this.switchTab('preset');
      }

      this.loadPresetCategories();

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
    this.overlay.classList.remove('active');
    this.modal.classList.remove('active');

    setTimeout(() => {
      if (this.overlay.parentNode) {
        document.body.removeChild(this.overlay);
      }
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
      tab.classList.remove('active');
      if (tab.dataset.tab === tabName) {
        tab.classList.add('active');
      }
    });

    tabContents.forEach(content => {
      content.classList.remove('active');
    });

    if (tabName === 'preset') {
      this.presetContent.classList.add('active');
      this.loadPresets().catch(err => console.error('Error in loadPresets:', err));
    } else {
      this.browserContent.classList.add('active');
      this.loadFiles().catch(err => console.error('Error in loadFiles:', err));
      this.loadFolderTree().catch(err => console.error('Error in loadFolderTree:', err));
    }
  }

  async loadPresetCategories() {
    try {
      this.updateStatus('Loading');

      const endpoint = this.options.apiActions.getPresetCategories || '/file-browser/get_preset_categories';

      const result = await this.makeApiRequest(endpoint, null, 'GET');

      if (result.success && result.data.categories) {
        this.renderPresetCategories(result.data.categories);
      } else {
        this.updateStatus('Unable to load the category');
      }
    } catch (error) {
      console.error('Error loading preset categories:', error);
      this.updateStatus('There is an error in loading categories.');
    }
  }

  /**
   * แสดงรายการหมวดหมู่
   * @param {Array} categories - ข้อมูลหมวดหมู่
   */
  renderPresetCategories(categories) {
    if (!this.presetContent) return;

    const categoriesContainer = this.presetContent.querySelector('.file-browser-categories');
    if (!categoriesContainer) return;

    categoriesContainer.innerHTML = '';

    const categoriesList = document.createElement('ul');

    const allItem = document.createElement('li');
    allItem.className = this.currentPresetCategory === null ? 'active' : '';
    allItem.innerHTML = `<span class="icon-folder"></span> ${window.translate('all')}`;
    allItem.addEventListener('click', () => {
      this.currentPresetCategory = null;
      this.loadPresets();

      categoriesList.querySelectorAll('li').forEach(item => {
        item.classList.remove('active');
      });
      allItem.classList.add('active');
    });

    categoriesList.appendChild(allItem);

    categories.forEach(category => {
      const item = document.createElement('li');
      item.className = this.currentPresetCategory === category.id ? 'active' : '';

      const iconClass = /^[a-zA-Z0-9_-]+$/.test(category.icon) ? category.icon : 'icon-folder';
      const iconSpan = document.createElement('span');
      iconSpan.className = iconClass;
      item.appendChild(iconSpan);
      item.appendChild(document.createTextNode(' ' + window.translate(category.name)));

      if (category.description) {
        item.title = category.description;
      }

      item.addEventListener('click', () => {
        this.currentPresetCategory = category.id;
        this.loadPresets();

        categoriesList.querySelectorAll('li').forEach(item => {
          item.classList.remove('active');
        });
        item.classList.add('active');
      });

      categoriesList.appendChild(item);
    });

    categoriesContainer.appendChild(categoriesList);
  }

  async loadPresets() {
    const filesContainer = this.presetContent.querySelector('.file-browser-files');
    filesContainer.innerHTML = '';

    this.isLoading = true;
    this.updateStatus('Loading');

    const params = new URLSearchParams({
      category: this.currentPresetCategory || 'all',
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
        this.updateStatus('{items} items.', {items: result.data.files.length});
      } else {
        this.updateStatus('Error: {message}', {message: result.message});
      }
    } catch (error) {
      console.error('Error loading presets:', error);
      this.updateStatus('Unable to load data');
    } finally {
      this.isLoading = false;
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
        this.updateStatus('{items} items.', {items: result.data.files.length});
      } else {
        this.updateStatus('Error: {message}', {message: result.message});
      }
    } catch (error) {
      console.error('Error loading files:', error);
      this.updateStatus('Unable to load data');
    } finally {
      this.isLoading = false;
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

    if (!/^[a-zA-Z0-9_\-]+$/.test(folderName)) {
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

    if (files.length === 0) {
      const emptyMessage = document.createElement('div');
      emptyMessage.className = 'empty-message';
      emptyMessage.textContent = window.translate('Not found files');
      container.appendChild(emptyMessage);
      return;
    }

    files.sort((a, b) => {
      if (a.type === 'folder' && b.type !== 'folder') return -1;
      if (a.type !== 'folder' && b.type === 'folder') return 1;

      const valueA = a[this.sortBy];
      const valueB = b[this.sortBy];

      if (this.sortDir === 'asc') {
        return valueA > valueB ? 1 : -1;
      } else {
        return valueA < valueB ? 1 : -1;
      }
    });

    files.forEach(file => {
      const fileItem = this.createFileItem(file, mode);
      container.appendChild(fileItem);
    });
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

    // Keyboard accessibility
    item.setAttribute('tabindex', '0');
    item.setAttribute('role', 'option');
    item.setAttribute('aria-label', file.name);
    item.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        if (file.type === 'folder' && mode === 'browser') {
          this.navigateToFolder(file.path);
        } else {
          this.selectFile(file, mode, e.ctrlKey || e.metaKey);
        }
      }
    });

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
    } else if (isImage) {
      // Use thumbnail URL or file URL for images — set via DOM style to avoid innerHTML injection
      const imgUrl = file.thumbnail || file.url;
      const imgSpan = document.createElement('span');
      // Sanitise parentheses/quotes that could break out of the url() value
      const safeUrl = imgUrl.replace(/[()'"\\]/g, ch => encodeURIComponent(ch));
      imgSpan.style.backgroundImage = `url(${safeUrl})`;
      thumbnail.appendChild(imgSpan);
    } else {
      const ext = file.extension ? file.extension.toLowerCase().replace('.', '') : 'default';
      const iconClass = this.fileIcons[ext] || this.fileIcons['default'];
      const iconSpan = document.createElement('span');
      iconSpan.className = iconClass;
      thumbnail.appendChild(iconSpan);
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

    item.addEventListener('click', (e) => {
      if (file.type === 'folder' && mode === 'browser') {
        this.navigateToFolder(file.path);
        return;
      }

      this.selectFile(file, mode, e.ctrlKey || e.metaKey);
    });

    item.addEventListener('dblclick', () => {
      if (file.type === 'folder' && mode === 'browser') {
        this.navigateToFolder(file.path);
        return;
      }

      this.selectedFiles = [{...file, mode}];
      this.confirmSelection();
    });

    item.addEventListener('contextmenu', (e) => {
      e.preventDefault();

      if (!isSelected) {
        this.selectFile(file, mode, false);
      }

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
          {label: 'Delete', icon: 'icon-delete', action: () => this.deleteFile(file.path, file.type)}
        );

        if (this.clipboardFile) {
          menuItems.push(
            {type: 'separator'},
            {label: 'Paste', icon: 'icon-paste', action: () => this.pasteFromClipboard(file.path)}
          );
        }
      }

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
      currentPath += path + '/';

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

        setTimeout(() => {
          this.loadFiles();
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

  async renameFile(path, type) {
    const name = path.split('/').pop();
    const newName = prompt(window.translate('Please specify a new name for {type}', {type: type === 'folder' ? 'folder' : 'file'}), name);

    if (!newName || newName === name) return;

    if (!/^[a-zA-Z0-9_\-\.]+$/.test(newName)) {
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
      action: this.clipboardAction === 'cut' ? 'move' : 'copy',
      source: this.clipboardFile.path,
      destination: dest
    };

    try {
      const result = await this.makeApiRequest('/file-browser', data);

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

    if (this.presetContent.classList.contains('active')) {
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
   * อัปเดทสถานะ
   * @param {string} message - ข้อความสถานะ
   */
  updateStatus(message = null, params) {
    // Show or hide loading spinner based on isLoading state
    const spinnerHtml = this.isLoading ? '<span class="fb-spinner"></span>' : '';

    if (message) {
      this.status.innerHTML = spinnerHtml + this.escapeHtml(window.translate(message, params));
    } else {
      if (this.selectedFiles.length > 0) {
        this.status.innerHTML = spinnerHtml + this.escapeHtml(window.translate('Select {items}', {items: this.selectedFiles.length}));
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

    const d = new Date(date);

    return `${d.getDate().toString().padStart(2, '0')}/${(d.getMonth() + 1).toString().padStart(2, '0')}/${d.getFullYear()} ${d.getHours().toString().padStart(2, '0')}:${d.getMinutes().toString().padStart(2, '0')}`;
  }

  /**
   * ทำความสะอาดเมื่อเลิกใช้งาน
   */
  destroy() {
    document.removeEventListener('keydown', this.handleEscapeKey);
    document.removeEventListener('click', this.hideContextMenu);

    if (this.contextMenu && this.contextMenu.parentNode) {
      this.contextMenu.parentNode.removeChild(this.contextMenu);
    }

    if (this.overlay && this.overlay.parentNode) {
      this.overlay.parentNode.removeChild(this.overlay);
    }
  }
}
export default FileBrowser;
