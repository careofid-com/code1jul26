<?php /* filename: billing_success.php */
require_once __DIR__ . '/include/security.php';
require_once __DIR__ . '/include/render.php';
require_once __DIR__ . '/include/auth.php';

coid_session_start();
security_headers();

/**
 * Get an optional logged-in user WITHOUT fatal errors.
 * - If logged in, try to reload from DB (auth_reload_user()).
 * - Otherwise, fall back to session user snapshot if your auth stores it.
 * - If nothing, return null (page still works).
 */
$user = null;
try {
  // Many pages in your system use auth_require_login(); here we do NOT force login.
  // If your auth layer uses $_SESSION['user_id'], this is the safest optional check.
  if (!empty($_SESSION['user_id'])) {
    $u = auth_reload_user(); // should return user array (or null if not found)
    if (is_array($u) && !empty($u['id'])) {
      $user = $u;
    }
  }
} catch (Throwable $e) {
  // swallow any auth/db errors; this page must still render
  $user = null;
}

// Fallback: some older flows store user array directly in session
if ($user === null && isset($_SESSION['user']) && is_array($_SESSION['user'])) {
  $user = $_SESSION['user'];
}

$email = '';
if (is_array($user) && !empty($user['email'])) {
  $email = (string)$user['email'];
}

page_head('Payment successful — careofid');
page_nav();
page_flash();
?>

<style>
  /* Use your existing center-column system */
  .centercol { width: var(--content-width); max-width: 640px; margin: 18px auto; }

  .card-success {
    border:1px solid #d1fae5;
    background:#ecfdf5;
    border-radius:12px;
    padding:16px;
    margin-top:14px;
  }
  .card-success h1 {
    margin-top:0;
    font-size:20px;
    color:#065f46;
  }
  .card-success p {
    font-size:14px;
    color:#064e3b;
    margin:6px 0;
  }

  .card-muted {
    border:1px solid #e5e7eb;
    background:#f9fafb;
    border-radius:10px;
    padding:12px;
    margin-top:10px;
    font-size:13px;
    color:#4b5563;
  }

  @media (max-width:600px){
    .card-success h1 { font-size:18px; }
    .card-success p, .card-muted { font-size:12px; }
  }
</style>

<div class="centercol">
  <div class="card-success">
    <h1>Thank you — payment successful</h1>
    <p>Your one-time payment has been received by Stripe.</p>

    <?php if ($email !== ''): ?>
      <p>Account email: <strong><?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?></strong></p>
    <?php endif; ?>

    <p>
      Your CareOfID account has been upgraded to the <strong>Paid</strong> plan
      (up to 20 providers).
    </p>
  </div>

  <div class="card-muted">
    <p>
      If your plan does not show as <strong>Paid</strong> on the
      <a href="/handles">Update handles</a> or <a href="/dashboard.php">Dashboard</a>
      within a few minutes, please refresh the page.
    </p>
    <p>
      If it still shows as Free after some time, please contact us and mention this email address
      so we can manually verify and upgrade your account.
    </p>
  </div>

  <div class="card-muted" style="margin-top:10px;">
    <p style="margin-bottom:6px;">
      <a class="btn" href="/handles">Go to Update handles</a>
    </p>
    <p>
      or visit your <a href="/dashboard.php">Dashboard</a>.
    </p>
  </div>
</div>

<?php
page_foot();
