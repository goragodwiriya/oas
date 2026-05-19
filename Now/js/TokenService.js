/**
 * TokenService - Handles JWT token operations
 *
 * This service manages token parsing, validation and extraction of user information
 */
class TokenService {
  constructor(options = {}) {
    this.options = {
      storageMethod: 'cookie', // 'cookie', 'localStorage'
      cookieName: 'auth_token',
      refreshCookieName: 'refresh_token',
      localStorageKey: 'auth',
      cookieOptions: {
        path: '/',
        secure: location.protocol === 'https:',
        sameSite: 'Lax'
      },
      ...options
    };
  }

  /**
   * Parse JWT token to extract payload
   * @param {string} token - JWT token
   * @returns {Object|null} Decoded token payload or null if invalid
   */
  parseToken(token) {
    try {
      if (!token || typeof token !== 'string' || !token.includes('.')) {
        return null;
      }

      const parts = token.split('.');
      if (parts.length !== 3) {
        return null;
      }

      const payload = parts[1];
      const decoded = this._base64UrlDecode(payload);

      return JSON.parse(decoded);
    } catch (e) {
      console.error('Error parsing token:', e);
      return null;
    }
  }

  /**
   * Check if token is expired
   * @param {string} token - JWT token
   * @returns {boolean} True if expired, false if valid
   */
  isTokenExpired(token) {
    const payload = this.parseToken(token);
    if (!payload || !payload.exp) {
      return true;
    }

    const now = Math.floor(Date.now() / 1000);
    return now >= payload.exp;
  }

  /**
   * Get token expiration timestamp
   * @param {string} token - JWT token
   * @returns {number|null} Expiration timestamp in seconds or null
   */
  getTokenExpiry(token) {
    const payload = this.parseToken(token);
    return payload?.exp || null;
  }

  /**
   * Extract user ID from token
   * @param {string} token - JWT token
   * @returns {string|number|null} User ID or null
   */
  getUserId(token) {
    const payload = this.parseToken(token);
    // JWT typically uses 'sub' for subject (user ID)
    return payload?.sub || payload?.id || null;
  }

  /**
   * Extract user roles from token
   * @param {string} token - JWT token
   * @returns {Array} Array of roles or empty array
   */
  getUserRoles(token) {
    const payload = this.parseToken(token);
    return payload?.roles || [];
  }

  /**
   * Base64Url decode helper
   * @private
   * @param {string} input - Base64Url encoded string
   * @returns {string} Decoded string
   */
  _base64UrlDecode(input) {
    // Convert base64url to standard base64
    let base64 = input.replace(/-/g, '+').replace(/_/g, '/');

    // Add padding if needed
    while (base64.length % 4) {
      base64 += '=';
    }

    return decodeURIComponent(Array.prototype.map.call(atob(base64), (c) => {
      return '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2);
    }).join(''));
  }

  /**
   * Get token from storage (uses httpOnly cookies by default)
   * @returns {string|null} Token if found, null otherwise
   */
  getToken() {
    // Note: HttpOnly cookies are not accessible via JS
    // This method will return null for httpOnly cookies
    // The server must send the token in a response header for validation

    if (this.options.storageMethod === 'localStorage') {
      try {
        const stored = localStorage.getItem(this.options.localStorageKey);
        if (stored) {
          const data = JSON.parse(stored);
          return data.token || null;
        }
      } catch (e) {
        console.error('Error retrieving token from localStorage:', e);
      }
    }

    return null; // For httpOnly cookies, this will always return null
  }

  /**
   * Store token in the configured storage method
   * @param {string} token - Token to store
   * @param {Object} additionalData - Additional data to store with token (user, etc.)
   * @returns {boolean} True if stored successfully, false otherwise
   */
  store(token, additionalData = {}) {
    try {
      if (this.options.storageMethod === 'localStorage') {
        const tokenData = {
          token: token,
          timestamp: Date.now(),
          ...additionalData
        };
        localStorage.setItem(this.options.localStorageKey, JSON.stringify(tokenData));

        return true;
      } else if (this.options.storageMethod === 'cookie') {
        // Store as cookie (non-httpOnly for JS access)
        const cookieOptions = {
          ...(this.options.cookieOptions || {}),
          ...(additionalData?.cookieOptions || {})
        };
        this.setCookie(this.options.cookieName, token, cookieOptions);
        return true;
      }
    } catch (e) {
      console.error('Error storing token:', e);
    }
    return false;
  }

