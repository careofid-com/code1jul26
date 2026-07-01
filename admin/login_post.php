<?php /* filename: admin/login_post.php */
require_once __DIR__ . '/../include/security.php';
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/render.php';
require_once __DIR__ . '/../include/authz.php';

admin_session_start(); security_headers(); strict_post_only();
if (!csrf_verify($_POST['csrf'] ?? '')) { http_response_code(400); exit('Bad CSRF'); }

$email = trim((string)($_POST['email'] ?? ''));
$pass  = (string)($_POST['password'] ?? '');

$st = db()->prepare('SELECT * FROM admins WHERE email = ? AND is_active = 1 LIMIT 1');
$st->execute([$email]);
$adm = $st->fetch();

if (!$adm || !password_verify($pass, $adm['password_hash'])) {
  flash_add('err','Invalid credentials.');
  header('Location: /admin/login.php'); exit;
}

admin_set_session($adm);
db()->prepare('UPDATE admins SET last_login_at = NOW() WHERE id = ?')->execute([$adm['id']]);
audit_log('admin.login', ['target_admin_id' => (int)$adm['id']]);

if (!empty($adm['must_change_password'])) {
  flash_add('ok','Please set a new password.');
  header('Location: /admin/account.php'); exit;
}

header('Location: /admin/index.php'); exit;
