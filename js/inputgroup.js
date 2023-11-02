/**
 * GInputGroup
 * Javascript range input
 *
 * @filesource js/inputgroup.js
 * @link https://www.kotchasan.com/
 * @copyright 2018 Goragod.com
 * @license https://www.kotchasan.com/license/
 */
(function() {
  "use strict";
  window.GInputGroup = GClass.create();
  GInputGroup.prototype = {
    initialize: function(id) {
      this.input = $G(id);
      this.id = this.input.id;
      this.ul = this.input.parentNode.parentNode;
      var self = this;
      forEach(this.ul.getElementsByTagName("button"), function() {
        callClick(this, function() {
          self.removeItem(this);
        });
      });
      this.input.addEvent("keydown", function(e) {
        if (GEvent.keyCode(e) == 8 && this.value == "") {
          if (self.input.readOnly == false && self.input.disabled == false) {
            var btns = self.ul.getElementsByTagName("button");
            if (btns.length > 0) {
              self.ul.removeChild(btns[btns.length - 1].parentNode);
            }
            GEvent.stop(e);
          }
        }
      });
      this.input.addEvent("keypress", function(e) {
        if (GEvent.keyCode(e) == 13) {
          self.addItem(this.value, this.value);
          this.value = "";
          GEvent.stop(e);
        }
      });
      $G(this.ul).addEvent("click", function() {
        self.input.focus();
      });
      if ($E(this.input.list)) {
        new GDatalist(this.input.id, function() {
          if (this.value != '' && this.selectedIndex !== null) {
            self.addItem(this.value, this.selectedIndex);
          }
          this.reset();
        });
      }
      this.input.inputGroup = this;
    },
    addItem: function(title, value) {
      var li = document.createElement("li"),
        span = document.createElement("span"),
        button = document.createElement("button"),
        hidden = document.createElement("input"),
        self = this;
      span.appendChild(document.createTextNode(title));
      li.appendChild(span);
      button.type = "button";
      button.innerHTML = "x";
      li.appendChild(button);
      hidden.type = "hidden";
      hidden.name = this.id + "[]";
      hidden.value = value;
      li.appendChild(hidden);
      li.id = this.id + '_item_' + value;
      this.ul.insertBefore(li, this.input.parentNode);
      callClick(button, function() {
        self.removeItem(this);
      });
    },
    removeItem: function(button) {
      if (this.input.readOnly == false && this.input.disabled == false) {
        this.ul.removeChild(button.parentNode);
      }
    },
    values: function() {
      var ret = [];
      forEach(this.ul.getElementsByTagName("input"), function() {
        if (this.type == 'hidden') {
          ret.push(this.value);
        }
      });
      return ret;
    },
    doAutocompleteGet: function() {
      return this.id + '=' + this.value;
    },
    doAutocompletePopulate: function(input) {
      if ($E(input.id)) {
        var datas = new Array();
        for (var prop in this) {
          if (prop != 'id' && this[prop] != null && this[prop] != '') {
            datas.push(this[prop]);
          }
        }
        var row = datas.join(' ').unentityify();
        forEach(input.value.replace(/[\s]+/, " ").split(" "), function() {
          if (this.length > 0) {
            var patt = new RegExp("(" + this.preg_quote() + ")", "gi");
            row = row.replace(patt, "<em>$1</em>");
          }
        });
        return '<p><span class="icon-search">' + row + "</span></p>";
      }
    },
    doAutocompleteCallback: function(input) {
      input.inputGroup.addItem(this[input.id], this.id);
      input.value = '';
    }
  };
})();
