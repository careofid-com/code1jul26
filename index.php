<?php /* filename: index.php */
require_once __DIR__ . '/include/security.php';
require_once __DIR__ . '/include/render.php';
require_once __DIR__ . '/include/router.php';
require_once __DIR__ . '/include/auth.php';
require_once __DIR__ . '/include/validation.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/providers.php';
require_once __DIR__ . '/include/analytics.php';

coid_session_start();
security_headers();

$segs = route_segments();

/* Normalize /index.php to / so /index.php?q=... hits home logic */
if (count($segs) === 1 && strtolower($segs[0]) === 'index.php') {
  $segs = array();
}


/* Direct route for claim page: /claim or /claim.php */
if (count($segs) === 1) {
  $first = strtolower($segs[0]);
  if ($first === 'claim' || $first === 'claim.php') {
    require __DIR__ . '/claim.php';
    exit;
  }
}


/* Treat URL-looking path (e.g., /facebook.com/handle) as a home search query,
   BUT do not treat /{coid}/{provider} as a URL search if provider slug exists. */
function looks_like_url_path($s) {
  if (!is_string($s) || $s === '') return false;
  if (preg_match('#^[a-z][a-z0-9+\-.]*://#i', $s)) return true;
  return (strpos($s, '.') !== false) ||
         preg_match('#^(www\.)?(facebook|instagram|linkedin|twitter|x|youtube|tiktok|github)\.com#i', $s);
}

$q_override = null;
if (!empty($segs)) {
  $maybe_url = implode('/', $segs);
  $treatAsUrl = looks_like_url_path($maybe_url);

  if ($treatAsUrl && count($segs) >= 2) {
    $maybeProv = provider_by_slug($segs[1]);
    if ($maybeProv) {
      $treatAsUrl = false;
    }
  }

  if ($treatAsUrl) {
    $q_override = $maybe_url;
    $segs = array(); // force home route
  }
}

/* Reserved routes → file include */
if (count($segs) > 0 && is_reserved_route($segs[0])) {
  $first = strtolower($segs[0]);
  $path = __DIR__ . '/' . $first . '.php';
  if (is_file($path)) { require $path; exit; }
  require __DIR__ . '/404.php'; exit;
}

