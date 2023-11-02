/**
 * GMultiSelect
 * Multiple Dropdown Select
 *
 * @filesource js/multiselect.js
 * @link https://www.kotchasan.com/
 * @copyright 2018 Goragod.com
 * @license https://www.kotchasan.com/license/
 */
(function() {
  "use strict";
  window.GMultiSelect = GClass.create();
  GMultiSelect.prototype = {
    initialize: function(selects, o) {
      var loading = true;
      this.prefix = "";
      this.selects = new Object();
      this.req = new GAjax();
      var self = this,
        _dochanged = function() {
          if (Object.isFunction(o.onchange)) {
            o.onchange.call(this);
          }
        },
        _doselected = function() {
          var a = false,
            temp = this;
          if (!loading && this.selectedIndex == 0) {
            loading = false;
            forEach(selects, function(item) {
              if (a) {
                var obj = self.selects[item];
                for (var i = obj.options.length - 1; i > 0; i--) {
                  obj.removeChild(obj.options[i]);
                }
              }
              a = !a && item == temp.id ? true : a;
            });
          } else {
            var qs = new Array();
            qs.push("srcItem=" + this.id.replace(self.prefix, ""));
            for (var prop in o) {
              if (prop != "action" && prop != "onchange") {
                qs.push(prop + "=" + o[prop]);
              }
            }
            for (var sel in self.selects) {
              var select = self.selects[sel];
              qs.push(select.id.replace(self.prefix, "") + "=" + encodeURIComponent(select.value));
            }
            temp.addClass("wait");
            self.req.send(o.action, qs.join("&"), function(xhr) {
              temp.removeClass("wait");
              var items = xhr.responseText.toJSON();
              if (items) {
                var sel = null;
                for (var prop in items) {
                  sel = $E(self.prefix + prop);
                  if (sel) {
                    if (sel.options) {
                      $G(sel).setOptions(items[prop], sel.value);
                    } else {
                      sel.value = items[prop];
                    }
                  }
                }
                if (sel) {
                  _dochanged.call(temp);
                }
              }
            });
          }
        };
      for (var prop in o) {
        this[prop] = o[prop];
      }
      var l = selects.length - 1;
      forEach(selects, function(item, index) {
        var select = $G(item);
        if (index < l || l == 0) {
          select.addEvent("change", _doselected);
        } else {
          select.addEvent("change", _dochanged);
        }
        self.selects[item] = select;
      });
      _doselected.call($E(selects[0]));
    }
  };
})();
