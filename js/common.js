/**
 * Javascript Libraly for Ajax Front-end and Back-end
 *
 * @filesource js/common.js
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */
var loader, modal = null;
function send(target, query, callback, wait, c) {
  var req = new GAjax();
  req.initLoading(wait || 'wait', false, c);
  req.send(target, query, function (xhr) {
    if (callback) {
      callback.call(this, xhr);
    }
  });
}
var hideModal = function () {
  if (modal != null) {
    modal.hide();
  }
};
function showModal(src, qstr, doClose) {
  send(src, qstr, function (xhr) {
    var ds = xhr.responseText.toJSON();
    var detail = '';
    if (ds) {
      if (ds.alert) {
        alert(ds.alert);
      } else if (ds.detail) {
        detail = decodeURIComponent(ds.detail);
      }
    } else {
      detail = xhr.responseText;
    }
    if (detail != '') {
      modal = new GModal({
        onclose: doClose
      }).show(detail);
      detail.evalScript();
    }
  });
}
function initModal(id, callback) {
  var patt = /^modal_([a-z\/]+)_(.*)$/;
  forEach($G(id).elems('a'), function () {
    if (patt.test(this.id)) {
      callClick(this, function () {
        showModal('index.php/index/controller/modal', 'data=' + this.id, (callback || $K.emptyFunction));
      });
    }
  });
}
function defaultSubmit(ds) {
  var _alert = '',
    _input = false,
    _url = false,
    _location = false,
    t, el,
    remove = /remove([0-9]{0,})/;
  for (var prop in ds) {
    var val = ds[prop];
    if (prop == 'error') {
      _alert = eval(val);
    } else if (prop == 'alert') {
      _alert = val;
    } else if (prop == 'modal') {
      if (modal && val == 'close') {
        modal.hide();
      }
    } else if (prop == 'location') {
      _location = val;
    } else if (prop == 'url') {
      _url = val;
      _location = val;
    } else if (prop == 'open') {
      window.setTimeout(function () {
        window.open(val);
      }, 1);
    } else if (prop == 'tab') {
      initWriteTab("accordient_menu", val);
    } else if (remove.test(prop)) {
      if ($E(val)) {
        $G(val).fadeOut(function () {
          $G(val).remove();
        });
      }
    } else if (prop == 'input') {
      el = $G(val);
      t = el.title ? el.title.strip_tags() : '';
      if (t == '' && el.placeholder) {
        t = el.placeholder.strip_tags();
      }
      if (_input != el) {
        el.invalid(t);
      }
      if (t != '' && _alert == '') {
        _alert = t;
        _input = el;
      }
    } else if ($E(prop)) {
      $G(prop).setValue(decodeURIComponent(val).replace(/\%/g, '&#37;'));
    } else if ($E(prop.replace('ret_', ''))) {
      el = $G(prop.replace('ret_', ''));
      if (el.display) {
        el = el.display;
      }
      if (val == '') {
        el.valid();
      } else {
        if (val == 'this' || val == 'Please fill in' || val == 'Please browse file') {
          if (el.placeholder) {
            t = el.placeholder.strip_tags();
          } else {
            t = el.title.strip_tags();
          }
          val = val == 'this' ? t : trans(val) + ' ' + t;
        }
        if (_input != el) {
          el.invalid(val);
        }
        if (_alert == '') {
          _alert = val;
          _input = el;
        }
      }
    }
  }
  if (_alert != '') {
    alert(_alert);
  }
  if (_input) {
    _input.focus();
    var tag = _input.tagName.toLowerCase();
    if (tag != 'select') {
      _input.highlight();
    }
    if (tag == 'input') {
      var type = _input.get('type').toLowerCase();
      if (type == 'text' || type == 'password') {
        _input.select();
      }
    }
  }
  if (_location) {
    if (_location == 'reload') {
      if (loader) {
        loader.reload();
      } else {
        window.location.reload();
      }
    } else if (_location == 'back') {
      if (loader) {
        loader.back();
      } else {
        window.history.go(-1);
      }
    } else if (loader && _location != _url) {
      loader.location(_location);
    } else {
      window.location = _location.replace(/&amp;/g, '&');
    }
  }
}
function doFormSubmit(xhr) {
  var datas = xhr.responseText.toJSON();
  if (datas) {
    defaultSubmit(datas);
  } else if (xhr.responseText != '') {
    alert(xhr.responseText);
  }
}
function initWriteTab(id, sel) {
  function _doclick(sel) {
    forEach($E(id).getElementsByTagName('a'), function () {
      var a = this.id.replace('tab_', '');
      if ($E(a)) {
        this.className = a == sel ? 'select' : '';
        $E(a).style.display = a == sel ? 'block' : 'none';
      }
    });
    $E('tab').value = sel;
  }
  forEach($G(id).elems('a'), function () {
    if ($E(this.id.replace('tab_', ''))) {
      callClick(this, function () {
        _doclick(this.id.replace('tab_', ''));
        return false;
      });
    }
  });
  _doclick(sel);
}
var dataTableActionCallback = function (xhr) {
  var el,
    prop,
    val,
    ds = xhr.responseText.toJSON();
  if (ds) {
    for (prop in ds) {
      val = ds[prop];
      if (prop == 'location') {
        if (val == 'reload') {
          loader.reload();
        } else {
          window.location = val;
        }
      } else if (prop == 'open') {
        window.setTimeout(function () {
          window.open(val);
        }, 1);
      } else if (prop == 'remove') {
        if ($E(val)) {
          $G(val).remove();
        }
      } else if (prop == 'alert') {
        alert(val);
      } else if (prop == 'elem') {
        el = $E(val);
        if (el) {
          el.className = ds.class;
          el.title = ds.title;
        }
      } else if (prop == 'modal') {
        if (val == 'close') {
          if (modal) {
            modal.hide();
          }
        } else {
          modal = new GModal().show(val);
          val.evalScript();
        }
      } else if ($E(prop)) {
        $G(prop).setValue(val);
      }
    }
  } else if (xhr.responseText != '') {
    alert(xhr.responseText);
  }
};
function checkUsername() {
  var patt = /[a-zA-Z0-9@\.\-_]+/;
  var value = this.value;
  var ids = this.id.split('_');
  var id = '&id=' + floatval($E(ids[0] + '_id').value);
  if (value == '') {
    this.invalid(this.title);
  } else if (patt.test(value)) {
    return 'value=' + encodeURIComponent(value) + id;
  } else {
    this.invalid(this.title);
  }
}
function checkEmail() {
  var value = this.value;
  var ids = this.id.split('_');
  var id = '&id=' + floatval($E(ids[0] + '_id').value);
  if (value == '') {
    this.invalid(this.title);
  } else if (/^[_\.0-9a-zA-Z-]+@([0-9a-zA-Z][0-9a-zA-Z-]+\.)+[a-zA-Z]{2,6}$/.test(value)) {
    return 'value=' + encodeURIComponent(value) + id;
  } else {
    this.invalid(this.title);
  }
}
function checkPassword() {
  var ids = this.id.split('_');
  var id = '&id=' + floatval($E(ids[0] + '_id').value);
  var Password = $E(ids[0] + '_password');
  var Repassword = $E(ids[0] + '_repassword');
  if (Password.value == '' && Repassword.value == '') {
    if (id == 0) {
      this.Validator.invalid(this.Validator.title);
    } else {
      this.Validator.reset();
    }
    this.Validator.reset();
  } else if (Password.value == Repassword.value) {
    Password.Validator.valid();
    Repassword.Validator.valid();
  } else {
    this.Validator.invalid(this.Validator.title);
  }
}
function checkIdcard() {
  var value = this.value;
  var ids = this.id.split('_');
  var id = '&id=' + floatval($E(ids[0] + '_id').value);
  var i, sum;
  if (value.length == 0) {
    this.reset();
  } else if (value.length != 13) {
    this.invalid(this.title);
  } else {
    for (i = 0, sum = 0; i < 12; i++) {
      sum += parseFloat(value.charAt(i)) * (13 - i);
    }
    if ((11 - sum % 11) % 10 != parseFloat(value.charAt(12))) {
      this.invalid(this.title);
    } else {
      return 'value=' + encodeURIComponent(value) + '&id=' + id;
    }
  }
}
function initMailserver() {
  var doChanged = function () {
    var a = this.value.toInt();
    $E('email_SMTPSecure').disabled = (a == 0);
    $E('email_Username').disabled = (a == 0);
    $E('email_Password').disabled = (a == 0);
  };
  var el = $G('email_SMTPAuth');
  el.addEvent('change', doChanged);
  doChanged.call(el);
}
function replaceURL(key, value) {
  var q,
    prop,
    urls = window.location.toString().replace(/\#/g, '&').replace(/\?/g, '&').split('&'),
    new_url = new Object(),
    qs = Array(),
    l = urls.length;
  if (l > 1) {
    for (var n = 1; n < l; n++) {
      if (urls[n] != 'action=login' && urls[n] != 'action=logout') {
        q = urls[n].split('=');
        if (q.length == 2) {
          new_url[q[0]] = q[1];
        }
      }
    }
  }
  new_url[key] = value;
  for (prop in new_url) {
    if (new_url[prop]) {
      qs.push(prop + '=' + new_url[prop]);
    } else {
      qs.push(prop);
    }
  }
  return urls[0] + '?' + qs.join('&');
}
function initSystem() {
  new Clock('local_time');
  new Clock('server_time');
}
function _doCheckKey(input, e, patt) {
  var val = input.value;
  var key = GEvent.keyCode(e);
  if (!((key > 36 && key < 41) || key == 8 || key == 9 || key == 13 || GEvent.isCtrlKey(e))) {
    val = String.fromCharCode(key);
    if (val !== '' && !patt.test(val)) {
      GEvent.stop(e);
      return false;
    }
  }
  return true;
}
var numberOnly = function (e) {
  return _doCheckKey(this, e, /[0-9]/);
};
var integerOnly = function (e) {
  return _doCheckKey(this, e, /[0-9\-]/);
};
var currencyOnly = function (e) {
  return _doCheckKey(this, e, /[0-9\.]/);
};
function countryChanged(prefix) {
  var _contryChanged = function () {
    if (this.value != 'TH') {
      $G($E(prefix + '_provinceID').parentNode.parentNode).addClass('hidden');
      $G($E(prefix + '_province').parentNode.parentNode).removeClass('hidden');
    } else {
      $G($E(prefix + '_provinceID').parentNode.parentNode).removeClass('hidden');
      $G($E(prefix + '_province').parentNode.parentNode).addClass('hidden');
    }
  };
  if ($E(prefix + '_country')) {
    $G(prefix + '_country').addEvent('change', _contryChanged);
    _contryChanged.call($E(prefix + '_country'));
  }
}
function selectMenu(module) {
  if ($E('topmenu')) {
    var tmp = false;
    forEach($E('topmenu').getElementsByTagName('li'), function (item, index) {
      var cs = new Array();
      if (index == 0) {
        tmp = item;
      }
      forEach(this.className.split(' '), function (c) {
        if (c == module) {
          tmp = false;
          cs.push(c + ' select');
        } else if (c !== '' && c != 'select' && c != 'default') {
          cs.push(c);
        }
      });
      this.className = cs.join(' ');
    });
    if (tmp) {
      $G(tmp).addClass('default');
    }
  }
}
function loadJavascript(id, src) {
  var js, fjs = document.getElementsByTagName('script')[0];
  if (document.getElementById(id)) {
    return;
  }
  js = document.createElement('script');
  js.id = id;
  js.src = src;
  fjs.parentNode.insertBefore(js, fjs);
}
function initEditInplace(id, model, addbtn) {
  var patt = /list_([a-z]+)_([0-9]+)(_([0-9]+))?/;
  var o = {
    onSave: function (v, editor) {
      var req = new GAjax({
        asynchronous: false
      });
      req.initLoading(editor, false);
      req.send('index.php/' + model, 'action=' + this.id + '&value=' + encodeURIComponent(v));
      ds = req.responseText.toJSON();
      if (ds) {
        if (ds.alert) {
          alert(ds.alert);
        }
        if (ds.editId) {
          $E(ds.editId).innerHTML = ds.edit;
        }
        return true;
      } else if (req.responseText != '') {
        alert(req.responseText);
      }
      return false;
    }
  };
  function _doAction(c) {
    var q = '',
      hs = patt.exec(this.id);
    if (hs[1] == 'add') {
      q = 'action=' + this.id;
    } else if (hs[1] == 'delete' && confirm(trans('You want to XXX ?').replace(/XXX/, trans('delete')))) {
      q = 'action=' + this.id;
    } else if (hs[1] == 'color') {
      q = 'action=' + this.id + '&value=' + encodeURIComponent(c);
    } else if (hs[1] == 'published') {
      q = 'action=' + this.id + '&value=' + this.className.replace('icon-published', '');
    } else if (hs[1] == 'status') {
      q = 'action=' + this.id + '&value=' + this.value;
    }
    if (q != '') {
      send('index.php/' + model, q, function (xhr) {
        var ds = xhr.responseText.toJSON();
        if (ds) {
          if (ds.data) {
            $G(id).appendChild(ds.data.toDOM());
            _doInitEditInplaceMethod(ds.newId);
            $E(ds.newId.replace('list_', 'list_name_')).focus();
          } else if (ds.del) {
            $G(ds.del).remove();
          } else if (ds.editId) {
            hs = patt.exec(ds.editId);
            if (hs[1] == 'color') {
              $E(ds.editId).title = trans('change color') + ' (' + ds.edit + ')';
              $E(ds.editId).style.color = ds.edit;
            } else if (hs[1] == 'published') {
              $E(ds.editId).className = 'icon-published' + ds.edit;
              $E(ds.editId).title = ds.edit == 1 ? DISABLE : ENABLE;
            }
          }
          if (ds.alert) {
            alert(ds.alert);
          }
        } else if (xhr.responseText != '') {
          alert(xhr.responseText);
        }
      }, this);
    }
  }
  function _initOrder() {
    new GSortTable(id, {
      'tag': 'li',
      'endDrag': function () {
        var trs = new Array();
        forEach($G(id).elems('li'), function () {
          if (this.id) {
            trs.push(this.id);
          }
        });
        if (trs.length > 1) {
          send('index.php/' + model, 'action=move&value=' + trs.join(',').replace(/list_/g, ''), doFormSubmit);
        }
      }
    });
  }
  function _doInitEditInplaceMethod(src) {
    var loading = true,
      move = false;
    forEach($G(src).elems('*'), function () {
      var hs = patt.exec(this.id);
      if (hs) {
        if ($G(this).hasClass('editinplace')) {
          new EditInPlace(this, o);
        } else if (hs[1] == 'published') {
          callClick(this, _doAction);
          this.title = this.className == 'icon-published1' ? DISABLE : ENABLE;
        } else if (hs[1] == 'color') {
          var t = this.title;
          this.title = trans('change color') + ' (' + t + ')';
          new GDDColor(this, function (c) {
            $E(this.input.id).style.color = c;
            if (!loading) {
              _doAction.call(this.input, c);
            }
          }).setColor(t);
        } else if (hs[1] == 'order') {
          move = true;
        } else {
          callClick(this, _doAction);
        }
      }
    });
    if (move) {
      _initOrder();
    }
    loading = false;
  }
  callClick(addbtn, _doAction);
  _doInitEditInplaceMethod(id);
}
function initLanguageTable(id) {
  forEach($G(id).elems('a'), function () {
    if ($G(this).hasClass('icon-copy')) {
      callClick(this, function () {
        copyToClipboard(this.title);
        document.body.msgBox(trans('successfully copied to clipboard'));
        return false;
      });
    }
  });
}
function validateKeyPress(input, e, patt) {
  var val = input.value;
  var key = GEvent.keyCode(e);
  if (!((key > 36 && key < 41) || key == 8 || key == 9 || key == 13 || GEvent.isCtrlKey(e))) {
    val = String.fromCharCode(key);
    if (val !== '' && !patt.test(val)) {
      GEvent.stop(e);
      return false;
    }
  }
  return true;
}
function initFirstRowNumberOnly(tr) {
  var doKeyPress = function (e) {
    return validateKeyPress(this, e, /[0-9]+/);
  };
  forEach($G(tr).elems('input'), function (item, index) {
    if (index == 0) {
      $G(item).addEvent('keypress', doKeyPress);
    }
  });
}
function initCompany() {
  var doChanged = function () {
    var t = $E('company_type').value;
    $E('tax_id').disabled = t == 0;
    $E('idcard').disabled = t == 1;
    $E('tax_id').parentNode.parentNode.className = t == 0 ? 'hidden' : 'item';
    $E('idcard').parentNode.parentNode.className = t == 1 ? 'hidden' : 'item';
  };
  countryChanged('company');
  $G('company_type').addEvent('change', doChanged);
  doChanged();
}
var createLikeButton;
function initWeb(module) {
  module = module ? module + '/' : '';
  if (navigator.userAgent.indexOf("MSIE") > -1) {
    document.body.addClass("ie");
  }
  forEach(document.body.elems('nav'), function () {
    if ($G(this).hasClass('topmenu sidemenu slidemenu gddmenu')) {
      new GDDMenu(this);
    }
  });
  var _scrolltop = 0;
  var toTop = 100;
  if ($E('toTop')) {
    if ($G('toTop').hasClass('fixed_top')) {
      document.addEvent('toTopChange', function () {
        if (document.body.hasClass('toTop')) {
          var _toTop = $G('toTop').copy();
          _toTop.zIndex = -1;
          _toTop.id = 'toTop_temp';
          _toTop.setStyle('opacity', 0);
          _toTop.removeClass('fixed_top');
          $G('toTop').after(_toTop);
        } else if ($E('toTop_temp')) {
          $G('toTop_temp').remove();
        }
      });
    }
    toTop = $E('toTop').getTop();
  }
  document.addEvent('scroll', function () {
    var c = this.viewport.getscrollTop() > toTop;
    if (_scrolltop != c) {
      _scrolltop = c;
      if (c) {
        document.body.addClass('toTop');
        document.callEvent('toTopChange');
      } else {
        document.body.removeClass('toTop');
        document.callEvent('toTopChange');
      }
    }
  });
  var fontSize = floatval(Cookie.get(module + 'fontSize'));
  document.body.set('data-fontSize', floatval(document.body.getStyle('fontSize')));
  if (fontSize > 5) {
    document.body.setStyle('fontSize', fontSize + 'px');
  }
  forEach(document.body.elems("a"), function () {
    if (/^lang_([a-z]{2,2})$/.test(this.id)) {
      callClick(this, function (e) {
        var hs = /^lang_([a-z]{2,2})$/.exec(this.id);
        window.location = replaceURL('lang', hs[1]);
        GEvent.stop(e);
      });
    } else if (/font_size\s(small|normal|large)/.test(this.className)) {
      callClick(this, function (e) {
        fontSize = floatval(document.body.getStyle('fontSize'));
        var hs = /font_size\s(small|normal|large)/.exec(this.className);
        if (hs[1] == 'small') {
          fontSize = Math.max(6, fontSize - 2);
        } else if (hs[1] == 'large') {
          fontSize = Math.min(24, fontSize + 2);
        } else {
          fontSize = document.body.get('data-fontSize');
        }
        document.body.setStyle('fontSize', fontSize + 'px');
        Cookie.set(module + 'fontSize', fontSize);
        GEvent.stop(e);
      });
    }
  });
  loader = new GLoader(WEB_URL + module + 'loader.php/index/controller/loader/index', function (xhr) {
    var scroll_to = 'scroll-to';
    var content = $G('content');
    var datas = xhr.responseText.toJSON();
    if (datas) {
      for (var prop in datas) {
        var value = datas[prop];
        if (prop == 'detail') {
          content.setHTML(value);
          loader.init(content);
          content.replaceClass('loading', 'animation');
          content.Ready(function () {
            value.evalScript();
          });
        } else if (prop == 'topic') {
          document.title = value.unentityify();
        } else if (prop == 'menu') {
          selectMenu(value);
        } else if (prop == 'to') {
          scroll_to = value;
        } else if ($E(prop)) {
          $E(prop).innerHTML = value;
        }
      }
      if (Object.isFunction(createLikeButton)) {
        createLikeButton();
      }
      if ($E(scroll_to)) {
        window.scrollTo(0, $G(scroll_to).getTop() - 10);
      }
    } else {
      content.setHTML(xhr.responseText);
    }
  }, null, function () {
    $G('content').replaceClass('animation', 'loading');
    return true;
  });
  loader.initLoading('wait', false);
  loader.init(document);
}
if (navigator.userAgent.match(/(iPhone|iPod|iPad)/i)) {
  document.addEventListener("touchstart", function () {}, false);
}
