EventManager.on('router:initialized', () => {
    RouterManager.register('/warehouses', {
        template: 'inventory/warehouses.html',
        title: '{LNG_Warehouses}',
        requireAuth: true
    });
    RouterManager.register('/warehouse', {
        template: 'inventory/warehouse.html',
        title: '{LNG_Warehouse}',
        menuPath: '/warehouses',
        requireAuth: true
    });
    RouterManager.register('/inventory-products', {
        template: 'inventory/products.html',
        title: '{LNG_Inventory}',
        requireAuth: true
    });

    RouterManager.register('/inventory-partner', {
        template: 'inventory/partner-edit.html',
        title: '{LNG_Edit} {LNG_Partner}',
        requireAuth: true
    });

    RouterManager.register('/inventory-stock-movements', {
        template: 'inventory/stock-movements.html',
        title: '{LNG_Stock Movement}',
        requireAuth: true
    });

    RouterManager.register('/inventory-cost-layers', {
        template: 'inventory/cost-layers.html',
        title: '{LNG_Cost Layers}',
        requireAuth: true
    });

    RouterManager.register('/inventory-product', {
        template: 'inventory/product-edit.html',
        title: '{LNG_Edit} {LNG_Inventory}',
        requireAuth: true
    });

    RouterManager.register('/inventory-items', {
        template: 'inventory/items.html',
        title: '{LNG_Item rows}',
        requireAuth: true
    });

    RouterManager.register('/inventory-categories', {
        template: 'inventory/categories.html',
        title: '{LNG_Category}',
        requireAuth: true
    });

    RouterManager.register('/inventory-settings', {
        template: 'inventory/settings.html',
        title: '{LNG_Module settings}',
        requireAuth: true
    });
});

function formatProductWithImage(cell, rawValue, rowData, attributes) {
    const opts = attributes.lookupOptions || attributes.tableDataOptions || attributes.tableFilterOptions;

    // Normalizer: build a map value->text
    const makeMap = (options) => {
        if (!options) return new Map();
        if (Array.isArray(options)) {
            // [{value,text}, ...]
            return new Map(options.map(o => [String(o.value), o.text]));
        }
        // object map {val: label, ...}
        return new Map(Object.entries(options).map(([k, v]) => [String(k), v]));
    };

    const map = makeMap(opts);

    const key = rawValue === null || rawValue === undefined ? '' : String(rawValue);
    const label = map.has(key) ? map.get(key) : (rawValue && rawValue.text) ? rawValue.text : key;
    const code = rowData?.product_code ? String(rowData.product_code) : ' ';

    const thumbHtml = rowData?.first_image_url
        ? `<span class="figure" style="background-image: url(${Utils.string.escape(rowData.first_image_url)});" aria-hidden="true"></span>`
        : '<span class="figure icon-thumbnail" aria-hidden="true"></span>';

    cell.innerHTML =
        `<span class="thumbnail">${thumbHtml}<span>
        <strong class="topic">${Utils.string.escape(label || '-')}</strong>
        <span class="description">${Utils.string.escape(code)}</span>
        </span></span>`;
}