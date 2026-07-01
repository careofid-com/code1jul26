<?php /* filename: verify.php */
require_once __DIR__ . '/include/security.php';
require_once __DIR__ . '/include/render.php';
require_once __DIR__ . '/include/db.php';

coid_session_start();
security_headers();

$pdo = db();

$next = isset($_GET['next']) ? (string)$_GET['next'] : '/dashboard';
if ($next === '' || preg_match('~^(https?:)?//~i', $next) || strpos($next, "\n") !== false || strpos($next, "\r") !== false) {
  $next = '/dashboard';
}
if ($next[0] !== '/') $next = '/' . ltrim($next, '/');

/* Try to get current user from session (support both styles) */
$userId = 0;
if (!empty($_SESSION['user_id'])) $userId = (int)$_SESSION['user_id'];
elseif (!empty($_SESSION['user']['id'])) $userId = (int)$_SESSION['user']['id'];

$emailPrefill = '';
$isVerified = 0;

if ($userId > 0) {
  $st = $pdo->prepare('SELECT email, is_verified FROM users WHERE id=? LIMIT 1');
  $st->execute([$userId]);
  $me = $st->fetch(PDO::FETCH_ASSOC);
  if ($me) {
    $emailPrefill = (string)($me['email'] ?? '');
    $isVerified = (int)($me['is_verified'] ?? 0);
  }
}

page_head('Verify email — careofid');
page_nav();
page_flash();
?>
<div class="centercol">
  <div class="card">
    <h1>Verify your email</h1>

    <?php if ($isVerified === 1): ?>
      <p class="muted">Your email is already verified.</p>
      <div class="row" style="gap:8px;margin-top:12px;">
        <a class="btn" href="<?php echo htmlspecialchars($next, ENT_QUOTES, 'UTF-8'); ?>">Continue</a>
      </div>
    <?php else: ?>
      <p class="muted">
        Enter the 6-digit code sent to your email.
        <?php if ($emailPrefill !== ''): ?>
          <br>We think your email is: <strong><?php echo htmlspecialchars($emailPrefill, ENT_QUOTES, 'UTF-8'); ?></strong>
        <?php endif; ?>
      </p>

      <form method="post" action="/actions/verify_post.php">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="next" value="<?php echo htmlspecialchars($next, ENT_QUOTES, 'UTF-8'); ?>">

        <label>Email</label>
        <input type="email" name="email" required
               value="<?php echo htmlspecialchars($emailPrefill, ENT_QUOTES, 'UTF-8'); ?>">

        <label>Verification code</label>
        <input type="text" name="code" required inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="123456">

        <div class="row" style="gap:8px;margin-top:12px;">
          <button type="submit">Verify</button>
          <a class="btn" href="/login.php?next=<?php echo urlencode($next); ?>">Log in</a>
        </div>
      </form>
    <?php endif; ?>

  </div>
</div>
<?php page_foot(); ?>
