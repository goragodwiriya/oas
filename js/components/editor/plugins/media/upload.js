/**
 * Upload helpers shared by the media plugins
 *
 * @author Goragod Wiriya
 * @version 1.0
 */

/**
 * FileBrowser settings for a plugin: its own `fileBrowser` option, otherwise
 * the one configured for images (integrations such as RichTextElementFactory
 * only set `image.fileBrowser`).
 * @param {RichTextEditor} editor
 * @param {Object} options - Plugin options
 * @returns {Object|null}
 */
export function getFileBrowserConfig(editor, options = {}) {
  return options.fileBrowser || editor?.options?.image?.fileBrowser || null;
}

/**
 * Upload endpoint for a plugin: the FileBrowser upload action, or `uploadUrl`.
 * @param {RichTextEditor} editor
 * @param {Object} options - Plugin options
 * @returns {string|null}
 */
export function getUploadUrl(editor, options = {}) {
  return getFileBrowserConfig(editor, options)?.options?.apiActions?.upload
    || options.uploadUrl
    || editor?.options?.image?.uploadUrl
    || null;
}

/**
 * Upload a file to the FileBrowser upload endpoint.
 * The auth_token cookie is sent with the request (credentials: 'include'),
 * the same mechanism as every other API call of the application.
 * @param {File} file
 * @param {string} uploadUrl
 * @param {string} uploadPath - Target folder inside the upload root
 * @param {string|null} storage - FileBrowser storage (e.g. 'file'); null = the server's default
 * @returns {Promise<{url: string, name: string}>} Public URL and stored name
 */
export async function uploadFile(file, uploadUrl, uploadPath = '/', storage = null) {
  if (!uploadUrl) {
    throw new Error('Upload URL not configured');
  }

  const formData = new FormData();
  formData.append('file', file);
  formData.append('path', uploadPath || '/');
  if (storage) {
    formData.append('storage', storage);
  }

  let requestOptions = {
    method: 'POST',
    body: formData,
    // Custom header enables server-side CSRF validation for cookie auth
    headers: {'X-Requested-With': 'XMLHttpRequest'},
    credentials: 'include'
  };
  if (typeof window.Now?.applyRequestLanguage === 'function') {
    requestOptions = window.Now.applyRequestLanguage(requestOptions);
  }

  const response = await fetch(uploadUrl, requestOptions);

  let result = null;
  try {
    result = await response.json();
  } catch (e) {
    result = null;
  }

  if (!response.ok || !result?.success) {
    throw new Error(result?.error || result?.message || `HTTP ${response.status}`);
  }

  const url = result.file?.url || result.data?.url || null;
  if (!url) {
    throw new Error('Server did not return file URL');
  }

  return {url, name: result.file?.name || result.data?.name || file.name};
}
