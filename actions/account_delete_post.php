<?php /* filename: actions/account_delete_post.php */
require_once __DIR__ . '/../include/security.php';
require_once __DIR__ . '/../include/render.php';
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/auth.php';

coid_session_start();
security_headers();
strict_post_only();

auth_require_login();

if (!csrf_verify($_POST['csrf'] ?? '')) {
  http_response_code(400);
  exit('Bad CSRF');
}

$confirm = (int)($_POST['confirm'] ?? 0);
if ($confirm !== 1) {
  flash_add('err', 'Please confirm account deletion.');
  header('Location: /dashboard');
  exit;
}

$pdo = db();
$uid = (int)auth_current_user_id();

// Small helper: delete dp file on disk (best effort)
function coid_try_delete_dp_file($publicPath) {
  if (!is_string($publicPath) || $publicPath === '') return;
  if (strpos($publicPath, '/uploads/dp/') !== 0) return;

  $base = realpath(__DIR__ . '/..');
  if ($base === false) $base = dirname(__DIR__);
  $abs = $base . $publicPath;

  if (is_file($abs)) {
    @unlink($abs);
  }
}

try {
  $pdo->beginTransaction();

  // Lock the user row
  $stU = $pdo->prepare('SELECT id, email, is_verified, deleted_at, dp_path, role FROM users WHERE id=? LIMIT 1 FOR UPDATE');
  $stU->execute([$uid]);
  $u = $stU->fetch(PDO::FETCH_ASSOC);

  if (!$u || !empty($u['deleted_at']) || (string)($u['role'] ?? 'user') !== 'user') {
    $pdo->rollBack();
    flash_add('err', 'Account not found.');
    header('Location: /dashboard');
    exit;
  }

  if ((int)$u['is_verified'] !== 1) {
    $pdo->rollBack();
    flash_add('err', 'You must verify your email before deleting your account.');
    header('Location: /dashboard');
    exit;
  }

  // If user already has a COID, do NOT allow self-delete here (admin flow handles deletions)
  $stC = $pdo->prepare('SELECT id FROM coids WHERE user_id=? LIMIT 1');
  $stC->execute([$uid]);
  $hasC = $stC->fetchColumn();
  if ($hasC) {
    $pdo->rollBack();
    flash_add('err', 'Account deletion is not available after a COID is created. Please contact support.');
    header('Location: /dashboard');
    exit;
  }

  $dpPath = (string)($u['dp_path'] ?? '');

  // Purge user-related rows (user has no COID, but may have pending claim etc.)
  $pdo->prepare('DELETE FROM coid_claims WHERE user_id=?')->execute([$uid]);
  $pdo->prepare('DELETE FROM password_resets WHERE user_id=?')->execute([$uid]);
  $pdo->prepare('DELETE FROM email_verifications WHERE user_id=?')->execute([$uid]);
  $pdo->prepare('DELETE FROM audit_logs WHERE target_user_id=?')->execute([$uid]);

  // Finally delete the user
  $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$uid]);

  $pdo->commit();

  // best-effort dp file cleanup (outside tx)
  if ($dpPath !== '') {
    coid_try_delete_dp_file($dpPath);
  }

  // Logout & redirect
  auth_logout();
  flash_add('ok', 'Your account has been permanently deleted.');
  header('Location: /');
  exit;

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log('account_delete_post failed: ' . $e->getMessage());
  flash_add('err', 'Sorry—could not delete your account. Please try again.');
  header('Location: /dashboard');
  exit;
}
