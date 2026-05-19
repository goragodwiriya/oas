/**
 * DocumentViewer Component
 * Advanced document viewer with signature placement capabilities
 * Extends MediaViewer for PDF and document display with e-signing workflow
 *
 * Features:
 * - PDF document rendering with PDF.js integration
 * - Interactive signature field placement
 * - Multi-page document navigation
 * - Zoom and pan controls with signature scaling
 * - Signature workflow management
 * - Document annotation and markup
 * - Real-time collaboration support
 * - Mobile-responsive touch controls
 * - Accessibility compliance
 */
class DocumentViewer {
  /**
   * Create DocumentViewer instance
   * @param {Object} options Configuration options
   */
  constructor(options = {}) {
    this.options = {
      // Document rendering options
      renderingEngine: 'pdf.js', // 'pdf.js', 'iframe', 'image'
      enableTextSelection: true,
      enableSearch: true,

      // Signature placement options
      enableSignaturePlacement: true,
      signatureFieldMinSize: 60,
      signatureFieldMaxSize: 400,
      signatureFieldDefaultSize: 120,
      signatureFieldTypes: ['signature', 'initial', 'date', 'text', 'checkbox'],

      // Workflow options
      enableMultiPartyWorkflow: true,
      enableRealTimeUpdates: false,
      enableComments: true,
      enableApprovalWorkflow: true,

      // Display options
      pageSpacing: 20,
      maxZoom: 5,
      minZoom: 0.25,
      zoomStep: 0.25,
      fitMode: 'width', // 'width', 'height', 'page', 'actual'

      // Security options
      enableDownload: true,
      enablePrint: true,
      enableCopy: true,
      watermarkText: null,

      // Event callbacks
      onDocumentLoad: null,
      onPageChange: null,
      onSignatureFieldAdd: null,
      onSignatureFieldUpdate: null,
      onSignatureFieldDelete: null,
      onSignatureComplete: null,
      onWorkflowUpdate: null,
      onError: null,

      ...options
    };

    this.state = {
      document: null,
      currentPage: 1,
      totalPages: 0,
      scale: 1,
      rotation: 0,

      // Signature fields management
      signatureFields: new Map(),
      selectedField: null,
      fieldIdCounter: 0,

      // Workflow state
      workflowStage: 'preparation', // 'preparation', 'signing', 'completed'
      participants: [],
      currentParticipant: null,

      // UI state
      isPlacingField: false,
      placingFieldType: null,
      isDragging: false,
      isResizing: false,
      viewMode: 'single', // 'single', 'continuous', 'facing'

      // Performance tracking
      renderingInProgress: false,
      loadedPages: new Set(),
      visiblePages: new Set()
    };

    this.components = {
      mediaViewer: null,
      signaturePad: null,
      toolbar: null,
      sidebar: null,
      statusBar: null
    };

    this.init();
  }

  /**
   * Initialize DocumentViewer
   * @private
   */
  init() {
    this.setupDOM();
    this.bindEvents();
    this.loadDependencies();

    // Register with Now.js framework
    if (typeof Now !== 'undefined') {
      Now.registerComponent('DocumentViewer', this);
    }
  }

