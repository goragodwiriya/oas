/**
 * TablePlugin - Insert and manage tables
 *
 * @author Goragod Wiriya
 * @version 1.0
 */
import PluginBase from '../PluginBase.js';
import BaseDialog from '../../ui/dialogs/BaseDialog.js';
import EventBus from '../../core/EventBus.js';

const DEFAULT_TABLE_CLASS_OPTIONS = [
  {value: 'table', label: '.table', checked: true},
  {value: 'border', label: '.border', checked: true},
  {value: 'data', label: '.data', checked: false},
  {value: 'fullwidth', label: '.fullwidth', checked: true}
];

const CLASS_NAME_PATTERN = /^[a-zA-Z_][a-zA-Z0-9_-]*$/;

class TableDialog extends BaseDialog {
  constructor(editor, options = {}) {
    super(editor, {
      title: 'Insert Table',
      width: 420
    });

    this.options = {
      classOptions: DEFAULT_TABLE_CLASS_OPTIONS,
      ...options
    };
    this.mode = 'insert';
    this.additionalTags = [];
    this.presetCheckboxes = new Map();
  }

  setMode(mode = 'insert') {
    this.mode = mode === 'edit' ? 'edit' : 'insert';
    const isInsert = this.mode === 'insert';

    if (this.rowsField) this.rowsField.style.display = isInsert ? '' : 'none';
    if (this.colsField) this.colsField.style.display = isInsert ? '' : 'none';
    if (this.headerField) this.headerField.style.display = isInsert ? '' : 'none';

    if (this.dialog) {
      this.setTitle(this.mode === 'edit' ? this.translate('Table Properties') : this.translate('Insert Table'));
    }
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

    this.classField = document.createElement('div');
    this.classField.className = 'rte-dialog-field';
    const classLabel = document.createElement('div');
    classLabel.className = 'rte-dialog-label';
    classLabel.textContent = this.translate('Table classes');
    this.classField.appendChild(classLabel);

    this.classOptionList = document.createElement('div');
    this.classOptionList.className = 'rte-table-class-options';

    this.options.classOptions.forEach((option, index) => {
      const wrapper = document.createElement('label');
      wrapper.className = 'rte-table-class-option';

      const input = document.createElement('input');
      input.type = 'checkbox';
      input.id = `rte-table-class-${option.value}`;
      input.checked = option.checked !== false;
      input.dataset.className = option.value;
      this.presetCheckboxes.set(option.value, input);

      const text = document.createElement('span');
      text.textContent = this.translate(option.label || option.value);

      wrapper.appendChild(input);
      wrapper.appendChild(text);
      this.classOptionList.appendChild(wrapper);
    });

    this.classField.appendChild(this.classOptionList);
    this.body.appendChild(this.classField);

    this.extraClassField = document.createElement('div');
    this.extraClassField.className = 'rte-dialog-field';

    const extraLabel = document.createElement('label');
    extraLabel.className = 'rte-dialog-label';
    extraLabel.textContent = this.translate('Additional classes');
    extraLabel.setAttribute('for', 'rte-table-extra-class-input');
    this.extraClassField.appendChild(extraLabel);

    this.tagInput = document.createElement('div');
    this.tagInput.className = 'rte-tags-input';

    this.tagList = document.createElement('div');
    this.tagList.className = 'rte-tags-list';
    this.tagInput.appendChild(this.tagList);

    this.extraClassInput = document.createElement('input');
    this.extraClassInput.type = 'text';
    this.extraClassInput.id = 'rte-table-extra-class-input';
    this.extraClassInput.className = 'rte-dialog-input rte-tags-input-field';
    this.extraClassInput.placeholder = this.translate('Type class and press Enter');
    this.extraClassInput.addEventListener('keydown', (event) => this.handleTagInputKeydown(event));
    this.extraClassInput.addEventListener('blur', () => this.addTagsFromText(this.extraClassInput.value));
    this.tagInput.appendChild(this.extraClassInput);

    this.extraClassField.appendChild(this.tagInput);

    const help = document.createElement('div');
    help.className = 'rte-dialog-help';
    help.textContent = this.translate('Use Enter or comma to add class tags.');
    this.extraClassField.appendChild(help);

    this.body.appendChild(this.extraClassField);

    // Width field
    this.widthField = this.createField({
      type: 'text',
      label: 'Width',
      id: 'rte-table-width',
      placeholder: '100% or 500px'
    });
    this.body.appendChild(this.widthField);
    this.setMode(this.mode);
  }

