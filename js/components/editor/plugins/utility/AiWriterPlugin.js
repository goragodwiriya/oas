/**
 * AiWriterPlugin - Generate and rewrite content with the configured AI provider.
 *
 * @author Goragod Wiriya
 * @version 1.0
 */
import PluginBase from '../PluginBase.js';
import BaseDialog from '../../ui/dialogs/BaseDialog.js';
import EventBus from '../../core/EventBus.js';

class AsyncDialog extends BaseDialog {
    constructor (editor, options = {}) {
        super(editor, options);
        this.plugin = options.plugin;
    }

    async handleConfirm() {
        if (!this.validate()) return;

        this.clearError();
        this.setLoading(true);
        this.editor.selection.savedSelection = this.savedSelection;

        try {
            const result = await this.onConfirm(this.getData());
            if (result !== false) {
                this.close();
            }
        } catch (error) {
            this.showError(error?.message || '{LNG_Request failed.} {LNG_Please try again later.}');
        } finally {
            this.setLoading(false);
        }
    }
}

class GenerateDialog extends AsyncDialog {
    constructor (editor, plugin) {
        super(editor, {
            plugin,
            title: 'Generate content with AI',
            width: 560
        });
    }

    buildBody() {
        this.promptField = this.createField({
            type: 'textarea',
            label: 'Prompt',
            id: 'rte-ai-generate-prompt',
            rows: 7,
            placeholder: 'Describe the article, section, or announcement you want to create.',
            required: true
        });
        this.body.appendChild(this.promptField);

        this.targetField = this.createField({
            type: 'select',
            label: 'Insert result',
            id: 'rte-ai-generate-target',
            options: [
                {value: 'insertAtCursor', label: 'Insert at cursor'},
                {value: 'replaceSelection', label: 'Replace selection'},
                {value: 'replaceDocument', label: 'Replace whole document'}
            ]
        });
        this.body.appendChild(this.targetField);

        this.contextField = this.createField({
            type: 'checkbox',
            id: 'rte-ai-generate-context',
            checkLabel: 'Use current document as context',
            checked: true
        });
        this.body.appendChild(this.contextField);
    }

    buildFooter() {
        super.buildFooter();
        this.confirmBtn.textContent = this.translate('Generate');
    }

    populate(data) {
        const promptInput = this.getInputFromField(this.promptField);
        const targetInput = this.getInputFromField(this.targetField);
        const contextInput = this.contextField.querySelector('input');

        promptInput.value = data.prompt || '';
        targetInput.value = data.target || 'insertAtCursor';
        contextInput.checked = data.useContext !== false;

        const replaceSelectionOption = Array.from(targetInput.options).find(option => option.value === 'replaceSelection');
        if (replaceSelectionOption) {
            replaceSelectionOption.disabled = !data.hasSelection;
        }
        if (!data.hasSelection && targetInput.value === 'replaceSelection') {
            targetInput.value = 'insertAtCursor';
        }
    }

    getData() {
        return {
            prompt: this.getInputFromField(this.promptField).value.trim(),
            target: this.getInputFromField(this.targetField).value,
            useContext: this.contextField.querySelector('input').checked
        };
    }

    validate() {
        this.clearError();
        const data = this.getData();

        if (!data.prompt) {
            this.showError('prompt is required', this.promptField);
            return false;
        }

        return true;
    }
}

class RewriteDialog extends AsyncDialog {
    constructor (editor, plugin) {
        super(editor, {
            plugin,
            title: 'Rewrite with AI',
            width: 560
        });
    }

    buildBody() {
        this.promptField = this.createField({
            type: 'textarea',
            label: 'Rewrite instruction',
            id: 'rte-ai-rewrite-prompt',
            rows: 5,
            placeholder: 'Rewrite in a clearer, original tone while preserving the important facts.',
            required: true
        });
        this.body.appendChild(this.promptField);

        this.scopeField = this.createField({
            type: 'select',
            label: 'Rewrite target',
            id: 'rte-ai-rewrite-scope',
            options: [
                {value: 'selection', label: 'Selected content'},
                {value: 'document', label: 'Whole document'}
            ]
        });
        this.body.appendChild(this.scopeField);
    }