  /**
   * Set a cookie
   * @param {string} name - Cookie name
   * @param {string} value - Cookie value
   * @param {Object} options - Cookie options
   */
  setCookie(name, value, options = {}) {
    if (!name || typeof name !== 'string') {
      return;
    }

    // Don't encode cookie name or JWT token value (base64url is already safe)
    let cookieString = `${name}=${value == null ? '' : value}`;

    if (options.path) cookieString += `; Path=${options.path}`;
    if (options.secure) cookieString += `; Secure`;
    if (options.sameSite) cookieString += `; SameSite=${options.sameSite}`;
    if (typeof options.maxAge === 'number') cookieString += `; Max-Age=${options.maxAge}`;
    if (options.expires instanceof Date) cookieString += `; Expires=${options.expires.toUTCString()}`;

    document.cookie = cookieString;
  }

  /**
   * Get a cookie value
   * @param {string} name - Cookie name
   * @returns {string|null} Cookie value or null if not found
   */
  getCookie(name) {
    const cookies = document.cookie.split(';');
    for (let cookie of cookies) {
      const [cookieName, cookieValue] = cookie.trim().split('=');
      if (cookieName === name) {
        // Don't decode since we're not encoding JWT tokens anymore
        return cookieValue;
      }
    }

    return null;
  }

  /**
   * Remove token from storage
   * @returns {boolean} True if removed successfully
   */
  remove() {
    try {
      if (this.options.storageMethod === 'localStorage') {
        localStorage.removeItem(this.options.localStorageKey);
        return true;
      } else if (this.options.storageMethod === 'cookie') {
        const clearOptions = {
          ...(this.options.cookieOptions || {}),
          maxAge: 0
        };
        this.setCookie(this.options.cookieName, '', clearOptions);
        return true;
      }
    } catch (e) {
      console.error('Error removing token:', e);
    }
    return false;
  }

  /**
   * Clear all stored authentication data
   * @returns {boolean} True if cleared successfully
   */
  clear() {
    try {
      // Clear from localStorage
      localStorage.removeItem(this.options.localStorageKey);
      localStorage.removeItem('auth_user');
      localStorage.removeItem('access_token');
      localStorage.removeItem('refresh_token');

      // Clear cookies
      const clearOptions = {
        ...(this.options.cookieOptions || {}),
        maxAge: 0
      };

      // Only clear cookies if names are defined
      if (this.options.cookieName) {
        this.setCookie(this.options.cookieName, '', clearOptions);
      }
      if (this.options.refreshCookieName) {
        this.setCookie(this.options.refreshCookieName, '', clearOptions);
      }

      return true;
    } catch (e) {
      console.error('Error clearing authentication data:', e);
    }
    return false;
  }

  /**
   * Get stored user data
   * @returns {Object|null} User data or null if not found
   */
  getUser() {
    try {
      if (this.options.storageMethod === 'localStorage') {
        const stored = localStorage.getItem(this.options.localStorageKey);
        if (stored) {
          const data = JSON.parse(stored);
          return data.user || null;
        }
      }
    } catch (e) {
      console.error('Error retrieving user data:', e);
    }
    return null;
  }

  /**
   * Check if user is authenticated (has valid token)
   * @returns {boolean} True if authenticated
   */
  isAuthenticated() {
    const token = this.getToken();
    if (!token) return false;

    // Check if token is expired
    if (this.isTokenExpired(token)) {
      this.remove(); // Clear expired token
      return false;
    }

    return true;
  }

  /**
   * Get token alias for backward compatibility
   * @returns {string|null} Token if found, null otherwise
   */
  get() {
    return this.getToken();
  }
}

// Export for module use
if (typeof module !== 'undefined' && module.exports) {
  module.exports = TokenService;
}

// Expose globally
window.TokenService = TokenService;

// Create default instance
window.tokenService = new TokenService();
