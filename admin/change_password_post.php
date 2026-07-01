<?php /* filename: admin/change_password_post.php */
require_once __DIR__ . '/../include/security.php';
require_once __DIR__ . '/../include/render.php';
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/authz.php';

admin_session_start(); security_headers(); admin_require_login(); strict_post_only();
if (!csrf_verify($_POST['csrf'] ?? '')) { http_response_code(400); exit('Bad CSRF'); }

$me = admin_current();

$cur = (string)($_POST['current'] ?? '');
$new = (string)($_POST['newpass'] ?? '');

$st = db()->prepare('SELECT id, password_hash FROM admins WHERE id = ? AND is_active = 1 LIMIT 1');
$st->execute([(int)$me['id']]);
$row = $st->fetch();
if (!$row || !password_verify($cur, $row['password_hash'])) {
  flash_add('err','Current password is incorrect.');
  header('Location: /admin/account.php'); exit;
}

$hash = password_hash($new, PASSWORD_DEFAULT);
db()->prepare('UPDATE admins SET password_hash = ?, must_change_password = 0 WHERE id = ?')
  ->execute([$hash, (int)$me['id']]);

audit_log('admin.password.change', ['target_admin_id' => (int)$me['id']]);
flash_add('ok','Password updated.');
header('Location: /admin/account.php'); exit;
