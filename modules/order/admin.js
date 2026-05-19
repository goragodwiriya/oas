// Register Routes for Order Module via EventManager
EventManager.on('router:initialized', () => {
  // Order settings
  RouterManager.register('/', {
    template: 'order/dashboard.html',
    title: '{LNG_Dashboard}',
    requireAuth: true
  });
  // Order settings
  RouterManager.register('/order-settings', {
    template: 'order/settings.html',
    title: '{LNG_Module settings}',
    requireAuth: true
  });
  // Order list
  RouterManager.register('/orders', {
    template: 'order/setup.html',
    title: '{LNG_Orders}',
    requireAuth: true
  });
  // Order view/edit
  RouterManager.register('/order', {
    template: 'order/write.html',
    title: '{LNG_Order}',
    requireAuth: true
  });
  // Payment channels list
  RouterManager.register('/payment-channels', {
    template: 'order/payments.html',
    title: '{LNG_Payment channels}',
    requireAuth: true
  });
  // Payment channel add/edit
  RouterManager.register('/payment-channel', {
    template: 'order/paymentchannel.html',
    title: '{LNG_Payment channel}',
    menuPath: '/payment-channels',
    requireAuth: true
  });
  // Shipping methods list
  RouterManager.register('/shipping-methods', {
    template: 'order/shippings.html',
    title: '{LNG_Shipping methods}',
    requireAuth: true
  });
  // Shipping method add/edit
  RouterManager.register('/shipping-method', {
    template: 'order/shippingmethod.html',
    title: '{LNG_Shipping method}',
    menuPath: '/shipping-methods',
    requireAuth: true
  });
});

function toOrderNumber(value, fallback = 0) {
  const number = parseFloat(String(value ?? '').replace(/,/g, ''));
  return Number.isFinite(number) ? number : fallback;
}

let ORDER_VALUE_DECIMALS = 2;

function normalizeOrderValueDecimals(value) {
  const decimals = parseInt(value, 10);
  return decimals === 2 || decimals === 4 ? decimals : 4;
}

function getOrderQuantityStep(decimals = ORDER_VALUE_DECIMALS) {
  const normalized = normalizeOrderValueDecimals(decimals);
  return (1 / Math.pow(10, normalized)).toFixed(normalized);
}

function setOrderValueDecimals(value) {
  ORDER_VALUE_DECIMALS = normalizeOrderValueDecimals(value);

  return ORDER_VALUE_DECIMALS;
}

function roundOrderNumber(value, decimals = ORDER_VALUE_DECIMALS) {
  return Number(toOrderNumber(value, 0).toFixed(decimals));
}

function formatOrderValue(value, decimals = ORDER_VALUE_DECIMALS) {
  return roundOrderNumber(value, decimals).toFixed(decimals);
}

function clampOrderQuantity(value, fallback = 1) {
  const minimumQuantity = Number(getOrderQuantityStep());

  return roundOrderNumber(Math.max(minimumQuantity, toOrderNumber(value, fallback)));
}

function formatOrderMoney(value, withCurrency = false) {
  const amount = roundOrderNumber(value);
  const formatted = amount.toLocaleString('th-TH', {
    minimumFractionDigits: ORDER_VALUE_DECIMALS,
    maximumFractionDigits: ORDER_VALUE_DECIMALS
  });
  return withCurrency ? `฿${formatted}` : formatted;
}

const ORDER_RECEIPT_STORAGE_KEY = 'orderReceiptItemsPerPage';
const ORDER_RECEIPT_DEFAULT_ITEMS_PER_PAGE = 10;

function normalizeOrderReceiptItemsPerPage(value) {
  const parsed = parseInt(value, 10);
  if (!Number.isFinite(parsed)) {
    return ORDER_RECEIPT_DEFAULT_ITEMS_PER_PAGE;
  }

  return Math.min(25, Math.max(5, parsed));
}

