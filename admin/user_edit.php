<?php /* filename: admin/user_edit.php */
require_once __DIR__ . '/../include/security.php';
require_once __DIR__ . '/../include/render.php';
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/authz.php';

admin_session_start(); security_headers(); admin_require_login();
$me = admin_current();

$user_id = (int)($_GET['id'] ?? 0);
if ($user_id <= 0) { http_response_code(400); exit('Bad id'); }

$pdo = db();

$st = $pdo->prepare('SELECT u.*, c.id AS coid_id, c.coid, c.is_masked
                     FROM users u LEFT JOIN coids c ON c.user_id = u.id
                     WHERE u.id = ? LIMIT 1');
$st->execute([$user_id]);
$u = $st->fetch();
if (!$u) { http_response_code(404); exit('User not found'); }

$stp = $pdo->prepare('SELECT p.id, p.slug, p.display, p.url_pattern, uph.handle
                      FROM providers p
                      LEFT JOIN user_provider_handles uph ON uph.provider_id = p.id AND uph.coid_id = ?
                      ORDER BY p.display ASC');
$stp->execute([(int)($u['coid_id'] ?? 0)]);
$providers = $stp->fetchAll();

$role = $me['role'];
$can_edit_admin = ($role === 'admin' || $role === 'superadmin');
$can_edit_super = ($role === 'superadmin');

page_head('Admin — Edit User'); page_nav(); page_flash();
?>
<div class="centercol">
  <div class="card">
    <h1>Edit User</h1>
    <p><strong>/<?php echo h($u['coid'] ?? '—'); ?></strong> · <?php echo h($u['email']); ?></p>

    <form method="post" action="/admin/user_update_post.php">
      <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
      <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">

      <h3>Identity</h3>
      <label>First name</label>
      <input type="text" name="first_name" value="<?php echo h($u['first_name']); ?>" required>
      <label>Last name</label>
      <input type="text" name="last_name" value="<?php echo h($u['last_name']); ?>" required>

      <h3>Account</h3>
      <label>Email</label>
      <input type="email" name="email" value="<?php echo h($u['email']); ?>" <?php echo $can_edit_admin ? '' : 'disabled'; ?>>
      <label>COID</label>
      <input type="text" name="coid" value="<?php echo h($u['coid'] ?? ''); ?>" <?php echo $can_edit_admin ? '' : 'disabled'; ?>>

      <label>Masked (hide from public)</label>
      <select name="is_masked" <?php echo $can_edit_admin ? '' : 'disabled'; ?>>
        <option value="0" <?php echo (int)$u['is_masked']===0?'selected':''; ?>>No</option>
        <option value="1" <?php echo (int)$u['is_masked']===1?'selected':''; ?>>Yes</option>
      </select>

      <h3>Handles</h3>
      <p class="muted">Leave blank to clear a handle.</p>
      <?php foreach ($providers as $p) { ?>
        <label><?php echo h($p['display']); ?></label>
        <input type="text" name="handles[<?php echo (int)$p['id']; ?>]" value="<?php echo h($p['handle'] ?? ''); ?>">
      <?php } ?>

      <div class="row" style="gap:8px; margin-top:12px;">
        <button type="submit">Save</button>
        <a class="btn" href="/admin/users.php">Back</a>
        <?php if ($can_edit_super) { ?>
          <form method="post" action="/admin/user_delete_post.php" style="display:inline;">
            <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
            <button type="submit" onclick="return confirm('Delete user (soft delete)?');">Delete User</button>
          </form>
        <?php } ?>
      </div>
    </form>
  </div>
</div>
<?php page_foot();
