<?php /* filename: actions/logout_post.php */
require_once __DIR__ . '/../include/security.php';
require_once __DIR__ . '/../include/auth.php';
coid_session_start(); security_headers(); strict_post_only();
auth_logout();
header('Location:/');
exit;
