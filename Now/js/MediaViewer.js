/**
 * MediaViewer Component
 * Unified media viewer with slideshow, zoom and fullscreen capabilities
 * Supports images, videos, YouTube, and custom content
 *
 * Features:
 * - Slideshow with thumbnails
 * - Image zoom and pan
 * - YouTube video embedding
 * - Responsive design
 * - Touch gestures
 * - Keyboard controls
 * - Accessibility support
 */
class MediaViewer {
  /**
   * Create MediaViewer instance
   * @param {Object} options Configuration options
   */
  constructor(options = {}) {
    this.options = {
      showThumbnails: false,
      showControls: true,

      autoplay: false,
      interval: 5000,
      loop: true,

      enableZoom: true,
      maxZoom: 3,
      zoomStep: 0.5,

      renderers: {
        image: this.renderImage.bind(this),
        video: this.renderVideo.bind(this),
        youtube: this.renderYouTube.bind(this),
        iframe: this.renderIframe.bind(this),
        text: this.renderText.bind(this),
        html: this.renderHtml.bind(this)
      },

      onShow: null,
      onHide: null,
      onChange: null,

      ...options
    };

    this.state = {
      visible: false,
      currentIndex: 0,
      items: [],
      zoom: 1,
      pan: {x: 0, y: 0},
      isDragging: false,
      startPos: {x: 0, y: 0},
      isPlaying: false,
      playTimer: null,
      naturalZoom: 1
    };

    this._baseMaxZoom = this.options.maxZoom;
    this.setupDOM();
    this.bindEvents();
  }

  /**
   * Set up DOM structure
   * @private
   */
  setupDOM() {
    this.container = document.createElement('div');
    this.container.className = 'media-viewer';
    this.container.setAttribute('role', 'dialog');
    this.container.setAttribute('aria-modal', 'true');
    this.container.setAttribute('aria-hidden', 'true');

    this.container.innerHTML = `
      <div class="media-counter"></div>

      <div class="media-viewer-header">
        <div class="media-viewer-controls">
          ${this.options.showControls ? `
            <button class="zoom-out icon-zoom-out" aria-label="Zoom out"></button>
            <button class="zoom-reset icon-zoom-reset" aria-label="Reset zoom"></button>
            <button class="zoom-in icon-zoom-in" aria-label="Zoom in"></button>
          ` : ''}
          <button class="close-button" aria-label="Close"></button>
        </div>
      </div>

      <div class="media-viewer-content">
        <button class="nav-button icon-prev prev" aria-label="Previous"></button>
        <div class="media-stage"></div>
        <button class="nav-button icon-next next" aria-label="Next"></button>
        <div class="media-caption"></div>
      </div>

      ${this.options.showThumbnails ? `
        <div class="media-viewer-thumbnails">
          <div class="thumbnail-track"></div>
        </div>
      ` : ''}
    `;

    this.stage = this.container.querySelector('.media-stage');
    this.thumbnailTrack = this.container.querySelector('.thumbnail-track');
    this.captionEl = this.container.querySelector('.media-caption');
    this.counterEl = this.container.querySelector('.media-counter');

    document.body.appendChild(this.container);
  }

