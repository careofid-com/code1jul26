<?php /* filename: handles.php */
require_once __DIR__ . '/include/security.php';
require_once __DIR__ . '/include/render.php';
require_once __DIR__ . '/include/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/validation.php'; // ensures h() is available

coid_session_start();
security_headers();
auth_require_login();

$u   = auth_reload_user();
$uid = (int)$u['id'];

$pdo = db();

/* -------------------------------------------------------
   0) Load latest user row with plan info
   ------------------------------------------------------- */
$stPlan = $pdo->prepare('SELECT email, plan_status FROM users WHERE id = ? LIMIT 1');
$stPlan->execute(array($uid));
$urow = $stPlan->fetch();

$email      = $urow ? (string)$urow['email'] : '';
$planStatus = $urow && isset($urow['plan_status']) && $urow['plan_status'] !== null
              ? (string)$urow['plan_status']
              : 'free';

$isStaffDomain = false;
if ($email !== '') {
    $isStaffDomain = (bool)preg_match('/@careofid\.com$/i', $email);
}

if ($isStaffDomain || $planStatus === 'paid') {
    $planLabel      = 'Paid plan';
    $planShort      = 'paid';
    $providersLimit = 20;
} else {
    $planLabel      = 'Free plan';
    $planShort      = 'free';
    $providersLimit = 4;
}

/* -------------------------------------------------------
   1) Find one COID for this user (first / primary)
   ------------------------------------------------------- */
$st = $pdo->prepare('SELECT id, coid FROM coids WHERE user_id = ? ORDER BY id ASC');
$st->execute(array($uid));
$coids = $st->fetchAll();

if (!$coids) {
  page_head('Update handles — careofid');
  page_nav();
  page_flash();
  ?>
  <div class="card">
    <h2>No COID yet</h2>
    <p>You need to create a COID before adding or updating handles.</p>
    <p><a class="btn" href="/dashboard.php">Go to dashboard</a></p>
  </div>
  <?php
  page_foot();
  exit;
}

$coid_id = (int)$coids[0]['id'];
$coid    = $coids[0]['coid'];

/* -------------------------------------------------------
   2) Load providers visible to this user
      - Global providers: created_by_user_id IS NULL
      - Private providers: created_by_user_id = current user
   ------------------------------------------------------- */
