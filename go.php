<?php /* filename: go.php */
require_once __DIR__ . '/include/security.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/providers.php';
require_once __DIR__ . '/include/router.php';
require_once __DIR__ . '/include/analytics.php';

coid_session_start();
security_headers();

$coid_raw = isset($_GET['coid']) ? trim((string)$_GET['coid']) : '';
$prov_raw = isset($_GET['provider']) ? trim((string)$_GET['provider']) : '';

if ($coid_raw === '' || $prov_raw === '') {
    header('Location: /', true, 302);
    exit;
}

// Find COID row with public rules (not masked, user not deleted)
$co_row = find_coid_row_public($coid_raw);
if (!$co_row) {
    require __DIR__ . '/404.php';
    exit;
}

// Find provider row by slug
$prov = provider_by_slug($prov_raw);
if (!$prov) {
    require __DIR__ . '/404.php';
    exit;
}

$coid_id     = (int)$co_row['id'];
$provider_id = (int)$prov['id'];

// Find handle for this coid+provider
$st = db()->prepare('SELECT handle FROM user_provider_handles WHERE coid_id = ? AND provider_id = ? LIMIT 1');
$st->execute(array($coid_id, $provider_id));
$hrow = $st->fetch();

if (!$hrow || trim($hrow['handle']) === '') {
    require __DIR__ . '/404.php';
    exit;
}

$handle = trim($hrow['handle']);

// Build final external URL
if ($prov['slug'] === 'website') {
    $redir = preg_match('#^https?://#i', $handle) ? $handle : ('https://' . $handle);
} else {
    $redir = str_replace('{handle}', $handle, $prov['url_pattern']);
}

// Determine source (web / qr / share)
$src = isset($_GET['src']) ? (string)$_GET['src'] : '';
$sourceConst = COID_SOURCE_WEB;
if ($src === 'qr') {
    $sourceConst = COID_SOURCE_QR;
} elseif ($src === 'share') {
    $sourceConst = COID_SOURCE_SHARE;
}

// Log the forward event
log_coid_event($coid_id, $provider_id, COID_EVENT_FORWARD, $sourceConst);

// Redirect to final URL
header('Location: ' . $redir, true, 302);
exit;
