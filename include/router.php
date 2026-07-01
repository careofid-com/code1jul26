<?php /* filename: include/router.php */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/providers.php';

/**
 * Parse request URI into path segments.
 * Returns array like: ['saeed.123'] or ['saeed.123','facebook'] or ['login']
 */
function route_segments() {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $path = trim($path, '/');
    if ($path === '') return array();
    $parts = explode('/', $path);
    return $parts;
}

/**
 * Reserved first segments that map to first-class pages.
 * NOTE: do NOT include 'index.php' or '404.php' here.
 */
function is_reserved_route($seg0) {
    static $reserved = array(
        'login',
        'logout',
        'signup',
        'verify',
        'dashboard',
        'account',
        'handles',
        'about',
        'contact',
        'actions',
        'reset_password',
        'privacy',
        'activity',  // activity page
        'claim'      // NEW: claim-coid page
    );
    return in_array(strtolower($seg0), $reserved, true);
}


/** Lookup COID (case-insensitive by using coid_lc) */
function find_coid_row($coid_raw) {
    $norm = coid_lc($coid_raw);

    // First try normalized column
    $st = db()->prepare('SELECT * FROM coids WHERE coid_lc = ? LIMIT 1');
    $st->execute(array($norm));
    $row = $st->fetch();
    if ($row) return $row;

    // Fallbacks (for legacy rows missing coid_lc)
    // 1) Exact match on coid
    $st = db()->prepare('SELECT * FROM coids WHERE coid = ? LIMIT 1');
    $st->execute(array($coid_raw));
    $row = $st->fetch();
    if ($row) return $row;

    // 2) Case-insensitive match on coid
    $st = db()->prepare('SELECT * FROM coids WHERE LOWER(coid) = ? LIMIT 1');
    $st->execute(array($norm));
    $row = $st->fetch();
    if ($row) return $row;

    return null;
}

/** Get handle for coid_id + provider slug; returns array(provider_row, handle) or null */
function find_handle_for($coid_id, $provider_slug) {
    $provider = provider_by_slug($provider_slug);
    if (!$provider) return null;
    $st = db()->prepare('SELECT * FROM user_provider_handles WHERE coid_id = ? AND provider_id = ? LIMIT 1');
    $st->execute(array($coid_id, $provider['id']));
    $uph = $st->fetch();
    if (!$uph) return null;
    return array($provider, $uph['handle']);
}
