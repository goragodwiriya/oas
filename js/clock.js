/**
 * Clock
 * Javascript realtime Clock
 *
 * @filesource js/clock.js
 * @link https://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 */
(function() {
  "use strict";
  window.Clock = GClass.create();
  Clock.prototype = {
    initialize: function(id, options) {
      this.options = {
        reverse: false,
        onTimer: null,
        onTimeOut: null
      };
      for (var property in options) {
        this.options[property] = options[property];
      }
      this.hour_offset = 0;
      this.display = $E(id);
      if (this._getDisplay() == "") {
        this._setDisplay(new Date().format("H:I:S"));
      }
      var temp = this;
      this.clock = window.setInterval(function() {
        temp._updateTime.call(temp);
      }, 1000);
    },
    hourOffset: function(val) {
      var d = new Date();
      var Second = d.getSeconds();
      var Minute = d.getMinutes();
      var Hour = d.getHours();
      Hour += floatval(val);
      if (Hour >= 24) {
        Hour = 0;
      }
      this._setDisplay(Hour.toString().leftPad(2, "0") + ":" + Minute.toString().leftPad(2, "0") + ":" + Second.toString().leftPad(2, "0"));
      return this;
    },
    stop: function() {
      window.clearInterval(this.clock);
    },
    _updateTime: function() {
      var ds = this._getDisplay().split(":"),
        Hour = floatval(ds[0]),
        Minute = floatval(ds[1]),
        Second = floatval(ds[2]);
      if (this.options.reverse) {
        Second--;
        if (Hour <= 0 && Minute <= 0 && Second <= 0) {
          Hour = 0;
          Minute = 0;
          Second = 0;
          this.stop();
          if (Object.isFunction(this.options.onTimeOut)) {
            this.options.onTimeOut.call(this);
          }
        } else {
          if (Second < 0) {
            Second = 59;
            Minute--;
          }
          if (Minute < 0) {
            Minute = 59;
            Hour--;
          }
        }
      } else {
        Second++;
        if (Second >= 60) {
          Second = 0;
          Minute++;
        }
        if (Minute >= 60) {
          Minute = 0;
          Hour++;
        }
        if (Hour >= 24) {
          Hour = 0;
        }
      }
      this._setDisplay(Hour.toString().leftPad(2, "0") + ":" + Minute.toString().leftPad(2, "0") + ":" + Second.toString().leftPad(2, "0"));
      if (Object.isFunction(this.options.onTimer)) {
        this.options.onTimer.call(this, Hour, Minute, Second);
      }
    },
    _getDisplay: function() {
      if (this.display.value) {
        return this.display.value;
      }
      return this.display.innerHTML;
    },
    _setDisplay: function(val) {
      if (this.display.value) {
        this.display.value = val;
      } else {
        this.display.innerHTML = val;
      }
    }
  };
})();
