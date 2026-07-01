<?php /* filename: reset_password.php */
require_once __DIR__ . '/include/security.php';
require_once __DIR__ . '/include/render.php';
coid_session_start(); security_headers();

page_head('Reset password — careofid'); page_nav(); page_flash();

$step = isset($_GET['step']) ? $_GET['step'] : 'request';
?>
<h1>Reset password</h1>

<?php if ($step === 'request') { ?>
  <div class="card">
    <p>Enter your account email. We’ll send a 6-digit code.</p>
    <form method="post" action="/actions/reset_request_post.php">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
      <label>Email</label>
      <input type="email" name="email" required>
      <div class="row" style="margin-top:10px;"><button type="submit">Send code</button></div>
    </form>
  </div>
<?php } else { ?>
  <div class="card">
    <p>Enter the code sent to your email and choose a new password.</p>
    <form method="post" action="/actions/reset_confirm_post.php">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
      <label>Email</label>
      <input type="email" name="email" required value="<?php echo isset($_GET['email'])?htmlspecialchars($_GET['email'],ENT_QUOTES,'UTF-8'):''; ?>">
      <label>Code</label>
      <input type="text" name="code" required minlength="6" maxlength="6" pattern="[0-9]{6}" placeholder="000000">
      <label>New password</label>
      <input type="password" name="password" required minlength="8">
      <div class="row" style="margin-top:10px;"><button type="submit">Reset password</button></div>
    </form>
  </div>
<?php } ?>

<?php page_foot(); ?>
