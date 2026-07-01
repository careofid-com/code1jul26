<?php /* filename: admin/staff_edit.php */
require_once __DIR__ . '/../include/security.php';
require_once __DIR__ . '/../include/render.php';
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/authz.php';

admin_session_start();
security_headers();
admin_require_login();
$me = admin_current();
require_admin_role('admin'); // only admin + superadmin may edit staff

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('Bad id');
}

$pdo = db();
$st = $pdo->prepare('SELECT * FROM admins WHERE id = ? LIMIT 1');
$st->execute(array($id));
$adm = $st->fetch(PDO::FETCH_ASSOC);

if (!$adm) {
    http_response_code(404);
    exit('Not found');
}

/* Safeguard: cannot edit someone you are not allowed to */
if (!can_edit_admin($me, $adm)) {
    http_response_code(403);
    exit('Forbidden');
}

/* Effective role (treat legacy editor as viewer) */
$currentRole = strtolower($adm['role']);
if ($currentRole === 'editor') {
    $currentRole = 'viewer';
}

/*
 * Build role options:
 *  - We show roles the actor can assign via can_create_role()
 *  - plus the target's current role so you can keep it as-is
 *  - We never offer "superadmin" as a change target.
 */
$roleOptions = array();
$allRoles = array('viewer', 'admin'); // superadmin cannot be newly assigned via UI

foreach ($allRoles as $r) {
    if (can_create_role($me['role'], $r) || $r === $currentRole) {
        $roleOptions[] = $r;
    }
}

/* If target is superadmin, force roleOptions to only 'superadmin' (display-only) */
if (strtolower($adm['role']) === 'superadmin') {
    $currentRole = 'superadmin';
    $roleOptions = array('superadmin');
}

page_head('Admin — Edit Staff');
page_nav();
page_flash();
?>
<div class="centercol">
  <div class="card">
    <h1>Edit Staff</h1>

    <p>
      <strong><?php echo h($adm['first_name'] . ' ' . $adm['last_name']); ?></strong><br>
      <?php echo h($adm['email']); ?>
      · Role: <?php echo h($adm['role']); ?>
      · <?php echo $adm['is_active'] ? 'Active' : 'Inactive'; ?>
    </p>

    <form method="post" action="/admin/staff_update_post.php">
      <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
      <input type="hidden" name="id" value="<?php echo (int)$adm['id']; ?>">

      <h3>Identity</h3>
      <label>First name</label>
      <input type="text" name="first_name" value="<?php echo h($adm['first_name']); ?>" required>

      <label>Last name</label>
      <input type="text" name="last_name" value="<?php echo h($adm['last_name']); ?>" required>

      <h3>Account</h3>
      <label>Email</label>
      <input type="email" name="email" value="<?php echo h($adm['email']); ?>" required>

      <label>Role</label>
      <select name="role" <?php echo (strtolower($adm['role']) === 'superadmin') ? 'disabled' : ''; ?>>
        <?php foreach ($roleOptions as $r): ?>
          <?php
            $label = ($r === 'admin') ? 'Admin' :
                     (($r === 'viewer') ? 'Viewer' : ucfirst($r));
            $sel = ($r === $currentRole) ? 'selected' : '';
          ?>
          <option value="<?php echo h($r); ?>" <?php echo $sel; ?>>
            <?php echo h($label); ?>
          </option>
        <?php endforeach; ?>
      </select>
      <?php if (strtolower($adm['role']) === 'superadmin'): ?>
        <p class="muted" style="margin-top:4px;">
          Superadmin role cannot be changed from this screen.
        </p>
      <?php endif; ?>

      <label>Status</label>
      <select name="is_active">
        <option value="1" <?php echo $adm['is_active'] ? 'selected' : ''; ?>>Active</option>
        <option value="0" <?php echo !$adm['is_active'] ? 'selected' : ''; ?>>Inactive</option>
      </select>

      <p class="muted" style="margin-top:8px;">
        Safeguards:
        <br>- You cannot edit someone with an equal or higher role than yourself.
        <br>- Only the original superadmin account can remain superadmin.
        <br>- You cannot deactivate the superadmin account from here.
      </p>

      <div class="row" style="gap:8px; margin-top:12px;">
        <button type="submit">Save</button>
        <a class="btn" href="/admin/staff.php">Back</a>
      </div>
    </form>
  </div>
</div>
<?php page_foot();