  /**
   * Bind event listeners
   * @private
   */
  bindEvents() {
    this.container.querySelector('.close-button').onclick = () => this.hide();
    this.container.querySelector('.nav-button.prev').onclick = () => this.prev();
    this.container.querySelector('.nav-button.next').onclick = () => this.next();

    if (this.options.showControls) {
      this.container.querySelector('.zoom-in').onclick = () => this.zoomIn();
      this.container.querySelector('.zoom-out').onclick = () => this.zoomOut();
      this.container.querySelector('.zoom-reset').onclick = () => this.resetZoom();
    }

    document.addEventListener('keydown', (e) => {
      if (!this.state.visible) return;

      switch (e.key) {
        case 'Escape': this.hide(); break;
        case 'ArrowLeft': this.prev(); break;
        case 'ArrowRight': this.next(); break;
        case '+': this.zoomIn(); break;
        case '-': this.zoomOut(); break;
        case '0': this.resetZoom(); break;
      }
    });

    let touchStartX = 0;
    let touchStartY = 0;
    let pinchStartDist = 0;
    let pinchStartZoom = 1;
    let isTouchPanning = false;
    let isPinching = false;

    this.stage.addEventListener('touchstart', (e) => {
      if (e.touches.length === 2) {
        isPinching = true;
        isTouchPanning = false;
        pinchStartDist = Math.hypot(
          e.touches[0].clientX - e.touches[1].clientX,
          e.touches[0].clientY - e.touches[1].clientY
        );
        pinchStartZoom = this.state.zoom;
        const mi = this.stage.querySelector('.media-item');
        if (mi) mi.style.transition = 'opacity 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94)';
        e.preventDefault();
      } else if (e.touches.length === 1) {
        touchStartX = e.touches[0].clientX;
        touchStartY = e.touches[0].clientY;
        isPinching = false;
        if (this.state.zoom > 1) {
          isTouchPanning = true;
          this.state.startPos = {
            x: e.touches[0].clientX - this.state.pan.x,
            y: e.touches[0].clientY - this.state.pan.y
          };
          const mi = this.stage.querySelector('.media-item');
          if (mi) mi.style.transition = 'opacity 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94)';
        } else {
          isTouchPanning = false;
        }
      }
    }, {passive: false});

    this.stage.addEventListener('touchmove', (e) => {
      if (!this.state.visible) return;

      if (e.touches.length === 2 && isPinching) {
        e.preventDefault();
        const currentDist = Math.hypot(
          e.touches[0].clientX - e.touches[1].clientX,
          e.touches[0].clientY - e.touches[1].clientY
        );
        const newZoom = Math.min(
          Math.max(pinchStartZoom * (currentDist / pinchStartDist), 1),
          this.options.maxZoom
        );
        this.setZoom(newZoom);
      } else if (e.touches.length === 1 && isTouchPanning) {
        e.preventDefault();
        const mi = this.stage.querySelector('.media-item');
        if (!mi) return;
        const containerRect = this.stage.getBoundingClientRect();
        const maxX = Math.max(0, (mi.offsetWidth * this.state.zoom - containerRect.width) / 2);
        const maxY = Math.max(0, (mi.offsetHeight * this.state.zoom - containerRect.height) / 2);
        this.state.pan = {
          x: Math.min(maxX, Math.max(-maxX, e.touches[0].clientX - this.state.startPos.x)),
          y: Math.min(maxY, Math.max(-maxY, e.touches[0].clientY - this.state.startPos.y))
        };
        this.updateTransform();
      } else if (e.touches.length === 1 && !isTouchPanning) {
        const deltaX = touchStartX - e.touches[0].clientX;
        const deltaY = touchStartY - e.touches[0].clientY;
        if (Math.abs(deltaX) > Math.abs(deltaY)) {
          e.preventDefault();
          if (deltaX > 50) this.next();
          if (deltaX < -50) this.prev();
        }
      }
    }, {passive: false});

    this.stage.addEventListener('touchend', (e) => {
      if (e.touches.length < 2) isPinching = false;
      if (e.touches.length === 0) {
        isTouchPanning = false;
        const mi = this.stage.querySelector('.media-item');
        if (mi) mi.style.transition = '';
      }
    }, {passive: true});

    this.stage.addEventListener('wheel', (e) => {
      if (!this.options.enableZoom) return;

      e.preventDefault();
      if (e.deltaY < 0) this.zoomIn();
      if (e.deltaY > 0) this.zoomOut();
    }, {passive: false});

    this.stage.addEventListener('mousedown', this.startDrag.bind(this));
    document.addEventListener('mousemove', this.doDrag.bind(this));
    document.addEventListener('mouseup', this.stopDrag.bind(this));
  }

  /**
   * Show viewer with media items
   * @param {Array} items Media items to display
   * @param {number} startIndex Starting item index
   */
  show(items, startIndex = 0) {
    this.state.items = items.map(item => this.normalizeItem(item));
    this.state.currentIndex = startIndex;
    this.state.visible = true;

    this.container.classList.add('visible');
    this.container.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';

    this.loadCurrentItem();

    if (this.options.showThumbnails) {
      this.renderThumbnails();
    }

    if (this.options.autoplay) {
      this.play();
    }

    if (this.options.onShow) {
      this.options.onShow(this.getCurrentItem());
    }
  }

