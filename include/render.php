<?php /* filename: include/render.php */
require_once __DIR__ . '/validation.php';

/* Flash messages ---------------------------------------------------------- */
function flash_add($type, $msg) {
    if (!isset($_SESSION['__flash'])) $_SESSION['__flash'] = array();
    $_SESSION['__flash'][] = array('t' => $type, 'm' => $msg);
}

function flash_get_all() {
    $all = isset($_SESSION['__flash']) ? $_SESSION['__flash'] : array();
    unset($_SESSION['__flash']);
    return $all;
}

/* Ads policy helper ------------------------------------------------------- */
/**
 * Return true ONLY on pages where AdSense is allowed:
 * - Public “content” pages (home, public profile pages, about/contact, etc.)
 * Return false on functional/restricted/low-content pages:
 * - login/signup/logout/reset/password/claim/pending/dashboard/admin/actions/qr, etc.
 */
function page_should_show_ads() {
    $uri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '/';
    $path = parse_url($uri, PHP_URL_PATH);
    if ($path === null || $path === '') $path = '/';

    // Normalize: trim trailing slash except root
    if ($path !== '/' && substr($path, -1) === '/') $path = rtrim($path, '/');

    // Block ads on these routes (and their subpaths)
    // Keep this list conservative to satisfy AdSense policy.
    $blocked = array(
        '/login',
        '/signup',
        '/logout',
        '/reset',
        '/forgot',
        '/password',
        '/verify',
        '/pending',
        '/claim',
        '/dashboard',
        '/admin',
        '/actions',
        '/qr',
        '/api',
    );

    foreach ($blocked as $b) {
        if ($path === $b) return false;
        if ($b !== '/' && strpos($path, $b . '/') === 0) return false;
    }

    // Also block common single-file endpoints if any exist
    // (safe extra guard: avoids ads on tool/handler pages)
    if (preg_match('~/(?:login|signup|logout|reset|forgot|password|verify|pending|claim|dashboard|admin|actions|qr)(?:\.php)?$~i', $path)) {
        return false;
    }

    return true;
}

function page_head($title) {
    $t = (string)$title;

    echo '<!doctype html><html lang="en"><head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . h($t) . '</title>';

    // Favicon (generated from /images/logo.png)
    echo '<link rel="icon" href="/images/favicon.ico" sizes="any">';
    echo '<link rel="icon" type="image/png" href="/images/favicon-32.png" sizes="32x32">';

    echo '<link rel="stylesheet" href="/assets/site.css?v=3">';

    // ✅ AdSense: ONLY load on content pages (policy compliance)
    if (page_should_show_ads()) {
        echo '<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-1573535637578488" crossorigin="anonymous"></script>';
    }

    // ✅ Google Ads (AdWords) global site tag: put inside <head>
    // Using same conservative gate so we don't run on login/admin/actions/etc.
    if (page_should_show_ads()) {
        echo '<!-- Google tag (gtag.js) -->';
        echo '<script async src="https://www.googletagmanager.com/gtag/js?id=AW-17856520648"></script>';
        echo '<script>';
        echo '  window.dataLayer = window.dataLayer || [];';
        echo '  function gtag(){dataLayer.push(arguments);}';
        echo '  gtag("js", new Date());';
        echo '  gtag("config", "AW-17856520648");';
        echo '</script>';
    }

    echo '</head><body>';
}

function page_nav() {
    // Session already started by pages that call this
    $isLogged = (!empty($_SESSION['user_id']) || (!empty($_SESSION['user']) && is_array($_SESSION['user'])));

    echo '<header class="brandbar">';
    echo '  <div class="brand-inner">';
    echo '    <img class="brand-logo" src="/images/logo.png" alt="CareOfID logo">';
    echo '    <div class="brand-text">';
    echo '      <div class="brand-title">CareOfID</div>';
    echo '      <div class="brand-tagline">One ID. Every Social Link.</div>';
    echo '    </div>';
    echo '  </div>';

    echo '  <nav class="topnav">';
    echo '    <div class="nav-inner">';
    echo '      <a href="/">Home</a>';
    if ($isLogged) {
        echo '      <a href="/dashboard">Dashboard</a>';
        echo '      <a href="/logout">Logout</a>';
    } else {
        echo '      <a href="/login">Login</a>';
        echo '      <a href="/signup">Sign up</a>';
    }
    echo '      <a href="/about">About</a>';
    echo '      <a href="/contact">Contact</a>';
    echo '    </div>';
    echo '  </nav>';
    echo '</header>';
}

function page_flash() {
    $msgs = flash_get_all();
    if (!$msgs) return;
    foreach ($msgs as $m) {
        $t = $m['t'] ?? 'ok';
        $msg = $m['m'] ?? '';
        $cls = ($t === 'err') ? 'err' : 'ok';
        echo '<div class="flash ' . h($cls) . '">' . h($msg) . '</div>';
    }
}

function page_foot() {
    $year = date('Y');
    echo '<footer class="site-footer">&copy; ' . h($year) . ' CareOfID. All rights reserved.</footer>';
    echo '</body></html>';
}
