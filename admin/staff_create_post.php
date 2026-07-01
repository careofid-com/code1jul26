<?php /* filename: admin/staff_create_post.php */
require_once __DIR__ . '/../include/security.php';
require_once __DIR__ . '/../include/render.php';
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/authz.php';
require_once __DIR__ . '/../include/mailer.php';

admin_session_start(); security_headers(); admin_require_login(); strict_post_only();
$me = admin_current(); require_admin_role('admin');
if (!csrf_verify($_POST['csrf'] ?? '')) { http_response_code(400); exit('Bad CSRF'); }

$fn    = trim((string)($_POST['first_name'] ?? ''));
$ln    = trim((string)($_POST['last_name'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$role  = trim((string)($_POST['role'] ?? ''));

/* Preserve in session so we can repopulate form on validation failure */
$_SESSION['staff_create_prefill'] = array(
    'first_name' => $fn,
    'last_name'  => $ln,
    'email'      => $email,
    'role'       => $role,
);

/* Basic validation */
if ($fn === '' || $ln === '') {
    flash_add('err','Enter first and last name.');
    header('Location: /admin/staff_create.php'); exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash_add('err','Enter a valid email.');
    header('Location: /admin/staff_create.php'); exit;
}

/* Normalize role to known values */
$role = strtolower($role);
if (!in_array($role, array('admin','viewer'), true)) {
    flash_add('err','Invalid role selection.');
    header('Location: /admin/staff_create.php'); exit;
}

/* Check permissions: can current admin create this role? */
if (!can_create_role($me['role'], $role)) {
    flash_add('err','You are not allowed to create that role.');
    header('Location: /admin/staff_create.php'); exit;
}

try {
    $pdo = db();
    $pdo->beginTransaction();

    /* Ensure email is unique among admins */
    $chk = $pdo->prepare('SELECT id FROM admins WHERE email = ? LIMIT 1');
    $chk->execute(array($email));
    if ($chk->fetch()) {
        throw new Exception('An admin with that email already exists.');
    }

    /* Create admin row WITHOUT a usable password (invite-only flow) */
    $emptyHash = ''; // will be replaced once they set a password via reset flow

    // Match the existing column order inferred from prior code:
    // (email, password_hash, first_name, last_name, role, is_active, must_change_password)
    $stmt = $pdo->prepare(
        'INSERT INTO admins (email, password_hash, first_name, last_name, role, is_active, must_change_password)
         VALUES (?,?,?,?,?,1,1)'
    );
    $stmt->execute(array($email, $emptyHash, $fn, $ln, $role));
    $newId = (int)$pdo->lastInsertId();

    /* Generate a one-time reset code (same as admin/forgot flow) */
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $exp  = date('Y-m-d H:i:s', time() + 15*60); // 15 minutes

    $stmt2 = $pdo->prepare(
        'INSERT INTO admin_password_resets (admin_id, code, expires_at)
         VALUES (?, ?, ?)'
    );
    $stmt2->execute(array($newId, $code, $exp));

    $pdo->commit();

    /* Audit log */
    audit_log('admin.staff.create', array(
        'target_admin_id' => $newId,
        'details' => array(
            'email' => $email,
            'role'  => $role,
            'invited' => true,
        ),
    ));

    /* Send invitation email with the code.
       We prefer a dedicated invite email helper if present. */
    if (function_exists('send_admin_invite_email')) {
        @send_admin_invite_email($email, $code, $role);
    } elseif (function_exists('send_admin_reset_email')) {
        // Fallback: use existing reset email template.
        @send_admin_reset_email($email, $code);
    }

    unset($_SESSION['staff_create_prefill']);

    flash_add('ok',
        'Staff account created and an invitation email has been sent to '
        . $email
        . '. They can use the code in that email on the Admin Password Reset page to set their password.'
    );
    header('Location: /admin/staff_edit.php?id=' . $newId); exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash_add('err','Create failed: ' . $e->getMessage());
    header('Location: /admin/staff_create.php'); exit;
}
