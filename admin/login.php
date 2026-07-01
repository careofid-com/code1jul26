<?php /* filename: admin/login.php */
require_once __DIR__ . '/../include/security.php';
require_once __DIR__ . '/../include/render.php';
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/authz.php';

admin_session_start(); security_headers();
if (admin_current()) { header('Location: /admin/index.php'); exit; }

page_head('Admin — Login'); page_nav(); page_flash();
?>
<div class="centercol">
  <div class="card">
    <h1>Admin Login</h1>
    <form method="post" action="/admin/login_post.php">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8'); ?>">
      <label>Email</label>
      <input type="email" name="email" required>
      <label>Password</label>
      <input type="password" name="password" required>
      <div class="row" style="margin-top:10px;"><button type="submit">Login</button></div>
      <p style="margin-top:10px;"><a href="/admin/forgot.php">Forgot password?</a></p>
    </form>
  </div>
</div>
<?php page_foot();