  populate(data) {
    const rowsInput = this.rowsField.querySelector('input');
    const colsInput = this.colsField.querySelector('input');
    const headerInput = this.headerField.querySelector('input');
    const widthInput = this.widthField.querySelector('input');

    rowsInput.value = data.rows || 3;
    colsInput.value = data.cols || 3;
    headerInput.checked = data.hasHeader !== false;
    widthInput.value = data.width || '100%';

    const selectedClasses = new Set(data.tableClasses || []);
    this.presetCheckboxes.forEach((input, className) => {
      const option = this.options.classOptions.find(item => item.value === className);
      const defaultChecked = option ? option.checked !== false : false;
      input.checked = selectedClasses.size > 0 ? selectedClasses.has(className) : defaultChecked;
    });

    this.setAdditionalTags(data.additionalClasses || []);
  }

  getData() {
    const rowsInput = this.rowsField.querySelector('input');
    const colsInput = this.colsField.querySelector('input');
    const headerInput = this.headerField.querySelector('input');
    const widthInput = this.widthField.querySelector('input');

    this.addTagsFromText(this.extraClassInput.value);

    return {
      mode: this.mode,
      rows: parseInt(rowsInput.value) || 3,
      cols: parseInt(colsInput.value) || 3,
      hasHeader: headerInput.checked,
      width: widthInput.value.trim() || '100%',
      tableClasses: Array.from(this.presetCheckboxes.entries())
        .filter(([, input]) => input.checked)
        .map(([className]) => className),
      additionalClasses: [...this.additionalTags]
    };
  }

  validate() {
    this.clearError();
    const data = this.getData();

    if (data.mode !== 'insert') {
      return true;
    }

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

  handleTagInputKeydown(event) {
    if (event.key === 'Enter' || event.key === ',') {
      event.preventDefault();
      this.addTagsFromText(this.extraClassInput.value);
    } else if (event.key === 'Backspace' && !this.extraClassInput.value.trim()) {
      this.removeTag(this.additionalTags[this.additionalTags.length - 1]);
    }
  }

  addTagsFromText(text) {
    const values = String(text || '')
      .split(/[\s,]+/)
      .map(item => item.trim())
      .filter(Boolean);

    if (values.length === 0) {
      this.extraClassInput.value = '';
      return;
    }

    values.forEach(value => {
      if (!CLASS_NAME_PATTERN.test(value)) {
        return;
      }

      if (!this.additionalTags.includes(value)) {
        this.additionalTags.push(value);
      }
    });

    this.additionalTags.sort((a, b) => a.localeCompare(b));
    this.extraClassInput.value = '';
    this.renderTagList();
  }

  setAdditionalTags(tags) {
    this.additionalTags = [];
    (tags || []).forEach(tag => {
      if (CLASS_NAME_PATTERN.test(tag) && !this.additionalTags.includes(tag)) {
        this.additionalTags.push(tag);
      }
    });
    this.additionalTags.sort((a, b) => a.localeCompare(b));
    this.renderTagList();
  }

  removeTag(tagName) {
    if (!tagName) {
      return;
    }

    this.additionalTags = this.additionalTags.filter(tag => tag !== tagName);
    this.renderTagList();
  }

  renderTagList() {
    this.tagList.innerHTML = '';

    this.additionalTags.forEach(tagName => {
      const tag = document.createElement('button');
      tag.type = 'button';
      tag.className = 'rte-tag-item';
      tag.title = this.translate('Remove class');
      tag.textContent = tagName;
      tag.addEventListener('click', () => this.removeTag(tagName));
      this.tagList.appendChild(tag);
    });
  }
}

class TablePlugin extends PluginBase {
  static pluginName = 'table';

