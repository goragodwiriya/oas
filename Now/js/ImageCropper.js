/**
 * ImageCropper
 *
 * Crops an image in the browser and hands the result back as a File. The frame
 * stays fixed at the requested aspect ratio and the image moves beneath it:
 * drag to pan, the wheel, a pinch or the slider to zoom, and a button turns it
 * a quarter turn. The image always covers the frame, so the result never has
 * empty edges.
 *
 * FileElementFactory opens it for `<input type="file" data-crop="true">`. App
 * code can call it directly:
 *
 *   const file = await ImageCropper.open(fileOrUrl, {aspect: '3/4', width: 600});
 *   // a File, or null when the user cancels
 *
 * A File this cropper produced remembers its source image and how it was
 * cropped: passing it to open() again starts from the original at the previous
 * crop, so editing a crop twice does not resample the image twice.
 *
 * One crop is on screen at a time; further open() calls wait their turn.
 *
 * Feature bundle 'imagecrop' (Now/dist/now.imagecrop.min.js + .css), loaded by
 * Now.require('ImageCropper') or by markup carrying data-crop="true".
 *
 * @filesource Now/js/ImageCropper.js
 */
const ImageCropper = {
  /**
   * Defaults for open(). Every option can be given as a number or as the
   * string a data-* attribute carries.
   */
  config: {
    // Frame width / height: 1, 0.75, '3/4', '3:4', '16/9'
    aspect: 1,
    // Width of the resulting image in px; 0 = the cropped area's own size, at most maxWidth
    width: 0,
    maxWidth: 2048,
    // rect | circle — circle only masks the frame, the file is always a rectangle
    shape: 'rect',
    // auto (keep JPEG, PNG and WebP, anything else becomes JPEG) | jpeg | png | webp
    format: 'auto',
    quality: 0.9,
    // Warn when the cropped area has fewer source pixels across than this; 0 = never
    minWidth: 0,
    // Furthest zoom, relative to the zoom at which the image just covers the frame
    maxZoom: 4,
    title: 'Crop image'
  },

  state: {
    // Output File -> {source, name, crop}
    sources: new WeakMap(),
    queue: Promise.resolve(),
    modal: null,
    session: null
  },

  /** Furthest the pixel canvas goes on either side while scaling down in steps. */
  MAX_CANVAS: 4096,

  TYPES: {jpeg: 'image/jpeg', png: 'image/png', webp: 'image/webp'},

  EXTENSIONS: {'image/jpeg': '.jpg', 'image/png': '.png', 'image/webp': '.webp'},

  /**
   * Let the user crop one image.
   *
   * @param {File|Blob|string} source - The image, or the URL of one (same origin, or one served with CORS).
   * @param {Object} [options={}] - Overrides for `config`.
   * @returns {Promise<File|null>} - The cropped image, or null when the user cancels.
   * @throws {Error} - When the source is not an image the browser can open.
   */
  async open(source, options = {}) {
    const opts = this.normalizeOptions(options);

    let blob = source;
    let name = '';
    let crop = null;
    const remembered = source instanceof Blob ? this.state.sources.get(source) : null;
    if (remembered) {
      ({source: blob, name, crop} = remembered);
    } else if (typeof source === 'string') {
      blob = await this.fetchImage(source);
      name = this.nameFromUrl(source);
    } else if (source instanceof Blob) {
      name = source.name || '';
    }
    if (!(blob instanceof Blob)) {
      throw new TypeError('ImageCropper.open(): pass a File, a Blob or an image URL');
    }

    const image = await this.loadImage(blob);
    const run = () => this.run({blob, name, image, crop, opts});
    const result = this.state.queue.then(run, run);
    // The next crop waits until this one has closed, whatever its outcome
    this.state.queue = result.then(r => r.closed, () => null);
    return result.then(r => r.file);
  },

  /**
   * Turn open() options into the values the cropper works with.
   *
   * @param {Object} options - Options as given, numbers or attribute strings.
   * @returns {Object} - Complete, validated options.
   */
  normalizeOptions(options) {
    const merged = {...this.config};
    Object.entries(options || {}).forEach(([key, value]) => {
      if (value !== undefined && value !== null && value !== '') merged[key] = value;
    });
    const int = (value, fallback) => {
      const number = parseInt(value, 10);
      return Number.isFinite(number) && number >= 0 ? number : fallback;
    };
    const quality = parseFloat(merged.quality);
    const format = String(merged.format).toLowerCase().replace(/^image\//, '').replace('jpg', 'jpeg');
    return {
      aspect: this.parseAspect(merged.aspect) || 1,
      width: int(merged.width, 0),
      maxWidth: int(merged.maxWidth, this.config.maxWidth) || this.config.maxWidth,
      shape: merged.shape === 'circle' ? 'circle' : 'rect',
      format: format === 'auto' || this.TYPES[format] ? format : 'auto',
      quality: Number.isFinite(quality) ? Math.min(1, Math.max(0, quality)) : this.config.quality,
      minWidth: int(merged.minWidth, 0),
      maxZoom: Math.max(1, parseFloat(merged.maxZoom) || this.config.maxZoom),
      title: merged.title
    };
  },

  /**
   * Read an aspect ratio written as a number, `w/h` or `w:h`.
   *
   * @param {number|string} value - e.g. 1, 0.75, '3/4', '16:9'.
   * @returns {number|null} - Width divided by height, or null when it is not a usable ratio.
   */
  parseAspect(value) {
    if (typeof value === 'number') return value > 0 && Number.isFinite(value) ? value : null;
    const text = String(value ?? '').trim();
    const pair = /^(\d+(?:\.\d+)?)\s*[/:]\s*(\d+(?:\.\d+)?)$/.exec(text);
    const ratio = pair ? parseFloat(pair[1]) / parseFloat(pair[2]) : parseFloat(text);
    return ratio > 0 && Number.isFinite(ratio) && (pair || /^\d+(?:\.\d+)?$/.test(text)) ? ratio : null;
  },

  /**
   * Download an image to crop.
   *
   * @param {string} url - Image URL.
   * @returns {Promise<Blob>} - The image.
   */
  async fetchImage(url) {
    const safe = SecurityManager.sanitizeUrl(url, {image: true});
    if (!safe) throw new Error(Now.translate('Unable to open this image'));
    const response = await fetch(safe, {credentials: 'same-origin'});
    if (!response.ok) throw new Error(Now.translate('Unable to open this image'));
    return response.blob();
  },

  /**
   * The file name a URL ends in.
   *
   * @param {string} url - Image URL.
   * @returns {string} - Name, or '' when the URL has none (a data: URL).
   */
  nameFromUrl(url) {
    if (/^\s*data:/i.test(String(url))) return '';
    try {
      return decodeURIComponent(String(url).split(/[?#]/)[0].split('/').pop() || '');
    } catch (e) {
      return '';
    }
  },

  /**
   * Decode an image so it can be shown and drawn.
   *
   * Browsers apply the EXIF orientation of a photo both when showing it and
   * when drawing it on a canvas, and report its size after that turn.
   *
   * @param {Blob} blob - The image.
   * @returns {Promise<{img: HTMLImageElement, url: string}>} - The image and its object URL.
   */
  loadImage(blob) {
    return new Promise((resolve, reject) => {
      const url = URL.createObjectURL(blob);
      const img = new Image();
      img.onload = () => {
        if (img.naturalWidth > 0 && img.naturalHeight > 0) {
          resolve({img, url});
        } else {
          URL.revokeObjectURL(url);
          reject(new Error(Now.translate('Unable to open this image')));
        }
      };
      img.onerror = () => {
        URL.revokeObjectURL(url);
        reject(new Error(Now.translate('Unable to open this image')));
      };
      img.src = url;
    });
  },

  /**
   * Show one crop and wait for the user.
   *
   * @param {Object} job - {blob, name, image: {img, url}, crop, opts}.
   * @returns {Promise<{file: File|null, closed: Promise}>} - The outcome, and a
   *   promise that settles once the dialog has finished closing.
   */
  run(job) {
    const modal = this.getModal();
    const s = this.createSession(job);
    this.state.session = s;

    modal.setTitle(Now.translate(job.opts.title));
    modal.body.appendChild(s.root);
    modal.footerOk.disabled = false;

    return new Promise(resolve => {
      s.finish = (file) => {
        if (s.done) return;
        s.done = true;
        const closed = new Promise(resolveClosed => {
          s.closed = () => {
            s.observer?.disconnect();
            URL.revokeObjectURL(job.image.url);
            job.image.img.removeAttribute('src');
            if (this.state.session === s) this.state.session = null;
            resolveClosed();
          };
        });
        resolve({file, closed});
      };
      modal.show();
    });
  },

  /**
   * The dialog every crop is shown in, created on first use.
   *
   * @returns {Modal} - The dialog, with `footerOk` and `footerCancel` buttons.
   */
  getModal() {
    if (this.state.modal) return this.state.modal;

    const modal = new Modal({
      title: Now.translate(this.config.title),
      className: 'image-cropper-modal',
      backdrop: false,
      focus: true,
      onShown: () => this.state.session?.stage.focus(),
      // Closed by Cancel, the close button or Escape: nothing was cropped
      onHide: () => this.state.session?.finish(null),
      onHidden: () => this.state.session?.closed?.()
    });

    const footer = document.createElement('div');
    footer.className = 'modal-footer';
    const cancel = document.createElement('button');
    cancel.type = 'button';
    cancel.className = 'btn';
    cancel.textContent = Now.translate('Cancel');
    cancel.addEventListener('click', () => modal.hide());
    const ok = document.createElement('button');
    ok.type = 'button';
    ok.className = 'btn btn-primary';
    ok.textContent = Now.translate('OK');
    ok.addEventListener('click', () => this.confirm());
    footer.append(cancel, ok);
    modal.dialog.appendChild(footer);

    // Escape closes this dialog only: every open Modal listens on document, and a
    // crop opened from a form inside another modal would close that one as well
    modal.modal.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        e.stopPropagation();
        modal.hide();
      }
    });

    modal.footerOk = ok;
    modal.footerCancel = cancel;
    this.state.modal = modal;
    return modal;
  },

  /**
   * Build the crop area and its controls for one image.
   *
   * @param {Object} job - {blob, name, image: {img, url}, crop, opts}.
   * @returns {Object} - The session: its elements, the crop state and handlers.
   */
  createSession(job) {
    const {opts} = job;
    const {img} = job.image;

    const root = document.createElement('div');
    root.className = 'image-cropper';

    const stage = document.createElement('div');
    stage.className = 'image-cropper-stage';
    stage.tabIndex = 0;
    stage.setAttribute('role', 'application');
    stage.setAttribute('aria-label', Now.translate('Drag or use the arrow keys to move the image, scroll or pinch to zoom'));

    img.className = 'image-cropper-image';
    img.alt = '';
    img.draggable = false;
    img.style.width = img.naturalWidth + 'px';
    img.style.height = img.naturalHeight + 'px';

    const frame = document.createElement('div');
    frame.className = 'image-cropper-frame' + (opts.shape === 'circle' ? ' circle' : '');
    stage.append(img, frame);

    const button = (icon, label, onClick) => {
      const el = document.createElement('button');
      el.type = 'button';
      el.className = `btn ${icon}`;
      el.title = Now.translate(label);
      el.setAttribute('aria-label', el.title);
      el.addEventListener('click', onClick);
      return el;
    };

    const zoom = document.createElement('input');
    zoom.type = 'range';
    zoom.className = 'image-cropper-zoom';
    zoom.min = '1';
    zoom.max = String(opts.maxZoom);
    zoom.step = '0.01';
    zoom.dataset.noEnhance = 'true';
    zoom.setAttribute('aria-label', Now.translate('Zoom'));

    const toolbar = document.createElement('div');
    toolbar.className = 'image-cropper-toolbar';

    const warning = document.createElement('div');
    warning.className = 'image-cropper-warning icon-warning';
    warning.hidden = true;
    warning.setAttribute('role', 'status');

    root.append(stage, toolbar, warning);

    const s = {
      root, stage, img, frame, zoom, warning, opts,
      blob: job.blob, name: job.name,
      W: img.naturalWidth, H: img.naturalHeight,
      rotate: 0, scale: 1, fx: 0, fy: 0,
      box: {x: 0, y: 0, w: 0, h: 0},
      pointers: new Map(),
      done: false
    };
    const dims = this.dims(s);
    s.fx = dims.w / 2;
    s.fy = dims.h / 2;
    if (job.crop) {
      s.rotate = job.crop.rotate;
      s.scale = job.crop.zoom;
      s.fx = job.crop.fx;
      s.fy = job.crop.fy;
    }

    toolbar.append(
      button('icon-zoom-out', 'Zoom out', () => this.zoomTo(s, s.scale / 1.2)),
      zoom,
      button('icon-zoom-in', 'Zoom in', () => this.zoomTo(s, s.scale * 1.2)),
      button('icon-refresh', 'Rotate', () => this.rotate(s)),
      button('icon-reset', 'Reset', () => this.reset(s))
    );

    zoom.addEventListener('input', () => this.zoomTo(s, parseFloat(zoom.value)));
    this.bindStage(s);

    s.observer = new ResizeObserver(() => this.layout(s));
    s.observer.observe(stage);

    return s;
  },

  /**
   * Pointer, wheel and keyboard handling on the crop area.
   *
   * @param {Object} s - Session.
   * @returns {void}
   */
  bindStage(s) {
    const {stage} = s;
    let pinch = null;

    const point = (e) => {
      const rect = stage.getBoundingClientRect();
      return {
        x: e.clientX - rect.left - (s.box.x + s.box.w / 2),
        y: e.clientY - rect.top - (s.box.y + s.box.h / 2)
      };
    };
    const pinchOf = () => {
      const [a, b] = [...s.pointers.values()];
      return {
        distance: Math.hypot(a.x - b.x, a.y - b.y) || 1,
        mid: {x: (a.x + b.x) / 2, y: (a.y + b.y) / 2}
      };
    };

    stage.addEventListener('pointerdown', (e) => {
      if (e.button !== 0 && e.pointerType === 'mouse') return;
      stage.setPointerCapture?.(e.pointerId);
      s.pointers.set(e.pointerId, point(e));
      pinch = s.pointers.size === 2 ? pinchOf() : null;
      stage.classList.add('dragging');
    });

    stage.addEventListener('pointermove', (e) => {
      const last = s.pointers.get(e.pointerId);
      if (!last) return;
      const now = point(e);
      s.pointers.set(e.pointerId, now);
      if (s.pointers.size === 1) {
        this.panBy(s, now.x - last.x, now.y - last.y);
      } else if (s.pointers.size === 2 && pinch) {
        const next = pinchOf();
        this.panBy(s, next.mid.x - pinch.mid.x, next.mid.y - pinch.mid.y, false);
        this.zoomTo(s, s.scale * next.distance / pinch.distance, next.mid);
        pinch = next;
      }
    });

    const release = (e) => {
      s.pointers.delete(e.pointerId);
      pinch = s.pointers.size === 2 ? pinchOf() : null;
      if (s.pointers.size === 0) stage.classList.remove('dragging');
    };
    stage.addEventListener('pointerup', release);
    stage.addEventListener('pointercancel', release);

    stage.addEventListener('wheel', (e) => {
      e.preventDefault();
      const lines = e.deltaMode === 1 ? 16 : e.deltaMode === 2 ? 400 : 1;
      this.zoomTo(s, s.scale * Math.exp(-e.deltaY * lines * 0.002), point(e));
    }, {passive: false});

    stage.addEventListener('keydown', (e) => {
      const step = e.shiftKey ? 50 : 10;
      const moves = {ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step]};
      if (moves[e.key]) {
        this.panBy(s, ...moves[e.key]);
      } else if (e.key === '+' || e.key === '=') {
        this.zoomTo(s, s.scale * 1.1);
      } else if (e.key === '-' || e.key === '_') {
        this.zoomTo(s, s.scale / 1.1);
      } else if (e.key === 'Enter') {
        this.confirm();
      } else {
        return;
      }
      e.preventDefault();
    });
  },

  /**
   * Size of the image once turned, in source pixels.
   *
   * @param {Object} s - Session.
   * @returns {{w: number, h: number}}
   */
  dims(s) {
    return s.rotate % 180 === 0 ? {w: s.W, h: s.H} : {w: s.H, h: s.W};
  },

  /**
   * Screen pixels per source pixel at the current zoom (`s.scale` is the zoom
   * relative to the image just covering the frame).
   *
   * @param {Object} s - Session.
   * @returns {number}
   */
  pixelScale(s) {
    const {w, h} = this.dims(s);
    return Math.max(s.box.w / w, s.box.h / h) * s.scale;
  },

  /**
   * Fit the frame to the crop area, then redraw. Runs whenever the area
   * changes size, so the crop survives a resize or a phone turning round.
   *
   * @param {Object} s - Session.
   * @returns {void}
   */
  layout(s) {
    const width = s.stage.clientWidth;
    const height = s.stage.clientHeight;
    if (!width || !height) return;
    const pad = Math.min(24, width * 0.06, height * 0.06);
    let w = width - pad * 2;
    let h = w / s.opts.aspect;
    if (h > height - pad * 2) {
      h = height - pad * 2;
      w = h * s.opts.aspect;
    }
    s.box = {x: (width - w) / 2, y: (height - h) / 2, w, h};
    Object.assign(s.frame.style, {left: s.box.x + 'px', top: s.box.y + 'px', width: w + 'px', height: h + 'px'});
    this.apply(s);
  },

  /**
   * Keep the zoom in range and the image covering the frame, then redraw.
   *
   * @param {Object} s - Session.
   * @returns {void}
   */
  apply(s) {
    if (!s.box.w) return;
    s.scale = Math.min(s.opts.maxZoom, Math.max(1, s.scale));
    const {w, h} = this.dims(s);
    const k = this.pixelScale(s);
    const halfW = s.box.w / (2 * k);
    const halfH = s.box.h / (2 * k);
    s.fx = Math.min(w - halfW, Math.max(halfW, s.fx));
    s.fy = Math.min(h - halfH, Math.max(halfH, s.fy));

    const cx = s.box.x + s.box.w / 2;
    const cy = s.box.y + s.box.h / 2;
    s.img.style.left = (cx - s.W / 2) + 'px';
    s.img.style.top = (cy - s.H / 2) + 'px';
    s.img.style.transform = `translate(${k * (w / 2 - s.fx)}px, ${k * (h / 2 - s.fy)}px) rotate(${s.rotate}deg) scale(${k})`;
    s.zoom.value = String(s.scale);

    const area = Math.round(s.box.w / k);
    const low = s.opts.minWidth > 0 && area < s.opts.minWidth;
    s.warning.hidden = !low;
    if (low) {
      s.warning.textContent = Now.translate('The selected area is {width} px wide, at least {min} px is recommended', {width: area, min: s.opts.minWidth});
    }
  },

  /**
   * Move the image by a distance on screen.
   *
   * @param {Object} s - Session.
   * @param {number} dx - Screen pixels to the right.
   * @param {number} dy - Screen pixels down.
   * @param {boolean} [redraw=true] - False to leave the redraw to the next call.
   * @returns {void}
   */
  panBy(s, dx, dy, redraw = true) {
    const k = this.pixelScale(s);
    s.fx -= dx / k;
    s.fy -= dy / k;
    if (redraw) this.apply(s);
  },

  /**
   * Zoom, keeping the image point under `at` where it is.
   *
   * @param {Object} s - Session.
   * @param {number} zoom - Zoom relative to the image just covering the frame.
   * @param {{x: number, y: number}} [at] - Screen offset from the frame centre; default the centre.
   * @returns {void}
   */
  zoomTo(s, zoom, at = {x: 0, y: 0}) {
    if (!Number.isFinite(zoom)) return;
    const before = this.pixelScale(s);
    const qx = s.fx + at.x / before;
    const qy = s.fy + at.y / before;
    s.scale = Math.min(s.opts.maxZoom, Math.max(1, zoom));
    const after = this.pixelScale(s);
    s.fx = qx - at.x / after;
    s.fy = qy - at.y / after;
    this.apply(s);
  },

  /**
   * Turn the image a quarter turn clockwise, keeping the same point centred.
   *
   * @param {Object} s - Session.
   * @returns {void}
   */
  rotate(s) {
    const {h} = this.dims(s);
    [s.fx, s.fy] = [h - s.fy, s.fx];
    s.rotate = (s.rotate + 90) % 360;
    this.apply(s);
  },

  /**
   * Back to the whole image, unturned and centred.
   *
   * @param {Object} s - Session.
   * @returns {void}
   */
  reset(s) {
    s.rotate = 0;
    s.scale = 1;
    s.fx = s.W / 2;
    s.fy = s.H / 2;
    this.apply(s);
  },

  /**
   * Crop with the current settings and close the dialog.
   *
   * @returns {Promise<void>}
   */
  async confirm() {
    const s = this.state.session;
    const modal = this.state.modal;
    if (!s || s.done || !s.box.w || modal.footerOk.disabled) return;
    modal.footerOk.disabled = true;
    try {
      const file = await this.render(s);
      s.finish(file);
      modal.hide();
    } catch (error) {
      modal.footerOk.disabled = false;
      ErrorManager.handle(error, {context: 'ImageCropper.confirm', type: 'error:file', notify: true});
    }
  },

  /**
   * The part of the turned image inside the frame, in source pixels.
   *
   * @param {Object} s - Session.
   * @returns {{x: number, y: number, width: number, height: number}}
   */
  cropRect(s) {
    const k = this.pixelScale(s);
    const width = s.box.w / k;
    const height = s.box.h / k;
    return {x: s.fx - width / 2, y: s.fy - height / 2, width, height};
  },

  /**
   * Draw the cropped area into a new image file.
   *
   * Large reductions are made in halving steps: one big step in a single draw
   * leaves jagged edges in some browsers.
   *
   * @param {Object} s - Session.
   * @returns {Promise<File>} - The cropped image.
   */
  async render(s) {
    const crop = this.cropRect(s);
    const {w: turnedW, h: turnedH} = this.dims(s);
    const outW = Math.max(1, Math.round(s.opts.width || Math.min(crop.width, s.opts.maxWidth)));
    const outH = Math.max(1, Math.round(outW / s.opts.aspect));

    const draw = (type) => {
      let w = outW;
      let h = outH;
      let steps = 0;
      while (w * 2 <= crop.width && Math.max(w, h) * 2 <= this.MAX_CANVAS) {
        w *= 2;
        h *= 2;
        steps++;
      }
      let canvas = this.canvas(w, h);
      const ctx = canvas.getContext('2d');
      if (type === 'image/jpeg') {
        // JPEG has no transparency: transparent pixels would turn black
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, w, h);
      }
      ctx.imageSmoothingEnabled = true;
      ctx.imageSmoothingQuality = 'high';
      ctx.scale(w / crop.width, h / crop.height);
      ctx.translate(-crop.x, -crop.y);
      ctx.translate(turnedW / 2, turnedH / 2);
      ctx.rotate(s.rotate * Math.PI / 180);
      ctx.drawImage(s.img, -s.W / 2, -s.H / 2);
      while (steps-- > 0) {
        w /= 2;
        h /= 2;
        const half = this.canvas(w, h);
        const halfCtx = half.getContext('2d');
        halfCtx.imageSmoothingEnabled = true;
        halfCtx.imageSmoothingQuality = 'high';
        halfCtx.drawImage(canvas, 0, 0, w, h);
        canvas = half;
      }
      return canvas;
    };

    let type = this.outputType(s.opts.format, s.blob.type);
    let blob = await this.toBlob(draw(type), type, s.opts.quality);
    if (blob && blob.type !== type) {
      // The browser cannot write this type (Safari and WebP) and fell back to PNG
      type = 'image/jpeg';
      blob = await this.toBlob(draw(type), type, s.opts.quality);
    }
    if (!blob) throw new Error(Now.translate('Unable to open this image'));

    const file = new File([blob], this.outputName(s.name, s.blob.type, blob.type), {type: blob.type, lastModified: Date.now()});
    this.state.sources.set(file, {
      source: s.blob,
      name: s.name,
      crop: {rotate: s.rotate, zoom: s.scale, fx: s.fx, fy: s.fy}
    });
    return file;
  },

  /**
   * @param {number} width
   * @param {number} height
   * @returns {HTMLCanvasElement}
   */
  canvas(width, height) {
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    return canvas;
  },

  /**
   * @param {HTMLCanvasElement} canvas
   * @param {string} type
   * @param {number} quality
   * @returns {Promise<Blob|null>}
   */
  toBlob(canvas, type, quality) {
    return new Promise(resolve => canvas.toBlob(resolve, type, quality));
  },

  /**
   * The MIME type to write.
   *
   * @param {string} format - auto | jpeg | png | webp.
   * @param {string} sourceType - MIME type of the source image.
   * @returns {string}
   */
  outputType(format, sourceType) {
    if (format !== 'auto') return this.TYPES[format];
    return Object.values(this.TYPES).includes(sourceType) ? sourceType : 'image/jpeg';
  },

  /**
   * Name for the cropped file: the source's own name while the type is
   * unchanged, otherwise the same name with the new type's extension. A source
   * without a name (a Blob, a data: URL) is called `image`.
   *
   * @param {string} name - Source file name.
   * @param {string} sourceType - Source MIME type.
   * @param {string} type - Output MIME type.
   * @returns {string}
   */
  outputName(name, sourceType, type) {
    if (type === sourceType && /\.[^./\\]+$/.test(name || '')) return name;
    const base = (name || 'image').replace(/\.[^./\\]*$/, '') || 'image';
    return base + (this.EXTENSIONS[type] || '');
  }
};

window.ImageCropper = ImageCropper;
