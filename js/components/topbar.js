/**
 * Topbar Component
 * Top header bar with user profile
 */
Now.getManager('component').define('topbar', {
  template:
    `<header class="topbar">
      <div data-component="api" data-endpoint="api/index/auth/me">
        <div class="topbar-left">
          <h1 data-text="data.web_title">Nowjs</h1>
          <p data-text="data.web_description">Admin Framework</p>
        </div>
        <div class="topbar-right">
          <!-- User profile dropdown -->
          <button class="user-profile btn dropdown">
            <img data-attr="src:data.avatar" class="avatar">
            <span class="user-name" data-text="data.name"></span>
            <ul>
              <li data-show="data.id > 0"><a data-attr="href:'profile?id=' + data.id" class="icon-customer" data-i18n>Edit profile</a></li>
              <li data-show="data.id > 0"><a href="logout" class="icon-signout" data-i18n>Logout</a></li>
              <li data-show="data.id == 0"><a href="login" class="icon-signin" data-i18n>Login</a></li>
            </ul>
          </button>

          <!-- Language toggle component (cycles configured locales) -->
          <button class="lang-toggle btn dropdown" data-component="lang-toggle" data-locales="en,th" title="Switch language">
            <span data-lang-label>EN</span>
            <ul>
              <li><a data-locale="th">TH</a></li>
              <li><a data-locale="en">EN</a></li>
            </ul>
          </button>

          <!-- Theme toggle component (cycles configured themes) -->
          <button data-component="config" class="nav-link" title="Toggle theme"></button>

          <!-- Menu toggle component (toggles side menu) -->
          <button class="menu-toggle sidemenu-toggle">
            <span class="toggle-icon">
              <span class="toggle-bar"></span>
              <span class="toggle-bar"></span>
              <span class="toggle-bar"></span>
            </span>
          </button>
        </div>
      </div>
    </header>`
});
