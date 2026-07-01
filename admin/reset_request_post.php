<?php /* filename: admin/reset_request_post.php */
require_once __DIR__ . '/../include/security.php';
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/render.php';
require_once __DIR__ . '/../include/authz.php';
require_once __DIR__ . '/../include/mailer.php';

admin_session_start(); security_headers(); strict_post_only();
if (!csrf_verify($_POST['csrf'] ?? '')) { http_response_code(400); exit('Bad CSRF'); }

$email = trim((string)($_POST['email'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  flash_add('err','Enter a valid email.');
  header('Location: /admin/forgot.php'); exit;
}

$st = db()->prepare('SELECT id FROM admins WHERE email = ? AND is_active = 1 LIMIT 1');
$st->execute([$email]);
$adm = $st->fetch();

if ($adm) {
  $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
  $exp  = date('Y-m-d H:i:s', time() + 15*60);
  db()->prepare('INSERT INTO admin_password_resets (admin_id, code, expires_at) VALUES (?, ?, ?)')
    ->execute([(int)$adm['id'], $code, $exp]);

  // Send email (use your existing mailer). Provide a helper for admins:
  if (function_exists('send_admin_reset_email')) {
    send_admin_reset_email($email, $code);
  } elseif (function_exists('send_verification_email')) {
    // Fallback to existing function with different wording server-side
    send_verification_email($email, $code);
  }
}

flash_add('ok','If that admin email exists, a code has been sent.');
header('Location: /admin/forgot.php?step=confirm&email=' . urlencode($email));
exit;
