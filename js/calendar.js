/**
 * Calendar
 * Javascript Calendar Component
 *
 * @filesource js/calendar.js
 * @link https://www.kotchasan.com/
 * @copyright 2018 Goragod.com
 * @license https://www.kotchasan.com/license/
 */
window.Calendar = GClass.create();
Calendar.prototype = {
  initialize: function(id, o) {
    this.id = id;
    this.url = null;
    this.params = '';
    this.onclick = $K.emptyFunction;
    this.events = {};
    this.cdate = new Date();
    this.calendar = $G(document.createElement('div'));
    this.buttons = $G(document.createElement('div'));
    this.calendar.className = 'event-calendar';
    this.buttons.className = 'event-calendar-buttons';
    this.buttonFormat = 'M';
    this.minYear = this.cdate.getFullYear();
    this.maxYear = this.minYear;
    this.showToday = false;
    this.first_day_of_calendar = null;
    this.next_day_of_calendar = null;
    this.showButton = false;
    for (var property in o) {
      if (property == 'month') {
        this.cdate.setMonth(floatval(o[property]) - 1);
      } else if (property == 'year') {
        this.cdate.setFullYear(floatval(o[property]));
      } else if (property == 'class') {
        this.calendar.className = o[property];
      } else {
        this[property] = o[property];
      }
    }
    $E(id).appendChild(this.buttons);
    $E(id).appendChild(this.calendar);
    var modal = document.createElement('div'),
      span = document.createElement('span');
    span.title = trans('Close');
    span.innerHTML = '&times;';
    span.className = 'closebtn';
    modal.className = 'event-calendar-modal';
    modal.appendChild(span);
    document.body.appendChild(modal);
    this.modal = $G(document.createElement('div'));
    $G(modal).appendChild(this.modal);
    self = this;
    span.onclick = function() {
      modal.removeClass('show');
    };
    $G(window).addEvent('resize', function() {
      self._resize();
    });
    this.setDate(this.cdate);
    Object.defineProperty(this, 'min', {
      get: function() {
        return self.minYear;
      },
      set: function(value) {
        self.minYear = value;
        self._resize();
      }
    });
    Object.defineProperty(this, 'max', {
      get: function() {
        return self.maxYear;
      },
      set: function(value) {
        self.maxYear = value;
        self._resize();
      }
    });
    return this;
  },
  moveTo: function(y, m) {
    var d = new Date();
    if (m) {
      d.setMonth(floatval(m) - 1);
    }
    if (y) {
      d.setFullYear(floatval(y));
    }
    this.setDate(d);
  },
  _resize: function() {
    var cw = this.calendar.getClientWidth(),
      w = cw / 7;
    document.css('#' + this.id + ' td div{width:' + w + 'px}#' + this.id + ' td{width:' + w + 'px;height:' + w + 'px}', this.id);
  },
  _drawMonth: function() {
    this.modal.parentNode.removeClass('show');
    var self = this,
      header = document.createElement('div');
    header.className = 'header';
    this.calendar.innerHTML = '';
    this.calendar.appendChild(header);
    this.first_day_of_calendar = null;
    this.next_day_of_calendar = null;
    var a = document.createElement('a');
    a.className = 'prev';
    a.title = trans('Prev Month');
    header.appendChild(a);
    callClick(a, function() {
      self._move(-1);
    });
    var label = document.createElement('label'),
      m = this.cdate.getMonth(),
      y = this.cdate.getFullYear(),
      cYear = document.createElement('select'),
      cMonth = document.createElement('select');
    label.className = 'curr';
    for (var i = 0; i < 12; i++) {
      var option = document.createElement('option');
      option.innerHTML = Date.longMonthNames[i];
      option.value = i;
      if (i == m) {
        option.selected = true;
      }
      cMonth.appendChild(option);
    }
    var _yearChanged = function() {
      self.moveTo(cYear.value, parseInt(cMonth.value) + 1);
    };
    label.appendChild(cMonth);
    for (var i = Math.min(y, this.minYear); i <= Math.max(y, this.maxYear); i++) {
      var option = document.createElement('option');
      option.innerHTML = i + Date.yearOffset;
      option.value = i;
      if (i == y) {
        option.selected = true;
      }
      cYear.appendChild(option);
    }
    label.appendChild(cYear);
    header.appendChild(label);
    $G(cMonth).addEvent('change', _yearChanged);
    $G(cYear).addEvent('change', _yearChanged);
    a = document.createElement('a');
    a.className = 'next';
    a.title = trans('Next Month');
    header.appendChild(a);
    callClick(a, function() {
      self._move(1);
    });
    var table = document.createElement('table'),
      thead = document.createElement('thead'),
      tbody = document.createElement('tbody');
    this.calendar.appendChild(table);
    table.appendChild(thead);
    table.appendChild(tbody);
    var intmonth = this.cdate.getMonth() + 1,
      intyear = this.cdate.getFullYear(),
      cls = '',
      today = new Date(),
      today_month = today.getMonth() + 1,
      today_year = today.getFullYear(),
      today_date = today.getDate(),
      r = 0,
      c = 0,
      row,
      cell;
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
      tmp_month = initial_day == 1 ? intmonth : floatval(tmp_prev_month),
      tmp_year = initial_day == 1 ? intyear : floatval(tmp_prev_year),
      flag_end = 0,
      d,
      div;
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
        tmp_month = floatval(tmp_next_month);
        tmp_year = floatval(tmp_next_year);
      }
      c = x % 7;
      if (c == 0) {
        row = tbody.insertRow(r);
        r++;
      }
      cell = row.insertCell(c);
      span = document.createElement('span');
      span.innerHTML = pointer;
      cell.appendChild(span);
      div = document.createElement('div');
      d = new Date(tmp_year, tmp_month - 1, pointer, 0, 0, 0, 0);
      if (self.first_day_of_calendar === null) {
        self.first_day_of_calendar = d;
      }
      div.id = this.id + '-' + d.format('y-m-d');
      cell.appendChild(div);
      cls = tmp_month == intmonth ? 'curr' : 'ex';
      if (tmp_year == today_year && tmp_month == today_month && pointer == today_date) {
        cls += ' today';
      }
      cell.className = cls;
      pointer++;
    }
    this.next_day_of_calendar = new Date(tmp_year, tmp_month - 1, pointer, 0, 0, 0, 0);
    if (this.showToday) {
      var a = document.createElement('a');
      a.innerHTML = new Date().format('d F Y');
      a.className = 'set-today';
      this.calendar.appendChild(a);
      a.onclick = function() {
        self.setDate(new Date());
      };
    }
    this._resize();
  },
  _addLabel: function(d, prop, c) {
    var self = this,
      id = this.id + '-' + d.format('y-m-d');
    if ($E(id)) {
      var div = $G(id),
        a = document.createElement('a'),
        className = prop.class ? ' class="' + prop.class + '"' : '';
      $G(div.parentNode).addClass('mark');
      if (prop.title) {
        a.title = prop.title;
        if (c == 'sub' && d == self.first_day_of_calendar) {
          a.innerHTML = '<span' + className + '>' + prop.title + '</span>';
        } else if (c == 'sub' || c == 'last') {
          a.innerHTML = '<span>&nbsp;</span>';
        } else {
          a.innerHTML = '<span' + className + '>' + prop.title + '</span>';
        }
      } else {
        a.innerHTML = '<span>&nbsp;</span>';
      }
      if (prop.url) {
        a.href = prop.url;
      }
      if (prop.color) {
        a.style.backgroundColor = prop.color;
      }
      a.className = c;
      a.datas = prop;
      div.appendChild(a);
      a.onclick = function() {
        return self.onclick.call(this.datas, d);
      };
      var as = div.getElementsByTagName('a'),
        span = div.getElementsByTagName('address');
      if (span.length == 0) {
        span = document.createElement('address');
        div.appendChild(span);
        span.onclick = function() {
          self.modal.parentNode.style.left = '-9999px';
          self.modal.parentNode.style.top = '-9999px';
          self.modal.innerHTML = '<h1>' + d.format('d M Y') + '</h1>';
          forEach(div.getElementsByTagName('a'), function() {
            var link = this,
              a = document.createElement('a');
            if (this.datas.title) {
              a.innerHTML = this.datas.title;
            }
            if (this.datas.color) {
              a.style.backgroundColor = this.datas.color;
            }
            if (this.datas.url) {
              a.href = this.datas.url;
            }
            if (this.datas.class) {
              a.className = this.datas.class;
            }
            self.modal.appendChild(a);
            a.onclick = function() {
              return self.onclick.call(link.datas, d);
            };
          });
          self.modal.parentNode.addClass('show');
          window.setTimeout(function() {
            var l, t,
              dp = div.viewportOffset(),
              vm = div.getDimensions(),
              dm = self.modal.getDimensions(),
              vp = document.viewport;
            if (dp.left + dm.width > vp.getWidth() + vp.getscrollLeft()) {
              l = dp.left + vm.width - dm.width;
            } else {
              l = dp.left;
            }
            if (dp.top + vm.height + dm.height > vp.getHeight() + vp.getscrollTop()) {
              t = dp.top - dm.height - 10;
            } else {
              t = dp.top + vm.height;
            }
            self.modal.parentNode.style.left = Math.max(0, l) + 'px';
            self.modal.parentNode.style.top = Math.max(0, t) + 'px';
          }, 1);
        };
      } else {
        span = span[0];
      }
      if (as.length == 1) {
        span.innerText = '1 ' + trans('item');
      } else {
        span.innerText = '+' + as.length + ' ' + trans('items');
      }
      return a;
    }
    return null;
  },
  _drawEvents: function() {
    var a,
      diff,
      diff_start_first,
      diff_end_first,
      elems = [],
      top = 0,
      start,
      start_date,
      end_date,
      c,
      d,
      e,
      elem,
      td,
      self = this;
    forEach(this.events, function() {
      if (this.start) {
        if (this.type == 'holiday') {
          elem = $E(self.id + '-' + this.start);
          if (elem) {
            td = $G(elem.parentNode);
            td.addClass('holiday');
            if (this.title) {
              a = document.createElement('div');
              a.innerText = this.title;
              a.className = 'label_holiday';
              td.appendChild(a);
            }
          }
        } else {
          start_date = this.start.split('T')[0].replace(/-/g, '/').split(' ')[0];
          a = new Date(start_date);
          end_date = this.end ? new Date(this.end.split('T')[0].replace(/-/g, '/').split(' ')[0]) : a;
          diff_end_first = end_date.compare(self.first_day_of_calendar);
          diff_end_first = diff_end_first.year < 0 ? 0 - diff_end_first.days : diff_end_first.days;
          diff_start_first = a.compare(self.first_day_of_calendar);
          diff_start_first = diff_start_first.year < 0 ? 0 - diff_start_first.days : diff_start_first.days;
          diff = end_date.compare(a);
          if (
            (diff_start_first >= 0) ||
            (diff_start_first < 0 && diff_end_first > 0 && diff_end_first < 42) ||
            (diff_start_first <= 0 && diff_end_first >= 41)
          ) {
            if (diff.days == 0) {
              c = 'first last';
            } else if (
              (diff_start_first < 0 && diff_end_first > 0 && diff_end_first < 42) ||
              (diff_start_first <= 0 && diff_end_first >= 41)
            ) {
              c = diff_start_first == 0 && diff_end_first == diff.days ? 'first' : 'sub';
              a = self.first_day_of_calendar;
              start = Date.parse(a);
              diff = end_date.compare(a);
            } else {
              c = 'first';
              start = Date.parse(start_date);
            }
            e = self._addLabel(a, this, c);
            if (e) {
              elems = [e];
              top = e.offsetTop;
              for (var i = 1; i <= diff.days; i++) {
                d = new Date(start + i * 86400000);
                e = self._addLabel(d, this, i == diff.days ? 'last' : 'sub');
                if (e) {
                  if (d.getDay() == 0) {
                    self._alignElementID(elems, top);
                    elems = [e];
                    top = e.offsetTop;
                  } else {
                    elems.push(e);
                    top = Math.max(top, e.offsetTop);
                  }
                }
              }
              self._alignElementID(elems, top);
            }
          }
        }
      }
    });
  },
  _alignElementID: function(elems, top) {
    forEach(elems, function() {
      if (this.offsetTop != top) {
        if (this.offsetTop == 0) {
          this.style.marginTop = top + 'px';
        } else {
          var self = this,
            t = 0;
          forEach(this.parentNode.getElementsByTagName('a'), function() {
            if (self == this) {
              this.style.marginTop = (top - t) + 'px';
            } else {
              t += this.offsetTop + floatval(this.style.marginTop) + this.clientHeight;
            }
          });
        }
      }
    });
  },
  _get: function(d) {
    var self = this,
      q = ['month=' + (floatval(d.getMonth()) + 1), 'year=' + d.getFullYear()];
    q = (this.params == '' ? '' : this.params + '&') + q.join('&');
    new GAjax().send(this.url, q, function(xhr) {
      var ds = xhr.responseText.toJSON();
      self.cdate = d;
      self.events = ds || [];
      self._drawMonth();
      self.setEvents(ds || []);
    });
  },
  _move: function(value) {
    var d = new Date();
    d.setTime(this.cdate.valueOf());
    d.setMonth(d.getMonth() + value, 1);
    this.setDate(d);
  },
  _setButton: function() {
    var id = this.id + '-' + this.cdate.format('y-m');
    forEach(this.buttons.querySelectorAll('.button'), function() {
      if (this.id == id) {
        this.className = 'button blue select';
      } else {
        this.className = 'button blue';
      }
    });
  },
  _drawButtons: function() {
    var y = this.cdate.getFullYear(),
      self = this;

    function doClick() {
      var ds = this.id.replace(self.id + '-', '').split('-');
      self.moveTo(ds[0], ds[1]);
    }
    if (this.showButton) {
      var d, ds = {};
      self.buttons.innerHTML = '';
      forEach(this.events, function() {
        if (this.start) {
          d = new Date(this.start.split('T')[0].replace(/-/g, '/'));
          if (d.getFullYear() == y) {
            ds[d.format('y-m')] = d;
          }
        }
      });
      Object.keys(ds).sort().forEach(function(key) {
        var a = document.createElement('a');
        a.className = 'button blue';
        a.innerHTML = ds[key].format(self.buttonFormat);
        a.id = self.id + '-' + key;
        self.buttons.appendChild(a);
        a.onclick = doClick;
      });
    }
  },
  setEvents: function(events) {
    this.events = events.sort(function(a, b) {
      return new Date(a.start) - new Date(b.start);
    });
    this._drawEvents();
    this._drawButtons();
    this._setButton();
  },
  setDate: function(date) {
    if (this.url !== null) {
      this._get(date);
    } else {
      this.cdate = date;
      this._drawMonth();
      this._drawEvents();
      this._drawButtons();
      this._setButton();
    }
  },
  setParams: function(params) {
    this.params = params;
    this.setDate(this.cdate);
  }
};
