/**
 * GLoader
 * Javascript page load (Ajax)
 *
 * @filesource js/table.js
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */
(function () {
  'use strict';
  window.GLoader = GClass.create();
  GLoader.prototype = {
    initialize: function (reader, callback, geturl, onbeforeload) {
      this.myhistory = new Array();
      this.geturl = geturl || this.parseURL;
      this.req = new GAjax();
      var my_location = location.toString();
      var a = my_location.indexOf('?');
      var b = my_location.indexOf('#');
      var locs = my_location.split(/[\?\#]/);
      if (a > -1 && b > -1) {
        this.lasturl = a < b ? locs[1] : locs[2];
      } else if (a > -1) {
        this.lasturl = locs[1];
      } else {
        this.lasturl = '';
      }
      var temp = this;
      window.setInterval(function () {
        locs = window.location.toString().split('#');
        if (locs[1]) {
          if (locs[1] != temp.lasturl && locs[1].indexOf('=') > -1) {
            temp.lasturl = locs[1];
            temp.myhistory.push(locs[1]);
            if (temp.myhistory.length > 2) {
              temp.myhistory.shift();
            }
            var ret = locs[1];
            if (Object.isFunction(onbeforeload)) {
              ret = onbeforeload.call(ret);
              if (ret === true || Object.isNull(ret)) {
                ret = locs[1];
              }
            }
            if (ret !== false) {
              temp.req.send(reader, ret, callback);
            }
          }
        } else {
          locs = locs[0].split('?');
          locs = locs[1] ? locs[1] : 'module=' + FIRST_MODULE;
          if (locs != temp.lasturl && temp.myhistory.length > 0) {
            temp.lasturl = locs;
            temp.myhistory.push(locs);
            if (temp.myhistory.length > 2) {
              temp.myhistory.shift();
            }
            temp.req.send(reader, locs, callback);
          }
        }
      }, 100);
    },
    initLoading: function (loading, center) {
      this.req.initLoading(loading, center);
      return this;
    },
    init: function (obj) {
      var temp = this;
      var patt1 = new RegExp('^.*' + location.hostname + '/(.*?)$');
      var patt2 = new RegExp('.*#.*?');
      forEach($E(obj).getElementsByTagName('a'), function () {
        if (this.target == '' && this.onclick == null && this.href != '' && patt1.exec(this.href) && !patt2.exec(this.href)) {
          this.onclick = function (e) {
            var evt = e || window.event;
            if (!(evt.shiftKey || evt.ctrlKey || evt.metaKey || evt.altKey)) {
              return temp.location(this.href);
            }
          };
        }
      });
      return this;
    },
    location: function (url) {
      var ret = this.geturl.call(this, url);
      if (ret) {
        var locs = window.location.toString().split('#');
        window.location = locs[0] + '#' + decodeURIComponent(ret.join('&'));
        return false;
      } else {
        window.location = url;
      }
      return true;
    },
    back: function () {
      if (this.myhistory.length >= 2) {
        var history = this.myhistory[this.myhistory.length - 2],
          urls = window.location.toString().split('#');
        window.location = urls[0] + '#' + history;
      } else {
        window.history.go(-1);
      }
    },
    reload: function () {
      var locs = window.location.toString().split('#'),
        ret = new Array();
      if (locs.length > 1) {
        forEach(locs[1].split('&'), function () {
          if (/([^0-9]+)/.test(this)) {
            ret.push(this);
          }
        });
      }
      if (ret.length == 0) {
        window.location.reload();
      } else {
        ret.push('time=' + new Date().getTime());
        window.location = locs[0] + '#' + decodeURIComponent(ret.join('&'));
      }
    },
    parseURL: function (url) {
      var loader_patt0 = /.*?module=.*?/,
        loader_patt1 = new RegExp('^' + WEB_URL + '([a-z0-9]+)/([0-9]+)/([0-9]+)/(.*).html$'),
        loader_patt2 = new RegExp('^' + WEB_URL + '([a-z0-9]+)/([0-9]+)/(.*).html$'),
        loader_patt3 = new RegExp('^' + WEB_URL + '([a-z0-9]+)/([0-9]+).html$'),
        loader_patt4 = new RegExp('^' + WEB_URL + '([a-z0-9]+)/(.*).html$'),
        loader_patt5 = new RegExp('^' + WEB_URL + '(.*).html$'),
        p1 = /module=(.*)?/,
        urls = url.replace(/&amp;/g, '&').split('?'),
        new_q = new Array(),
        hs;
      if (urls[1] && loader_patt0.exec(urls[1])) {
        new_q.push(urls[1]);
        return new_q;
      } else if (hs = loader_patt1.exec(urls[0])) {
        new_q.push('module=' + hs[1] + '&cat=' + hs[2] + '&id=' + hs[3]);
      } else if (hs = loader_patt2.exec(urls[0])) {
        new_q.push('module=' + hs[1] + '&cat=' + hs[2] + '&alias=' + hs[3]);
      } else if (hs = loader_patt3.exec(urls[0])) {
        new_q.push('module=' + hs[1] + '&cat=' + hs[2]);
      } else if (hs = loader_patt4.exec(urls[0])) {
        new_q.push('module=' + hs[1] + '&alias=' + hs[2]);
      } else if (hs = loader_patt5.exec(urls[0])) {
        new_q.push('module=' + hs[1]);
      } else {
        return null;
      }
      if (urls[1]) {
        forEach(urls[1].split('&'), function (q) {
          if (q != 'action=logout' && q != 'action=login' && !p1.test(q)) {
            new_q.push(q);
          }
        });
      }
      return new_q;
    }
  };
}());
