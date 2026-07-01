<?php /* filename: actions/login_post.php */
require_once __DIR__ . '/../include/security.php';
require_once __DIR__ . '/../include/render.php';
require_once __DIR__ . '/../include/db.php';

coid_session_start();
security_headers();
strict_post_only();

if (!csrf_verify($_POST['csrf'] ?? '')) {
  http_response_code(400);
  exit('Bad CSRF');
}

$pdo = db();

$email = trim((string)($_POST['email'] ?? ''));
$pass  = (string)($_POST['password'] ?? '');
$next  = isset($_POST['next']) ? (string)$_POST['next'] : '/dashboard.php';

/* Validate next (site-relative only) to prevent open redirect */
$next = trim($next);
if ($next === '' || preg_match('~^(https?:)?//~i', $next) || strpos($next, "\n") !== false || strpos($next, "\r") !== false) {
  $next = '/dashboard.php';
}
if ($next[0] !== '/') $next = '/' . ltrim($next, '/');

/* Capture IP for login_attempts (VARBINARY(16)) */
$ipStr = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$ipBin = @inet_pton($ipStr);
if ($ipBin === false || $ipBin === null) {
  // fallback 0.0.0.0
  $ipBin = @inet_pton('0.0.0.0');
}

if ($email === '' || $pass === '') {
  flash_add('err', 'Please enter your email and password.');
  header('Location: /login.php?next=' . urlencode($next));
  exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  flash_add('err', 'Please enter a valid email address.');
  header('Location: /login.php?next=' . urlencode($next));
  exit;
}

$success = 0;

try {
  // Find user (exclude soft-deleted)
  $st = $pdo->prepare('
    SELECT id, email, password_hash, first_name, last_name, is_verified, plan_status, role, deleted_at
    FROM users
    WHERE email = ?
    LIMIT 1
  ');
  $st->execute([$email]);
  $u = $st->fetch(PDO::FETCH_ASSOC);

  if ($u && empty($u['deleted_at']) && password_verify($pass, (string)$u['password_hash'])) {
    $success = 1;

    $uid = (int)$u['id'];

    // Set session (support both styles used in your codebase)
    $_SESSION['user_id'] = $uid;
    $_SESSION['user'] = [
      'id'         => $uid,
      'email'      => (string)$u['email'],
      'first_name' => (string)$u['first_name'],
      'last_name'  => (string)$u['last_name'],
      'is_verified'=> (int)$u['is_verified'],
      'plan_status'=> (string)$u['plan_status'],
      'role'       => (string)$u['role'],
    ];

    // Record login attempt (success)
    try {
      $la = $pdo->prepare('INSERT INTO login_attempts (ip, email, occurred_at, success) VALUES (?, ?, NOW(), 1)');
      $la->bindParam(1, $ipBin, PDO::PARAM_LOB);
      $la->bindValue(2, $email);
      $la->execute();
    } catch (\Throwable $e) { /* ignore */ }

    // ✅ NEW REQUIREMENT: If any pending claim exists, show pending-claim message page
    $stP = $pdo->prepare('SELECT 1 FROM coid_claims WHERE user_id = ? AND status = "pending" LIMIT 1');
    $stP->execute([$uid]);
    if ($stP->fetchColumn()) {
      header('Location: /pending_claim.php');
      exit;
    }

    // Normal redirect
    header('Location: ' . $next);
    exit;
  }

  // Record login attempt (fail)
  try {
    $la = $pdo->prepare('INSERT INTO login_attempts (ip, email, occurred_at, success) VALUES (?, ?, NOW(), 0)');
    $la->bindParam(1, $ipBin, PDO::PARAM_LOB);
    $la->bindValue(2, $email);
    $la->execute();
  } catch (\Throwable $e) { /* ignore */ }

  // If user exists but not verified, gently guide to verify instead of a generic error
  if ($u && empty($u['deleted_at']) && (int)$u['is_verified'] === 0) {
    flash_add('err', 'Your email is not verified yet. Please verify your email to continue.');
    header('Location: /verify.php?next=' . urlencode($next));
    exit;
  }

  flash_add('err', 'Invalid email or password.');
  header('Location: /login.php?next=' . urlencode($next));
  exit;

} catch (\Throwable $e) {
  // Record login attempt (fail) best-effort
  try {
    $la = $pdo->prepare('INSERT INTO login_attempts (ip, email, occurred_at, success) VALUES (?, ?, NOW(), 0)');
    $la->bindParam(1, $ipBin, PDO::PARAM_LOB);
    $la->bindValue(2, $email);
    $la->execute();
  } catch (\Throwable $e2) { /* ignore */ }

  flash_add('err', 'Login failed. Please try again.');
  header('Location: /login.php?next=' . urlencode($next));
  exit;
}
