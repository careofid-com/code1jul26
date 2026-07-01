<?php /* filename: login.php */
require_once __DIR__ . '/include/security.php';
require_once __DIR__ . '/include/render.php';
coid_session_start(); security_headers();

page_head('Login — careofid'); page_nav(); page_flash();
$next = isset($_GET['next']) ? $_GET['next'] : '/dashboard';
?>
<h1>Log in</h1>
<div class="card">
  <form method="post" action="/actions/login_post.php">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="next" value="<?php echo htmlspecialchars($next, ENT_QUOTES, 'UTF-8'); ?>">
    <label>Email</label>
    <input type="email" name="email" required>
    <label>Password</label>
    <input type="password" name="password" required autocomplete="current-password">
    <div class="row"><button type="submit">Login</button></div>
    <p style="margin-top:10px;"><a href="/reset_password">Reset password</a></p>
  </form>
</div>
<?php page_foot(); ?>
