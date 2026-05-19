/**
 * ContentCleanupPlugin - Clean imported HTML while preserving selected framework classes.
 *
 * @author Goragod Wiriya
 * @version 1.0
 */
import PluginBase from '../PluginBase.js';
import BaseDialog from '../../ui/dialogs/BaseDialog.js';
import EventBus from '../../core/EventBus.js';
import {cleanupHtmlFragment, DEFAULT_CLASS_PREFIXES, DEFAULT_TABLE_ATTRIBUTE_ALLOW_MAP} from '../../core/HtmlCleanup.js';

class CleanupDialog extends BaseDialog {
    constructor (editor, plugin) {
        super(editor, {
            title: 'Clean HTML',
            width: 560
        });
        this.plugin = plugin;
    }

    buildBody() {
        this.scopeField = this.createField({
            type: 'select',
            label: 'Clean target',
            id: 'rte-clean-scope',
            options: [
                {value: 'selection', label: 'Selected content'},
                {value: 'document', label: 'Whole document'}
            ]
        });
        this.body.appendChild(this.scopeField);

        this.removeHorizontalRulesField = this.createField({
            type: 'checkbox',
            id: 'rte-clean-hr',
            checkLabel: 'Remove horizontal rules',
            checked: true
        });
        this.body.appendChild(this.removeHorizontalRulesField);

        this.removeIdsField = this.createField({
            type: 'checkbox',
            id: 'rte-clean-id',
            checkLabel: 'Remove id attributes',
            checked: true
        });
        this.body.appendChild(this.removeIdsField);

        this.removeStylesField = this.createField({
            type: 'checkbox',
            id: 'rte-clean-style',
            checkLabel: 'Remove inline styles',
            checked: true
        });
        this.body.appendChild(this.removeStylesField);

        this.cleanClassesField = this.createField({
            type: 'checkbox',
            id: 'rte-clean-classes',
            checkLabel: 'Keep only allowed CSS classes',
            checked: true
        });
        this.body.appendChild(this.cleanClassesField);

        this.cleanTablesField = this.createField({
            type: 'checkbox',
            id: 'rte-clean-tables',
            checkLabel: 'Clean table structure and attributes',
            checked: true
        });
        this.body.appendChild(this.cleanTablesField);

        this.allowedClassesField = this.createField({
            type: 'text',
            label: 'Additional allowed classes',
            id: 'rte-clean-allowed-classes',
            placeholder: 'center, left, right',
            help: 'Framework defaults from layout.css are kept automatically. Add extra classes here when needed.'
        });
        this.body.appendChild(this.allowedClassesField);
    }

    buildFooter() {
        super.buildFooter();
        this.confirmBtn.textContent = this.translate('Clean');
    }

    populate(data) {
        this.getInputFromField(this.scopeField).value = data.scope || 'selection';
        this.removeHorizontalRulesField.querySelector('input').checked = data.removeHorizontalRules !== false;
        this.removeIdsField.querySelector('input').checked = data.removeIds !== false;
        this.removeStylesField.querySelector('input').checked = data.removeStyles !== false;
        this.cleanClassesField.querySelector('input').checked = data.cleanClasses !== false;
        this.cleanTablesField.querySelector('input').checked = data.cleanTables !== false;
        this.getInputFromField(this.allowedClassesField).value = (data.additionalAllowedClasses || []).join(', ');

        const scopeInput = this.getInputFromField(this.scopeField);
        const selectionOption = Array.from(scopeInput.options).find(option => option.value === 'selection');
        if (selectionOption) {
            selectionOption.disabled = !data.hasSelection;
        }
        if (!data.hasSelection && scopeInput.value === 'selection') {
            scopeInput.value = 'document';
        }
    }

    getData() {
        return {
            scope: this.getInputFromField(this.scopeField).value,
            removeHorizontalRules: this.removeHorizontalRulesField.querySelector('input').checked,
            removeIds: this.removeIdsField.querySelector('input').checked,
            removeStyles: this.removeStylesField.querySelector('input').checked,
            cleanClasses: this.cleanClassesField.querySelector('input').checked,
            cleanTables: this.cleanTablesField.querySelector('input').checked,
            allowedClasses: this.getInputFromField(this.allowedClassesField).value
        };
    }
}

class ContentCleanupPlugin extends PluginBase {
    static pluginName = 'contentCleanup';

