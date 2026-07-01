<?php /* filename: logout.php */
require_once __DIR__ . '/include/security.php';
require_once __DIR__ . '/include/auth.php';

coid_session_start(); security_headers();
auth_logout();
header('Location: /');
exit;
