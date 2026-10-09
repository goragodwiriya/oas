<?php
 /**
 * index.php
 */
    if (!file_exists('settings/config.php')) {
    // install
    header('Location: install/index.php');
    exit;
    }

    /*
     * security headers ของหน้า HTML (API ตั้งของตัวเองใน Kotchasan\Http\Response)
     * หน้านี้เคยไม่ส่งอะไรเลย จึงถูกฝังใน iframe ของเว็บอื่นได้ (clickjacking)
     * ตั้งเท่าที่ไม่กระทบโมดูล: ไม่มี CSP ของ script เพราะโมดูลใช้ inline script/CDN
     * .htaccess ของโปรเจ็คที่ใช้ "Header setifempty" จะไม่ทับค่าเหล่านี้
     */
    header('X-Frame-Options: SAMEORIGIN');
    header("Content-Security-Policy: frame-ancestors 'self'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');

    $cfg = include 'settings/config.php';

    // ค่าเหล่านี้มาจาก config ของ tenant ที่ init() merge ให้แล้ว (ไม่ใช่ค่ากลาง)
    $reversion = rawurlencode((string) $cfg['reversion'] ?? '');
    $webTitle = (string) ($cfg['web_title'] ?? 'Admin System');
    $webDescription = (string) ($cfg['web_description'] ?? 'Admin panel to manage site contents and settings.');

    /*
     * ภาษาของหน้า — ต้องอยู่บน <html lang> ตั้งแต่ HTML ตัวแรกที่ส่งออกไป
     *
     * ⚠️ Now/js/I18nManager.js อ่าน document.documentElement.lang เป็นตัวตัดสินภาษา
     * (loadInitialLocale) ไม่มี lang = ใช้ defaultLocale ของตัวเอง ซึ่งคือ 'en'
     * ผู้เยี่ยมชมที่ยังไม่เคยเลือกภาษา (หน้าร้านออนไลน์ · หน้าล็อกอิน) จึงเห็นหน้าเว็บ
     * เป็นภาษาอังกฤษทั้งหน้า ทั้งที่ไซต์ตั้งเป็นภาษาไทย และคำแปลมีครบอยู่แล้ว
     *
     * ลำดับเดียวกับ Kotchasan\Language::name() : ?lang= → คุกกี้ my_lang → INIT_LANGUAGE
     * → ภาษาแรกที่ติดตั้งไว้ (ที่นี่อ่านจากโฟลเดอร์ language/ ตรง ๆ เพราะ index.php
     * ยังไม่ได้โหลดเฟรมเวิร์กตอนพิมพ์ <html>)
     */
    $installed = [];
    foreach (glob('language/*.php') ?: [] as $file) {
        $name = basename($file, '.php');
        if (preg_match('/^[a-z]{2}$/', $name)) {
            $installed[] = $name;
        }
    }
    $pageLang = '';
    foreach ([$_GET['lang'] ?? '', $_COOKIE['my_lang'] ?? ''] as $candidate) {
        if (is_string($candidate) && preg_match('/^[a-z]{2}$/', $candidate) && in_array($candidate, $installed, true)) {
            $pageLang = $candidate;
            break;
        }
    }
    if ($pageLang === '') {
        // load.php ประกาศ INIT_LANGUAGE ไว้ แต่ยังไม่ถูก include ตอนนี้ — อ่านค่าจากไฟล์
        if (preg_match("/define\\('INIT_LANGUAGE',\\s*'([a-z]+)'\\)/", (string) @file_get_contents('load.php'), $m)
            && $m[1] !== 'auto' && in_array($m[1], $installed, true)) {
            $pageLang = $m[1];
        } else {
            $pageLang = $installed[0] ?? 'th';
        }
    }
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($pageLang, ENT_QUOTES); ?>">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($webTitle, ENT_QUOTES); ?></title>
  <meta name="description" content="<?php echo htmlspecialchars($webDescription, ENT_QUOTES); ?>">

  <!-- Framework CSS -->
  <link rel="stylesheet" href="Now/dist/now.core.min.css?v=<?php echo $reversion; ?>">
  <link rel="stylesheet" href="Now/css/fonts.css?v=<?php echo $reversion; ?>">
  <!-- Custom CSS -->
  <link rel="stylesheet" href="css/styles.css?v=<?php echo $reversion; ?>">

  <!-- PWA -->
  <link rel="manifest" href="manifest.json?v=<?php echo $reversion; ?>">
  <meta name="theme-color" content="#4f46e5">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="default">
  <meta name="apple-mobile-web-app-title" content="<?php echo htmlspecialchars($webTitle, ENT_QUOTES); ?>">
  <link rel="apple-touch-icon" href="images/web-app-manifest-192x192.png">
</head>

<body>
  <main id="main" role="main">
    <!-- Main Content -->
  </main>

  <!-- Framework Scripts -->
  <script src="Now/dist/now.core.min.js?v=<?php echo $reversion; ?>"></script>
  <script src="Now/dist/now.table.min.js?v=<?php echo $reversion; ?>"></script>
  <script src="Now/dist/now.graph.min.js?v=<?php echo $reversion; ?>"></script>
  <!-- ServiceWorkerManager is not part of now.core.min.js, so it loads
       separately and must come before js/main.js, which calls its init() -->
  <script src="Now/dist/now.serviceworker.min.js?v=<?php echo $reversion; ?>"></script>

  <!-- App Scripts -->

  <?php
      $skipModules = ['index'];
      foreach (['modules'] as $dir) {
          $path = __DIR__.'/'.$dir;
          if (is_dir($path)) {
              foreach (scandir($path) as $name) {
            if ($name[0] !== '.' && !in_array($name, $skipModules, true)) {
                if (is_file($path.'/'.$name.'/admin.js')) {
                    echo '<script src="'.$dir.'/'.$name.'/admin.js?v='.$reversion.'"></script>'."\n";
                }
                if (is_file($path.'/'.$name.'/styles.css')) {
                    echo '<link rel="stylesheet" href="'.$dir.'/'.$name.'/styles.css?v='.$reversion.'">'."\n";
                }
                if (is_file('templates/'.$name.'/styles.css')) {
                    echo '<link rel="stylesheet" href="templates/'.$name.'/styles.css?v='.$reversion.'">'."\n";
                }
            }
              }
          }
      }
  ?>

  <!-- App Scripts -->
  <script src="js/main.js?v=<?php echo $reversion; ?>"></script>
  <script src="js/global.js?v=<?php echo $reversion; ?>"></script>
</body>

</html>