    buildFooter() {
        super.buildFooter();
        this.confirmBtn.textContent = this.translate('Rewrite');
    }

    populate(data) {
        const promptInput = this.getInputFromField(this.promptField);
        const scopeInput = this.getInputFromField(this.scopeField);

        promptInput.value = data.prompt || '';
        scopeInput.value = data.scope || 'selection';

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
            prompt: this.getInputFromField(this.promptField).value.trim(),
            scope: this.getInputFromField(this.scopeField).value
        };
    }

    validate() {
        this.clearError();
        const data = this.getData();

        if (!data.prompt) {
            this.showError('Please enter a rewrite instruction.', this.promptField);
            return false;
        }

        return true;
    }
}

class GenerateImageDialog extends AsyncDialog {
    constructor (editor, plugin) {
        super(editor, {
            plugin,
            title: 'Generate image with AI',
            width: 560
        });
    }

    buildBody() {
        this.promptField = this.createField({
            type: 'textarea',
            label: 'Image prompt',
            id: 'rte-ai-image-prompt',
            rows: 6,
            placeholder: 'Describe the image you want to create.',
            required: true
        });
        this.body.appendChild(this.promptField);

        this.altField = this.createField({
            type: 'text',
            label: 'Alt text',
            id: 'rte-ai-image-alt',
            placeholder: 'Accessible description for the generated image'
        });
        this.body.appendChild(this.altField);

        this.sizeField = this.createField({
            type: 'select',
            label: 'Image size',
            id: 'rte-ai-image-size',
            options: [
                {value: '1024x1024', label: '{LNG_Square} 1024 x 1024'},
                {value: '1536x1024', label: '{LNG_Landscape} 1536 x 1024'},
                {value: '1024x1536', label: '{LNG_Portrait} 1024 x 1536'}
            ]
        });
        this.body.appendChild(this.sizeField);

        this.alignField = this.createField({
            type: 'select',
            label: 'Alignment',
            id: 'rte-ai-image-align',
            options: [
                {value: '', label: 'None'},
                {value: 'left', label: 'Left'},
                {value: 'center', label: 'Center'},
                {value: 'right', label: 'Right'}
            ]
        });
        this.body.appendChild(this.alignField);
    }

    buildFooter() {
        super.buildFooter();
        this.confirmBtn.textContent = this.translate('Generate image');
    }

    populate(data) {
        this.getInputFromField(this.promptField).value = data.prompt || '';
        this.getInputFromField(this.altField).value = data.altText || '';
        this.getInputFromField(this.sizeField).value = data.size || '1024x1024';
        this.getInputFromField(this.alignField).value = data.align || '';
    }

    getData() {
        return {
            prompt: this.getInputFromField(this.promptField).value.trim(),
            altText: this.getInputFromField(this.altField).value.trim(),
            size: this.getInputFromField(this.sizeField).value,
            align: this.getInputFromField(this.alignField).value
        };
    }

    validate() {
        this.clearError();
        const data = this.getData();

        if (!data.prompt) {
            this.showError('prompt is required', this.promptField);
            return false;
        }

        return true;
    }
}

class AiWriterPlugin extends PluginBase {
    static pluginName = 'aiWriter';

    static DEFAULT_ALLOWED_CLASSES = [
        'left', 'center', 'right', 'justify',
        'top', 'bottom', 'middle', 'baseline',
        'float-left', 'float-right', 'float-center',
        'block', 'inline', 'inline-block'
    ];

