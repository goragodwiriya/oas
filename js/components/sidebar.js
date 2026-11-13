/**
 * Sidebar Component
 * Dynamic navigation menu loaded from API with 2-level nested menu support
 * Uses data-source attribute for declarative menu data binding via MenuManager
 */
Now.getManager('component').define('sidebar', {
  template:
    `<aside class="sidebar sidemenu-panel">
      <div data-component="api" data-endpoint="api/index/auth/me" data-refresh-event="form:submitted,table:action,auth:restored">
        <div class="sidebar-header">
          <a href="/" class="logo logo-tint" data-style="backgroundImage:url({{data.logo}})"></a>
          <a href="/" class="logo-text" data-text="data.web_title">Admin Framework</a>
        </div>
        <nav class="sidemenu" data-component="menu" data-source="data.menus"></nav>
      </div>
    </aside>`
});
