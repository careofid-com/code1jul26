<?php
// /admin/users_no_coid.php
// Report: Verified users with NO COID (cleanup candidates)
// PHP 7.x, PDO, no composer

require_once __DIR__ . '/../include/security.php';
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/csrf.php';
require_once __DIR__ . '/../include/authz.php';



// ---- Helpers ----
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$pdo = db();

// timeframe filter
// q=24h | 7d | all
$q = $_GET['q'] ?? '24h';
if (!in_array($q, ['24h','7d','all'], true)) $q = '24h';

$whereTime = '';
$params = [];

if ($q === '24h') {
  $whereTime = " AND u.created_at >= (NOW() - INTERVAL 24 HOUR) ";
} elseif ($q === '7d') {
  $whereTime = " AND u.created_at >= (NOW() - INTERVAL 7 DAY) ";
}

// Bulk delete older-than-24h
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_delete_24h') {
  if (!csrf_verify($_POST['csrf'] ?? null)) {
    $flash = 'CSRF check failed.';
  } else {
    // delete ONLY verified + no coid + older than 24h
    // Hard delete: remove related rows first (minimal safe set)
    $pdo->beginTransaction();
    try {
      // Find candidate user IDs
      $st = $pdo->prepare("
        SELECT u.id
        FROM users u
        LEFT JOIN coids c ON c.user_id = u.id
        WHERE u.deleted_at IS NULL
          AND u.role = 'user'
          AND u.is_verified = 1
          AND u.email IS NOT NULL
          AND c.id IS NULL
          AND u.created_at < (NOW() - INTERVAL 24 HOUR)
        ORDER BY u.created_at ASC
        LIMIT 500
      ");
      $st->execute();
      $ids = $st->fetchAll(PDO::FETCH_COLUMN);

      if (!$ids) {
        $pdo->commit();
        $flash = 'No users older than 24 hours found to delete.';
      } else {
        // Delete related rows (safe)
        $in = implode(',', array_fill(0, count($ids), '?'));

        $pdo->prepare("DELETE FROM coid_claims WHERE user_id IN ($in)")->execute($ids);
        $pdo->prepare("DELETE FROM password_resets WHERE user_id IN ($in)")->execute($ids);
        $pdo->prepare("DELETE FROM email_verifications WHERE user_id IN ($in)")->execute($ids);

        // Best-effort: remove audit logs that target these users (optional)
        // If you want to keep audit logs, comment this out.
        $pdo->prepare("DELETE FROM audit_logs WHERE target_user_id IN ($in)")->execute($ids);

        // Delete users
        $pdo->prepare("DELETE FROM users WHERE id IN ($in)")->execute($ids);

        $pdo->commit();
        $flash = 'Deleted ' . count($ids) . ' user(s) older than 24 hours with no COID.';
      }
    } catch (Throwable $e) {
      $pdo->rollBack();
      $flash = 'Bulk delete failed: ' . $e->getMessage();
    }
  }
}

// Single delete (hard delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_one') {
  $uid = (int)($_POST['user_id'] ?? 0);
  if ($uid <= 0) {
    $flash = 'Invalid user.';
  } elseif (!csrf_verify($_POST['csrf'] ?? null)) {
    $flash = 'CSRF check failed.';
  } else {
    // Ensure user qualifies: verified + no coid
    $st = $pdo->prepare("
      SELECT u.id, u.dp_path
      FROM users u
      LEFT JOIN coids c ON c.user_id = u.id
      WHERE u.id = ?
        AND u.deleted_at IS NULL
        AND u.role = 'user'
        AND u.is_verified = 1
        AND u.email IS NOT NULL
        AND c.id IS NULL
      LIMIT 1
    ");
    $st->execute([$uid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
      $flash = 'User not eligible (has COID, not verified, or not found).';
    } else {
      $pdo->beginTransaction();
      try {
        $pdo->prepare("DELETE FROM coid_claims WHERE user_id = ?")->execute([$uid]);
        $pdo->prepare("DELETE FROM password_resets WHERE user_id = ?")->execute([$uid]);
        $pdo->prepare("DELETE FROM email_verifications WHERE user_id = ?")->execute([$uid]);
        $pdo->prepare("DELETE FROM audit_logs WHERE target_user_id = ?")->execute([$uid]);
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$uid]);

        $pdo->commit();

        // Best-effort DP delete (only if it looks like your uploads path)
        $dp = $row['dp_path'] ?? '';
        if ($dp && strpos($dp, '..') === false) {
          $abs = realpath(__DIR__ . '/..') . '/' . ltrim($dp, '/');
          if ($abs && file_exists($abs)) @unlink($abs);
        }

        $flash = 'User deleted.';
      } catch (Throwable $e) {
        $pdo->rollBack();
        $flash = 'Delete failed: ' . $e->getMessage();
      }
    }
  }
}