    init() {
        super.init();

        this.options = {
            endpoint: '',
            maxContextLength: 12000,
            allowedClasses: [...AiWriterPlugin.DEFAULT_ALLOWED_CLASSES],
            defaultGeneratePrompt: '',
            defaultRewritePrompt: 'Rewrite this content in new words while preserving the important facts.',
            defaultImagePrompt: '',
            defaultImageSize: '1024x1024',
            cleanupAiOutput: true,
            ...this.options
        };

        this.generateDialog = new GenerateDialog(this.editor, this);
        this.generateDialog.onConfirm = (data) => this.generateContent(data);

        this.rewriteDialog = new RewriteDialog(this.editor, this);
        this.rewriteDialog.onConfirm = (data) => this.rewriteContent(data);

        this.imageDialog = new GenerateImageDialog(this.editor, this);
        this.imageDialog.onConfirm = (data) => this.generateImage(data);

        this.subscribe(EventBus.Events.TOOLBAR_BUTTON_CLICK, (event) => {
            if (event.id === 'aiGenerate') {
                this.openGenerateDialog();
            } else if (event.id === 'aiRewrite') {
                this.openRewriteDialog();
            } else if (event.id === 'aiImage') {
                this.openImageDialog();
            }
        });

        this.registerCommand('aiGenerate', {
            execute: () => this.openGenerateDialog()
        });

        this.registerCommand('aiRewrite', {
            execute: () => this.openRewriteDialog()
        });

        this.registerCommand('aiImage', {
            execute: () => this.openImageDialog()
        });
    }

    openGenerateDialog() {
        this.saveSelection();
        this.generateDialog.open({
            prompt: this.options.defaultGeneratePrompt,
            target: this.getSelection().hasSelection() ? 'replaceSelection' : 'insertAtCursor',
            useContext: true,
            hasSelection: this.getSelection().hasSelection()
        });
    }

    openRewriteDialog() {
        this.saveSelection();
        const hasSelection = this.getSelection().hasSelection();
        this.rewriteDialog.open({
            prompt: this.options.defaultRewritePrompt,
            scope: hasSelection ? 'selection' : 'document',
            hasSelection
        });
    }

    openImageDialog() {
        this.saveSelection();
        this.imageDialog.open({
            prompt: this.options.defaultImagePrompt,
            altText: '',
            size: this.options.defaultImageSize,
            align: ''
        });
    }

    async generateContent(data) {
        const response = await this.request('generate', {
            prompt: data.prompt,
            context_html: data.useContext ? this.truncateHtml(this.getDocumentHtml()) : '',
            allowed_classes: this.getAllowedClasses()
        });

        this.applyResultHtml(response.html, data.target);
        this.notify(this.translate('Content generated'), 'success');
        return true;
    }

    async rewriteContent(data) {
        this.restoreSelection();

        const hasSelection = this.getSelection().hasSelection();
        const scope = data.scope === 'selection' && hasSelection ? 'selection' : 'document';
        const contentHtml = scope === 'selection'
            ? this.getSelection().getSelectedHtml()
            : this.getDocumentHtml();

        const response = await this.request('rewrite', {
            prompt: data.prompt,
            content_html: this.truncateHtml(contentHtml),
            allowed_classes: this.getAllowedClasses()
        });

        this.applyResultHtml(response.html, scope === 'selection' ? 'replaceSelection' : 'replaceDocument');
        this.notify(this.translate('Content rewritten'), 'success');
        return true;
    }

    async generateImage(data) {
        const response = await this.request('image', {
            prompt: data.prompt,
            size: data.size
        });

        const src = this.getGeneratedImageSource(response.images);
        if (!src) {
            throw new Error('AI response did not include an image.');
        }

        await this.insertGeneratedImage(src, {
            alt: data.altText || data.prompt,
            align: data.align
        });

        this.notify(this.translate('Image generated'), 'success');
        return true;
    }

    getSelection() {
        return this.editor.selection;
    }

    getDocumentHtml() {
        return this.editor.contentArea?.getContent?.() || this.getContent() || '';
    }

    truncateHtml(html) {
        const maxLength = parseInt(this.options.maxContextLength, 10) || 0;
        if (!maxLength || typeof html !== 'string' || html.length <= maxLength) {
            return html;
        }
        return html.slice(0, maxLength);
    }

    getAllowedClasses(extra = []) {
        const merged = [
            ...(Array.isArray(this.options.allowedClasses) ? this.options.allowedClasses : []),
            ...(Array.isArray(extra) ? extra : [])
        ];

        return Array.from(new Set(
            merged
                .map(item => String(item || '').trim().toLowerCase())
                .filter(Boolean)
        ));
    }

