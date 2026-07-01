<?php /* filename: actions/claim_coid_post.php */
require_once __DIR__ . '/../include/security.php';
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/render.php';

coid_session_start();
security_headers();
strict_post_only();

if (!csrf_verify($_POST['csrf'] ?? '')) {
  http_response_code(400);
  exit('Bad CSRF');
}

$userId = 0;
if (!empty($_SESSION['user_id'])) $userId = (int)$_SESSION['user_id'];
elseif (!empty($_SESSION['user']['id'])) $userId = (int)$_SESSION['user']['id'];

if ($userId <= 0) {
  flash_add('err', 'Please log in first.');
  header('Location: /login.php');
  exit;
}

$coidId = (int)($_POST['coid_id'] ?? 0);
$note   = trim((string)($_POST['note'] ?? ''));
if (strlen($note) > 500) $note = substr($note, 0, 500);

if ($coidId <= 0) {
  flash_add('err', 'Invalid COID.');
  header('Location: /');
  exit;
}

$pdo = db();

try {
  $pdo->beginTransaction();

  // Ensure user exists + verified
  $stU = $pdo->prepare('SELECT id, email, is_verified, deleted_at FROM users WHERE id=? LIMIT 1 FOR UPDATE');
  $stU->execute([$userId]);
  $me = $stU->fetch(PDO::FETCH_ASSOC);

  if (!$me || !empty($me['deleted_at'])) {
    $pdo->rollBack();
    flash_add('err', 'Please log in again.');
    header('Location: /login.php');
    exit;
  }
  if ((int)$me['is_verified'] !== 1) {
    $pdo->rollBack();
    flash_add('err', 'Please verify your email first.');
    header('Location: /verify.php');
    exit;
  }

  // Load COID and ensure it is placeholder-owned (email NULL/empty/"null")
  $st = $pdo->prepare('
    SELECT c.id, c.coid, c.user_id AS owner_user_id, c.is_masked,
           u.email AS owner_email, u.deleted_at AS owner_deleted_at
    FROM coids c
    JOIN users u ON u.id = c.user_id
    WHERE c.id = ?
    LIMIT 1
    FOR UPDATE
  ');
  $st->execute([$coidId]);
  $co = $st->fetch(PDO::FETCH_ASSOC);

  if (!$co) {
    $pdo->rollBack();
    flash_add('err', 'COID not found.');
    header('Location: /');
    exit;
  }

  if ((int)$co['is_masked'] === 1 || !empty($co['owner_deleted_at'])) {
    $pdo->rollBack();
    flash_add('err', 'This COID cannot be claimed.');
    header('Location: /');
    exit;
  }

  $ownerEmail = $co['owner_email'];
  $isPlaceholder = ($ownerEmail === null || $ownerEmail === '' || strtolower($ownerEmail) === 'null');
  if (!$isPlaceholder) {
    $pdo->rollBack();
    flash_add('err', 'This COID is already owned and cannot be claimed.');
    header('Location: /');
    exit;
  }

  // Prevent duplicate claim rows
  $chk = $pdo->prepare('SELECT id, status FROM coid_claims WHERE coid_id=? AND user_id=? ORDER BY id DESC LIMIT 1');
  $chk->execute([$coidId, $userId]);
  $ex = $chk->fetch(PDO::FETCH_ASSOC);

  if ($ex) {
    $pdo->rollBack();
    if ($ex['status'] === 'pending') {
      flash_add('ok', 'Your claim is already pending admin review.');
      header('Location: /claim.php?coid=' . urlencode($co['coid']));
      exit;
    }
    flash_add('err', 'You already submitted a claim for this COID.');
    header('Location: /claim.php?coid=' . urlencode($co['coid']));
    exit;
  }

  // Create claim
  $ins = $pdo->prepare('
    INSERT INTO coid_claims (coid_id, user_id, status, created_at, note)
    VALUES (?, ?, "pending", NOW(), ?)
  ');
  $ins->execute([$coidId, $userId, ($note === '' ? null : $note)]);

  $pdo->commit();

  flash_add('ok', 'Your claim request has been sent to admin. You will receive an email once approved.');
  header('Location: /');
  exit;

} catch (\Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  flash_add('err', 'Claim request failed: ' . $e->getMessage());
  header('Location: /');
  exit;
}
