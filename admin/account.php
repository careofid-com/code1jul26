<?php /* filename: admin/account.php */
require_once __DIR__ . '/../include/security.php';
require_once __DIR__ . '/../include/render.php';
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/authz.php';

admin_session_start();
security_headers();
admin_require_login();

$me = admin_current();

page_head('Admin — My Account');
page_nav();
page_flash();
?>
<div class="centercol">
  <div class="card">

    <!-- ✅ Back button -->
    <div style="margin-bottom:10px;">
      <a href="/admin/index.php"
         style="display:inline-block;padding:8px 12px;border-radius:8px;border:1px solid #bbb;text-decoration:none;background:#f7f7f7;color:#111;">
        &larr; Back
      </a>
    </div>

    <h1>My Account</h1>
    <p><strong><?php echo htmlspecialchars($me['first_name'].' '.$me['last_name'], ENT_QUOTES, 'UTF-8'); ?></strong><br>
       <?php echo htmlspecialchars($me['email'], ENT_QUOTES, 'UTF-8'); ?><br>
       Role: <?php echo htmlspecialchars($me['role'], ENT_QUOTES, 'UTF-8'); ?></p>

    <h2>Change Password</h2>
    <form method="post" action="/admin/change_password_post.php">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
      <label>Current password</label>
      <input type="password" name="current" required>
      <label>New password</label>
      <input type="password" name="newpass" required minlength="8">
      <div class="row" style="margin-top:10px;"><button type="submit">Update password</button></div>
    </form>

  </div>
</div>
<?php page_foot();