  /**
   * Hide viewer
   */
  hide() {
    this.stop();
    this.resetZoom();

    this.state.visible = false;
    this.container.classList.remove('visible');

    // Move focus out before hiding to avoid aria-hidden warning
    if (this.container.contains(document.activeElement)) {
      document.activeElement.blur();
    }

    this.container.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';

    if (this.options.onHide) {
      this.options.onHide();
    }
  }

  /**
   * Load and render current item
   * @private
   */
  loadCurrentItem() {
    const item = this.getCurrentItem();
    if (!item) return;

    // Show loading state
    this.stage.classList.add('loading');
    this.stage.innerHTML = '';

    // Reset natural zoom for new item
    this.options.maxZoom = this._baseMaxZoom;
    this.state.naturalZoom = 1;
    this.resetZoom();

    // Update counter
    this.updateCounter();

    // Update caption
    this.updateCaption(item);

    const renderer = this.options.renderers[item.type];
    if (renderer) {
      renderer(item);
    }

    if (this.options.showThumbnails) {
      this.updateThumbnails();
    }

    if (this.options.onChange) {
      this.options.onChange(item, this.state.currentIndex);
    }
  }

  /**
   * Render image item
   * @private
   */
  renderImage(item) {
    const img = document.createElement('img');
    img.className = 'media-item';
    img.src = item.url;
    img.alt = item.caption || '';

    img.onload = () => {
      this.stage.classList.remove('loading');
      if (img.offsetWidth > 0) {
        const naturalZoom = img.naturalWidth / img.offsetWidth;
        this.state.naturalZoom = naturalZoom;
        this.options.maxZoom = Math.max(this._baseMaxZoom, naturalZoom);
      }
      this.resetZoom();
      img.classList.add('loaded');
    };

    img.onerror = () => {
      this.stage.classList.remove('loading');
    };

    this.stage.appendChild(img);
  }

  /**
   * Render video item
   * @private
   */
  renderVideo(item) {
    const video = document.createElement('video');
    video.className = 'media-item';
    video.src = item.url;
    video.controls = true;

    if (item.poster) {
      video.poster = item.poster;
    }

    // Remove loading state when video can play
    video.oncanplay = () => {
      this.stage.classList.remove('loading');
    };

    video.onerror = () => {
      this.stage.classList.remove('loading');
    };

    this.stage.appendChild(video);
  }

  /**
   * Render YouTube video
   * @private
   */
  renderYouTube(item) {
    const iframe = document.createElement('iframe');
    iframe.className = 'media-item iframe';
    iframe.src = `https://www.youtube.com/embed/${this.sanitizeVideoId(item.videoId)}?rel=0&showinfo=0`;
    iframe.allow = 'accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture';
    iframe.allowFullscreen = true;

    // Remove loading state when iframe loads
    iframe.onload = () => {
      this.stage.classList.remove('loading');
    };

    this.stage.appendChild(iframe);
  }

  /**
   * Sanitize YouTube video ID
   * @private
   */
  sanitizeVideoId(videoId) {
    if (typeof videoId !== 'string') return '';
    // Verify that videoId is 11 characters long and contains only valid characters
    const validId = /^[a-zA-Z0-9_-]{11}$/.test(videoId);
    return validId ? videoId : '';
  }

  /**
   * Render iframe content
   * @private
   */
  renderIframe(item) {
    const iframe = document.createElement('iframe');
    iframe.className = 'media-item iframe';
    iframe.src = item.url;
    iframe.allowFullscreen = true;

    // Remove loading state when iframe loads
    iframe.onload = () => {
      this.stage.classList.remove('loading');
    };

    this.stage.appendChild(iframe);
  }

