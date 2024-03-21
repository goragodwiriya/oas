/**
 * GDDMenu
 * Responsive dropdown menu (WAI AAA)
 *
 * @filesource js/gddmenu.js
 * @link https://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 */
(function() {
  "use strict";
  var GDDMenus = new Array();
  window.GDDMenu = GClass.create();
  GDDMenu.prototype = {
    initialize: function(id, onClick) {
      this.menu_id = "toggle-menu-" + GDDMenus.length;
      this.menu = $G(id);
      this.onClick = onClick;
      var self = this;
      var _toggleMenu = function(val) {
        self.toggleMenu(val);
      };
      if (this.menu.tagName.toLowerCase() == "nav") {
        var ul = $G(this.menu.getElementsByTagName("ul")[0]);
        if (this.menu.hasClass("topmenu slidemenu")) {
          var chk = $G().create("input", {
            type: "checkbox",
            id: self.menu_id,
            className: "toggle-menu"
          });
          var label = $G(document.createElement("label"));
          if (this.menu.hasClass("responsive")) {
            this.menu.insertBefore(chk, ul);
            this.menu.insertBefore(label, chk);
          } else {
            if ($E("slidemenu_content")) {
              $E("slidemenu_content").parentNode.insertBefore(
                chk,
                $E("slidemenu_content")
              );
            } else {
              this.menu.parentNode.insertBefore(chk, this.menu);
            }
            this.menu.insertBefore(label, ul);
          }
          label.className = "toggle-menu";
          label.set("for", self.menu_id);
          label.tabIndex = 0;
          label.addEvent("click", function(e) {
            _toggleMenu(!chk.checked);
            GEvent.stop(e);
          });
          label.addEvent("keydown", function(e) {
            if (GEvent.keyCode(e) == 32) {
              _toggleMenu(!chk.checked);
            }
          });
          for (var i = 0; i < 3; i++) {
            label.appendChild(document.createElement("span"));
          }
        }
        this.menu = ul;
      }
      this.id = "GDDmenu" + GDDMenus.length;
      var _dofocus = function(e) {
        window.clearTimeout(self.blurTime);
        self.select(this.parentNode, 0, true);
      };
      var _domouseover = function(e) {
        self.select(this, 0);
        GEvent.stop(e);
      };
      var _doblur = function(e) {
        self.blurItem = this.parentNode;
        self.blurTime = window.setTimeout(function() {
          self.select(self.blurItem, null, false);
        }, 1);
        GEvent.stop(e);
      };
      var _domouseout = function(e) {
        this.removeClass("hover focus");
      };

      function initMenu(ul, tab, id) {
        var li = ul.firstChild;
        while (li) {
          if (li.tagName && li.tagName.toLowerCase() == "li") {
            $G(li).addEvent("mouseover", _domouseover);
            li.addEvent("mouseout", _domouseout);
            var a = $G(li.getElementsByTagName("a")[0]);
            a.addEvent("focus", _dofocus);
            a.addEvent("blur", _doblur);
            var uls = li.getElementsByTagName("ul");
            if (tab > 0) {
              a.tabIndex = 0;
              li.addClass(id + " toplevelmenu");
            } else {
              li.addClass(id + " sublevelmenu");
            }
            if (uls.length > 0) {
              initMenu(uls[0], 0, id);
            }
          }
          li = li.nextSibling;
        }
      }
      initMenu(this.menu, 1, this.id);
      this.menu.addEvent("keydown", function(e) {
        var li = $G(GEvent.element(e).parentNode),
          key = GEvent.keyCode(e);
        if (key == 13) {
          self.selectMenu(li);
        } else if (li.hasClass("toplevelmenu")) {
          if (key == 37) {
            self.select(self.currItem, -1, true);
            GEvent.stop(e);
          } else if (key == 39) {
            self.select(self.currItem, 1, true);
            GEvent.stop(e);
          } else if (key == 40) {
            li = li.getElementsByTagName("li")[0];
            if (li) {
              self.select(li, 0, true);
              GEvent.stop(e);
            }
          }
        } else {
          if (key == 9) {
            self.selectTop(li, 1, true);
            GEvent.stop(e);
          } else if (key == 37) {
            li = li.parentNode.parentNode;
            if (li) {
              if ($G(li).hasClass("toplevelmenu")) {
                self.select(li, -1, true);
              } else {
                self.select(li, 0, true);
              }
              GEvent.stop(e);
            }
          } else if (key == 38) {
            self.select(li, -1, true);
            GEvent.stop(e);
          } else if (key == 39) {
            var lis = li.getElementsByTagName("li");
            if (lis.length > 0) {
              self.select(lis[0], 0, true);
            } else {
              self.selectTop(li, 1, true);
            }
            GEvent.stop(e);
          } else if (key == 40) {
            self.select(li, 1, true);
            GEvent.stop(e);
          }
        }
      });
      this.menu.addEvent("click", function(e) {
        var a = GEvent.element(e).parentNode;
        if (
          a.tagName.toLowerCase() == "a" &&
          (a.href != "" || a.parentNode.getElementsByTagName("li").length == 0)
        ) {
          _toggleMenu(false);
          self.selectMenu(a.parentNode);
          if (Object.isFunction(self.onClick)) {
            self.onClick.call(a);
          }
        }
      });
      GDDMenus.push(this);
    },
    selectMenu: function(li) {
      forEach(this.menu.getElementsByTagName("li"), function() {
        this.removeClass("hover focus select");
      });
      li.addClass('select');
    },
    toggleMenu: function(val) {
      var chk = $E(this.menu_id);
      if (chk) {
        chk.checked = val;
        if ($E("slidemenu_content") && this.menu.hasClass("slidemenu")) {
          if (val) {
            $G("slidemenu_content").addClass("showmenu");
          } else {
            $G("slidemenu_content").removeClass("showmenu");
          }
        }
      }
    },
    selectTop: function(li, v, s) {
      var m = li;
      while (m && m.tagName.toLowerCase() == "li" && $G(m).hasClass(this.id)) {
        li = m;
        m = m.parentNode.parentNode;
      }
      this.select(li, v, s);
    },
    select: function(m, v, s) {
      var n,
        f = m.parentNode.firstChild,
        treeNode = new Array(),
        self = this;
      if (v == null) {
        m = true;
      } else if (v == 1) {
        m = self.nextNode(m);
      } else if (v == -1) {
        m = self.previousNode(m);
      }
      if (m) {
        while (f) {
          if (f == m) {
            n = f;
            while (
              n &&
              n.tagName.toLowerCase() == "li" &&
              $G(n).hasClass(this.id)
            ) {
              treeNode.push(n);
              n = n.parentNode.parentNode;
            }
          }
          f = self.nextNode(f);
        }
        forEach(this.menu.getElementsByTagName("li"), function() {
          if (treeNode.indexOf(this) > -1) {
            if (this == m) {
              self.currItem = this;
              if (s) {
                self.firstNode(this).focus();
                this.addClass("focus");
              }
            }
            this.addClass("hover");
          } else {
            this.removeClass("hover focus");
          }
        });
      }
    },
    nextNode: function(n) {
      n = n.nextSibling;
      while (n && n.nodeType == 3) {
        n = n.nextSibling;
      }
      return n;
    },
    previousNode: function(n) {
      n = n.previousSibling;
      while (n && n.nodeType == 3) {
        n = n.previousSibling;
      }
      return n;
    },
    firstNode: function(n) {
      n = n.firstChild;
      while (n && n.nodeType == 3) {
        n = n.nextSibling;
      }
      return n;
    }
  };
})();