function getStoredOrderReceiptItemsPerPage() {
  try {
    return normalizeOrderReceiptItemsPerPage(window.localStorage.getItem(ORDER_RECEIPT_STORAGE_KEY));
  } catch (error) {
    return ORDER_RECEIPT_DEFAULT_ITEMS_PER_PAGE;
  }
}

function setStoredOrderReceiptItemsPerPage(value) {
  const normalized = normalizeOrderReceiptItemsPerPage(value);

  try {
    window.localStorage.setItem(ORDER_RECEIPT_STORAGE_KEY, String(normalized));
  } catch (error) {
    // Ignore storage failures in private mode or restricted browsers.
  }

  return normalized;
}

function getOrderReceiptItemsPerPage(root = document) {
  const select = root.querySelector('#orderPrintItemsPerPage');
  return select ? normalizeOrderReceiptItemsPerPage(select.value) : getStoredOrderReceiptItemsPerPage();
}

function buildOrderReceiptUrl(orderIds, root = document) {
  const ids = Array.from(new Set((orderIds || [])
    .map(value => parseInt(value, 10))
    .filter(value => Number.isFinite(value) && value > 0)));

  if (ids.length === 0) {
    return '';
  }

  const params = new URLSearchParams();
  if (ids.length === 1) {
    params.set('id', String(ids[0]));
  } else {
    params.set('ids', ids.join(','));
  }
  params.set('items_per_page', String(getOrderReceiptItemsPerPage(root)));

  return `export.php?module=order&typ=print&${params.toString()}`;
}

function openOrderReceiptPrint(orderIds, root = document, target = '_blank') {
  const url = buildOrderReceiptUrl(orderIds, root);
  if (!url) {
    return;
  }

  if (target === '_self') {
    window.location.href = url;
    return;
  }

  window.open(url, target, 'noopener');
}

if (!window.OrderItemsDraft) {
  const ORDER_ITEMS_DRAFT_PREFIX = 'order-items-draft:';
  const ORDER_PARTS_DRAFT_PREFIX_LEGACY = 'parts-order-draft:';

  const buildOrderDraftStorageKey = (prefix, target) => `${prefix}${String(target || 'new')}`;

  window.OrderItemsDraft = {
    targetKey(orderId) {
      const numericId = parseInt(orderId, 10);
      return Number.isFinite(numericId) && numericId > 0 ? `order:${numericId}` : 'new';
    },
    getItems(target) {
      try {
        const raw = window.localStorage.getItem(buildOrderDraftStorageKey(ORDER_ITEMS_DRAFT_PREFIX, target))
          || window.localStorage.getItem(buildOrderDraftStorageKey(ORDER_PARTS_DRAFT_PREFIX_LEGACY, target));
        const items = raw ? JSON.parse(raw) : [];
        return Array.isArray(items) ? items : [];
      } catch (error) {
        return [];
      }
    },
    clear(target) {
      try {
        window.localStorage.removeItem(buildOrderDraftStorageKey(ORDER_ITEMS_DRAFT_PREFIX, target));
        window.localStorage.removeItem(buildOrderDraftStorageKey(ORDER_PARTS_DRAFT_PREFIX_LEGACY, target));
      } catch (error) {
        // Ignore storage failures in restricted browsers.
      }
    }
  };
}

function getSelectedOrderIds(root = document) {
  const table = root.querySelector('[data-table="orderList"]');
  if (!table) {
    return [];
  }

  return Array.from(table.querySelectorAll('tbody .select-row:checked'))
    .map(input => parseInt(input.value, 10))
    .filter(value => Number.isFinite(value) && value > 0);
}

function syncOrderReceiptItemsPerPageControl(root = document) {
  const select = root.querySelector('#orderPrintItemsPerPage');
  if (select) {
    select.value = String(getStoredOrderReceiptItemsPerPage());
  }
}