  /**
   * Render text content
   * @private
   */
  renderText(item) {
    this.stage.classList.remove('loading');

    const textContainer = document.createElement('div');
    textContainer.className = 'media-item media-text';
    textContainer.textContent = item.content || item.text || '';

    if (item.title) {
      const title = document.createElement('h2');
      title.className = 'media-text-title';
      title.textContent = item.title;
      textContainer.insertBefore(title, textContainer.firstChild);
    }

    this.stage.appendChild(textContainer);
  }

  /**
   * Render HTML content
   * @private
   */
  renderHtml(item) {
    this.stage.classList.remove('loading');

    const htmlContainer = document.createElement('div');
    htmlContainer.className = 'media-item media-html';

    // Sanitize HTML if SecurityManager is available
    if (window.SecurityManager && SecurityManager.sanitize) {
      htmlContainer.innerHTML = SecurityManager.sanitize(item.content || item.html || '');
    } else {
      htmlContainer.innerHTML = item.content || item.html || '';
    }

    this.stage.appendChild(htmlContainer);
  }

  /**
   * Render error state
   * @private
   */
  renderError(message) {
    this.stage.classList.remove('loading');
    this.stage.innerHTML = '';

    const errorContainer = document.createElement('div');
    errorContainer.className = 'media-error';
    errorContainer.innerHTML = `
      <div class="media-error-icon">⚠️</div>
      <div class="media-error-message">${message || 'Failed to load media'}</div>
    `;

    this.stage.appendChild(errorContainer);
  }

  /**
   * Render thumbnail strip
   * @private
   */
  renderThumbnails() {
    this.thumbnailTrack.innerHTML = '';

    this.state.items.forEach((item, index) => {
      const thumb = document.createElement('div');
      thumb.className = 'thumbnail';
      if (index === this.state.currentIndex) {
        thumb.classList.add('active');
      }

      if (item.type === 'image') {
        const img = document.createElement('img');
        img.src = item.thumbnail || item.url;
        img.alt = item.caption || '';
        thumb.appendChild(img);
      } else {
        const icon = document.createElement('span');
        icon.className = `icon-${item.type}`;
        thumb.appendChild(icon);
      }

      thumb.onclick = () => {
        this.goTo(index);
      };

      this.thumbnailTrack.appendChild(thumb);
    });
  }

  /**
   * Update thumbnail selection
   * @private
   */
  updateThumbnails() {
    if (!this.thumbnailTrack) return;

    const thumbs = this.thumbnailTrack.children;
    for (let i = 0; i < thumbs.length; i++) {
      thumbs[i].classList.toggle('active', i === this.state.currentIndex);
    }
  }

  /**
   * Update counter display
   * @private
   */
  updateCounter() {
    if (!this.counterEl) return;

    const current = this.state.currentIndex + 1;
    const total = this.state.items.length;
    this.counterEl.textContent = `${current} / ${total}`;
  }

  /**
   * Update caption display
   * @param {Object} item - Current media item
   * @private
   */
  updateCaption(item) {
    if (!this.captionEl) return;

    if (item && item.caption) {
      this.captionEl.textContent = item.caption;
      this.captionEl.style.display = '';
    } else {
      this.captionEl.textContent = '';
      this.captionEl.style.display = 'none';
    }
  }

  /**
   * Start slideshow playback
   */
  play() {
    if (this.state.isPlaying) return;

    this.state.isPlaying = true;
    this.state.playTimer = setInterval(() => {
      this.next();
    }, this.options.interval);
  }

  /**
   * Stop slideshow playback
   */
  stop() {
    if (!this.state.isPlaying) return;

    this.state.isPlaying = false;
    clearInterval(this.state.playTimer);
  }

  /**
   * Go to next item
   */
  next() {
    if (this.state.currentIndex < this.state.items.length - 1) {
      this.goTo(this.state.currentIndex + 1);
    } else if (this.options.loop) {
      this.goTo(0);
    }
  }

  /**
   * Go to previous item
   */
  prev() {
    if (this.state.currentIndex > 0) {
      this.goTo(this.state.currentIndex - 1);
    } else if (this.options.loop) {
      this.goTo(this.state.items.length - 1);
    }
  }

