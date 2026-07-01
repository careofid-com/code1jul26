<?php /* filename: admin/reset_confirm_post.php */
require_once __DIR__ . '/../include/security.php';
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/render.php';
require_once __DIR__ . '/../include/authz.php';

admin_session_start(); security_headers(); strict_post_only();
if (!csrf_verify($_POST['csrf'] ?? '')) { http_response_code(400); exit('Bad CSRF'); }

$email = trim((string)($_POST['email'] ?? ''));
$code  = trim((string)($_POST['code'] ?? ''));
$pass  = (string)($_POST['password'] ?? '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^[0-9]{6}$/', $code) || strlen($pass) < 8) {
  flash_add('err','Invalid input.');
  header('Location: /admin/forgot.php?step=confirm&email=' . urlencode($email)); exit;
}

$pdo = db();
$st = $pdo->prepare('SELECT * FROM admins WHERE email = ? AND is_active = 1 LIMIT 1');
$st->execute([$email]);
$a = $st->fetch();
if (!$a) { flash_add('err','Invalid email or code.'); header('Location: /admin/forgot.php?step=confirm&email=' . urlencode($email)); exit; }

$st = $pdo->prepare('SELECT * FROM admin_password_resets
                     WHERE admin_id = ? AND code = ? AND consumed_at IS NULL
                     ORDER BY id DESC LIMIT 1');
$st->execute([(int)$a['id'], $code]);
$pr = $st->fetch();
if (!$pr || strtotime($pr['expires_at']) < time()) {
  flash_add('err','Invalid or expired code.');
  header('Location: /admin/forgot.php?step=confirm&email=' . urlencode($email)); exit;
}

try {
  $pdo->beginTransaction();
  $hash = password_hash($pass, PASSWORD_DEFAULT);
  $pdo->prepare('UPDATE admins SET password_hash = ?, must_change_password = 0 WHERE id = ?')
      ->execute([$hash, (int)$a['id']]);
  $pdo->prepare('UPDATE admin_password_resets SET consumed_at = NOW() WHERE id = ?')
      ->execute([(int)$pr['id']]);
  $pdo->commit();

  audit_log('admin.password.reset', ['target_admin_id' => (int)$a['id']]);
  flash_add('ok','Password reset. You can log in now.');
  header('Location: /admin/login.php'); exit;

} catch (\Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  flash_add('err','Could not reset password.');
  header('Location: /admin/forgot.php?step=confirm&email=' . urlencode($email)); exit;
}
