/**
 * GRange
 * Javascript range input
 *
 * @filesource js/range.js
 * @link https://www.kotchasan.com/
 * @copyright 2018 Goragod.com
 * @license https://www.kotchasan.com/license/
 */
(function() {
  "use strict";
  window.GRange = GClass.create();
  GRange.prototype = {
    initialize: function(id, o) {
      if (!$E(id)) {
        console.log("[GRange] Cannot find target element " + id);
        return;
      }
      this.input = $G(id);
      if (this.input.getAttribute('GRange')) {
        return;
      }
      this.input.setAttribute('GRange', true);
      this.rate = 100;
      for (var prop in o) {
        this[prop] = o[prop];
      }
      var minValue = floatval(this.input.get("min") || 0),
        maxValue = floatval(this.input.get("max") || 99),
        range = maxValue - minValue,
        values = [minValue, maxValue],
        step = floatval(this.input.get("step"));
      if (step == 0) {
        if (range < 1) {
          step = 0.01;
        } else if (range < 100) {
          step = 1;
        } else {
          step = Math.floor((range / 100) * 1000) / 1000;
        }
      }
      if (this.input.get("value") != null) {
        var vs = this.input.get("value").split(",");
        values[0] = floatval(vs[0]);
        if (vs[1] && vs[1] != "") {
          values[1] = floatval(vs[1]);
        }
      }
      this.input.set("type", "hidden");
      this.input.min = minValue;
      this.input.max = maxValue;
      this.input.step = step;
      this.oldvalue = {
        value: this.input.get("value"),
        minValue: minValue,
        maxValue: maxValue,
        step: step
      };
      this.slider = $G(document.createElement("div"));
      this.width = this.slider.getDimensions().width;
      this.slider.className = "grange_div";
      this.input.parentNode.appendChild(this.slider);
      if (this.input.get("range") !== null) {
        this.range = $G(document.createElement("div"));
        this.range.className = "grange_range";
        this.slider.appendChild(this.range);
      }
      this.pointerL = $G(document.createElement("div"));
      this.pointerL.className = "grange_button btnleft";
      this.pointerL.tabIndex = 0;
      this.slider.appendChild(this.pointerL);
      var HInstant = this;
      this.slider.addEvent("mousedown", function(e) {
        var elem = GEvent.element(e);
        if (elem == HInstant.slider || elem == HInstant.range) {
          var pos =
            GEvent.pointerX(e) -
            this.viewportOffset().left -
            HInstant.pointerL.getDimensions().width / 2,
            oldvalue = HInstant.getValue(),
            onchange = false,
            value,
            l = floatval(HInstant.pointerL.getStyle("left"));
          if (HInstant.pointerR && pos > l) {
            var r = floatval(HInstant.pointerR.getStyle("left"));
            if (pos > l && (pos > r || r - pos < pos - l)) {
              value = HInstant.setX(HInstant.pointerR, pos);
            } else {
              value = HInstant.setX(HInstant.pointerL, pos);
            }
            if (oldvalue[0] != value[0] || oldvalue[1] != value[1]) {
              onchange = true;
            }
          } else {
            value = HInstant.setX(HInstant.pointerL, pos);
            if (value != oldvalue) {
              onchange = true;
            }
          }
          if (onchange) {
            HInstant.input.callEvent("change");
          }
        }
      });
      this.slider.addEvent("click", function(e) {
        var elem = GEvent.element(e);
        if (
          elem &&
          (elem == HInstant.pointerL || elem == HInstant.pointerR)
        ) {
          elem.focus();
        }
      });
      this.slider.addEvent("keydown", function(e) {
        var key = GEvent.keyCode(e);
        if (key == 37 || key == 39) {
          var value = HInstant.getValue(),
            input = GEvent.element(e),
            step = floatval(HInstant.input.step),
            oldvalue = HInstant.getValue();
          if (key == 37) {
            if (HInstant.pointerR) {
              if (input == HInstant.pointerR) {
                value[1] = value[1] - step;
              } else {
                value[0] = value[0] - step;
              }
            } else {
              value = value - step;
            }
          } else if (key == 39) {
            if (HInstant.pointerR) {
              if (input == HInstant.pointerR) {
                value[1] = value[1] + step;
              } else {
                value[0] = value[0] + step;
              }
            } else {
              value = value + step;
            }
          }
          value = HInstant.setValue(value, input);
          if (HInstant.pointerR) {
            if (oldvalue[0] != value[0] || oldvalue[1] != value[1]) {
              HInstant.input.callEvent("change");
            }
          } else if (oldvalue != value) {
            HInstant.input.callEvent("change");
          }
        } else if (key == 40 && HInstant.pointerR) {
          HInstant.pointerL.focus();
          GEvent.stop(e);
        } else if (key == 38 && HInstant.pointerR) {
          HInstant.pointerR.focus();
          GEvent.stop(e);
        }
      });
      var o = {
        moveDrag: function(e) {
          var cw = HInstant.getWidth(),
            pos = Math.min(cw, Math.max(0, e.mousePos.x - e.mouseOffset.x));
          HInstant.setX(this, pos);
        },
        endDrag: function(e) {
          HInstant.input.callEvent("change");
        }
      };
      new GDragMove(this.pointerL, this.pointerL, o);
      if (this.range) {
        this.pointerR = $G(document.createElement("div"));
        this.pointerR.className = "grange_button btnright";
        this.pointerR.tabIndex = 0;
        this.slider.appendChild(this.pointerR);
        new GDragMove(this.pointerR, this.pointerR, o);
        this.setValue(values, null);
      } else {
        this.pointerR = null;
        this.setValue(values[0], null);
      }
      this.timer = window.setInterval(function() {
        if (!$E(HInstant.input)) {
          window.clearInterval(HInstant.timer);
        } else {
          var w = HInstant.slider.getDimensions().width;
          if (w != HInstant.width) {
            HInstant.width = w;
            HInstant.setValue(HInstant.getValue(), null);
          } else if (
            HInstant.oldvalue.value != HInstant.input.value ||
            HInstant.oldvalue.minValue != HInstant.input.min ||
            HInstant.oldvalue.maxValue != HInstant.input.max ||
            HInstant.oldvalue.step != HInstant.input.step
          ) {
            HInstant.setValue(HInstant.getValue(), null);
          }
        }
      }, 100);
    },
    setX: function(button, pos) {
      button.style.left = pos + "px";
      var val =
        floatval(this.input.min) + (this.getRange() * pos) / this.getWidth();
      if (this.pointerR) {
        var values = this.getValue();
        if (this.pointerL == button) {
          values[0] = val;
          if (val > values[1]) {
            values[1] = val;
          }
        } else {
          values[1] = val;
          if (val < values[0]) {
            values[0] = val;
          }
        }
        this.setValue(values, button);
      } else {
        this.setValue(val, button);
        values = val;
      }
      window.setTimeout(function() {
        button.focus();
      }, 1);
      return values;
    },
    getWidth: function() {
      return (
        this.slider.getDimensions().width - this.pointerL.getDimensions().width
      );
    },
    getRange: function() {
      return floatval(this.input.max) - floatval(this.input.min);
    },
    getValue: function() {
      if (this.pointerR) {
        var values = this.input.value.split(",");
        return [floatval(values[0]), floatval(values[1])];
      } else {
        return floatval(this.input.value);
      }
    },
    calcValue: function(value) {
      var step = floatval(this.input.step),
        ss = this.input.step.split(".");
      value = Math.round(value / step) * step;
      value = Math.min(floatval(this.input.max), value);
      value = Math.max(floatval(this.input.min), value);
      if (ss[1] && ss[1] != "") {
        value = floatval(value.toFixed(ss[1].length));
      }
      return value;
    },
    setValue: function(value, button) {
      var oldvalue = this.getValue(),
        cw = this.getWidth(),
        range = this.getRange(),
        min = floatval(this.input.min),
        oninput = false,
        bw = this.pointerL.getDimensions().width;
      if (this.pointerR) {
        value[0] = this.calcValue(value[0]);
        value[1] = this.calcValue(value[1]);
        if (button != null && value[1] < value[0]) {
          if (button == this.pointerR) {
            value[0] = value[1];
          } else if (button == this.pointerL) {
            value[1] = value[0];
          }
        }
        var l = value[0] - min,
          r = value[1] - min;
        this.pointerR.style.left = (cw * r) / range + "px";
        this.range.style.left = bw / 2 + (cw * l) / range + "px";
        this.range.style.width = (cw * (r - l)) / range + "px";
        this.input.value = value.join(",");
        if (oldvalue[0] != value[0] || oldvalue[1] != value[1]) {
          oninput = true;
        }
      } else {
        value = this.calcValue(value);
        var l = value - min;
        this.input.value = value;
        if (oldvalue != value) {
          oninput = true;
        }
      }
      this.oldvalue.value = this.input.value;
      this.pointerL.style.left = (cw * l) / range + "px";
      if (oninput) {
        this.input.callEvent("input");
      }
      return value;
    }
  };
})();