/* Forwarding: /{coid}/{provider} */
if (count($segs) === 2) {
  $coid_raw = $segs[0];
  $prov_raw = $segs[1];

  $co_row = find_coid_row_public($coid_raw);
  if ($co_row) {
    $prov = provider_by_slug($prov_raw);
    if ($prov) {
      $coid_id     = (int)$co_row['id'];
      $provider_id = (int)$prov['id'];

      $stF = db()->prepare('SELECT handle FROM user_provider_handles
                            WHERE coid_id = ? AND provider_id = ? LIMIT 1');
      $stF->execute(array($coid_id, $provider_id));
      $hrow = $stF->fetch();

      if ($hrow && trim($hrow['handle']) !== '') {
        $handle = trim($hrow['handle']);

        if ($prov['slug'] === 'website') {
          $redir = preg_match('#^https?://#i', $handle) ? $handle : ('https://' . $handle);
        } else {
          $redir = str_replace('{handle}', $handle, $prov['url_pattern']);
        }

        $source = (isset($_GET['via']) && $_GET['via'] === 'qr')
          ? COID_SOURCE_QR
          : COID_SOURCE_WEB;

        log_coid_event($coid_id, $provider_id, COID_EVENT_FORWARD, $source);

        header('Location: ' . $redir, true, 302);
        exit;
      }
    }
  }

  require __DIR__ . '/404.php';
  exit;
}

/* Profile: /{coid} */
if (count($segs) === 1 && $segs[0] !== '') {
  $coid_raw = $segs[0];

  $co_row = find_coid_row_public($coid_raw);

  if ($co_row) {
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

  $_GET['coid'] = $coid_raw;
  require __DIR__ . '/profile.php';
  exit;
}

/* ---------------- Home (/) ---------------- */
page_head('careofid — COID lookup & forwarding');
page_nav();
page_flash();

/* Styles */
echo '<style>
  .centercol { max-width: 640px; margin: 0 auto; }
  .hero-box { border:1px solid #e6e6e6; border-radius:14px; padding:18px; background:#fff;
              box-shadow:0 2px 14px rgba(0,0,0,0.05); margin-top:8px; }
  .hero-title { font-size: clamp(22px, 4.5vw, 28px); margin: 4px 0 2px; }
  .hero-sub { font-size: clamp(13px, 2.6vw, 15px); color:#666; margin: 6px 0 14px; }
  .stack { display:flex; gap:10px; flex-wrap:nowrap; }
  @media (max-width:560px){ .stack{flex-wrap:wrap;} }
  .results { margin-top:14px; }
  .result-item { border:1px solid #eee; border-radius:10px; padding:10px 12px; margin:8px 0; background:#fafafa; }
  .rowline { display:flex; align-items:center; justify-content:space-between; gap:8px; }
  .muted { color:#666; font-size:14px; }
  .coid-line { font-weight:600; font-size:16px; }
  .qr-box { margin-top:8px; }
  .qr-box img { width:140px; height:140px; display:block; }
  .result-item p { margin:3px 0; }

  .result-multi-line { display:flex; align-items:center; gap:8px; }

  .result-multi-line img.dp {
    width:40px;
    height:40px;
    border-radius:50%;
    object-fit:cover;
    border:1px solid #ddd;
  }

  /* QR overlay */
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

  /* NEW: DP overlay (for enlarged profile picture on mobile) */
  .dp-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.8);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 1000;
  }
  .dp-overlay-inner {
    position: relative;
    max-width: 80vw;
    max-height: 80vw;
    background: transparent;
    border-radius: 50%;
    padding: 0;
  }
  .dp-overlay-inner img {
    display: block;
    width: 80vw;
    height: 80vw;
    max-width: 320px;
    max-height: 320px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #fff;
  }
  .dp-overlay-close {
    position: absolute;
    top: -10px;
    right: -10px;
    text-decoration: none;
    font-size: 26px;
    line-height: 1;
    color: #fff;
    background: rgba(0,0,0,0.6);
    border-radius: 50%;
    padding: 4px 8px;
  }

  @media (max-width: 560px) {
    h1 { font-size: 18px !important; line-height: 1.25; }
    h2 { font-size: 15px !important; line-height: 1.25; }
    p, label, .muted, .hero-sub, .coid-line { font-size: 12px !important; }
    .btn, button { font-size: 12px !important; padding: 7px 10px !important; }
    input[type="text"], input[type="email"], input[type="password"], select, textarea {
      font-size: 12px !important; padding: 7px !important;
    }
    .result-item { font-size: 12px !important; }
    .hero-title { font-size: 20px !important; }

    .qr-overlay:target {
      display: flex;
    }
    /* Only on phone: tapping DP shows overlay */
    .dp-overlay:target {
      display: flex;
    }
  }
</style>';

$q_val = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
if ($q_override !== null) $q_val = $q_override;

$isLogged = !empty($_SESSION['user']);

/* URL helpers */
function looks_like_url($s) {
  if (!is_string($s) || $s === '') return false;
  if (preg_match('#^[a-z][a-z0-9+\-.]*://#i', $s)) return true;
  return (strpos($s, 'facebook.com') !== false ||
          strpos($s, 'linkedin.com') !== false ||
          strpos($s, 'instagram.com') !== false ||
          strpos($s, 'twitter.com') !== false ||
          strpos($s, 'x.com') !== false ||
          strpos($s, 'youtube.com') !== false ||
          strpos($s, 'tiktok.com') !== false ||
          strpos($s, 'github.com') !== false ||
          preg_match('/^[\w.-]+\.[a-z]{2,}([\/?#].*)?$/i', $s));
}
function norm_url_for_compare($url) {
  $u = $url;
  if (!preg_match('#^[a-z][a-z0-9+\-.]*://#i', $u)) $u = 'https://' . $u;
  $u = preg_replace('#/+$#', '', $u);
  $parts = @parse_url($u);
  if ($parts && isset($parts['host'])) {
    $host = strtolower($parts['host']);
    $scheme = isset($parts['scheme']) ? $parts['scheme'] : 'https';
    $path = isset($parts['path']) ? rtrim($parts['path'], '/') : '';
    $query = isset($parts['query']) ? ('?'.$parts['query']) : '';
    $frag  = isset($parts['fragment']) ? ('#'.$parts['fragment']) : '';
    return $scheme . '://' . $host . $path . $query . $frag;
  }
  return $u;
}
function parse_social_url($input) {
  $raw = $input;
  if (!preg_match('#^[a-z][a-z0-9+\-.]*://#i', $raw)) $raw = 'https://' . $raw;
  $parts = @parse_url($raw);
  if (!$parts || !isset($parts['host'])) return array('website_url' => norm_url_for_compare($input));

  $host = strtolower($parts['host']);
  $path = isset($parts['path']) ? trim($parts['path'], '/') : '';
  if ($host === 'www.facebook.com' || $host === 'm.facebook.com') $host = 'facebook.com';
  if ($host === 'www.instagram.com') $host = 'instagram.com';
  if ($host === 'www.linkedin.com')  $host = 'linkedin.com';
  if ($host === 'twitter.com' || $host === 'www.twitter.com' || $host === 'www.x.com') $host = 'x.com';
  if ($host === 'www.youtube.com') $host = 'youtube.com';
  if ($host === 'www.tiktok.com')  $host = 'tiktok.com';
  if ($host === 'www.github.com')  $host = 'github.com';

  if ($host === 'facebook.com') {
    $seg = explode('/', $path);
    if (!empty($seg[0]) && $seg[0] !== 'profile.php') return array('provider' => 'facebook', 'handle' => $seg[0]);
  } elseif ($host === 'instagram.com') {
    $seg = explode('/', $path);
    if (!empty($seg[0])) return array('provider' => 'instagram', 'handle' => $seg[0]);
  } elseif ($host === 'linkedin.com') {
    if (strpos($path, 'in/') === 0) {
      $seg = explode('/', $path);
      if (isset($seg[1]) && $seg[1] !== '') return array('provider' => 'linkedin', 'handle' => $seg[1]);
    }
  } elseif ($host === 'x.com') {
    $seg = explode('/', $path);
    if (!empty($seg[0])) return array('provider' => 'x', 'handle' => $seg[0]);
  } elseif ($host === 'youtube.com') {
    if (preg_match('#^@([A-Za-z0-9._-]+)$#', $path, $m)) return array('provider' => 'youtube', 'handle' => '@'.$m[1]);
  } elseif ($host === 'tiktok.com') {
    if (preg_match('#^@([A-Za-z0-9._-]+)$#', $path, $m)) return array('provider' => 'tiktok', 'handle' => '@'.$m[1]);
  } elseif ($host === 'github.com') {
    $seg = explode('/', $path);
    if (!empty($seg[0])) return array('provider' => 'github', 'handle' => $seg[0]);
  }
  return array('website_url' => norm_url_for_compare($input));
}

/* Render full COID result (1–2 matches), now with DP overlay support */
function render_coid_with_all_handles($coid_id, $coid, $name, $isLogged, $dp_path = null, $owner_email = null) {
  echo '<div class="result-item">';

  echo '<div class="rowline">';
  echo '<div style="display:flex;align-items:center;gap:8px;">';

  if (!empty($dp_path)) {
    $dpId = 'dp-full-' . $coid;
    echo '<a href="#' . h($dpId) . '">';
    echo '<img src="' . h($dp_path) . '" alt="Profile picture"'
       . ' style="width:40px;height:40px;border-radius:50%;object-fit:cover;border:1px solid #ddd;">';
    echo '</a>';

    // DP overlay (large) – only visible on mobile via CSS :target
    echo '<div id="' . h($dpId) . '" class="dp-overlay">';
    echo '  <div class="dp-overlay-inner">';
    echo '    <a href="#" class="dp-overlay-close">×</a>';
    echo '    <img src="' . h($dp_path) . '" alt="Profile picture (large)">';
    echo '  </div>';
    echo '</div>';
  }

  if ($isLogged) {
    echo '<span class="muted">COID:</span> ';
    echo '<a class="btn" href="/' . h($coid) . '">' . h($coid) . '</a>';


    echo '<span class="muted">' . h($name) . '</span>';
  } else {
    echo '<span class="coid-line">';
echo '<span class="muted">COID:</span> ';
echo '<span class="btn" style="cursor:default;">' . h($coid) . '</span> ';
echo '<span class="muted">(' . h($name) . ')</span>';
echo '</span>';


  }

  
  // Claim button (for placeholder/unclaimed COIDs)
  $em = is_null($owner_email) ? '' : trim((string)$owner_email);
  $emLc = strtolower($em);
  $isUnclaimed = ($em === '' || $emLc === 'null');
  if ($isUnclaimed) {
    echo '<div style="margin-top:6px;">';
    echo '<a class="btn" href="/claim?coid=' . urlencode($coid) . '">Claim this COID</a>';
    echo '<span class="muted" style="margin-left:8px;">(admin review required)</span>';
    echo '</div>';
  }

echo '</div>';
  echo '</div>';

  // Handles
  $st2 = db()->prepare('SELECT p.slug, p.display, p.url_pattern, uph.handle
                        FROM user_provider_handles uph
                        JOIN providers p ON p.id = uph.provider_id
                        WHERE uph.coid_id = ?
                        ORDER BY p.display ASC');
  $st2->execute(array($coid_id));
  $handles = $st2->fetchAll();

  if ($handles) {
    foreach ($handles as $r) {
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
      $redirPath = '/go.php?coid=' . rawurlencode($coid)
                 . '&provider=' . rawurlencode($r['slug']);

      echo '<p style="margin:3px 0;">';
      echo '<a class="btn" href="' . h($redirPath) . '" target="_blank" rel="noopener noreferrer">'
         . h($label)
         . '</a> ';
      echo '<a href="' . h($redirPath) . '" target="_blank" rel="noopener noreferrer"'
         . ' style="margin-left:6px; color:#0066cc; word-break:break-all;">'
         . h($linkText)
         . '</a>';
      echo '</p>';
    }
  } else {
    echo '<p class="muted" style="margin:3px 0;">No social links yet.</p>';
  }

  // QR code & overlay
  $qrSrc = '/qr_logo.php?coid=' . h($coid);
  $overlayId = 'qr-full-' . $coid;

  echo '<div class="qr-box">';
  echo '<a href="#' . h($overlayId) . '">'
     . '<img src="' . $qrSrc . '" alt="QR for /' . h($coid) . '">'
     . '</a>';
  echo '<p style="margin-top:4px;">'
     . '<a href="' . $qrSrc . '" download="careofid-' . h($coid) . '-qr.png">'
     . 'Download QR (PNG)'
     . '</a></p>';
  echo '</div>';

  echo '<div id="' . h($overlayId) . '" class="qr-overlay">';
  echo '  <div class="qr-overlay-inner">';
  echo '    <a href="#" class="qr-overlay-close">×</a>';
  echo '    <img src="' . $qrSrc . '" alt="QR for /' . h($coid) . ' (large)">';
  echo '  </div>';
  echo '</div>';

  echo '</div>';
}

/* Matches: coid_lc => [coid,id,name,dp] */
$matches = array();
function add_match(&$m, $coid, $coid_id, $name, $dp_path, $email = null) {
  $key = strtolower($coid);
  if (!isset($m[$key])) {
    $m[$key] = array(
      'coid' => $coid,
      'id'   => $coid_id,
      'name' => $name,
      'dp'   => $dp_path,
      'email'=> $email
    );
  }
}
?>
<div class="centercol">

  <div class="hero-box">

    <p>Careofid lets you share one simple COID that points to all of your profiles and links.</p>
    <p>Now with CareOfID you can share all your handles for Facebook, YouTube, Instagram, X or LinkedIn just by simple COID or QR Code.</p>
    <p>To test type in CNN in the below box, here you just need to know COID, in this case it is CNN </p>
    <p>If you know only one handle, you can find all other handles, type https:/facebook.com/cnn</p>
  </div>

  <div class="hero-box">
    <div class="hero-title">Find a COID</div>
    <div class="hero-sub">Search by COID, name, phone, or paste a full social URL (e.g., https://facebook.com/handle).</div>

    <form method="get" action="/index.php">
      <label for="q">Search</label>
      <div class="stack">
        <!-- keep input visually blank after search -->
        <input id="q" name="q" type="text" value="">
        <button type="submit">Search</button>
      </div>
    </form>

    <?php
    if ($q_val !== '') {
      echo '<div class="results">';

      /* URL query */
      if (looks_like_url($q_val)) {
        $parsed = parse_social_url($q_val);

        if (isset($parsed['provider'], $parsed['handle'])) {
          $slug = $parsed['provider']; $handle = $parsed['handle'];
          $sql = 'SELECT u.first_name, u.last_name, u.email, u.dp_path, c.coid, c.id AS coid_id
                  FROM user_provider_handles uph
                  JOIN providers p ON p.id = uph.provider_id
                  JOIN coids c ON c.id = uph.coid_id
                  JOIN users u ON u.id = c.user_id
                  WHERE p.slug = ?
                      AND (uph.handle = ? OR LOWER(uph.handle) = LOWER(?))
                      AND u.deleted_at IS NULL
                      AND c.is_masked = 0
                  LIMIT 100';
          $st = db()->prepare($sql);
          $st->execute(array($slug, $handle, $handle));
          foreach ($st->fetchAll() as $r) {
            $name = $r['first_name'].' '.$r['last_name'];
            $dp   = isset($r['dp_path']) ? $r['dp_path'] : null;
            add_match($matches, $r['coid'], $r['coid_id'], $name, $dp, isset($r['email']) ? $r['email'] : null);
          }
        } elseif (isset($parsed['website_url'])) {
          $canon = norm_url_for_compare($parsed['website_url']);

          $variants = array(
            $canon,
            preg_replace('#^https?://#i', '', $canon),
            rtrim($canon, '/'),
            rtrim(preg_replace('#^https?://#i', '', $canon), '/'),
          );
          $variants = array_values(array_unique($variants));

          $sql = 'SELECT u.first_name, u.last_name, u.email, u.dp_path, c.coid, c.id AS coid_id
                  FROM user_provider_handles uph
                  JOIN providers p ON p.id = uph.provider_id
                  JOIN coids c ON c.id = uph.coid_id
                  JOIN users u ON u.id = c.user_id
                  WHERE p.slug = "website" AND (';
          $conds = array();
          foreach ($variants as $_) { $conds[] = 'uph.handle = ?'; }
          foreach ($variants as $_) { $conds[] = 'LOWER(uph.handle) = LOWER(?)'; }
          $sql .= implode(' OR ', $conds) . ')
            AND u.deleted_at IS NULL
            AND c.is_masked = 0
          LIMIT 100';
          $params = array_merge($variants, $variants);
          $st = db()->prepare($sql);
          $st->execute($params);
          foreach ($st->fetchAll() as $r) {
            $name = $r['first_name'].' '.$r['last_name'];
            $dp   = isset($r['dp_path']) ? $r['dp_path'] : null;
            add_match($matches, $r['coid'], $r['coid_id'], $name, $dp, isset($r['email']) ? $r['email'] : null);
          }

          $sqlAll = 'SELECT u.first_name, u.last_name, u.dp_path,
                            c.coid, c.id AS coid_id,
                            p.slug, p.display, p.url_pattern, uph.handle
                     FROM user_provider_handles uph
                     JOIN providers p ON p.id = uph.provider_id
                     JOIN coids c ON c.id = uph.coid_id
                     JOIN users u ON u.id = c.user_id
                     WHERE u.deleted_at IS NULL
                       AND c.is_masked = 0
                     LIMIT 1000';
          $stAll = db()->prepare($sqlAll);
          $stAll->execute();

          foreach ($stAll->fetchAll() as $r) {
            if ($r['slug'] === 'website') {
              $full = preg_match('#^https?://#i', $r['handle'])
                        ? $r['handle']
                        : 'https://' . $r['handle'];
            } else {
              $full = str_replace('{handle}', $r['handle'], $r['url_pattern']);
            }

            $normFull = norm_url_for_compare($full);
            if ($normFull === $canon) {
              $name = $r['first_name'].' '.$r['last_name'];
              $dp   = isset($r['dp_path']) ? $r['dp_path'] : null;
              add_match($matches, $r['coid'], $r['coid_id'], $name, $dp, isset($r['email']) ? $r['email'] : null);
            }
          }
        }
      }

      /* COID lookup */
      $co_try = find_coid_row_public($q_val);
      if ($co_try) {
        // Pull DP path from users, but reuse email from the public lookup row
        $stn = db()->prepare('SELECT first_name, last_name, dp_path FROM users WHERE id = ? LIMIT 1');
        $stn->execute(array($co_try['user_id']));
        $nm = $stn->fetch();
        $name = $nm ? ($nm['first_name'].' '.$nm['last_name']) : '';
        $dp   = ($nm && isset($nm['dp_path'])) ? $nm['dp_path'] : null;
        $email = isset($co_try['email']) ? $co_try['email'] : null;
        add_match($matches, $co_try['coid'], $co_try['id'], $name, $dp, $email);
      }

      /* Phone search */
      $p = normalize_phone_e164($q_val);
      if ($p !== null) {
        $st = db()->prepare('SELECT u.first_name, u.last_name, u.email, u.dp_path, c.coid, c.id AS coid_id
                             FROM users u
                             JOIN coids c ON c.user_id = u.id
                             WHERE u.phone_e164 = ? 
                                AND u.deleted_at IS NULL
                                AND c.is_masked = 0
                                LIMIT 100');
        $st->execute(array($p));
        foreach ($st->fetchAll() as $r) {
          $name = $r['first_name'].' '.$r['last_name'];
          $dp   = isset($r['dp_path']) ? $r['dp_path'] : null;
          add_match($matches, $r['coid'], $r['coid_id'], $name, $dp, isset($r['email']) ? $r['email'] : null);
        }
      }

      /* Name partials */
      $looks_like_name = preg_match('/[^\w.@+\-]/', $q_val);
      if ($looks_like_name || empty($matches)) {
        $like = '%'.$q_val.'%';
        $st = db()->prepare('SELECT u.first_name, u.last_name, u.email, u.dp_path, c.coid, c.id AS coid_id
                             FROM users u
                             JOIN coids c ON c.user_id = u.id
                             WHERE (u.first_name LIKE ? OR u.last_name LIKE ?)
                                AND u.deleted_at IS NULL
                                AND c.is_masked = 0
                             LIMIT 100');
        $st->execute(array($like, $like));
        foreach ($st->fetchAll() as $r) {
          $name = $r['first_name'].' '.$r['last_name'];
          $dp   = isset($r['dp_path']) ? $r['dp_path'] : null;
          add_match($matches, $r['coid'], $r['coid_id'], $name, $dp, isset($r['email']) ? $r['email'] : null);
        }
      }

      $count = count($matches);
      if ($count === 0) {
        echo '<div class="muted">No matches.</div>';
      } elseif ($count > 2) {
        echo '<div class="result-item"><div class="muted">Multiple matches — select a COID</div></div>';
        foreach ($matches as $m) {
          echo '<div class="result-item">';
          echo '<div class="result-multi-line">';
          if (!empty($m['dp'])) {
            $dpId = 'dp-full-' . $m['coid'];
            echo '<a href="#' . h($dpId) . '">';
            echo '<img class="dp" src="' . h($m['dp']) . '" alt="Profile picture">';
            echo '</a>';

            // DP overlay for list item
            echo '<div id="' . h($dpId) . '" class="dp-overlay">';
            echo '  <div class="dp-overlay-inner">';
            echo '    <a href="#" class="dp-overlay-close">×</a>';
            echo '    <img src="' . h($m['dp']) . '" alt="Profile picture (large)">';
            echo '  </div>';
            echo '</div>';
          }
          echo '<span class="muted">COID:</span> ';
echo '<a class="btn" href="/index.php?q=' . h($m['coid']) . '">' . h($m['coid']) . '</a>';


          echo '<span class="muted" style="margin-left:8px;">' . h($m['name']) . '</span>';
          echo '</div>';
          echo '</div>';
        }
      } else {
        foreach ($matches as $m) {
          $dp = isset($m['dp']) ? $m['dp'] : null;
          render_coid_with_all_handles($m['id'], $m['coid'], $m['name'], $isLogged, $dp, isset($m['email']) ? $m['email'] : null);
        }
      }

      echo '</div>';
    }
    ?>
  </div>
</div>

<div class="centercol" style="margin-top:10px;margin-bottom:20px;">
  <p class="muted" style="text-align:center;font-size:12px;">
    <a href="/privacy">Privacy</a>
  </p>
</div>

<?php
page_foot();
