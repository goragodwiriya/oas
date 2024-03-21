/**
 * Javascript Library for Kotchasan Framework
 *
 * @filesource js/gajax.js
 * @link https://www.kotchasan.com/
 * @copyright 2018 Goragod.com
 * @license https://www.kotchasan.com/license/
 */
window.$K = (function() {
  'use strict';
  var domloaded = false;
  var $K = {
    emptyFunction: function() {},
    resultFunction: function() {
      return true;
    },
    isMobile: function() {
      let check = false;
      (function(a) {if (/(android|bb\d+|meego).+mobile|avantgo|bada\/|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|mobile.+firefox|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\/|plucker|pocket|psp|series(4|6)0|symbian|treo|up\.(browser|link)|vodafone|wap|windows ce|xda|xiino/i.test(a) || /1207|6310|6590|3gso|4thp|50[1-6]i|770s|802s|a wa|abac|ac(er|oo|s\-)|ai(ko|rn)|al(av|ca|co)|amoi|an(ex|ny|yw)|aptu|ar(ch|go)|as(te|us)|attw|au(di|\-m|r |s )|avan|be(ck|ll|nq)|bi(lb|rd)|bl(ac|az)|br(e|v)w|bumb|bw\-(n|u)|c55\/|capi|ccwa|cdm\-|cell|chtm|cldc|cmd\-|co(mp|nd)|craw|da(it|ll|ng)|dbte|dc\-s|devi|dica|dmob|do(c|p)o|ds(12|\-d)|el(49|ai)|em(l2|ul)|er(ic|k0)|esl8|ez([4-7]0|os|wa|ze)|fetc|fly(\-|_)|g1 u|g560|gene|gf\-5|g\-mo|go(\.w|od)|gr(ad|un)|haie|hcit|hd\-(m|p|t)|hei\-|hi(pt|ta)|hp( i|ip)|hs\-c|ht(c(\-| |_|a|g|p|s|t)|tp)|hu(aw|tc)|i\-(20|go|ma)|i230|iac( |\-|\/)|ibro|idea|ig01|ikom|im1k|inno|ipaq|iris|ja(t|v)a|jbro|jemu|jigs|kddi|keji|kgt( |\/)|klon|kpt |kwc\-|kyo(c|k)|le(no|xi)|lg( g|\/(k|l|u)|50|54|\-[a-w])|libw|lynx|m1\-w|m3ga|m50\/|ma(te|ui|xo)|mc(01|21|ca)|m\-cr|me(rc|ri)|mi(o8|oa|ts)|mmef|mo(01|02|bi|de|do|t(\-| |o|v)|zz)|mt(50|p1|v )|mwbp|mywa|n10[0-2]|n20[2-3]|n30(0|2)|n50(0|2|5)|n7(0(0|1)|10)|ne((c|m)\-|on|tf|wf|wg|wt)|nok(6|i)|nzph|o2im|op(ti|wv)|oran|owg1|p800|pan(a|d|t)|pdxg|pg(13|\-([1-8]|c))|phil|pire|pl(ay|uc)|pn\-2|po(ck|rt|se)|prox|psio|pt\-g|qa\-a|qc(07|12|21|32|60|\-[2-7]|i\-)|qtek|r380|r600|raks|rim9|ro(ve|zo)|s55\/|sa(ge|ma|mm|ms|ny|va)|sc(01|h\-|oo|p\-)|sdk\/|se(c(\-|0|1)|47|mc|nd|ri)|sgh\-|shar|sie(\-|m)|sk\-0|sl(45|id)|sm(al|ar|b3|it|t5)|so(ft|ny)|sp(01|h\-|v\-|v )|sy(01|mb)|t2(18|50)|t6(00|10|18)|ta(gt|lk)|tcl\-|tdg\-|tel(i|m)|tim\-|t\-mo|to(pl|sh)|ts(70|m\-|m3|m5)|tx\-9|up(\.b|g1|si)|utst|v400|v750|veri|vi(rg|te)|vk(40|5[0-3]|\-v)|vm40|voda|vulc|vx(52|53|60|61|70|80|81|83|85|98)|w3c(\-| )|webc|whit|wi(g |nc|nw)|wmlb|wonu|x700|yas\-|your|zeto|zte\-/i.test(a.substr(0, 4))) check = true;})(navigator.userAgent || navigator.vendor || window.opera);
      return check;
    },
    init: function(element) {
      forEach(element.querySelectorAll('input,textarea,select'), function(elem) {
        var tagName = $G(elem).tagName.toLowerCase(),
          type = elem.get('type'),
          type = type ? type.toLowerCase() : '';
        if (elem.initObj !== true) {
          if (tagName == 'select') {
            elem.initObj = true;
            if (elem.get('checkbox') !== null) {
              new GSelect(elem);
            }
          } else if (tagName == 'textarea' || (type !== 'hidden' && type !== 'radio' && type !== 'checkbox' && type !== 'button' && type !== 'submit')) {
            var obj = new Object();
            obj.tagName = tagName;
            obj.type = type;
            elem.initObj = true;
            obj.title = elem.title;
            obj.disabled = elem.get('disabled') !== null;
            obj.maxlength = floatval(elem.get('maxlength'));
            obj.pattern = elem.get('pattern');
            if (obj.pattern !== null) {
              obj.pattern = new RegExp('^(?:' + obj.pattern + ')$');
              elem.setAttribute('pattern', '(.*){0,}');
            }
            obj.dataset = elem.dataset;
            if (typeof obj.dataset == 'undefined') {
              obj.dataset = {};
              forEach(elem.attributes, function() {
                var hs = this.name.match(/^data\-(.+)/);
                if (hs) {
                  obj.dataset[hs[0]] = this.value;
                }
              });
            }
            if (obj.tagName == 'textarea') {
              if (obj.maxlength > 0 || obj.pattern) {
                var _docheck = function() {
                  if (this.value != '' && obj.pattern && !obj.pattern.test(this.value)) {
                    this.invalid(obj.title !== '' ? obj.title : trans('Invalid data'));
                  } else if (obj.pattern) {
                    this.reset();
                  }
                };
                elem.srcObj = obj;
                elem.addEvent('keyup', _docheck);
                elem.addEvent('change', _docheck);
              }
            } else if (obj.tagName == 'input') {
              var c = elem.hasClass('currency number integer color inputgroup');
              if (c !== false) {
                obj.type = c;
              }
              if (elem.min) {
                obj.min = elem.min;
              }
              if (elem.max) {
                obj.max = elem.max;
              }
              var autofocus = elem.get('autofocus'),
                inputs = ['number', 'integer', 'tel', 'email', 'url', 'color', 'currency'],
                text = elem;
              if (obj.type == 'date' || obj.type == 'datetime-local' || obj.type == 'time') {
                new GDateTime(elem);
              } else if (obj.dataset['keyboard'] || inputs.indexOf(obj.type) > -1) {
                var o = {
                  type: 'text',
                  name: elem.name,
                  disabled: obj.disabled
                };
                if (elem.id != '') {
                  o.id = elem.id;
                }
                text = $G().create('input', o);
                if (elem.value != '') {
                  text.value = elem.value;
                }
                if (obj.title != '') {
                  text.title = obj.title;
                }
                if (elem.size) {
                  text.size = elem.size;
                }
                if (elem.placeholder) {
                  text.placeholder = elem.placeholder;
                }
                if (obj.maxlength > 0) {
                  text.maxlength = obj.maxlength;
                }
                if (elem.readOnly) {
                  text.readOnly = true;
                }
                if (obj.type == 'currency') {
                  text.digit = floatval(obj.dataset['digit'] ? obj.dataset['digit'] : 2);
                }
                text.className = elem.className;
                text.initObj = true;
                elem.replace(text);
                if (obj.type == 'color') {
                  new GDDColor(text, function(c) {
                    this.input.style.backgroundColor = c;
                    this.input.style.color = this.invertColor(c);
                    this.input.value = c;
                    this.input.callEvent('change');
                  });
                } else if (obj.type == 'email' || obj.type == 'url') {
                  if (obj.pattern == null) {
                    if (obj.type == 'email') {
                      obj.pattern = /^[_\.0-9a-zA-Z-]+@([0-9a-zA-Z][0-9a-zA-Z-]+\.)+[a-zA-Z]{2,6}$/;
                    } else {
                      obj.pattern = /^[a-z0-9\-\.:\/\#%\?\&\=_@~]{3,}$/i;
                    }
                  }
                  text.addEvent('keyup', _docheck);
                  text.addEvent('change', _docheck);
                } else {
                  if (!obj.dataset['keyboard']) {
                    if (obj.type == 'integer') {
                      obj.dataset['keyboard'] = '1234567890-';
                    } else if (obj.type == 'currency') {
                      obj.dataset['keyboard'] = '1234567890.,';
                    } else if (obj.type == 'number' || obj.type == 'tel') {
                      obj.dataset['keyboard'] = '1234567890';
                    }
                  }
                  if (obj.dataset['keyboard']) {
                    obj.pattern = new RegExp('^(?:[' + obj.dataset['keyboard'].preg_quote() + ']+)$');
                    if (obj.type == 'integer' || obj.type == 'currency' || obj.type == 'number') {
                      new GInput(text, obj.dataset['keyboard'], function() {
                        if (obj.min) {
                          this.value = Math.max(obj.min, floatval(this.value));
                        }
                        if (obj.max) {
                          this.value = Math.min(obj.max, floatval(this.value));
                        }
                        if (obj.type == 'currency') {
                          this.value = toCurrency(this.value, this.digit);
                        }
                      });
                    } else {
                      new GInput(text, obj.dataset['keyboard']);
                    }
                  }
                }
              } else if (obj.type == 'file') {
                if (elem.hasClass('g-file')) {
                  var p = elem.parentNode;
                  elem.setStyle('opacity', 0);
                  elem.style.cursor = 'pointer';
                  elem.style.position = 'absolute';
                  elem.style.left = 0;
                  elem.style.top = 1;
                  p.style.position = 'relative';
                  var display = document.createElement('input');
                  display.setAttribute('type', 'text');
                  display.id = elem.id;
                  elem.id = elem.id + '_tmp';
                  display.disabled = true;
                  display.placeholder = elem.placeholder;
                  p.appendChild(display);
                  elem.style.zIndex = text.style.zIndex + 1;
                  elem.style.height = '100%';
                  elem.style.width = '100%';
                  elem.addEvent('change', function() {
                    if (this.files) {
                      var input = this,
                        hs,
                        files = [],
                        preview = $E(this.get('data-preview')),
                        max = floatval(input.get('data-max')),
                        validImageTypes = ['image/gif', 'image/jpeg', 'image/jpg', 'image/png'];
                      if (preview) {
                        preview.innerHTML = '';
                      }
                      forEach(input.files, function() {
                        if (max > 0 && this.size > max) {
                          input.invalid(input.title);
                        } else {
                          files.push(this.name);
                          input.valid();
                          if (preview) {
                            hs = /\.([a-z0-9]+)$/.exec(this.name.toLowerCase());
                            var div = document.createElement('div');
                            div.className = 'file-thumb';
                            if (hs) {
                              div.innerHTML = hs[1];
                            }
                            preview.appendChild(div);
                            if (validImageTypes.includes(this.type) && window.FileReader) {
                              var r = new FileReader();
                              r.onload = function(evt) {
                                div.innerHTML = '';
                                div.style.backgroundImage = 'url(' + evt.target.result + ')';
                              };
                              r.readAsDataURL(this);
                            }
                          }
                        }
                      });
                      display.value = files.join(', ');
                      $G(display).callEvent('change', {
                        value: this.value,
                        files: this.files
                      });
                    }
                  });
                  elem.initObj = true;
                }
              } else if (obj.type == 'range') {
                new GRange(elem);
              } else if (obj.type == 'inputgroup') {
                new GInputGroup(elem);
              } else if (elem.get('list')) {
                new Datalist(elem);
              } else if (obj.pattern) {
                new GMask(text, function() {
                  return obj.pattern.test(this.value);
                });
              }
              if (elem.hasClass('showpassword') !== false) {
                var span = document.createElement('span');
                text.parentNode.appendChild(span);
                span.onclick = function() {
                  var type = text.getAttribute('type') === 'password' ? 'text' : 'password';
                  text.setAttribute('type', type);
                };
              }
              if (typeof obj.dataset !== 'undefined') {
                for (var prop in obj.dataset) {
                  if (obj.dataset[prop] !== null) {
                    text.setAttribute('data-' + prop, obj.dataset[prop]);
                  }
                }
              }
              if (autofocus !== null) {
                text.focus();
                if (obj.type == 'text') {
                  text.select();
                }
              }
              if (obj.pattern) {
                text.srcObj = obj;
              }
            }
          }
        }
      });
      var checkbox_loading = true,
        wCheckboxChanged = function(e) {
          this.checkId.disabled = !this.checked;
          if (!checkbox_loading && this.checked) {
            this.checkId.focus();
          }
        };
      forEach(element.querySelectorAll('.w_checkbox input[type=checkbox]'), function(elem) {
        if (!elem.checkId) {
          var id = elem.name.replace('checkbox_', '').replace('[', '').replace(']', '');
          if ($E(id)) {
            elem.checkId = $E(id);
            $G(elem).addEvent('change', wCheckboxChanged);
            wCheckboxChanged.call(elem);
          }
        }
      });
      window.setTimeout(function() {
        checkbox_loading = false;
      }, 1);
    }
  };
  if (typeof Array.prototype.indexOf != 'function') {
    Array.prototype.indexOf = function(t, i) {
      i || (i = 0);
      var l = this.length;
      if (i < 0) {
        i = l + i;
      }
      for (; i < l; i++) {
        if (this[i] == t) {
          return i;
        }
      }
      return -1;
    };
  }
  if (typeof forEach != 'function') {
    window.forEach = function(a, f) {
      var i,
        l = a.length,
        x = [];
      for (i = 0; i < l; i++) {
        x.push(a[i]);
      }
      for (i = 0; i < l; i++) {
        if (f.call(x[i], x[i], i) == true) {
          break;
        }
      }
    };
  }
  window.floatval = function(val) {
    var n = parseFloat(typeof val == 'string' ? val.replace(/[^0-9\-\.]/g, '') : val);
    return isNaN(n) ? 0 : n;
  };
  window.toCurrency = function(val, digit, zero) {
    if (typeof digit == 'undefined') {
      val = floatval(val).toFixed(2);
    } else if (typeof digit == 'number') {
      val = floatval(val).toFixed(digit);
    } else if (typeof val == 'number') {
      val = new String(floatval(val));
    }
    var ds = val.split('.'),
      val = ds[0].replace(/(\d)(?=(\d{3})+(?!\d))/g, '$1,');
    if (zero && /[0]+/g.test(ds[1])) {
      ds[1] = null;
    }
    return val + (ds[1] ? '.' + ds[1] : '');
  };
  window.round = function(val, digit) {
    var value = Math.round(val * Math.pow(10, digit)) / Math.pow(10, digit);
    if (val - value > 0) {
      return value + Math.floor((2 * Math.round((val - value) * Math.pow(10, digit + 1))) / 10) / Math.pow(10, digit);
    } else {
      return value;
    }
  };
  window.copyToClipboard = function(text) {
    var el = document.createElement('textarea');
    el.value = text;
    el.setAttribute('readonly', '');
    el.style.position = 'absolute';
    el.style.top = '-1000px';
    document.body.appendChild(el);
    el.select();
    document.execCommand('copy');
    document.body.removeChild(el);
  };
  window.trans = function(val) {
    try {
      var patt = /^[_]+|[_]+$/g;
      return eval(val.replace(/[\s]/g, '_').replace('?', '').replace(patt, '').toUpperCase());
    } catch (e) {
      return val;
    }
  };
  window.jsonToParams = function(json, separator) {
    var ret = [];
    for (var k in json) {
      ret.push(k + '=' + json[k]);
    }
    return ret.join(separator ? separator : '&');
  };
  window.jwt_decode = function(token) {
    var base64Url = token.split('.')[1],
      base64 = base64Url.replace(/-/g, '+').replace(/_/g, '/'),
      jsonPayload = decodeURIComponent(
        atob(base64)
          .split('')
          .map(function(c) {
            return '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2);
          })
          .join('')
      );
    return JSON.parse(jsonPayload);
  };
  window.debug = function(val) {
    var p = document.createElement('p'),
      div = $E('gdebug');
    if (!div) {
      div = document.createElement('div');
      div.id = 'gdebug';
      document.body.appendChild(div);
      div.style.cssText = 'left:0;bottom:0;width:100%;height:100px;color:#F00;background-color:#FFF;position:fixed;line-height:1;padding:10px;overflow:auto;';
    }
    p.style.cssText = 'margin:0;';
    p.innerText = val;
    div.appendChild(p);
    div.scrollTop = div.scrollHeight;
  };
  window.timeToMinute = function(time) {
    var sp = time.split(':');
    if (sp.length == 1) {
      return 0;
    } else {
      return (floatval(sp[0]) * 60) + floatval(sp[1]);
    }
  };
  window.timeToSecond = function(time) {
    var sp = time.split(':');
    if (sp.length == 1) {
      return 0;
    } else {
      return (floatval(sp[0]) * 60) + (floatval(sp[1] * 60) + floatval(sp[2]));
    }
  };
  Function.prototype.bind = function(o) {
    var __method = this;
    return function() {
      return __method.apply(o, arguments);
    };
  };
  Date.prototype.fromTime = function(mktime) {
    return new Date(mktime * 1000);
  };
  Date.prototype.format = function(fmt) {
    var result = '';
    for (var i = 0; i < fmt.length; i++) {
      result += this.formatter(fmt.charAt(i));
    }
    return result;
  };
  Date.prototype.formatter = function(c) {
    switch (c) {
      case 'd':
        return this.getDate().toString().leftPad(2, '0');
      case 'D':
        return Date.dayNames[this.getDay()];
      case 'y':
        return this.getFullYear().toString();
      case 'Y':
        return (this.getFullYear() + Date.yearOffset).toString();
      case 'm':
        return (this.getMonth() + 1).toString().leftPad(2, '0');
      case 'M':
        return Date.monthNames[this.getMonth()];
      case 'F':
        return Date.longMonthNames[this.getMonth()];
      case 'H':
        return this.getHours().toString().leftPad(2, '0');
      case 'h':
        return this.getHours();
      case 'A':
        return this.getHours() < 12 ? 'AM' : 'PM';
      case 'a':
        return this.getHours() < 12 ? 'am' : 'pm';
      case 'I':
        return this.getMinutes().toString().leftPad(2, '0');
      case 'i':
        return this.getMinutes();
      case 'S':
        return this.getSeconds().toString().leftPad(2, '0');
      case 's':
        return this.getSeconds();
      default:
        return c;
    }
  };
  Date.prototype.tomktime = function() {
    return Math.floor(this.getTime() / 1000);
  };
  Date.prototype.moveDate = function(value) {
    this.setDate(this.getDate() + value);
    return this;
  };
  Date.prototype.moveMonth = function(value) {
    this.setMonth(this.getMonth() + value);
    return this;
  };
  Date.prototype.moveYear = function(value) {
    this.setFullYear(this.getFullYear() + value);
    return this;
  };
  Date.prototype.isLeapYear = function() {
    var year = this.getFullYear();
    return (year & 3) == 0 && (year % 100 || (year % 400 == 0 && year));
  };
  Date.prototype.daysInMonth = function() {
    var arr = Array(31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31);
    arr[1] = this.isLeapYear() ? 29 : 28;
    return arr[this.getMonth()];
  };
  Date.prototype.dayOfWeek = function() {
    var a = parseInt((14 - this.getMonth()) / 12);
    var y = this.getFullYear() - a;
    var m = this.getMonth() + 12 * a - 2;
    var d = (this.getDate() + y + parseInt(y / 4) - parseInt(y / 100) + parseInt(y / 400) + parseInt((31 * m) / 12)) % 7;
    return d;
  };
  Date.prototype.compare = function(d) {
    if (Object.isString(d)) {
      d = new Date(d.replace(/-/g, '/'));
    }
    var days = Math.floor((this.getTime() - d.getTime()) / 86400000),
      numDays = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    if (days < 0) {
      var fromDate = this.getDate(),
        fromMonth = this.getMonth(),
        fromYear = this.getFullYear(),
        toDate = d.getDate(),
        toMonth = d.getMonth(),
        toYear = d.getFullYear();
      if (d.isLeapYear()) {
        numDays[1] = 29;
      }
    } else {
      var fromDate = d.getDate(),
        fromMonth = d.getMonth(),
        fromYear = d.getFullYear(),
        toDate = this.getDate(),
        toMonth = this.getMonth(),
        toYear = this.getFullYear();
      if (this.isLeapYear()) {
        numDays[1] = 29;
      }
    }
    var diffYear = toYear - fromYear,
      diffMonth = toMonth - fromMonth,
      diffDate = toDate - fromDate;
    if (diffDate < 0) {
      diffMonth--;
      toMonth = toMonth == 0 ? 11 : toMonth - 1;
      diffDate = numDays[toMonth] + diffDate;
    }
    if (diffMonth < 0) {
      diffYear--;
      diffMonth = 12 + diffMonth;
    }
    return {
      day: diffDate,
      month: diffMonth,
      year: diffYear,
      days: days
    };
  };
  Date.monthNames = [
    'Jan.',
    'Feb.',
    'Mar.',
    'Apr.',
    'May.',
    'Jun.',
    'Jul.',
    'Aug.',
    'Sep.',
    'Oct.',
    'Nov.',
    'Dec.'
  ];
  Date.longMonthNames = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December'
  ];
  Date.longDayNames = [
    'Sunday',
    'Monday',
    'Tuesday',
    'Wednesday',
    'Thursday',
    'Friday',
    'Saturday'
  ];
  Date.dayNames = ['Su.', 'Mo.', 'We.', 'Tu.', 'Th.', 'Fr.', 'Sa.'];
  Date.yearOffset = 0;
  String.prototype.entityify = function() {
    return this.replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;')
      .replace(/\\/g, '&#92;')
      .replace(/\//g, '&#47;')
      .replace(/&/g, '&amp;')
      .replace(/\{/g, '&#x007B;')
      .replace(/\}/g, '&#x007D;');
  };
  String.prototype.unentityify = function() {
    return this.replace(/&lt;/g, '<')
      .replace(/&gt;/g, '>')
      .replace(/&quot;/g, '"')
      .replace(/&#[0]?39;/g, "'")
      .replace(/&#92;/g, '\\')
      .replace(/&#47;/g, '/')
      .replace(/&amp;/g, '&')
      .replace(/&#x007B;/g, '{')
      .replace(/&#x007D;/g, '}');
  };
  String.prototype.toJSON = function() {
    try {
      var datas = JSON.parse(this);
      return typeof datas === 'object' ? datas : false;
    } catch (e) {
      return false;
    }
  };
  String.prototype.toInt = function() {
    return floatval(this);
  };
  String.prototype.currFormat = function() {
    return floatval(this).toFixed(2);
  };
  String.prototype.preg_quote = function() {
    return this.replace(/([-.*+?^${}()|[\]\/\\])/g, '\\$1');
  };
  String.prototype.capitalize = function() {
    return this.replace(/\b[a-z]/g, function(m) {
      return m.toUpperCase();
    });
  };
  String.prototype.evalScript = function() {
    var regex = /<script.*?>(.*?)<\/script>/g;
    var t = this.replace(/[\r\n]/g, '').replace(/\/\/<\!\[CDATA\[/g, '').replace(/\/\/\]\]>/g, '');
    var m = regex.exec(t);
    while (m) {
      try {
        eval(m[1]);
      } catch (e) {}
      m = regex.exec(t);
    }
    return this;
  };
  String.prototype.leftPad = function(c, f) {
    var r = '';
    for (var i = 0; i < c - this.length; i++) {
      r = r + f;
    }
    return r + this;
  };
  String.prototype.trim = function() {
    return this.replace(/^(\s|&nbsp;)+|(\s|&nbsp;)+$/g, '');
  };
  String.prototype.ltrim = function() {
    return this.replace(/^(\s|&nbsp;)+/, '');
  };
  String.prototype.rtrim = function() {
    return this.replace(/(\s|&nbsp;)+$/, '');
  };
  String.prototype.strip_tags = function(allowed) {
    allowed = (((allowed || '') + '').toLowerCase().match(/<[a-z][a-z0-9]*>/g) || []).join('');
    var tags = /<\/?([a-z][a-z0-9]*)\b[^>]*>/gi;
    var php = /<!--[\s\S]*?-->|<\?(?:php)?[\s\S]*?\?>/gi;
    return this.replace(php, '').replace(tags, function($0, $1) {
      return allowed.indexOf('<' + $1.toLowerCase() + '>') > -1 ? $0 : '';
    });
  };
  String.prototype.toDOM = function() {
    var s = function(a) {
      return a
        .replace(/&gt;/g, '>')
        .replace(/&lt;/g, '<')
        .replace(/&nbsp;/g, ' ')
        .replace(/&quot;/g, '"')
        .replace(/&#[0]?39;/g, "'")
        .replace(/&#92;/g, '\\')
        .replace(/&amp;/g, '&');
    };
    var t = function(a) {
      return a.replace(/ /g, '');
    };
    var u = function(a) {
      var b = document.createDocumentFragment();
      var c = a.indexOf(' ');
      if (c == -1) {
        var d = a.toLowerCase();
        b.appendChild(document.createElement(d));
      } else {
        d = t(a.substring(0, c)).toLowerCase();
        if (document.all && (d == 'input' || d == 'iframe')) {
          try {
            b.appendChild(document.createElement('<' + a + '/>'));
            return b;
          } catch (e) {}
        }
        a = a.substring(c + 1);
        b.appendChild(document.createElement(d));
        while (a.length > 0) {
          var e = a.indexOf('=');
          if (e >= 0) {
            var f = t(a.substring(0, e)).toLowerCase();
            var g = a.indexOf('"');
            a = a.substring(g + 1);
            g = a.indexOf('"');
            var h = s(a.substring(0, g));
            a = a.substring(g + 2);
            if (document.all && f == 'style') {
              b.lastChild.style.cssText = h;
            } else if (f == 'class') {
              b.lastChild.className = h;
            } else {
              b.lastChild.setAttribute(f, h);
            }
          } else {
            break;
          }
        }
      }
      return b;
    };
    var v = function(a, b, c) {
      var d = a;
      var e = b;
      c = c.toLowerCase();
      var f = e.indexOf('</' + c + '>');
      d = d.concat(e.substring(0, f));
      e = e.substring(f);
      while (d.indexOf('<' + c) != -1) {
        d = d.substring(d.indexOf('<' + c));
        d = d.substring(d.indexOf('>') + 1);
        e = e.substring(e.indexOf('>') + 1);
        f = e.indexOf('</' + c + '>');
        d = d.concat(e.substring(0, f));
        e = e.substring(f);
      }
      return b.length - e.length;
    };
    var w = function(a) {
      var b = document.createDocumentFragment();
      while (a && a.length > 0) {
        var c = a.indexOf('<');
        if (c == -1) {
          a = s(a);
          b.appendChild(document.createTextNode(a));
          a = null;
        }
        if (c > 0) {
          var d = s(a.substring(0, c));
          b.appendChild(document.createTextNode(d));
          a = a.substring(c);
        }
        if (c == 0) {
          var e = a.indexOf('<!--');
          if (e == 0) {
            var f = a.indexOf('-->');
            var g = a.substring(4, f);
            g = s(g);
            b.appendChild(document.createComment(g));
            a = a.substring(f + 3);
          } else {
            var h = a.indexOf('>');
            if (a.substring(h - 1, h) == '/') {
              var i = a.indexOf('/>');
              var j = a.substring(1, i);
              b.appendChild(u(j));
              a = a.substring(i + 2);
            } else {
              var k = a.indexOf('>');
              var l = a.substring(1, k);
              var m = document.createDocumentFragment();
              m.appendChild(u(l));
              a = a.substring(k + 1);
              var n = a.substring(0, a.indexOf('</'));
              a = a.substring(a.indexOf('</'));
              if (n.indexOf('<') != -1) {
                var o = m.lastChild.nodeName;
                var p = v(n, a, o);
                n = n.concat(a.substring(0, p));
                a = a.substring(p);
              }
              a = a.substring(a.indexOf('>') + 1);
              m.lastChild.appendChild(w(n));
              b.appendChild(m);
            }
          }
        }
      }
      return b;
    };
    return w(this);
  };
  String.prototype.toDate = function() {
    if (this === '') {
      return null;
    }
    let d = new Date(),
      hs = /^(today|tomorrow|yesterday|((([0-9]{4,4})[\-\/]([0-9]{1,2})[\-\/]([0-9]{1,2}))?([T\s]{0,}(([0-9]{1,2}):([0-9]{1,2})(:([0-9]{1,2}))?))?))([\s]{0,}([\+\-]{0,})[\s]{0,}([0-9]{0,}))?$/.exec(this);
    if (hs) {
      if (typeof hs[4] !== 'undefined') {
        d.setFullYear(floatval(hs[4]), floatval(hs[5]) - 1, floatval(hs[6]));
      }
      if (typeof hs[9] !== 'undefined') {
        if (typeof hs[13] === 'undefined') {
          d.setHours(floatval(hs[9]), floatval(hs[10]));
        } else {
          d.setHours(floatval(hs[9]), floatval(hs[10]), floatval(hs[12]));
        }
      }
      if (hs[1] == 'yesterday') {
        d.setDate(d.getDate() - 1);
      } else if (hs[1] == 'tomorrow') {
        d.setDate(d.getDate() + 1);
      }
      if (hs[14] == '+' && floatval(hs[15]) > 0) {
        d.setDate(d.getDate() + floatval(hs[15]));
      } else if (hs[14] == '-' && floatval(hs[15]) > 0) {
        d.setDate(d.getDate() - floatval(hs[15]));
      }
      return d;
    } else {
      return null;
    }
  };
  Number.prototype.format = function(decimals, dec_point, thousands_sep) {
    decimals = isNaN((decimals = Math.abs(decimals))) ? 2 : decimals;
    dec_point = dec_point == undefined ? '.' : dec_point;
    thousands_sep = thousands_sep == undefined ? ',' : thousands_sep;
    var n = this,
      s = n < 0 ? '-' : '',
      i = String(parseInt((n = Math.abs(Number(n) || 0).toFixed(decimals)))),
      j = (j = i.length) > 3 ? j % 3 : 0;
    return (s + (j ? i.substr(0, j) + thousands_sep : '') + i.substr(j).replace(/(\d{3})(?=\d)/g, '$1' + thousands_sep) + (decimals ? dec_point + Math.abs(n - i).toFixed(decimals).slice(2) : ''));
  };
  document.viewport = {
    getWidth: function() {
      return (
        document.documentElement.clientWidth ||
        document.body.clientWidth ||
        self.innerWidth
      );
    },
    getHeight: function() {
      return (
        document.documentElement.clientHeight ||
        document.body.clientHeight ||
        self.innerHeight
      );
    },
    getscrollTop: function() {
      return (
        window.pageYOffset ||
        document.documentElement.scrollTop ||
        document.body.scrollTop
      );
    },
    getscrollLeft: function() {
      return (
        window.pageXOffset ||
        document.documentElement.scrollLeft ||
        document.body.scrollLeft
      );
    }
  };
  document.css = function(css, id) {
    var style = document.createElement('style');
    if (id) {
      style.id = 'css_' + id;
      if ($E('css_' + id)) {
        $E('css_' + id).parentNode.removeChild($E('css_' + id));
      }
    }
    if (css !== null) {
      if (style.styleSheet) {
        style.styleSheet.cssText = css;
      } else {
        style.appendChild(document.createTextNode(css));
      }
      document.getElementsByTagName('head')[0].appendChild(style);
    }
  };
  Object.extend = function(d, s) {
    for (var property in s) {
      d[property] = s[property];
    }
    return d;
  };
  Object.extend(Object, {
    isObject: function(o) {
      return typeof o == 'object';
    },
    isFunction: function(o) {
      return typeof o == 'function';
    },
    isString: function(o) {
      return typeof o == 'string';
    },
    isNumber: function(o) {
      return typeof o == 'number';
    },
    isNull: function(o) {
      return typeof o == 'undefined';
    },
    isGElement: function(o) {
      return (
        o != null && typeof o == 'object' && 'Ready' in o && 'element' in o
      );
    },
    toArray: function(o) {
      var prop,
        result = [];
      for (prop in o) {
        result.push(o[prop]);
      }
      return result;
    }
  });
  window.GClass = {
    create: function() {
      return function() {
        this.initialize.apply(this, arguments);
      };
    }
  };
  window.GNative = GClass.create();
  GNative.prototype = {
    initialize: function() {
      this.elem = null;
    },
    Ready: function(f) {
      var s = this;
      var p = function() {
        if (domloaded && s.element()) {
          f.call($G(s.elem));
        } else {
          window.setTimeout(p, 10);
        }
      };
      p();
    },
    after: function(e) {
      var p = this.parentNode;
      if (this.nextSibling == null) {
        p.appendChild(e);
      } else {
        p.insertBefore(e, this.nextSibling);
      }
      return e;
    },
    before: function(e) {
      var p = this.parentNode;
      if (p.firstChild == this) {
        p.appendChild(e);
      } else {
        p.insertBefore(e, this);
      }
      return e;
    },
    insert: function(e) {
      e = $G(e);
      this.appendChild(e);
      return e;
    },
    copy: function(o) {
      return $G(this.cloneNode(o || true));
    },
    replace: function(e) {
      var p = this.parentNode;
      p.insertBefore(e, this.nextSibling);
      p.removeChild(this);
      return $G(e);
    },
    remove: function() {
      if (this.element()) {
        this.parentNode.removeChild(this);
      }
      return this;
    },
    setHTML: function(o) {
      try {
        this.innerHTML = o;
      } catch (e) {
        o = o
          .replace(/[\r\n\t]/g, '')
          .replace(/<script[^>]*>.*?<\/script>/gi, '');
        this.appendChild(o.toDOM());
      }
      return this;
    },
    getTop: function() {
      return this.viewportOffset().top;
    },
    getLeft: function() {
      return this.viewportOffset().left;
    },
    getWidth: function() {
      return this.getDimensions().width;
    },
    getHeight: function() {
      return this.getDimensions().height;
    },
    getClientWidth: function() {
      return (
        this.clientWidth -
        parseInt(this.getStyle('paddingLeft')) -
        parseInt(this.getStyle('paddingRight'))
      );
    },
    getClientHeight: function() {
      return (
        this.clientHeight -
        parseInt(this.getStyle('paddingTop')) -
        parseInt(this.getStyle('paddingBottom'))
      );
    },
    viewportOffset: function() {
      var t = 0,
        l = 0,
        p = this;
      while (p) {
        t += parseInt(p.offsetTop);
        l += parseInt(p.offsetLeft);
        p = p.offsetParent;
      }
      if (this.getBoundingClientRect) {
        return {top: t, left: this.getBoundingClientRect().left};
      } else {
        return {top: t, left: l};
      }
    },
    getDimensions: function() {
      var ow, oh;
      if (this == document) {
        ow = Math.max(
          Math.max(
            document.body.scrollWidth,
            document.documentElement.scrollWidth
          ),
          Math.max(
            document.body.offsetWidth,
            document.documentElement.offsetWidth
          ),
          Math.max(
            document.body.clientWidth,
            document.documentElement.clientWidth
          )
        );
        oh = Math.max(
          Math.max(
            document.body.scrollHeight,
            document.documentElement.scrollHeight
          ),
          Math.max(
            document.body.offsetHeight,
            document.documentElement.offsetHeight
          ),
          Math.max(
            document.body.clientHeight,
            document.documentElement.clientHeight
          )
        );
      } else {
        var d = this.getStyle('display');
        if (d != 'none' && d !== null) {
          ow = this.offsetWidth;
          oh = this.offsetHeight;
        } else {
          var s = this.style;
          var ov = s.visibility;
          var op = s.position;
          var od = s.display;
          s.visibility = 'hidden';
          s.position = 'absolute';
          s.display = 'block';
          ow = this.clientWidth;
          oh = this.clientHeight;
          s.display = od;
          s.position = op;
          s.visibility = ov;
        }
      }
      return {width: ow, height: oh};
    },
    getOffsetParent: function() {
      var e = this.offsetParent;
      if (!e) {
        e = this.parentNode;
        while (e != document.body && e.style.position == 'static') {
          e = e.parentNode;
        }
      }
      return GElement(e);
    },
    getCaretPosition: function() {
      if (document.selection) {
        var range = document.selection.createRange(),
          textLength = range.text.length;
        range.moveStart('character', -this.value.length);
        var caretAt = range.text.length;
        return {start: caretAt, end: caretAt + textLength};
      } else if (this.selectionStart || this.selectionStart == '0') {
        return {start: this.selectionStart, end: this.selectionEnd};
      }
    },
    setCaretPosition: function(start, length) {
      if (this.setSelectionRange) {
        this.focus();
        this.setSelectionRange(start, start + length);
      } else if (this.createTextRange) {
        var range = this.createTextRange();
        range.collapse(true);
        range.moveEnd('character', start + length);
        range.moveStart('character', start);
        range.select();
      }
      return this;
    },
    getStyle: function(s) {
      s = s == 'float' && this.currentStyle ? 'styleFloat' : s;
      s = s == 'borderColor' ? 'borderBottomColor' : s;
      var v = this.currentStyle ? this.currentStyle[s] : null;
      v = !v && window.getComputedStyle ? document.defaultView.getComputedStyle(this, null).getPropertyValue(s.replace(/([A-Z])/g, '-$1').toLowerCase()) : v;
      if (s == 'opacity') {
        return Object.isNull(v) ? 100 : floatval(v) * 100;
      } else {
        return v;
      }
    },
    setStyle: function(p, v) {
      if (p == 'opacity') {
        if (window.ActiveXObject) {
          this.style.filter = 'alpha(opacity=' + v * 100 + ')';
        }
        this.style.opacity = v;
      } else if (p == 'float' || p == 'styleFloat' || p == 'cssFloat') {
        if (Object.isNull(this.style.styleFloat)) {
          this.style['cssFloat'] = v;
        } else {
          this.style['styleFloat'] = v;
        }
      } else if (
        p == 'backgroundColor' &&
        this.tagName.toLowerCase() == 'iframe'
      ) {
        if (document.all) {
          this.contentWindow.document.bgColor = v;
        } else {
          this.style.backgroundColor = v;
        }
      } else if (p == 'borderColor') {
        this.style.borderLeftColor = v;
        this.style.borderTopColor = v;
        this.style.borderRightColor = v;
        this.style.borderBottomColor = v;
      } else {
        this.style[p] = v;
      }
      return this;
    },
    center: function() {
      var size = this.getDimensions();
      if (this.getStyle('position') == 'fixed') {
        this.style.top = (document.viewport.getHeight() - size.height) / 2 + 'px';
        this.style.left = (document.viewport.getWidth() - size.width) / 2 + 'px';
      } else {
        this.style.top = document.viewport.getscrollTop() + (document.viewport.getHeight() - size.height) / 2 + 'px';
        this.style.left = document.viewport.getscrollLeft() + (document.viewport.getWidth() - size.width) / 2 + 'px';
      }
      return this;
    },
    get: function(p) {
      try {
        return this.getAttribute(p);
      } catch (e) {
        return null;
      }
    },
    set: function(p, v) {
      try {
        this.setAttribute(p, v);
      } catch (e) {}
      return this;
    },
    hasClass: function(v) {
      var vs = v.split(' ');
      var cs = this.className.split(' ');
      for (var c = 0; c < cs.length; c++) {
        for (v = 0; v < vs.length; v++) {
          if (vs[v] != '' && vs[v] == cs[c]) {
            return vs[v];
          }
        }
      }
      return false;
    },
    addClass: function(v) {
      if (this.className || this.className === '') {
        if (!v) {
          this.className = '';
        } else {
          var rm = v.split(' '),
            cs = [];
          forEach(this.className.split(' '), function(c) {
            if (c !== '' && rm.indexOf(c) == -1) {
              cs.push(c);
            }
          });
          cs.push(v);
          this.className = cs.join(' ');
        }
      }
      return this;
    },
    removeClass: function(v) {
      if (this.className || this.className === '') {
        var rm = v.split(' ');
        var cs = [];
        forEach(this.className.split(' '), function(c) {
          if (c !== '' && rm.indexOf(c) == -1) {
            cs.push(c);
          }
        });
        this.className = cs.join(' ');
      }
      return this;
    },
    replaceClass: function(source, replace) {
      if (this.className || this.className === '') {
        var rm = (replace + ' ' + source).split(' ');
        var cs = [];
        forEach(this.className.split(' '), function(c) {
          if (c !== '' && rm.indexOf(c) == -1) {
            cs.push(c);
          }
        });
        cs.push(replace);
        this.className = cs.join(' ');
      }
      return this;
    },
    hide: function() {
      this.display = this.getStyle('display');
      this.setStyle('display', 'none');
      return this;
    },
    show: function() {
      if (this.getStyle('display') == 'none') {
        this.setStyle('display', 'block');
      }
      return this;
    },
    visible: function() {
      return this.getStyle('display') != 'none';
    },
    toggle: function() {
      if (this.visible()) {
        this.hide();
      } else {
        this.show();
      }
      return this;
    },
    nextNode: function() {
      var n = this;
      do {
        n = n.nextSibling;
      } while (n && n.nodeType != 1);
      return n;
    },
    previousNode: function() {
      var p = this;
      do {
        p = p.previousSibling;
      } while (p && p.nodeType != 1);
      return p;
    },
    firstNode: function() {
      var p = this.firstChild;
      do {
        p = p.nextSibling;
      } while (p && p.nodeType != 1);
      return p;
    },
    nextTab: function() {
      var tag,
        result,
        self = this,
        check = null;
      forEach(document.forms, function() {
        return forEach(this.getElementsByTagName('*'), function() {
          if (this == self.elem) {
            check = this;
          } else if (check != null) {
            if (
              this.tabIndex >= 0 &&
              this.disabled != true &&
              this.style.display != 'none' &&
              this.offsetParent != null
            ) {
              result = this;
              return true;
            }
          }
        });
      });
      return result;
    },
    sendKey: function(keyCode) {
      return this.callEvent('keypress', {keyCode: keyCode});
    },
    callEvent: function(t, params) {
      var evt;
      if (document.createEvent) {
        evt = document.createEvent('Events');
        evt.initEvent(t, true, true);
        for (var prop in params) {
          evt[prop] = params[prop];
        }
        this.dispatchEvent(evt);
      } else if (document.createEventObject) {
        evt = document.createEventObject();
        for (var prop in params) {
          evt[prop] = params[prop];
        }
        this.fireEvent('on' + t, evt);
      }
      return this;
    },
    addEvent: function(t, f, c) {
      var ts = t.split(/[\s,]/g),
        input = this;
      forEach(ts, function(e) {
        if (input.addEventListener) {
          c = !c ? false : c;
          input.addEventListener(e, f, c);
        } else if (input.attachEvent) {
          input['e' + e + f] = f;
          input[e + f] = function() {
            input['e' + e + f](window.event);
          };
          input.attachEvent('on' + e, input[e + f]);
        }
      });
      return this;
    },
    removeEvent: function(t, f, c) {
      var ts = t.split(/[\s,]/g),
        input = this;
      forEach(ts, function(e) {
        if (input.removeEventListener) {
          c = !c ? false : c;
          input.removeEventListener(e == 'mousewheel' && window.gecko ? 'DOMMouseScroll' : e, f, c);
        } else if (input.detachEvent) {
          input.detachEvent('on' + e, input[e + f]);
          input['e' + e + f] = null;
          input[e + f] = null;
        }
      });
      return this;
    },
    highlight: function(o) {
      this.addClass('highlight');
      var self = this;
      window.setTimeout(function() {
        self.removeClass('highlight');
      }, 1);
      return this;
    },
    fadeIn: function(oncomplete) {
      this.addClass('fadein');
      var self = this;
      window.setTimeout(function() {
        self.removeClass('fadein');
        if (Object.isFunction(oncomplete)) {
          oncomplete.call(this);
        }
      }, 1000);
      return this;
    },
    fadeOut: function(oncomplete) {
      this.addClass('fadeout');
      var self = this;
      window.setTimeout(function() {
        self.removeClass('fadeout');
        if (Object.isFunction(oncomplete)) {
          oncomplete.call(this);
        }
      }, 1000);
      return this;
    },
    setValue: function(v) {
      function _find(e, a) {
        var s = e.getElementsByTagName('option');
        for (var i = 0; i < s.length; i++) {
          if (s[i].value == a) {
            return i;
          }
        }
        return -1;
      }
      if (this.time) {
        this.time.setTime(v);
      } else if (this.calendar) {
        this.value = v;
      } else if (this.datalist) {
        this.selectedIndex = v;
      } else {
        v = decodeURIComponent(v);
        var t = this.tagName.toLowerCase();
        if (t == 'img') {
          this.src = v;
        } else if (t == 'select') {
          this.selectedIndex = _find(this, v);
        } else if (t == 'input') {
          if (this.type == 'checkbox' || this.type == 'radio') {
            this.checked = v == this.value;
          } else {
            this.value = v.unentityify();
          }
        } else if (t == 'textarea') {
          this.value = v.unentityify();
        } else {
          this.setHTML(v);
        }
      }
      return this;
    },
    getText: function() {
      if (!Object.isNull(this.elem.selectedIndex)) {
        if (this.elem.selectedIndex == -1) {
          return null;
        }
        return this.elem.options[this.elem.selectedIndex].text;
      } else if (this.elem.innerHTML) {
        return this.elem.innerHTML;
      }
      return this.elem.value;
    },
    setOptions: function(json, value) {
      if (this.tagName.toLowerCase() == 'select') {
        for (var i = this.options.length; i > 0; i--) {
          this.removeChild(this.options[i - 1]);
        }
        var selectedIndex = 0;
        if (json) {
          var i = 0;
          for (var key in json) {
            if (key == value) {
              selectedIndex = i;
            }
            var option = document.createElement('option');
            option.innerHTML = json[key];
            option.value = key;
            this.appendChild(option);
            i++;
          }
        }
        this.selectedIndex = selectedIndex;
      }
    },
    getSelectedText: function() {
      var text = '';
      if (this.selectionStart) {
        if (this.selectionStart != this.selectionEnd) {
          text = this.value.substring(this.selectionStart, this.selectionEnd);
        }
      } else if (document.selection) {
        var range = document.selection.createRange();
        if (range.parentElement() === this) {
          text = range.text;
        }
      }
      return text;
    },
    setSelectedText: function(value) {
      if (this.selectionStart) {
        if (this.selectionStart != this.selectionEnd) {
          this.value =
            this.value.substring(0, this.selectionStart) +
            value +
            this.value.substring(this.selectionEnd);
        }
      } else {
        var range = document.selection.createRange();
        if (range.parentElement() === this) {
          range.text = value;
        }
      }
      return this;
    },
    findLabel: function() {
      var result = null,
        id = this.id;
      forEach(document.getElementsByTagName('label'), function() {
        if (this.htmlFor != '' && this.htmlFor == id) {
          result = this;
          return true;
        }
      });
      return result;
    },
    element: function() {
      return Object.isString(this.elem) ?
        document.getElementById(this.elem) :
        this.elem;
    },
    elems: function(tagname) {
      return this.getElementsByTagName(tagname);
    },
    create: function(tagname, o) {
      var v;
      if (tagname == 'iframe' || tagname == 'input') {
        var n = o.name || o.id || '';
        var i = o.id || o.name || '';
        if (window.ActiveXObject) {
          try {
            if (tagname == 'iframe') {
              v = document.createElement(
                '<iframe id="' + i + '" name="' + n + '" scrolling="no" />'
              );
            } else {
              v = document.createElement('<input id="' + i + '" name="' + n + '" type="' + o.type + '" />');
            }
          } catch (e) {
            v = document.createElement(tagname);
            v.name = n;
            v.id = i;
          }
        } else {
          v = document.createElement(tagname);
          v.name = n;
          v.id = i;
        }
      } else {
        v = document.createElement(tagname);
      }
      if (this.elem) {
        this.appendChild(v);
      }
      for (var p in o) {
        v[p] = o[p];
      }
      return $G(v);
    },
    msgBox: function(value, className, autohide, id) {
      var parent,
        parent_id = id ? id + '_parent' : 'body_msg_div_parent';
      if ($E(parent_id)) {
        parent = $E(parent_id);
      } else {
        parent = document.createElement('div');
        parent.id = parent_id;
        parent.className = 'msgbox';
        this.elem.appendChild(parent);
      }
      if (value && value != '') {
        var div, innerDiv;
        if (id && $E(id)) {
          innerDiv = $E(id);
          div = innerDiv.parentNode;
        } else {
          div = document.createElement('div');
          innerDiv = document.createElement('div');
          if (id) {
            innerDiv.id = id;
          }
          var span = document.createElement('span');
          span.innerHTML = '&times;';
          span.className = 'closebtn';
          div.appendChild(span);
          div.appendChild(innerDiv);
          parent.appendChild(div);
        }
        div.className = 'alert ' + (className || 'message');
        innerDiv.innerHTML = value;
      }
      forEach(parent.getElementsByClassName('closebtn'), function() {
        if (this.onclick === null) {
          var span = this;
          span.onclick = function() {
            var parent = this.parentNode;
            parent.style.opacity = '0';
            if (this.timer) {
              clearTimeout(this.timer);
            }
            setTimeout(function() {
              parent.remove();
            }, 600);
          };
          if (typeof autohide === 'undefined' || autohide === true) {
            span.timer = setTimeout(function() {
              span.click();
            }, 3000);
          }
        }
      });
    },
    valid: function(className) {
      if (this.ret) {
        if (this.ret.hasClass('validationResult')) {
          this.ret.remove();
          this.ret = false;
        } else {
          this.ret.replaceClass('invalid', 'valid');
          this.ret.innerHTML = this.retDef ? this.retDef : '';
        }
      }
      this.replaceClass(
        'invalid wait',
        'valid' + (className ? ' ' + className : '')
      );
      return this;
    },
    invalid: function(value, className) {
      if (!this.ret) {
        if (
          typeof this.dataset !== 'undefined' &&
          typeof this.dataset.result === 'string' &&
          this.dataset.result !== '' &&
          $E(this.dataset.result)
        ) {
          this.ret = $G(this.dataset.result);
        } else {
          var id = this.id || this.name;
          if ($E('result_' + id)) {
            this.ret = $G('result_' + id);
          }
        }
        if (this.ret && !this.retDef) {
          this.retDef = this.ret.innerHTML;
        }
      }
      if (this.ret) {
        if (value && value != '') {
          this.ret.innerHTML = value;
        }
        this.ret.replaceClass(
          'valid',
          'invalid' + (className ? ' ' + className : '')
        );
      }
      this.replaceClass('valid wait', 'invalid');
      return this;
    },
    reset: function() {
      if (this.ret) {
        if (this.ret.hasClass('validationResult')) {
          this.ret.remove();
          this.ret = false;
        } else {
          this.ret.replaceClass('invalid valid', '');
          this.ret.innerHTML = this.retDef ? this.retDef : '';
        }
      }
      this.replaceClass('invalid valid wait', '');
      return this;
    },
    init: function(e) {
      this.elem = e;
      var elem = this.element();
      if (!elem) {
        return this;
      } else {
        this.elem = elem;
        for (var p in this) {
          if (p != 'elements') {
            elem[p] = this[p];
          }
        }
        return elem;
      }
    }
  };
  var ajaxAccepts = {
    xml: 'application/xml, text/xml',
    html: 'text/html',
    text: 'text/plain',
    json: 'application/json, text/javascript',
    all: 'text/html, text/plain, application/xml, text/xml, application/json, text/javascript'
  };
  window.GAjax = GClass.create();
  GAjax.prototype = {
    initialize: function(options) {
      this.options = {
        method: 'post',
        cache: false,
        asynchronous: true,
        contentType: 'application/x-www-form-urlencoded',
        CORS: false,
        encoding: 'UTF-8',
        Accept: 'all',
        onTimeout: $K.emptyFunction,
        onError: $K.emptyFunction,
        onProgress: $K.emptyFunction,
        timeout: 0,
        loadingClass: 'show',
        headers: {}
      };
      for (var property in options) {
        this.options[property] = options[property];
      }
      this.options.method = this.options.method.toLowerCase();
      this.loader = null;
    },
    xhr: function() {
      return new XMLHttpRequest();
    },
    send: function(url, parameters, callback) {
      var self = this;
      this._xhr = this.xhr();
      this._abort = false;
      if (!Object.isNull(this._xhr)) {
        var option = this.options;
        if (option.method == 'get') {
          url += '?' + parameters;
          parameters = null;
        } else {
          parameters = parameters === null ? '' : parameters;
        }
        if (option.cache == false) {
          var match = /\?/;
          url += (match.test(url) ? '&' : '?') + new Date().getTime();
        }
        this._xhr.withCredentials = option.CORS;
        this._xhr.open(option.method, url, option.asynchronous);
        if (option.method == 'post') {
          this._xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
          this._xhr.setRequestHeader('Accept', ajaxAccepts[option.Accept]);
          if (option.contentType && option.encoding) {
            this._xhr.setRequestHeader('Content-Type', option.contentType + '; charset=' + option.encoding);
          }
        }
        for (var prop in option.headers) {
          this._xhr.setRequestHeader(prop, option.headers[prop]);
        }
        if (option.timeout > 0) {
          this._xhr.timeout = option.timeout;
          this._xhr.ontimeout = function(e) {
            self.hideLoading();
            option.onTimeout.bind(self);
          };
        }
        this._xhr.onreadystatechange = function() {
          if (self._xhr.readyState == 4) {
            self.hideLoading();
            window.clearTimeout(self.calltimeout);
            if (
              self._xhr.status == 200 &&
              !self._abort &&
              Object.isFunction(callback)
            ) {
              self.responseText = self._xhr.responseText;
              self.responseXML = self._xhr.responseXML;
              callback(self);
            } else {
              option.onError(self);
            }
          }
        };
        if (this._xhr.upload) {
          this._xhr.upload.onprogress = function(e) {
            if (e.lengthComputable) {
              option.onProgress.call(e, e.loaded, e.total);
            } else {
              option.onProgress.call(e, e.loaded, 0);
            }
          };
        }
        self.showLoading();
        this._xhr.send(parameters);
        if (!option.asynchronous) {
          window.clearTimeout(this.calltimeout);
          this.responseText = this._xhr.responseText;
          this.responseXML = this._xhr.responseXML;
        }
      }
      return this;
    },
    autoupdate: function(url, interval, getRequest, callback) {
      this._xhr = this.xhr();
      this.interval = interval * 1000;
      if (!Object.isNull(this._xhr)) {
        this.url = url;
        this.getRequest = getRequest;
        this.callback = callback;
        this._abort = false;
        this._getupdate();
      }
      return this;
    },
    _getupdate: function() {
      if (this._abort == false) {
        var parameters = null;
        var url = this.url;
        var option = this.options;
        if (Object.isFunction(this.getRequest)) {
          if (option.method == 'get') {
            url += '?' + this.getRequest();
          } else {
            parameters = this.getRequest();
          }
        }
        parameters = option.method == 'post' && parameters == null ? '' : parameters;
        if (option.cache == false) {
          var match = /\?/;
          url += (match.test(url) ? '&' : '?') + new Date().getTime();
        }
        var xhr = this._xhr;
        var temp = this;
        xhr.open(option.method, url, true);
        if (option.method == 'post') {
          xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
          xhr.setRequestHeader('Accept', ajaxAccepts[option.Accept]);
          if (option.contentType && option.encoding) {
            xhr.setRequestHeader('Content-Type', option.contentType + '; charset=' + option.encoding);
          }
        }
        for (var prop in option.headers) {
          this._xhr.setRequestHeader(prop, option.headers[prop]);
        }
        temp.showLoading();
        xhr.send(parameters);
        xhr.onreadystatechange = function() {
          if (xhr.readyState == 4 && xhr.status == 200) {
            temp.hideLoading();
            if (temp.callback) {
              temp.callback(xhr);
            }
            window.clearTimeout(temp.calltimeout);
            _nextupdate();
          }
        };
        var _nextupdate = function() {
          temp.timeinterval = window.setTimeout(
            temp._getupdate.bind(temp),
            temp.interval
          );
        };
        this.calltimeout = window.setTimeout(function() {
          window.clearTimeout(temp.timeinterval);
          xhr.abort();
          _nextupdate();
        }, this.interval);
      }
    },
    getRequestBody: function(pForm) {
      pForm = $E(pForm);
      var nParams = [];
      forEach(pForm.getElementsByTagName('*'), function() {
        if (!this.disabled) {
          var t = this.tagName.toLowerCase();
          if (t == 'input') {
            if (
              (this.checked == true && this.type == 'radio') ||
              (this.checked == true && this.type == 'checkbox') ||
              (this.type != 'radio' && this.type != 'checkbox')
            ) {
              nParams.push(this.name + '=' + this.value);
            }
          } else if (t == 'select') {
            nParams.push(this.name + '=' + this.value);
          } else if (t == 'textarea') {
            nParams.push(this.name + '=' + encodeURIComponent(this.innerHTML));
          }
        }
      });
      return nParams.join('&');
    },
    showLoading: function() {
      if (this.loading) {
        if (this.loading == 'wait' && this.center == false) {
          if (this.loader == null) {
            this.loader = new GLoading();
          }
          this.loader.show();
        } else if ($E(this.loading)) {
          this.wait = $G(this.loading);
          if (this.center) {
            this.wait.center();
          }
          this.wait.addClass(this.options.loadingClass);
        }
      }
      return this;
    },
    hideLoading: function() {
      if (this.loading) {
        if (this.loader) {
          this.loader.hide();
        } else if (this.wait) {
          this.wait.removeClass(this.options.loadingClass);
        }
      }
      return this;
    },
    initLoading: function(loading, center, c) {
      this.loading = loading;
      this.center = center;
      if (c) {
        this.options.loadingClass = c;
      }
      return this;
    },
    abort: function() {
      clearTimeout(this.timeinterval);
      this._abort = true;
      return this;
    }
  };
  var gform_id = 0;
  window.GForm = GClass.create();
  GForm.prototype = {
    initialize: function(frm, frmaction, loading, center, onbeforesubmit) {
      frm = $G(frm);
      if (frmaction) {
        frm.set('action', frmaction);
      }
      this.loader = null;
      this.submitButton = frm.querySelector('[type=submit]');
      this.loading = loading || this.submitButton || 'wait';
      this.center = center;
      this.onbeforesubmit = Object.isFunction(onbeforesubmit) ? onbeforesubmit : $K.resultFunction;
      var self = this;
      frm.onsubmit = function() {
        var loading = true;
        var ret = true;
        if (self.onbeforesubmit.call(this)) {
          forEach(this.querySelectorAll('input,textarea'), function(elem) {
            if (elem.srcObj) {
              var title = elem.srcObj.title,
                val = elem.value;
              if (elem.srcObj.pattern && val !== '' && !elem.srcObj.pattern.test(val)) {
                if (title == '' && elem.placeholder != '') {
                  title = elem.placeholder;
                }
                var label = elem.findLabel();
                title = trans('Invalid data').replace('XXX', label ? label.innerText : elem.name) + ' ' + title;
                title = title.strip_tags();
                elem.invalid(title);
                alert(title);
                elem.highlight().focus();
                elem.select();
                ret = false;
                return true;
              } else {
                elem.reset();
              }
            }
          });
          if (ret && Object.isFunction(self.callback)) {
            self.showLoading();
            var uploadCallback = function() {
              if (!loading) {
                try {
                  self.responseText = io.contentWindow.document.body ? io.contentWindow.document.body.innerHTML : null;
                  self.responseXML = io.contentWindow.document.XMLDocument ? io.contentWindow.document.XMLDocument : io.contentWindow.document;
                } catch (e) {}
                self.hideLoading();
                self.form.method = old_method;
                self.form.target = old_target;
                if (self.form.encoding) {
                  self.form.encoding = old_enctype;
                } else {
                  self.form.enctype = old_enctype;
                }
                window.setTimeout(function() {
                  io.removeEvent('load', uploadCallback);
                  io.remove();
                }, 1);
                window.setTimeout(function() {
                  self.callback(self);
                }, 1);
              }
            };
            var io = self.createIframe();
            io.addEvent('load', uploadCallback);
            var old_target = this.target || '';
            var old_method = this.method || 'post';
            var old_enctype = this.encoding ? this.encoding : this.enctype;
            if (this.encoding) {
              this.encoding = 'multipart/form-data';
            } else {
              this.enctype = 'multipart/form-data';
            }
            this.target = io.id;
            this.method = 'post';
            window.setTimeout(function() {
              loading = false;
              frm.submit();
            }, 50);
            ret = false;
          }
        } else {
          ret = false;
        }
        return ret;
      };
      frm.GForm = this;
      this.form = frm;
      $K.init(frm);
    },
    onsubmit: function(callback) {
      this.callback = callback;
      return this;
    },
    submit: function(callback) {
      var loading = true,
        old_target = null,
        old_method = null,
        self = this;
      this.showLoading();
      var uploadCallback = function() {
        if (!loading) {
          self.hideLoading();
          try {
            self.responseText = io.contentWindow.document.body ?
              io.contentWindow.document.body.innerHTML :
              null;
            self.responseXML = io.contentWindow.document.XMLDocument ?
              io.contentWindow.document.XMLDocument :
              io.contentWindow.document;
          } catch (e) {}
          self.form.method = old_method;
          self.form.target = old_target;
          window.setTimeout(function() {
            io.removeEvent('load', uploadCallback);
            io.remove();
          }, 1);
          window.setTimeout(function() {
            callback(self);
          }, 1);
        }
      };
      if (this.form.encoding) {
        this.form.encoding = 'multipart/form-data';
      } else {
        this.form.enctype = 'multipart/form-data';
      }
      var io = this.createIframe();
      io.addEvent('load', uploadCallback);
      old_target = this.form.target || '';
      old_method = this.form.method || 'post';
      this.form.target = io.id;
      this.form.method = 'post';
      window.setTimeout(function() {
        loading = false;
        self.form.submit();
      }, 50);
      return this;
    },
    createIframe: function() {
      var frameId = 'GForm_Submit_' + gform_id + '_' + (this.form.id || this.form.name),
        io = $G(document.body).create('iframe', {
          id: frameId,
          name: frameId
        });
      io.style = 'position:absolute;width:1px;height:1px;inset:-9999px;display:none;';
      gform_id++;
      return io;
    },
    showLoading: function() {
      if (this.submitButton) {
        this.submitButton.disabled = true;
      }
      if (this.loading && $E(this.loading)) {
        this.loading = $G(this.loading);
        if (this.center) {
          this.loading.center();
        }
        this.loading.addClass('show');
      }
      return this;
    },
    hideLoading: function() {
      if (this.loading && $E(this.loading)) {
        this.loading.removeClass('show');
      } else if (this.loader) {
        this.loader.removeClass('wait');
      }
      if (this.submitButton) {
        this.submitButton.disabled = false;
      }
      return this;
    },
    initLoading: function(loading, center) {
      this.loading = loading;
      this.center = center;
      return this;
    }
  };
  window.GModal = GClass.create();
  GModal.prototype = {
    initialize: function(options) {
      this.id = 'modaldiv';
      this.btnclose = 'btnclose';
      this.backgroundClass = 'modalbg';
      this.onhide = $K.emptyFunction;
      this.onclose = $K.emptyFunction;
      this.parentClass = 'hasparent';
      this.parent = null;
      for (var property in options) {
        this[property] = options[property];
      }
      var self = this;
      var checkESCkey = function(e) {
        if (GEvent.keyCode(e) == 27 || e.key == 'Escape' || e.key == 'Esc') {
          self.hide();
          GEvent.stop(e);
        }
      };
      var container_div = 'GModal_' + this.id;
      var doc = $G(document);
      doc.addEvent('keydown', checkESCkey);
      if (!$E(container_div)) {
        this.div = $G(doc.createElement('div'));
        this.div.id = container_div;
        doc.body.appendChild(this.div);
        this.div.onmousedown = function(e) {
          if (GEvent.element(e).id == container_div) {
            self.hide();
          }
        };
        var c = doc.createElement('div');
        this.div.appendChild(c);
        c.className = this.id;
        this.body = $G(doc.createElement('div'));
        c.appendChild(this.body);
        var s = doc.createElement('span');
        c.appendChild(s);
        s.className = this.btnclose;
        s.title = trans('Close');
        s.onclick = function() {
          self.hide();
        };
      } else {
        this.div = $G(container_div);
        this.body = $G(this.div.firstChild.firstChild);
      }
    },
    content: function() {
      return this.body;
    },
    show: function(value, className) {
      this.body.setHTML(value);
      this.overlay();
      if (className) {
        this.div.className = className;
      }
      if (this.parent) {
        var parent = $G(this.parent),
          vp = parent.viewportOffset(),
          dm = parent.getDimensions(),
          d = $G(document).getDimensions();
        this.div.style.right = (d.width - (vp.left + dm.width)) + 'px';
        this.div.addClass(this.parentClass);
      }
      var self = this;
      window.setTimeout(function() {
        self.div.addClass('show');
        if (self.parent) {
          self.div.style.top = (vp.top + dm.height + 10) + 'px';
        }
      }, 500);
      return this;
    },
    hide: function() {
      if (this.div.hasClass('show')) {
        var self = this;
        this.div.removeClass('show');
        this.iframe.fadeOut(function() {
          self._hide.call(self);
        });
        window.setTimeout(function() {
          self.div.className = '';
          self.div.style.top = null;
          self.div.style.right = null;
        }, 500);
      }
      return this;
    },
    overlay: function() {
      var frameId = 'iframe_' + this.div.id,
        self = this;
      if (!$E(frameId)) {
        var io = $G(document.body).create('iframe', {
          id: frameId,
          height: '100%',
          frameBorder: 0
        });
        io.setStyle('position', 'fixed');
        io.setStyle('zIndex', 999);
        io.className = this.backgroundClass;
        io.style.display = 'none';
      }
      this.iframe = $G(frameId);
      if (this.iframe.style.display == 'none') {
        this.iframe.style.left = '0px';
        this.iframe.style.top = '0px';
        this.iframe.style.display = 'block';
        this.iframe.fadeIn();
        $G(self.iframe.contentWindow.document).addEvent('click', function(e) {
          self.hide();
        });
        var d = $G(document).getDimensions();
        this.iframe.style.height = d.height + 'px';
        this.iframe.style.width = '100%';
      }
      return this;
    },
    _hide: function() {
      this.iframe.style.display = 'none';
      this.body.innerHTML = '';
      if (Object.isFunction(this.onclose)) {
        this.onclose.call(this);
      }
    }
  };
  window.GFx = $K.emptyFunction;
  GFx.prototype = {
    _run: function() {
      this.playing = true;
      this.step();
    },
    stop: function() {
      this.playing = false;
      this.options.onComplete.call(this.Element);
    }
  };
  window.GScroll = GClass.create();
  GScroll.prototype = Object.extend(new GFx(), {
    initialize: function(container, scroller) {
      this.options = {
        speed: 20,
        duration: 1,
        pauseit: 1,
        scrollto: 'top'
      };
      this.container = $G(container);
      this.scroller = $G(scroller);
      this.container.addEvent('mouseover', function() {
        this.rel = 'pause';
      });
      this.container.addEvent('mouseout', function() {
        this.rel = 'play';
      });
      this.container.rel = 'play';
      this.playing = false;
    },
    play: function(options) {
      for (var property in options) {
        this.options[property] = options[property];
      }
      this.scrollerTop = 0;
      this.scrollerLeft = 0;
      this._run();
      return this;
    },
    step: function() {
      if (this.container.rel == 'play' || this.options.pauseit != 1) {
        var size = this.container.getDimensions();
        if (this.options.scrollto == 'bottom') {
          this.scrollerTop =
            this.scrollerTop > size.height ?
              0 - this.scroller.getHeight() :
              this.scrollerTop + this.options.duration;
          this.scroller.style.top = this.scrollerTop + 'px';
        } else if (this.options.scrollto == 'left') {
          this.scrollerLeft =
            this.scrollerLeft + this.scroller.getWidth() < 0 ?
              size.width :
              this.scrollerLeft - this.options.duration;
          this.scroller.style.left = this.scrollerLeft + 'px';
        } else if (this.options.scrollto == 'right') {
          this.scrollerLeft =
            this.scrollerLeft > size.width ?
              0 - this.scroller.getWidth() :
              this.scrollerLeft + this.options.duration;
          this.scroller.style.left = this.scrollerLeft + 'px';
        } else {
          this.scrollerTop =
            this.scrollerTop + this.scroller.getHeight() < 0 ?
              size.height :
              this.scrollerTop - this.options.duration;
          this.scroller.style.top = this.scrollerTop + 'px';
        }
      }
      this.timer = window.setTimeout(this.step.bind(this), this.options.speed);
    }
  });
  window.preload = GClass.create();
  preload.prototype = {
    initialize: function(img, onComplete) {
      var temp = new Image();
      if (img.src) {
        temp.src = img.src;
        temp.original = img;
      } else {
        temp.src = img;
      }
      var _preload = function() {
        if (temp.complete) {
          onComplete.call(temp);
        } else {
          window.setTimeout(_preload, 30);
        }
      };
      window.setTimeout(_preload, 30);
    }
  };
  window.GEvent = {
    isButton: function(e, code) {
      var button;
      e = window.event || e;
      if (e.which == null) {
        button = e.button < 2 ? 0 : e.button == 4 ? 1 : 2;
      } else {
        button = e.which < 2 ? 0 : e.which == 2 ? 1 : 2;
      }
      return button === code;
    },
    isLeftClick: function(e) {
      return GEvent.isButton(e, 0);
    },
    isMiddleClick: function(e) {
      return GEvent.isButton(e, 1);
    },
    isRightClick: function(e) {
      return GEvent.isButton(e, 2);
    },
    isCtrlKey: function(e) {
      return window.event ? window.event.ctrlKey : e.ctrlKey;
    },
    isShiftKey: function(e) {
      return window.event ? window.event.shiftKey : e.shiftKey;
    },
    isAltKey: function(e) {
      return window.event ? window.event.altKey : e.altKey;
    },
    element: function(e) {
      e = window.event || e;
      if (e) {
        var node = e.target ? e.target : e.srcElement;
        return e.nodeType == 3 ? node.parentNode : node;
      }
      return null;
    },
    keyCode: function(e) {
      e = window.event || e;
      return e.which || e.keyCode;
    },
    stop: function(e) {
      e = window.event || e;
      if (e.stopPropagation) {
        e.stopPropagation();
      }
      e.cancelBubble = true;
      if (e.preventDefault) {
        e.preventDefault();
      }
      e.returnValue = false;
    },
    pointer: function(e) {
      e = window.event || e;
      return {
        x: e.pageX ||
          e.clientX +
          (document.documentElement.scrollLeft || document.body.scrollLeft),
        y: e.pageY ||
          e.clientY +
          (document.documentElement.scrollTop || document.body.scrollTop)
      };
    },
    pointerX: function(e) {
      return GEvent.pointer(e).x;
    },
    pointerY: function(e) {
      return GEvent.pointer(e).y;
    }
  };
  window.Cookie = {
    get: function(k) {
      var v = document.cookie.match(
        '(?:^|;)\\s*' + k.preg_quote() + '=([^;]*)'
      );
      return v ? decodeURIComponent(v[1]) : null;
    },
    set: function(k, v, options) {
      var _options = {
        path: false,
        domain: false,
        duration: false,
        secure: false
      };
      for (var property in options) {
        _options[property] = options[property];
      }
      v = encodeURIComponent(v);
      if (_options.domain) {
        v += '; domain=' + _options.domain;
      }
      if (_options.path) {
        v += '; path=' + _options.path;
      }
      if (_options.duration) {
        var date = new Date();
        date.setTime(date.getTime() + _options.duration * 24 * 60 * 60 * 1000);
        v += '; expires=' + date.toGMTString();
      }
      if (_options.secure) {
        v += '; secure';
      }
      document.cookie = k + '=' + v;
      return this;
    },
    remove: function(k) {
      Cookie.set(k, '', {
        duration: -1
      });
      return this;
    }
  };
  window.GLoading = GClass.create();
  GLoading.prototype = {
    initialize: function() {
      this.waittime = 0;
      this.loading = null;
    },
    show: function() {
      window.clearTimeout(this.waittime);
      if (this.loading == null && !$E('wait')) {
        var div = document.createElement('dl');
        div.id = 'wait';
        div.innerHTML = '<dt></dt><dd></dd>';
        document.body.appendChild(div);
      }
      this.loading = $G('wait');
      this.loading.addClass('show');
      return this;
    },
    hide: function() {
      if (this.loading) {
        this.loading.replaceClass('show', 'complete');
        var self = this;
        this.waittime = window.setTimeout(function() {
          self.loading.removeClass('wait show complete');
        }, 500);
      }
      return this;
    }
  };
  window.GValidator = GClass.create();
  GValidator.prototype = {
    initialize: function(input, events, validtor, action, callback, form) {
      this.timer = 0;
      this.req = new GAjax();
      this.interval = 1000;
      this.input = $G(input);
      this.input.Validator = this;
      this.title = this.input.get('title');
      this.validtor = validtor;
      this.action = action;
      this.callback = callback;
      this.form = form;
      var temp = this;
      if (form && form !== '') {
        form = $G(form);
        form.addEvent('submit', function() {
          temp.abort();
        });
      }
      forEach(events.split(','), function() {
        temp.input.addEvent(this, temp.validate.bind(temp));
      });
    },
    validate: function() {
      this.abort();
      var ret = Object.isFunction(this.validtor) ?
        this.validtor.call(this.input) :
        true;
      if (this.form && ret && this.action && ret !== '' && this.action !== '') {
        this.input.addClass('wait');
        var temp = this;
        this.timer = window.setTimeout(function() {
          temp.req.send(temp.action, ret, function(xhr) {
            temp.input.removeClass('wait');
            if (temp.callback) {
              ret = temp.callback.call(temp, xhr);
            } else {
              ret = xhr.responseText;
            }
            if (!ret || ret == '') {
              temp.valid();
            } else {
              try {
                ret = eval(ret);
              } catch (e) {}
              temp.invalid(ret);
            }
          });
        }, this.interval);
      }
    },
    abort: function() {
      window.clearTimeout(this.timer);
      this.req.abort();
      this.input.reset();
      return this;
    },
    interval: function(value) {
      this.interval = value;
      return this;
    },
    valid: function(className) {
      this.input.valid(className);
    },
    invalid: function(value, className) {
      this.input.invalid(value, className);
    },
    reset: function() {
      this.input.set('title', this.title);
      this.input.reset();
    }
  };
  window.GDrag = GClass.create();
  GDrag.prototype = {
    initialize: function(src, options) {
      this.options = {
        beginDrag: $K.emptyFunction,
        moveDrag: $K.emptyFunction,
        endDrag: $K.emptyFunction,
        srcOnly: true
      };
      for (var property in options) {
        this.options[property] = options[property];
      }
      this.src = $G(src);
      var self = this;

      function _mousemove(e) {
        self.mousePos = GEvent.pointer(e);
        self.options.moveDrag.call(self);
      }

      function cancelEvent(e) {
        GEvent.stop(e);
      }

      function _mouseup(e) {
        document.removeEvent('mouseup', _mouseup);
        document.removeEvent('mousemove', _mousemove);
        document.removeEvent('selectstart dragstart', cancelEvent);
        if (self.src.releaseCapture) {
          self.src.releaseCapture();
        }
        self.mousePos = GEvent.pointer(e);
        GEvent.stop(e);
        self.options.endDrag.call(self.src);
      }

      function _mousedown(e) {
        var delay,
          src = GEvent.element(e),
          temp = this;

        function _cancelClick() {
          window.clearTimeout(delay);
          this.removeEvent('mouseup', _cancelClick);
        }
        if ((!self.options.srcOnly || src == self.src) && GEvent.isLeftClick(e)) {
          GEvent.stop(e);
          self.mousePos = GEvent.pointer(e);
          if (this.setCapture) {
            this.setCapture();
          }
          delay = window.setTimeout(function() {
            document.addEvent('mouseup', _mouseup);
            document.addEvent('mousemove', _mousemove);
            document.addEvent('selectstart dragstart', cancelEvent);
            self.options.beginDrag.call(self);
          }, 100);
          temp.addEvent('mouseup', _cancelClick);
        } else if ($K.isMobile()) {
          src.callEvent('click');
        }
      }
      this.src.addEvent('mousedown', _mousedown);

      function touchHandler(e) {
        var touches = e.changedTouches,
          first = touches[0],
          type = '';
        switch (e.type) {
          case 'touchstart':
            type = 'mousedown';
            break;
          case 'touchmove':
            type = 'mousemove';
            break;
          case 'touchend':
            type = 'mouseup';
            break;
          default:
            return;
        }
        var simulatedEvent = document.createEvent('MouseEvent');
        simulatedEvent.initMouseEvent(
          type,
          true,
          false,
          window,
          1,
          first.screenX,
          first.screenY,
          first.clientX,
          first.clientY,
          false,
          false,
          false,
          false,
          0,
          null
        );
        first.target.dispatchEvent(simulatedEvent);
        e.preventDefault();
      }
      this.src.addEvent('touchstart touchmove touchend', touchHandler, false);
    }
  };
  window.GDragMove = GClass.create();
  GDragMove.prototype = {
    initialize: function(move_id, drag_id, options) {
      this.options = {
        beginDrag: $K.resultFunction,
        moveDrag: $K.resultFunction,
        endDrag: $K.emptyFunction,
        srcOnly: true
      };
      for (var property in options) {
        this.options[property] = options[property];
      }
      this.dragObj = $G(drag_id);
      this.dragObj.style.cursor = 'move';
      this.moveObj = $G(move_id);
      var Hinstance = this;

      function _beginDrag() {
        if (
          Hinstance.options.beginDrag.call(Hinstance.moveObj, {
            mousePos: this.mousePos,
            mouseOffset: Hinstance.mouseOffset
          })
        ) {
          Hinstance.mouseOffset = {
            x: this.mousePos.x - Hinstance.moveObj.getStyle('left').toInt(),
            y: this.mousePos.y - Hinstance.moveObj.getStyle('top').toInt()
          };
        }
      }

      function _moveDrag() {
        if (
          Hinstance.options.moveDrag.call(Hinstance.moveObj, {
            mousePos: this.mousePos,
            mouseOffset: Hinstance.mouseOffset
          })
        ) {
          Hinstance.moveObj.style.top =
            this.mousePos.y - Hinstance.mouseOffset.y + 'px';
          Hinstance.moveObj.style.left =
            this.mousePos.x - Hinstance.mouseOffset.x + 'px';
        }
      }

      function _endDrag() {
        Hinstance.options.endDrag.call(Hinstance.moveObj, {
          mousePos: this.mousePos,
          mouseOffset: Hinstance.mouseOffset
        });
      }
      var o = {
        beginDrag: _beginDrag,
        moveDrag: _moveDrag,
        endDrag: _endDrag,
        srcOnly: this.options.srcOnly
      };
      new GDrag(this.dragObj, o);
    }
  };
  window.GMask = GClass.create();
  GMask.prototype = {
    initialize: function(id, onkeypress) {
      var input = $G(id),
        tmp = this;
      this.maxlength = floatval(input.maxlength);
      this.disabled = input.disabled;
      if (input.min) {
        this.min = floatval(input.min);
      }
      if (input.max) {
        this.max = floatval(input.max);
      }
      if (input.pattern) {
        this.pattern = new RegExp(input.pattern);
        input.setAttribute('pattern', '(.*){0,}');
      }

      function checkKey(e) {
        if (e.key) {
          return /^(Backspace|(Arrow)?Left|(Arrow)?Right|Enter|Tab|Delete)$/.test(
            e.key
          );
        } else {
          var key = GEvent.keyCode(e);
          if (
            key == 8 ||
            key == 37 ||
            key == 39 ||
            key == 13 ||
            key == 9 ||
            key == 46
          ) {
            return true;
          } else {
            return false;
          }
        }
      }
      var doActive = function(e) {
        var el = e.target;
        tmp.oldCursor = el.getCaretPosition();
        tmp.key = false;
        tmp.oldValue = el.value;
      };
      var doKeydown = function(e) {
        var el = e.target;
        tmp.oldCursor = el.getCaretPosition();
        tmp.key = checkKey(e);
        tmp.oldValue = el.value;
        if (
          tmp.maxlength > 0 &&
          !tmp.key &&
          !GEvent.isCtrlKey(e) &&
          tmp.oldCursor.start == tmp.oldCursor.end
        ) {
          if (el.value.length >= tmp.maxlength) {
            GEvent.stop(e);
            return false;
          }
        }
      };
      var doInput = function(e) {
        var ret = true,
          el = e.target,
          value = el.value;
        if (value != '' && !tmp.key && !GEvent.isCtrlKey(e)) {
          e.key = value.substr(tmp.oldCursor.start, 1);
          if (Object.isFunction(onkeypress)) {
            ret = onkeypress.call(el, e);
          } else if (tmp.pattern) {
            ret = tmp.pattern.test(value);
          }
          if (tmp.maxlength > 0 && value.length > tmp.maxlength) {
            el.value = value.substr(0, tmp.maxlength);
            el.setSelectionRange(tmp.oldCursor.end, tmp.oldCursor.end);
          } else if (!ret) {
            el.value = tmp.oldValue;
            el.setSelectionRange(tmp.oldCursor.end, tmp.oldCursor.end);
          }
        }
      };
      input.addEvent('focus', doActive);
      input.addEvent('keydown', doKeydown);
      input.addEvent('input', doInput);
    }
  };
  window.GInput = GClass.create();
  GInput.prototype = {
    initialize: function(id, inputchar, onchanged) {
      this.input = $G(id);
      this.input.addClass('ginput');
      this.keyboard = inputchar.split('');
      this.onchanged = onchanged || $K.emptyFunction;
      this.maxlength = floatval(this.input.maxlength);
      var self = this;
      if ($K.isMobile()) {
        this.panel = new GDropdown(this.input, {float: false});
        this.input.readOnly = true;
        this.input.addEvent('click', function(e) {
          self.input.setCaretPosition(self.input.value.length, 1);
          self._draw();
          GEvent.stop(e);
          return false;
        });
        $G(document.body).addEvent('click', function(e) {
          let elem = GEvent.element(e),
            ginput = $G(elem).hasClass('ginput');
          if (!ginput) {
            self.panel.hide();
            self._doChanged();
            if (self.panel.input) {
              if (self.panel.input_value != self.panel.input.value) {
                self.panel.input.callEvent('change');
              }
              self.panel.input = null;
            }
          }
        });
      } else {
        this.input.addEvent('focus', function() {
          self.input.select();
        });
      }
      new GMask(this.input, function(e) {
        return self.keyboard.indexOf(e.key) > -1;
      });
      this.input.addEvent('change', function() {
        self._doChanged();
      });
      this._doChanged();
    },
    _doChanged: function() {
      this.onchanged.call(this.input);
    },
    _createButton: function(parent, text, title, className) {
      var a = document.createElement('a');
      a.innerHTML = text;
      a.title = title;
      if (className) {
        parent.className = className;
      }
      parent.appendChild(a);
    },
    _draw: function() {
      var dropdown = this.panel.getDropdown(),
        self = this,
        panel = document.createElement('table');
      dropdown.innerHTML = '';
      panel.className = 'ginput';
      dropdown.appendChild(panel);
      var tr = document.createElement('tr'),
        td = document.createElement('td');
      td.className = 'buttons';
      td.rowSpan = 2;
      tr.appendChild(td);
      forEach(this.keyboard, function() {
        self._createButton(td, this, this);
      });
      td = document.createElement('td');
      tr.appendChild(td);
      panel.appendChild(tr);
      self._createButton(td, 'x', 'Backspace', 'backspace');
      tr = document.createElement('tr');
      td = document.createElement('td');
      tr.appendChild(td);
      panel.appendChild(tr);
      self._createButton(td, '&crarr;', 'Enter', 'enter');
      forEach(panel.getElementsByTagName('a'), function() {
        $G(this).addEvent('click', function(e) {
          var elem = GEvent.element(e);
          if (elem.parentNode.className == 'backspace') {
            if (document.selection) {
              self.input.focus();
              document.selection.empty();
            } else {
              var text = self.input.value,
                startPos = self.input.selectionStart,
                endPos = self.input.selectionEnd;
              if ((startPos > 0 && endPos > 0) || endPos > startPos) {
                startPos = startPos == endPos ? startPos - 1 : startPos;
                text = text.slice(0, startPos) + text.slice(endPos);
                self.input.value = text;
                self.input.selectionStart = startPos;
                self.input.selectionEnd = startPos;
              }
              self.input.focus();
            }
            GEvent.stop(e);
          } else if (elem.parentNode.className != 'enter') {
            var text = this.innerHTML;
            self.input.focus();
            if (document.selection) {
              sel.setSelectedText(text);
            } else if (
              self.input.selectionStart ||
              self.input.selectionStart === 0
            ) {
              var startPos = self.input.selectionStart,
                endPos = self.input.selectionEnd,
                value =
                  self.input.value.substring(0, startPos) +
                  text +
                  self.input.value.substring(endPos, self.input.value.length);
              if (self.maxlength == 0 || value.length <= self.maxlength) {
                self.input.value = value;
                self.input.selectionStart = startPos + text.length;
                self.input.selectionEnd = startPos + text.length;
              } else {
                self.input.selectionStart = startPos;
                self.input.selectionEnd = endPos;
              }
            } else {
              text = self.input.value + text;
              if (self.maxlength == 0 || text.length <= self.maxlength) {
                self.input.value = text;
              }
            }
            GEvent.stop(e);
          }
        });
      });
      this.panel.show();
      this.panel.input = this.input;
      this.panel.input_value = this.input.value;
    }
  };

  window.GDropdown = GClass.create();
  GDropdown.prototype = {
    initialize: function(src, o) {
      this.src = src;
      this.options = {
        autoHeight: false,
        float: true,
        id: 'gdropdown',
        className: 'gdropdown'
      };
      for (var prop in o) {
        this.options[prop] = o[prop];
      }
      if (!$E(this.options.id)) {
        var div = document.createElement('div');
        document.body.appendChild(div);
        div.id = this.options.id;
      }
      this.dropdown = $G(this.options.id);
      this.dropdown.style.display = 'none';
    },
    getDropdown: function() {
      return this.dropdown;
    },
    getPosition: function() {
      var e = this.src.parentNode,
        position = getComputedStyle(this.src)['position'];
      while (e != document.body && position != 'absolute' && position != 'fixed') {
        e = e.parentNode;
        position = getComputedStyle(e)['position'];
      }
      return position == 'fixed' ? 'fixed' : 'absolute';
    },
    show: function() {
      if (this.options.className) {
        this.dropdown.classList.add(this.options.className);
      }
      this.dropdown.style.zIndex = 1001;
      if (this.options.float === true || (this.options.float === 'auto' && !$K.isMobile())) {
        this.dropdown.style.position = this.getPosition();
        var vpo = this.src.viewportOffset(),
          input_height = this.src.getHeight(),
          dm = this.dropdown.getDimensions(),
          scrolltop = document.viewport.getscrollTop(),
          doc_height = document.viewport.getHeight(),
          top_space = vpo.top - scrolltop,
          bottom_space = doc_height - top_space - input_height;
        if (this.options.autoHeight) {
          var space = Math.max(top_space, bottom_space);
          if (dm.height > space) {
            this.dropdown.style.height = (space - 10) + 'px';
            dm = this.dropdown.getDimensions();
          }
        }
        if (top_space >= bottom_space) {
          this.dropdown.style.top = Math.max(vpo.top - dm.height - 5, 0) + 'px';
        } else {
          this.dropdown.style.top = (vpo.top + input_height + 5) + 'px';
        }
        var l = Math.max(
          vpo.left + dm.width > document.viewport.getWidth() ?
            vpo.left + this.src.getWidth() - dm.width :
            vpo.left, document.viewport.getscrollLeft() + 5
        );
        this.dropdown.style.left = l + 'px';
        this.dropdown.style.display = 'block';
      } else {
        this.dropdown.style.left = 0;
        this.dropdown.style.right = 0;
        this.dropdown.style.bottom = 0;
        this.dropdown.style.position = 'fixed';
        this.dropdown.classList.add('mobile_fixed');
        this.dropdown.style.display = 'block';
        this.src.scrollIntoView();
      }
    },
    hide: function() {
      if (this.options.className) {
        this.dropdown.classList.remove(this.options.className);
      }
      this.dropdown.style.display = 'none';
      this.dropdown.style.height = 'auto';
    },
    showing: function() {
      return this.dropdown.style.display == 'block';
    }
  };
  window.GDateTime = GClass.create();
  GDateTime.prototype = {
    initialize: function(elem) {
      elem = $E(elem);
      this.input = $G(document.createElement('div'));
      if (elem.id && elem.id != '') {
        this.input.id = elem.id;
      } else {
        this.input.id = elem.name.replace(/\[.*?\]/, '');
      }
      this.type = elem.type.toLowerCase();
      this.mode = 0;
      if (this.type === 'date') {
        this.displayFormat = 'd M Y';
        this.format = 'y-m-d';
      } else if (this.type === 'datetime-local') {
        this.displayFormat = 'd M Y H:I';
        this.format = 'y-m-d H:I';
      } else if (this.type === 'time') {
        this.displayFormat = 'H:I';
        this.format = 'H:I';
        this.mode = 3;
      } else {
        console.error('Not support ' + this.type);
        return;
      }
      this.input.className = 'input-gcalendar input-select';
      this.input.tabIndex = 0;
      this.input.style.cursor = 'pointer';
      elem.parentNode.appendChild(this.input);
      this.display = document.createElement('div');
      this.display.id = elem.id + '_display';
      this.input.appendChild(this.display);
      this.hidden = document.createElement('input');
      this.hidden.type = 'hidden';
      this.hidden.name = elem.name;
      this.input.appendChild(this.hidden);
      this.placeholder = document.createElement('div');
      this.placeholder.className = 'placeholder';
      this.placeholder.style.position = 'absolute';
      this.input.appendChild(this.placeholder);
      this.value = null;
      this.cdate = new Date();
      this.min_date = null;
      this.max_date = null;
      this.input_click = false;
      this.firstKey = null;
      this.keyboard = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '0'];
      this.calendar = new GDropdown(this.input, {float: 'auto'});
      var self = this;
      this.input.addEvent('mousedown', function(e) {
        self.input_click = true;
      });
      this.input.addEvent('mouseup', function(e) {
        self.input_click = false;
        self.firstKey = null;
        self.mode = self._modeFromCaretPosition();
        self._setHighlight();
      });
      this.input.addEvent('focus', function(e) {
        if (!self.input_click) {
          self.mode = self._modeFromCaretPosition();
          self._setHighlight();
        }
      });
      this.input.addEvent('blur', function(e) {
        self._clearSelectText();
      });
      this.input.addEvent('click', function(e) {
        if (self._draw()) {
          GEvent.stop(e);
        }
      });
      this.input.addEvent('keydown', function(e) {
        var key = GEvent.keyCode(e),
          old_mode = self.mode;
        if (key == 9) {
          if (self.mode == 3 && self.type !== 'date') {
            self.mode = 4;
            self._setHighlight();
            GEvent.stop(e);
          } else if (self.mode < 3 && self.type === 'datetime-local') {
            self.mode = 3;
            self.calendar.hide();
            self._setHighlight();
            GEvent.stop(e);
          } else if (self.mode < 3 && self.type !== 'time') {
            self.calendar.hide();
            self.mode = 0;
          }
        } else if (key == 32) {
          self._toogle();
          GEvent.stop(e);
        } else if (key == 37 || key == 39) {
          if (self.mode < 3 && self.type !== 'time') {
            self._moveDate(key == 39 ? 1 : -1);
            self._drawCalendar();
            old_mode = -1;
          } else if (key == 37 && self.mode == 4) {
            self.mode = 3;
          } else if (key == 37 && self.mode == 3 && self.type === 'datetime-local') {
            self.mode = 0;
          } else if (key == 39 && self.mode < 3 && self.type === 'datetime-local') {
            self.mode = 3;
          } else if (key == 39 && self.mode == 3) {
            self.mode = 4;
          }
          if (old_mode != self.mode) {
            self._setHighlight();
          }
          GEvent.stop(e);
        } else if (key == 38 || key == 40) {
          if (self.mode < 3 && self.type !== 'time') {
            if (GEvent.isShiftKey(e)) {
              self._moveYear(key == 40 ? 1 : -1);
            } else if (GEvent.isCtrlKey(e)) {
              self._moveMonth(key == 40 ? 1 : -1);
            } else {
              self._moveDate(key == 40 ? 7 : -7);
            }
            self._drawCalendar();
            self._setHighlight();
          } else {
            let hour = self.value.getHours(),
              d = new Date(self.value),
              t,
              min,
              max;
            if (self.mode == 3) {
              t = hour;
              min = self.min_date ? self.min_date.getHours() : 0;
              max = self.max_date ? self.max_date.getHours() : 23;
            } else if (self.mode == 4) {
              t = Math.floor(self.value.getTime() / 60000);
              if (self.max_date) {
                max = Math.floor(self.max_date.getTime() / 60000);
              } else {
                d.setHours(23, 59, 59);
                max = Math.floor(d.getTime());
              }
              if (self.min_date) {
                min = Math.floor(self.min_date.getTime() / 60000);
              } else {
                d.setHours(0, 0, 0);
                min = Math.floor(d.getTime() / 60000);
              }
            }
            if (key == 38) {
              if (t >= max) {
                t = min;
              } else {
                t++;
              }
            } else if (key == 40) {
              if (t <= min) {
                t = max;
              } else {
                t--;
              }
            }
            if (self.mode == 3) {
              d.setHours(t);
            } else if (self.mode == 4) {
              d.setTime(t * 60000);
            }

            self._doChanged(d);
            self._setHighlight();
          }
          GEvent.stop(e);
        } else if (key == 8 && self.input.hasClass('readonly disabled') == false) {
          self.input.value = null;
          GEvent.stop(e);
        } else if (GEvent.isCtrlKey(e)) {
          if (key == 67 && self.value) {
            copyToClipboard(self.value.format('y-m-d H:i:s'));
          } else if (key == 86) {
            navigator.clipboard.readText().then((copiedText) => {
              self.input.value = copiedText;
              if (self.mode < 3 && self.type !== 'time') {
                self._drawCalendar();
              }
              self._setHighlight();
            });
          }
        } else {
          if (self.mode == 3 || self.mode == 4) {
            self._timeFromChar(e.key);
            self._setHighlight();
          }
          GEvent.stop(e);
        }
      });
      $G(document.body).addEvent('click', function(e) {
        let elem = GEvent.element(e),
          parent = elem && elem.parentNode,
          gcalendar = $G(elem).hasClass('input-gcalendar');
        gcalendar = gcalendar || (parent && $G(parent).hasClass('input-gcalendar'));
        if (!gcalendar) {
          self.calendar.hide();
        }
      });
      Object.defineProperty(this.input, 'value', {
        get: function() {
          return self.value ? self.value.format(self.format) : null;
        },
        set: function(value) {
          self._doChanged(value);
        }
      });
      Object.defineProperty(this.input, 'min', {
        get: function() {
          return self.min_date.format(self.format);
        },
        set: function(value) {
          self.min_date = self._toDate(value);
          self._doChanged(self.value);
        }
      });
      Object.defineProperty(this.input, 'max', {
        get: function() {
          return self.max_date.format(self.format);
        },
        set: function(value) {
          self.max_date = self._toDate(value);
          self._doChanged(self.value);
        }
      });
      Object.defineProperty(this.input, 'placeholder', {
        get: function() {
          return self.placeholder.innerHTML;
        },
        set: function(value) {
          self.placeholder.innerHTML = value;
        }
      });
      Object.defineProperty(this.input, 'text', {
        get: function() {
          return self.display.innerHTML;
        },
        set: function(value) {
          self.display.innerHTML = value;
        }
      });
      Object.defineProperty(this.input, 'disabled', {
        get: function() {
          return self.input.hasClass('disabled') ? true : false;
        },
        set: function(value) {
          if (value) {
            self.input.addClass('disabled');
            self.input.tabIndex = -1;
          } else {
            self.input.removeClass('disabled');
            self.input.tabIndex = 0;
          }
        },
      });
      Object.defineProperty(this.input, 'readOnly', {
        get: function() {
          return self.input.hasClass('readonly') ? true : false;
        },
        set: function(value) {
          if (value) {
            self.input.addClass('readonly');
          } else {
            self.input.removeClass('readonly');
          }
        },
      });
      if (elem.min) {
        this.input.min = elem.min;
      }
      if (elem.max) {
        this.input.max = elem.max;
      }
      if (elem.disabled) {
        this.input.disabled = true;
      }
      if (elem.readOnly) {
        this.input.readOnly = true;
      }
      this.input.placeholder = elem.placeholder;
      this.input.value = elem.value;
      elem.parentNode.removeChild(elem);
    },
    _draw: function() {
      if (this.input.hasClass('readonly disabled') == false) {
        if ((this.mode == 3 || this.mode == 4) && $K.isMobile()) {
          this._drawTime();
          return true;
        } else if (this.mode < 3) {
          this._drawCalendar();
          return true;
        }
      }
      return false;
    },
    _drawCalendar: function() {
      let self = this,
        calendar = this.calendar.getDropdown(),
        div = document.createElement('div'),
        p = document.createElement('p'),
        a = document.createElement('a');
      calendar.innerHTML = '';
      calendar.appendChild(div);
      div.className = 'gcalendar';
      div.appendChild(p);
      p.appendChild(a);
      a.innerHTML = '&larr;';
      a.style.cursor = 'pointer';
      $G(a).addEvent('click', function(e) {
        self._move(e, -1);
        GEvent.stop(e);
        return false;
      });
      if (this.mode == 2) {
        var start_year = this.cdate.getFullYear() - 6;
        a = document.createElement('span');
        a.appendChild(
          document.createTextNode(
            start_year +
            Date.yearOffset +
            '-' +
            (start_year + 11 + Date.yearOffset)
          )
        );
        p.appendChild(a);
        a.style.cursor = 'pointer';
      } else {
        a = document.createElement('a');
        a.innerHTML = this.cdate.format('M');
        a.style.cursor = 'pointer';
        $G(a).addEvent('click', function(e) {
          self.mode++;
          self._drawCalendar();
          GEvent.stop(e);
          return false;
        });
        p.appendChild(a);
        a = document.createElement('a');
        a.innerHTML = this.cdate.format('Y');
        a.style.cursor = 'pointer';
        $G(a).addEvent('click', function(e) {
          self.mode = 2;
          self._drawCalendar();
          GEvent.stop(e);
          return false;
        });
        p.appendChild(a);
      }
      a = document.createElement('a');
      p.appendChild(a);
      a.innerHTML = '&rarr;';
      a.style.cursor = 'pointer';
      $G(a).addEvent('click', function(e) {
        self._move(e, 1);
        GEvent.stop(e);
        return false;
      });
      var table = document.createElement('table'),
        thead = document.createElement('thead'),
        tbody = document.createElement('tbody'),
        intmonth = this.cdate.getMonth() + 1,
        intyear = this.cdate.getFullYear(),
        cls = '',
        today = new Date(),
        today_month = today.getMonth() + 1,
        today_year = today.getFullYear(),
        today_date = today.getDate(),
        sel_month = this.value ? this.value.getMonth() + 1 : today_month,
        sel_year = this.value ? this.value.getFullYear() : today_year,
        sel_date = this.value ? this.value.getDate() : today_date,
        r = 0,
        c = 0,
        bg, row, cell;
      table.appendChild(thead);
      table.appendChild(tbody);
      div.appendChild(table);
      if (this.mode == 2) {
        for (var i = start_year; i < start_year + 12; i++) {
          c = (i - start_year) % 4;
          if (c == 0) {
            row = tbody.insertRow(r);
            bg = bg == 'bg1' ? 'bg2' : 'bg1';
            row.className = 'gcalendar_' + bg;
            r++;
          }
          cell = row.insertCell(c);
          cls = 'month';
          if (i == sel_year) {
            cls = cls + ' select';
          }
          if (i == today_year) {
            cls = cls + ' today';
          }
          cell.className = cls;
          cell.appendChild(document.createTextNode(i + Date.yearOffset));
          cell.oDate = new Date(i, 1, 1, 12, 0, 0, 0);
          $G(cell).addEvent('click', function(e) {
            self.cdate.setTime(this.oDate.valueOf());
            self.mode--;
            self._drawCalendar();
            GEvent.stop(e);
            return false;
          });
        }
      } else if (this.mode == 1) {
        forEach(Date.monthNames, function(month, i) {
          c = i % 4;
          if (c == 0) {
            row = tbody.insertRow(r);
            bg = bg == 'bg1' ? 'bg2' : 'bg1';
            row.className = 'gcalendar_' + bg;
            r++;
          }
          cell = row.insertCell(c);
          cls = 'month';
          if (intyear == sel_year && i + 1 == sel_month) {
            cls = cls + ' select';
          }
          if (intyear == today_year && i + 1 == today_month) {
            cls = cls + ' today';
          }
          cell.className = cls;
          cell.appendChild(document.createTextNode(month));
          cell.oDate = new Date(intyear, i, 1, 0, 0, 0, 0);
          $G(cell).addEvent('click', function(e) {
            self.cdate.setTime(this.oDate.valueOf());
            self.mode--;
            self._drawCalendar();
            GEvent.stop(e);
            return false;
          });
        });
      } else {
        row = thead.insertRow(0);
        forEach(Date.dayNames, function(item, i) {
          cell = document.createElement('th');
          row.appendChild(cell);
          cell.appendChild(document.createTextNode(item));
        });
        var tmp_prev_month = intmonth - 1,
          tmp_next_month = intmonth + 1,
          tmp_next_year = intyear,
          tmp_prev_year = intyear;
        if (tmp_prev_month == 0) {
          tmp_prev_month = 12;
          tmp_prev_year--;
        }
        if (tmp_next_month == 13) {
          tmp_next_month = 1;
          tmp_next_year++;
        }
        var initial_day = 1,
          tmp_init = new Date(intyear, intmonth, 1, 0, 0, 0, 0).dayOfWeek(),
          max_prev = new Date(tmp_prev_year, tmp_prev_month, 0, 0, 0, 0, 0).daysInMonth(),
          max_this = new Date(intyear, intmonth, 0, 0, 0, 0, 0).daysInMonth();
        if (tmp_init !== 0) {
          initial_day = max_prev - (tmp_init - 1);
        }
        tmp_next_year = tmp_next_year.toString();
        tmp_prev_year = tmp_prev_year.toString();
        tmp_next_month = tmp_next_month.toString();
        tmp_prev_month = tmp_prev_month.toString();
        var pointer = initial_day,
          flag_init = initial_day == 1 ? 1 : 0,
          tmp_month = initial_day == 1 ? intmonth : parseInt(tmp_prev_month),
          tmp_year = initial_day == 1 ? intyear : parseInt(tmp_prev_year),
          flag_end = 0,
          min_date = this.min_date ? this.min_date.format('y-m-d') : null,
          max_date = this.max_date ? this.max_date.format('y-m-d') : null,
          canclick,
          oDate;
        r = 0;
        for (var x = 0; x < 42; x++) {
          if (tmp_init !== 0 && pointer > max_prev && flag_init == 0) {
            flag_init = 1;
            pointer = 1;
            tmp_month = intmonth;
            tmp_year = intyear;
          }
          if (flag_init == 1 && flag_end == 0 && pointer > max_this) {
            flag_end = 1;
            pointer = 1;
            tmp_month = parseInt(tmp_next_month);
            tmp_year = parseInt(tmp_next_year);
          }
          c = x % 7;
          if (c == 0) {
            row = tbody.insertRow(r);
            r++;
          }
          cell = row.insertCell(c);
          cell.oDate = new Date(tmp_year, tmp_month - 1, pointer, 0, 0, 0, 0);
          cell.title = cell.oDate.format(self.displayFormat);
          cell.appendChild(document.createTextNode(pointer));
          canclick = true;
          oDate = cell.oDate.format('y-m-d');
          if (min_date && max_date) {
            canclick = oDate >= min_date && oDate <= max_date;
          } else if (min_date) {
            canclick = oDate >= min_date;
          } else if (max_date) {
            canclick = oDate <= max_date;
          }
          if (canclick) {
            cls = tmp_month == intmonth ? 'curr' : 'ex';
          } else {
            cls = 'ex';
          }
          $G(cell).addEvent('click', function(e) {
            var c = this.hasClass('curr ex');
            if (c == 'curr') {
              if (self.value) {
                this.oDate.setHours(self.value.getHours(), self.value.getMinutes(), self.value.getSeconds());
              }
              self._doChanged(this.oDate);
            } else if (c == 'ex') {
              self._doChanged(null);
            }
            self.input.focus();
          });
          if (tmp_year == sel_year && tmp_month == sel_month && pointer == sel_date) {
            cls = cls + ' select';
          }
          if (tmp_year == today_year && tmp_month == today_month && pointer == today_date) {
            cls = cls + ' today';
          }
          cell.className = cls;
          pointer++;
        }
      }
      this.calendar.show();
    },
    _drawTime: function() {
      let self = this,
        calendar = this.calendar.getDropdown(),
        panel = document.createElement('table'),
        tr = document.createElement('tr'),
        td = document.createElement('td');
      calendar.innerHTML = '';
      panel.className = 'ginput';
      calendar.appendChild(panel);
      td.className = 'buttons';
      td.rowSpan = 2;
      tr.appendChild(td);
      forEach(this.keyboard, function() {
        self._createButton(td, this, this);
      });
      td = document.createElement('td');
      tr.appendChild(td);
      panel.appendChild(tr);
      this._createButton(td, 'x', 'Backspace', 'backspace');
      tr = document.createElement('tr');
      td = document.createElement('td');
      tr.appendChild(td);
      panel.appendChild(tr);
      this._createButton(td, '&crarr;', 'Enter', 'enter');
      forEach(panel.getElementsByTagName('a'), function() {
        $G(this).addEvent('click', function(e) {
          var elem = GEvent.element(e);
          if (elem.parentNode.className == 'enter') {
            return true;
          } else if (elem.parentNode.className == 'backspace') {
            self.input.value = null;
            self.mode = self.type == 'time' ? 3 : 0;
            self.firstKey = null;
          } else {
            self._timeFromChar(elem.innerText);
          }
          self.mouse_click = true;
          self._setHighlight();
          GEvent.stop(e);
          return false;
        });
      });
      this.calendar.show();
    },
    _move: function(e, value) {
      if (this.mode == 2) {
        this.cdate.setFullYear(this.cdate.getFullYear() + value * 12, 1, 1);
      } else if (this.mode == 1) {
        this.cdate.setFullYear(this.cdate.getFullYear() + value, 1, 1);
      } else {
        this.cdate.setMonth(this.cdate.getMonth() + value, 1);
      }
      this._draw();
      GEvent.stop(e);
    },
    _timeFromChar: function(value) {
      let d,
        c = value.toInt(),
        hours = this.value ? this.value.getHours().toString() : '0',
        minutes = this.value ? this.value.getMinutes().toString() : '0';
      if (this.keyboard.indexOf(value) > -1) {
        if (this.mode == 3) {
          if (this.firstKey == null) {
            hours = '0' + c;
            if (c < 3) {
              this.firstKey = c;
            } else {
              this.mode = 4;
              this.firstKey = null;
            }
          } else {
            c = floatval(String(this.firstKey) + c);
            hours = c < 10 ? '0' + c : String(Math.min(23, c));
            this.mode = 4;
            this.firstKey = null;
          }
        } else if (this.mode == 4) {
          if (this.firstKey == null) {
            minutes = '0' + c;
            this.firstKey = c;
          } else {
            c = floatval(String(this.firstKey) + c);
            minutes = c < 10 ? '0' + c : String(Math.min(59, c));
            this.firstKey = null;
          }
          this.mode = 4;
        }
        if (this.value) {
          d = new Date(this.value.valueOf());
        } else {
          d = new Date(this.cdate.valueOf());
        }
        d.setHours(hours, minutes);
        this._doChanged(d);
      }
    },
    _toogle: function() {
      if (this.calendar.showing()) {
        this.calendar.hide();
      } else {
        this._draw();
      }
    },
    _createButton: function(parent, text, title, className) {
      var a = document.createElement('a');
      a.innerHTML = text;
      a.title = title;
      if (className) {
        parent.className = className;
      }
      parent.appendChild(a);
    },
    _modeFromCaretPosition: function() {
      let value = this.display.innerText,
        len = value.length,
        position = this._getCaretPosition(),
        match = /[0-9\-][0-9\-]\:[0-9\-][0-9\-]$/.exec(value),
        mode = this.type === 'time' ? 3 : 0;
      if (len > 0 && match) {
        if (position >= len - 2) {
          mode = 4;
        } else if (position >= match.index) {
          mode = 3;
        }
      }
      return mode;
    },
    _getCaretPosition: function() {
      var sel = window.getSelection && window.getSelection();
      if (sel && sel.rangeCount > 0) {
        var range = sel.getRangeAt(0);
        return range.startOffset;
      }
      return 0;
    },
    _setHighlight: function() {
      let value = this.display.innerText,
        len = value.length;
      if (len > 0) {
        let match = /[0-9\-][0-9\-]\:[0-9\-][0-9\-]$/.exec(value);
        if (match) {
          if (this.mode == 4) {
            this._selectText(match.index + 3, len);
          } else if (this.mode == 3) {
            this._selectText(match.index, len - 3);
          } else {
            this._selectText(0, match.index - 1);
          }
        } else {
          this._selectText(0, len);
        }
      }
    },
    _clearSelectText: function() {
      var selection = window.getSelection();
      selection.empty();
    },
    _selectText: function(start, stop) {
      let node = this.display.firstChild,
        range = document.createRange();
      range.setStart(node, start);
      range.setEnd(node, stop);
      let sel = window.getSelection();
      sel.removeAllRanges();
      sel.addRange(range);
    },
    _setTime: function(value) {
      value = this._toDate(value);
      if (value !== null) {
        let d = new Date();
        value.setFullYear(d.getFullYear());
        value.setMonth(d.getMonth());
        value.setDate(d.getDate());
        value.setSeconds(0);
      }
      return value;
    },
    _doChanged: function(value) {
      let old_value = this.value ? this.value.getTime() : -1,
        display = '';
      if (this.type === 'time') {
        this.value = this._setTime(value);
        this.min_date = this._setTime(this.min_date);
        this.max_date = this._setTime(this.max_date);
      } else {
        this.value = this._toDate(value);
      }
      let current_time = this.value ? this.value.getTime() : 0;
      if (this.type !== 'datetime-local') {
        let min_date = this.min_date ? this.min_date.getTime() : 0,
          max_date = this.max_date ? this.max_date.getTime() : 0;
        if (max_date > 0 && current_time > 0 && current_time > max_date) {
          this.value.setTime(this.max_date.valueOf());
        } else if (min_date > 0 && current_time > 0 && current_time < min_date) {
          this.value.setTime(this.min_date.valueOf());
        }
      }
      if (this.value) {
        this.cdate.setTime(this.value.valueOf());
      }
      if (old_value != current_time) {
        if (current_time == 0) {
          if (this.type === 'time') {
            display = '--:--';
          }
          this.hidden.value = '';
        } else {
          display = this.value.format(this.displayFormat);
          this.hidden.value = this.value.format(this.format);
        }
        this._setText(display);
        var self = this;
        window.setTimeout(function() {
          self.input.callEvent('change');
        });
      }
    },
    _toDate: function(value) {
      let d = null;
      if (Object.isString(value)) {
        d = value.toDate();
      } else if (value) {
        d = new Date();
        d.setTime(value.valueOf());
      }
      return d;
    },
    _setText: function(value) {
      this.display.innerHTML = value;
      this.placeholder.style.display = value == '' && this.placeholder.innerHTML != '' ? 'block' : 'none';
    },
    _moveDate: function(days) {
      let d;
      if (this.value === null) {
        d = new Date(this.cdate.format('y-m-d H:i'));
      } else {
        d = new Date(this.value.format('y-m-d H:i'));
      }
      d.setDate(d.getDate() + days);
      this._doChanged(d);
      return this;
    },
    _moveMonth: function(months) {
      let d;
      if (this.value === null) {
        d = new Date(this.cdate.format('y-m-d H:i'));
      } else {
        d = new Date(this.value.format('y-m-d H:i'));
      }
      d.setMonth(d.getMonth() + months);
      this._doChanged(d);
      return this;
    },
    _moveYear: function(years) {
      let d;
      if (this.value === null) {
        d = new Date(this.cdate.format('y-m-d H:i'));
      } else {
        d = new Date(this.value.format('y-m-d H:i'));
      }
      d.setFullYear(d.getFullYear() + years);
      this._doChanged(d);
      return this;
    },
  };
  window.GFxZoom = GClass.create();
  GFxZoom.prototype = Object.extend(new GFx(), {
    initialize: function(elem, options) {
      this.options = {
        duration: 2,
        speed: 1,
        offset: 0,
        fitdoc: true,
        onComplete: $K.emptyFunction,
        onResize: $K.emptyFunction
      };
      for (var property in options) {
        this.options[property] = options[property];
      }
      this.options.duration = this.options.duration > 8 ? 8 : this.options.duration;
      this.options.duration -= this.options.duration % 2 == 0 ? 0 : 1;
      this.Player = $G(elem);
      this.Player.style.zIndex = 9999999;
      var tmp = this.Player.viewportOffset();
      this.t = tmp.top;
      this.l = tmp.left;
      tmp = this.Player.getDimensions();
      this.w = tmp.width;
      this.h = tmp.height;
    },
    play: function(dw, dh, dl, dt) {
      var cw = document.viewport.getWidth();
      var ch = document.viewport.getHeight();
      if (this.options.fitdoc) {
        if (dw > cw) {
          dh = Math.round((cw * dh) / dw);
          dw = cw;
        }
        if (dh > ch) {
          dw = Math.round((ch * dw) / dh);
          dh = ch;
        }
        dw = dw - this.options.offset;
        dh = dh - this.options.offset;
      }
      this.dw = dw;
      this.dh = dh;
      if (dl == null) {
        dl = document.viewport.getscrollLeft() + (cw - dw) / 2;
      }
      if (dt == null) {
        dt = document.viewport.getscrollTop() + (ch - dh) / 2;
      }
      this.lStep = (dl - this.l) / 2 / this.options.duration;
      this.tStep = (dt - this.t) / 2 / this.options.duration;
      this.wStep = (dw - this.w) / 2 / this.options.duration;
      this.hStep = (dh - this.h) / 2 / this.options.duration;
      this.timer = window.setInterval(this.step.bind(this), this.options.speed);
      this.options.onResize.call(this);
    },
    step: function() {
      if (this.w != this.dw || this.h != this.dh) {
        this.l += this.lStep;
        this.t += this.tStep;
        this.w += this.wStep;
        this.h += this.hStep;
        this.Player.style.left = this.l + 'px';
        this.Player.style.top = this.t + 'px';
        this.Player.style.width = this.w + 'px';
        this.Player.style.height = this.h + 'px';
        this.options.onResize.call(this);
      } else {
        this.stop();
      }
    },
    stop: function() {
      window.clearInterval(this.timer);
      this.options.onComplete.call(this);
    }
  });
  window.Color = GClass.create();
  Color.prototype = {
    initialize: function(value) {
      if (Array.isArray(value)) {
        this.r = value[0];
        this.g = value[1];
        this.b = value[2];
        this.a = value.length > 3 ? value[3] : null;
      } else {
        var rgb = /#?([a-zA-Z0-9]{1,2})([a-zA-Z0-9]{1,2})([a-zA-Z0-9]{1,2})([a-zA-Z0-9]{0,2})$/.exec(
          value
        );
        if (rgb) {
          this.r =
            rgb[1].length == 2 ?
              parseInt(rgb[1], 16) :
              parseInt(rgb[1] + rgb[1], 16);
          this.g =
            rgb[2].length == 2 ?
              parseInt(rgb[2], 16) :
              parseInt(rgb[2] + rgb[2], 16);
          this.b =
            rgb[3].length == 2 ?
              parseInt(rgb[3], 16) :
              parseInt(rgb[3] + rgb[3], 16);
          this.a =
            rgb[4] == '' ?
              null :
              rgb[4].length == 2 ?
                parseInt(rgb[4], 16) :
                parseInt(rgb[4] + rgb[4], 16);
        } else {
          this.r = 0;
          this.g = 0;
          this.b = 0;
          this.a = null;
        }
      }
    },
    darken: function(amount) {
      return new Color([
        Math.max(0, Math.round(this.r - amount)),
        Math.max(0, Math.round(this.g - amount)),
        Math.max(0, Math.round(this.b - amount)),
        this.a
      ]);
    },
    lighten: function(amount) {
      return new Color([
        Math.min(255, Math.round(this.r + amount)),
        Math.min(255, Math.round(this.g + amount)),
        Math.min(255, Math.round(this.b + amount)),
        this.a
      ]);
    },
    invert: function() {
      return new Color([
        this.r > 128 ? 0 : 255,
        this.g > 128 ? 0 : 255,
        this.b > 128 ? 0 : 255,
        this.a
      ]);
    },
    toString: function() {
      return (
        '#' +
        this.r
          .toString(16)
          .toUpperCase()
          .leftPad(2, '0') +
        this.g
          .toString(16)
          .toUpperCase()
          .leftPad(2, '0') +
        this.b
          .toString(16)
          .toUpperCase()
          .leftPad(2, '0') +
        (this.a !== null && this.a !== 1 ?
          this.a
            .toString(16)
            .toUpperCase()
            .leftPad(2, '0') :
          '')
      );
    },
    toRGB: function() {
      return this.a !== null && this.a !== 1 ?
        'rgba(' + this.r + ', ' + this.g + ', ' + this.b + ', ' + this.a + ')' :
        'rgb(' + this.r + ', ' + this.g + ', ' + this.b + ')';
    },
    toArray: function() {
      return [this.r, this.g, this.b, this.a];
    }
  };
  window.GDDColor = GClass.create();
  GDDColor.prototype = {
    initialize: function(id, onchanged) {
      this.Colors = Array(
        'B71C1C',
        '880E4F',
        '4A148C',
        '311B92',
        '1A237E',
        '0D47A1',
        '304FFE',
        '01579B',
        '2387CA',
        '006064',
        '004D40',
        '1B5E20',
        '33691E',
        '827717',
        'FFD600',
        'FF6F00',
        'E65100',
        'BF360C',
        '3E2723',
        '263238',
        'FFFFFF',
        '000000',
        'T',
        'C'
      );
      this.cols = 6;
      this.input = $G(id);
      this.input.addClass('gddcolor');
      if ($K.isMobile()) {
        this.input.readOnly = true;
      }
      this.onchanged = onchanged || $K.emptyFunction;
      this.color = '';
      this.color_format = /^((transparent)|(\#[0-9a-fA-F]{6,6}))$/i;
      this.ddcolor = new GDropdown(this.input, {float: 'auto'});
      var self = this;
      this.input.addEvent('click', function(e) {
        self.createColors();
        self.ddcolor.show();
        self.showDemo(self.color);
        self.pickColor(self.color);
        GEvent.stop(e);
        return false;
      });
      new GMask(this.input, function(e) {
        return /[0-9a-fA-F]/.test(e.key);
      });
      this.input.addEvent('keydown', function(e) {
        var key = GEvent.keyCode(e);
        if (key == 38 || key == 39 || key == 40 || /^(Arrow)?(Up|Down|Right)$/.test(e.key)) {
          self.createColors();
          self.ddcolor.show();
          $E('gddcolor_div').firstChild.firstChild.focus();
          GEvent.stop(e);
          return false;
        } else if (GEvent.isCtrlKey(e)) {
          if (key == 67) {
            var currentColor = self.getColor();
            if (currentColor) {
              copyToClipboard(currentColor);
            }
          } else if (key == 86) {
            navigator.clipboard.readText().then((copiedText) => {
              self.setColor(copiedText);
            });
          }
        }
      });
      if (this.input.type == 'text') {
        this.input.addEvent('keyup', function() {
          var value = this.value.toUpperCase();
          if (value != 'TRANSPARENT') {
            var c = value.replace('#', '').replace(/[^0-9A-F]+/, '');
            if (c != '') {
              this.value = '#' + c;
            }
          }
        });
      } else {
        this.input.style.cursor = 'pointer';
        self.input.tabIndex = 0;
      }
      $G(document.body).addEvent('click', function(e) {
        let elem = GEvent.element(e),
          gddcolor = $G(elem).hasClass('gddcolor');
        if (!gddcolor) {
          self.ddcolor.hide();
        }
      });
      if (self.input.value) {
        self.timer = window.setInterval(function() {
          if (!$E(self.input)) {
            window.clearInterval(self.timer);
          } else if (self.input.value !== self.color) {
            if (self.input.value == '') {
              self.color = '';
              self.input.style.color = '#000000';
              self.input.style.backgroundColor = '#FFFFFF';
            } else if (self.color_format.test(self.input.value)) {
              self.color = self.input.value;
              self.input.style.color = self.invertColor(self.color);
              self.input.style.backgroundColor = self.color;
            } else {
              return;
            }
            self.pickColor(self.color);
            self.showDemo(self.color);
            self.input.callEvent('change');
          }
        }, 50);
      }
    },
    createColors: function() {
      var r = this.Colors.length / this.cols,
        t = this.input.tabIndex + 1,
        dropdown = this.ddcolor.getDropdown(),
        ddcolor = document.createElement('div'),
        self = this,
        patt = /((color_)([0-9]+)_)([0-9]+)/;
      dropdown.innerHTML = '';
      ddcolor.className = 'gddcolor';
      ddcolor.id = 'gddcolor_div';
      dropdown.appendChild(ddcolor);
      var _dokeydown = function(e) {
        var key = GEvent.keyCode(e),
          hs = patt.exec(this.id),
          z = floatval(hs[3]),
          x = floatval(hs[4]);
        if (key > 36 && key < 41) {
          if (key == 37) {
            x--;
          } else if (key == 38) {
            z--;
          } else if (key == 39) {
            x++;
          } else if (key == 40) {
            z++;
          }
          var el = $E(hs[2] + z + '_' + x);
          if (el) {
            el.focus();
            self.showDemo(el.title);
          }
          GEvent.stop(e);
        } else if (key == 13) {
          self.doClick(this.title);
          GEvent.stop(e);
        } else if (key == 32) {
          if (r - z > 1) {
            self.pickColor(this.title);
            $E('color_' + (self.cols - 1) + '_0').focus();
          }
          GEvent.stop(e);
        } else if (key == 27 || key == 9) {
          self.ddcolor.hide();
          self.input.focus();
          GEvent.stop(e);
        }
      };
      var c = 0,
        a,
        p = document.createElement('p'),
        z;
      ddcolor.appendChild(p);
      forEach(this.Colors, function(color, n) {
        if (n % self.cols == 0) {
          c++;
        }
        a = $G(document.createElement('a'));
        a.id = 'color_' + c + '_' + (n % self.cols);
        p.appendChild(a);
        a.tabIndex = t;
        t++;
        if (color == 'T') {
          a.title = 'Transparent';
          a.innerHTML = 'T';
          a.className = 'item dark';
        } else if (color == 'C') {
          a.title = 'Clear';
          a.style.backgroundColor = '#EEEEEE';
          a.innerHTML = 'C';
          a.className = 'item dark';
        } else {
          z = '#' + color;
          a.style.backgroundColor = z;
          a.title = z;
          a.className = color == '#FFFFFF' ? 'item dark' : 'item';
        }
        a.addEvent('click', function(e) {
          if (
            this.title == 'Clear' ||
            this.title == 'Transparent' ||
            this.title == '#FFFFFF'
          ) {
            self.doClick(this.title);
          } else {
            self.pickColor(this.title);
          }
          GEvent.stop(e);
          return false;
        });
        a.addEvent('mouseover', function() {
          self.showDemo(this.title);
        });
        a.addEvent('keydown', _dokeydown);
      });
      this.demoColor = $G(document.createElement('p'));
      ddcolor.appendChild(this.demoColor);
      this.customColor = $G(document.createElement('p'));
      ddcolor.appendChild(this.customColor);
      t++;
      c++;
      for (r = 0; r < self.cols; r++) {
        a = $G(document.createElement('a'));
        this.customColor.appendChild(a);
        a.id = 'color_' + c + '_' + r;
        a.tabIndex = t;
        a.className = 'item';
        a.addEvent('click', function() {
          self.doClick(this.title);
        });
        a.addEvent('mouseover', function() {
          self.showDemo(this.title);
        });
        a.addEvent('keydown', _dokeydown);
      }
    },
    doClick: function(c) {
      this.ddcolor.hide();
      if (c == 'Clear') {
        c = '';
      }
      this.color = c;
      this.onchanged.call(this, c);
      this.input.focus();
    },
    pickColor: function(c) {
      if (this.customColor) {
        var n,
          c = new Color(c),
          rgb = c.toArray(),
          m = Math.min(rgb[0], rgb[1], rgb[2]),
          o = Math.floor((255 - m) / this.cols);
        forEach(this.customColor.elems('a'), function(item, index) {
          n = c.lighten(o * index);
          item.title = n.toString();
          item.style.backgroundColor = n.toString();
          item.style.color = n.invert().toString();
        });
      }
    },
    showDemo: function(c) {
      if (this.demoColor) {
        var a;
        if (c == 'Transparent') {
          c = 'transparent';
          a = trans('Transparent');
        } else if (c == 'Clear') {
          c = 'transparent';
          a = trans('Remove Color');
        } else {
          a = c;
        }
        this.demoColor.style.backgroundColor = c;
        this.demoColor.innerHTML = a;
        this.demoColor.style.color = this.invertColor(c);
      }
    },
    setColor: function(c) {
      if (c != '' && c != this.color && this.color_format.test(c)) {
        this.doClick(c.toUpperCase());
      }
    },
    getColor: function() {
      return this.color;
    },
    invertColor: function(c) {
      if (c.toLowerCase() == 'transparent') {
        return this.ddcolor.getDropdown().style.color;
      } else {
        return new Color(c).invert().toString();
      }
    }
  };
  window.GLightbox = GClass.create();
  GLightbox.prototype = {
    initialize: function(options) {
      this.id = 'gslide_div';
      this.btnclose = 'btnclose';
      this.backgroundClass = 'modalbg';
      this.previewClass = 'gallery_preview';
      this.loadingClass = 'spinner';
      this.onshow = null;
      this.onhide = null;
      this.onclose = null;
      this.ondownload = null;
      for (var property in options) {
        this[property] = options[property];
      }
      var self = this;
      var checkESCkey = function(e) {
        var k = GEvent.keyCode(e);
        if (k == 27) {
          self.hide(e);
        } else if (k == 37) {
          self.showPrev(e);
        } else if (k == 39) {
          self.showNext(e);
        }
      };
      var container_div = 'GLightbox_' + this.id,
        doc = $G(document);
      doc.addEvent('keydown', checkESCkey);
      if (!$E(container_div)) {
        var div = doc.createElement('div');
        doc.body.appendChild(div);
        div.id = container_div;
        div.style.left = '100%';
        div.style.top = '0';
        div.style.width = '100%';
        div.style.height = '100vh';
        div.style.position = 'fixed';
        div.style.zIndex = 1000;
        var c = doc.createElement('div');
        div.appendChild(c);
        c.className = this.id;
        c.style.position = 'fixed';
        var c2 = doc.createElement('figure');
        c.appendChild(c2);
        c2.className = this.previewClass;
        this.img = doc.createElement('img');
        this.img.alt = '';
        c2.appendChild(this.img);
        new GDragMove(c, this.img);
        c = doc.createElement('figcaption');
        div.appendChild(c);
        this.loading = doc.createElement('span');
        div.appendChild(this.loading);
        this.loading.className = this.loadingClass;
        this.caption = doc.createElement('p');
        c.appendChild(this.caption);
        var btnclose = doc.createElement('span');
        div.appendChild(btnclose);
        btnclose.className = this.btnclose;
        btnclose.title = trans('Close');
        callClick(btnclose, function() {
          self.hide();
        });
        this.zoom = doc.createElement('span');
        div.appendChild(this.zoom);
        this.zoom.id = 'GLightbox_zoom';
        callClick(this.zoom, function(e) {
          self._fullScreen(e);
        });
        this.prev = doc.createElement('span');
        div.appendChild(this.prev);
        this.prev.className = 'hidden';
        this.prev.title = trans('Prev');
        callClick(this.prev, function() {
          self.showPrev();
        });
        this.next = doc.createElement('span');
        div.appendChild(this.next);
        this.next.className = 'hidden';
        this.next.title = trans('Next');
        callClick(this.next, function() {
          self.showNext();
        });
        this.download = doc.createElement('a');
        div.appendChild(this.download);
        this.download.title = trans('Download');
        if (Object.isFunction(this.ondownload)) {
          callClick(this.download, function() {
            self.ondownload(this.href);
            return false;
          });
        }
      }
      this.zoom = $E('GLightbox_zoom');
      this.div = $G(container_div);
      this.body = $G(this.div.firstChild);
      this.preview = $G(this.body.firstChild);
      this.img = this.preview.firstChild;
      this.body.style.overflow = 'hidden';
      this.currentId = 0;
      this.imgs = [];
    },
    clear: function() {
      this.currentId = 0;
      this.imgs.length = 0;
    },
    add: function(a) {
      var img = $E(a);
      img['data-id'] = this.imgs.length;
      this.imgs.push(img);
      var self = this;
      callClick(img, function() {
        if (this.drag !== true) {
          self.currentId = floatval(this['data-id']);
          self.show(this, false);
        }
        return false;
      });
    },
    showNext: function() {
      if (this.div.style.display == 'block' && this.imgs.length > 0) {
        this.currentId++;
        if (this.currentId >= this.imgs.length) {
          this.currentId = 0;
        }
        var img = this.imgs[this.currentId];
        this.show(img, false);
      }
    },
    showPrev: function() {
      if (this.div.style.display == 'block' && this.imgs.length > 0) {
        this.currentId--;
        if (this.currentId < 0) {
          this.currentId = this.imgs.length - 1;
        }
        var img = this.imgs[this.currentId];
        this.show(img, false);
      }
    },
    _fullScreen: function() {
      if (this.div.style.display == 'block' && this.imgs.length > 0) {
        var img = this.imgs[this.currentId];
        this.show(img, this.zoom.className == 'btnnav zoomout');
      }
    },
    show: function(obj, fullscreen) {
      var img,
        title,
        self = this;
      if (obj.href) {
        img = obj.href;
        title = obj.title;
      } else if (obj.src) {
        img = obj.src;
        title = obj['data-id'];
      } else {
        img = obj.style.backgroundImage.substr(5, obj.style.backgroundImage.length - 7);
        title = obj.title;
      }
      this.overlay();
      this.zoom.className = fullscreen ? 'btnnav zoomin' : 'btnnav zoomout';
      this.zoom.title = trans(fullscreen ? 'fit screen' : 'full image');
      this.loading.className = this.loadingClass + ' show';
      if (this.currentId == 0) {
        this.prev.addClass('hide');
      } else if (this.prev.className != 'btnnav prev') {
        this.prev.className = 'btnnav prev hide';
      }
      if (this.currentId == this.imgs.length - 1) {
        this.next.addClass('hide');
      } else if (this.next.className != 'btnnav next') {
        this.next.className = 'btnnav next hide';
      }
      var ds = /.*\/([^\/]+\.[a-zA-Z]{3,4})$/.exec(img);
      if (ds) {
        this.download.className = 'btnnav download';
        this.download.href = img;
        this.download.download = ds[1];
      } else {
        this.download.className = 'hidden';
      }
      window.setTimeout(function() {
        if (self.currentId == 0) {
          self.prev.className = 'hidden';
        } else {
          self.prev.className = 'btnnav prev';
        }
        if (self.currentId == self.imgs.length - 1) {
          self.next.className = 'hidden';
        } else {
          self.next.className = 'btnnav next';
        }
      }, 500);
      new preload(img, function() {
        self.loading.className = self.loadingClass;
        self.img.src = this.src;
        if (!fullscreen) {
          var w = this.width;
          var h = this.height;
          var dm = self.body.getDimensions();
          var hOffset =
            dm.height -
            self.body.getClientHeight() +
            parseInt(self.body.getStyle('marginTop')) +
            parseInt(self.body.getStyle('marginBottom'));
          var wOffset =
            dm.width -
            self.body.getClientWidth() +
            parseInt(self.body.getStyle('marginLeft')) +
            parseInt(self.body.getStyle('marginRight'));
          var src_h = document.viewport.getHeight() - hOffset - 20;
          var src_w = document.viewport.getWidth() - wOffset - 20;
          var nw, nh;
          if (h > src_h) {
            nh = src_h;
            nw = (src_h * w) / h;
          } else if (w > src_w) {
            nw = src_w;
            nh = (src_w * h) / w;
          } else {
            nw = w;
            nh = h;
          }
          if (nw > src_w) {
            nw = src_w;
            nh = (src_w * h) / w;
          } else if (nh > src_h) {
            nh = src_h;
            nw = (src_h * w) / h;
          }
          self.img.style.width = nw + 'px';
          self.img.style.height = nh + 'px';
        } else {
          self.img.style.width = 'auto';
          self.img.style.height = 'auto';
        }
        if (title && title != '') {
          self.caption.innerHTML = title.replace(/[\n]/g, '<br>');
          self.caption.parentNode.className = 'show';
        } else {
          self.caption.parentNode.className = '';
        }
        self.div.style.display = 'block';
        self.div.firstChild.center();
        self.div.style.left = 0;
        self.div.fadeIn(function() {
          self._show.call(self);
        });
      });
      return this;
    },
    hide: function() {
      if (Object.isFunction(this.onhide)) {
        this.onhide.call(this);
      }
      var self = this;
      this.div.fadeOut();
      this.iframe.fadeOut(function() {
        self._hide.call(self);
      });
      return this;
    },
    overlay: function() {
      var frameId = 'iframe_' + this.div.id,
        self = this;
      if (!$E(frameId)) {
        var io = $G(document.body).create('iframe', {
          id: frameId,
          height: '100%',
          frameBorder: 0
        });
        io.setStyle('position', 'absolute');
        io.setStyle('zIndex', 999);
        io.className = this.backgroundClass;
        io.style.display = 'none';
      }
      this.iframe = $G(frameId);
      if (this.iframe.style.display == 'none') {
        this.iframe.style.left = '0px';
        this.iframe.style.top = '0px';
        this.iframe.style.display = 'block';
        this.iframe.fadeIn();
        $G(self.iframe.contentWindow.document).addEvent('click', function(e) {
          self.hide();
        });
        var d = $G(document).getDimensions();
        this.iframe.style.height = d.height + 'px';
        this.iframe.style.width = '100%';
      }
      return this;
    },
    _show: function() {
      if (Object.isFunction(this.onshow)) {
        this.onshow.call(this);
      }
    },
    _hide: function() {
      this.iframe.style.display = 'none';
      this.div.style.display = 'none';
      if (Object.isFunction(this.onclose)) {
        this.onclose.call(this);
      }
    }
  };
  window.callClick = function(input, func) {
    var doKeyDown = function(e) {
      if (GEvent.keyCode(e) == 13 || e.key == 'Enter') {
        if (func.call(this, e) !== true) {
          GEvent.stop(e);
          return false;
        }
      }
    };
    input = $E(input);
    if (input && input.onclick == null) {
      input.onclick = func;
      input.style.cursor = 'pointer';
      input.tabIndex = 0;
      $G(input).addEvent('keydown', doKeyDown);
    }
  };
  var GElement = new GNative();
  window.$G = function(e) {
    return Object.isGElement(e) ? e : GElement.init(e);
  };
  window.$E = function(e) {
    e = Object.isString(e) ? document.getElementById(e) : e;
    return Object.isObject(e) ? e : null;
  };
  var loadCompleted = function() {
    domloaded = true;
    if (document.addEventListener) {
      document.removeEventListener('DOMContentLoaded', loadCompleted, false);
      window.removeEventListener('load', loadCompleted, false);
    } else {
      document.detachEvent('onreadystatechange', loadCompleted);
      window.detachEvent('onload', loadCompleted);
    }
    $G(document);
    $G(document.body);
  };
  if (document.addEventListener) {
    document.addEventListener('DOMContentLoaded', loadCompleted, false);
    window.addEventListener('load', loadCompleted, false);
  } else {
    document.attachEvent('onreadystatechange', loadCompleted);
    window.attachEvent('onload', loadCompleted);
  }
  return $K;
})();