  init() {
    super.init();

    this.options = {
      classOptions: DEFAULT_TABLE_CLASS_OPTIONS,
      ...this.options
    };
    this.editingTable = null;

    // Create dialog
    this.dialog = new TableDialog(this.editor, {
      classOptions: this.options.classOptions
    });
    this.dialog.onConfirm = (data) => this.handleTableDialogConfirm(data);

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

    const table = cell.closest('table');
    const hasClass = (className) => !!table?.classList.contains(className);

    const menuItems = [
      {
        label: `${hasClass('border') ? '[x]' : '[ ]'} Toggle .border`,
        action: () => this.toggleTableClass('border')
      },
      {
        label: `${hasClass('data') ? '[x]' : '[ ]'} Toggle .data`,
        action: () => this.toggleTableClass('data')
      },
      {
        label: `${hasClass('fullwidth') ? '[x]' : '[ ]'} Toggle .fullwidth`,
        action: () => this.toggleTableClass('fullwidth')
      },
      {type: 'separator'},
      {label: 'Table properties...', action: () => this.openTablePropertiesDialog(table)},
      {type: 'separator'},
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
    this.editingTable = null;
    this.dialog.setMode('insert');
    this.saveSelection();
    this.dialog.open({});
  }

  openTablePropertiesDialog(table = null) {
    const targetTable = table || this.getTableContext()?.table;
    if (!targetTable) {
      return;
    }

    this.editingTable = targetTable;
    this.saveSelection();
    this.dialog.setMode('edit');
    this.dialog.open(this.extractTableProperties(targetTable));
  }

  handleTableDialogConfirm(data) {
    if (data.mode === 'edit') {
      this.updateTableProperties(data);
    } else {
      this.insertTable(data);
    }
  }

  /**
   * Insert table
   * @param {Object} data - Table data
   */
  insertTable(data) {
    this.restoreSelection();

    const {rows, cols, hasHeader, width} = data;
    const tableClasses = this.resolveTableClasses(data);
    const tableClassAttr = tableClasses.length > 0 ? ` class="${tableClasses.join(' ')}"` : '';
    const tableStyleAttr = width ? ` style="width: ${width};"` : '';

    let html = `<div class="tablebody"><table${tableClassAttr}${tableStyleAttr}>`;

    for (let r = 0; r < rows; r++) {
      html += '<tr>';
      for (let c = 0; c < cols; c++) {
        const isHeader = hasHeader && r === 0;
        const tag = isHeader ? 'th' : 'td';

        html += `<${tag}>&nbsp;</${tag}>`;
      }
      html += '</tr>';
    }

    html += '</table></div><p></p>';

    this.insertHtml(html);
    this.recordHistory(true);
    this.focusEditor();
  }

  extractTableProperties(table) {
    const classes = Array.from(table.classList);
    const presetClassSet = new Set((this.options.classOptions || []).map(option => option.value));

    return {
      width: table.style.width || '',
      tableClasses: classes.filter(className => presetClassSet.has(className)),
      additionalClasses: classes.filter(className => !presetClassSet.has(className)),
      rows: table.rows?.length || 1,
      cols: table.rows?.[0]?.cells?.length || 1,
      hasHeader: !!table.querySelector('th')
    };
  }

  updateTableProperties(data) {
    const table = this.editingTable || this.getTableContext()?.table;
    if (!table) {
      return;
    }

    this.ensureTableWrapper(table);

    const classes = this.resolveTableClasses(data);
    table.className = classes.join(' ');

    if (data.width) {
      table.style.width = data.width;
    } else {
      table.style.removeProperty('width');
    }

    if (table.classList.contains('fullwidth') && !table.style.width) {
      table.style.width = '100%';
    }

    this.recordHistory(true);
    this.focusEditor();
  }

  toggleTableClass(className) {
    const table = this.getTableContext()?.table;
    if (!table) {
      return;
    }

    this.ensureTableWrapper(table);

    if (!table.classList.contains('table')) {
      table.classList.add('table');
    }

    const enabled = table.classList.toggle(className);
    if (className === 'fullwidth') {
      if (enabled) {
        table.style.width = '100%';
      } else if (table.style.width === '100%') {
        table.style.removeProperty('width');
      }
    }

    this.recordHistory(true);
    this.focusEditor();
  }

  ensureTableWrapper(table) {
    if (table.parentElement?.classList.contains('tablebody')) {
      return;
    }

    const wrapper = document.createElement('div');
    wrapper.className = 'tablebody';
    table.parentNode?.insertBefore(wrapper, table);
    wrapper.appendChild(table);
  }

  resolveTableClasses(data = {}) {
    const classes = new Set(['table']);
    (data.tableClasses || []).forEach(className => {
      if (CLASS_NAME_PATTERN.test(className)) {
        classes.add(className);
      }
    });

    (data.additionalClasses || []).forEach(className => {
      if (CLASS_NAME_PATTERN.test(className)) {
        classes.add(className);
      }
    });

    return Array.from(classes);
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
