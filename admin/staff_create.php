<?php /* filename: admin/staff_create.php */
require_once __DIR__ . '/../include/security.php';
require_once __DIR__ . '/../include/render.php';
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/authz.php';

admin_session_start(); security_headers(); admin_require_login();
$me = admin_current();
/* Only admin+ can access; role options limited via can_create_role() */
require_admin_role('admin');

/* Determine which roles this admin is allowed to create */
$allowedRoles = array();
foreach (array('admin','viewer') as $r) {
    if (can_create_role($me['role'], $r)) {
        $allowedRoles[] = $r;
    }
}

/* Safety: if somehow no roles are allowed, block the page */
if (empty($allowedRoles)) {
    http_response_code(403);
    echo 'You are not allowed to create staff accounts.';
    exit;
}

/* Simple helpers to preserve submitted values on validation errors (optional) */
$prefill_fn   = isset($_SESSION['staff_create_prefill']['first_name'])
    ? (string)$_SESSION['staff_create_prefill']['first_name'] : '';
$prefill_ln   = isset($_SESSION['staff_create_prefill']['last_name'])
    ? (string)$_SESSION['staff_create_prefill']['last_name'] : '';
$prefill_email= isset($_SESSION['staff_create_prefill']['email'])
    ? (string)$_SESSION['staff_create_prefill']['email'] : '';
$prefill_role = isset($_SESSION['staff_create_prefill']['role'])
    ? (string)$_SESSION['staff_create_prefill']['role'] : '';

unset($_SESSION['staff_create_prefill']);

page_head('Admin — Create Staff'); page_nav(); page_flash();
?>
<div class="centercol">
  <div class="card">
    <h1>Create Staff</h1>

    <p class="muted" style="margin-bottom:12px;">
      Create an <strong>Admin</strong> or <strong>Viewer</strong> account. The new staff member
      will receive an email with a one-time code so they can set their own password using the
      “Admin Password Reset” page. No temporary password is shown here.
    </p>

    <form method="post" action="/admin/staff_create_post.php">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8'); ?>">

      <label>First name</label>
      <input type="text" name="first_name" required
             value="<?php echo htmlspecialchars($prefill_fn,ENT_QUOTES,'UTF-8'); ?>">

      <label>Last name</label>
      <input type="text" name="last_name" required
             value="<?php echo htmlspecialchars($prefill_ln,ENT_QUOTES,'UTF-8'); ?>">

      <label>Email</label>
      <input type="email" name="email" required
             value="<?php echo htmlspecialchars($prefill_email,ENT_QUOTES,'UTF-8'); ?>">

      <label>Role</label>
      <select name="role" required>
        <?php
        foreach ($allowedRoles as $r) {
            $label = ($r === 'admin') ? 'Admin' : 'Viewer';
            $sel = ($prefill_role === $r) ? 'selected' : '';
            echo '<option value="'.htmlspecialchars($r,ENT_QUOTES,'UTF-8').'" '.$sel.'>'
               . htmlspecialchars($label,ENT_QUOTES,'UTF-8')
               . '</option>';
        }
        ?>
      </select>

      <p class="muted" style="margin-top:8px;">
        After creation, an email with a verification code will be sent to this address.
        The staff member should open <code>/admin/forgot.php</code>, choose
        “Enter code &amp; new password”, and use that code to set their password.
      </p>

      <div class="row" style="gap:8px; margin-top:12px;">
        <button type="submit">Create staff</button>
        <a class="btn" href="/admin/staff.php">Cancel</a>
      </div>
    </form>
  </div>
</div>
<?php page_foot();
