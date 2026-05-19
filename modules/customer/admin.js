// Register Routes for Customer Module via EventManager
EventManager.on('router:initialized', () => {
  if (window.RouterManager) {
    RouterManager.register('/customers', {
      template: 'customer/customers.html',
      title: '{LNG_Customers}',
      requireAuth: true
    });
    RouterManager.register('/customer', {
      template: 'customer/customer.html',
      title: '{LNG_Customer}',
      menuPath: '/customers',
      requireAuth: true
    });
  }
});
