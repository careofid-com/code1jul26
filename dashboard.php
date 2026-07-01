<?php /* filename: dashboard.php */
require_once __DIR__ . '/include/security.php';
require_once __DIR__ . '/include/render.php';
require_once __DIR__ . '/include/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/providers.php';
require_once __DIR__ . '/include/validation.php';
require_once __DIR__ . '/include/analytics.php';

coid_session_start();
security_headers();
auth_require_login();
$user = auth_reload_user();


// If user came here after starting a COID claim, send them back to the claim page
if (!empty($_SESSION['pending_claim_slug']) && !empty($user) && (int)$user['is_verified'] === 1) {
    $slug = $_SESSION['pending_claim_slug'];
    unset($_SESSION['pending_claim_slug'], $_SESSION['pending_claim_coid']);
    header('Location: /claim?coid=' . rawurlencode($slug));
    exit;
}

// Load user COID if any
$st = db()->prepare('SELECT * FROM coids WHERE user_id = ? LIMIT 1');
$st->execute(array($user['id']));
$co = $st->fetch();

page_head('Dashboard — careofid');
page_nav();
page_flash();

/* Dashboard-specific compact spacing + mobile font tweaks + QR overlay */
echo '<style>
  /* Reduce vertical gaps inside dashboard cards */
  .card h2 { margin-top: 0; margin-bottom: 6px; }
  .card p { margin: 3px 0; }

  .coid-row {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 4px 0 6px;
  }

  .qr-box { margin: 8px 0; }

  /* Make handle rows tighter */
  .card .link-row { margin: 3px 0; }

  /* Top links under Dashboard */
  .dash-subnav a { text-decoration: none; }
  .dash-subnav a:hover { text-decoration: underline; }

  /* Full-screen QR overlay (mobile-only trigger) */
  .qr-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.8);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 1000;
  }
  .qr-overlay-inner {
    position: relative;
    max-width: 90vw;
    max-height: 90vh;
    background: #fff;
    border-radius: 8px;
    padding: 8px;
    box-shadow: 0 4px 18px rgba(0,0,0,0.4);
  }
  .qr-overlay-inner img {
    display: block;
    width: 100%;
    height: auto;
  }
  .qr-overlay-close {
    position: absolute;
    top: 4px;
    right: 8px;
    text-decoration: none;
    font-size: 22px;
    line-height: 1;
    color: #333;
  }

  /* Mobile: shrink fonts further and enable overlay via :target */
  @media (max-width: 560px) {
    h1 { font-size: 18px !important; line-height: 1.25; }
    h2 { font-size: 15px !important; line-height: 1.25; }

    .card p,
    .card label,
    .muted,
    .dash-subnav,
    .dash-subnav a {
      font-size: 12px !important;
    }

    .btn, button {
      font-size: 12px !important;
      padding: 7px 10px !important;
    }

    input[type="text"],
    input[type="email"],
    input[type="password"],
    select,
    textarea {
      font-size: 12px !important;
      padding: 7px !important;
    }

    /* Only on phone: clicking QR (anchor to #qr-full) shows overlay */
    #qr-full:target {
      display: flex;
    }
  }
</style>';

echo '<h1>Dashboard</h1>';

/* Top text links under Dashboard */
echo '<div class="centercol">';
echo '<p class="muted dash-subnav" style="margin-top:4px;margin-bottom:12px;">';
echo '<a href="/handles">Update Handles</a> · ';
echo '<a href="/account">Account</a> · ';
echo '<a href="/activity">Activities</a>';
echo '</p>';
echo '</div>';

if (!$user['is_verified']) {
  // Email not verified → do not allow COID creation yet
  echo '<div class="card" style="background:#fff3cd;border-color:#ffecb5;">
          <h2>Verify your email</h2>
          <p>Your account email must be verified before creating your COID.</p>
          <p><strong>Email:</strong> ' . h($user['email']) . '</p>
          <p><a class="btn" href="/verify?email=' . h($user['email']) . '">Verify Now</a></p>
        </div>';

  page_foot();
  exit;
}

