/**
 * GTooltip
 * Javascript tooltip
 *
 * @filesource js/gtooltip.js
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */
(function () {
  'use strict';
  var GTooltips = new Array();
  window.GTooltip = GClass.create();
  GTooltip.prototype = {
    initialize: function (o) {
      this.options = {
        id: '',
        delayin: 200,
        delayout: 500,
        autohide: true,
        autohidedelay: 5000,
        opacity: 100,
        cache: false
      };
      for (var property in o) {
        this.options[property] = o[property];
      }
      this.iframe = $G(document.body).create('iframe', {
        id: 'iframe_' + this.options.id,
        name: 'iframe_' + this.options.id,
        frameBorder: 0,
        scrollbar: 0
      });
      this.iframe.setStyle('opacity', 0);
      this.iframe.setStyle('position', 'absolute');
      this.iframe.setStyle('display', 'none');
      this.iframe.setStyle('zIndex', 1001);
      if (this.options.id !== '') {
        if ($E(this.options.id)) {
          this.tooltip = $G(this.options.id);
        } else {
          this.tooltip = $G(document.body).create('div', {
            id: 'div_' + this.options.id,
            name: 'div_' + this.options.id
          });
        }
      } else {
        this.tooltip = $G(document.body).create('div');
      }
      this.tooltip.setStyle('opacity', 0);
      this.tooltip.setStyle('position', 'absolute');
      this.tooltip.setStyle('display', 'none');
      this.tooltip.setStyle('zIndex', 1002);
      this.tooltip.onmouseover = this.cancleHideDelay.bind(this);
      this.tooltip.onmouseout = this.delayHide.bind(this);
      this.id = GTooltips.length;
      GTooltips[this.id] = this;
      $G(document.body).addEvent('click', function () {
        for (var i = 0; i < GTooltips.length; i++) {
          if (GTooltips[i].options.autohide) {
            GTooltips[i].hide();
          }
        }
      });
      this.req = new GAjax({
        cache: this.options.cache
      });
    },
    showAjax: function (elem, url, query, onload) {
      if (this.ajax_elem != elem) {
        this.ajax_elem = elem;
        this.req.abort();
        var temp = this;
        this.req.send(url, query, function (xhr) {
          var data = xhr.responseText;
          if (data !== '') {
            if (temp.iframe.style.display != 'none') {
              temp.show(elem, data);
              onload.call(temp, xhr);
            } else {
              temp.delayin = window.setTimeout(function () {
                temp.show(elem, data);
                onload.call(temp, xhr);
              }, temp.options.delayin);
            }
          }
        });
        var el = $E(elem);
        var old_onmouseout = el.onmouseout;
        var req = this.req;
        el.onmouseout = function () {
          req.abort();
          window.clearTimeout(temp.delayin);
          window.clearTimeout(temp.delayout);
          window.clearTimeout(temp.timeautohidedelay);
          el.onmouseout = old_onmouseout;
          temp.delayout = window.setTimeout(function () {
            temp.hide.call(temp);
          }, temp.options.delayout);
        };
      }
      return this;
    },
    show: function (s, v) {
      s = $G(s);
      var sPos = s.viewportOffset();
      var sHeight = s.getHeight();
      var sWidth = s.getWidth();
      var cHeight = document.viewport.getHeight();
      var cWidth = document.viewport.getWidth();
      var cTop = document.viewport.getscrollTop();
      var cLeft = document.viewport.getscrollLeft();
      this.node = s;
      this.tooltip.setStyle('display', 'block');
      this.iframe.setStyle('display', 'block');
      this.tooltip.style.width = 'auto';
      this.tooltip.innerHTML = v;
      this.value = v;
      var l, t, w;
      var p = s.hasClass('tooltip-bottom tooltip-top tooltip-left tooltip-right');
      if (p == 'tooltip-bottom') {
        t = sPos.top + sHeight + 6;
        if (t + this.tooltip.getHeight() > cTop + cHeight) {
          t = sPos.top - this.tooltip.getHeight() - 6;
          this.tooltip.className = 'tooltip-bottom';
        } else {
          this.tooltip.className = 'tooltip-top';
        }
        l = sPos.left;
      } else if (p == 'tooltip-top') {
        t = sPos.top - this.tooltip.getHeight() - 6;
        if (t < cTop) {
          t = sPos.top + sHeight + 6;
          this.tooltip.className = 'tooltip-top';
        } else {
          this.tooltip.className = 'tooltip-bottom';
        }
        l = sPos.left;
      } else {
        var rw = cWidth - sPos.left + cLeft - sWidth;
        var lw = sPos.left - cLeft;
        this.tooltip.className = lw < rw ? 'tooltip-left' : 'tooltip-right';
        var tWidth = this.tooltip.getClientWidth();
        var oWidth = this.tooltip.getWidth() - tWidth;
        if (lw < rw) {
          l = sPos.left + sWidth + 6;
          w = Math.min(tWidth, rw - oWidth - 16);
        } else {
          l = sPos.left - tWidth - oWidth - 6;
          if (l < cLeft + 10) {
            l = cLeft + 10;
            w = sPos.left - cLeft - 36;
          } else {
            w = Math.min(tWidth, sPos.left);
            l = sPos.left - w - 26;
          }
        }
        t = (sPos.top + ((sHeight - this.tooltip.getHeight()) / 2));
        if (w != tWidth) {
          this.tooltip.style.width = w + 'px';
        }
      }
      this.tooltip.style.left = l + 'px';
      this.tooltip.style.top = t + 'px';
      this.iframe.style.left = (l - 6) + 'px';
      this.iframe.style.top = (t - 6) + 'px';
      this.iframe.style.width = (12 + this.tooltip.getWidth()) + 'px';
      this.iframe.style.height = (12 + this.tooltip.getHeight()) + 'px';
      this.cancleHideDelay();
      var temp = this;
      for (var i = 0; i < GTooltips.length; i++) {
        if (i != this.id && GTooltips[i].options.autohide) {
          GTooltips[i].hide();
        }
      }
      this.tooltip.fadeTo(this.options.opacity, function () {
        temp.timeautohidedelay = window.setTimeout(temp.hide.bind(temp), temp.options.autohidedelay);
      });
    },
    delayHide: function () {
      this.timedelayhide = window.setTimeout(this.hide.bind(this), this.options.autohidedelay);
    },
    cancleHideDelay: function () {
      if (this.req) {
        this.req.abort();
      }
      window.clearTimeout(this.timeautohidedelay);
      window.clearTimeout(this.timedelayhide);
      window.clearTimeout(this.delayout);
    },
    hide: function () {
      var self = this;
      this.tooltip.fadeOut(function () {
        self.tooltip.setStyle('display', 'none');
        self.iframe.setStyle('display', 'none');
      });
    }
  };
}());