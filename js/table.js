/**
 * GTable
 * Javascript data table for Kotchasan Framework
 *
 * @filesource js/table.js
 * @link https://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 */
(function() {
  "use strict";
  window.GTable = GClass.create();
  GTable.prototype = {
    initialize: function(id, o) {
      this.options = {
        url: null,
        params: [],
        cols: [],
        action: null,
        actionCallback: null,
        actionConfirm: null,
        onBeforeDelete: null,
        onDelete: null,
        onAddRow: null,
        onInitRow: null,
        onChanged: null,
        pmButton: false,
        dragColumn: -1
      };
      for (var prop in o) {
        if (prop == "debug" && o.debug != "") {
          console.log(o.debug);
        } else {
          this.options[prop] = o[prop];
        }
      }
      this.table = $E(id);
      this.search = o["search"] || "";
      this.sort = o["sort"] || null;
      this.page = o["page"] || 1;
      this.submit = null;
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
      if (this.options.onDelete) {
        this.options.onDelete = window[this.options.onDelete];
        if (!Object.isFunction(this.options.onDelete)) {
          this.options.onDelete = null;
        }
      }
      if (this.options.onInitRow) {
        this.options.onInitRow = window[this.options.onInitRow];
        if (!Object.isFunction(this.options.onInitRow)) {
          this.options.onInitRow = null;
        }
      }
      if (this.options.onChanged) {
        this.options.onChanged = window[this.options.onChanged];
        if (!Object.isFunction(this.options.onChanged)) {
          this.options.onChanged = null;
        }
      }

      var hs,
        sort_patt = /sort_(none|asc|desc)\s(col_([\w]+))(|\s.*)$/,
        action_patt = /button[\s][a-z]+[\s]action/,
        temp = this;

      function _send() {
        var params = [],
          sort = [];
        if (temp.options.params) {
          for (var key in temp.options.params) {
            params.push(key + '=' + encodeURIComponent(temp.options.params[key]));
          }
        }
        forEach($G(temp.table).elems("th"), function() {
          hs = sort_patt.exec(this.className);
          if (hs) {
            if (hs[1] == "asc") {
              sort.push(hs[3] + "%20asc");
            } else if (hs[1] == "desc") {
              sort.push(hs[3] + "%20desc");
            }
          }
        });
        if (sort.length > 0) {
          params.push('sort=' + sort.join(','));
        }
        send(temp.options.url, params.join('&'), function(xhr) {
          var ds = xhr.responseText.toJSON();
          if (ds) {
            var td,
              tr,
              tbody,
              tbodies = temp.table.getElementsByTagName('tbody');
            if (tbodies.length == 0) {
              tbody = document.createElement('tbody');
              temp.table.getElementsByTagName('table')[0].appendChild(tbody);
            } else {
              tbody = tbodies[0];
              tbody.innerHTML = '';
            }
            for (var row in ds) {
              tr = document.createElement('tr');
              for (var item in ds[row]) {
                td = document.createElement('td');
                if (temp.options.cols[item]) {
                  for (var el in temp.options.cols[item]) {
                    if (el == 'class') {
                      td.className = temp.options.cols[item][el];
                    }
                  }
                }
                td.innerHTML = ds[row][item];
                tr.appendChild(td);
              }
              tbody.appendChild(tr);
            }
            temp.initTBODY(tbody, null);
          }
          if (temp.options.onChanged) {
            temp.options.onChanged.call(temp, tbody, ds);
          }
        }, this);
      }
      var _doSort = function(e) {
        if ((hs = sort_patt.exec(this.className))) {
          var sort = [];
          if (GEvent.isCtrlKey(e)) {
            var patt = new RegExp(hs[3] + "[\\s](asc|desc|none)");
            if (temp.sort) {
              forEach(temp.sort.split(","), function() {
                if (!patt.test(this)) {
                  sort.push(this);
                }
              });
            }
          } else {
            forEach($G(temp.table).elems("th"), function() {
              var ds = sort_patt.exec(this.className);
              if (ds) {
                this.className = 'sort_none col_' + ds[3] + (ds[4] ? ds[4] : '');
              }
            });
          }
          if (hs[1] == "none") {
            this.className = 'sort_asc col_' + hs[3] + (hs[4] ? hs[4] : '');
            sort.push(hs[3] + "%20asc");
          } else if (hs[1] == "asc") {
            this.className = 'sort_desc col_' + hs[3] + (hs[4] ? hs[4] : '');
            sort.push(hs[3] + "%20desc");
          } else {
            this.className = 'sort_none col_' + hs[3] + (hs[4] ? hs[4] : '');
            sort.push(hs[3] + "%20none");
          }
          if (temp.options.url) {
            _send();
          } else {
            temp.sort = sort.join(",");
            window.location = temp.redirect();
          }
        }
      };
      var doAction = function() {
        var action = "",
          cs = temp.getCheck();
        if (cs.length == 0) {
          alert(trans("Please select at least one item").replace(/XXX/, trans('Checkbox')));
        } else {
          cs = cs.join(",");
          var t,
            f = this.get("for"),
            fn = window[temp.options.actionConfirm];
          if ($E(f).type.toLowerCase() == 'text') {
            t = this.innerText;
          } else {
            t = $G(f).getText();
          }
          t = t ? t.strip_tags() : null;
          if (Object.isFunction(fn)) {
            action = fn(t, $E(f).value, cs);
          } else {
            if (confirm(trans("You want to XXX the selected items ?").replace(/XXX/, t))) {
              action = "module=" + f + "&action=" + $E(f).value + "&id=" + cs;
            }
          }
          if (action != "") {
            temp.callAction(this, action);
          }
        }
      };
      var doSearchChanged = function() {
        if (temp.input_search.value == "") {
          temp.input_search.parentNode.parentNode.className = 'search';
        } else {
          temp.input_search.parentNode.parentNode.className = 'search with_text';
        }
      };
      if (this.table) {
        if (this.options.url) {
          _send();
        }
        forEach($G(this.table).elems("th"), function() {
          if (sort_patt.test(this.className)) {
            callClick(this, _doSort);
          }
        });
        this.initTR(this.table);
        forEach(this.table.elems("tbody"), function() {
          temp.initTBODY(this, null);
        });
        forEach(this.table.elems("label"), function() {
          if (action_patt.test(this.className)) {
            callClick(this, doAction);
          }
        });
        if (this.options.dragColumn > -1) {
          new GDragDrop(this.table, {
            dragClass: "icon-move",
            endDrag: function() {
              var trs = new Array();
              forEach(temp.table.elems("tr"), function() {
                if (this.id) {
                  trs.push(this.id.replace(id + "_", ""));
                }
              });
              if (trs.length > 1) {
                temp.callAction(this, "action=move&data=" + trs.join(","));
              }
            }
          });
        }
        forEach(this.table.elems("button"), function() {
          if (this.className == "clear_search") {
            temp.clear_search = this;
            temp.input_search = this.parentNode.firstChild.firstChild;
            callClick(this, function() {
              temp.input_search.value = "";
              if (temp.submit) {
                temp.submit.click();
              }
            });
          } else if (this.type == "submit") {
            temp.submit = this;
          } else if (this.id != "") {
            callClick(this, function() {
              temp._doButton(this);
            });
          }
        });
        if (this.options.action) {
          window.setTimeout(function() {
            if ($E(temp.table)) {
              forEach(temp.table.elems("tbody"), function() {
                forEach(
                  this.querySelectorAll("select,input,textarea"),
                  function() {
                    if (this.id != "") {
                      $G(this).addEvent("change", function() {
                        temp._doButton(this);
                      });
                    }
                  }
                );
              });
            }
          }, 1000);
        }
        if (temp.input_search) {
          $G(temp.input_search).addEvent("change", doSearchChanged);
          doSearchChanged.call(temp);
        }
        if (typeof loader !== "undefined") {
          forEach(this.table.querySelectorAll("form.table_nav"), function() {
            this.onsubmit = function() {
              var urls = this.action.split("?"),
                obj = new Object();
              if (urls[1]) {
                forEach(urls[1].split("&"), function() {
                  var hs = this.split("=");
                  if (hs.length == 2 && hs[1] != "") {
                    obj[hs[0]] = hs[1];
                  }
                });
                forEach(this.querySelectorAll("input,select"), function() {
                  obj[this.name] = this.value;
                });
                var q = new Array();
                for (var prop in obj) {
                  if (prop == "search") {
                    q.push(prop + "=" + encodeURIComponent(obj[prop]));
                  } else if (prop != "" && prop != "time") {
                    q.push(prop + "=" + obj[prop]);
                  }
                }
                q.push("time=" + new Date().getTime());
                loader.setParams(q.join("&"));
              }
              return false;
            };
          });
        }
      }
    },
    getCheck: function() {
      var cs = new Array(),
        chk = /check_[0-9]+/;
      forEach(this.table.elems("a"), function() {
        if (chk.test(this.id) && $G(this).hasClass("icon-check")) {
          cs.push(this.id.replace("check_", ""));
        }
      });
      return cs;
    },
    callAction: function(el, action) {
      var hs = this.options.action.split("?");
      if (hs[1]) {
        action = hs[1] + "&" + action;
      }
      action += "&src=" + this.table.id;
      if (el.value) {
        action += "&value=" + encodeURIComponent(el.value);
      }
      var temp = this;
      el.addClass("wait");
      send(hs[0], action, function(xhr) {
        el.removeClass("wait");
        if (temp.options.actionCallback) {
          var fn = window[temp.options.actionCallback];
          if (Object.isFunction(fn)) {
            fn(xhr);
          }
        } else if (xhr.responseText != "") {
          alert(xhr.responseText);
        } else {
          window.location.reload();
        }
      });
    },
    _doButton: function(input) {
      var action = "",
        cs = [],
        patt = /^([a-z0-9_\-]+)_([0-9]+)(_([0-9]+))?$/,
        q = input.get("data-confirm"),
        chk = input.get("data-checkbox");
      if (chk) {
        cs = this.getCheck();
        if (cs.length == 0) {
          alert(trans("Please select at least one item").replace(/XXX/, trans('Checkbox')));
          return;
        }
      }
      if (this.options.actionConfirm) {
        var fn = window[this.options.actionConfirm],
          hs = patt.exec(input.id);
        if (hs && Object.isFunction(fn)) {
          var t = input.getText();
          t = t ? t.strip_tags() : null;
          action = fn(t, hs[1], hs[2], hs[4]);
        } else {
          action = "action=" + input.id;
        }
      } else if (!q || confirm(q)) {
        hs = patt.exec(input.id);
        if (hs) {
          if (hs[1] == "delete" || hs[1] == "cancel") {
            if (cs.length > 0 && confirm(trans("You want to XXX the selected items ?").replace(/XXX/, trans(hs[1])))) {
              action = "action=" + hs[1] + "&id=" + hs[2] + (hs[4] ? '&opt=' + hs[4] : '');
            } else if (confirm(trans("You want to XXX ?").replace(/XXX/, trans(hs[1])))) {
              action = "action=" + hs[1] + "&id=" + hs[2] + (hs[4] ? '&opt=' + hs[4] : '');
            }
          } else if (hs[4]) {
            action = "action=" + hs[1] + "_" + hs[2] + "&id=" + hs[4];
          } else {
            action = "action=" + hs[1] + "&id=" + hs[2];
          }
        } else {
          action = "action=" + input.id;
        }
      }
      if (action != "") {
        if (cs.length > 0) {
          action += '&ids=' + cs.join(',');
        }
        this.callAction(input, action);
      }
    },
    initTBODY: function(tbody, tr) {
      var row = 0,
        temp = this;
      forEach($G(tbody).elems("tr"), function() {
        if (temp.options.pmButton) {
          this.id = temp.table.id + "_" + row;
          forEach(this.querySelectorAll("select,input,textarea"), function() {
            this.id = this.name.replace(/([\[\]_]+)/g, "_") + row;
          });
        }
        if (tr === null || tr === this) {
          if (temp.options.onInitRow) {
            temp.options.onInitRow.call(temp, this, row);
          }
          if (temp.options.action) {
            var move = /(check|move)_([0-9]+)/;
            forEach($G(this).elems("a"), function() {
              var id = this.id;
              if (id && !move.test(id)) {
                callClick(this, function() {
                  temp._doButton(this);
                });
              }
            });
          }
        }
        row++;
      });
      let menus = this.table.querySelectorAll('.menubutton > ul'),
        tablebody = this.table.querySelector('.tablebody'),
        table_height = $G(tablebody).getHeight(),
        vp = $G(tablebody).viewportOffset(),
        height = 0;
      forEach(menus, function() {
        height = Math.max(height, $G(this).getHeight());
      });
      forEach(menus, function() {
        if (this.getTop() - vp.top + height > table_height) {
          $G(this.parentNode).addClass('uppermenu');
        }
      });
      tablebody.style.paddingTop = height + 'px';
      tablebody.style.marginTop = '-' + height + 'px';
    },
    initTR: function(el) {
      var hs,
        a_patt = /(delete|icon)[_\-](plus|minus|[0-9]+)/,
        check_patt = /check_([0-9]+)/,
        temp = this;
      var aClick = function() {
        var c = this.className;
        if (c == "icon-plus") {
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
          ntr = ntr.elems("input")[0];
          if (ntr) {
            ntr.focus();
            ntr.select();
          }
        } else if (c == "icon-minus") {
          var tr = $G(this.parentNode.parentNode.parentNode);
          var tbody = $G(tr.parentNode);
          var ret = true;
          if (temp.options.onBeforeDelete) {
            ret = temp.options.onBeforeDelete.call(temp, tr);
          }
          if (ret) {
            if (tbody.elems("tr").length > 1) {
              tr.remove();
              temp.initTBODY(tbody, false);
              if (temp.options.onDelete) {
                temp.options.onDelete.call(temp);
              }
            }
          }
        } else if ((hs = a_patt.exec(c))) {
          var action = "";
          if (hs[1] == "delete" && confirm(trans("You want to XXX ?").replace(/XXX/, trans("delete")))) {
            action = "action=delete&id=" + hs[2];
          }
          if (action != "" && temp.options.action) {
            send(temp.options.action, action, function(xhr) {
              var ds = xhr.responseText.toJSON();
              if (ds) {
                if (ds.alert && ds.alert != "") {
                  alert(ds.alert);
                } else if (ds.action) {
                  if (ds.action == "delete") {
                    var tr = $G(temp.table.id + "_" + ds.id);
                    var tbody = tr.parentNode;
                    tr.remove();
                    temp.initTBODY(tbody, tr);
                  }
                }
              } else if (xhr.responseText != "") {
                alert(xhr.responseText);
              }
            }, this);
          }
        }
      };
      var saClick = function() {
        this.focus();
        var chk = this.hasClass("icon-check");
        forEach(el.elems("a"), function() {
          if (check_patt.test(this.id)) {
            this.className = chk ? "icon-uncheck" : "icon-check";
            this.title = chk ? trans("check") : trans("uncheck");
          } else if ($G(this).hasClass("checkall")) {
            this.className = chk ? "checkall icon-uncheck" : "checkall icon-check";
            this.title = chk ? trans("select all") : trans("select none");
          }
        });
        return false;
      };
      var sClick = function() {
        this.focus();
        var chk = $G(this).hasClass("icon-check");
        this.className = chk ? "icon-uncheck" : "icon-check";
        this.title = chk ? trans("check") : trans("uncheck");
        forEach(el.elems("a"), function() {
          if (this.hasClass("checkall")) {
            this.className = "checkall icon-uncheck";
            this.title = trans("select all");
          }
        });
        return false;
      };
      forEach($G(el).elems("a"), function() {
        if (a_patt.test(this.className)) {
          callClick(this, aClick);
        } else if ($G(this).hasClass("checkall")) {
          this.title = trans("select all");
          callClick(this, saClick);
        } else if (check_patt.test(this.id)) {
          this.title = trans("check");
          callClick(this, sClick);
        }
      });
      forEach(el.querySelectorAll('.icon-copy'), function() {
        callClick(this, function() {
          if (this.value) {
            copyToClipboard(this.value);
          } else if (this.title) {
            copyToClipboard(this.title);
          } else if (this.innerHTML) {
            copyToClipboard(this.innerHTML.strip_tags());
          } else {
            return false;
          }
          document.body.msgBox(trans('successfully copied to clipboard'));
          return false;
        });
      });
    },
    setSort: function(sort, patt) {
      var hs;
      forEach(this.table.elems("th"), function() {
        hs = patt.exec(this.className);
        if (hs) {
          if (sort == hs[2]) {
            this.className = this.className.replace("sort_" + hs[1], "sort_" + (hs[1] == "asc" ? "desc" : "asc"));
          } else {
            this.className = this.className.replace("sort_" + hs[1], "sort_none");
          }
        }
      });
    },
    redirect: function() {
      var hs,
        patt = /^(.*)=(.*)$/,
        urls = {},
        u = window.location.href,
        us2 = u.split("#"),
        us1 = us2[0].split("?");
      forEach([us1[1], us2[1]], function() {
        if (this) {
          forEach(this.split("&"), function() {
            if ((hs = patt.exec(this))) {
              hs[1] = hs[1].toLowerCase();
              hs[2] = hs[2].toLowerCase();
              if (hs[1] != "page" && hs[1] != "sort" && hs[1] != "search" && !(hs[1] == "action" && (hs[2] == "login" || hs[2] == "logout"))) {
                urls[hs[1]] = this;
              }
            } else {
              urls[this] = this;
            }
          });
        }
      });

      var us = Object.toArray(urls);
      us.push("page=" + this.page);
      if (this.sort) {
        us.push("sort=" + this.sort);
      }
      if (this.search) {
        us.push("search=" + encodeURIComponent(this.search));
      }
      if (us2.length == 2) {
        u = us2[0];
        if (us.length > 0) {
          u += "#" + us.join("&");
        }
      } else {
        u = us1[0];
        if (us.length > 0) {
          u += "?" + us.join("&");
        }
      }
      return u;
    }
  };
})();