  /**
   * Set up DOM structure
   * @private
   */
  setupDOM() {
    this.container = document.createElement('div');
    this.container.className = 'document-viewer';
    this.container.setAttribute('data-component', 'DocumentViewer');
    this.container.setAttribute('role', 'application');
    this.container.setAttribute('aria-label', 'Document Viewer with Signature Capabilities');

    this.container.innerHTML = `
      <div class="document-viewer-header">
        <div class="document-toolbar">
          <div class="toolbar-group navigation">
            <button class="btn-icon icon-first-page" data-action="first-page" title="First Page" aria-label="Go to first page"></button>
            <button class="btn-icon icon-prev-page" data-action="prev-page" title="Previous Page" aria-label="Go to previous page"></button>
            <div class="page-controls">
              <input type="number" class="current-page" min="1" aria-label="Current page">
              <span class="page-separator">/</span>
              <span class="total-pages">0</span>
            </div>
            <button class="btn-icon icon-next-page" data-action="next-page" title="Next Page" aria-label="Go to next page"></button>
            <button class="btn-icon icon-last-page" data-action="last-page" title="Last Page" aria-label="Go to last page"></button>
          </div>

          <div class="toolbar-group zoom">
            <button class="btn-icon icon-zoom-out" data-action="zoom-out" title="Zoom Out" aria-label="Zoom out"></button>
            <div class="zoom-controls">
              <select class="zoom-level" aria-label="Zoom level">
                <option value="auto">Auto</option>
                <option value="page">Fit Page</option>
                <option value="width">Fit Width</option>
                <option value="0.5">50%</option>
                <option value="0.75">75%</option>
                <option value="1">100%</option>
                <option value="1.25">125%</option>
                <option value="1.5">150%</option>
                <option value="2">200%</option>
                <option value="3">300%</option>
              </select>
            </div>
            <button class="btn-icon icon-zoom-in" data-action="zoom-in" title="Zoom In" aria-label="Zoom in"></button>
          </div>

          <div class="toolbar-group signature-tools" ${!this.options.enableSignaturePlacement ? 'style="display: none;"' : ''}>
            <button class="btn-icon icon-signature" data-action="add-signature" data-field-type="signature" title="Add Signature Field" aria-label="Add signature field"></button>
            <button class="btn-icon icon-initial" data-action="add-initial" data-field-type="initial" title="Add Initial Field" aria-label="Add initial field"></button>
            <button class="btn-icon icon-date" data-action="add-date" data-field-type="date" title="Add Date Field" aria-label="Add date field"></button>
            <button class="btn-icon icon-text" data-action="add-text" data-field-type="text" title="Add Text Field" aria-label="Add text field"></button>
            <button class="btn-icon icon-checkbox" data-action="add-checkbox" data-field-type="checkbox" title="Add Checkbox" aria-label="Add checkbox field"></button>
          </div>

          <div class="toolbar-group document-actions">
            <button class="btn-icon icon-rotate" data-action="rotate" title="Rotate Document" aria-label="Rotate document"></button>
            <button class="btn-icon icon-search" data-action="search" title="Search Document" aria-label="Search in document"></button>
            <button class="btn-icon icon-print" data-action="print" title="Print Document" aria-label="Print document" ${!this.options.enablePrint ? 'style="display: none;"' : ''}></button>
            <button class="btn-icon icon-download" data-action="download" title="Download Document" aria-label="Download document" ${!this.options.enableDownload ? 'style="display: none;"' : ''}></button>
          </div>

          <div class="toolbar-group workflow-actions" ${!this.options.enableMultiPartyWorkflow ? 'style="display: none;"' : ''}>
            <button class="btn-primary workflow-btn" data-action="send-for-signature" title="Send for Signature">Send for Signature</button>
            <button class="btn-secondary workflow-btn" data-action="save-draft" title="Save Draft">Save Draft</button>
          </div>
        </div>

        <div class="document-status-bar">
          <div class="status-info">
            <span class="document-name"></span>
            <span class="document-status"></span>
            <span class="participant-info"></span>
          </div>
          <div class="progress-indicator" style="display: none;">
            <div class="progress-bar"><div class="progress-fill"></div></div>
            <span class="progress-text">Loading...</span>
          </div>
        </div>
      </div>

      <div class="document-viewer-content">
        <div class="document-sidebar" style="display: none;">
          <div class="sidebar-tabs">
            <button class="sidebar-tab active" data-tab="fields" aria-label="Signature Fields">
              <span class="icon-fields"></span>
              Fields
            </button>
            <button class="sidebar-tab" data-tab="participants" aria-label="Participants">
              <span class="icon-participants"></span>
              Participants
            </button>
            <button class="sidebar-tab" data-tab="comments" aria-label="Comments">
              <span class="icon-comments"></span>
              Comments
            </button>
          </div>

          <div class="sidebar-content">
            <div class="sidebar-panel fields-panel active">
              <div class="panel-header">
                <h3>Signature Fields</h3>
                <button class="btn-icon icon-add" data-action="add-field-menu" title="Add Field"></button>
              </div>
              <div class="fields-list"></div>
            </div>

            <div class="sidebar-panel participants-panel">
              <div class="panel-header">
                <h3>Participants</h3>
                <button class="btn-icon icon-add" data-action="add-participant" title="Add Participant"></button>
              </div>
              <div class="participants-list"></div>
            </div>

            <div class="sidebar-panel comments-panel">
              <div class="panel-header">
                <h3>Comments</h3>
              </div>
              <div class="comments-list"></div>
              <div class="comment-form">
                <textarea placeholder="Add a comment..." class="comment-input"></textarea>
                <button class="btn-primary add-comment">Add Comment</button>
              </div>
            </div>
          </div>
        </div>

        <div class="document-main">
          <div class="document-container">
            <div class="document-pages"></div>
            <div class="signature-overlay"></div>
            <div class="field-selection-overlay" style="display: none;"></div>
          </div>

          <div class="search-overlay" style="display: none;">
            <div class="search-controls">
              <input type="text" class="search-input" placeholder="Search in document..." aria-label="Search text">
              <button class="btn-icon icon-search" data-action="search-execute" title="Search"></button>
              <button class="btn-icon icon-close" data-action="search-close" title="Close Search"></button>
            </div>
            <div class="search-results"></div>
          </div>
        </div>
      </div>

      <div class="document-viewer-footer">
        <div class="footer-info">
          <span class="zoom-info">Zoom: <span class="zoom-percentage">100%</span></span>
          <span class="page-info">Page <span class="current-page-footer">1</span> of <span class="total-pages-footer">1</span></span>
          <span class="selection-info" style="display: none;"></span>
        </div>

        <div class="footer-actions">
          <button class="btn-icon icon-sidebar" data-action="toggle-sidebar" title="Toggle Sidebar" aria-label="Toggle sidebar"></button>
          <button class="btn-icon icon-fullscreen" data-action="fullscreen" title="Fullscreen" aria-label="Enter fullscreen"></button>
        </div>
      </div>
    `;

    // Cache DOM elements
    this.elements = {
      header: this.container.querySelector('.document-viewer-header'),
      toolbar: this.container.querySelector('.document-toolbar'),
      statusBar: this.container.querySelector('.document-status-bar'),
      sidebar: this.container.querySelector('.document-sidebar'),
      main: this.container.querySelector('.document-main'),
      container: this.container.querySelector('.document-container'),
      pages: this.container.querySelector('.document-pages'),
      overlay: this.container.querySelector('.signature-overlay'),
      selectionOverlay: this.container.querySelector('.field-selection-overlay'),
      searchOverlay: this.container.querySelector('.search-overlay'),
      footer: this.container.querySelector('.document-viewer-footer'),

      // Controls
      currentPageInput: this.container.querySelector('.current-page'),
      totalPagesSpan: this.container.querySelector('.total-pages'),
      zoomSelect: this.container.querySelector('.zoom-level'),

      // Status elements
      documentName: this.container.querySelector('.document-name'),
      documentStatus: this.container.querySelector('.document-status'),
      participantInfo: this.container.querySelector('.participant-info'),

      // Progress indicator
      progressIndicator: this.container.querySelector('.progress-indicator'),
      progressBar: this.container.querySelector('.progress-fill'),
      progressText: this.container.querySelector('.progress-text')
    };

    // Don't append to body yet - will be done when show() is called
  }

