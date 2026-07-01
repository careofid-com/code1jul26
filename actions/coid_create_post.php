<?php /* filename: actions/coid_create_post.php */
require_once __DIR__ . '/../include/security.php';
require_once __DIR__ . '/../include/auth.php';
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/render.php';
require_once __DIR__ . '/../include/validation.php';

coid_session_start(); security_headers(); strict_post_only(); auth_require_login();
if (!csrf_verify(isset($_POST['csrf'])?$_POST['csrf']:'')) { http_response_code(400); exit('Bad CSRF'); }

$coid = isset($_POST['coid']) ? trim($_POST['coid']) : '';
if (!is_valid_coid($coid)) {
  flash_add('err','Invalid COID format.');
  header('Location:/dashboard'); exit;
}

$lc = coid_lc($coid);
$uid = auth_current_user_id();
$pdo = db();

try {
  // Ensure user has no COID yet
  $st = $pdo->prepare('SELECT id FROM coids WHERE user_id = ?');
  $st->execute(array($uid));
  if ($st->fetch()) {
    flash_add('err','You already have a COID.');
    header('Location:/dashboard'); exit;
  }
  // Ensure unique lc
  $st = $pdo->prepare('SELECT id FROM coids WHERE coid_lc = ?');
  $st->execute(array($lc));
  if ($st->fetch()) {
    flash_add('err','That COID is taken.');
    header('Location:/dashboard'); exit;
  }
  $st = $pdo->prepare('INSERT INTO coids (user_id, coid, coid_lc) VALUES (?, ?, ?)');
  $st->execute(array($uid, $coid, $lc));
  flash_add('ok','COID saved.');
  header('Location:/dashboard'); exit;
} catch (Throwable $e) {
  flash_add('err','Could not save COID.');
  header('Location:/dashboard'); exit;
}
