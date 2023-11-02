/**
 * GDragDrop
 * Javascript drag drop
 *
 * @filesource js/dragdrop.js
 * @link https://www.kotchasan.com/
 * @copyright 2019 Goragod.com
 * @license https://www.kotchasan.com/license/
 */
(function() {
  "use strict";
  window.GDragDrop = GClass.create();
  GDragDrop.prototype = {
    initialize: function(id, options) {
      this.options = {
        dragClass: "icon-drag",
        itemClass: "sort",
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
        return (
          mouseover &&
          mousePos.x < elemPos.left + elemSize.width &&
          mousePos.y < elemPos.top + elemSize.height
        );
      }

      function doBeginDrag() {
        self.changed = false;
        self.dragItem = this;
        hoverItem = this;
        position = this.mousePos.y;
      }

      function doMoveDrag() {
        var temp = this;
        forEach(dropitems, function() {
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

      function _find(elem) {
        if (elem.hasClass(self.options.dragClass)) {
          return elem;
        } else {
          var els = $E(elem).getElementsByTagName("*");
          for (var i = 0; i < els.length; i++) {
            if ($G(els[i]).hasClass(self.options.dragClass)) {
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
      forEach($E(id).getElementsByTagName("*"), function() {
        if ($G(this).hasClass(self.options.itemClass)) {
          var drag = new GDrag(_find(this), o);
          drag.move = this;
          dropitems.push(this);
        }
      });
    }
  };
})();