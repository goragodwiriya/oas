/**
 * Select+Checkbox
 *
 * @filesource js/select.js
 * @link https://www.kotchasan.com/
 * @copyright 2018 Goragod.com
 * @license https://www.kotchasan.com/license/
 */
(function() {
  "use strict";
  window.GSelect = GClass.create();
  GSelect.prototype = {
    initialize: function(elem) {
      if (elem.checkbox !== null) {
        var id = elem.id || elem.name.replace(/\[(.*?)\]/, '$1'),
          name = elem.name || elem.id,
          placeholder = elem.get('placeholder') || trans('Please select'),
          select = document.createElement('div'),
          display = document.createElement('div'),
          showing = 0,
          list = [],
          listindex = 0;
        select.id = id;
        select.className = 'dropdown input-select';
        select.tabIndex = 0;
        select.style.cursor = 'pointer';
        elem.parentNode.appendChild(select);
        display.className = 'input-display';
        select.appendChild(display);
        $G(display).id = id + '_display';
        var panel = new GDropdown($G(select), {
          autoHeight: true,
          id: id + '_gautocomplete',
          className: 'gautocomplete'
        });
        var dropdown = panel.getDropdown();
        dropdown.innerHTML = '';
        Object.defineProperty(select, 'disabled', {
          get: function() {
            return select.hasClass('disabled') ? true : false;
          },
          set: function(value) {
            if (value) {
              select.addClass('disabled');
            } else {
              select.removeClass('disabled');
            }
          },
        });
        Object.defineProperty(select, 'readOnly', {
          get: function() {
            return select.hasClass('readonly') ? true : false;
          },
          set: function(value) {
            if (value) {
              select.addClass('readonly');
            } else {
              select.removeClass('readonly');
            }
          },
        });
        Object.defineProperty(select, 'value', {
          get: function() {
            var results = [];
            forEach(select.getElementsByTagName('input'), function() {
              results.push(this.value);
            });
            return results;
          },
          set: function(value) {
            _setValue(value);
          }
        });
        Object.defineProperty(select, 'options', {
          set: function(value) {
            list = [];
            dropdown.innerHTML = '';
            listindex = 0;
            for (var key in value) {
              select.addItem(key, value[key], false);
            }
          },
        });
        if (elem.disabled) {
          select.disabled = true;
        }
        if (elem.get('readonly') != null) {
          select.readonly = true;
        }

        function _doChanged(values) {
          if (values.length == 0) {
            display.innerText = placeholder;
          } else if (values.length < 3) {
            var vs = [];
            forEach(values, function() {
              vs.push(this.innerText);
            });
            display.innerText = vs.join(',');
          } else {
            display.innerText = '+' + values.length + ' ' + trans('items');
          }
          window.setTimeout(function() {
            select.callEvent('change');
          });
        }

        function _itemMouseDown() {
          if (!select.hasClass('readonly')) {
            this.className = this.hasClass('icon-check') ? 'icon-uncheck' : 'icon-check';
          }
          if (showing > 0) {
            window.clearTimeout(showing);
          }
          showing = window.setTimeout(function() {
            showing = 0;
          }, 1000);
        }

        function _itemMouseMove() {
          _movehighlight(this.itemindex);
        }

        select.addItem = function(value, text, checked) {
          var item = document.createElement('label'),
            className = checked ? 'icon-check' : 'icon-uncheck';
          $G(item).itemindex = list.length;
          className += listindex == item.itemindex ? ' select' : '';
          item.className = className;
          item.value = value;
          item.changed = 0;
          list.push(item);
          item.innerHTML = text;
          item.addEvent("click", function() {
            if (item.changed == 0) {
              item.changed = window.setTimeout(function() {
                item.changed = 0;
                changed();
              }, 1);
            }
            select.focus();
          });
          item.addEvent("mousedown", _itemMouseDown);
          item.addEvent("mousemove", _itemMouseMove);
          dropdown.appendChild(item);
        };

        function _movehighlight(id) {
          listindex = Math.max(0, id);
          listindex = Math.min(list.length - 1, listindex);
          var selItem = null;
          forEach(list, function() {
            if (listindex == this.itemindex) {
              this.addClass('select');
              selItem = this;
            } else {
              this.removeClass('select');
            }
          });
          return selItem;
        }

        function _setValue(value) {
          var className,
            input,
            values = [];
          forEach(select.getElementsByTagName('input'), function() {
            select.removeChild(this);
          });
          forEach(dropdown.getElementsByTagName('label'), function() {
            if (value.indexOf(this.value) > -1 || value.indexOf(floatval(this.value)) > -1) {
              className = 'icon-check';
              input = document.createElement('input');
              input.type = 'hidden';
              input.name = name + '[]';
              input.value = this.value;
              select.appendChild(input);
              values.push(this);
            } else {
              className = 'icon-uncheck';
            }
            this.className = className + (listindex == this.itemindex ? ' select' : '');
          });
          _doChanged(values);
        }

        function changed() {
          var values = [];
          forEach(dropdown.getElementsByTagName('label'), function() {
            if (this.hasClass('icon-check')) {
              values.push(this.value);
            }
          });
          _setValue(values);
        }

        function _showitem(item) {
          if (item) {
            var top = item.getTop() - dropdown.getTop();
            var height = dropdown.getHeight();
            if (top < dropdown.scrollTop) {
              dropdown.scrollTop = top;
            } else if (top >= height) {
              dropdown.scrollTop = top - height + item.getHeight();
            }
          }
        }

        function _dokeydown(evt) {
          if (!select.hasClass('disabled')) {
            var cancelEvent = false,
              key = GEvent.keyCode(evt);
            if (key == 40) {
              _showitem(_movehighlight(listindex + 1));
              cancelEvent = true;
            } else if (key == 38) {
              _showitem(_movehighlight(listindex - 1));
              cancelEvent = true;
            } else if (key == 32 && !select.hasClass('readonly')) {
              list[listindex].className = list[listindex].hasClass('icon-check') ? 'icon-uncheck select' : 'icon-check select';
              changed();
              cancelEvent = true;
            }
            if (cancelEvent) {
              GEvent.stop(evt);
            }
          }
        }

        forEach(elem.getElementsByTagName('option'), function() {
          select.addItem(this.value, this.innerHTML, this.getAttribute('selected') !== null);
        });

        var bodyClick = function(e) {
          if (GEvent.element(e) != display && showing == 0) {
            _hide();
          }
        };

        function _show() {
          if (!select.hasClass('disabled')) {
            panel.show();
            $G(document.body).addEvent("click", bodyClick);
          }
        }

        function _hide() {
          panel.hide();
          $G(document.body).removeEvent("click", bodyClick);
        }

        select.addEvent("click", _show);
        select.addEvent("focus", _show);
        select.addEvent("keydown", _dokeydown);
        select.addEvent("blur", function() {
          if (showing == 0) {
            _hide();
          }
        });
        dropdown.addEvent("keydown", _dokeydown);
        elem.parentNode.removeChild(elem);
        changed();
      }
    }
  };
})();
