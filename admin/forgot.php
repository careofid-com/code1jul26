<?php /* filename: admin/forgot.php */
require_once __DIR__ . '/../include/security.php';
require_once __DIR__ . '/../include/render.php';
require_once __DIR__ . '/../include/authz.php';

admin_session_start(); security_headers();
page_head('Admin — Reset Password'); page_nav(); page_flash();

$step = $_GET['step'] ?? 'request';
$emailPrefill = htmlspecialchars($_GET['email'] ?? '', ENT_QUOTES, 'UTF-8');
?>
<div class="centercol">
  <div class="card">
    <h1>Admin Password Reset</h1>

    <?php if ($step === 'request') { ?>
      <p>Enter your admin email. We’ll send a 6-digit code.</p>
      <form method="post" action="/admin/reset_request_post.php">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8'); ?>">
        <label>Email</label>
        <input type="email" name="email" required>
        <div class="row" style="margin-top:10px;">
          <button type="submit">Send code</button>
        </div>
      </form>
    <?php } else { ?>
      <p>Enter the 6-digit code sent to your email and choose a new password.</p>
      <form method="post" action="/admin/reset_confirm_post.php">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8'); ?>">
        <label>Email</label>
        <input type="email" name="email" required value="<?php echo $emailPrefill; ?>">
        <label>Code</label>
        <input type="text" name="code" required minlength="6" maxlength="6" pattern="[0-9]{6}" placeholder="000000">
        <label>New password</label>
        <input type="password" name="password" required minlength="8">
        <div class="row" style="margin-top:10px;">
          <button type="submit">Reset password</button>
        </div>
      </form>
    <?php } ?>
  </div>
</div>
<?php page_foot();
