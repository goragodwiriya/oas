/**
 * TablePlugin - Insert and manage tables
 *
 * @author Goragod Wiriya
 * @version 1.0
 */
import PluginBase from '../PluginBase.js';
import BaseDialog from '../../ui/dialogs/BaseDialog.js';
import EventBus from '../../core/EventBus.js';

class TableDialog extends BaseDialog {
  constructor(editor) {
    super(editor, {
      title: 'Insert Table',
      width: 350
    });
  }

  buildBody() {
    // Rows field
    this.rowsField = this.createField({
      type: 'number',
      label: 'Rows',
      id: 'rte-table-rows',
      value: 3,
      placeholder: '3'
    });
    const rowsInput = this.rowsField.querySelector('input');
    rowsInput.min = 1;
    rowsInput.max = 50;
    this.body.appendChild(this.rowsField);

    // Columns field
    this.colsField = this.createField({
      type: 'number',
      label: 'Columns',
      id: 'rte-table-cols',
      value: 3,
      placeholder: '3'
    });
    const colsInput = this.colsField.querySelector('input');
    colsInput.min = 1;
    colsInput.max = 20;
    this.body.appendChild(this.colsField);

    // Header row checkbox
    this.headerField = this.createField({
      type: 'checkbox',
      id: 'rte-table-header',
      checkLabel: 'First row as header',
      checked: true
    });
    this.body.appendChild(this.headerField);

    // Border checkbox
    this.borderField = this.createField({
      type: 'checkbox',
      id: 'rte-table-border',
      checkLabel: 'Show border',
      checked: true
    });
    this.body.appendChild(this.borderField);

    // Width field
    this.widthField = this.createField({
      type: 'text',
      label: 'Width',
      id: 'rte-table-width',
      placeholder: '100% or 500px'
    });
    this.body.appendChild(this.widthField);
  }

  populate(data) {
    const rowsInput = this.rowsField.querySelector('input');
    const colsInput = this.colsField.querySelector('input');
    const headerInput = this.headerField.querySelector('input');
    const borderInput = this.borderField.querySelector('input');
    const widthInput = this.widthField.querySelector('input');

    rowsInput.value = data.rows || 3;
    colsInput.value = data.cols || 3;
    headerInput.checked = data.hasHeader !== false;
    borderInput.checked = data.hasBorder !== false;
    widthInput.value = data.width || '100%';
  }

  getData() {
    const rowsInput = this.rowsField.querySelector('input');
    const colsInput = this.colsField.querySelector('input');
    const headerInput = this.headerField.querySelector('input');
    const borderInput = this.borderField.querySelector('input');
    const widthInput = this.widthField.querySelector('input');

    return {
      rows: parseInt(rowsInput.value) || 3,
      cols: parseInt(colsInput.value) || 3,
      hasHeader: headerInput.checked,
      hasBorder: borderInput.checked,
      width: widthInput.value.trim() || '100%'
    };
  }

  validate() {
    this.clearError();
    const data = this.getData();

    if (data.rows < 1 || data.rows > 50) {
      this.showError('Rows must be between 1 and 50', this.rowsField);
      return false;
    }

    if (data.cols < 1 || data.cols > 20) {
      this.showError('Columns must be between 1 and 20', this.colsField);
      return false;
    }

    return true;
  }
}

class TablePlugin extends PluginBase {
  static pluginName = 'table';

  init() {
    super.init();

    // Create dialog
    this.dialog = new TableDialog(this.editor);
    this.dialog.onConfirm = (data) => this.insertTable(data);

    // Create context menu for table operations
    this.setupContextMenu();

    // Listen for toolbar button click
    this.subscribe(EventBus.Events.TOOLBAR_BUTTON_CLICK, (event) => {
      if (event.id === 'table') {
        this.openDialog();
      }
    });

    // Register commands
    this.registerCommand('insertTable', {
      execute: (data) => this.insertTable(data)
    });

    this.registerCommand('addRowBefore', {
      execute: () => this.addRow('before')
    });

    this.registerCommand('addRowAfter', {
      execute: () => this.addRow('after')
    });

    this.registerCommand('addColBefore', {
      execute: () => this.addColumn('before')
    });

    this.registerCommand('addColAfter', {
      execute: () => this.addColumn('after')
    });

    this.registerCommand('deleteRow', {
      execute: () => this.deleteRow()
    });

    this.registerCommand('deleteCol', {
      execute: () => this.deleteColumn()
    });

    this.registerCommand('deleteTable', {
      execute: () => this.deleteTable()
    });
  }