// Load report rows
$sql = "
  SELECT
    u.id,
    u.email,
    u.first_name,
    u.last_name,
    u.created_at,
    TIMESTAMPDIFF(HOUR, u.created_at, NOW()) AS age_hours
  FROM users u
  LEFT JOIN coids c ON c.user_id = u.id
  WHERE u.deleted_at IS NULL
    AND u.role = 'user'
    AND u.is_verified = 1
    AND u.email IS NOT NULL
    AND c.id IS NULL
    $whereTime
  ORDER BY u.created_at DESC
  LIMIT 500
";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$csrf = csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Users Verified But No COID</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="../assets/site.css">
  <style>
    /* tiny local safety (ok if you prefer moving to shared CSS) */
    .pill { display:inline-block; padding:2px 8px; border-radius:999px; font-size:12px; }
    .pill.bad { background:#fee2e2; }
    .pill.ok { background:#e5e7eb; }
    .btn-danger { background:#b91c1c; color:#fff; border:none; padding:8px 12px; border-radius:10px; cursor:pointer; }
    .btn-ghost { background:transparent; border:1px solid #d1d5db; padding:8px 12px; border-radius:10px; cursor:pointer; }
    table { width:100%; border-collapse:collapse; }
    th, td { padding:10px; border-bottom:1px solid #eee; text-align:left; }
    .topbar { display:flex; gap:10px; flex-wrap:wrap; align-items:center; justify-content:space-between; }
  </style>
</head>
<body>

<div class="page">
  <h1>Verified Users With No COID</h1>
  <p style="margin-top:6px;">
    These are verified accounts that never created a COID. (Limit 500 rows)
  </p>

  <?php if ($flash): ?>
    <div class="notice" style="margin:12px 0; padding:10px; border:1px solid #ddd; border-radius:10px;">
      <?= h($flash) ?>
    </div>
  <?php endif; ?>

  <div class="topbar" style="margin:14px 0;">
    <div>
      <a class="btn-ghost" href="?q=24h">Last 24h</a>
      <a class="btn-ghost" href="?q=7d">Last 7 days</a>
      <a class="btn-ghost" href="?q=all">All</a>
    </div>

    <form method="post" onsubmit="return confirm('Delete ALL verified users with NO COID older than 24 hours? This is permanent.');">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="bulk_delete_24h">
      <button class="btn-danger" type="submit">Bulk Delete &gt; 24h</button>
    </form>
  </div>

  <div style="overflow:auto;">
    <table>
      <thead>
        <tr>
          <th>User</th>
          <th>Email</th>
          <th>Created</th>
          <th>Age (hrs)</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="6">No rows found.</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $r): ?>
          <?php $old = ((int)$r['age_hours'] >= 24); ?>
          <tr>
            <td>
              #<?= (int)$r['id'] ?>
              <?= h(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''))) ?>
            </td>
            <td><?= h($r['email']) ?></td>
            <td><?= h($r['created_at']) ?></td>
            <td><?= (int)$r['age_hours'] ?></td>
            <td>
              <?php if ($old): ?>
                <span class="pill bad">Older than 24h</span>
              <?php else: ?>
                <span class="pill ok">Within 24h</span>
              <?php endif; ?>
            </td>
            <td style="white-space:nowrap;">
              <a class="btn-ghost" href="users_edit.php?id=<?= (int)$r['id'] ?>">View</a>
              <form method="post" style="display:inline;"
                    onsubmit="return confirm('Hard delete this user? Permanent.');">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="delete_one">
                <input type="hidden" name="user_id" value="<?= (int)$r['id'] ?>">
                <button class="btn-danger" type="submit">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

</div>
</body>
</html>
