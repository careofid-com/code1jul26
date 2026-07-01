<?php /* filename: profile.php */
require_once __DIR__ . '/include/security.php';
require_once __DIR__ . '/include/render.php';
require_once __DIR__ . '/include/router.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/providers.php';
require_once __DIR__ . '/include/analytics.php';

coid_session_start();
security_headers();

$coid_raw = isset($_GET['coid']) ? trim((string)$_GET['coid']) : '';
if ($coid_raw === '') {
    require __DIR__ . '/404.php';
    exit;
}

// Use same public lookup used elsewhere (respects masking/deleted users)
$co_row = find_coid_row_public($coid_raw);
if (!$co_row) {
    require __DIR__ . '/404.php';
    exit;
}

// Optionally log profile view here if index.php did not already do it
if (!defined('COID_ANALYTICS_FROM_INDEX')) {
    $isOwner = false;
    if (!empty($_SESSION['user']) && isset($_SESSION['user']['id'])) {
        $isOwner = ((int)$_SESSION['user']['id'] === (int)$co_row['user_id']);
    }

    if (!$isOwner) {
        $source = (isset($_GET['via']) && $_GET['via'] === 'qr')
            ? COID_SOURCE_QR
            : COID_SOURCE_WEB;

        log_coid_event((int)$co_row['id'], null, COID_EVENT_PROFILE_VIEW, $source);
    }
}

// Fetch user name + email for display / claim logic
$st = db()->prepare('SELECT first_name, last_name, email FROM users WHERE id = ? LIMIT 1');
$st->execute(array($co_row['user_id']));
$u = $st->fetch();

$name = '';
$ownerEmailRaw = null;
if ($u) {
    $name = trim($u['first_name'] . ' ' . $u['last_name']);
    $ownerEmailRaw = $u['email'];
}

// Determine if this COID is "unclaimed" (no real email)
$ownerEmail   = is_null($ownerEmailRaw) ? '' : trim((string)$ownerEmailRaw);
$ownerEmailLc = strtolower($ownerEmail);
$isUnclaimed  = ($ownerEmail === '' || $ownerEmailLc === 'null');

page_head('/' . h($co_row['coid']) . ' — careofid');
page_nav();
page_flash();

// Simple inline styles for profile layout
echo '<style>
.profile-wrap { max-width: 560px; margin: 0 auto; }
.profile-card {
  border:1px solid #e6e6e6; border-radius:14px; padding:18px; background:#fff;
  box-shadow:0 2px 14px rgba(0,0,0,0.05); margin-top:12px;
}
.profile-coid { font-size:20px; font-weight:600; margin:0 0 4px; }
.profile-name { font-size:15px; color:#555; margin:0 0 12px; }
.profile-links p { margin:6px 0; }
.qr-box { margin-top:14px; }
.qr-box img { width:140px; height:140px; display:block; }

.claim-box {
  border:1px dashed #ddd;
  padding:10px;
  border-radius:8px;
  background:#fafafa;
  margin-bottom:12px;
  font-size:14px;
}
.claim-box p { margin:4px 0; }

@media (max-width:560px) {
  .profile-coid { font-size:18px; }
  .profile-name { font-size:14px; }
  .claim-box { font-size:13px; }
}
</style>';

echo '<div class="profile-wrap">';
echo '<div class="profile-card">';

echo '<div class="profile-coid">/' . h($co_row['coid']) . '</div>';
if ($name !== '') {
    echo '<div class="profile-name">' . h($name) . '</div>';
}

/* --- Claim box for unclaimed COIDs (no owner email) --- */
if ($isUnclaimed) {
    echo '<div class="claim-box">';
    echo '<p class="muted">This COID is not yet linked to an email account.</p>';
    echo '<p>If this is you, you can request to claim it. An administrator will review your request.</p>';
    echo '<p style="margin-top:8px;">';
    echo '<a class="btn" href="/claim?coid=' . urlencode($co_row['coid']) . '">Claim this COID</a>';
    echo '</p>';
    echo '</div>';
}

// Fetch all handles for this COID
$st2 = db()->prepare('SELECT p.slug, p.display, p.url_pattern, uph.handle
                      FROM user_provider_handles uph
                      JOIN providers p ON p.id = uph.provider_id
                      WHERE uph.coid_id = ?
                      ORDER BY p.display ASC');
$st2->execute(array($co_row['id']));
$handles = $st2->fetchAll();

echo '<div class="profile-links">';
if ($handles) {
    foreach ($handles as $r) {
        // External URL for display only
        if ($r['slug'] === 'website') {
            $finalUrl = preg_match('#^https?://#i', $r['handle'])
                ? $r['handle']
                : 'https://' . $r['handle'];
            $linkText = $r['handle'];
        } else {
            $finalUrl = str_replace('{handle}', $r['handle'], $r['url_pattern']);
            $linkText = $finalUrl;
        }

        $label = $r['display'];
        // Internal redirect path for logging
        $redirPath = '/go.php?coid=' . rawurlencode($co_row['coid'])
                   . '&provider=' . rawurlencode($r['slug']);

        echo '<p>';
        // Button with provider name
        echo '<a class="btn" href="' . h($redirPath) . '" target="_blank" rel="noopener noreferrer">'
           . h($label)
           . '</a> ';
        // Clickable text link with URL/handle (but going via go.php)
        echo '<a href="' . h($redirPath) . '" target="_blank" rel="noopener noreferrer"'
           . ' style="margin-left:8px; color:#0066cc; word-break:break-all;">'
           . h($linkText)
           . '</a>';
        echo '</p>';
    }
} else {
    echo '<p class="muted">No social links yet.</p>';
}
echo '</div>';

// QR for this COID
$qrSrc = '/qr_logo.php?coid=' . h($co_row['coid']);
echo '<div class="qr-box"><img src="' . $qrSrc . '" alt="QR for /' . h($co_row['coid']) . '"></div>';

echo '</div>'; // .profile-card
echo '</div>'; // .profile-wrap

page_foot();
