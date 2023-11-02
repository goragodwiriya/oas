/**
 * EditInPlace
 * Ajax in-place editor
 *
 * @filesource js/editinplace.js
 * @link https://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 */
(function() {
  "use strict";
  window.EditInPlace = GClass.create();
  EditInPlace.prototype = {
    initialize: function(e, o) {
      this.className = "editinplace";
      this.editing = function() {
        return (
          '<input type="text" value="' +
          (this.value ? this.value : this.innerHTML) +
          '" />'
        );
      };
      for (var p in o) {
        this[p] = o[p];
      }
      this.src = $G(e);
      this.src.style.cursor = "pointer";
      this.src.tabIndex = 0;
      this.src.addClass(this.className);
      this.src.addEvent("click", this.Edit.bind(this));
      var self = this;
      this.src.addEvent("keydown", function(e) {
        var key = GEvent.keyCode(e);
        if (key == 13 || key == 32) {
          self.Edit.call(self);
          GEvent.stop(e);
          return false;
        }
        return true;
      });
    },
    Edit: function() {
      var e = this.editing.call(this.src);
      if (e !== "" && e !== null) {
        e = e.toDOM().firstChild;
        this.src.parentNode.insertBefore(e, this.src);
        this.editor = $G(e);
        this.editor.addEvent("blur", this._saveEdit.bind(this));
        this.editor.addEvent("keypress", this._checkKey.bind(this));
        this.editor.addEvent("keydown", this._checkKey.bind(this));
        this.oldDisplay = this.src.style.display;
        this.src.style.display = "none";
        this.editor.focus();
        if (this.editor.select) {
          this.editor.select();
        }
      }
      return this;
    },
    select: function() {
      this.editor.select();
    },
    cancelEdit: function() {
      this.src.style.display = this.oldDisplay;
      this.editor.removeEvent("blur", this._saveEdit.bind(this));
      this.editor.removeEvent("keypress", this._checkKey.bind(this));
      this.editor.removeEvent("keydown", this._checkKey.bind(this));
      this.editor.remove();
      this.src.focus();
      return this;
    },
    _saveEdit: function() {
      var ret = true,
        v = this.editor.value ? this.editor.value : this.editor.innerHTML;
      if (Object.isFunction(this.onSave)) {
        ret = this.onSave.call(this.src, v, this.editor);
      } else {
        this.src.setValue(v);
      }
      if (ret) {
        this.cancelEdit();
      }
    },
    _checkKey: function(e) {
      var key = GEvent.keyCode(e);
      if (key == 27) {
        this.cancelEdit();
        GEvent.stop(e);
        return false;
      } else if (key == 13) {
        if (this.editor.tagName.toLowerCase() != "textarea") {
          this._saveEdit();
        }
        GEvent.stop(e);
        return false;
      }
      return true;
    }
  };
})();