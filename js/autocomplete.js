/**
 * GAutoComplete
 *
 * @filesource js/autocomplete.js
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */
(function () {
  'use strict';
  window.GAutoComplete = GClass.create();
  GAutoComplete.prototype = {
    initialize: function (id, o) {
      var options = {
        className: 'gautocomplete',
        itemClass: 'item',
        callBack: $K.emptyFunction,
        get: $K.emptyFunction,
        populate: $K.emptyFunction,
        onSuccess: $K.emptyFunction,
        onChanged: $K.emptyFunction,
        loadingClass: 'wait',
        url: false,
        interval: 300
      };
      for (var property in o) {
        options[property] = o[property];
      }
      var cancleEvent = false;
      var showing = false;
      var listindex = 0;
      var list = new Array();
      this.input = $G(id);
      this.text = this.input.value;
      var req = new GAjax();
      var self = this;
      if (!$E('gautocomplete_div')) {
        var div = document.createElement('div');
        document.body.appendChild(div);
        div.id = 'gautocomplete_div';
      }
      var display = $G('gautocomplete_div');
      display.className = options.className;
      display.style.left = '-100000px';
      display.style.position = 'absolute';
      display.style.display = 'block';
      display.style.zIndex = 9999;
      function _movehighlight(id) {
        listindex = Math.max(0, id);
        listindex = Math.min(list.length - 1, listindex);
        var selItem = null;
        forEach(list, function () {
          if (listindex == this.itemindex) {
            this.addClass('select');
            selItem = this;
          } else {
            this.removeClass('select');
          }
        });
        return selItem;
      }
      function onSelect() {
        if (showing) {
          _hide();
          try {
            options.callBack.call(this.datas);
            self.text = self.input.value;
          } catch (e) {
          }
        }
      }
      var _mouseclick = function () {
        onSelect.call(this);
        if (Object.isFunction(options.onSuccess)) {
          options.onSuccess.call(self.input);
        }
      };
      var _mousemove = function () {
        _movehighlight(this.itemindex);
      };
      function _populateitems(datas) {
        display.innerHTML = '';
        var f, i, r, p;
        list = new Array();
        for (i in datas) {
          r = options.populate.call(datas[i]);
          if (r && r != '') {
            p = r.toDOM();
            f = p.firstChild;
            $G(f).className = options.itemClass;
            f.datas = datas[i];
            f.addEvent('mousedown', _mouseclick);
            f.addEvent('mousemove', _mousemove);
            f.itemindex = list.length;
            list.push(f);
            display.appendChild(p);
          }
        }
        _movehighlight(0);
      }
      function _hide() {
        self.input.removeClass(options.loadingClass);
        display.style.left = '-100000px';
        showing = false;
      }
      var _search = function () {
        window.clearTimeout(self.timer);
        req.abort();
        if (self.text != self.input.value) {
          options.onChanged.call(self.input);
        }
        if (!cancleEvent && options.url) {
          var q = options.get.call(this);
          if (q && q != '') {
            self.input.addClass(options.loadingClass);
            self.timer = window.setTimeout(function () {
              req.send(options.url, q, function (xhr) {
                self.input.removeClass(options.loadingClass);
                if (xhr.responseText !== '') {
                  var datas = xhr.responseText.toJSON();
                  listindex = 0;
                  if (datas) {
                    _populateitems(datas);
                  } else {
                    display.setValue(xhr.responseText);
                  }
                  var vp = self.input.viewportOffset(),
                    dm = self.input.getDimensions(),
                    cw = document.viewport.getWidth();
                  display.style.width = dm.width + 'px';
                  display.style.left = Math.max(0, (vp.left + dm.width > cw ? cw - dm.width : vp.left)) + 'px';
                  var h = display.getDimensions().height;
                  if ((vp.top + dm.height + 5 + h) >= (document.viewport.getHeight() + document.viewport.getscrollTop())) {
                    display.style.top = (vp.top - h - 5) + 'px';
                  } else {
                    display.style.top = (vp.top + dm.height + 5) + 'px';
                  }
                  showing = true;
                } else {
                  _hide();
                }
              });
            }, options.interval);
          } else {
            _hide();
          }
        }
        cancleEvent = false;
      };
      function _showitem(item) {
        if (item) {
          var top = item.getTop() - display.getTop();
          var height = display.getHeight();
          if (top < display.scrollTop) {
            display.scrollTop = top;
          } else if (top > height) {
            display.scrollTop = top - height + item.getHeight();
          }
        }
      }
      function _dokeydown(evt) {
        var key = GEvent.keyCode(evt);
        if (key == 40) {
          _showitem(_movehighlight(listindex + 1));
          cancleEvent = true;
        } else if (key == 38) {
          _showitem(_movehighlight(listindex - 1));
          cancleEvent = true;
        } else if (key == 13) {
          cancleEvent = true;
          this.removeClass(options.loadingClass);
          forEach(list, function () {
            if (this.itemindex == listindex) {
              onSelect.call(this);
            }
          });
          if (Object.isFunction(options.onSuccess)) {
            options.onSuccess.call(self.input);
          }
        } else if (key == 32) {
          if (this.value == '') {
            _search();
            cancleEvent = true;
          }
        }
        if (cancleEvent) {
          GEvent.stop(evt);
        }
      }
      self.input.addEvent('click', _search);
      self.input.addEvent('keyup', _search);
      self.input.addEvent('keydown', _dokeydown);
      self.input.addEvent('blur', function () {
        _hide();
      });
      $G(document.body).addEvent('click', function () {
        _hide();
      });
    },
    setText: function (value) {
      this.input.value = value;
      this.text = value;
    },
    valid: function () {
      this.input.valid();
      this.text = this.input.value;
    },
    invalid: function () {
      this.input.invalid();
      this.text = this.input.value;
    },
    reset: function () {
      this.input.reset();
      this.text = this.input.value;
    },
  };
}());
function initAutoComplete(id, model, displayFields, icon, options) {
  var obj,
    df = displayFields.split(',');
  function doGetQuery() {
    var q = null,
      value = $E(id).value;
    if (value != '') {
      q = (id + '=' + encodeURIComponent(value));
    }
    return q;
  }
  function doCallBack() {
    for (var prop in this) {
      $G(prop).setValue(this[prop]);
    }
    obj.valid();
  }
  function doPopulate() {
    var datas = new Array();
    for (var i in df) {
      datas.push(this[df[i]]);
    }
    var patt = new RegExp('(' + $E(id).value.replace(/[|\\{}()[\]^$+*?.]/g, '\\$&') + ')', 'gi');
    return '<p><span class="icon-' + (icon || 'search') + '">' + datas.join(' ').unentityify().replace(patt, '<em>$1</em>') + '</span></p>';
  }
  var o = {
    get: doGetQuery,
    populate: doPopulate,
    callBack: doCallBack,
    url: 'index.php/' + model
  };
  for (var prop in options) {
    o[prop] = options[prop];
  }
  obj = new GAutoComplete(id, o);
  return obj;
}
