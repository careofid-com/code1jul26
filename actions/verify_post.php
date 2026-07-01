<?php /* filename: actions/verify_post.php */
require_once __DIR__ . '/../include/security.php';
require_once __DIR__ . '/../include/render.php';
require_once __DIR__ . '/../include/db.php';

coid_session_start();
security_headers();
strict_post_only();

if (!csrf_verify($_POST['csrf'] ?? '')) { http_response_code(400); exit('Bad CSRF'); }

$pdo = db();

$email = trim((string)($_POST['email'] ?? ''));
$code  = trim((string)($_POST['code'] ?? ''));
$next  = isset($_POST['next']) ? (string)$_POST['next'] : '/dashboard';

/* Validate next (site-relative only) */
if ($next === '' || preg_match('~^(https?:)?//~i', $next) || strpos($next, "\n") !== false || strpos($next, "\r") !== false) {
  $next = '/dashboard';
}
if ($next[0] !== '/') $next = '/' . ltrim($next, '/');

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  flash_add('err', 'Please enter a valid email address.');
  header('Location: /verify.php?next=' . urlencode($next));
  exit;
}
if (!preg_match('~^[0-9]{6}$~', $code)) {
  flash_add('err', 'Please enter a valid 6-digit code.');
  header('Location: /verify.php?next=' . urlencode($next));
  exit;
}

try {
  $pdo->beginTransaction();

  // Find user by email
  $stU = $pdo->prepare('SELECT id, is_verified, deleted_at, first_name, last_name FROM users WHERE email=? LIMIT 1 FOR UPDATE');
  $stU->execute([$email]);
  $u = $stU->fetch(PDO::FETCH_ASSOC);

  if (!$u || !empty($u['deleted_at'])) {
    $pdo->rollBack();
    flash_add('err', 'Account not found for this email.');
    header('Location: /verify.php?next=' . urlencode($next));
    exit;
  }

  $userId = (int)$u['id'];

  if ((int)$u['is_verified'] === 1) {
    // ensure session is set
    $_SESSION['user_id'] = $userId;
    $_SESSION['user'] = [
      'id' => $userId,
      'email' => $email,
      'first_name' => (string)($u['first_name'] ?? ''),
      'last_name'  => (string)($u['last_name'] ?? ''),
      'is_verified' => 1,
    ];
    $pdo->commit();
    flash_add('ok', 'Email already verified.');
    header('Location: ' . $next);
    exit;
  }

  // Validate latest unconsumed code for user
  $st = $pdo->prepare('
    SELECT id, code, expires_at, consumed_at
    FROM email_verifications
    WHERE user_id = ?
      AND consumed_at IS NULL
    ORDER BY id DESC
    LIMIT 1
    FOR UPDATE
  ');
  $st->execute([$userId]);
  $ev = $st->fetch(PDO::FETCH_ASSOC);

  if (!$ev) {
    $pdo->rollBack();
    flash_add('err', 'No active verification code found. Please sign up again or request a new code.');
    header('Location: /verify.php?next=' . urlencode($next));
    exit;
  }

  if ((string)$ev['code'] !== $code) {
    $pdo->rollBack();
    flash_add('err', 'Incorrect code. Please try again.');
    header('Location: /verify.php?next=' . urlencode($next));
    exit;
  }

  if (!empty($ev['expires_at']) && strtotime($ev['expires_at']) < time()) {
    $pdo->rollBack();
    flash_add('err', 'This code has expired. Please request a new one.');
    header('Location: /verify.php?next=' . urlencode($next));
    exit;
  }

  // Mark verified + consume
  $pdo->prepare('UPDATE users SET is_verified=1 WHERE id=?')->execute([$userId]);
  $pdo->prepare('UPDATE email_verifications SET consumed_at=NOW() WHERE id=?')->execute([(int)$ev['id']]);

  // set session so claim step 2 works immediately
  $_SESSION['user_id'] = $userId;
  $_SESSION['user'] = [
    'id' => $userId,
    'email' => $email,
    'first_name' => (string)($u['first_name'] ?? ''),
    'last_name'  => (string)($u['last_name'] ?? ''),
    'is_verified' => 1,
  ];

  $pdo->commit();

  flash_add('ok', 'Email verified successfully.');
  header('Location: ' . $next);
  exit;

} catch (\Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  flash_add('err', 'Verification failed: ' . $e->getMessage());
  header('Location: /verify.php?next=' . urlencode($next));
  exit;
}
