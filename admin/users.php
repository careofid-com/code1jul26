<?php /* filename: admin/users.php */
require_once __DIR__ . '/../include/security.php';
require_once __DIR__ . '/../include/render.php';
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/authz.php';

admin_session_start();
security_headers();
admin_require_login();
$me = admin_current();

$masked = isset($_GET['masked']) ? (string)$_GET['masked'] : ''; // '', '1', '0'
$q      = trim((string)($_GET['q'] ?? ''));
$page   = max(1, (int)($_GET['p'] ?? 1));
$limit  = 20;
$off    = ($page - 1) * $limit;

// Only show non-deleted users; email may be NULL (admin-created placeholders)
$conds = array('u.deleted_at IS NULL');
$args  = array();

if ($masked === '1') {
    $conds[] = 'c.is_masked = 1';
} elseif ($masked === '0') {
    $conds[] = 'c.is_masked = 0';
}

if ($q !== '') {
    $like = '%' . $q . '%';
    $conds[] = '(u.email LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR c.coid LIKE ?)';
    $args[] = $like;
    $args[] = $like;
    $args[] = $like;
    $args[] = $like;
}

$where = $conds ? ('WHERE ' . implode(' AND ', $conds)) : '';

$sql = "
SELECT u.id,
       u.email,
       u.first_name,
       u.last_name,
       c.coid,
       c.is_masked,
       u.is_verified
FROM users u
LEFT JOIN coids c ON c.user_id = u.id
{$where}
ORDER BY u.id DESC
LIMIT {$limit} OFFSET {$off}";
$st = db()->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll();

$st2 = db()->prepare("
    SELECT COUNT(DISTINCT u.id) AS n
    FROM users u
    LEFT JOIN coids c ON c.user_id = u.id
    {$where}
");
$st2->execute($args);
$total = (int)($st2->fetch()['n'] ?? 0);
$pages = max(1, (int)ceil($total / $limit));

page_head('Admin — Users');
page_nav();
page_flash();
?>
<div class="centercol">
  <div class="card">
    <h1>Users (<?php echo (int)$total; ?>)</h1>

    <form method="get" action="/admin/users.php" class="row" style="gap:8px; flex-wrap:wrap;">
      <input type="text" name="q" placeholder="Search email, name, COID" value="<?php echo h($q); ?>">
      <select name="masked">
        <option value="" <?php echo $masked===''?'selected':''; ?>>All</option>
        <option value="1" <?php echo $masked==='1'?'selected':''; ?>>Masked only</option>
        <option value="0" <?php echo $masked==='0'?'selected':''; ?>>Unmasked only</option>
      </select>
      <button type="submit">Filter</button>
      <a class="btn" href="/admin/index.php">Back</a>
    </form>

    <?php if (!$rows) { ?>
      <p class="muted">No users found.</p>
    <?php } else { ?>
      <div class="results">
        <?php foreach ($rows as $r) {
            $email = $r['email'];
            $emailLabel = $email;
            if ($email === null || $email === '' || strtolower($email) === 'null') {
                $emailLabel = '(no email / placeholder)';
            }
        ?>
          <div class="result-item rowline">
            <div>
              <div>
                <strong><?php echo h($r['first_name'] . ' ' . $r['last_name']); ?></strong>
                <span class="muted">
                  · <?php echo h($emailLabel); ?>
                  · /<?php echo h($r['coid'] ?? '—'); ?>
                </span>
              </div>
              <div class="muted">
                <?php echo ($r['is_verified'] ? 'Verified' : 'Unverified'); ?> ·
                <?php echo ((int)$r['is_masked'] ? 'Masked' : 'Unmasked'); ?>
              </div>
            </div>
            <div>
              <a class="btn" href="/admin/user_edit.php?id=<?php echo (int)$r['id']; ?>">Edit</a>
            </div>
          </div>
        <?php } ?>
      </div>

      <div class="row" style="justify-content:center; gap:8px; margin-top:10px;">
        <?php for ($p = 1; $p <= $pages; $p++) {
          $u = '/admin/users.php?' . http_build_query(array(
              'q'      => $q,
              'masked' => $masked,
              'p'      => $p,
          ));
          echo '<a class="btn" href="' . h($u) . '">' . $p . '</a>';
        } ?>
      </div>
    <?php } ?>
  </div>
</div>
<?php page_foot();
