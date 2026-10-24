/**
 * PrintPlugin - Print editor content
 * Opens a print-friendly window with only the editor content.
 *
 * Usage: add 'print' to the toolbar items array.
 *
 * @author Goragod Wiriya
 * @version 1.0
 */
import PluginBase from '../PluginBase.js';
import EventBus from '../../core/EventBus.js';

class PrintPlugin extends PluginBase {
  static pluginName = 'print';

  init() {
    super.init();

    // Register command
    this.registerCommand('print', {
      execute: () => this.print()
    });

    // Listen for toolbar button click
    this.subscribe(EventBus.Events.TOOLBAR_BUTTON_CLICK, (event) => {
      if (event.id === 'print') {
        this.print();
      }
    });
  }

  /**
   * Open a print window with the current editor content.
   */
  print() {
    const content = this.getContent();
    if (!content) {
      this.notify(this.translate('Nothing to print'), 'warning');
      return;
    }

    const win = window.open('', '_blank', 'width=800,height=600');
    if (!win) {
      this.notify(this.translate('Please allow pop-ups to print'), 'warning');
      return;
    }

    // Collect only RTE/theme-related stylesheets — skip admin or internal CSS
    const rtePatterns = ['RichTextEditor', 'filebrowser', 'fonts', 'variables', 'theme'];
    const styleLinks = Array.from(document.querySelectorAll('link[rel="stylesheet"]'))
      .filter(l => rtePatterns.some(p => l.href.includes(p)))
      .map(l => `<link rel="stylesheet" href="${l.href}">`)
      .join('\n');

    win.document.write(`<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>${this.translate('Print')}</title>
  ${styleLinks}
  <style>
    body { margin: 2cm; font-family: var(--font-family-base, inherit); }
    @media print { body { margin: 1cm; } }
  </style>
</head>
<body class="rte-content">
${content}
</body>
</html>`);

    win.document.close();

    // Wait for resources to load before printing
    win.addEventListener('load', () => {
      win.focus();
      win.print();
    });
  }
}

export default PrintPlugin;
