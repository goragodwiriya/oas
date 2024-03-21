/**
 @name GBanner
 @description คลาสสำหรับการแสดงรูปภาพแบบไสลด์โชว์
 @author https://goragod.com (goragod wiriya)
 @version 07-08-61

 @param string className คลาสของ GBanner ค่าเริ่มต้นคือ gbanner
 @param int slideTime เวลาในการเปลี่ยนไสลด์อัตโนมัติ (msec) ค่าเริ่มต้นคือ 5000
 @param boolean backgroundImage กำหนดให้แสดงรูปภาพเป็นพื้นหลังของ figure ค่าเริ่มต้นคือ false
 @param boolean touchThumbnail สนับสนุนการปัด Thumbnail หากมีการแสดง Thumbnail ค่าเริ่มต้นคือ false
 @param boolean showNumber แสดงตัวเลขในปุ่มกดเลือกรูปภาพหรือไม่ ค่าเริ่มต้นคือ false
 @param boolean loop true (ค่าเริ่มต้น) วนแสดงรูปไปเรื่อยๆ
 */
var GBanner = GClass.create();
GBanner.prototype = {
  initialize: function(div, options) {
    this.options = {
      className: "gbanner",
      slideTime: 5000,
      backgroundImage: false,
      touchThumbnail: false,
      showNumber: false,
      loop: true
    };
    for (var property in options) {
      this.options[property] = options[property];
    }
    this.slideshow = $G(div);
    this.slideshow.addClass(this.options.className);
    this.container = this.slideshow.querySelector("div");
    if (!this.container) {
      this.container = document.createElement("div");
      this.slideshow.appendChild(this.container);
    }
    $G(this.container).className = "slide_display";
    var tmp = this;
    this.next = this.container.create("span");
    this.next.className = "btnnav next";
    this.next.title = trans("Next");
    callClick(this.next, function() {
      window.clearTimeout(tmp.SlideTime);
      tmp._nextSlide();
    });
    this.prev = this.container.create("span");
    this.prev.className = "btnnav prev";
    this.prev.title = trans("Prev");
    callClick(this.prev, function() {
      window.clearTimeout(tmp.SlideTime);
      tmp._prevSlide();
    });
    this.buttons = $G(this.slideshow.create("div"));
    this.buttons.style.zIndex = 2;
    this.buttons.className = this.options.touchThumbnail ? 'button_wrapper_thumbnail' : 'button_wrapper';
    this.button = $G(this.buttons.create("div"));
    this.button.className = "button_container scroll";
    this.button.style.position = "relative";
    this.datas = new Array();
    forEach(this.container.querySelectorAll(".figure"), function() {
      tmp._initItem(this);
    });
    this.drag = false;
    if (this.options.touchThumbnail) {
      new GDragMove(this.button, this.buttons, {
        srcOnly: false,
        beginDrag: function(e) {
          tmp.button.className = "button_container";
          return true;
        },
        moveDrag: function(e) {
          tmp.drag = true;
          var l = tmp.buttons.getWidth() - tmp.button.getWidth();
          if (l < 0) {
            tmp.button.style.left = Math.min(0, Math.max(l, e.mousePos.x - e.mouseOffset.x)) + "px";
          }
        },
        endDrag: function() {
          tmp.button.className = "button_container scroll";
          tmp.drag = false;
          return true;
        }
      });
    }
    this.currentId = -1;
  },
  add: function(picture, detail, url) {
    var figure = document.createElement("figure");
    this.container.appendChild(figure);
    figure.className = 'figure';
    var img = document.createElement("img");
    img.src = picture;
    img.className = "nozoom";
    figure.appendChild(img);
    if (detail && detail != "") {
      var figcaption = document.createElement("figcaption");
      figure.appendChild(figcaption);
      var a = document.createElement("a");
      a.href = url;
      a.target = "_blank";
      figcaption.appendChild(a);
      var span = document.createElement("span");
      span.innerHTML = detail;
      a.appendChild(span);
    }
    this._initItem(figure);
    return this;
  },
  JSONData: function(data) {
    try {
      var datas = eval(data);
      for (var i = 0; i < datas.length; i++) {
        this.add(datas[i].picture, datas[i].detail || "", datas[i].url || "");
      }
    } catch (e) {}
    return this;
  },
  _initItem: function(obj) {
    var i = this.datas.length;
    this.datas.push($G(obj));
    var img = obj.querySelector("img"),
      a = $G(this.button.create("button"));
    var span = a.create("span");
    a.title = i;
    if (i == 0) {
      obj.addClass('show');
    } else {
      obj.removeClass('show');
    }
    if (img) {
      if (this.options.backgroundImage) {
        obj.style.backgroundImage = "url(" + img.src + ")";
        img.style.display = "none";
      } else {
        img.className = "nozoom";
      }
      span.style.backgroundImage = "url(" + img.src + ")";
    } else {
      span.style.backgroundImage = obj.style.backgroundImage;
    }
    if (this.options.showNumber) {
      a.appendChild(document.createTextNode(i + 1));
    }
    var tmp = this;
    a.style.cursor = "pointer";
    a.addEvent("mouseup", function() {
      if (tmp.drag == false) {
        window.clearTimeout(tmp.SlideTime);
        tmp._show(floatval(this.title));
      }
    });
  },
  _prevSlide: function() {
    if (this.datas.length > 0) {
      var next = this.currentId - 1;
      if (next < 0 && this.options.loop) {
        next = this.datas.length - 1;
      }
      this._playIng(next);
    }
  },
  _nextSlide: function() {
    if (this.datas.length > 0) {
      var next = this.currentId + 1;
      if (next >= this.datas.length && this.options.loop) {
        next = 0;
      }
      this._playIng(next);
    }
  },
  _playIng: function(id) {
    if ($E(this.slideshow.id)) {
      this._show(id);
      if (this.datas.length > 1) {
        var temp = this;
        this.SlideTime = window.setTimeout(function() {
          temp.playSlideShow.call(temp);
        }, this.options.slideTime);
      }
    }
  },
  playSlideShow: function() {
    this._nextSlide();
    return this;
  },
  _show: function(id) {
    if (this.datas[id]) {
      var figcaption;
      forEach(this.datas, function(item, index) {
        figcaption = item.querySelector("figcaption");
        if (id == index) {
          item.addClass('show');
          item.style.zIndex = 1;
          if (figcaption) {
            figcaption.className = "show";
          }
        } else {
          item.removeClass('show');
          item.style.zIndex = 0;
          if (figcaption) {
            figcaption.className = "";
          }
        }
      });
      this._setButton(id);
      this.currentId = id;
    }
  },
  _setButton: function(id) {
    var tmp = this,
      current;
    forEach(this.button.elems("button"), function() {
      if (this.title == id) {
        this.className = "current";
        current = this;
      } else {
        this.className = "";
      }
    });
    window.setTimeout(function() {
      var cw = tmp.buttons.getWidth(),
        bw = tmp.button.getWidth(),
        l = current.getLeft() - tmp.buttons.getLeft(),
        w = current.getWidth();
      if (bw > cw) {
        if (l + w > cw) {
          tmp.button.style.left = Math.min(0, Math.max(cw - bw, cw - (id + 1) * w)) + "px";
        } else if (l < 0) {
          tmp.button.style.left = Math.min(0, Math.max(cw - bw, -id * w)) + "px";
        }
      }
      tmp.prev.style.left = id == 0 ? -tmp.prev.getWidth() + "px" : "0.5em";
      tmp.next.style.right = id == tmp.datas.length - 1 ? -tmp.next.getWidth() + "px" : "0.5em";
    }, 1);
  }
};
