<?php /* filename: admin/index.php */
require_once __DIR__ . '/../include/security.php';
require_once __DIR__ . '/../include/render.php';
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/authz.php';

admin_session_start();
security_headers();
admin_require_login();
$me = admin_current();

page_head('Admin — Dashboard');
page_nav();
page_flash();
?>
<div class="centercol">
  <div class="card">
    <h1>Admin Dashboard</h1>
    <p>Welcome, <?php echo htmlspecialchars($me['first_name'], ENT_QUOTES, 'UTF-8'); ?>
      (<?php echo htmlspecialchars($me['role'], ENT_QUOTES, 'UTF-8'); ?>)</p>
    <p>
      <a class="btn" href="/admin/users.php">Users</a>
      <?php if (role_rank($me['role']) >= role_rank('admin')) { ?>
        <a class="btn" href="/admin/staff.php">Staff</a>
        <a class="btn" href="/admin/coid_claims.php">COID claims</a>
        <a class="btn" href="/admin/coid_new.php">Create COID</a>
      <?php } ?>
      <a class="btn" href="/admin/account.php">My account</a>
      <a class="btn" href="/admin/logout.php">Logout</a>
    </p>
  </div>
</div>
<?php page_foot();
