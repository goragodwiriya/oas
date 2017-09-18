function initInventoryWrite() {
  initAutoComplete('write_unit', 'index/model/autocomplete/findCategory', 'name', 'expand', {
    get: function () {
      return 'typ=3&name=' + encodeURIComponent($E('write_unit').value);
    },
    callBack: function () {
      $G('write_unit').setValue(this.name).reset();
    }
  });
  initAutoComplete('write_category', 'index/model/autocomplete/findCategory', 'name', 'category', {
    get: function () {
      return 'typ=0&name=' + encodeURIComponent($E('write_category').value);
    },
    callBack: function () {
      $G('write_category').setValue(this.name).reset();
    }
  });
  $G('write_product_no').addEvent('keydown', function (evt) {
    if (GEvent.keyCode(evt) == 13) {
      GEvent.stop(evt);
    }
  });
  var doCountStock = function () {
    var quantity = $G($E('write_quantity').parentNode.parentNode);
    if (this.value == 1) {
      quantity.removeClass('hidden');
    } else {
      quantity.addClass('hidden');
    }
  };
  if ($E('write_quantity') && $E('write_count_stock')) {
    $G('write_count_stock').addEvent('change', doCountStock);
  }
}
function initInventoryOverview(id) {
  new GGraphs('year_graph', {type: 'line'});
  $G('year').addEvent('change', function () {
    loaddoc(WEB_URL + 'index.php?module=inventory-write&tab=overview&to=year_graph&id=' + id + '&y=' + this.value);
  });
}
function initInventoryInOut(vat_percent, typ) {
  var tbody = $G('tb_products');
  var addCustomer = function () {
    showModal('xhr.php', 'class=Inventory\\Customer\\\Controller&method=showModal&typ=1&name=' + encodeURIComponent($E('customer').value), function () {
      $E('customer').focus();
    });
  };
  var addProduct = function () {
    showModal('xhr.php', 'class=Inventory\\Write\\\Controller&method=showModal&product_no=' + encodeURIComponent($E('product_no').value), function () {
      $E('product_no').focus();
    });
  };
  callClick('add_customer', addCustomer);
  callClick('add_product', addProduct);
  initAutoComplete('customer', 'index/model/autocomplete/findUser', 'company,name,email,phone', 'user', {
    get: function () {
      return 'name=' + encodeURIComponent($E('customer').value) +
        '&from=company,name,email,phone';
    },
    callBack: function () {
      $E('customer_id').value = this.id;
      $G('customer').valid().value = this.company.unentityify();
    },
    onChanged: function () {
      $E('customer_id').value = 0;
      $G('customer').reset();
    }
  });
  initAutoComplete('product_no', 'inventory/model/autocomplete/findProduct', 'product_no,topic', 'addtocart', {
    get: function () {
      return 'name=' + encodeURIComponent($E('product_no').value) +
        '&from=product_no,topic';
    },
    callBack: function () {
      $G('product_no').valid().value = this.product_no.unentityify();
      send('index.php/inventory/model/search/fromProductno', 'product_no=' + this.product_no + '&typ=' + typ, function (xhr) {
        var ds = xhr.responseText.toJSON();
        if (ds) {
          var inputs,
            ntr = findInputRow('id', ds.id),
            quantity = $E('product_quantity').value.toInt();
          if (ntr == null) {
            ntr = findInputRow('topic', '');
            if (ntr == null) {
              ntr = $G(tbody.firstChild).copy(false);
              tbody.appendChild(ntr);
            } else {
              ntr = ntr.parentNode.parentNode.parentNode;
            }
            var inputs = $G(ntr).elems('input');
            setInputValue(inputs, 'quantity', quantity);
            setInputValue(inputs, 'topic', (ds.topic + ' ' + ds.description).unentityify());
            setInputValue(inputs, 'id', ds.id);
            setInputValue(inputs, 'price', ds.price);
            setInputValue(inputs, 'vat', ds.vat);
            setInputValue(inputs, 'discount', 0);
            ntr.removeClass('hidden');
          } else {
            ntr = $G(ntr.parentNode.parentNode);
            var input = getInput(ntr.elems('input'), 'quantity');
            input.value = input.value.toInt() + quantity;
          }
          initTBODY();
          $E('product_no').value = '';
          $E('product_quantity').value = 1;
        } else if (xhr.responseText != '') {
          alert(xhr.responseText);
        } else {
          alert(SORRY_XXX_NOT_FOUND.replace(/XXX/, $E('product_no').title));
          $G('product_no').invalid();
        }
      });
    }
  });
  function findInput(inputs, name) {
    var patt = new RegExp(name + '_[0-9]+'),
      l = inputs.length;
    for (var i = 0; i < l; i++) {
      if (patt.test(inputs[i].id)) {
        return inputs[i];
      }
    }
    return null;
  }
  function findInputRow(name, val) {
    var tr, patt = new RegExp(name + '_[0-9]+');
    forEach($G(tbody).elems('input'), function () {
      if (patt.test(this.id) && this.value == val) {
        tr = this;
        return true;
      }
    });
    return tr;
  }
  function getInput(inputs, name) {
    return findInput(inputs, name);
  }
  function setInputValue(inputs, name, value) {
    var input = findInput(inputs, name);
    if (input) {
      input.value = value;
      if (input.type.toLowerCase() == 'checkbox') {
        input.checked = value > 0;
      }
    }
  }
  var priceOnly = function (e) {
    return _doCheckKey(this, e, /[0-9\.\-]/);
  };
  var doCurrency = function () {
    this.value = this.value.currFormat();
  };
  var doChanged = function (e) {
    var id,
      _quantity,
      _price,
      _discount,
      _total,
      _total_w_tax = 0,
      total = 0,
      vat = 0,
      tax = 0,
      discount_percent,
      discount,
      tax_status = $E('tax_status').value,
      vat_status = $E('vat_status').value;
    forEach(tbody.elems('tr'), function () {
      id = this.id.replace(tbody.id + '_', '');
      _quantity = Math.max(1, $E('quantity_' + id).value.toInt());
      _price = $E('price_' + id).value.toInt();
      _discount = $E('discount_' + id).value.toInt();
      if (_discount > 0) {
        _total = (_price - ((_discount * _price) / 100)) * _quantity;
      } else {
        _total = _price * _quantity;
      }
      if ($E('vat_' + id).checked) {
        _total_w_tax += _total;
      }
      total += _total;
      $E('total_' + id).value = _total.toFixed(2);
      $E('vat_' + id).value = vat_status > 0 ? round(calcVat(_total, vat_percent, vat_status == 1), 2) : 0;
    });
    $E('sub_total').innerHTML = total.toFixed(2);
    var discount_percent = $E('discount_percent').value.toInt();
    if (discount_percent > 0) {
      discount = (discount_percent * total) / 100;
      $E('total_discount').value = discount.toFixed(2);
      _total_w_tax -= ((discount_percent * _total_w_tax) / 100);
    } else {
      discount = $E('total_discount').value.toInt();
      if (discount > 0) {
        _total_w_tax -= discount;
      }
    }
    if (vat_status > 0) {
      vat = round(calcVat(_total_w_tax, vat_percent, vat_status == 1), 2);
      if (vat_status == 2) {
        total -= vat;
      }
    }
    if (tax_status > 0) {
      tax = ((total - discount) * tax_status) / 100;
    }
    $E('amount').value = (total - discount).toFixed(2);
    $E('vat_total').value = vat.toFixed(2);
    $E('tax_total').value = tax.toFixed(2);
    $E('grand_total').innerHTML = (total - discount + vat).toFixed(2);
    $E('payment_amount').innerHTML = (total - discount + vat - tax).toFixed(2);
  };
  function initTBODY() {
    var cls, row = 0;
    forEach(tbody.elems('tr'), function () {
      this.id = tbody.id + '_' + row;
      forEach($G(this).elems('input'), function () {
        $G(this).id = this.name.replace(/([\[\]_]+)/g, '_') + row;
        if (this.className == 'num') {
          this.addEvent('keypress', numberOnly);
          this.addEvent('change', doChanged);
        } else if (this.className == 'price') {
          this.addEvent('keypress', priceOnly);
          this.addEvent('change', doChanged);
          this.addEvent('blur', doCurrency);
          doCurrency.call(this);
        } else if (this.className == 'amount') {
          this.addEvent('keypress', currencyOnly);
          this.addEvent('change', doChanged);
          this.addEvent('blur', doCurrency);
          doCurrency.call(this);
        } else if (this.className == 'vat') {
          this.addEvent('change', doChanged);
        }
        this.addEvent('focus', function () {
          this.select();
        });
      });
      forEach($G(this).elems('a'), function () {
        cls = $G(this).hasClass('delete');
        if (cls == 'delete') {
          callClick(this, function () {
            if (tbody.elems('tr').length > 1 && confirm(trans('You want to XXX ?').replace(/XXX/, trans('delete')))) {
              var tr = $G(this.parentNode.parentNode);
              tr.remove();
              doChanged.call(null);
            }
          });
        }
      });
      row++;
    });
    doChanged.call(null);
  }
  initTBODY();
  $G('total_discount').addEvent('change', doChanged);
  $G('discount_percent').addEvent('change', doChanged);
  $G('tax_status').addEvent('change', doChanged);
  $G('vat_status').addEvent('change', doChanged);
  document.body.addEvent('keydown', function (e) {
    var keycode = GEvent.keyCode(e);
    if (keycode == 13) {
      var elem = GEvent.element(e);
      var tag = elem.tagName.toLowerCase();
      if (tag != 'a' && tag != 'button' && tag != 'textarea' && elem.id != 'paid' && elem.type != 'submit') {
        GEvent.stop(e);
        return false;
      }
    } else if (keycode == 113) {
      /* F2 */
      $E('customer').focus();
    } else if (keycode == 115) {
      /* F4 */
      $E('product_no').focus();
    } else if (keycode == 118) {
      /* F7 */
      if ($E('add_product')) {
        $E('add_product').click();
      } else if ($E('add_order')) {
        $E('add_order').click();
      }
    } else if (keycode == 119) {
      /* F8 */
      $E('discount_percent').focus();
    } else if (keycode == 120) {
      /* F9 */
      if ($E('paid')) {
        $E('paid').focus();
      } else if ($E('status')) {
        $E('status').focus();
      }
    } else if (keycode == 121) {
      /* F10 */
      $E('order_submit').click();
    }
  });
}
function doInventorySubmit(xhr) {
  var datas = xhr.responseText.toJSON();
  if (datas) {
    defaultSubmit(datas);
    if (datas.valid && $E(datas.valid)) {
      $G(datas.valid).valid();
    }
  } else if (xhr.responseText != '') {
    alert(xhr.responseText);
  }
}
function calcVat(amount, vat, vat_ex) {
  if (!vat_ex) {
    return amount - (amount * (100 / (100 + vat)));
  } else {
    return ((vat * amount) / 100);
  }
}
function initPaymentDetails(order) {
  if ($E('payment_print')) {
    callClick('payment_print', function () {
      billingPrint(order, 'print_id', 'typ');
    });
  }
  if ($E('payment_email')) {
    callClick('payment_email', function () {
      billingEmail(order, 'print_id', 'typ');
    });
  }
}