  /**
   * Bind event listeners
   * @private
   */
  bindEvents() {
    // Toolbar actions
    this.container.addEventListener('click', (e) => {
      const action = e.target.dataset.action;
      if (action) {
        e.preventDefault();
        this.handleToolbarAction(action, e.target);
      }
    });

    // Page navigation
    this.elements.currentPageInput.addEventListener('change', (e) => {
      const page = parseInt(e.target.value, 10);
      if (page >= 1 && page <= this.state.totalPages) {
        this.goToPage(page);
      } else {
        e.target.value = this.state.currentPage;
      }
    });

    // Zoom control
    this.elements.zoomSelect.addEventListener('change', (e) => {
      this.setZoom(e.target.value);
    });

    // Document container events
    this.elements.container.addEventListener('mousedown', this.handleMouseDown.bind(this));
    this.elements.container.addEventListener('mousemove', this.handleMouseMove.bind(this));
    this.elements.container.addEventListener('mouseup', this.handleMouseUp.bind(this));

    // Touch events for mobile
    this.elements.container.addEventListener('touchstart', this.handleTouchStart.bind(this));
    this.elements.container.addEventListener('touchmove', this.handleTouchMove.bind(this));
    this.elements.container.addEventListener('touchend', this.handleTouchEnd.bind(this));

    // Scroll handling for continuous view
    this.elements.main.addEventListener('scroll', this.handleScroll.bind(this));

    // Keyboard shortcuts
    document.addEventListener('keydown', (e) => {
      if (!this.isVisible()) return;
      this.handleKeyboardShortcut(e);
    });

    // Window resize
    window.addEventListener('resize', this.handleResize.bind(this));

    // Signature field events
    this.container.addEventListener('signatureFieldCreated', this.handleSignatureFieldCreated.bind(this));
    this.container.addEventListener('signatureFieldUpdated', this.handleSignatureFieldUpdated.bind(this));
    this.container.addEventListener('signatureFieldDeleted', this.handleSignatureFieldDeleted.bind(this));
  }

  /**
   * Load external dependencies
   * @private
   */
  async loadDependencies() {
    try {
      // Load PDF.js if needed
      if (this.options.renderingEngine === 'pdf.js' && !window.pdfjsLib) {
        await this.loadPdfJs();
      }

      // Initialize MediaViewer integration
      if (window.MediaViewer) {
        this.components.mediaViewer = new MediaViewer({
          showThumbnails: false,
          showControls: false,
          enableZoom: true,
          maxZoom: this.options.maxZoom,
          onShow: this.handleMediaViewerShow.bind(this),
          onHide: this.handleMediaViewerHide.bind(this)
        });
      }

      // Initialize SignaturePad integration
      if (window.SignaturePad) {
        this.components.signaturePad = new SignaturePad({
          onSave: this.handleSignatureSave.bind(this),
          onCancel: this.handleSignatureCancel.bind(this)
        });
      }

    } catch (error) {
      console.error('Failed to load DocumentViewer dependencies:', error);
      this.handleError('DEPENDENCY_LOAD_ERROR', error);
    }
  }

