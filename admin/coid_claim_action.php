<?php /* filename: admin/coid_claim_action.php */
require_once __DIR__ . '/../include/security.php';
require_once __DIR__ . '/../include/auth.php';
require_once __DIR__ . '/../include/authz.php';

require_admin_login();

header('Location: /admin/coid_claims.php', true, 302);
exit;