  /**
   * Go to specific item
   * @param {number} index Item index
   */
  goTo(index) {
    if (index === this.state.currentIndex) return;

    this.state.currentIndex = index;
    this.loadCurrentItem();
  }

  /**
   * Get current item
   * @returns {Object} Current media item
   */
  getCurrentItem() {
    return this.state.items[this.state.currentIndex];
  }

  /**
   * Normalize media item object
   * @private
   */
  normalizeItem(item) {
    if (typeof item === 'string') {
      return {
        type: 'image',
        url: item
      };
    }

    if (item.url && item.url.includes('youtube.com')) {
      const videoId = this.getYouTubeId(item.url);
      return {
        type: 'youtube',
        videoId,
        thumbnail: `https://img.youtube.com/vi/${videoId}/mqdefault.jpg`,
        ...item
      };
    }

    return {
      type: 'image',
      ...item
    };
  }

  /**
   * Extract YouTube video ID from URL
   * @private
   */
  getYouTubeId(url) {
    const regExp = /^.*(youtu.be\/|v\/|u\/\w\/|embed\/|watch\?v=|&v=)([^#&?]*).*/;
    const match = url.match(regExp);
    return (match && match[2].length === 11) ? match[2] : null;
  }

  /**
   * Start drag operation
   * @private
   */
  startDrag(e) {
    if (!this.options.enableZoom || this.state.zoom === 1) return;

    this.state.isDragging = true;
    this.state.startPos = {
      x: e.clientX - this.state.pan.x,
      y: e.clientY - this.state.pan.y
    };

    const mediaItem = this.stage.querySelector('.media-item');
    if (mediaItem) mediaItem.style.transition = 'opacity 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94)';
    this.stage.style.cursor = 'grabbing';
  }

  /**
   * Handle drag operation
   * @private
   */
  doDrag(e) {
    if (!this.state.isDragging) return;

    e.preventDefault();

    const mediaItem = this.stage.querySelector('.media-item');
    if (!mediaItem) return;

    const containerRect = this.stage.getBoundingClientRect();
    const maxX = Math.max(0, (mediaItem.offsetWidth * this.state.zoom - containerRect.width) / 2);
    const maxY = Math.max(0, (mediaItem.offsetHeight * this.state.zoom - containerRect.height) / 2);

    this.state.pan = {
      x: Math.min(maxX, Math.max(-maxX, e.clientX - this.state.startPos.x)),
      y: Math.min(maxY, Math.max(-maxY, e.clientY - this.state.startPos.y))
    };

    this.updateTransform();
  }

  /**
   * End drag operation
   * @private
   */
  stopDrag() {
    if (!this.state.isDragging) return;

    this.state.isDragging = false;
    const mediaItem = this.stage.querySelector('.media-item');
    if (mediaItem) mediaItem.style.transition = '';
    this.stage.style.cursor = 'grab';
  }

  /**
   * Zoom in
   */
  zoomIn() {
    if (!this.options.enableZoom) return;

    const newZoom = Math.min(
      this.state.zoom + this.options.zoomStep,
      this.options.maxZoom
    );
    this.setZoom(newZoom);
  }

  /**
   * Zoom out
   */
  zoomOut() {
    if (!this.options.enableZoom) return;

    const newZoom = Math.max(
      this.state.zoom - this.options.zoomStep,
      1
    );
    this.setZoom(newZoom);
  }

  /**
   * Reset zoom level
   */
  resetZoom() {
    this.setZoom(1);
    this.state.pan = {x: 0, y: 0};
    this.updateTransform();
  }

  /**
   * Set zoom level
   * @param {number} zoom Zoom level
   * @private
   */
  setZoom(zoom) {
    this.state.zoom = zoom;
    if (zoom <= 1) {
      this.state.pan = {x: 0, y: 0};
    }
    this.updateTransform();

    const mediaItem = this.stage.querySelector('.media-item');
    if (mediaItem) {
      mediaItem.style.cursor = zoom > 1 ? 'grab' : 'default';
    }
  }

  /**
   * Update transform
   * @private
   */
  updateTransform() {
    const mediaItem = this.stage.querySelector('.media-item');
    if (!mediaItem) return;

    const transform = `
      translate(${this.state.pan.x}px, ${this.state.pan.y}px)
      scale(${this.state.zoom})
    `;

    mediaItem.style.transform = transform;
  }

  /**
   * Set viewer options
   * @param {Object} options New options
   */
  setOptions(options) {
    this.options = {
      ...this.options,
      ...options
    };

    if (this.container) {
      this.container.classList.toggle('show-thumbnails', this.options.showThumbnails);
      this.container.classList.toggle('show-controls', this.options.showControls);
    }
  }

  /**
   * Clean up viewer
   */
  destroy() {
    this.stop();

    if (this.container && this.container.parentNode) {
      this.container.parentNode.removeChild(this.container);
    }

    this.state = {
      visible: false,
      currentIndex: 0,
      items: [],
      zoom: 1,
      pan: {x: 0, y: 0},
      isDragging: false,
      startPos: {x: 0, y: 0},
      isPlaying: false,
      playTimer: null
    };
  }

  /**
   * Initialize MediaViewer instances from data attributes
   * @static
   */
  static init() {
    document.querySelectorAll('[data-media-viewer]').forEach(container => {
      MediaViewer.initFromElement(container);
    });
  }

  /**
   * Initialize MediaViewer from a container element
   * @param {HTMLElement} container - Container element with data-media-viewer attribute
   * @returns {MediaViewer|Promise<MediaViewer>} The created MediaViewer instance
   * @static
   */
  static initFromElement(container) {
    // Parse options from data attributes
    const options = MediaViewer.parseOptionsFromElement(container);

    // Create viewer instance
    const viewer = new MediaViewer(options);

    // Store viewer instance on container
    container._mediaViewer = viewer;

    // Check for API endpoint
    const apiUrl = container.dataset.api;
    if (apiUrl) {
      // Load items from API
      MediaViewer.loadFromApi(container, viewer, apiUrl);
    } else {
      // Collect media items from child elements
      const items = MediaViewer.collectMediaItems(container);
      container._mediaItems = items;
      MediaViewer.bindItemEvents(container, viewer, items);
    }

    return viewer;
  }

  /**
   * Load items from API endpoint
   * @param {HTMLElement} container - Container element
   * @param {MediaViewer} viewer - MediaViewer instance
   * @param {string} apiUrl - API endpoint URL
   * @static
   */
  static async loadFromApi(container, viewer, apiUrl) {
    try {
      const method = container.dataset.apiMethod || 'GET';
      const headers = {'Content-Type': 'application/json'};

      // Add auth token if AuthManager is available
      if (window.AuthManager && AuthManager.getToken) {
        const token = AuthManager.getToken();
        if (token) {
          headers['Authorization'] = `Bearer ${token}`;
        }
      }

      const requestOptions = Now.applyRequestLanguage({method, headers});
      const response = await fetch(apiUrl, requestOptions);

      if (!response.ok) {
        throw new Error(`API request failed: ${response.status}`);
      }

      const data = await response.json();

      // Support different API response formats
      let items = [];
      if (Array.isArray(data)) {
        items = data;
      } else if (data.items) {
        items = data.items;
      } else if (data.data) {
        items = data.data;
      } else if (data.media) {
        items = data.media;
      }

      // Normalize items
      items = items.map(item => {
        if (typeof item === 'string') {
          return {type: 'image', url: item};
        }
        return {
          type: item.type || 'image',
          url: item.url || item.src || item.path,
          thumbnail: item.thumbnail || item.thumb,
          caption: item.caption || item.title || item.description,
          ...item
        };
      });

      container._mediaItems = items;
      MediaViewer.bindItemEvents(container, viewer, items);

      // Dispatch loaded event
      container.dispatchEvent(new CustomEvent('mediaviewer:loaded', {
        detail: {items, viewer}
      }));

    } catch (error) {
      console.error('MediaViewer API load error:', error);

      // Show error in viewer if opened
      container._mediaItems = [];
      container._apiError = error.message;

      // Dispatch error event
      container.dispatchEvent(new CustomEvent('mediaviewer:error', {
        detail: {error: error.message, viewer}
      }));
    }
  }

  /**
   * Bind click events to media items
   * @param {HTMLElement} container - Container element
   * @param {MediaViewer} viewer - MediaViewer instance
   * @param {Array} items - Media items array
   * @static
   */
  static bindItemEvents(container, viewer, items) {
    const clickableItems = container.querySelectorAll('img, a[data-media-item], [data-media-item]');
    clickableItems.forEach((item, index) => {
      item.style.cursor = 'pointer';
      item.addEventListener('click', (e) => {
        e.preventDefault();
        if (container._apiError) {
          viewer.renderError(container._apiError);
          viewer.container.classList.add('visible');
        } else {
          viewer.show(items, index);
        }
      });
    });

    // If no clickable items but has items from API, add click to container
    if (clickableItems.length === 0 && items.length > 0) {
      container.style.cursor = 'pointer';
      container.addEventListener('click', () => {
        viewer.show(items, 0);
      });
    }
  }

  /**
   * Parse MediaViewer options from element's data attributes
   * @param {HTMLElement} element - Element with data attributes
   * @returns {Object} Parsed options object
   * @static
   */
  static parseOptionsFromElement(element) {
    const options = {};

    // Boolean options
    if (element.dataset.showThumbnails !== undefined) {
      options.showThumbnails = element.dataset.showThumbnails === 'true';
    }
    if (element.dataset.showControls !== undefined) {
      options.showControls = element.dataset.showControls === 'true';
    }
    if (element.dataset.autoplay !== undefined) {
      options.autoplay = element.dataset.autoplay === 'true';
    }
    if (element.dataset.loop !== undefined) {
      options.loop = element.dataset.loop === 'true';
    }
    if (element.dataset.enableZoom !== undefined) {
      options.enableZoom = element.dataset.enableZoom === 'true';
    }

    // Numeric options
    if (element.dataset.interval) {
      options.interval = parseInt(element.dataset.interval, 10);
    }
    if (element.dataset.maxZoom) {
      options.maxZoom = parseFloat(element.dataset.maxZoom);
    }
    if (element.dataset.zoomStep) {
      options.zoomStep = parseFloat(element.dataset.zoomStep);
    }

    return options;
  }

  /**
   * Collect media items from container's child elements
   * @param {HTMLElement} container - Container element
   * @returns {Array} Array of media item objects
   * @static
   */
  static collectMediaItems(container) {
    const items = [];

    // Collect from img elements
    container.querySelectorAll('img').forEach(img => {
      // Use data-src for full-size image, fallback to src
      const url = img.dataset.src || img.src;

      items.push({
        type: img.dataset.type || 'image',
        url: url,
        caption: img.dataset.caption || img.alt || '',
        thumbnail: img.src
      });
    });

    // Collect from anchor elements with data-media-item
    container.querySelectorAll('a[data-media-item]').forEach(anchor => {
      const url = anchor.href;
      const type = anchor.dataset.type || 'image';

      // Check if it's a YouTube URL
      if (url.includes('youtube.com') || url.includes('youtu.be')) {
        items.push({
          type: 'youtube',
          url: url,
          caption: anchor.dataset.caption || anchor.textContent || ''
        });
      } else {
        items.push({
          type: type,
          url: url,
          caption: anchor.dataset.caption || anchor.textContent || '',
          thumbnail: anchor.dataset.thumbnail || anchor.querySelector('img')?.src
        });
      }
    });

    // Collect from elements with data-media-item (non-anchor)
    container.querySelectorAll('[data-media-item]:not(a):not(img)').forEach(el => {
      const url = el.dataset.src || el.dataset.url;
      if (!url) return;

      items.push({
        type: el.dataset.type || 'image',
        url: url,
        caption: el.dataset.caption || '',
        thumbnail: el.dataset.thumbnail
      });
    });

    return items;
  }
}

// Auto-initialize on DOM ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => MediaViewer.init());
} else {
  MediaViewer.init();
}

// Expose globally
window.MediaViewer = MediaViewer;
