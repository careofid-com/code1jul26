<?php /* filename: actions/reset_request_post.php */
require_once __DIR__ . '/../include/security.php';
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/render.php';
require_once __DIR__ . '/../include/mailer.php';

coid_session_start();
security_headers();
strict_post_only();

if (!csrf_verify($_POST['csrf'] ?? '')) {
    http_response_code(400);
    exit('Bad CSRF');
}

$email = trim((string)($_POST['email'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash_add('err', 'Enter a valid email address.');
    header('Location: /reset_password.php'); 
    exit;
}

$pdo = db();

/* Look up user by email (ignore deleted users if you’re using deleted_at) */
$st = $pdo->prepare('SELECT id FROM users WHERE email = ? AND (deleted_at IS NULL OR deleted_at IS NULL) LIMIT 1');
$st->execute([$email]);
$user = $st->fetch();

if ($user) {
    $userId = (int)$user['id'];
    $code   = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $exp    = date('Y-m-d H:i:s', time() + 15 * 60); // 15 minutes

    try {
        $pdo->beginTransaction();

        // Invalidate all previous unconsumed codes immediately
        $pdo->prepare('UPDATE password_resets SET consumed_at = NOW() WHERE user_id = ? AND consumed_at IS NULL')
            ->execute([$userId]);

        // Insert new code
        $pdo->prepare('INSERT INTO password_resets (user_id, code, expires_at) VALUES (?, ?, ?)')
            ->execute([$userId, $code, $exp]);

        $pdo->commit();

        // Send reset email
        if (function_exists('send_password_reset_email')) {
            send_password_reset_email($email, $code);
        } elseif (function_exists('send_verification_email')) {
            // Reuse existing mailer with different copy if you want
            send_verification_email($email, $code);
        } else {
            // Very simple fallback (you can improve headers)
            @mail(
                $email,
                'CareOfID password reset code',
                "Your password reset code is: {$code}\nIt expires in 15 minutes.\n",
                "From: info@careofid.com\r\n"
            );
        }

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // Fail silently to user to avoid leaking which emails exist
    }
}

// Always say this (even if user not found) to avoid leaking existence
flash_add('ok', 'If that email exists, a reset code has been sent.');
header('Location: /reset_password.php?step=confirm&email=' . urlencode($email));
exit;