  /**
   * Load PDF.js library
   * @private
   */
  async loadPdfJs() {
    return new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';
      script.onload = () => {
        window.pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
        resolve();
      };
      script.onerror = reject;
      document.head.appendChild(script);
    });
  }

  /**
   * Show document viewer
   * @param {string|File|ArrayBuffer} document Document to display
   * @param {Object} options Display options
   */
  async show(document, options = {}) {
    try {
      // Merge options
      this.options = {...this.options, ...options};

      // Show loading state
      this.showLoading('Loading document...');

      // Add to DOM if not already added
      if (!this.container.parentNode) {
        document.body.appendChild(this.container);
      }

      this.container.classList.add('visible');
      this.container.setAttribute('aria-hidden', 'false');

      // Load and render document
      await this.loadDocument(document);

      // Update UI
      this.updateUI();

      // Hide loading state
      this.hideLoading();

      // Focus for accessibility
      this.container.focus();

      // Trigger callback
      if (this.options.onDocumentLoad) {
        this.options.onDocumentLoad(this.state.document);
      }

    } catch (error) {
      this.hideLoading();
      this.handleError('DOCUMENT_LOAD_ERROR', error);
    }
  }

  /**
   * Hide document viewer
   */
  hide() {
    this.container.classList.remove('visible');
    this.container.setAttribute('aria-hidden', 'true');

    // Clean up resources
    this.cleanup();

    // Remove from DOM
    if (this.container.parentNode) {
      this.container.parentNode.removeChild(this.container);
    }

    // Restore body scroll
    document.body.style.overflow = '';
  }

  /**
   * Load document
   * @param {string|File|ArrayBuffer} document Document source
   * @private
   */
  async loadDocument(document) {
    // Determine document type and source
    let documentType = 'pdf';
    let documentSource = document;

    if (document instanceof File) {
      documentType = this.getDocumentType(document.name, document.type);
      documentSource = await this.fileToArrayBuffer(document);
    } else if (typeof document === 'string') {
      documentType = this.getDocumentType(document);
      // URL will be handled by the renderer
    }

    // Load based on rendering engine and document type
    switch (this.options.renderingEngine) {
      case 'pdf.js':
        if (documentType === 'pdf') {
          await this.loadPdfDocument(documentSource);
        } else {
          throw new Error(`PDF.js cannot render ${documentType} files`);
        }
        break;

      case 'iframe':
        await this.loadIframeDocument(documentSource);
        break;

      case 'image':
        await this.loadImageDocument(documentSource);
        break;

      default:
        throw new Error(`Unknown rendering engine: ${this.options.renderingEngine}`);
    }
  }

  /**
   * Load PDF document using PDF.js
   * @param {string|ArrayBuffer} source PDF source
   * @private
   */
  async loadPdfDocument(source) {
    const loadingTask = window.pdfjsLib.getDocument(source);

    loadingTask.onProgress = (progress) => {
      if (progress.total > 0) {
        const percentage = Math.round((progress.loaded / progress.total) * 100);
        this.updateProgress(percentage, `Loading PDF... ${percentage}%`);
      }
    };

    this.state.document = await loadingTask.promise;
    this.state.totalPages = this.state.document.numPages;

    // Render initial page
    await this.renderPages();
  }

  /**
   * Render PDF pages
   * @private
   */
  async renderPages() {
    this.elements.pages.innerHTML = '';
    this.state.loadedPages.clear();

    const viewMode = this.state.viewMode;

    if (viewMode === 'single') {
      await this.renderPage(this.state.currentPage);
    } else if (viewMode === 'continuous') {
      // Render all pages for continuous view
      for (let i = 1; i <= this.state.totalPages; i++) {
        await this.renderPage(i);
      }
    } else if (viewMode === 'facing') {
      // Render facing pages
      const leftPage = this.state.currentPage;
      const rightPage = leftPage + 1;

      if (leftPage <= this.state.totalPages) {
        await this.renderPage(leftPage);
      }
      if (rightPage <= this.state.totalPages) {
        await this.renderPage(rightPage);
      }
    }
  }

  /**
   * Render single page
   * @param {number} pageNumber Page number to render
   * @private
   */
  async renderPage(pageNumber) {
    if (this.state.loadedPages.has(pageNumber)) {
      return;
    }

    const page = await this.state.document.getPage(pageNumber);

    // Calculate scale based on fit mode
    const scale = this.calculateScale(page);
    const viewport = page.getViewport({scale, rotation: this.state.rotation});

    // Create page container
    const pageContainer = document.createElement('div');
    pageContainer.className = 'document-page';
    pageContainer.dataset.pageNumber = pageNumber;
    pageContainer.style.width = `${viewport.width}px`;
    pageContainer.style.height = `${viewport.height}px`;

    // Create canvas
    const canvas = document.createElement('canvas');
    const context = canvas.getContext('2d');
    canvas.width = viewport.width;
    canvas.height = viewport.height;

    pageContainer.appendChild(canvas);

    // Add to pages container
    this.elements.pages.appendChild(pageContainer);

    // Render page content
    const renderContext = {
      canvasContext: context,
      viewport: viewport
    };

    await page.render(renderContext).promise;

    // Mark as loaded
    this.state.loadedPages.add(pageNumber);

    // Add text layer if text selection is enabled
    if (this.options.enableTextSelection) {
      await this.addTextLayer(page, pageContainer, viewport);
    }

    // Render signature fields for this page
    this.renderSignatureFields(pageNumber);
  }

  /**
   * Add text layer for text selection
   * @param {Object} page PDF page object
   * @param {HTMLElement} container Page container
   * @param {Object} viewport Page viewport
   * @private
   */
  async addTextLayer(page, container, viewport) {
    const textContent = await page.getTextContent();

    const textLayer = document.createElement('div');
    textLayer.className = 'text-layer';
    textLayer.style.width = `${viewport.width}px`;
    textLayer.style.height = `${viewport.height}px`;

    container.appendChild(textLayer);

    // Render text layer
    window.pdfjsLib.renderTextLayer({
      textContent: textContent,
      container: textLayer,
      viewport: viewport,
      textDivs: []
    });
  }

  /**
   * Calculate appropriate scale for page rendering
   * @param {Object} page PDF page object
   * @returns {number} Scale factor
   * @private
   */
  calculateScale(page) {
    const containerWidth = this.elements.main.clientWidth - 40; // Account for padding
    const containerHeight = this.elements.main.clientHeight - 40;

    const pageViewport = page.getViewport({scale: 1, rotation: this.state.rotation});

    let scale = this.state.scale;

    if (this.options.fitMode === 'width') {
      scale = containerWidth / pageViewport.width;
    } else if (this.options.fitMode === 'height') {
      scale = containerHeight / pageViewport.height;
    } else if (this.options.fitMode === 'page') {
      const widthScale = containerWidth / pageViewport.width;
      const heightScale = containerHeight / pageViewport.height;
      scale = Math.min(widthScale, heightScale);
    }

    // Apply zoom limits
    scale = Math.max(this.options.minZoom, Math.min(this.options.maxZoom, scale));

    return scale;
  }

  /**
   * Handle toolbar actions
   * @param {string} action Action name
   * @param {HTMLElement} button Button element
   * @private
   */
  handleToolbarAction(action, button) {
    switch (action) {
      case 'first-page':
        this.goToPage(1);
        break;

      case 'prev-page':
        this.goToPage(Math.max(1, this.state.currentPage - 1));
        break;

      case 'next-page':
        this.goToPage(Math.min(this.state.totalPages, this.state.currentPage + 1));
        break;

      case 'last-page':
        this.goToPage(this.state.totalPages);
        break;

      case 'zoom-in':
        this.zoomIn();
        break;

      case 'zoom-out':
        this.zoomOut();
        break;

      case 'rotate':
        this.rotate();
        break;

      case 'search':
        this.toggleSearch();
        break;

      case 'print':
        this.print();
        break;

      case 'download':
        this.download();
        break;

      case 'toggle-sidebar':
        this.toggleSidebar();
        break;

      case 'fullscreen':
        this.toggleFullscreen();
        break;

      case 'send-for-signature':
        this.sendForSignature();
        break;

      case 'save-draft':
        this.saveDraft();
        break;

      // Signature field actions
      case 'add-signature':
      case 'add-initial':
      case 'add-date':
      case 'add-text':
      case 'add-checkbox':
        this.startFieldPlacement(button.dataset.fieldType);
        break;
    }
  }

  /**
   * Go to specific page
   * @param {number} pageNumber Page number
   */
  goToPage(pageNumber) {
    if (pageNumber < 1 || pageNumber > this.state.totalPages || pageNumber === this.state.currentPage) {
      return;
    }

    this.state.currentPage = pageNumber;
    this.elements.currentPageInput.value = pageNumber;

    if (this.state.viewMode === 'single') {
      this.renderPages();
    } else {
      // Scroll to page in continuous view
      const pageElement = this.elements.pages.querySelector(`[data-page-number="${pageNumber}"]`);
      if (pageElement) {
        pageElement.scrollIntoView({behavior: 'smooth', block: 'start'});
      }
    }

    this.updateUI();

    if (this.options.onPageChange) {
      this.options.onPageChange(pageNumber);
    }
  }

  /**
   * Set zoom level
   * @param {string|number} zoom Zoom level
   */
  setZoom(zoom) {
    let newScale = this.state.scale;

    if (typeof zoom === 'string') {
      if (zoom === 'auto' || zoom === 'page') {
        this.options.fitMode = 'page';
        this.renderPages();
        return;
      } else if (zoom === 'width') {
        this.options.fitMode = 'width';
        this.renderPages();
        return;
      } else {
        newScale = parseFloat(zoom);
      }
    } else {
      newScale = zoom;
    }

    newScale = Math.max(this.options.minZoom, Math.min(this.options.maxZoom, newScale));

    if (newScale !== this.state.scale) {
      this.state.scale = newScale;
      this.options.fitMode = 'actual';
      this.renderPages();
      this.updateUI();
    }
  }

  /**
   * Zoom in
   */
  zoomIn() {
    const newScale = this.state.scale + this.options.zoomStep;
    this.setZoom(newScale);
  }

  /**
   * Zoom out
   */
  zoomOut() {
    const newScale = this.state.scale - this.options.zoomStep;
    this.setZoom(newScale);
  }

  /**
   * Rotate document
   */
  rotate() {
    this.state.rotation = (this.state.rotation + 90) % 360;
    this.renderPages();
  }

  /**
   * Start signature field placement
   * @param {string} fieldType Type of field to place
   */
  startFieldPlacement(fieldType) {
    if (!this.options.enableSignaturePlacement) {
      return;
    }

    this.state.isPlacingField = true;
    this.state.placingFieldType = fieldType;

    this.container.classList.add('placing-field');
    this.elements.container.style.cursor = 'crosshair';

    // Update UI feedback
    this.showPlacementInstructions(fieldType);
  }

  /**
   * Show field placement instructions
   * @param {string} fieldType Field type
   * @private
   */
  showPlacementInstructions(fieldType) {
    const instructions = {
      signature: 'Click and drag to place a signature field',
      initial: 'Click and drag to place an initial field',
      date: 'Click to place a date field',
      text: 'Click and drag to place a text field',
      checkbox: 'Click to place a checkbox'
    };

    // Show tooltip or status message
    this.showStatusMessage(instructions[fieldType] || 'Click to place field');
  }

  /**
   * Handle mouse events for field placement and interaction
   * @param {MouseEvent} e Mouse event
   * @private
   */
  handleMouseDown(e) {
    if (this.state.isPlacingField) {
      this.startFieldCreation(e);
    } else {
      const field = this.getSignatureFieldFromEvent(e);
      if (field) {
        this.selectSignatureField(field);
      }
    }
  }

  /**
   * Start creating a new signature field
   * @param {MouseEvent} e Mouse event
   * @private
   */
  startFieldCreation(e) {
    const rect = this.elements.container.getBoundingClientRect();
    const pageElement = e.target.closest('.document-page');

    if (!pageElement) return;

    const pageNumber = parseInt(pageElement.dataset.pageNumber, 10);
    const pageRect = pageElement.getBoundingClientRect();

    // Calculate position relative to page
    const x = e.clientX - pageRect.left;
    const y = e.clientY - pageRect.top;

    const fieldId = this.generateFieldId();
    const fieldData = {
      id: fieldId,
      type: this.state.placingFieldType,
      page: pageNumber,
      x: x,
      y: y,
      width: this.options.signatureFieldDefaultSize,
      height: this.options.signatureFieldDefaultSize * 0.6,
      required: true,
      assignedTo: null,
      value: null,
      placeholder: this.getFieldPlaceholder(this.state.placingFieldType)
    };

    // Create field
    this.createSignatureField(fieldData);

    // End placement mode
    this.endFieldPlacement();
  }

  /**
   * Create signature field
   * @param {Object} fieldData Field configuration
   */
  createSignatureField(fieldData) {
    // Add to state
    this.state.signatureFields.set(fieldData.id, fieldData);

    // Render field
    this.renderSignatureField(fieldData);

    // Update sidebar
    this.updateFieldsList();

    // Trigger callback
    if (this.options.onSignatureFieldAdd) {
      this.options.onSignatureFieldAdd(fieldData);
    }

    // Dispatch event
    this.container.dispatchEvent(new CustomEvent('signatureFieldCreated', {
      detail: {field: fieldData}
    }));
  }

  /**
   * Render signature field on page
   * @param {Object} fieldData Field data
   * @private
   */
  renderSignatureField(fieldData) {
    const pageElement = this.elements.pages.querySelector(`[data-page-number="${fieldData.page}"]`);
    if (!pageElement) return;

    const fieldElement = document.createElement('div');
    fieldElement.className = `signature-field signature-field-${fieldData.type}`;
    fieldElement.dataset.fieldId = fieldData.id;
    fieldElement.style.left = `${fieldData.x}px`;
    fieldElement.style.top = `${fieldData.y}px`;
    fieldElement.style.width = `${fieldData.width}px`;
    fieldElement.style.height = `${fieldData.height}px`;

    fieldElement.innerHTML = `
      <div class="field-content">
        <div class="field-placeholder">${fieldData.placeholder}</div>
        <div class="field-value" style="display: none;"></div>
      </div>
      <div class="field-controls">
        <button class="field-control-btn field-edit" title="Edit Field">
          <span class="icon-edit"></span>
        </button>
        <button class="field-control-btn field-delete" title="Delete Field">
          <span class="icon-delete"></span>
        </button>
      </div>
      <div class="field-resize-handles">
        <div class="resize-handle resize-nw"></div>
        <div class="resize-handle resize-ne"></div>
        <div class="resize-handle resize-sw"></div>
        <div class="resize-handle resize-se"></div>
      </div>
    `;

    // Add event listeners
    this.bindFieldEvents(fieldElement, fieldData);

    // Add to page
    pageElement.appendChild(fieldElement);
  }

  /**
   * Bind events to signature field
   * @param {HTMLElement} fieldElement Field element
   * @param {Object} fieldData Field data
   * @private
   */
  bindFieldEvents(fieldElement, fieldData) {
    // Click to select/edit
    fieldElement.addEventListener('click', (e) => {
      e.stopPropagation();

      if (e.target.classList.contains('field-edit')) {
        this.editSignatureField(fieldData.id);
      } else if (e.target.classList.contains('field-delete')) {
        this.deleteSignatureField(fieldData.id);
      } else {
        this.selectSignatureField(fieldData.id);
      }
    });

    // Double-click to open signature pad
    fieldElement.addEventListener('dblclick', (e) => {
      e.stopPropagation();
      this.openSignaturePad(fieldData.id);
    });

    // Drag to move
    let isDragging = false;
    let dragStart = {x: 0, y: 0};

    fieldElement.addEventListener('mousedown', (e) => {
      if (e.target.classList.contains('resize-handle')) return;

      isDragging = true;
      dragStart.x = e.offsetX;
      dragStart.y = e.offsetY;

      fieldElement.classList.add('dragging');
    });

    document.addEventListener('mousemove', (e) => {
      if (!isDragging) return;

      const pageElement = fieldElement.closest('.document-page');
      const pageRect = pageElement.getBoundingClientRect();

      const newX = e.clientX - pageRect.left - dragStart.x;
      const newY = e.clientY - pageRect.top - dragStart.y;

      // Update position
      fieldElement.style.left = `${Math.max(0, newX)}px`;
      fieldElement.style.top = `${Math.max(0, newY)}px`;

      // Update data
      fieldData.x = newX;
      fieldData.y = newY;
    });

    document.addEventListener('mouseup', () => {
      if (isDragging) {
        isDragging = false;
        fieldElement.classList.remove('dragging');

        // Update field data
        this.updateSignatureField(fieldData.id, {
          x: fieldData.x,
          y: fieldData.y
        });
      }
    });
  }

  /**
   * Update UI elements
   * @private
   */
  updateUI() {
    // Update page controls
    this.elements.currentPageInput.value = this.state.currentPage;
    this.elements.totalPagesSpan.textContent = this.state.totalPages;
    this.container.querySelector('.current-page-footer').textContent = this.state.currentPage;
    this.container.querySelector('.total-pages-footer').textContent = this.state.totalPages;

    // Update zoom display
    const zoomPercentage = Math.round(this.state.scale * 100);
    this.container.querySelector('.zoom-percentage').textContent = `${zoomPercentage}%`;
    this.elements.zoomSelect.value = this.state.scale.toString();

    // Update status
    this.updateStatus();
  }

  /**
   * Update status information
   * @private
   */
  updateStatus() {
    if (this.options.documentName) {
      this.elements.documentName.textContent = this.options.documentName;
    }

    const statusText = this.getWorkflowStatusText();
    this.elements.documentStatus.textContent = statusText;

    if (this.state.currentParticipant) {
      this.elements.participantInfo.textContent = `Assigned to: ${this.state.currentParticipant.name}`;
    }
  }

  /**
   * Get workflow status text
   * @returns {string} Status text
   * @private
   */
  getWorkflowStatusText() {
    const statusMap = {
      preparation: 'Preparing Document',
      signing: 'Awaiting Signatures',
      completed: 'Completed'
    };

    return statusMap[this.state.workflowStage] || 'Unknown Status';
  }

  /**
   * Show loading state
   * @param {string} message Loading message
   * @private
   */
  showLoading(message = 'Loading...') {
    this.elements.progressIndicator.style.display = 'flex';
    this.elements.progressText.textContent = message;
    this.elements.progressBar.style.width = '0%';
  }

  /**
   * Update loading progress
   * @param {number} percentage Progress percentage (0-100)
   * @param {string} message Progress message
   * @private
   */
  updateProgress(percentage, message) {
    this.elements.progressBar.style.width = `${percentage}%`;
    if (message) {
      this.elements.progressText.textContent = message;
    }
  }

  /**
   * Hide loading state
   * @private
   */
  hideLoading() {
    this.elements.progressIndicator.style.display = 'none';
  }

  /**
   * Handle errors
   * @param {string} errorType Error type
   * @param {Error} error Error object
   * @private
   */
  handleError(errorType, error) {
    console.error(`DocumentViewer ${errorType}:`, error);

    const errorMessage = this.getErrorMessage(errorType, error);
    this.showStatusMessage(errorMessage, 'error');

    if (this.options.onError) {
      this.options.onError(errorType, error);
    }
  }

  /**
   * Get user-friendly error message
   * @param {string} errorType Error type
   * @param {Error} error Error object
   * @returns {string} Error message
   * @private
   */
  getErrorMessage(errorType, error) {
    const errorMessages = {
      DOCUMENT_LOAD_ERROR: 'Failed to load document. Please check the file and try again.',
      DEPENDENCY_LOAD_ERROR: 'Failed to load required components. Please refresh the page.',
      RENDER_ERROR: 'Failed to render document page. The file may be corrupted.',
      SIGNATURE_ERROR: 'Failed to process signature. Please try again.'
    };

    return errorMessages[errorType] || 'An unexpected error occurred.';
  }

  /**
   * Show status message
   * @param {string} message Message text
   * @param {string} type Message type ('info', 'success', 'warning', 'error')
   * @private
   */
  showStatusMessage(message, type = 'info') {
    // Use NotificationManager if available
    if (window.NotificationManager) {
      NotificationManager.show(message, type);
    } else {
      // Fallback to console
      if (this.config?.debug) console.info(`DocumentViewer ${type}:`, message);
    }
  }

  /**
   * Check if viewer is visible
   * @returns {boolean} Visibility state
   */
  isVisible() {
    return this.container.classList.contains('visible');
  }

  /**
   * Clean up resources
   * @private
   */
  cleanup() {
    // Clear loaded pages
    this.state.loadedPages.clear();
    this.state.visiblePages.clear();

    // Reset state
    this.state.document = null;
    this.state.currentPage = 1;
    this.state.totalPages = 0;
    this.state.selectedField = null;

    // Clear signature fields
    this.state.signatureFields.clear();

    // Stop any ongoing operations
    this.state.renderingInProgress = false;
    this.state.isPlacingField = false;
  }

  /**
   * Utility methods
   */

  /**
   * Generate unique field ID
   * @returns {string} Field ID
   * @private
   */
  generateFieldId() {
    return `field-${Date.now()}-${++this.state.fieldIdCounter}`;
  }

  /**
   * Get field placeholder text
   * @param {string} fieldType Field type
   * @returns {string} Placeholder text
   * @private
   */
  getFieldPlaceholder(fieldType) {
    const placeholders = {
      signature: 'Signature',
      initial: 'Initial',
      date: 'Date',
      text: 'Text',
      checkbox: '☐'
    };

    return placeholders[fieldType] || 'Field';
  }

  /**
   * Get document type from filename or MIME type
   * @param {string} filename Filename
   * @param {string} mimeType MIME type
   * @returns {string} Document type
   * @private
   */
  getDocumentType(filename, mimeType) {
    if (mimeType) {
      if (mimeType.includes('pdf')) return 'pdf';
      if (mimeType.includes('image')) return 'image';
    }

    const ext = filename.split('.').pop().toLowerCase();
    if (ext === 'pdf') return 'pdf';
    if (['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'].includes(ext)) return 'image';

    return 'pdf'; // Default to PDF
  }

  /**
   * Convert File to ArrayBuffer
   * @param {File} file File object
   * @returns {Promise<ArrayBuffer>} ArrayBuffer
   * @private
   */
  fileToArrayBuffer(file) {
    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = () => resolve(reader.result);
      reader.onerror = reject;
      reader.readAsArrayBuffer(file);
    });
  }

  /**
   * API Methods for external use
   */

  /**
   * Add signature field programmatically
   * @param {Object} fieldData Field configuration
   * @returns {string} Field ID
   */
  addSignatureField(fieldData) {
    const field = {
      id: this.generateFieldId(),
      type: 'signature',
      page: 1,
      x: 50,
      y: 50,
      width: this.options.signatureFieldDefaultSize,
      height: this.options.signatureFieldDefaultSize * 0.6,
      required: false,
      assignedTo: null,
      value: null,
      placeholder: 'Signature',
      ...fieldData
    };

    this.createSignatureField(field);
    return field.id;
  }

  /**
   * Get all signature fields
   * @returns {Array} Array of signature fields
   */
  getSignatureFields() {
    return Array.from(this.state.signatureFields.values());
  }

  /**
   * Get signature field by ID
   * @param {string} fieldId Field ID
   * @returns {Object|null} Field data
   */
  getSignatureField(fieldId) {
    return this.state.signatureFields.get(fieldId) || null;
  }

  /**
   * Update signature field
   * @param {string} fieldId Field ID
   * @param {Object} updates Field updates
   */
  updateSignatureField(fieldId, updates) {
    const field = this.state.signatureFields.get(fieldId);
    if (!field) return;

    Object.assign(field, updates);

    // Re-render field if needed
    const fieldElement = this.container.querySelector(`[data-field-id="${fieldId}"]`);
    if (fieldElement) {
      this.updateFieldElement(fieldElement, field);
    }

    // Trigger callback
    if (this.options.onSignatureFieldUpdate) {
      this.options.onSignatureFieldUpdate(field);
    }

    // Dispatch event
    this.container.dispatchEvent(new CustomEvent('signatureFieldUpdated', {
      detail: {field}
    }));
  }

  /**
   * Delete signature field
   * @param {string} fieldId Field ID
   */
  deleteSignatureField(fieldId) {
    const field = this.state.signatureFields.get(fieldId);
    if (!field) return;

    // Remove from state
    this.state.signatureFields.delete(fieldId);

    // Remove from DOM
    const fieldElement = this.container.querySelector(`[data-field-id="${fieldId}"]`);
    if (fieldElement) {
      fieldElement.remove();
    }

    // Update sidebar
    this.updateFieldsList();

    // Trigger callback
    if (this.options.onSignatureFieldDelete) {
      this.options.onSignatureFieldDelete(field);
    }

    // Dispatch event
    this.container.dispatchEvent(new CustomEvent('signatureFieldDeleted', {
      detail: {field}
    }));
  }

  /**
   * Export document with signatures
   * @param {Object} options Export options
   * @returns {Promise<Blob>} Exported document
   */
  async exportDocument(options = {}) {
    // Implementation depends on the backend API
    // This would flatten the signature fields into the PDF
    throw new Error('Export functionality requires backend integration');
  }

  /**
   * Set document workflow stage
   * @param {string} stage Workflow stage
   */
  setWorkflowStage(stage) {
    this.state.workflowStage = stage;
    this.updateStatus();

    if (this.options.onWorkflowUpdate) {
      this.options.onWorkflowUpdate(stage);
    }
  }

  /**
   * Destroy DocumentViewer instance
   */
  destroy() {
    this.cleanup();

    if (this.container.parentNode) {
      this.container.parentNode.removeChild(this.container);
    }

    // Clean up components
    if (this.components.mediaViewer) {
      this.components.mediaViewer.destroy();
    }

    if (this.components.signaturePad) {
      this.components.signaturePad.destroy();
    }
  }

  // Placeholder methods for features that need implementation
  handleMouseMove(e) { /* TODO: Implement mouse move handling */}
  handleMouseUp(e) { /* TODO: Implement mouse up handling */}
  handleTouchStart(e) { /* TODO: Implement touch start handling */}
  handleTouchMove(e) { /* TODO: Implement touch move handling */}
  handleTouchEnd(e) { /* TODO: Implement touch end handling */}
  handleScroll(e) { /* TODO: Implement scroll handling */}
  handleKeyboardShortcut(e) { /* TODO: Implement keyboard shortcuts */}
  handleResize(e) { /* TODO: Implement resize handling */}
  handleSignatureFieldCreated(e) { /* TODO: Implement field created handler */}
  handleSignatureFieldUpdated(e) { /* TODO: Implement field updated handler */}
  handleSignatureFieldDeleted(e) { /* TODO: Implement field deleted handler */}
  handleMediaViewerShow(item) { /* TODO: Implement media viewer integration */}
  handleMediaViewerHide() { /* TODO: Implement media viewer integration */}
  handleSignatureSave(signature) { /* TODO: Implement signature save */}
  handleSignatureCancel() { /* TODO: Implement signature cancel */}
  loadIframeDocument(source) { /* TODO: Implement iframe document loading */}
  loadImageDocument(source) { /* TODO: Implement image document loading */}
  renderSignatureFields(pageNumber) { /* TODO: Implement signature fields rendering */}
  endFieldPlacement() { /* TODO: Implement field placement end */}
  getSignatureFieldFromEvent(e) { /* TODO: Implement field detection from event */}
  selectSignatureField(fieldId) { /* TODO: Implement field selection */}
  editSignatureField(fieldId) { /* TODO: Implement field editing */}
  openSignaturePad(fieldId) { /* TODO: Implement signature pad opening */}
  updateFieldsList() { /* TODO: Implement fields list update */}
  updateFieldElement(element, field) { /* TODO: Implement field element update */}
  toggleSearch() { /* TODO: Implement search toggle */}
  print() { /* TODO: Implement print functionality */}
  download() { /* TODO: Implement download functionality */}
  toggleSidebar() { /* TODO: Implement sidebar toggle */}
  toggleFullscreen() { /* TODO: Implement fullscreen toggle */}
  sendForSignature() { /* TODO: Implement send for signature */}
  saveDraft() { /* TODO: Implement save draft */}
}

// Register with Now.js framework
if (typeof Now !== 'undefined') {
  Now.registerComponent('DocumentViewer', DocumentViewer);
}

// Export for module use
if (typeof module !== 'undefined' && module.exports) {
  module.exports = DocumentViewer;
}

// Expose globally
window.DocumentViewer = DocumentViewer;
