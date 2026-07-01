<?php /* filename: actions/reset_confirm_post.php */
require_once __DIR__ . '/../include/security.php';
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/render.php';

coid_session_start();
security_headers();
strict_post_only();

if (!csrf_verify($_POST['csrf'] ?? '')) {
    http_response_code(400);
    exit('Bad CSRF');
}

$email = trim((string)($_POST['email'] ?? ''));
$code  = trim((string)($_POST['code'] ?? ''));
$pass  = (string)($_POST['password'] ?? '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL) ||
    !preg_match('/^[0-9]{6}$/', $code) ||
    strlen($pass) < 8) {
    flash_add('err', 'Invalid input.');
    header('Location: /reset_password.php?step=confirm&email=' . urlencode($email));
    exit;
}

$pdo = db();

/* Find user */
$st = $pdo->prepare('SELECT id FROM users WHERE email = ? AND (deleted_at IS NULL OR deleted_at IS NULL) LIMIT 1');
$st->execute([$email]);
$user = $st->fetch();
if (!$user) {
    flash_add('err', 'Invalid email or code.');
    header('Location: /reset_password.php?step=confirm&email=' . urlencode($email));
    exit;
}
$userId = (int)$user['id'];

/* Find matching, unconsumed reset row for this user+code */
$st = $pdo->prepare('
    SELECT *
    FROM password_resets
    WHERE user_id = ? AND code = ? AND consumed_at IS NULL
    ORDER BY id DESC
    LIMIT 1
');
$st->execute([$userId, $code]);
$pr = $st->fetch();

if (!$pr) {
    flash_add('err', 'Invalid or expired code.');
    header('Location: /reset_password.php?step=confirm&email=' . urlencode($email));
    exit;
}

/* Check expiry */
if (strtotime($pr['expires_at']) < time()) {
    flash_add('err', 'Invalid or expired code.');
    header('Location: /reset_password.php?step=confirm&email=' . urlencode($email));
    exit;
}

/* All good: update password & consume this reset row */
try {
    $pdo->beginTransaction();

    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
        ->execute([$hash, $userId]);

    $pdo->prepare('UPDATE password_resets SET consumed_at = NOW() WHERE id = ?')
        ->execute([(int)$pr['id']]);

    $pdo->commit();

    flash_add('ok', 'Password reset. You can log in now.');
    header('Location: /login.php');
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash_add('err', 'Could not reset password. Please try again.');
    header('Location: /reset_password.php?step=confirm&email=' . urlencode($email));
    exit;
}