$st = $pdo->prepare('SELECT p.id,
                            p.slug,
                            p.display,
                            p.url_pattern,
                            p.created_by_user_id,
                            p.is_active,
                            p.is_system,
                            uph.handle
                     FROM providers p
                     LEFT JOIN user_provider_handles uph
                       ON uph.provider_id = p.id
                      AND uph.coid_id = ?
                     WHERE p.is_active = 1
                       AND (p.created_by_user_id IS NULL OR p.created_by_user_id = ?)
                     ORDER BY p.display');
$st->execute(array($coid_id, $uid));
$providers = $st->fetchAll();

/* Count how many providers are currently in use (non-empty handle in DB) */
$usedCount = 0;
if ($providers) {
    foreach ($providers as $p) {
        $rawHandle = isset($p['handle']) ? trim((string)$p['handle']) : '';
        if ($rawHandle !== '') {
            $usedCount++;
        }
    }
}

page_head('Update handles — careofid');
page_nav();
page_flash();
?>

<style>
  .handles-wrap { max-width: 760px; margin: 0 auto; }
  .handles-table { width: 100%; border-collapse: collapse; margin-top: 8px; }
  .handles-table th, .handles-table td {
    padding: 6px 4px;
    border-bottom: 1px solid #eee;
    vertical-align: middle;
  }
  .handles-table th { text-align: left; font-size: 13px; color:#555; }
  .handles-table td label { font-weight: 600; }
  .handles-table input[type="text"] {
    width: 100%;
    box-sizing: border-box;
  }
  .provider-note {
    font-size: 11px;
    color: #777;
  }
  .provider-system-badge {
    display:inline-block;
    padding:1px 5px;
    font-size:10px;
    border-radius:8px;
    background:#f0f0f0;
    color:#555;
    margin-left:4px;
  }
  .provider-private-badge {
    display:inline-block;
    padding:1px 5px;
    font-size:10px;
    border-radius:8px;
    background:#e0f3ff;
    color:#0066aa;
    margin-left:4px;
  }
  .plan-chip {
    display:inline-block;
    padding:3px 8px;
    border-radius:999px;
    font-size:11px;
    background:#f2f2f2;
    color:#333;
    margin-top:4px;
  }
  .plan-chip.paid {
    background:#e6f7e9;
    color:#166534;
  }
  .plan-chip.free {
    background:#fef3c7;
    color:#92400e;
  }
</style>

<div class="handles-wrap">

  <!-- Plan summary / upgrade link (for free, non-staff users) -->
  <div class="card">
    <h1>Update handles</h1>
    <p>
      <span class="muted">COID:</span>
      <span class="btn" style="cursor:default; margin-left:4px;"><?php echo h($coid); ?></span>
    </p>

    <p class="plan-chip <?php echo ($planShort === 'paid' ? 'paid' : 'free'); ?>">
      <?php echo h($planLabel); ?> — <?php echo (int)$usedCount; ?> of <?php echo (int)$providersLimit; ?> providers used
    </p>

    <?php if ($planShort === 'free' && !$isStaffDomain): ?>
      <p class="muted" style="font-size:13px; margin-top:8px;">
        Free plan lets you use up to 4 providers. Upgrade once to unlock up to 20 providers.
      </p>
      <div style="margin-top:8px; margin-bottom:6px;">
        <!-- SIMPLE STRIPE PAYMENT LINK (no JS, CSP-safe) -->
        <a class="btn"
           href="https://buy.stripe.com/dRmaEW3TT5yNbX4euEeIw02"
           target="_blank"
           rel="noopener noreferrer">
          Upgrade to Paid
        </a>
        <div class="provider-note">
          This opens a secure Stripe checkout page in a new tab.
        </div>
      </div>
    <?php else: ?>
      <p class="muted" style="font-size:13px; margin-top:8px;">
        You can configure up to <?php echo (int)$providersLimit; ?> providers for this account.
      </p>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Your providers & handles</h2>
    <p class="muted" style="font-size:13px;">
      Add or update your social usernames and links. You’ll see global providers
      (Facebook, LinkedIn, Website, etc.) plus any private providers you created.
    </p>

    <form method="post" action="/actions/handle_save_post.php">
      <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
      <input type="hidden" name="coid_id" value="<?php echo (int)$coid_id; ?>">

      <table class="handles-table">
        <thead>
          <tr>
            <th style="width:35%;">Provider</th>
            <th style="width:65%;">Your handle / link</th>
          </tr>
        </thead>
        <tbody>
        <?php if ($providers): ?>
          <?php foreach ($providers as $p): ?>
            <?php
              $provId    = (int)$p['id'];
              $label     = $p['display'];
              $slug      = $p['slug'];
              $pattern   = $p['url_pattern'];
              $dbHandle  = isset($p['handle']) ? trim((string)$p['handle']) : '';
              $handleVal = $dbHandle;
              $isSystem  = (int)$p['is_system'] === 1;
              $isPrivate = (!$isSystem && !is_null($p['created_by_user_id']));

              if ($handleVal === '' &&
                  !$isSystem &&
                  !is_null($p['created_by_user_id']) &&
                  (int)$p['created_by_user_id'] === $uid &&
                  strpos($pattern, '{handle}') === false) {
                  $handleVal = $pattern;
              }

              $finalUrl = '';
              if ($slug === 'website') {
                  if ($handleVal !== '') {
                      if (preg_match('#^https?://#i', $handleVal)) {
                          $finalUrl = $handleVal;
                      } else {
                          $finalUrl = 'https://' . $handleVal;
                      }
                  }
              } else {
                  if (strpos($pattern, '{handle}') !== false) {
                      if ($handleVal !== '') {
                          $finalUrl = str_replace('{handle}', $handleVal, $pattern);
                      }
                  } else {
                      $finalUrl = $pattern;
                  }
              }
            ?>
            <tr>
              <td>
                <label><?php echo h($label); ?></label>
                <?php if ($isSystem): ?>
                  <span class="provider-system-badge">system</span>
                <?php elseif ($isPrivate): ?>
                  <span class="provider-private-badge">your provider</span>
                <?php endif; ?>
                <div class="provider-note">
                  <?php
                  if ($slug === 'website') {
                    echo 'Enter full website or domain (e.g. example.com or https://example.com).';
                  } else {
                    echo 'Shown as a button on your public profile.';
                  }
                  ?>
                </div>
              </td>
              <td>
                <input type="text"
                       name="handle[<?php echo $provId; ?>]"
                       value="<?php echo h($handleVal); ?>"
                       placeholder="<?php echo ($slug === 'website') ? 'your-site.com or https://your-site.com' : 'username or link'; ?>">
                <?php if ($finalUrl !== ''): ?>
                  <div class="provider-note">
                    Final link: <?php echo h($finalUrl); ?>
                  </div>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr>
            <td colspan="2">
              <span class="muted">No providers available yet.</span>
            </td>
          </tr>
        <?php endif; ?>
        </tbody>
      </table>

      <div class="row" style="margin-top:12px;">
        <button type="submit">Save handles</button>
      </div>
    </form>
  </div>

  <div class="card">
    <h2>Add new provider</h2>
    <p class="muted" style="font-size:13px;">
      Providers you create here will be visible only in your own handles list,
      but will show on your public profile for your COID.
    </p>

    <form method="post" action="/actions/provider_create_post.php">
      <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">

      <label>Display name</label>
      <input type="text" name="display" required placeholder="e.g. My Blog">

      <label>URL pattern</label>
      <input type="text" name="url_pattern" required placeholder="e.g. https://myblog.com/{handle} or a fixed URL">

      <div class="provider-note">
        You can use <code>{handle}</code> where the username should go (optional).
        If you omit <code>{handle}</code>, the pattern will be used as a fixed URL.
        The technical slug will be generated automatically.
      </div>

      <div class="row" style="margin-top:10px;">
        <button type="submit">Add provider</button>
      </div>
    </form>
  </div>

</div>

<?php
page_foot();
