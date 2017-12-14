/**
 * GTable
 * Javascript data table for Kotchasan Framework
 *
 * @filesource js/table.js
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */
(function () {
  'use strict';
  window.GTable = GClass.create();
  GTable.prototype = {
    initialize: function (id, o) {
      this.options = {
        action: null,
        actionCallback: null,
        actionConfirm: null,
        onBeforeDelete: null,
        onAddRow: null,
        onInitRow: null,
        pmButton: false,
        dragColumn: -1
      };
      for (var prop in o) {
        this.options[prop] = o[prop];
      }
      this.table = $E(id);
      this.search = o['search'] || '';
      this.sort = o['sort'] || null;
      this.page = o['page'] || 1;
      if (this.options.onAddRow) {
        this.options.onAddRow = window[this.options.onAddRow];
        if (!Object.isFunction(this.options.onAddRow)) {
          this.options.onAddRow = null;
        }
      }
      if (this.options.onBeforeDelete) {
        this.options.onBeforeDelete = window[this.options.onBeforeDelete];
        if (!Object.isFunction(this.options.onBeforeDelete)) {
          this.options.onBeforeDelete = null;
        }
      }
      if (this.options.onInitRow) {
        this.options.onInitRow = window[this.options.onInitRow];
        if (!Object.isFunction(this.options.onInitRow)) {
          this.options.onInitRow = null;
        }
      }
      var hs,
        sort_patt = /sort_(none|asc|desc)\s(col_([\w]+))(|\s.*)$/,
        action_patt = /button[\s][a-z]+[\s]action/,
        temp = this;
      var _doSort = function (e) {
        if (hs = sort_patt.exec(this.className)) {
          var sort = new Array();
          if (GEvent.isCtrlKey(e)) {
            var patt = new RegExp(hs[3] + '[\\s](asc|desc|none)');
            forEach(temp.sort.split(','), function () {
              if (!patt.test(this)) {
                sort.push(this);
              }
            });
          }
          if (hs[1] == 'none') {
            sort.push(hs[3] + '%20asc');
          } else if (hs[1] == 'asc') {
            sort.push(hs[3] + '%20desc');
          } else {
            sort.push(hs[3] + '%20none');
          }
          temp.sort = sort.join(',');
          window.location = temp.redirect();
        }
      };
      if (this.table) {
        forEach($G(this.table).elems('th'), function () {
          if (sort_patt.test(this.className)) {
            callClick(this, _doSort);
          }
        });
        this.initTR(this.table);
        forEach(this.table.elems('tbody'), function () {
          temp.initTBODY(this, null);
        });
        var doAction = function () {
          var action = '',
            cs = new Array(),
            chk = /check_[0-9]+/;
          forEach(temp.table.elems('a'), function () {
            if (chk.test(this.id) && $G(this).hasClass('icon-check')) {
              cs.push(this.id.replace('check_', ''));
            }
          });
          if (cs.length == 0) {
            alert(trans('Please select at least one item'));
          } else {
            cs = cs.join(',');
            var f = this.get('for');
            var fn = window[temp.options.actionConfirm];
            var t = $G(f).getText();
            t = t ? t.strip_tags() : null;
            if (Object.isFunction(fn)) {
              action = fn(t, $E(f).value, cs);
            } else {
              if (confirm(trans('You want to XXX the selected items ?').replace(/XXX/, t))) {
                action = 'module=' + f + '&action=' + $E(f).value + '&id=' + cs;
              }
            }
            if (action != '') {
              temp.callAction(this, action);
            }
          }
        };
        forEach(this.table.elems('label'), function () {
          if (action_patt.test(this.className)) {
            callClick(this, doAction);
          }
        });
        if (this.options.dragColumn > -1) {
          new GSortTable(this.table, {
            'endDrag': function () {
              var trs = new Array();
              forEach(temp.table.elems('tr'), function () {
                if (this.id) {
                  trs.push(this.id.replace(id + '_', ''));
                }
              });
              if (trs.length > 1) {
                temp.callAction(this, 'action=move&data=' + trs.join(','));
              }
            }
          });
        }
      }
      forEach(this.table.elems('button'), function () {
        if (this.className == 'clear_search') {
          temp.clear_search = this;
          temp.input_search = this.parentNode.firstChild.firstChild;
          callClick(this, function () {
            temp.input_search.value = "";
          });
        } else if (/^([a-z_\-]+)_([0-9]+)(_([0-9]+))?$/.test(this.id)) {
          callClick(this, function () {
            temp._doButton(this);
          });
        }
      });
      var doSearchChanged = function () {
        if (temp.input_search.value == '') {
          temp.clear_search.style.display = 'none';
        } else {
          temp.clear_search.style.display = 'block';
        }
      };
      if (temp.input_search) {
        $G(temp.input_search).addEvent('change', doSearchChanged);
        doSearchChanged.call(temp);
      }
    },
    callAction: function (el, action) {
      var hs = this.options.action.split('?');
      if (hs[1]) {
        action = hs[1] + '&' + action;
      }
      action += '&src=' + this.table.id;
      var temp = this;
      if (el.hasClass('button')) {
        el.addClass('wait');
      } else {
        var _class = el.className;
        el.className = 'icon-loading';
      }
      send(hs[0], action, function (xhr) {
        if (el.hasClass('button')) {
          el.removeClass('wait');
        } else {
          el.className = _class;
        }
        if (temp.options.actionCallback) {
          var fn = window[temp.options.actionCallback];
          if (Object.isFunction(fn)) {
            fn(xhr);
          }
        } else if (xhr.responseText != '') {
          alert(xhr.responseText);
        } else {
          window.location.reload();
        }
      });
    },
    _doButton: function (input) {
      var action = '',
        patt = /^([a-z_\-]+)_([0-9]+)(_([0-9]+))?$/,
        q = input.get('data-confirm');
      if (this.options.actionConfirm) {
        var fn = window[this.options.actionConfirm],
          hs = patt.exec(input.id);
        if (hs && Object.isFunction(fn)) {
          var t = input.getText();
          t = t ? t.strip_tags() : null;
          action = fn(t, hs[1], hs[2], hs[4]);
        } else {
          action = 'action=' + input.id;
        }
      } else if (!q || confirm(q)) {
        hs = patt.exec(input.id);
        if (hs) {
          if (hs[1] == 'delete') {
            if (confirm(trans('You want to XXX ?').replace(/XXX/, trans('delete')))) {
              action = 'action=delete&id=' + hs[2];
            }
          } else if (hs[4]) {
            action = 'action=' + hs[1] + '_' + hs[2] + '&id=' + hs[4];
          } else {
            action = 'action=' + hs[1] + '&id=' + hs[2];
          }
        } else {
          action = 'action=' + input.id;
        }
      }
      if (action != '') {
        this.callAction(input, action);
      }
    },
    initTBODY: function (tbody, tr) {
      var row = 0,
        temp = this;
      forEach($G(tbody).elems('tr'), function () {
        if (temp.options.pmButton) {
          this.id = temp.table.id + '_' + row;
          forEach($G(this).elems('input'), function () {
            this.id = this.name.replace(/([\[\]_]+)/g, '_') + row;
          });
        }
        if (tr === null || tr === this) {
          if (temp.options.onInitRow) {
            temp.options.onInitRow.call(temp, this, row);
          }
          if (temp.options.action) {
            var move = /(check|move)_([0-9]+)/;
            forEach($G(this).elems('a'), function () {
              var id = this.id;
              if (id && !move.test(id)) {
                callClick(this, function () {
                  temp._doButton(this);
                });
              }
            });
          }
        }
        row++;
      });
    },
    initTR: function (el) {
      var hs,
        a_patt = /(delete|icon)[_\-](plus|minus|[0-9]+)/,
        check_patt = /check_([0-9]+)/,
        temp = this;
      var aClick = function () {
        var c = this.className;
        if (c == 'icon-plus') {
          var tr = $G(this.parentNode.parentNode.parentNode);
          var tbody = tr.parentNode;
          var ntr = tr.copy(false);
          tr.after(ntr);
          temp.initTR(ntr);
          if (temp.options.onAddRow) {
            ret = temp.options.onAddRow.call(temp, ntr);
          }
          temp.initTBODY(tbody, ntr);
          ntr.highlight();
          ntr = ntr.elems('input')[0];
          if (ntr) {
            ntr.focus();
            ntr.select();
          }
        } else if (c == 'icon-minus') {
          var tr = $G(this.parentNode.parentNode.parentNode);
          var tbody = $G(tr.parentNode);
          var ret = true;
          if (temp.options.onBeforeDelete) {
            ret = temp.options.onBeforeDelete.call(temp, tr);
          } else if (tbody.elems('tr').length > 1) {
            ret = confirm(trans('You want to XXX ?').replace(/XXX/, trans('delete')));
          }
          if (ret) {
            if (tbody.elems('tr').length > 1) {
              tr.remove();
              temp.initTBODY(tbody, false);
            } else {
              if (temp.options.onAddRow) {
                temp.options.onAddRow.call(temp, tr);
              }
            }
          }
        } else if (hs = a_patt.exec(c)) {
          var action = '';
          if (hs[1] == 'delete' && confirm(trans('You want to XXX ?').replace(/XXX/, trans('delete')))) {
            action = 'action=delete&id=' + hs[2];
          }
          if (action != '' && temp.options.action) {
            send(temp.options.action, action, function (xhr) {
              var ds = xhr.responseText.toJSON();
              if (ds) {
                if (ds.alert && ds.alert != '') {
                  alert(ds.alert);
                } else if (ds.action) {
                  if (ds.action == 'delete') {
                    var tr = $G(temp.table.id + '_' + ds.id);
                    var tbody = tr.parentNode;
                    tr.remove();
                    temp.initTBODY(tbody, tr);
                  }
                }
              } else if (xhr.responseText != '') {
                alert(xhr.responseText);
              }
            }, this);
          }
        }
      };
      var saClick = function () {
        this.focus();
        var chk = this.hasClass('icon-check');
        forEach(el.elems('a'), function () {
          if (check_patt.test(this.id)) {
            this.className = chk ? 'icon-uncheck' : 'icon-check';
            this.title = chk ? trans('check') : trans('uncheck');
          } else if (this.hasClass('checkall')) {
            this.className = chk ? 'checkall icon-uncheck' : 'checkall icon-check';
            this.title = chk ? trans('select all') : trans('select none');
          }
        });
        return false;
      };
      var sClick = function () {
        this.focus();
        var chk = $G(this).hasClass('icon-check');
        this.className = chk ? 'icon-uncheck' : 'icon-check';
        this.title = chk ? trans('check') : trans('uncheck');
        forEach(el.elems('a'), function () {
          if (this.hasClass('checkall')) {
            this.className = 'checkall icon-uncheck';
            this.title = trans('select all');
          }
        });
        return false;
      };
      forEach($G(el).elems('a'), function () {
        if (a_patt.test(this.className)) {
          callClick(this, aClick);
        } else if ($G(this).hasClass('checkall')) {
          this.title = trans('select all');
          callClick(this, saClick);
        } else if (check_patt.test(this.id)) {
          this.title = trans('check');
          callClick(this, sClick);
        }
      });
    },
    setSort: function (sort, patt) {
      var hs;
      forEach(this.table.elems('th'), function () {
        hs = patt.exec(this.className);
        if (hs) {
          if (sort == hs[2]) {
            this.className = this.className.replace('sort_' + hs[1], 'sort_' + (hs[1] == 'asc' ? 'desc' : 'asc'));
          } else {
            this.className = this.className.replace('sort_' + hs[1], 'sort_none');
          }
        }
      });
    },
    redirect: function () {
      var hs,
        url = '',
        patt = /^(.*)=(.*)$/,
        urls = new Object(),
        u = window.location.href,
        us2 = u.split('#'),
        us1 = us2[0].split('?');
      if (us2.length == 2) {
        url = us2[1];
      } else if (us1.length == 2) {
        url = us1[1];
      }
      if (url != '') {
        forEach(url.split('&'), function () {
          if (hs = patt.exec(this)) {
            hs[1] = hs[1].toLowerCase();
            hs[2] = hs[2].toLowerCase();
            if (hs[1] != 'page' && hs[1] != 'sort' && hs[1] != 'search' && !(hs[1] == 'action' && (hs[2] == 'login' || hs[2] == 'logout'))) {
              urls[hs[1]] = this;
            }
          } else {
            urls[this] = this;
          }
        });
      }
      var us = Object.toArray(urls);
      us.push('page=' + this.page);
      if (this.sort) {
        us.push('sort=' + this.sort);
      }
      if (this.search) {
        us.push('search=' + encodeURIComponent(this.search));
      }
      if (us2.length == 2) {
        u = us2[0];
        if (us.length > 0) {
          u += '#' + us.join('&');
        }
      } else {
        u = us1[0];
        if (us.length > 0) {
          u += '?' + us.join('&');
        }
      }
      return u;
    }
  };
}());
