<?php /* filename: admin/user_delete_post.php */
require_once __DIR__ . '/../include/security.php';
require_once __DIR__ . '/../include/render.php';
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/authz.php';

admin_session_start(); security_headers(); admin_require_login(); strict_post_only();
if (!csrf_verify($_POST['csrf'] ?? '')) { http_response_code(400); exit('Bad CSRF'); }

$me = admin_current();
require_admin_role('superadmin');

$uid = (int)($_POST['id'] ?? 0);
if ($uid <= 0) { http_response_code(400); exit('Bad id'); }

db()->prepare('UPDATE users SET deleted_at = NOW() WHERE id = ?')->execute([$uid]);
audit_log('user.delete', ['target_user_id'=>$uid]);
flash_add('ok','User deleted (soft).');
header('Location: /admin/users.php'); exit;