  /**
   * Setup context menu for table operations
   */
  setupContextMenu() {
    const contentEl = this.editor.contentArea?.getElement();
    if (!contentEl) return;

    contentEl.addEventListener('contextmenu', (e) => {
      const cell = e.target.closest('td, th');
      if (!cell) return;

      e.preventDefault();
      this.showTableContextMenu(e, cell);
    });
  }

  /**
   * Show table context menu
   * @param {MouseEvent} event
   * @param {HTMLTableCellElement} cell
   */
  showTableContextMenu(event, cell) {
    // Remove existing context menu
    this.hideContextMenu();

    const menu = document.createElement('div');
    menu.className = 'rte-table-context-menu';
    menu.style.cssText = `
      position: fixed;
      left: ${event.clientX}px;
      top: ${event.clientY}px;
      background: var(--rte-bg-color, #fff);
      border: 1px solid var(--rte-border-color, #ddd);
      border-radius: 6px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.15);
      padding: 4px 0;
      z-index: 10001;
      min-width: 180px;
    `;

    const menuItems = [
      {label: 'Insert row above', action: () => this.addRow('before')},
      {label: 'Insert row below', action: () => this.addRow('after')},
      {type: 'separator'},
      {label: 'Insert column left', action: () => this.addColumn('before')},
      {label: 'Insert column right', action: () => this.addColumn('after')},
      {type: 'separator'},
      {label: 'Delete row', action: () => this.deleteRow()},
      {label: 'Delete column', action: () => this.deleteColumn()},
      {type: 'separator'},
      {label: 'Delete table', action: () => this.deleteTable(), danger: true}
    ];

    menuItems.forEach(item => {
      if (item.type === 'separator') {
        const sep = document.createElement('div');
        sep.style.cssText = 'height: 1px; background: var(--rte-border-color, #ddd); margin: 4px 0;';
        menu.appendChild(sep);
      } else {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = this.translate(item.label);
        btn.style.cssText = `
          display: block;
          width: 100%;
          padding: 8px 16px;
          border: none;
          background: transparent;
          text-align: left;
          cursor: pointer;
          color: ${item.danger ? '#f44336' : 'inherit'};
        `;
        btn.addEventListener('mouseover', () => {
          btn.style.background = 'var(--rte-bg-hover, #f0f0f0)';
        });
        btn.addEventListener('mouseout', () => {
          btn.style.background = 'transparent';
        });
        btn.addEventListener('click', () => {
          this.hideContextMenu();
          item.action();
        });
        menu.appendChild(btn);
      }
    });

    document.body.appendChild(menu);
    this.contextMenu = menu;

    // Store current cell for operations
    this.currentCell = cell;

    // Close on outside click
    const closeHandler = (e) => {
      if (!menu.contains(e.target)) {
        this.hideContextMenu();
        document.removeEventListener('click', closeHandler);
      }
    };
    setTimeout(() => {
      document.addEventListener('click', closeHandler);
    }, 0);
  }

  /**
   * Hide context menu
   */
  hideContextMenu() {
    if (this.contextMenu) {
      this.contextMenu.remove();
      this.contextMenu = null;
    }
  }

  /**
   * Open table dialog
   */
  openDialog() {
    this.saveSelection();
    this.dialog.open({});
  }

