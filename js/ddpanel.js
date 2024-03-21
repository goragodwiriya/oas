/**
 * GDPanel
 * Javascript dropdown panel
 *
 * @filesource js/gdpanel.js
 * @link https://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 */
(function() {
  "use strict";
  var GDPanels = [];
  var gdpanels_len = 0;
  window.GDPanel = GClass.create();
  GDPanel.prototype = {
    initialize: function(a, div, prefix) {
      this.prefix = prefix || "gdpanel";
      var self = this;
      $E(div).className = this.prefix + " " + this.prefix + gdpanels_len;
      $E(a).className = this.prefix + "-arrow " + this.prefix + gdpanels_len;
      gdpanels_len++;
      GDPanels[a] = div;
      callClick(a, function() {
        self.show(this);
        return false;
      });

      function _isPanel(src) {
        var c,
          tag = src.tagName.toLowerCase();
        var test = self.prefix + " gcalendar gddcolor " + self.prefix + "-arrow";
        while (src && src != document.body) {
          c = $G(src).hasClass(test);
          if (c) {
            return c == self.prefix + "-arrow" ||
              c == "gcalendar" ||
              c == "gddcolor" ||
              tag == "input" ||
              tag == "select" ||
              tag == "textarea" ||
              tag == "label" ||
              tag == "button" ?
              src :
              null;
          } else {
            src = src.parentNode;
          }
        }
        return null;
      }
      $G(document.body).addEvent("click", function(e) {
        if (_isPanel(GEvent.element(e)) === null) {
          self.show(null);
        }
      });
    },
    show: function(src) {
      var c = "",
        a,
        div;
      if (src) {
        c = src.className.replace(this.prefix + "-arrow ", this.prefix + " ");
      }
      for (a in GDPanels) {
        div = $E(GDPanels[a]);
        if (div) {
          if (div.className == c) {
            $G(a).addClass("hover");
            $G(div).addClass("show");
          } else {
            $G(a).removeClass("hover");
            $G(div).removeClass("show");
          }
        }
      }
    },
    hide: function() {
      this.show(null);
    }
  };
})();