// At this point, user is verified:
if (!$co) {
  // Show COID creation form
  ?>
  <div class="card">
    <h2>Create your COID</h2>
    <p>Must start with a letter; allowed letters, digits, dot, underscore, hyphen. 3–32 chars. Case-preserving.</p>
    <form method="post" action="/actions/coid_create_post.php">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
      <label>COID</label>
      <input type="text" name="coid" placeholder="e.g., Saeed.123" required minlength="3" maxlength="32">
      <div class="row" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <button type="submit">Save COID</button>
      </div>
    </form>

    <hr style="margin:14px 0;">

    <h2 style="margin-top:0;">Don’t want to continue?</h2>
    <p class="muted">If you don’t create a COID, your verified account will be automatically removed after 24 hours.</p>
    <form method="post" action="/actions/account_delete_post.php">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
      <label style="display:flex;gap:8px;align-items:flex-start;">
        <input type="checkbox" name="confirm" value="1" required style="margin-top:3px;">
        <span>I understand this will permanently delete my account and cannot be undone.</span>
      </label>
      <div class="row" style="margin-top:10px;">
        <button type="submit" class="danger">Delete Account</button>
      </div>
    </form>
  </div>
  <?php
} else {
  // Existing COID → show summary, QR, sharing, and links
  $coidStr = $co['coid'];
  $coidUrl = 'https://careofid.com/' . $coidStr;

  echo '<div class="card">';
  echo '<h2>Your COID</h2>';

  // COID label + button on the same row
  echo '<div class="coid-row">';
  echo '<span>Your COID:</span>';
  echo '<a class="btn" href="/' . h($coidStr) . '">/' . h($coidStr) . '</a>';
  echo '</div>';

  // QR code for this COID, using qr_logo.php
  $qrSrc = '/qr_logo.php?coid=' . h($coidStr);

  // On phone, clicking this image (anchor) targets #qr-full → overlay
  echo '<div class="qr-box">';
  echo '<a href="#qr-full"><img src="' . $qrSrc . '" width="140" height="140" alt="QR for /' . h($coidStr) . '"></a>';
  echo '</div>';

  // Downloadable QR (PNG)
  echo '<p>'
     . '<a href="' . $qrSrc . '" download="careofid-' . h($coidStr) . '-qr.png">'
     . 'Download QR (PNG)'
     . '</a>'
     . '</p>';

  // Simple share tools (CSP-safe, no JS)
  $shareUrl = $coidUrl;
  $wa = 'https://wa.me/?text=' . rawurlencode('My COID: ' . $shareUrl);
  $tw = 'https://twitter.com/intent/tweet?text=' . rawurlencode('My COID: ' . $shareUrl);

  echo '<p style="margin-top:8px;">';
  echo '<label style="display:block;margin-bottom:3px;">Your COID link</label>';
  echo '<input type="text" readonly value="' . h($shareUrl) . '" style="width:100%;max-width:360px;">';
  echo '<span class="muted" style="display:block;margin-top:3px;">Select and copy this link to share.</span>';
  echo '</p>';

  echo '<p style="margin-top:6px;">';
  echo '<a class="btn" href="' . h($wa) . '" target="_blank" rel="noopener noreferrer" style="margin-right:6px;">Share on WhatsApp</a>';
  echo '<a class="btn" href="' . h($tw) . '" target="_blank" rel="noopener noreferrer">Share on X</a>';
  echo '</p>';

  echo '</div>';

  // Full-screen QR overlay (mobile-only via media query)
  echo '<div id="qr-full" class="qr-overlay">';
  echo '  <div class="qr-overlay-inner">';
  echo '    <a href="#" class="qr-overlay-close">×</a>';
  echo '    <img src="' . $qrSrc . '" alt="QR for /' . h($coidStr) . ' (large)">';
  echo '  </div>';
  echo '</div>';

  // Fetch non-empty handles
  $st = db()->prepare('SELECT p.display, p.slug, p.url_pattern, uph.handle
                       FROM user_provider_handles uph
                       JOIN providers p ON p.id = uph.provider_id
                       WHERE uph.coid_id = ?
                       ORDER BY p.display ASC');
  $st->execute(array($co['id']));
  $rows = $st->fetchAll();

  echo '<div class="card"><h2>Your links</h2>';
  if (!$rows) {
    echo '<p>No social links yet.</p>';
  } else {
    foreach ($rows as $r) {
      // External URL for display only
      if ($r['slug'] === 'website') {
        $finalUrl = preg_match('#^https?://#i', $r['handle'])
                     ? $r['handle']
                     : 'https://' . $r['handle'];
        $linkText = $r['handle'];  // show chay.com as text
      } else {
        $finalUrl = str_replace('{handle}', $r['handle'], $r['url_pattern']);
        $linkText = $finalUrl;     // show full URL as text
      }

      $label = $r['display']; // e.g. "Facebook", "Website"
      // Redirect path that triggers logging via go.php
      $redirPath = '/go.php?coid=' . rawurlencode($co['coid'])
                 . '&provider=' . rawurlencode($r['slug']);

      echo '<p class="link-row">';

      // Button: provider name → go.php
      echo '<a class="btn" href="' . h($redirPath) . '" target="_blank" rel="noopener noreferrer">'
         . h($label)
         . '</a> ';

      // Text link: go.php, but shows final URL/handle
      echo '<a href="' . h($redirPath) . '" target="_blank" rel="noopener noreferrer"'
         . ' style="margin-left:6px; color:#0066cc; word-break:break-all;">'
         . h($linkText)
         . '</a>';

      echo '</p>';
    }
  }
  echo '</div>';
}

page_foot();