  /**
   * Insert table
   * @param {Object} data - Table data
   */
  insertTable(data) {
    this.restoreSelection();

    const {rows, cols, hasHeader, hasBorder, width} = data;

    let html = `<table style="width: ${width}; border-collapse: collapse;">`;

    for (let r = 0; r < rows; r++) {
      html += '<tr>';
      for (let c = 0; c < cols; c++) {
        const isHeader = hasHeader && r === 0;
        const tag = isHeader ? 'th' : 'td';
        const borderStyle = hasBorder ? 'border: 1px solid #ddd;' : '';
        const headerBg = isHeader ? 'background: #f5f5f5;' : '';

        html += `<${tag} style="${borderStyle} ${headerBg} padding: 8px;">&nbsp;</${tag}>`;
      }
      html += '</tr>';
    }

    html += '</table><p></p>';

    this.insertHtml(html);
    this.recordHistory(true);
    this.focusEditor();
  }

  /**
   * Get current table context
   * @returns {Object|null}
   */
  getTableContext() {
    const cell = this.currentCell || this.getSelection()?.getAncestor('td, th');
    if (!cell) return null;

    const row = cell.parentElement;
    const table = cell.closest('table');
    if (!table || !row) return null;

    const cells = Array.from(row.cells);
    const colIndex = cells.indexOf(cell);
    const rows = Array.from(table.rows);
    const rowIndex = rows.indexOf(row);

    return {table, row, cell, rowIndex, colIndex};
  }

  /**
   * Add row
   * @param {string} position - 'before' or 'after'
   */
  addRow(position) {
    const ctx = this.getTableContext();
    if (!ctx) return;

    const colCount = ctx.row.cells.length;
    const newRow = ctx.table.insertRow(position === 'before' ? ctx.rowIndex : ctx.rowIndex + 1);

    for (let i = 0; i < colCount; i++) {
      const cell = newRow.insertCell();
      cell.innerHTML = '&nbsp;';
      cell.style.cssText = ctx.row.cells[0]?.style.cssText || 'border: 1px solid #ddd; padding: 8px;';
    }

    this.recordHistory(true);
  }

  /**
   * Add column
   * @param {string} position - 'before' or 'after'
   */
  addColumn(position) {
    const ctx = this.getTableContext();
    if (!ctx) return;

    const insertIndex = position === 'before' ? ctx.colIndex : ctx.colIndex + 1;

    Array.from(ctx.table.rows).forEach((row, rowIndex) => {
      const isHeader = rowIndex === 0 && row.cells[0]?.tagName === 'TH';
      const cell = row.insertCell(insertIndex);

      if (isHeader) {
        // Convert to th
        const th = document.createElement('th');
        th.innerHTML = '&nbsp;';
        th.style.cssText = row.cells[0]?.style.cssText || 'border: 1px solid #ddd; padding: 8px; background: #f5f5f5;';
        row.replaceChild(th, cell);
      } else {
        cell.innerHTML = '&nbsp;';
        cell.style.cssText = row.cells[0]?.style.cssText || 'border: 1px solid #ddd; padding: 8px;';
      }
    });

    this.recordHistory(true);
  }

  /**
   * Delete row
   */
  deleteRow() {
    const ctx = this.getTableContext();
    if (!ctx) return;

    if (ctx.table.rows.length <= 1) {
      this.deleteTable();
      return;
    }

    ctx.table.deleteRow(ctx.rowIndex);
    this.recordHistory(true);
  }

  /**
   * Delete column
   */
  deleteColumn() {
    const ctx = this.getTableContext();
    if (!ctx) return;

    if (ctx.row.cells.length <= 1) {
      this.deleteTable();
      return;
    }

    Array.from(ctx.table.rows).forEach(row => {
      if (row.cells[ctx.colIndex]) {
        row.deleteCell(ctx.colIndex);
      }
    });

    this.recordHistory(true);
  }

  /**
   * Delete entire table
   */
  deleteTable() {
    const ctx = this.getTableContext();
    if (!ctx) return;

    ctx.table.remove();
    this.recordHistory(true);
  }

  destroy() {
    this.hideContextMenu();
    this.dialog?.destroy();
    super.destroy();
  }
}

export default TablePlugin;
