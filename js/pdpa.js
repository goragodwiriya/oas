/**
 * PDPA
 *
 * @filesource js/pdpa.js
 * @link https://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 */
(function() {
  "use strict";
  window.PDPA = GClass.create();
  PDPA.prototype = {
    initialize: function() {
      $G(window).Ready(function() {
        send(WEB_URL + 'index.php/index/model/consent/execute', null, function(xhr) {
          if (xhr.responseText != '') {
            var div = document.createElement('div'),
              innerDiv = document.createElement('div'),
              footer = document.createElement('div'),
              accept = document.createElement('a'),
              settings = document.createElement('a');
            div.className = 'pdpa_consent';
            footer.className = 'pdpa_consent_footer';
            div.appendChild(innerDiv);
            div.appendChild(footer);
            document.body.appendChild(div);
            innerDiv.innerHTML = xhr.responseText;
            settings.innerHTML = trans('Cookies settings');
            settings.id = 'pdpa_consent_settings';
            footer.appendChild(settings);
            accept.innerHTML = trans('Accept all');
            accept.className = 'button orange accept';
            accept.id = 'pdpa_consent_accept';
            footer.appendChild(accept);
            var doClick = function() {
              div.style.opacity = 0;
              send(WEB_URL + 'index.php/index/model/consent/action', 'action=' + this.id.replace('pdpa_consent_', ''), doFormSubmit, this);
              window.setTimeout(function() { document.body.removeChild(div); }, 300);
            };
            callClick(accept, doClick);
            callClick(settings, doClick);
          }
        });
      });
    }
  };
})();
