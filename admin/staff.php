<?php /* filename: admin/staff.php */
require_once __DIR__ . '/../include/security.php';
require_once __DIR__ . '/../include/render.php';
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/authz.php';

admin_session_start(); security_headers(); admin_require_login();
$me = admin_current();
/* Only admin and superadmin can view staff list */
require_admin_role('admin');

$q     = trim((string)($_GET['q'] ?? ''));
$roleF = trim((string)($_GET['role'] ?? '')); // '', 'editor','admin','superadmin'
$activeF = trim((string)($_GET['active'] ?? '')); // '', '1','0'
$page  = max(1, (int)($_GET['p'] ?? 1));
$limit = 20; $off = ($page - 1) * $limit;

$conds = ['1=1'];
$args = [];

if ($q !== '') {
  $like = '%'.$q.'%';
  $conds[] = '(a.email LIKE ? OR a.first_name LIKE ? OR a.last_name LIKE ?)';
  $args[] = $like; $args[] = $like; $args[] = $like;
}
if ($roleF !== '' && in_array($roleF, ['editor','admin','superadmin'], true)) {
  $conds[] = 'a.role = ?'; $args[] = $roleF;
}
if ($activeF !== '' && ($activeF === '0' || $activeF === '1')) {
  $conds[] = 'a.is_active = ?'; $args[] = (int)$activeF;
}

$where = 'WHERE '.implode(' AND ', $conds);

$st = db()->prepare("SELECT a.* FROM admins a {$where} ORDER BY a.id DESC LIMIT {$limit} OFFSET {$off}");
$st->execute($args);
$rows = $st->fetchAll();

$st2 = db()->prepare("SELECT COUNT(*) n FROM admins a {$where}");
$st2->execute($args);
$total = (int)($st2->fetch()['n'] ?? 0);
$pages = max(1, (int)ceil($total / $limit));

page_head('Admin — Staff'); page_nav(); page_flash();
?>
<div class="centercol">
  <div class="card">
    <h1>Staff (<?php echo (int)$total; ?>)</h1>

    <form method="get" action="/admin/staff.php" class="row" style="gap:8px; flex-wrap:wrap;">
      <input type="text" name="q" placeholder="Search name or email" value="<?php echo h($q); ?>">
      <select name="role">
        <option value="" <?php echo $roleF===''?'selected':''; ?>>All roles</option>
        <option value="editor" <?php echo $roleF==='editor'?'selected':''; ?>>Editor</option>
        <option value="admin" <?php echo $roleF==='admin'?'selected':''; ?>>Admin</option>
        <option value="superadmin" <?php echo $roleF==='superadmin'?'selected':''; ?>>Superadmin</option>
      </select>
      <select name="active">
        <option value="" <?php echo $activeF===''?'selected':''; ?>>All</option>
        <option value="1" <?php echo $activeF==='1'?'selected':''; ?>>Active</option>
        <option value="0" <?php echo $activeF==='0'?'selected':''; ?>>Inactive</option>
      </select>
      <button type="submit">Filter</button>
      <a class="btn" href="/admin/index.php">Back</a>
      <?php if (role_rank($me['role']) >= role_rank('admin')) { ?>
        <a class="btn" href="/admin/staff_create.php">Create staff</a>
      <?php } ?>
    </form>

    <?php if (!$rows) { ?>
      <p class="muted">No staff found.</p>
    <?php } else { ?>
      <div class="results">
        <?php foreach ($rows as $r) { ?>
          <div class="result-item rowline">
            <div>
              <div><strong><?php echo h($r['first_name'].' '.$r['last_name']); ?></strong>
                <span class="muted">· <?php echo h($r['email']); ?></span>
              </div>
              <div class="muted">Role: <?php echo h($r['role']); ?> · <?php echo $r['is_active']?'Active':'Inactive'; ?></div>
            </div>
            <div>
              <a class="btn" href="/admin/staff_edit.php?id=<?php echo (int)$r['id']; ?>">Edit</a>
            </div>
          </div>
        <?php } ?>
      </div>

      <div class="row" style="justify-content:center; gap:8px; margin-top:10px;">
        <?php for ($p=1; $p<=$pages; $p++) {
          $u = '/admin/staff.php?'.http_build_query(['q'=>$q,'role'=>$roleF,'active'=>$activeF,'p'=>$p]);
          echo '<a class="btn" href="'.h($u).'">'.$p.'</a>';
        } ?>
      </div>
    <?php } ?>
  </div>
</div>
<?php page_foot();
