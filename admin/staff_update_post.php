<?php /* filename: admin/staff_update_post.php */
require_once __DIR__ . '/../include/security.php';
require_once __DIR__ . '/../include/render.php';
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/authz.php';

admin_session_start();
security_headers();
admin_require_login();
strict_post_only();

$me = admin_current();
require_admin_role('admin');
if (!csrf_verify($_POST['csrf'] ?? '')) {
    http_response_code(400);
    exit('Bad CSRF');
}

$id      = (int)($_POST['id'] ?? 0);
$fn      = trim((string)($_POST['first_name'] ?? ''));
$ln      = trim((string)($_POST['last_name'] ?? ''));
$email   = trim((string)($_POST['email'] ?? ''));
$newRole = trim((string)($_POST['role'] ?? ''));
$isActive= (int)($_POST['is_active'] ?? 1);

if ($id <= 0) {
    flash_add('err', 'Bad staff id.');
    header('Location: /admin/staff.php');
    exit;
}

/* Basic validation */
if ($fn === '' || $ln === '') {
    flash_add('err','Enter first and last name.');
    header('Location: /admin/staff_edit.php?id=' . $id);
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash_add('err','Enter a valid email.');
    header('Location: /admin/staff_edit.php?id=' . $id);
    exit;
}

/* Normalize role */
$newRole = strtolower($newRole);
if ($newRole === 'editor') {
    // Collapse legacy 'editor' into 'viewer'
    $newRole = 'viewer';
}

/* Load target admin row */
$pdo = db();
$st = $pdo->prepare('SELECT * FROM admins WHERE id = ? LIMIT 1');
$st->execute(array($id));
$tgt = $st->fetch(PDO::FETCH_ASSOC);

if (!$tgt) {
    flash_add('err','Staff not found.');
    header('Location: /admin/staff.php');
    exit;
}

/* Safeguard: can this actor edit this target at all? */
if (!can_edit_admin($me, $tgt)) {
    http_response_code(403);
    flash_add('err','You are not allowed to modify this staff member.');
    header('Location: /admin/staff.php');
    exit;
}

$currentRole = strtolower($tgt['role']);
if ($currentRole === 'editor') {
    $currentRole = 'viewer';
}

/* Role change rules */
if ($currentRole === 'superadmin') {
    // Superadmin role is immutable from UI
    $newRole = 'superadmin';
} else {
    // For non-superadmin targets:
    if (!in_array($newRole, array('viewer','admin'), true)) {
        // If UI somehow sends an invalid role, keep the current one
        $newRole = $currentRole;
    }

    // If changing role (not just keeping the same)
    if ($newRole !== $currentRole) {
        // Cannot change your own role for safety
        if ((int)$me['id'] === (int)$tgt['id']) {
            flash_add('err','You cannot change your own role.');
            header('Location: /admin/staff_edit.php?id=' . $id);
            exit;
        }

        // Check whether actor is allowed to assign this new role
        if (!can_create_role($me['role'], $newRole)) {
            flash_add('err','You are not allowed to assign that role.');
            header('Location: /admin/staff_edit.php?id=' . $id);
            exit;
        }
    }
}

/* Prevent deactivating superadmin */
if ($currentRole === 'superadmin' && $isActive === 0) {
    flash_add('err','You cannot deactivate the superadmin account.');
    header('Location: /admin/staff_edit.php?id=' . $id);
    exit;
}

/* Ensure email is unique among admins (excluding this id) */
$chk = $pdo->prepare('SELECT id FROM admins WHERE email = ? AND id <> ? LIMIT 1');
$chk->execute(array($email, $id));
if ($chk->fetch()) {
    flash_add('err','Another staff member already uses that email.');
    header('Location: /admin/staff_edit.php?id=' . $id);
    exit;
}

try {
    $pdo->beginTransaction();

    $upd = $pdo->prepare(
        'UPDATE admins
            SET first_name = ?, last_name = ?, email = ?, role = ?, is_active = ?, updated_at = NOW()
          WHERE id = ?'
    );
    $upd->execute(array($fn, $ln, $email, $newRole, $isActive, $id));

    $pdo->commit();

    audit_log('admin.staff.update', array(
        'target_admin_id' => $id,
        'details' => array(
            'email_from'   => $tgt['email'],
            'email_to'     => $email,
            'role_from'    => $tgt['role'],
            'role_to'      => $newRole,
            'is_active'    => $isActive,
        ),
    ));

    flash_add('ok','Staff updated.');
    header('Location: /admin/staff_edit.php?id=' . $id);
    exit;

} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash_add('err','Update failed: ' . $e->getMessage());
    header('Location: /admin/staff_edit.php?id=' . $id);
    exit;
}
