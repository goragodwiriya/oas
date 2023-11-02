/**
 * GGraphs
 * Javascript HTML5 graphs
 *
 * @filesource js/graphs.js
 * @link https://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 */
var GGraphs = (function(document) {
  'use strict';
  var GGraphs = function(id, options) {
    this.options = {
      type: 'line',
      rows: 5,
      colors: [],
      shadowColor: 'rgba(0,0,0,0.2)',
      grid: true,
      gridHColor: 'rgba(0,0,0,0.05)',
      gridVColor: 'rgba(0,0,0,0.05)',
      zeroColor: 'rgba(0,0,0,0.2)',
      barSpace: 5,
      showTitle: true,
      lineWidth: 2,
      linePointerSize: 4,
      centerOffset: null,
      centerX: null,
      centerY: null,
      labelOffset: null,
      ringWidth: 60,
      rotate: false,
      table: 'auto',
      height: '200px'
    };
    for (let property in options) {
      this.options[property] = options[property];
    }
    if (this.options.colors.length == 0) {
      let span = document.createElement('span');
      span.style.position = 'absolute';
      span.style.top = '-100%';
      document.body.appendChild(span);
      for (let i = 0; i <= 11; i++) {
        span.className = 'term' + i;
        this.options.colors.push(getComputedStyle(span).getPropertyValue("background-color"));
      }
      let borderColor = getComputedStyle(span).getPropertyValue("border-color");
      this.options.gridHColor = borderColor;
      this.options.gridVColor = borderColor;
      this.options.zeroColor = borderColor;
      document.body.removeChild(span);
    }
    let wraper = $E(id),
      transparent = /rgba\([0-9a-fA-F,\s]+0\)/;
    this.graphs = $G(document.createElement('div'));
    this.graphs.style.height = this.options.height;
    wraper.insertBefore(this.graphs, wraper.firstChild);
    $G(wraper).addClass('ggraphs');
    this.panel = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    this.panel.style.width = '100%';
    this.panel.style.height = this.options.height;
    this.graphs.appendChild(this.panel);
    this.backgroundColor = wraper.getStyle('backgroundColor');
    if (this.backgroundColor == 'transparent' || transparent.test(this.backgroundColor)) {
      this.backgroundColor = $G(document.body).getStyle('backgroundColor');
    }
    this.fontColor = wraper.getStyle('color');
    this.max = 0;
    this.min = null;
    let self = this,
      table = null,
      _resize = function() {
        self._draw();
      };
    if (this.options.table == 'auto') {
      table = wraper.getElementsByTagName('table')[0];
    } else if (this.options.table && this.options.table != '') {
      table = document.getElementById(this.options.table);
    }
    if (table) {
      this.setDatas(this._loadFromTable(table));
    }
    window.addEventListener('resize', _resize, true);
  };

  GGraphs.prototype.setDatas = function(datas) {
    this.datas = this._reset();
    if (datas.headers.title) {
      this.datas.headers.title = datas.headers.title;
    }
    if (datas.headers.items) {
      this.datas.headers.items = datas.headers.items;
    }
    let self = this,
      headers = this.datas.headers;
    datas.rows.forEach(function(rows) {
      let datas = [],
        d = {},
        max = 0,
        min = null,
        sum = 0;
      if (rows.title) {
        d.title = rows.title;
      }
      rows.items.forEach(function(item, row) {
        sum = sum + item.value;
        max = Math.max(max, item.value);
        min = min == null ? item.value : Math.min(min, item.value);
        if (!item.tooltip) {
          item.tooltip = headers.title + ' ' + headers.items[row].text + ' ' + rows.title + ' ' + toCurrency(item.value, null, true);
        }
        datas.push({
          value: item.value,
          tooltip: item.tooltip
        });
      });
      d.items = datas;
      d.total = sum;
      if (min == max) {
        min -= max;
      }
      d.max = max;
      d.min = min;
      self.datas.rows.push(d);
      self.max = Math.max(max, self.max);
      self.min = self.min == null ? min : Math.min(min, self.min);
      if (self.min == self.max) {
        self.max = self.min + self.options.rows;
      }
    });
    let range = this.max - this.min,
      rowHeight = Math.ceil(range / this.options.rows),
      p = 1;
    while (Math.ceil(rowHeight / p) * p <= rowHeight) {
      p = p * 10;
    }
    if (p < rowHeight) {
      rowHeight = Math.ceil(rowHeight / p) * p;
      if (this.min >= 0) {
        this.min = Math.floor(this.min / p) * p;
      } else if (this.max <= 0) {
        this.max = 0;
      }
    }
    if (this.min >= 0) {
      this.max = this.min + (rowHeight * this.options.rows);
    } else {
      this.min = this.max - (rowHeight * this.options.rows);
    }
    this._draw();
  };

  GGraphs.prototype._loadFromTable = function(table) {
    let datas = this._reset();
    table.querySelectorAll('thead:first-child>tr:first-child>th').forEach(function(item, index) {
      if (index == 0) {
        datas.headers.title = item.innerHTML.strip_tags().replace(/&nbsp;/g, '');
      } else {
        let node = item.innerHTML,
          ds = /href=['"]([^'"]+)/.exec(node),
          hs = /target=['"]{0,}([_a-z]+)/.exec(node);
        datas.headers.items.push({
          text: node.strip_tags(),
          href: ds ? ds[1] : null,
          target: hs ? hs[1] : null
        });
      }
    });
    table.querySelectorAll('tbody>tr').forEach(function(tr) {
      let rows = [],
        d = {};
      tr.querySelectorAll('td,th').forEach(function(item) {
        let val = {};
        if (item.tagName == 'TH') {
          d.title = item.innerHTML.strip_tags();
        } else {
          if (item.dataset.value) {
            val.value = floatval(item.dataset.value);
          } else {
            val.value = floatval(item.innerHTML.replace(/,/g, ''));
          }
          if (item.dataset.tooltip) {
            val.tooltip = item.dataset.tooltip;
          }
          val.title = item.innerHTML.strip_tags();
          rows.push(val);
        }
      });
      d.items = rows;
      datas.rows.push(d);
    });
    return datas;
  };

  GGraphs.prototype._draw = function() {
    let dm = $G(this.graphs).getDimensions();
    this.panel.innerHTML = '';
    this.panel.setAttribute('viewBox', '0 0 ' + dm.width + ' ' + dm.height);
    this.width = dm.width;
    this.height = dm.height;
    if (this.options.type == 'line') {
      this._drawLine(false);
    } else if (this.options.type == 'spline') {
      this._drawLine(true);
    } else if (this.options.type == 'pie') {
      this._drawPie(false);
    } else if (this.options.type == 'donut') {
      this._drawPie(true);
    } else if (this.options.type == 'hchart') {
      this._drawHChart();
    } else {
      this._drawVChart();
    }
  };

  GGraphs.prototype._drawLine = function(spline) {
    let options = this.options,
      self = this,
      headers = this.datas.headers.items,
      pointerStroke = (options.linePointerSize * options.ringWidth) / 100,
      pointerSize = options.linePointerSize + pointerStroke,
      offsetRight = pointerSize,
      labelWidth = pointerSize,
      labelHeight = pointerSize,
      offsetBottom = 0,
      step = (this.max - this.min) / options.rows,
      labelValue = this.max,
      labelText,
      rc,
      labels = [];
    if (options.grid) {
      if (options.rotate) {
        headers.forEach(function(item) {
          rc = self._getTextSize(item.text);
          offsetBottom = Math.max(offsetBottom, rc.width);
          offsetRight = rc.height / 2;
        });
      } else {
        rc = self._getTextSize(headers[headers.length - 1].text);
        offsetBottom = Math.max(offsetBottom, rc.height);
        offsetRight = rc.width / 2;
      }
    }
    for (let r = 0; r <= options.rows; r++) {
      labelText = toCurrency(labelValue, null, true);
      labels.push(labelText);
      if (options.grid) {
        rc = self._getTextSize(labelText);
        labelWidth = Math.max(labelWidth, rc.width);
        labelHeight = Math.max(labelHeight, rc.height);
      }
      labelValue -= step;
    }
    let top = options.grid ? labelHeight / 2 : offsetRight,
      bottom = this.height - offsetBottom - pointerSize - (options.rotate ? 5 : 0),
      clientHeight = bottom - top,
      rowHeight = clientHeight / options.rows,
      labeloffset = options.grid ? 10 : 0,
      y = top,
      x = labelWidth + labeloffset,
      columnWidth = (this.width - offsetRight - x) / Math.max(1, headers.length - 1),
      panel = this.panel;
    if (options.grid) {
      labels.forEach(function(row) {
        self.line(panel, labelWidth + labeloffset, y, self.width - offsetRight, y, options.gridVColor, 1);
        self.text(panel, labelWidth, y, row, self.fontColor, 'right');
        y += rowHeight;
      });
      headers.forEach(function(row) {
        self.line(panel, x, top, x, bottom, options.gridHColor, 1);
        if (options.rotate) {
          self.text(panel, x, bottom + top - pointerSize, row.text, self.fontColor, 'left', true);
        } else {
          self.text(panel, x, bottom + top + pointerSize, row.text, self.fontColor, 'center');
        }
        x += columnWidth;
      });
    }
    let xp,
      yp,
      color,
      marker,
      point,
      id,
      zero = clientHeight + top - Math.floor((clientHeight * (0 - self.min)) / (self.max - self.min)),
      markers = {};
    if (options.zeroColor && this.min < 0 && this.max > 0) {
      self.dash(panel, labelWidth + labeloffset, zero, self.width - offsetRight, zero, options.zeroColor, 1);
    }
    this.datas.rows.forEach(function(rows, row) {
      color = options.colors[row % options.colors.length];
      rows.items.forEach(function(item, index) {
        x = labelWidth + labeloffset + (columnWidth * index);
        y = clientHeight + top - Math.floor((clientHeight * (item.value - self.min)) / (self.max - self.min));
        if (index > 0) {
          if (spline) {
            self.curve(panel, xp, yp, (xp + x) / 2, yp, x, y, (xp + x) / 2, y, color, options.lineWidth);
          } else {
            self.line(panel, xp, yp, x, y, color, options.lineWidth);
          }
        }
        marker = {
          x: x,
          y: y,
          color: color,
          tooltip: item.tooltip
        };
        id = index + '_' + item.value;
        if (markers[id]) {
          markers[id].push(marker);
        } else {
          markers[id] = [marker];
        }
        xp = x;
        yp = y;
      });
    });
    for (id in markers) {
      let tooltip = [];
      markers[id].forEach(function(marker) {
        tooltip.push(marker.tooltip);
      });
      markers[id].forEach(function(marker) {
        point = self.circle(panel, marker.x, marker.y, options.linePointerSize, self.backgroundColor, marker.color, pointerStroke);
        self.setTooltip(point, tooltip.join("\n"));
      });
    };
    this._displayTitle(this.datas.rows);
  };

  GGraphs.prototype._drawPie = function(donut) {
    let options = this.options,
      self = this,
      centerX = options.centerX == null ? Math.round(this.width / 2) : options.centerX,
      centerY = options.centerY == null ? Math.round(this.height / 2) : options.centerY,
      radius = centerY - (options.centerOffset || (this.height * 0.15)),
      currentValue = 0,
      currentRate = 0,
      cummulatedValue = 0,
      cummulatedRate = 0,
      fillColor,
      piece,
      panel = this.panel,
      totalValue = this.datas.rows[0].total,
      labelOffset = options.labelOffset || (this.height * 0.15),
      distance = (radius / 2.5) * (Math.pow(1 - 2.5 / radius, 0.8) + 1) + labelOffset;
    forEach(this.datas.rows[0].items, function(item, index) {
      fillColor = options.colors[index % options.colors.length];
      currentValue = item.value;
      currentRate = currentValue / totalValue;
      if (currentRate == 1.0) {
        piece = self.circle(panel, centerX, centerY, radius, fillColor, self.backgroundColor);
      } else {
        piece = self.pie(panel, centerX, centerY, radius, cummulatedRate, cummulatedRate + currentRate, fillColor, self.backgroundColor);
      }
      self.setTooltip(piece, item.tooltip);
      let midAngle = cummulatedRate + currentRate / 2,
        labelX = Math.round(centerX + Math.sin(midAngle * Math.PI * 2) * distance),
        labelY = Math.round(centerY - Math.cos(midAngle * Math.PI * 2) * distance);
      self.line(panel, centerX, centerY, labelX, labelY, fillColor, 1);
      if (labelX < centerX) {
        self.line(panel, labelX - 5, labelY, labelX, labelY, fillColor, 1);
        self.text(panel, labelX - 10, labelY, toCurrency(currentValue, null, true), fillColor, 'right');
      } else {
        self.line(panel, labelX, labelY, labelX + 5, labelY, fillColor, 1);
        self.text(panel, labelX + 10, labelY, toCurrency(currentValue, null, true), fillColor);
      }
      cummulatedValue += currentValue;
      cummulatedRate = cummulatedValue / totalValue;
    });
    if (donut) {
      self.circle(panel, centerX, centerY, radius * options.ringWidth / 100, self.backgroundColor);
    }
    this._displayTitle(this.datas.headers.items);
  };

  GGraphs.prototype._drawHChart = function() {
    let options = this.options,
      self = this,
      headers = this.datas.headers.items,
      offsetRight = 0,
      labelWidth = 0,
      labelHeight = 0,
      step = (this.max - this.min) / options.rows,
      labelValue = this.min,
      labelText,
      rc,
      offsetBottom = 0,
      labels = [];
    if (options.grid) {
      for (let r = 0; r <= options.rows; r++) {
        labelText = toCurrency(labelValue, null, true);
        labels.push(labelText);
        if (options.rotate) {
          rc = self._getTextSize(labelText);
          offsetBottom = Math.max(offsetBottom, rc.width);
          offsetRight = rc.height / 2;
        }
        labelValue += step;
      }
      if (!options.rotate) {
        rc = self._getTextSize(labels[labels.length - 1]);
        offsetBottom = rc.height;
        offsetRight = rc.width / 2;
      }
      headers.forEach(function(item) {
        rc = self._getTextSize(item.text);
        labelWidth = Math.max(labelWidth, rc.width);
        labelHeight = Math.max(labelHeight, rc.height);
      });
    }
    let top = 0,
      bottom = this.height - offsetBottom - (options.rotate ? 5 : 0),
      clientHeight = bottom - top,
      cellHeight = clientHeight / headers.length,
      labeloffset = options.grid ? 10 : 0,
      y = top,
      middleRow = cellHeight / 2,
      x = labelWidth + labeloffset,
      columnWidth = (this.width - offsetRight - x) / options.rows,
      panel = this.panel;
    if (options.grid) {
      headers.forEach(function(row) {
        self.line(panel, labelWidth + labeloffset, y, self.width - offsetRight, y, options.gridVColor, 1);
        self.text(panel, 0, y + middleRow, row.text, self.fontColor, 'left');
        y += cellHeight;
      });
      self.line(panel, labelWidth + labeloffset, y, self.width - offsetRight, y, options.gridVColor, 1);
      labels.forEach(function(row) {
        self.line(panel, x, top, x, bottom, options.gridHColor, 1);
        if (options.rotate) {
          self.text(panel, x, bottom + top + 5, row, self.fontColor, 'left', true);
        } else {
          self.text(panel, x, bottom + top + labelHeight - 5, row, self.fontColor, 'center');
        }
        x += columnWidth;
      });
    }
    let rowCount = this.datas.rows.length,
      itemHeight = Math.max(1, (cellHeight - (options.barSpace * (rowCount + 1))) / rowCount),
      barSpace = (cellHeight - (itemHeight * rowCount)) / (rowCount + 1),
      barHeight = itemHeight > 4 ? itemHeight - 2 : itemHeight,
      rx = labelWidth + labeloffset,
      clientWidth = options.rows * columnWidth,
      zero = (self.min >= 0 ? 0 : clientWidth - (clientWidth * self.max) / (self.max - self.min)),
      ry,
      rw,
      rect,
      color;
    if (options.zeroColor && this.min < 0 && this.max > 0) {
      self.line(panel, zero + rx, top, zero + rx, bottom, options.zeroColor, 1);
    }
    this.datas.rows.forEach(function(rows, row) {
      y = top;
      rows.items.forEach(function(item, index) {
        color = options.colors[(rowCount > 1 ? row : index) % options.colors.length];
        ry = y + (row * itemHeight) + ((row + 1) * barSpace);
        rw = Math.max(2, Math.abs((clientWidth * (item.value - Math.max(0, self.min))) / (self.max - self.min)));
        if (self.min >= 0 || item.value > 0) {
          if (itemHeight > 4) {
            self.rect(panel, zero + rx, ry + 2, rw + 2, barHeight, options.shadowColor);
          }
          rect = self.rect(panel, zero + rx, ry, rw, barHeight, color);
        } else if (self.max <= 0 || item.value < 0) {
          if (itemHeight > 4) {
            self.rect(panel, zero - rw + rx + 2, ry + 2, rw - 2, barHeight, options.shadowColor);
          }
          rect = self.rect(panel, zero - rw + rx, ry, rw, barHeight, color);
        } else {
          rect = self.rect(panel, zero + rx - 1, ry, 2, barHeight, color);
        }
        self.setTooltip(rect, item.tooltip);
        y += cellHeight;
      });
    });
    this._displayTitle(this.datas.rows);
  };

  GGraphs.prototype._drawVChart = function() {
    let options = this.options,
      self = this,
      headers = this.datas.headers.items,
      pointerStroke = (options.linePointerSize * options.ringWidth) / 100,
      pointerSize = options.linePointerSize + pointerStroke,
      offsetRight = pointerSize,
      labelWidth = pointerSize,
      labelHeight = pointerSize,
      offsetBottom = 0,
      step = (this.max - this.min) / options.rows,
      labelValue = this.max,
      labelText,
      rc,
      labels = [];
    if (options.grid) {
      if (options.rotate) {
        headers.forEach(function(item) {
          rc = self._getTextSize(item.text);
          offsetBottom = Math.max(offsetBottom, rc.width);
          offsetRight = rc.height / 2;
        });
      } else {
        rc = self._getTextSize(headers[headers.length - 1].text);
        offsetBottom = Math.max(offsetBottom, rc.height);
        offsetRight = rc.width / 2;
      }
    }
    for (let r = 0; r <= options.rows; r++) {
      labelText = toCurrency(labelValue, null, true);
      labels.push(labelText);
      if (options.grid) {
        rc = self._getTextSize(labelText);
        labelWidth = Math.max(labelWidth, rc.width);
        labelHeight = Math.max(labelHeight, rc.height);
      }
      labelValue -= step;
    }
    let top = options.grid ? labelHeight / 2 : offsetRight,
      bottom = this.height - offsetBottom - pointerSize - (options.rotate ? 5 : 0),
      clientHeight = bottom - top,
      rowHeight = clientHeight / options.rows,
      labeloffset = options.grid ? 10 : 0,
      y = top,
      x = labelWidth + labeloffset,
      cellWidth = (this.width - offsetRight - x) / headers.length,
      midCell = cellWidth / 2,
      panel = this.panel;
    if (options.grid) {
      labels.forEach(function(row) {
        self.line(panel, labelWidth + labeloffset, y, self.width - offsetRight, y, options.gridVColor, 1);
        self.text(panel, labelWidth, y, row, self.fontColor, 'right');
        y += rowHeight;
      });
      headers.forEach(function(row) {
        self.line(panel, x, top, x, bottom, options.gridHColor, 1);
        if (options.rotate) {
          self.text(panel, x + midCell, bottom + top - pointerSize, row.text, self.fontColor, 'left', true);
        } else {
          self.text(panel, x + midCell, bottom + top + pointerSize, row.text, self.fontColor, 'center');
        }
        x += cellWidth;
      });
      self.line(panel, x, top, x, bottom, options.gridHColor, 1);
    }
    let colCount = this.datas.rows.length,
      itemWidth = Math.max(1, (cellWidth - (options.barSpace * (colCount + 1))) / colCount),
      barSpace = (cellWidth - (itemWidth * colCount)) / (colCount + 1),
      barWidth = itemWidth > 4 ? itemWidth - 2 : itemWidth,
      rx,
      zero = self.min >= 0 ? clientHeight : (clientHeight * self.max) / (self.max - self.min),
      rh,
      rect,
      color;
    if (options.zeroColor && this.min < 0 && this.max > 0) {
      self.line(panel, labelWidth + labeloffset, zero + top, self.width - offsetRight, zero + top, options.zeroColor, 1);
    }
    this.datas.rows.forEach(function(rows, row) {
      x = labelWidth + labeloffset;
      rows.items.forEach(function(item, index) {
        color = options.colors[(colCount > 1 ? row : index) % options.colors.length];
        rx = x + (row * itemWidth) + ((row + 1) * barSpace);
        rh = Math.max(2, Math.abs((clientHeight * (item.value - Math.max(0, self.min))) / (self.max - self.min)));
        if (self.min >= 0 || item.value > 0) {
          if (itemWidth > 4) {
            self.rect(panel, rx + 2, zero - rh + top + 2, barWidth, rh - 2, options.shadowColor);
          }
          rect = self.rect(panel, rx, zero - rh + top, barWidth, rh, color);
        } else if (self.max <= 0 || item.value < 0) {
          if (itemWidth > 4) {
            self.rect(panel, rx + 2, zero + top + 1, barWidth, rh + 1, options.shadowColor);
          }
          rect = self.rect(panel, rx, zero + top + 1, barWidth, rh - 1, color);
        } else {
          rect = self.rect(panel, rx, zero + top - 1, barWidth, 2, color);
        }
        self.setTooltip(rect, item.tooltip);
        x += cellWidth;
      });
    });
    this._displayTitle(this.datas.rows);
  };

  GGraphs.prototype.setTooltip = function(item, text) {
    let self = this,
      hideTooltip = function() {
        self.graphs.style.cursor = 'default';
        self.graphs.title = '';
      },
      showTooltip = function() {
        self.graphs.style.cursor = 'pointer';
        self.graphs.title = text;
      };
    item.addEventListener('mouseover', hideTooltip);
    item.addEventListener('mousemove', showTooltip);
    item.addEventListener('mouseout', hideTooltip);
  };

  GGraphs.prototype._reset = function() {
    return {
      headers: {
        title: '',
        items: []
      },
      rows: []
    };
  };

  GGraphs.prototype._getFontSize = function() {
    let div = document.createElement('div'),
      atts = {
        fontSize: '1em',
        padding: '0',
        position: 'absolute',
        lineHeight: '1',
        visibility: 'hidden'
      };
    for (let p in atts) {
      div.style[p] = atts[p];
    }
    div.appendChild(document.createTextNode('M'));
    this.graphs.appendChild(div);
    let h = div.offsetHeight;
    this.graphs.removeChild(div);
    return h;
  };

  GGraphs.prototype._getTextSize = function(value) {
    let svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg'),
      text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
    text.innerHTML = value;
    text.setAttribute('x', 100);
    text.setAttribute('y', 100);
    svg.appendChild(text);
    this.graphs.appendChild(svg);
    let rc = text.getBBox();
    this.graphs.removeChild(svg);
    return {
      width: rc.width,
      height: rc.height,
    };
  };

  GGraphs.prototype._displayTitle = function(headers) {
    if (this.options.showTitle) {
      let label,
        div,
        span,
        self = this,
        wrapper = this.graphs.parentNode,
        lbs = wrapper.getElementsByClassName('bottom_label');
      if (lbs.length == 0) {
        div = document.createElement('div');
        wrapper.insertBefore(div, this.graphs.nextSibling);
        div.className = 'bottom_label';
      } else {
        div = lbs[0];
        div.innerHTML = '';
      }
      headers.forEach(function(item, index) {
        if (item.title || item.text) {
          label = document.createElement('div');
          span = document.createElement('span');
          span.style.backgroundColor = self.options.colors[index % self.options.colors.length];
          label.appendChild(span);
          if (item.text && item.href) {
            span = document.createElement('a');
            span.innerHTML = item.text;
            span.href = item.href.replace(/&amp;/g, '&');
            if (item.target) {
              span.target = item.target;
            }
          } else {
            span = document.createElement('span');
            span.innerHTML = item.title ? item.title : item.text;
          }
          label.appendChild(span);
          div.appendChild(label);
        }
      });
    }
  };

  GGraphs.prototype.circle = function(parent, cx, cy, r, color, strokeColor, strokeWidth) {
    let circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
    circle.setAttribute('cx', cx);
    circle.setAttribute('cy', cy);
    circle.setAttribute('r', r);
    circle.setAttribute('fill', color);
    if (strokeColor && strokeWidth) {
      circle.setAttribute('stroke', strokeColor);
      circle.setAttribute('stroke-width', strokeWidth);
    }
    parent.appendChild(circle);
    return circle;
  };

  GGraphs.prototype.pie = function(parent, cx, cy, r, from, to, color, strokeColor) {
    let fromDegree = from * 360 - 90,
      toDegree = to * 360 - 90,
      over180 = to - from > 0.5 ? '1' : '0',
      fromRadian = fromDegree * Math.PI / 180.0,
      toRadian = toDegree * Math.PI / 180.0,
      fromX = cx + r * Math.cos(fromRadian),
      fromY = cy + r * Math.sin(fromRadian),
      toX = cx + r * Math.cos(toRadian),
      toY = cy + r * Math.sin(toRadian),
      moveTo = 'M ' + cx + ' ' + cy,
      lineTo1 = 'L ' + fromX + ' ' + fromY,
      arc = 'A ' + r + ' ' + r + ' 0 ' + over180 + ' 1 ' + toX + ' ' + toY,
      lineTo2 = 'L ' + cx + ' ' + cy,
      pie = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    pie.setAttribute('d', moveTo + ' ' + lineTo1 + ' ' + arc + ' ' + lineTo2 + ' Z');
    pie.setAttribute('fill', color);
    if (strokeColor) {
      pie.setAttribute('stroke', strokeColor);
      pie.setAttribute('stroke-width', 1);
    }
    parent.appendChild(pie);
    return pie;
  };

  GGraphs.prototype.curve = function(parent, x1, y1, cx1, cy1, x2, y2, cx2, cy2, stroke, width) {
    let moveTo = 'M ' + x1 + ' ' + y1,
      curveTo = 'C ' + cx1 + ' ' + cy1 + ' ' + cx2 + ' ' + cy2 + ' ' + x2 + ' ' + y2,
      strokeWidth = undefined == width ? 1 : width,
      curve = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    curve.setAttribute('d', moveTo + ' ' + curveTo);
    curve.setAttribute('fill', 'transparent');
    curve.setAttribute('stroke', stroke);
    curve.setAttribute('stroke-width', strokeWidth);
    parent.appendChild(curve);
    return curve;
  };

  GGraphs.prototype.rect = function(parent, x, y, width, height, fill, stroke) {
    let rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
    rect.setAttribute('x', x);
    rect.setAttribute('y', y);
    rect.setAttribute('width', Math.max(0, width));
    rect.setAttribute('height', Math.max(0, height));
    rect.setAttribute('fill', fill);
    rect.setAttribute('stroke', stroke);
    parent.appendChild(rect);
    return rect;
  };

  GGraphs.prototype.line = function(parent, x1, y1, x2, y2, color, lineWidth) {
    let moveTo = 'M ' + x1 + ' ' + y1,
      lineTo = 'L ' + x2 + ' ' + y2,
      line = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    line.setAttribute('d', moveTo + ' ' + lineTo + ' Z');
    line.setAttribute('fill', 'none');
    line.setAttribute('stroke', color);
    line.setAttribute('stroke-width', lineWidth);
    parent.appendChild(line);
    return line;
  };

  GGraphs.prototype.dash = function(parent, x1, y1, x2, y2, color, lineWidth) {
    let moveTo = 'M ' + x1 + ' ' + y1,
      lineTo = 'L ' + x2 + ' ' + y2,
      line = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    line.setAttribute('d', moveTo + ' ' + lineTo + ' Z');
    line.setAttribute('fill', 'none');
    line.setAttribute('stroke', color);
    line.setAttribute('stroke-width', lineWidth);
    line.setAttribute('stroke-dasharray', '5,5');
    parent.appendChild(line);
    return line;
  };

  GGraphs.prototype.text = function(parent, x, y, value, color, align, rotate) {
    let text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
    text.setAttribute('fill', color);
    text.setAttribute('x', x);
    text.setAttribute('y', y);
    text.setAttribute('alignment-baseline', 'middle');
    if (rotate) {
      text.setAttribute('transform', 'rotate(90 ' + x + ' ' + y + ')');
    }
    if (align == 'right') {
      text.setAttribute('text-anchor', 'end');
    } else if (align == 'center') {
      text.setAttribute('text-anchor', 'middle');
    } else {
      text.setAttribute('text-anchor', 'start');
    }
    text.innerHTML = value;
    parent.appendChild(text);
    return text;
  };

  return GGraphs;
})(document);