function updateSelectedOrderPrintButton(root = document) {
  const button = root.querySelector('[data-order-print-selected]');
  if (!button) {
    return;
  }

  const selectedIds = getSelectedOrderIds(root);
  const baseLabel = button.dataset.baseLabel || button.dataset.label || button.textContent.trim() || 'Print selected';
  button.dataset.baseLabel = baseLabel;
  button.disabled = selectedIds.length === 0;
  button.textContent = selectedIds.length > 0 ? `${baseLabel} (${selectedIds.length})` : baseLabel;
}

function normalizeOrderLineItem(item) {
  const productCode = String(item?.product_code || item?.part_no || item?.sku || '').trim();
  if (productCode === '') {
    return null;
  }

  const qty = clampOrderQuantity(item?.qty ?? item?.quantity ?? 1);
  const price = roundOrderNumber(Math.max(0, toOrderNumber(item?.price ?? item?.unit_price ?? item?.list_price, 0)));
  const discountAmount = roundOrderNumber(Math.max(0, toOrderNumber(item?.discount_amount, 0)));
  const lineBase = roundOrderNumber(qty * price);
  const lineDiscount = roundOrderNumber(Math.min(lineBase, discountAmount));

  return {
    id: parseInt(item?.id || 0, 10) || 0,
    part_id: parseInt(item?.part_id || item?.id || 0, 10) || 0,
    inventory_item_id: parseInt(item?.inventory_item_id || 0, 10) || 0,
    product_code: productCode,
    source_item_id: parseInt(item?.source_item_id || 0, 10) || 0,
    root_item_id: parseInt(item?.root_item_id || 0, 10) || 0,
    name: item?.name || '',
    qty,
    unit: item?.unit || '',
    price,
    cost_price: roundOrderNumber(Math.max(0, toOrderNumber(item?.cost_price, 0))),
    discount_amount: lineDiscount,
    total: roundOrderNumber(Math.max(0, lineBase - lineDiscount)),
    note: item?.note || item?.notes || ''
  };
}

function getOrderLineMergeKeys(item) {
  const keys = [];

  if ((item?.inventory_item_id || 0) > 0) {
    keys.push(`item:${item.inventory_item_id}`);
  }
  if (item?.product_code) {
    keys.push(`code:${String(item.product_code).trim().toUpperCase()}`);
  }

  return keys;
}

function setMergedOrderLineItem(merged, item) {
  const keys = getOrderLineMergeKeys(item);
  keys.forEach(key => {
    merged.set(key, item);
  });
}

function getMergedOrderLineItem(merged, item) {
  const keys = getOrderLineMergeKeys(item);
  for (const key of keys) {
    if (merged.has(key)) {
      return merged.get(key);
    }
  }

  return null;
}

function mergeOrderLineItems(baseItems, draftItems) {
  const merged = new Map();

  (baseItems || []).forEach(item => {
    const normalized = normalizeOrderLineItem(item);
    if (normalized) {
      setMergedOrderLineItem(merged, normalized);
    }
  });

  (draftItems || []).forEach(item => {
    const normalized = normalizeOrderLineItem(item);
    if (!normalized) {
      return;
    }

    const existing = getMergedOrderLineItem(merged, normalized);
    if (existing) {
      existing.qty = roundOrderNumber(existing.qty + normalized.qty);
      if (normalized.note) {
        existing.note = normalized.note;
      }
      if (!existing.unit && normalized.unit) {
        existing.unit = normalized.unit;
      }
      if (!existing.inventory_item_id && normalized.inventory_item_id) {
        existing.inventory_item_id = normalized.inventory_item_id;
      }
      setMergedOrderLineItem(merged, existing);
    } else {
      setMergedOrderLineItem(merged, normalized);
    }
  });

  return Array.from(new Set(merged.values()));
}

