/**
 * RichTextEditor - Config Profiles
 * Pre-defined configurations for common use cases
 *
 * @author Goragod Wiriya
 * @version 1.0
 */

/**
 * Full profile - All features enabled
 */
export const full = {
  name: 'full',
  plugins: [
    'link', 'image', 'galleryUpload', 'table', 'video', 'iframe',
    'emoji', 'specialChars',
    'sourceView', 'fullscreen', 'findReplace',
    'autosave', 'wordcount', 'maxLength', 'mention',
    'pasteCleaner', 'print', 'aiWriter', 'contentCleanup'
  ],
  toolbar: [
    ['sourceView', 'fullscreen'],
    ['aiGenerate', 'aiRewrite', 'aiImage', 'cleanContent'],
    ['bold', 'italic', 'underline', 'strikethrough', 'superscript', 'subscript'],
    ['heading'],
    ['bulletList', 'numberedList'],
    ['alignLeft', 'alignCenter', 'alignRight', 'alignJustify'],
    ['indent', 'outdent'],
    ['link', 'image', 'galleryUpload', 'video', 'iframe', 'table'],
    ['blockquote', 'codeBlock', 'horizontalRule'],
    ['textColor', 'backgroundColor'],
    ['emoji', 'specialChars'],
    ['dirLtr', 'dirRtl'],
    ['undo', 'redo'],
    ['removeFormat', 'pasteCleaner', 'findReplace', 'print']
  ],
  options: {
    minHeight: 300,
    allowInteractiveTags: '',
    allowStyle: true,
    allowScript: true,
    allowIframe: true,
    autosave: {
      interval: 30000,
      useLocalStorage: true
    },
    maxLength: {
      maxLength: 50000,
      showCounter: true
    },
    wordcount: {
      showWords: true,
      showCharacters: true
    }
  }
};

/**
 * Basic profile - Common features for general use
 */
export const basic = {
  name: 'basic',
  plugins: [
    'link', 'image', 'table', 'video', 'iframe', 'galleryUpload',
    'sourceView', 'fullscreen', 'wordcount', 'pasteCleaner', 'aiWriter', 'contentCleanup'
  ],
  toolbar: [
    ['sourceView', 'fullscreen'],
    ['aiGenerate', 'aiRewrite', 'aiImage', 'cleanContent'],
    ['bold', 'italic', 'underline'],
    ['heading'],
    ['bulletList', 'numberedList'],
    ['alignLeft', 'alignCenter', 'alignRight'],
    ['link', 'image', 'galleryUpload', 'video', 'iframe', 'table'],
    ['blockquote', 'codeBlock', 'horizontalRule'],
    ['textColor', 'backgroundColor'],
    ['undo', 'redo'],
    ['removeFormat', 'pasteCleaner']
  ],
  options: {
    minHeight: 250,
    allowInteractiveTags: '',
    allowStyle: true,
    allowScript: true,
    allowIframe: true,
    wordcount: {
      showWords: true,
      showCharacters: false
    }
  }
};

/**
 * Minimal profile - Text formatting only
 */
export const minimal = {
  name: 'minimal',
  plugins: ['wordcount', 'pasteCleaner'],
  toolbar: [
    ['bold', 'italic', 'underline'],
    ['bulletList', 'numberedList'],
    ['undo', 'redo'],
    ['removeFormat', 'pasteCleaner']
  ],
  options: {
    minHeight: 150,
    wordcount: {
      showWords: true,
      showCharacters: false
    }
  }
};

/**
 * Comment profile - For short comments/replies
 */
export const comment = {
  name: 'comment',
  plugins: ['link', 'emoji'],
  toolbar: [
    ['bold', 'italic'],
    ['link', 'emoji'],
    ['undo', 'redo']
  ],
  options: {
    minHeight: 100,
    maxLength: {
      maxLength: 2000,
      showCounter: true
    }
  }
};

/**
 * Email profile - For composing emails
 */
export const email = {
  name: 'email',
  plugins: ['link', 'image', 'autosave'],
  toolbar: [
    ['bold', 'italic', 'underline'],
    ['bulletList', 'numberedList'],
    ['alignLeft', 'alignCenter', 'alignRight'],
    ['link', 'image'],
    ['textColor'],
    ['undo', 'redo']
  ],
  options: {
    minHeight: 300,
    autosave: {
      interval: 10000,
      useLocalStorage: true
    }
  }
};

/**
 * All available profiles
 */
export const profiles = {
  full,
  basic,
  minimal,
  comment,
  email
};

export default profiles;
