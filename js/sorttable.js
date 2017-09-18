/**
 * GSortTable
 * Javascript sort table
 *
 * @filesource js/sorttable.js
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */
(function () {
  'use strict';
  window.GSortTable = GClass.create();
  GSortTable.prototype = {
    initialize: function (id, options) {
      this.options = {
        sortClass: 'icon-move',
        itemClass: 'sort',
        endDrag: $K.emptyFunction
      };
      for (var property in options) {
        this.options[property] = options[property];
      }
      this.changed = false;
      var self = this,
        dropitems = new Array(),
        hoverItem = null,
        position = 0;
      function checkMouseOver(item, mousePos) {
        var elemPos = item.viewportOffset();
        var elemSize = item.getDimensions();
        var mouseover = mousePos.x > elemPos.left && mousePos.y > elemPos.top;
        return mouseover && mousePos.x < elemPos.left + elemSize.width && mousePos.y < elemPos.top + elemSize.height;
      }
      function doBeginDrag() {
        self.changed = false;
        self.dragItem = this;
        hoverItem = this;
        position = this.mousePos.y;
      }
      function doMoveDrag() {
        var temp = this;
        forEach(dropitems, function () {
          if (checkMouseOver(this, temp.mousePos)) {
            if (this != hoverItem) {
              self.changed = true;
              if (temp.mousePos.y > position) {
                temp.move.parentNode.insertBefore(temp.move, this.nextSibling);
              } else {
                temp.move.parentNode.insertBefore(temp.move, this);
              }
              hoverItem = this;
              return true;
            }
          }
        });
        position = this.mousePos.y;
      }
      function doEndDrag() {
        if (self.changed) {
          self.options.endDrag.call(this);
        }
      }
      function _find(tr) {
        if (tr.hasClass(self.options.sortClass)) {
          return tr;
        } else {
          var els = $E(tr).getElementsByTagName('*');
          for (var i = 0; i < els.length; i++) {
            if ($G(els[i]).hasClass(self.options.sortClass)) {
              return els[i];
            }
          }
        }
      }
      var o = {
        beginDrag: doBeginDrag,
        moveDrag: doMoveDrag,
        endDrag: doEndDrag
      };
      forEach($E(id).getElementsByTagName('*'), function () {
        if ($G(this).hasClass(self.options.itemClass)) {
          new GDrag(_find(this), this, o);
          dropitems.push(this);
        }
      });
    }
  };
}());