function getOrderDocumentProfile(documentType, profiles, fallbackProfile = {}) {
  const normalizedType = String(documentType || '').trim().toUpperCase();
  if (normalizedType && profiles && typeof profiles === 'object' && profiles[normalizedType]) {
    return profiles[normalizedType];
  }

  return fallbackProfile || {};
}

window.calculateOrderItems = function(ctx = {}) {
  const {items = [], instance} = ctx;
  const form = instance?.form || instance?.table?.closest('form') || document;
  const getNumber = (selector) => {
    const input = form.querySelector(selector);
    return input ? parseFloat(String(input.value).replace(/,/g, '')) || 0 : 0;
  };

  const emptyState = form.querySelector('#orderItemsEmptyState');

  let subtotal = 0;

  const updatedItems = items.map((item) => {
    const normalized = normalizeOrderLineItem(item) || {
      part_id: parseInt(item?.part_id || item?.id || 0, 10) || 0,
      inventory_item_id: parseInt(item?.inventory_item_id || 0, 10) || 0,
      product_code: String(item?.product_code || item?.part_no || item?.sku || '').trim(),
      name: item?.name || '',
      qty: clampOrderQuantity(item?.qty ?? item?.quantity ?? 1),
      unit: item?.unit || '',
      price: roundOrderNumber(Math.max(0, toOrderNumber(item?.price ?? item?.unit_price, 0))),
      cost_price: roundOrderNumber(Math.max(0, toOrderNumber(item?.cost_price, 0))),
      discount_amount: roundOrderNumber(Math.max(0, toOrderNumber(item?.discount_amount, 0))),
      note: item?.note || item?.notes || ''
    };

    const lineBase = roundOrderNumber(normalized.qty * normalized.price);
    const lineDiscount = roundOrderNumber(Math.min(lineBase, Math.max(0, normalized.discount_amount)));
    const lineSubtotal = roundOrderNumber(Math.max(0, lineBase - lineDiscount));

    subtotal = roundOrderNumber(subtotal + lineSubtotal);

    return {
      id: normalized.id || item?.id || 0,
      part_id: normalized.part_id || item?.part_id || item?.id || 0,
      inventory_item_id: normalized.inventory_item_id || item?.inventory_item_id || 0,
      product_code: normalized.product_code,
      source_item_id: normalized.source_item_id || item?.source_item_id || null,
      root_item_id: normalized.root_item_id || item?.root_item_id || null,
      qty: formatOrderValue(normalized.qty),
      unit: normalized.unit || item?.unit || '',
      price: formatOrderValue(normalized.price),
      cost_price: formatOrderValue(normalized.cost_price),
      discount_amount: formatOrderValue(lineDiscount),
      total: formatOrderValue(lineSubtotal),
      note: normalized.note || ''
    };
  });

  const documentDiscount = roundOrderNumber(Math.max(0, Math.min(getNumber('#order_discount_amount'), subtotal)));
  const vatRate = roundOrderNumber(Math.max(0, getNumber('#order_vat_rate, #tax_rate')));
  const shippingCost = roundOrderNumber(Math.max(0, getNumber('#shipping_cost')));
  const baseAmount = roundOrderNumber(Math.max(0, subtotal - documentDiscount));
  const vatAmount = roundOrderNumber(baseAmount * (vatRate / 100));
  const totalAmount = roundOrderNumber(baseAmount + vatAmount + shippingCost);

  if (emptyState) {
    emptyState.classList.toggle('hidden', updatedItems.length > 0);
  }

  return {
    items: updatedItems,
    '#order_subtotal': formatOrderValue(subtotal),
    '#order_discount_amount': formatOrderValue(documentDiscount),
    '#order_vat_amount': formatOrderValue(vatAmount),
    '#order_total_amount': formatOrderValue(totalAmount),
    '#orderTotalView': formatOrderMoney(totalAmount, true),
    '#orderSubtotalView': formatOrderMoney(subtotal),
    '#orderTaxAmountView': formatOrderMoney(vatAmount)
  };
};

