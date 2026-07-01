<?php /* filename: admin/logout.php */
require_once __DIR__ . '/../include/security.php';
require_once __DIR__ . '/../include/authz.php';
require_once __DIR__ . '/../include/render.php';

admin_session_start(); security_headers();
if (admin_current()) { audit_log('admin.logout'); }
admin_clear_session();
flash_add('ok','Logged out.');
header('Location: /admin/login.php'); exit;
