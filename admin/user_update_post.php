<?php /* filename: admin/user_update_post.php */
require_once __DIR__ . '/../include/security.php';
require_once __DIR__ . '/../include/render.php';
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/authz.php';

admin_session_start(); security_headers(); admin_require_login(); strict_post_only();
if (!csrf_verify($_POST['csrf'] ?? '')) { http_response_code(400); exit('Bad CSRF'); }

$me = admin_current();
$uid = (int)($_POST['id'] ?? 0);
if ($uid <= 0) { http_response_code(400); exit('Bad id'); }

$pdo = db();

/* Load current */
$st = $pdo->prepare('SELECT u.*, c.id AS coid_id, c.coid, c.coid_lc, c.is_masked
                     FROM users u LEFT JOIN coids c ON c.user_id=u.id
                     WHERE u.id=? LIMIT 1');
$st->execute([$uid]);
$row = $st->fetch();
if (!$row) { http_response_code(404); exit('User not found'); }

$fieldsAttempted = [];
$updUser = [];
$updCoid = [];
$handlesIn = (array)($_POST['handles'] ?? []);

/* Collect proposed changes */
$fn = trim((string)($_POST['first_name'] ?? ''));
$ln = trim((string)($_POST['last_name'] ?? ''));
if ($fn !== $row['first_name']) { $updUser['first_name']=$fn; $fieldsAttempted[]='first_name'; }
if ($ln !== $row['last_name'])  { $updUser['last_name']=$ln;  $fieldsAttempted[]='last_name'; }

if (isset($_POST['email'])) {
  $em = trim((string)$_POST['email']);
  if ($em !== '' && $em !== $row['email']) { $updUser['email']=$em; $fieldsAttempted[]='email'; }
}
if (isset($_POST['coid'])) {
  $co = trim((string)$_POST['coid']);
  if ($co !== ($row['coid'] ?? '')) { $updCoid['coid']=$co; $updCoid['coid_lc']=mb_strtolower($co,'UTF-8'); $fieldsAttempted[]='coid'; }
}
if (isset($_POST['is_masked'])) {
  $mk = (int)$_POST['is_masked'];
  if ($mk !== (int)$row['is_masked']) { $updCoid['is_masked']=$mk; $fieldsAttempted[]='is_masked'; }
}
if (!empty($handlesIn)) {
  $fieldsAttempted[]='handles';
}

/* Policy check */
if (!can_edit_user_fields($me['role'], $fieldsAttempted)) {
  flash_add('err','You are not allowed to change one or more fields.');
  header('Location: /admin/user_edit.php?id='.$uid); exit;
}

/* Apply */
try {
  $pdo->beginTransaction();

  if ($updUser) {
    if (isset($updUser['email'])) {
      // ensure unique email
      $chk = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
      $chk->execute([$updUser['email'], $uid]);
      if ($chk->fetch()) { throw new Exception('Email already in use'); }
    }
    $sets = []; $vals = [];
    foreach ($updUser as $k=>$v) { $sets[] = "{$k}=?"; $vals[]=$v; }
    $vals[] = $uid;
    $pdo->prepare('UPDATE users SET '.implode(',',$sets).' WHERE id=?')->execute($vals);
  }

  if ($updCoid && $row['coid_id']) {
    if (isset($updCoid['coid_lc'])) {
      // coid unique
      $chk = $pdo->prepare('SELECT id FROM coids WHERE coid_lc = ? AND id <> ? LIMIT 1');
      $chk->execute([$updCoid['coid_lc'], (int)$row['coid_id']]);
      if ($chk->fetch()) { throw new Exception('COID already taken'); }
    }
    $sets = []; $vals = [];
    foreach ($updCoid as $k=>$v) { $sets[] = "{$k}=?"; $vals[]=$v; }
    $vals[] = (int)$row['coid_id'];
    $pdo->prepare('UPDATE coids SET '.implode(',',$sets).' WHERE id=?')->execute($vals);
  }

  // Update handles
  if ($row['coid_id']) {
    foreach ($handlesIn as $pid => $handle) {
      $pid = (int)$pid; $h = trim((string)$handle);
      // existence?
      $stH = $pdo->prepare('SELECT id FROM user_provider_handles WHERE coid_id=? AND provider_id=? LIMIT 1');
      $stH->execute([(int)$row['coid_id'], $pid]);
      $existing = $stH->fetch();
      if ($h === '') {
        if ($existing) {
          $pdo->prepare('DELETE FROM user_provider_handles WHERE id=?')->execute([(int)$existing['id']]);
        }
      } else {
        if ($existing) {
          $pdo->prepare('UPDATE user_provider_handles SET handle=? WHERE id=?')->execute([$h, (int)$existing['id']]);
        } else {
          $pdo->prepare('INSERT INTO user_provider_handles (coid_id, provider_id, handle) VALUES (?,?,?)')
              ->execute([(int)$row['coid_id'], $pid, $h]);
        }
      }
    }
  }

  $pdo->commit();
  audit_log('user.update', ['target_user_id'=>$uid, 'details'=>['fields'=>$fieldsAttempted]]);
  flash_add('ok','User updated.');
  header('Location: /admin/user_edit.php?id='.$uid); exit;

} catch (\Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  flash_add('err','Update failed: '.$e->getMessage());
  header('Location: /admin/user_edit.php?id='.$uid); exit;
}