window.reCalculateOrderItems = function(e) {
  if (window.LineItemsManager) {
    LineItemsManager.recalculate(e);
  }
};

window.initOrderWrite = function(element, context) {
  const data = context?.data || context || {};
  const options = context?.options && typeof context.options === 'object' ? context.options : {};
  const url = new URL(window.location.href);
  const orderId = parseInt(data.id || url.searchParams.get('id') || 0, 10);
  const form = element.querySelector('form[data-form="orderWrite"]');
  const defaultFormAction = form?.getAttribute('action') || 'api/order/write/save';
  const target = window.OrderItemsDraft.targetKey(orderId);
  const clearDraftTarget = url.searchParams.get('clear_items_draft') || url.searchParams.get('clear_parts_draft') || '';
  const useDraft = url.searchParams.get('draft') === '1';

  if (clearDraftTarget) {
    window.OrderItemsDraft.clear(clearDraftTarget);
    url.searchParams.delete('clear_items_draft');
    url.searchParams.delete('clear_parts_draft');
    window.history.replaceState({}, '', `${url.pathname}${url.search}`);
  }

  if (!orderId && !useDraft) {
    window.OrderItemsDraft.clear('new');
  }

  const shippingInput = element.querySelector('#shipping_cost');
  const discountInput = element.querySelector('#order_discount_amount, #discount_amount');
  const taxRateInput = element.querySelector('#order_vat_rate, #tax_rate');
  const printItemsPerPageInput = element.querySelector('#orderPrintItemsPerPage');
  const printButton = element.querySelector('#orderPrintBtn');
  const documentTypeSelect = element.querySelector('#document_type');
  const saveAsNewDocumentCheckbox = element.querySelector('#saveAsNewDocument');
  const lineItemsTable = element.querySelector('[data-line-items="items"]');
  const orderValueDecimals = setOrderValueDecimals(options.value_decimals ?? data.value_decimals);
  const lineItems = lineItemsTable
    ? (window.LineItemsManager?.getInstance(lineItemsTable) || window.LineItemsManager?.create(lineItemsTable))
    : null;

  if (taxRateInput && taxRateInput.value !== '') {
    taxRateInput.value = formatOrderValue(taxRateInput.value, orderValueDecimals);
  }

  const applyDocumentProfile = (documentTypeValue) => {
  };

  syncOrderReceiptItemsPerPageControl(element);


  if (saveAsNewDocumentCheckbox) {
    saveAsNewDocumentCheckbox.checked = false;
  }

  if (lineItems) {
    lineItems.config.onCalculate = window.calculateOrderItems;
  }

  const existingItems = Array.isArray(data.items) ? data.items : [];
  const draftItems = window.OrderItemsDraft.getItems(target);
  const mergedItems = mergeOrderLineItems(existingItems, draftItems);

  applyDocumentProfile(documentTypeSelect?.value || data.document_type);

  const syncPrintButton = () => {
    if (!printButton) {
      return;
    }

    if (orderId > 0) {
      printButton.classList.remove('hidden');
      printButton.dataset.orderId = String(orderId);
      printButton.href = buildOrderReceiptUrl([orderId], element);
    } else {
      printButton.classList.add('hidden');
      printButton.removeAttribute('href');
      delete printButton.dataset.orderId;
    }
  };

  syncPrintButton();

  if (lineItems) {
    lineItems.setData(mergedItems);
    lineItems.calculate();
  }

  const recalculate = () => {
    if (lineItems) {
      lineItems.calculate();
    }
  };

  [shippingInput, discountInput, taxRateInput].forEach(input => {
    input?.addEventListener('input', recalculate);
    input?.addEventListener('change', recalculate);
  });

  if (saveAsNewDocumentCheckbox) {
    saveAsNewDocumentCheckbox.checked = false;
  }

  const onDocumentTypeChange = () => {
    if (saveAsNewDocumentCheckbox) {
      saveAsNewDocumentCheckbox.checked = true;
    }
  };

  documentTypeSelect?.addEventListener('change', onDocumentTypeChange);

  const onSubmit = event => {
    if (form) {
      form.setAttribute('action', defaultFormAction);
      const shouldCreateNew = Boolean(createNewDocumentCheckbox?.checked);
      const targetDocumentType = String(targetDocumentTypeSelect?.value || '').trim();

      if (shouldCreateNew && orderId > 0 && targetDocumentType !== '') {
        form.setAttribute('action', 'api/order/write/copy-document');
      } else if (shouldCreateNew && orderId > 0) {
        event.preventDefault();
        window.alert(Now.translate('Please select document type'));
        return;
      } else if (targetDocumentTypeSelect) {
        targetDocumentTypeSelect.value = '';
      }
    }

    if (lineItems) {
      lineItems.calculate();
    }

    const items = lineItems ? lineItems.getData().filter(item => normalizeOrderLineItem(item)) : [];

    if (items.length === 0) {
      event.preventDefault();
      window.alert(Now.translate('Please select at least one item'));
    }
  };

  const onPrintSettingsChange = event => {
    if (event.target === printItemsPerPageInput) {
      setStoredOrderReceiptItemsPerPage(event.target.value);
      syncPrintButton();
    }
  };

  const onPrintClick = event => {
    const trigger = event.target.closest('#orderPrintBtn');
    if (!trigger || !element.contains(trigger) || orderId < 1) {
      return;
    }

    event.preventDefault();
    openOrderReceiptPrint([orderId], element, trigger.target || '_blank');
  };

  element.addEventListener('submit', onSubmit, true);
  printItemsPerPageInput?.addEventListener('change', onPrintSettingsChange);
  element.addEventListener('click', onPrintClick);

  // Normalize created_at for date / datetime-local inputs so browser accepts API timestamps
  try {
    const createdInput = element.querySelector('input[data-attr="value:created_at"]');
    const createdRaw = data?.created_at || '';
    if (createdInput && createdRaw) {
      const str = String(createdRaw).trim();
      if (createdInput.type === 'date') {
        const m = str.match(/^(\d{4}-\d{2}-\d{2})/);
        if (m) {
          createdInput.value = m[1];
        } else {
          const d = new Date(str);
          if (!Number.isNaN(d.getTime())) {
            createdInput.value = d.toISOString().slice(0, 10);
          }
        }
      } else if (createdInput.type === 'datetime-local') {
        // Accept formats like 'YYYY-MM-DD HH:MM:SS' by converting space to 'T' and trimming seconds
        let iso = str.replace(' ', 'T');
        const m = iso.match(/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2})/);
        if (m) {
          createdInput.value = m[1];
        } else {
          const d = new Date(str);
          if (!Number.isNaN(d.getTime())) {
            createdInput.value = d.toISOString().slice(0, 16);
          }
        }
      } else {
        createdInput.value = str;
      }
    }
  } catch (e) {
    // non-fatal
  }

  return () => {
    [shippingInput, discountInput, taxRateInput].forEach(input => {
      input?.removeEventListener('input', recalculate);
      input?.removeEventListener('change', recalculate);
    });
    documentTypeSelect?.removeEventListener('change', onDocumentTypeChange);
    element.removeEventListener('submit', onSubmit, true);
    printItemsPerPageInput?.removeEventListener('change', onPrintSettingsChange);
    element.removeEventListener('click', onPrintClick);
    if (form) {
      form.setAttribute('action', defaultFormAction);
    }
  };
};

// Print order receipt — opens receipt in new tab
window.printOrderReceipt = function(btn) {
  const url = new URL(window.location.href);
  const orderId = parseInt(url.searchParams.get('id') || 0, 10);
  if (orderId > 0) {
    window.open('export.php?module=order&typ=print&id=' + orderId, '_blank');
  }
};
