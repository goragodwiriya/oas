/**
 * GGraphs
 * Javascript HTML5 graphs
 *
 * @filesource js/ggraph.js
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */
(function () {
  'use strict';
  window.GGraphs = GClass.create();
  GGraphs.prototype = {
    initialize: function (id, o) {
      this.graphs = $G(id);
      this.graphs.addClass('ggraphs');
      this.graphs.style.position = 'relative';
      this.graphs.setStyle('padding', 0);
      this.canvas = $G(this.graphs.getElementsByTagName('canvas')[0]);
      this.hoverItem = null;
      this.options = {
        type: 'line',
        rows: 5,
        colors: ['#438AEF', '#FBB242', '#DE4210', '#259B24', '#E91E63', '#1F3D68', '#FEE280', '#1A9ADC', '#C86A4C', '#055CDA', '#F2D086', '#51627F', '#F0B7A6', '#DE8210', '#7791BC'],
        startColor: 0,
        backgroundColor: 'auto',
        shadowColor: 'rgba(0,0,0,0.3)',
        fontColor: 'auto',
        gridHColor: '#CDCDCD',
        gridVColor: '#CDCDCD',
        showTitle: true,
        lineWidth: 2,
        centerOffset: 10,
        centerX: null,
        centerY: null,
        labelOffset: 5,
        ringWidth: 30,
        rotate: false,
        strokeColor: '#000000'
      };
      for (var property in o) {
        this.options[property] = o[property];
      }
      this.context = this.canvas.getContext('2d');
      var self = this;
      var options = this.options;
      if (options.startColor > 0) {
        var temp = new Array();
        var l = options.colors.length;
        var i = Math.max(0, Math.min(l - 1, options.startColor));
        for (var a = 0; a < l; a++) {
          temp.push(options.colors[i]);
          i = i < l - 1 ? i + 1 : 0;
        }
        options.colors = temp;
      }
      this.datas = new Object();
      var datas = new Array();
      forEach(this.graphs.getElementsByTagName('thead')[0].getElementsByTagName('th'), function (item, index) {
        if (index > 0) {
          datas.push(item.innerHTML.strip_tags());
        }
      });
      this.subtitle = this.graphs.getElementsByTagName('thead')[0].getElementsByTagName('th')[0].innerHTML.strip_tags().trim();
      this.subtitle = this.subtitle == '' ? '' : this.subtitle + ' ';
      this.datas.labels = datas;
      this.max = 0;
      var rows = new Array();
      forEach(this.graphs.getElementsByTagName('tbody')[0].getElementsByTagName('tr'), function () {
        var val,
          datas = new Array(),
          d = new Object(),
          max = 0,
          sum = 0;
        forEach(this.getElementsByTagName('td'), function () {
          val = new Object();
          if (this.dataset.value) {
            val.value = floatval(this.dataset.value);
          } else {
            val.value = floatval(this.innerHTML.replace(/,/g, ''));
          }
          val.title = this.innerHTML.strip_tags();
          if (this.dataset.tooltip) {
            val.tooltip = this.dataset.tooltip;
          }
          sum = sum + val.value;
          max = Math.max(max, val.value);
          datas.push(val);
        });
        d.title = this.getElementsByTagName('th')[0].innerHTML.strip_tags();
        d.items = datas;
        d.total = sum;
        d.max = max;
        rows.push(d);
        self.max = Math.max(max, self.max);
      });
      this.datas.rows = rows;
      if (this.max % options.rows) {
        this.max = Math.ceil(this.max / options.rows) * options.rows;
      }
      var _mouseMove = function (e) {
        var currItem = null;
        var offset = self.canvas.viewportOffset();
        var pos = GEvent.pointer(e);
        var mouseX = pos.x - offset.left;
        var mouseY = pos.y - offset.top;
        var tootip = new Array();
        forEach(self.datas.rows, function (rows, row) {
          forEach(this.items, function (item, index) {
            if (mouseX >= item.x && mouseX <= item.w && mouseY >= item.y && mouseY <= item.h) {
              currItem = item;
              if (item.tooltip) {
                tootip.push(item.tooltip);
              } else {
                tootip.push('<b>' + self.subtitle + self.datas.labels[index] + '</b> ' + rows.title + ' ' + item.title);
              }
              return true;
            }
          });
        });
        if (!currItem) {
          if (self.hoverItem) {
            self.canvas.style.cursor = 'default';
            self.tooltip.hide();
            self.hoverItem = null;
          }
        } else if (self.hoverItem !== currItem) {
          self.canvas.style.cursor = 'pointer';
          self.hoverItem = currItem;
          self.tooltip.innerHTML = tootip.join('<br>');
          var rc = self.tooltip.getDimensions(),
            l = pos.x - 20;
          if (l > document.viewport.getWidth() / 2) {
            l = pos.x - rc.width + 20;
            self.tooltip.className = 'tooltip-bottom-right';
          } else {
            self.tooltip.className = 'tooltip-bottom-left';
          }
          self.tooltip.style.left = l + 'px';
          self.tooltip.style.top = (pos.y - 16 - rc.height) + 'px';
          self.tooltip.fadeIn();
          self.tooltip.show();
        }
      };
      this.loading = true;
      var transparent = /rgba\([0-9a-fA-F,\s]+0\)/;
      var _change = function () {
        var val, changed = false;
        val = self.getFontSize();
        if (val != self.fontSize) {
          self.fontSize = val;
          changed = true;
        }
        val = self.graphs.getStyle('color');
        if (val != self.fontColor) {
          self.fontColor = val;
          changed = true;
        }
        val = self.graphs.getStyle('backgroundColor');
        if (val == 'transparent' || transparent.test(val)) {
          val = $G(document.body).getStyle('backgroundColor');
        }
        if (val != self.backgroundColor) {
          self.backgroundColor = val;
          changed = true;
        }
        val = self.canvas.getWidth();
        if (val != self.width) {
          self.width = val;
          changed = true;
        }
        val = self.canvas.getHeight();
        if (val != self.height) {
          self.height = val;
          changed = true;
        }
        if (changed) {
          try {
            if (options.type == 'line') {
              self.drawLine();
            } else if (options.type == 'pie') {
              self.drawPie();
            } else if (options.type == 'donut') {
              self.drawDonut();
            } else if (options.type == 'hchart') {
              self.drawHChart();
            } else {
              self.drawVChart();
            }
          } catch (err) {
          }
        }
        if (self.loading) {
          if (options.type !== 'pie' && options.type !== 'donut') {
            self.canvas.addEvent('mousemove', _mouseMove);
          }
          self.loading = false;
        }
      };
      window.setInterval(_change, 50);
      if ($E('ggraph_tooltip')) {
        this.tooltip = $G('ggraph_tooltip');
      } else {
        this.tooltip = $G(document.createElement('div'));
        document.body.appendChild(this.tooltip);
        this.tooltip.className = 'tooltip-bottom';
        this.tooltip.id = 'ggraph_tooltip';
        this.tooltip.hide();
        $G(document.body).addEvent('click', function () {
          self.tooltip.hide();
        });
      }
    },
    drawLine: function () {
      this.clear();
      var options = this.options;
      var self = this;
      var context = this.context;
      var offsetRight = Math.ceil(context.measureText(this.datas.labels[this.datas.labels.length - 1]).width / 2);
      var label = this.max;
      var labelValue = this.max / options.rows;
      if (labelValue > 1) {
        labelValue = Math.floor(labelValue);
      }
      var l = 0;
      for (var i = 0; i < options.rows; i++) {
        l = Math.max(l, context.measureText(label).width);
        label = label - labelValue;
      }
      l = l + 15;
      var t = Math.ceil(this.fontSize / 2);
      var r = this.width - offsetRight - 5;
      var b = this.height - this.fontSize - options.labelOffset;
      var rows = options.rows;
      var cols = Math.max(2, this.datas.labels.length);
      var cellWidth = Math.floor((r - l) / (cols - 1));
      var cellHeight = Math.floor((b - t) / rows);
      r = (cellWidth * (cols - 1)) + l;
      b = (cellHeight * rows) + t;
      var clientHeight = b - t;
      var o = options.lineWidth + 2;
      forEach(this.datas.rows, function () {
        forEach(this.items, function (item, index) {
          item.cx = (index * cellWidth) + l;
          item.cy = clientHeight + t - Math.floor((clientHeight * item.value) / self.max);
          item.x = item.cx - o;
          item.y = item.cy - o;
          item.w = item.cx + o;
          item.h = item.cy + o;
        });
      });
      function drawGraph() {
        var y = t;
        context.lineWidth = 1;
        context.textAlign = 'right';
        context.textBaseline = 'middle';
        context.fillStyle = self.fontColor;
        var label = self.max;
        var labelValue = self.max / rows;
        if (labelValue > 1) {
          labelValue = Math.floor(labelValue);
        }
        for (var i = 0; i <= rows; i++) {
          context.fillText(label, l - 10, y);
          if (options.gridVColor && i > 0 && i < rows) {
            context.strokeStyle = options.gridVColor;
            context.beginPath();
            context.moveTo(l, y);
            context.lineTo(r, y);
            context.stroke();
            context.closePath();
          }
          y = y + cellHeight;
          label = label - labelValue;
        }
        var x = l;
        context.textAlign = 'center';
        context.textBaseline = 'bottom';
        context.fillStyle = self.fontColor;
        forEach(self.datas.labels, function (item, index) {
          if (options.gridHColor && index > 0 && index < cols - 1) {
            context.strokeStyle = options.gridHColor;
            context.beginPath();
            context.moveTo(x, t);
            context.lineTo(x, b);
            context.stroke();
            context.closePath();
          }
          if (options.rotate) {
            var metric = context.measureText(item);
            var y = self.height - metric.width + 35;
            var xx = x + (self.fontSize / 2);
            context.save();
            context.translate(xx, y);
            context.rotate(-Math.PI / 2);
            context.translate(-xx, -y);
            context.fillText(item, xx, y);
            context.restore();
          } else {
            context.fillText(item, x, self.height);
          }
          x = x + cellWidth;
        });
        context.strokeStyle = self.fontColor;
        context.beginPath();
        context.moveTo(l, t);
        context.lineTo(r, t);
        context.lineTo(r, b);
        context.lineTo(l, b);
        context.lineTo(l, t);
        context.stroke();
        context.closePath();
        var xp, yp;
        context.lineWidth = Math.max(1, options.lineWidth);
        forEach(self.datas.rows, function (rows, row) {
          forEach(rows.items, function (item, index) {
            if (index > 0) {
              context.strokeStyle = options.colors[row % options.colors.length];
              context.beginPath();
              context.moveTo(xp, yp);
              context.lineTo(item.cx, item.cy);
              context.stroke();
              context.closePath();
            }
            xp = item.cx;
            yp = item.cy;
          });
          forEach(this.items, function () {
            context.fillStyle = options.colors[row % options.colors.length];
            context.beginPath();
            context.arc(this.cx, this.cy, options.lineWidth + 3, 0, Math.PI * 2, true);
            context.fill();
            context.fillStyle = self.backgroundColor;
            context.beginPath();
            context.arc(this.cx, this.cy, 3, 0, Math.PI * 2, true);
            context.fill();
          });
        });
        self.drawTitle(r, t);
      }
      drawGraph();
    },
    drawPie: function () {
      this.clear();
      var options = this.options;
      var self = this;
      var context = this.context;
      var centerX = options.centerX == null ? Math.round(this.width / 2) : options.centerX;
      var centerY = options.centerY == null ? Math.round(this.height / 2) : options.centerY;
      var radius = centerY - options.centerOffset;
      var counter = 0.0;
      var chartStartAngle = -.5 * Math.PI;
      var sum = this.datas.rows[0].total;
      forEach(this.datas.rows[0].items, function (item, index) {
        var fraction = item.value / sum;
        item.startAngle = (counter * Math.PI * 2);
        item.endAngle = ((counter + fraction) * Math.PI * 2);
        item.midAngle = (counter + fraction / 2);
        item.percentage = Math.round(fraction * 100);
        counter += fraction;
      });
      function drawSlice(slice, index) {
        if (slice.percentage) {
          var distance = (radius / 2.5) * (Math.pow(1 - (2.5 / radius), 0.8) + 1) + options.labelOffset;
          var labelX = Math.round(centerX + Math.sin(slice.midAngle * Math.PI * 2) * distance);
          var labelY = Math.round(centerY - Math.cos(slice.midAngle * Math.PI * 2) * distance);
          var c = options.colors[index % options.colors.length];
          context.strokeStyle = c;
          context.beginPath();
          context.moveTo(centerX, centerY);
          context.lineTo(labelX, labelY);
          if (labelX < 180) {
            context.lineTo(labelX - 5, labelY);
            context.textAlign = 'right';
            labelX -= 10;
          } else {
            context.lineTo(labelX + 5, labelY);
            context.textAlign = 'left';
            labelX += 10;
          }
          context.textBaseline = 'middle';
          context.stroke();
          context.closePath();
          context.fillStyle = c;
          if (options.strokeColor) {
            context.strokeStyle = options.strokeColor;
            context.strokeText(slice.value, labelX, labelY);
          }
          context.fillText(slice.value, labelX, labelY);
        }
        var startAngle = slice.startAngle + chartStartAngle;
        var endAngle = slice.endAngle + chartStartAngle;
        context.beginPath();
        context.moveTo(centerX, centerY);
        context.arc(centerX, centerY, radius, startAngle, endAngle, false);
        context.lineTo(centerX, centerY);
        context.closePath();
        context.fillStyle = options.colors[index % options.colors.length];
        context.fill();
        context.lineWidth = 0;
        context.strokeStyle = self.backgroundColor;
        context.stroke();
      }
      function drawGraph() {
        context.save();
        context.fillStyle = self.backgroundColor;
        context.beginPath();
        context.arc(centerX, centerY, radius + 2, 0, Math.PI * 2, false);
        context.fill();
        context.restore();
        forEach(self.datas.rows[0].items, function (item, index) {
          drawSlice(item, index);
        });
        if (options.showTitle) {
          var x = self.width - 10;
          var t = 10;
          var y = t + self.fontSize;
          x = x - self.fontSize - 10;
          context.textAlign = 'right';
          context.textBaseline = 'middle';
          context.lineWidth = 1;
          var offset = self.fontSize / 2;
          forEach(self.datas.labels, function (item, index) {
            context.fillStyle = options.colors[index % options.colors.length];
            context.fillRect(x, y, self.fontSize, self.fontSize);
            context.fillStyle = self.fontColor;
            context.fillText(item, x - 5, y + offset);
            y = y + self.fontSize + 5;
          });
        }
      }
      drawGraph();
      var _mouseMove = function (e) {
        var currItem = null;
        var offset = self.canvas.viewportOffset();
        var pos = GEvent.pointer(e);
        var mouseX = pos.x - offset.left;
        var mouseY = pos.y - offset.top;
        var xFromCenter = mouseX - centerX;
        var yFromCenter = mouseY - centerY;
        var distanceFromCenter = Math.sqrt(Math.pow(Math.abs(xFromCenter), 2) + Math.pow(Math.abs(yFromCenter), 2));
        if (distanceFromCenter <= radius) {
          var mouseAngle = Math.atan2(yFromCenter, xFromCenter) - chartStartAngle;
          if (mouseAngle < 0) {
            mouseAngle = 2 * Math.PI + mouseAngle;
          }
          forEach(self.datas.rows[0].items, function (item, index) {
            if (mouseAngle >= item.startAngle && mouseAngle <= item.endAngle) {
              currItem = item;
              if (item.tooltip) {
                self.tooltip.innerHTML = item.tooltip;
              } else {
                self.tooltip.innerHTML = self.subtitle + self.datas.labels[index] + '<br>' + self.datas.rows[0].title + ' ' + item.title;
              }
              var rc = self.tooltip.getDimensions(),
                l = pos.x - 20;
              if (l > document.viewport.getWidth() / 2) {
                l = pos.x - rc.width + 20;
                self.tooltip.className = 'tooltip-bottom-right';
              } else {
                self.tooltip.className = 'tooltip-bottom-left';
              }
              self.tooltip.style.left = l + 'px';
              self.tooltip.style.top = (pos.y - 16 - rc.height) + 'px';
              return true;
            }
          });
        }
        if (!currItem) {
          if (self.hoverItem) {
            self.canvas.style.cursor = 'default';
            self.tooltip.hide();
            self.hoverItem = null;
          }
        } else if (self.hoverItem !== currItem) {
          self.canvas.style.cursor = 'pointer';
          self.hoverItem = currItem;
          self.tooltip.fadeIn();
          self.tooltip.show();
        }
      };
      if (this.loading) {
        this.canvas.addEvent('mousemove', _mouseMove);
      }
    },
    drawDonut: function () {
      this.clear();
      var options = this.options;
      var self = this;
      var context = this.context;
      var centerX = options.centerX == null ? Math.round(this.width / 2) : options.centerX;
      var centerY = options.centerY == null ? Math.round(this.height / 2) : options.centerY;
      var radius = centerY - options.centerOffset;
      var counter = 0.0;
      var chartStartAngle = -.5 * Math.PI;
      var sum = this.datas.rows[0].total;
      forEach(this.datas.rows[0].items, function (item, index) {
        var fraction = item.value / sum;
        item.startAngle = (counter * Math.PI * 2);
        item.endAngle = ((counter + fraction) * Math.PI * 2);
        item.midAngle = (counter + fraction / 2);
        item.percentage = Math.round(fraction * 100);
        counter += fraction;
      });
      function drawSlice(slice, index) {
        if (slice.percentage) {
          var distance = (radius / 2.5) * (Math.pow(1 - (2.5 / radius), 0.8) + 1) + options.labelOffset;
          var labelX = Math.round(centerX + Math.sin(slice.midAngle * Math.PI * 2) * distance);
          var labelY = Math.round(centerY - Math.cos(slice.midAngle * Math.PI * 2) * distance);
          var c = options.colors[index % options.colors.length];
          context.strokeStyle = c;
          context.beginPath();
          context.moveTo(centerX, centerY);
          context.lineTo(labelX, labelY);
          if (labelX < 180) {
            context.lineTo(labelX - 5, labelY);
            context.textAlign = 'right';
            labelX -= 10;
          } else {
            context.lineTo(labelX + 5, labelY);
            context.textAlign = 'left';
            labelX += 10;
          }
          context.textBaseline = 'middle';
          context.stroke();
          context.closePath();
          context.fillStyle = c;
          if (options.strokeColor) {
            context.strokeStyle = options.strokeColor;
            context.strokeText(slice.value, labelX, labelY);
          }
          context.fillText(slice.value, labelX, labelY);
        }
        var startAngle = slice.startAngle + chartStartAngle;
        var endAngle = slice.endAngle + chartStartAngle;
        context.beginPath();
        context.moveTo(centerX, centerY);
        context.arc(centerX, centerY, radius, startAngle, endAngle, false);
        context.lineTo(centerX, centerY);
        context.closePath();
        context.fillStyle = options.colors[index % options.colors.length];
        context.fill();
        context.lineWidth = 0;
        context.strokeStyle = self.backgroundColor;
        context.stroke();
      }
      function drawGraph() {
        context.save();
        context.fillStyle = self.backgroundColor;
        context.beginPath();
        context.arc(centerX, centerY, radius + 2, 0, Math.PI * 2, false);
        context.fill();
        forEach(self.datas.rows[0].items, function (item, index) {
          drawSlice(item, index);
        });
        context.fillStyle = self.backgroundColor;
        context.beginPath();
        context.arc(centerX, centerY, radius - options.ringWidth, 0, Math.PI * 2, false);
        context.fill();
        context.restore();
        if (options.showTitle) {
          var x = self.width - 10;
          var t = 10;
          var y = t + self.fontSize;
          x = x - self.fontSize - 10;
          context.textAlign = 'right';
          context.textBaseline = 'middle';
          context.lineWidth = 1;
          var offset = self.fontSize / 2;
          forEach(self.datas.labels, function (item, index) {
            context.fillStyle = options.colors[index % options.colors.length];
            context.fillRect(x, y, self.fontSize, self.fontSize);
            context.fillStyle = self.fontColor;
            context.fillText(item, x - 5, y + offset);
            y = y + self.fontSize + 5;
          });
        }
      }
      drawGraph();
      var _mouseMove = function (e) {
        var currItem = null;
        var offset = self.canvas.viewportOffset();
        var pos = GEvent.pointer(e);
        var mouseX = pos.x - offset.left;
        var mouseY = pos.y - offset.top;
        var xFromCenter = mouseX - centerX;
        var yFromCenter = mouseY - centerY;
        var distanceFromCenter = Math.sqrt(Math.pow(Math.abs(xFromCenter), 2) + Math.pow(Math.abs(yFromCenter), 2));
        if (distanceFromCenter <= radius && distanceFromCenter > radius - options.ringWidth) {
          var mouseAngle = Math.atan2(yFromCenter, xFromCenter) - chartStartAngle;
          if (mouseAngle < 0) {
            mouseAngle = 2 * Math.PI + mouseAngle;
          }
          forEach(self.datas.rows[0].items, function (item, index) {
            if (mouseAngle >= item.startAngle && mouseAngle <= item.endAngle) {
              currItem = item;
              if (item.tooltip) {
                self.tooltip.innerHTML = item.tooltip;
              } else {
                self.tooltip.innerHTML = self.subtitle + self.datas.labels[index] + '<br>' + self.datas.rows[0].title + ' ' + item.title;
              }
              var rc = self.tooltip.getDimensions(),
                l = pos.x - 20;
              if (l > document.viewport.getWidth() / 2) {
                l = pos.x - rc.width + 20;
                self.tooltip.className = 'tooltip-bottom-right';
              } else {
                self.tooltip.className = 'tooltip-bottom-left';
              }
              self.tooltip.style.left = l + 'px';
              self.tooltip.style.top = (pos.y - 16 - rc.height) + 'px';
              return true;
            }
          });
        }
        if (!currItem) {
          if (self.hoverItem) {
            self.canvas.style.cursor = 'default';
            self.tooltip.hide();
            self.hoverItem = null;
          }
        } else if (self.hoverItem !== currItem) {
          self.canvas.style.cursor = 'pointer';
          self.hoverItem = currItem;
          self.tooltip.fadeIn();
          self.tooltip.show();
        }
      };
      if (this.loading) {
        this.canvas.addEvent('mousemove', _mouseMove);
      }
    },
    drawHChart: function () {
      this.clear();
      var options = this.options;
      var self = this;
      var context = this.context;
      var offsetRight = Math.ceil(context.measureText(this.max).width / 2);
      var l = 0;
      forEach(this.datas.labels, function () {
        l = Math.max(l, self.context.measureText(this).width);
      });
      l = l + 10;
      var t = Math.ceil(this.fontSize / 2);
      var r = this.width - offsetRight - 5;
      var b = this.height - this.fontSize - options.labelOffset;
      var cols = options.rows;
      var rows = Math.max(2, this.datas.labels.length);
      var cellWidth = Math.floor((r - l) / cols);
      var cellHeight = Math.floor((b - t) / rows);
      r = (cellWidth * cols) + l;
      b = (cellHeight * rows) + t;
      var clientWidth = r - l;
      var barHeight = Math.max(2, (cellHeight - 8 - (2 * (this.datas.rows.length + 1))) / this.datas.rows.length);
      var offsetHeight = t + 6;
      forEach(self.datas.rows, function () {
        forEach(this.items, function (item, index) {
          item.x = l;
          item.y = (index * cellHeight) + offsetHeight;
          item.cw = Math.max(3, Math.floor((clientWidth * item.value) / self.max));
          item.ch = barHeight;
          item.w = item.x + item.cw;
          item.h = item.y + barHeight;
        });
        offsetHeight = offsetHeight + barHeight + 2;
      });
      function drawGraph() {
        var y = t;
        context.textAlign = 'left';
        context.textBaseline = 'middle';
        context.fillStyle = self.fontColor;
        var offset = cellHeight / 2;
        forEach(self.datas.labels, function (item, index) {
          context.fillText(item, 0, y + offset);
          if (options.gridVColor && index > 0 && index < rows) {
            context.strokeStyle = options.gridVColor;
            context.beginPath();
            context.moveTo(l, y);
            context.lineTo(r, y);
            context.stroke();
            context.closePath();
          }
          y = y + cellHeight;
        });
        var label = 0;
        var labelValue = self.max / cols;
        if (labelValue > 1) {
          labelValue = Math.floor(labelValue);
        }
        var x = l;
        context.textAlign = 'center';
        context.textBaseline = 'bottom';
        context.fillStyle = self.fontColor;
        for (var i = 0; i <= cols; i++) {
          if (i > 0) {
            if (options.rotate) {
              var metric = context.measureText(label);
              var y = self.height - metric.width + 35;
              var y = self.height - metric.width;
              var xx = x + (self.fontSize / 2);
              context.save();
              context.translate(xx, y);
              context.rotate(-Math.PI / 2);
              context.translate(-xx, -y);
              context.fillText(label, xx, y);
              context.restore();
            } else {
              context.fillText(label, x, self.height);
            }
          }
          if (options.gridHColor && i > 0 && i < cols) {
            context.strokeStyle = options.gridHColor;
            context.beginPath();
            context.moveTo(x, t);
            context.lineTo(x, b);
            context.stroke();
            context.closePath();
          }
          x = x + cellWidth;
          label = label + labelValue;
        }
        context.strokeStyle = self.fontColor;
        context.beginPath();
        context.moveTo(l, t);
        context.lineTo(r, t);
        context.lineTo(r, b);
        context.lineTo(l, b);
        context.lineTo(l, t);
        context.stroke();
        context.closePath();
        var sw = barHeight < 10 ? 1 : 3;
        var dl = self.datas.rows.length;
        forEach(self.datas.rows, function (rows, row) {
          forEach(this.items, function (item, index) {
            if (item.cw > sw && item.value > 0) {
              context.fillStyle = options.shadowColor;
              context.fillRect(item.x, item.y, item.cw - sw, item.ch);
            }
            context.fillStyle = options.colors[(dl > 1 ? row : index) % options.colors.length];
            context.fillRect(item.x + 1, item.y, item.cw, item.ch - sw);
          });
        });
        if (dl > 1) {
          self.drawTitle(r, t);
        }
      }
      drawGraph();
    },
    drawVChart: function () {
      this.clear();
      var options = this.options;
      var self = this;
      var context = this.context;
      var offsetRight = Math.ceil(context.measureText(this.datas.labels[this.datas.labels.length - 1]).width / 2);
      var label = this.max;
      var labelValue = this.max / options.rows;
      if (labelValue > 1) {
        labelValue = Math.floor(labelValue);
      }
      var l = 0;
      for (var i = 0; i < options.rows; i++) {
        l = Math.max(l, context.measureText(label).width);
        label = label - labelValue;
      }
      l = l + 15;
      var t = Math.ceil(this.fontSize / 2);
      var r = this.width - offsetRight - 5;
      var b = this.height - this.fontSize - options.labelOffset;
      var rows = options.rows;
      var cols = Math.max(2, this.datas.labels.length);
      var cellWidth = Math.floor((r - l) / cols);
      var cellHeight = Math.floor((b - t) / rows);
      r = (cellWidth * cols) + l;
      b = (cellHeight * rows) + t;
      var clientHeight = b - t;
      var barWidth = Math.max(2, (cellWidth - 8 - (2 * (this.datas.rows.length + 1))) / this.datas.rows.length);
      var offsetWidth = l + 6;
      forEach(self.datas.rows, function () {
        forEach(this.items, function (item, index) {
          item.x = (index * cellWidth) + offsetWidth;
          item.y = clientHeight + t - Math.floor((clientHeight * item.value) / self.max) - 1;
          item.ch = b - item.y;
          item.cw = barWidth;
          item.w = item.x + item.cw;
          item.h = b;
          if (item.ch < 3) {
            item.y = b - 3;
            item.ch = 3;
          }
        });
        offsetWidth = offsetWidth + barWidth + 2;
      });
      function drawGraph() {
        var y = t;
        context.textAlign = 'right';
        context.textBaseline = 'middle';
        context.fillStyle = self.fontColor;
        var label = self.max;
        var labelValue = self.max / rows;
        if (labelValue > 1) {
          labelValue = Math.floor(labelValue);
        }
        for (var i = 0; i <= rows; i++) {
          if (i < rows) {
            context.fillText(label, l - 5, y);
          }
          if (options.gridVColor && i > 0 && i < rows) {
            context.strokeStyle = options.gridVColor;
            context.beginPath();
            context.moveTo(l, y);
            context.lineTo(r, y);
            context.stroke();
            context.closePath();
          }
          y = y + cellHeight;
          label = label - labelValue;
        }
        var x = l;
        var offset = cellWidth / 2;
        context.textAlign = 'center';
        context.textBaseline = 'bottom';
        context.fillStyle = self.fontColor;
        forEach(self.datas.labels, function (item, index) {
          if (index < cols) {
            if (options.rotate) {
              var metric = context.measureText(item);
              var y = self.height - metric.width + 35;
              var xx = x + offset + (self.fontSize / 2);
              context.save();
              context.translate(xx, y);
              context.rotate(-Math.PI / 2);
              context.translate(-xx, -y);
              context.fillText(item, xx, y);
              context.restore();
            } else {
              context.fillText(item, x + offset, self.height);
            }
          }
          if (options.gridHColor && index > 0 && index < cols) {
            context.strokeStyle = options.gridHColor;
            context.beginPath();
            context.moveTo(x, t);
            context.lineTo(x, b);
            context.stroke();
            context.closePath();
          }
          x = x + cellWidth;
        });
        context.strokeStyle = self.fontColor;
        context.beginPath();
        context.moveTo(l, t);
        context.lineTo(r, t);
        context.lineTo(r, b);
        context.lineTo(l, b);
        context.lineTo(l, t);
        context.stroke();
        context.closePath();
        var sw = barWidth < 10 ? 1 : 3;
        var dl = self.datas.rows.length;
        forEach(self.datas.rows, function (rows, row) {
          forEach(this.items, function (item, index) {
            if (item.ch > sw && item.value > 0) {
              context.fillStyle = options.shadowColor;
              context.fillRect(item.x, item.y + sw, item.cw, item.ch - sw);
            }
            context.fillStyle = options.colors[(dl > 1 ? row : index) % options.colors.length];
            context.fillRect(item.x, item.y, item.cw - sw, item.ch - 1);
          });
        });
        if (dl > 1) {
          self.drawTitle(r, t);
        }
      }
      drawGraph();
    },
    clear: function () {
      this.canvas.set('width', this.width);
      this.canvas.set('height', this.height);
      this.context.font = this.fontSize + 'px ' + this.graphs.getStyle('fontFamily');
      this.context.fillStyle = this.backgroundColor;
      this.context.fillRect(0, 0, this.width, this.height);
    },
    drawTitle: function (x, t) {
      if (this.options.showTitle) {
        var self = this;
        t = t + 10;
        x = x - this.fontSize - 10;
        var context = this.context;
        context.textAlign = 'right';
        context.textBaseline = 'middle';
        context.lineWidth = 1;
        var offset = this.fontSize / 2;
        forEach(this.datas.rows, function (item, index) {
          context.fillStyle = self.fontColor;
          context.fillText(item.title, x - 5, t + offset);
          context.fillStyle = self.options.colors[index % self.options.colors.length];
          context.fillRect(x, t, self.fontSize, self.fontSize);
          t = t + self.fontSize + 5;
        });
      }
    },
    getFontSize: function () {
      var div = document.createElement('div');
      var atts = {
        fontSize: '1em',
        padding: '0',
        position: 'absolute',
        lineHeight: '1',
        visibility: 'hidden'
      };
      for (var p in atts) {
        div.style[p] = atts[p];
      }
      div.appendChild(document.createTextNode('M'));
      this.graphs.appendChild(div);
      var h = div.offsetHeight;
      this.graphs.removeChild(div);
      return h;
    }
  };
}());