    static DEFAULT_ALLOWED_CLASSES = [
        'left', 'center', 'right', 'justify',
        'top', 'bottom', 'middle', 'baseline',
        'float-left', 'float-right', 'float-center',
        'block', 'inline', 'inline-block', 'flex', 'grid'
    ];

    init() {
        super.init();

        this.options = {
            allowedClasses: [...ContentCleanupPlugin.DEFAULT_ALLOWED_CLASSES],
            removeHorizontalRules: true,
            removeIds: true,
            removeStyles: true,
            cleanClasses: true,
            cleanTables: true,
            ...this.options
        };

        this.dialog = new CleanupDialog(this.editor, this);
        this.dialog.onConfirm = (data) => this.cleanContent(data);

        this.subscribe(EventBus.Events.TOOLBAR_BUTTON_CLICK, (event) => {
            if (event.id === 'cleanContent') {
                this.openDialog();
            }
        });

        this.registerCommand('cleanContent', {
            execute: () => this.openDialog()
        });
    }

    openDialog() {
        this.saveSelection();
        this.dialog.open({
            scope: this.editor.selection?.hasSelection() ? 'selection' : 'document',
            additionalAllowedClasses: [],
            removeHorizontalRules: this.options.removeHorizontalRules,
            removeIds: this.options.removeIds,
            removeStyles: this.options.removeStyles,
            cleanClasses: this.options.cleanClasses,
            cleanTables: this.options.cleanTables,
            hasSelection: this.editor.selection?.hasSelection() || false
        });
    }

    cleanContent(data) {
        this.restoreSelection();

        const hasSelection = this.editor.selection?.hasSelection() || false;
        const scope = data.scope === 'selection' && hasSelection ? 'selection' : 'document';
        const sourceHtml = scope === 'selection'
            ? this.editor.selection?.getSelectedHtml() || ''
            : this.getDocumentHtml();

        const cleanedHtml = this.cleanHtmlFragment(sourceHtml, data);

        if (scope === 'selection') {
            this.insertHtml(cleanedHtml);
            this.recordHistory(true);
            this.emit(EventBus.Events.CONTENT_CHANGE);
        } else {
            this.setContent(cleanedHtml);
        }

        this.focusEditor();
        this.notify(this.translate('Content cleaned'), 'success');
        return true;
    }

    getDocumentHtml() {
        return this.editor.contentArea?.getContent?.() || this.getContent() || '';
    }

    cleanHtmlFragment(html, overrides = {}) {
        if (!html) return '';

        const settings = this.resolveSettings(overrides);
        const output = cleanupHtmlFragment(html, {
            removeSelectors: settings.removeHorizontalRules
                ? (settings.cleanTables ? ['hr', 'colgroup', 'col'] : ['hr'])
                : (settings.cleanTables ? ['colgroup', 'col'] : []),
            removeIds: settings.removeIds,
            removeStyles: settings.removeStyles,
            cleanClasses: settings.cleanClasses,
            allowedClasses: this.getAllowedClasses(settings.allowedClasses),
            classPrefixes: DEFAULT_CLASS_PREFIXES,
            attributeAllowMap: settings.cleanTables ? DEFAULT_TABLE_ATTRIBUTE_ALLOW_MAP : {},
            normalizeTables: settings.cleanTables
        });

        return this.editor.options?.sanitize !== false
            ? this.editor.sanitizeHtml(output)
            : output;
    }

    resolveSettings(overrides = {}) {
        return {
            removeHorizontalRules: overrides.removeHorizontalRules ?? this.options.removeHorizontalRules,
            removeIds: overrides.removeIds ?? this.options.removeIds,
            removeStyles: overrides.removeStyles ?? this.options.removeStyles,
            cleanClasses: overrides.cleanClasses ?? this.options.cleanClasses,
            cleanTables: overrides.cleanTables ?? this.options.cleanTables,
            allowedClasses: overrides.allowedClasses ?? ''
        };
    }

    getAllowedClasses(extra = '') {
        const extraClasses = Array.isArray(extra)
            ? extra
            : String(extra || '').split(',');

        const merged = [
            ...(Array.isArray(this.options.allowedClasses) ? this.options.allowedClasses : []),
            ...extraClasses
        ];

        return Array.from(new Set(
            merged
                .map(item => String(item || '').trim().toLowerCase())
                .filter(Boolean)
        ));
    }

    destroy() {
        this.dialog?.destroy();
        super.destroy();
    }
}

export default ContentCleanupPlugin;