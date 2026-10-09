/**
 * MigrationParser
 * Now.js Framework
 *
 * Parses Excel (.xlsx, .xls) and CSV files in the browser using SheetJS,
 * normalizes the result (unmerge cells, ISO dates, trailing-empty trimming)
 * and uploads it to the server in chunks. The raw file never leaves the
 * browser — only normalized rows are sent.
 *
 * Usage:
 *   const parsed = await MigrationParser.parseFile(file);
 *   const jobId = await MigrationParser.upload(parsed, {
 *     onProgress: ({sent, total}) => { ... }
 *   });
 */

import * as XLSX from '@e965/xlsx';

const MigrationParser = {
  config: {
    maxSheets: 20,
    maxRows: 200000,
    chunkRows: 2000,
    endpoints: {
      start: '../api/migration/upload/start',
      chunk: '../api/migration/upload/chunk',
      finish: '../api/migration/upload/finish'
    }
  },

  /**
   * Parse a File/Blob into normalized sheets.
   * @param {File} file
   * @returns {Promise<{filename: string, filesize: number, sha256: string, sheets: Array}>}
   */
  async parseFile(file) {
    if (!/\.(xlsx|xls|csv)$/i.test(file.name)) {
      throw new Error('Unsupported file type: only .xlsx, .xls and .csv are supported');
    }

    const buffer = await file.arrayBuffer();
    const sha256 = await this._sha256Hex(buffer);

    // CSV: decode bytes as UTF-8 explicitly. SheetJS defaults a BOM-less CSV to
    // cp1252, which corrupts Thai (and other non-Latin) text. Real .xls/.xlsx are
    // binary and keep type:'array'.
    const isCsv = /\.csv$/i.test(file.name);
    const workbook = isCsv
      ? XLSX.read(new TextDecoder('utf-8').decode(buffer), {
        type: 'string',
        cellDates: true,
        dense: false
      })
      : XLSX.read(buffer, {
        type: 'array',
        cellDates: true,
        dense: false
      });

    const sheets = [];
    let totalRows = 0;

    for (const name of workbook.SheetNames) {
      const worksheet = workbook.Sheets[name];
      if (!worksheet || !worksheet['!ref']) {
        continue; // empty sheet
      }

      this._unmergeCells(worksheet);

      let rows = XLSX.utils.sheet_to_json(worksheet, {
        header: 1,
        raw: true,
        defval: null
      });

      rows = this._normalizeRows(rows);
      if (rows.length === 0) {
        continue;
      }

      const columnCount = rows.reduce((max, row) => Math.max(max, row.length), 0);
      // Pad all rows to equal length so column indexes are stable
      rows = rows.map(row => {
        while (row.length < columnCount) {
          row.push(null);
        }
        return row;
      });

      totalRows += rows.length;
      sheets.push({name, rowCount: rows.length, columnCount, rows});
    }

    if (sheets.length === 0) {
      throw new Error('No data found in file');
    }
    if (sheets.length > this.config.maxSheets) {
      throw new Error(`Too many sheets: maximum ${this.config.maxSheets} sheets per file`);
    }
    if (totalRows > this.config.maxRows) {
      throw new Error(`Too many rows: maximum ${this.config.maxRows.toLocaleString()} rows per file. Please split the file.`);
    }

    return {
      filename: file.name,
      filesize: file.size,
      sha256,
      totalRows,
      sheets
    };
  },

  /**
   * Upload a parsed workbook to the server in chunks.
   * Requires window.ApiService (now.core bundle).
   *
   * @param {Object} parsed Result of parseFile()
   * @param {Object} [options] {onProgress: ({sent, total, percent}) => void}
   * @returns {Promise<number>} job id
   */
  async upload(parsed, options = {}) {
    const api = window.ApiService;
    if (!api) {
      throw new Error('ApiService is not available');
    }

    // endpoints override ได้ (เช่น demo landing ใช้ api/demo/preview/*)
    const endpoints = {...this.config.endpoints, ...(options.endpoints || {})};

    // 1) start — declare file + sheet metadata
    const startData = this._responseData(await api.post(endpoints.start, {
      filename: parsed.filename,
      filesize: parsed.filesize,
      sha256: parsed.sha256,
      sheets: parsed.sheets.map(s => ({
        name: s.name,
        rowCount: s.rowCount,
        columnCount: s.columnCount
      }))
    }));
    const jobId = startData.job_id;
    const serverSheets = startData.sheets; // server-assigned sheet ids, same order
    const chunkRows = startData.max_chunk_rows || this.config.chunkRows;

    // 2) chunk — send rows sheet by sheet
    const total = parsed.totalRows;
    let sent = 0;

    for (let i = 0; i < parsed.sheets.length; i++) {
      const sheet = parsed.sheets[i];
      const sheetId = serverSheets[i].id;

      for (let from = 0; from < sheet.rows.length; from += chunkRows) {
        const rows = sheet.rows.slice(from, from + chunkRows);
        this._responseData(await api.post(endpoints.chunk, {
          job_id: jobId,
          sheet_id: sheetId,
          from_row: from,
          rows
        }));

        sent += rows.length;
        if (typeof options.onProgress === 'function') {
          options.onProgress({sent, total, percent: Math.round((sent / total) * 100)});
        }
      }
    }

    // 3) finish — server verifies declared vs received row counts
    this._responseData(await api.post(endpoints.finish, {job_id: jobId}));

    return jobId;
  },

  /**
   * Copy the top-left value of every merged range into all covered cells,
   * so downstream profiling sees a rectangular table.
   * @param {Object} worksheet
   */
  _unmergeCells(worksheet) {
    const merges = worksheet['!merges'];
    if (!merges || merges.length === 0) {
      return;
    }
    for (const range of merges) {
      const source = worksheet[XLSX.utils.encode_cell({r: range.s.r, c: range.s.c})];
      if (!source) {
        continue;
      }
      for (let r = range.s.r; r <= range.e.r; r++) {
        for (let c = range.s.c; c <= range.e.c; c++) {
          const address = XLSX.utils.encode_cell({r, c});
          if (!worksheet[address]) {
            worksheet[address] = {t: source.t, v: source.v, w: source.w};
          }
        }
      }
    }
    worksheet['!merges'] = [];
  },

  /**
   * Normalize cell values (Date → ISO string) and drop trailing empty rows.
   * @param {Array<Array>} rows
   * @returns {Array<Array>}
   */
  _normalizeRows(rows) {
    const normalized = rows.map(row => row.map(cell => this._normalizeCell(cell)));

    // Drop trailing rows that are entirely empty
    let last = normalized.length - 1;
    while (last >= 0 && normalized[last].every(cell => cell === null)) {
      last--;
    }
    return normalized.slice(0, last + 1);
  },

  /**
   * @param {*} cell
   * @returns {*}
   */
  _normalizeCell(cell) {
    if (cell === undefined || cell === null) {
      return null;
    }
    if (cell instanceof Date) {
      if (isNaN(cell.getTime())) {
        return null;
      }
      // Date-only values get 'YYYY-MM-DD'; date-times keep the time part
      const pad = n => String(n).padStart(2, '0');
      const date = `${cell.getFullYear()}-${pad(cell.getMonth() + 1)}-${pad(cell.getDate())}`;
      if (cell.getHours() === 0 && cell.getMinutes() === 0 && cell.getSeconds() === 0) {
        return date;
      }
      return `${date} ${pad(cell.getHours())}:${pad(cell.getMinutes())}:${pad(cell.getSeconds())}`;
    }
    if (typeof cell === 'string') {
      const trimmed = cell.replace(/ /g, ' ').trim();
      return trimmed === '' ? null : trimmed;
    }
    return cell;
  },

  /**
   * @param {ArrayBuffer} buffer
   * @returns {Promise<string>} hex digest
   */
  async _sha256Hex(buffer) {
    // crypto.subtle is only available in secure context (HTTPS /localhost) — web client running on
    // Plain HTTP (e.g. subdomain under) is not available → use pure-JS SHA-256 instead.
    // (Values ​​obtained are exactly the same, tested to match crypto standards)
    if (typeof crypto !== 'undefined' && crypto.subtle && typeof crypto.subtle.digest === 'function') {
      const digest = await crypto.subtle.digest('SHA-256', buffer);
      return Array.from(new Uint8Array(digest))
        .map(b => b.toString(16).padStart(2, '0'))
        .join('');
    }
    return this._sha256Js(new Uint8Array(buffer));
  },

  /**
   * SHA-256 แบบ pure-JS (fallback สำหรับ non-secure context)
   *
   * @param {Uint8Array} data
   * @returns {string} hex digest 64 ตัว
   */
  _sha256Js(data) {
    const K = new Uint32Array([
      0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
      0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
      0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
      0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
      0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
      0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
      0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
      0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2
    ]);
    let h0 = 0x6a09e667, h1 = 0xbb67ae85, h2 = 0x3c6ef372, h3 = 0xa54ff53a;
    let h4 = 0x510e527f, h5 = 0x9b05688c, h6 = 0x1f83d9ab, h7 = 0x5be0cd19;
    const l = data.length;
    const bitLen = l * 8;
    const withOne = l + 1;
    const total = withOne + ((56 - (withOne % 64) + 64) % 64) + 8;
    const m = new Uint8Array(total);
    m.set(data);
    m[l] = 0x80;
    const dv = new DataView(m.buffer);
    dv.setUint32(total - 8, Math.floor(bitLen / 0x100000000));
    dv.setUint32(total - 4, bitLen >>> 0);
    const w = new Uint32Array(64);
    const rotr = (x, n) => (x >>> n) | (x << (32 - n));
    for (let i = 0; i < total; i += 64) {
      for (let t = 0; t < 16; t++) {
        w[t] = dv.getUint32(i + t * 4);
      }
      for (let t = 16; t < 64; t++) {
        const s0 = rotr(w[t - 15], 7) ^ rotr(w[t - 15], 18) ^ (w[t - 15] >>> 3);
        const s1 = rotr(w[t - 2], 17) ^ rotr(w[t - 2], 19) ^ (w[t - 2] >>> 10);
        w[t] = (w[t - 16] + s0 + w[t - 7] + s1) | 0;
      }
      let a = h0, b = h1, c = h2, d = h3, e = h4, f = h5, g = h6, h = h7;
      for (let t = 0; t < 64; t++) {
        const S1 = rotr(e, 6) ^ rotr(e, 11) ^ rotr(e, 25);
        const ch = (e & f) ^ (~e & g);
        const t1 = (h + S1 + ch + K[t] + w[t]) | 0;
        const S0 = rotr(a, 2) ^ rotr(a, 13) ^ rotr(a, 22);
        const maj = (a & b) ^ (a & c) ^ (b & c);
        const t2 = (S0 + maj) | 0;
        h = g; g = f; f = e; e = (d + t1) | 0; d = c; c = b; b = a; a = (t1 + t2) | 0;
      }
      h0 = (h0 + a) | 0; h1 = (h1 + b) | 0; h2 = (h2 + c) | 0; h3 = (h3 + d) | 0;
      h4 = (h4 + e) | 0; h5 = (h5 + f) | 0; h6 = (h6 + g) | 0; h7 = (h7 + h) | 0;
    }
    const hx = x => ('00000000' + (x >>> 0).toString(16)).slice(-8);
    return hx(h0) + hx(h1) + hx(h2) + hx(h3) + hx(h4) + hx(h5) + hx(h6) + hx(h7);
  },

  /**
   * Unwrap the response and throw the real server message on failure.
   *
   * ApiService (HttpClient) resolves to {success: <HTTP ok>, status, data: <body>}
   * where <body> is the API envelope {success, message, code, data: <payload>}.
   * Either layer may be absent depending on transport fallbacks, so unwrap
   * defensively and validate the success flag at both layers.
   *
   * @param {*} result
   * @returns {Object} payload
   */
  _responseData(result) {
    if (!result || typeof result !== 'object') {
      return {};
    }

    // Layer 1: HttpClient wrapper {success, status, statusText, data: body}
    let body = result;
    if ('status' in result && 'data' in result) {
      if (result.success === false && !(result.data && typeof result.data === 'object')) {
        throw new Error(result.statusText || 'Request failed');
      }
      body = result.data && typeof result.data === 'object' ? result.data : {};
    }

    // Layer 2: API envelope {success, message, code, data: payload}
    if ('success' in body || 'code' in body) {
      if (body.success === false) {
        throw new Error(body.message || 'Request failed');
      }
      return body.data && typeof body.data === 'object' ? body.data : {};
    }

    return body;
  }
};

// Register with Now framework
if (window.Now?.registerManager) {
  Now.registerManager('migrationParser', MigrationParser);
}

// Expose globally
window.MigrationParser = MigrationParser;

export default MigrationParser;