    getApiBase() {
        if (this.options.endpoint) {
            return this.options.endpoint.replace(/\/+$/, '');
        }

        const globalBase = typeof WEB_URL !== 'undefined' && typeof WEB_URL === 'string'
            ? WEB_URL
            : (typeof window !== 'undefined' && typeof window.WEB_URL === 'string' ? window.WEB_URL : '/');

        const normalizedBase = globalBase.endsWith('/') ? globalBase : `${globalBase}/`;
        return `${normalizedBase}api/index/aiwriter`;
    }

    async request(action, payload) {
        const headers = {
            'Accept': 'application/json',
            'Content-Type': 'application/json'
        };
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        if (csrfToken) {
            headers['X-CSRF-Token'] = csrfToken;
        }

        const response = await fetch(`${this.getApiBase()}/${action}`, {
            method: 'POST',
            headers,
            credentials: 'same-origin',
            body: JSON.stringify(payload)
        });

        const raw = await response.json().catch(() => ({}));
        const result = this.unwrapResponse(raw);

        if (!response.ok || !result.success) {
            throw new Error(result.message || `{LNG_Request failed.} (${response.status})`);
        }

        return result.data || {};
    }

    unwrapResponse(payload) {
        if (payload && typeof payload === 'object') {
            if (typeof payload.success === 'boolean') {
                return payload;
            }
            if (payload.data && typeof payload.data === 'object' && typeof payload.data.success === 'boolean') {
                return payload.data;
            }
        }
        return {
            success: false,
            message: 'Unexpected API response format.',
            data: null
        };
    }

    applyResultHtml(html, target) {
        const content = this.prepareResultHtml(html);
        if (!content) {
            throw new Error('AI response was empty after cleanup.');
        }

        if (target === 'replaceDocument') {
            this.setContent(content);
            this.focusEditor();
            return;
        }

        this.restoreSelection();
        this.insertHtml(content);
        this.recordHistory(true);
        this.emit(EventBus.Events.CONTENT_CHANGE);
        this.focusEditor();
    }

    prepareResultHtml(html) {
        const rawHtml = typeof html === 'string' ? html.trim() : '';
        if (!rawHtml) {
            return '';
        }

        const cleanupPlugin = this.editor.getPlugin('contentCleanup');
        if (this.options.cleanupAiOutput && cleanupPlugin?.cleanHtmlFragment) {
            return cleanupPlugin.cleanHtmlFragment(rawHtml, {
                allowedClasses: this.getAllowedClasses(),
                removeHorizontalRules: true,
                removeIds: true,
                removeStyles: true,
                cleanClasses: true,
                cleanTables: true
            });
        }

        return this.editor.options?.sanitize !== false
            ? this.editor.sanitizeHtml(rawHtml)
            : rawHtml;
    }

    getGeneratedImageSource(images) {
        if (!Array.isArray(images) || images.length === 0) {
            return '';
        }

        const image = images[0];
        if (image && typeof image.url === 'string' && image.url.trim() !== '') {
            return image.url.trim();
        }
        if (image && typeof image.b64_json === 'string' && image.b64_json.trim() !== '') {
            const mimeType = typeof image.mime_type === 'string' && image.mime_type.trim() !== ''
                ? image.mime_type.trim()
                : 'image/png';
            return `data:${mimeType};base64,${image.b64_json.trim()}`;
        }

        return '';
    }

    async insertGeneratedImage(src, options = {}) {
        const imagePlugin = this.editor.getPlugin('image');
        if (imagePlugin?.insertImage) {
            await imagePlugin.insertImage({
                src,
                alt: options.alt || '',
                align: options.align || '',
                file: null,
                isEdit: false
            });
            this.emit(EventBus.Events.CONTENT_CHANGE);
            return;
        }

        this.restoreSelection();
        const image = document.createElement('img');
        image.src = src;
        image.alt = options.alt || '';
        this.insertHtml(image.outerHTML);
        this.recordHistory(true);
        this.emit(EventBus.Events.CONTENT_CHANGE);
        this.focusEditor();
    }

    destroy() {
        this.imageDialog?.destroy();
        this.generateDialog?.destroy();
        this.rewriteDialog?.destroy();
        super.destroy();
    }
}

export default AiWriterPlugin;