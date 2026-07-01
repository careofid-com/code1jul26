<?php /* filename: actions/signup_post.php */
require_once __DIR__ . '/../include/security.php';
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/render.php';

// DP upload helper exists in your project
require_once __DIR__ . '/../include/dp_upload.php';

coid_session_start();
security_headers();
strict_post_only();

if (!csrf_verify($_POST['csrf'] ?? '')) {
  http_response_code(400);
  exit('Bad CSRF');
}

$pdo = db();

/* Inputs from your signup.php */
$email = trim((string)($_POST['email'] ?? ''));
$fn    = trim((string)($_POST['first_name'] ?? ''));
$ln    = trim((string)($_POST['last_name'] ?? ''));
$pass  = (string)($_POST['password'] ?? '');

$phone_cc    = trim((string)($_POST['phone_cc'] ?? ''));
$phone_local = trim((string)($_POST['phone_local'] ?? ''));

$dpCropped = (string)($_POST['dp_cropped'] ?? '');
$next      = trim((string)($_POST['next'] ?? '/dashboard'));

/* Validate next (site-relative only) */
if ($next === '' || preg_match('~^(https?:)?//~i', $next) || strpos($next, "\n") !== false || strpos($next, "\r") !== false) {
  $next = '/dashboard';
}
if ($next[0] !== '/') $next = '/' . ltrim($next, '/');

/* Basic validation */
if ($email === '' || $fn === '' || $ln === '' || $pass === '') {
  flash_add('err', 'Please fill in all required fields.');
  header('Location: /signup.php?next=' . urlencode($next));
  exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  flash_add('err', 'Please enter a valid email address.');
  header('Location: /signup.php?next=' . urlencode($next));
  exit;
}
if (strlen($pass) < 8) {
  flash_add('err', 'Password must be at least 8 characters.');
  header('Location: /signup.php?next=' . urlencode($next));
  exit;
}

/* Normalize phone to E.164 if provided */
$phone_e164 = null;
if ($phone_local !== '') {
  $digits = preg_replace('~\D+~', '', $phone_local);
  $cc = preg_replace('~\s+~', '', $phone_cc);
  if ($cc === '') $cc = '+1';
  if ($cc[0] !== '+') $cc = '+' . ltrim($cc, '+');

  if ($digits !== '') {
    $phone_e164 = $cc . $digits;
    if (strlen($phone_e164) < 8 || strlen($phone_e164) > 20) {
      $phone_e164 = null;
    }
  }
}

try {
  $pdo->beginTransaction();

  // Find existing user by email
  $st = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1 FOR UPDATE');
  $st->execute([$email]);
  $existing = $st->fetch(PDO::FETCH_ASSOC);

  // If exists and soft-deleted, block (keeps your public rules safe)
  if ($existing && !empty($existing['deleted_at'])) {
    $pdo->rollBack();
    flash_add('err', 'This account is not available. Please contact support.');
    header('Location: /login.php?next=' . urlencode($next));
    exit;
  }

  // Helper to create/refresh a verification code
  $make_code = function() {
    $code = (string)random_int(100000, 999999);
    $exp  = (new DateTime('now'))->modify('+30 minutes')->format('Y-m-d H:i:s');
    return [$code, $exp];
  };

  if ($existing) {
    // ✅ EXISTING EMAIL CASE
    $userId = (int)$existing['id'];

    if ((int)$existing['is_verified'] === 1) {
      // Already verified → normal login flow
      $pdo->rollBack();
      flash_add('err', 'Email already registered. Please log in.');
      header('Location: /login.php?next=' . urlencode($next));
      exit;
    }

    // NOT verified → resend verification code and go to verify (this is what you want)
    // Optional: you can update name/phone to latest submitted values
    $upd = $pdo->prepare('UPDATE users SET first_name=?, last_name=?, phone_e164=?, updated_at=NOW() WHERE id=?');
    $upd->execute([$fn, $ln, $phone_e164, $userId]);

    // Create a new verification code row (preferred; keeps history)
    list($code, $exp) = $make_code();
    $pdo->prepare('
      INSERT INTO email_verifications (user_id, code, expires_at, created_at)
      VALUES (?, ?, ?, NOW())
    ')->execute([$userId, $code, $exp]);

    // Login the user in session so verify works
    $_SESSION['user_id'] = $userId;
    $_SESSION['user'] = [
      'id' => $userId,
      'email' => $email,
      'first_name' => $fn,
      'last_name' => $ln,
      'is_verified' => 0,
    ];

    $pdo->commit();

    // Email the code
    $subject = 'Your CareOfID verification code';
    $body = "Your verification code is: {$code}\n\nThis code expires in 30 minutes.\n\n— CareOfID";
    @mail($email, $subject, $body, "From: noreply@careofid.com\r\n");

    flash_add('ok', 'Your account exists but is not verified. We sent you a new verification code.');
    header('Location: /verify.php?next=' . urlencode($next));
    exit;
  }

  // ✅ NEW USER CASE
  $hash = password_hash($pass, PASSWORD_DEFAULT);

  $ins = $pdo->prepare('
    INSERT INTO users (email, password_hash, first_name, last_name, phone_e164, is_verified, plan_status, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, 0, "free", NOW(), NOW())
  ');
  $ins->execute([$email, $hash, $fn, $ln, $phone_e164]);
  $userId = (int)$pdo->lastInsertId();

  // Optional DP upload (do not fail signup if DP fails)
  try {
    $dpPath = null;
    if (function_exists('dp_handle_upload')) {
      $dpPath = dp_handle_upload($userId, $dpCropped, $_FILES['dp'] ?? null);
    } elseif (function_exists('dp_upload_from_signup')) {
      $dpPath = dp_upload_from_signup($userId, $dpCropped, $_FILES['dp'] ?? null);
    }
    if ($dpPath) {
      $pdo->prepare('UPDATE users SET dp_path=? WHERE id=?')->execute([$dpPath, $userId]);
    }
  } catch (\Throwable $e) { /* ignore */ }

  // Create verification code
  list($code, $exp) = $make_code();
  $pdo->prepare('
    INSERT INTO email_verifications (user_id, code, expires_at, created_at)
    VALUES (?, ?, ?, NOW())
  ')->execute([$userId, $code, $exp]);

  $pdo->commit();

  // Set session
  $_SESSION['user_id'] = $userId;
  $_SESSION['user'] = [
    'id' => $userId,
    'email' => $email,
    'first_name' => $fn,
    'last_name' => $ln,
    'is_verified' => 0,
  ];

  // Send verification email
  $subject = 'Your CareOfID verification code';
  $body = "Your verification code is: {$code}\n\nThis code expires in 30 minutes.\n\n— CareOfID";
  @mail($email, $subject, $body, "From: noreply@careofid.com\r\n");

  flash_add('ok', 'Account created. Please check your email for the 6-digit verification code.');
  header('Location: /verify.php?next=' . urlencode($next));
  exit;

} catch (\Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  flash_add('err', 'Signup failed: ' . $e->getMessage());
  header('Location: /signup.php?next=' . urlencode($next));
  exit;
}
