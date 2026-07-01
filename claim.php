<?php /* filename: claim.php */
require_once __DIR__ . '/include/security.php';
require_once __DIR__ . '/include/render.php';
require_once __DIR__ . '/include/db.php';

coid_session_start();
security_headers();


$pdo = db();

$coidIn = trim((string)($_GET['coid'] ?? ''));
if ($coidIn === '') {
  page_head('Claim COID');
  page_nav();
  page_flash();
  echo '<div class="centercol"><div class="card"><h1>Claim COID</h1><p class="muted">Missing COID.</p></div></div>';
  page_foot();
  exit;
}

$coidLc = mb_strtolower($coidIn, 'UTF-8');

/* Load COID + owner email */
$st = $pdo->prepare('
  SELECT c.id AS coid_id, c.coid, c.coid_lc, c.user_id AS owner_user_id, c.is_masked,
         u.email AS owner_email,
         u.deleted_at AS owner_deleted_at
  FROM coids c
  JOIN users u ON u.id = c.user_id
  WHERE c.coid_lc = ?
  LIMIT 1
');
$st->execute([$coidLc]);
$row = $st->fetch(PDO::FETCH_ASSOC);

page_head('Claim COID');
page_nav();
page_flash();

echo '<div class="centercol"><div class="card">';
echo '<h1>Claim this COID</h1>';

if (!$row) {
  echo '<p class="muted">COID not found.</p>';
  echo '</div></div>';
  page_foot();
  exit;
}

/* Public safety: do not allow claims for masked or soft-deleted owners */
if ((int)$row['is_masked'] === 1 || !empty($row['owner_deleted_at'])) {
  echo '<p class="muted">This COID cannot be claimed.</p>';
  echo '</div></div>';
  page_foot();
  exit;
}

$ownerEmail = $row['owner_email'];
$isPlaceholder = ($ownerEmail === null || $ownerEmail === '' || strtolower($ownerEmail) === 'null');

echo '<p><strong>COID:</strong> /' . htmlspecialchars($row['coid'], ENT_QUOTES, 'UTF-8') . '</p>';

if (!$isPlaceholder) {
  echo '<p class="muted">This COID is already owned and cannot be claimed.</p>';
  echo '</div></div>';
  page_foot();
  exit;
}

/* Determine logged-in user */
$userId = (int)($_SESSION['user_id'] ?? 0);
$next = '/claim.php?coid=' . urlencode($row['coid']);

if ($userId <= 0) {
  echo '<p class="muted">To claim this COID, please create an account or log in, then verify your email.</p>';
  echo '<div class="row" style="gap:8px;margin-top:10px;">';
  echo '<a class="btn" href="/signup.php?next=' . urlencode($next) . '">Sign up</a>';
  echo '<a class="btn" href="/login.php?next=' . urlencode($next) . '">Log in</a>';
  echo '</div>';
  echo '</div></div>';
  page_foot();
  exit;
}

/* Load current user verified status */
$stU = $pdo->prepare('SELECT id, email, is_verified, deleted_at FROM users WHERE id=? LIMIT 1');
$stU->execute([$userId]);
$me = $stU->fetch(PDO::FETCH_ASSOC);

if (!$me || !empty($me['deleted_at'])) {
  // bad session or deleted user
  unset($_SESSION['user_id']);
  flash_add('err', 'Please log in again.');
  header('Location: /login.php?next=' . urlencode($next));
  exit;
}

if ((int)$me['is_verified'] !== 1) {
  echo '<p class="muted">Please verify your email before submitting a claim request.</p>';
  echo '<div class="row" style="gap:8px;margin-top:10px;">';
  echo '<a class="btn" href="/verify.php?next=' . urlencode($next) . '">Verify email</a>';
  echo '<a class="btn" href="/logout.php">Log out</a>';
  echo '</div>';
  echo '</div></div>';
  page_foot();
  exit;
}

/* Check if user already has a claim for this COID */
$stC = $pdo->prepare('SELECT status FROM coid_claims WHERE coid_id=? AND user_id=? ORDER BY id DESC LIMIT 1');
$stC->execute([(int)$row['coid_id'], $userId]);
$existing = $stC->fetch(PDO::FETCH_ASSOC);

if ($existing) {
  $stt = (string)$existing['status'];
  if ($stt === 'pending') {
    echo '<p class="muted">Your claim request is already pending review by an admin.</p>';
  } elseif ($stt === 'approved') {
    echo '<p class="muted">Your claim has already been approved. You can now manage this COID from your account.</p>';
  } else {
    echo '<p class="muted">Your previous claim was rejected. You may contact support if needed.</p>';
  }
  echo '<div class="row" style="gap:8px;margin-top:10px;">';
  echo '<a class="btn" href="/">Go to Home</a>';
  echo '</div>';
  echo '</div></div>';
  page_foot();
  exit;
}

/* Step 2: show claim form */
echo '<p class="muted">Step 2: Submit your claim request. An admin will review it. You will receive an email once approved.</p>';

echo '<form method="post" action="/actions/claim_coid_post.php" style="margin-top:10px;">';
echo '<input type="hidden" name="csrf" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
echo '<input type="hidden" name="coid_id" value="' . (int)$row['coid_id'] . '">';
echo '<label>Optional note to Admin</label>';
echo '<textarea name="note" rows="3" maxlength="500" style="width:100%;"></textarea>';
echo '<div class="row" style="gap:8px;margin-top:10px;">';
echo '<button type="submit">Submit claim request</button>';
echo '<a class="btn" href="/">Cancel</a>';
echo '</div>';
echo '</form>';

echo '</div></div>';
page_foot();
