<?php /* filename: admin/coid_new.php */
require_once __DIR__ . '/../include/security.php';
require_once __DIR__ . '/../include/render.php';
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/authz.php';

admin_session_start();
security_headers();
admin_require_login();

$me = admin_current();
require_admin_role('admin');

$pdo = db();

function admin_safe_next(string $next): string {
  $next = trim($next);
  if ($next === '' || preg_match('~^(https?:)?//~i', $next) || strpos($next, "\n") !== false || strpos($next, "\r") !== false) {
    return '/admin/index.php';
  }
  if ($next[0] !== '/') $next = '/' . ltrim($next, '/');
  if (strpos($next, '/admin/') !== 0) return '/admin/index.php';
  return $next;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  if (!csrf_verify($_POST['csrf'] ?? '')) { http_response_code(400); exit('Bad CSRF'); }

  $next = admin_safe_next((string)($_POST['next'] ?? '/admin/index.php'));

  $coid = trim((string)($_POST['coid'] ?? ''));
  $coid_lc = mb_strtolower($coid, 'UTF-8');

  $fn = trim((string)($_POST['first_name'] ?? ''));
  $ln = trim((string)($_POST['last_name'] ?? ''));

  $handles = (array)($_POST['handles'] ?? []);

  if ($coid === '') {
    flash_add('err', 'Please enter a COID.');
    header('Location: /admin/coid_new.php'); exit;
  }

  if (strlen($coid) < 3 || strlen($coid) > 64) {
    flash_add('err', 'COID must be between 3 and 64 characters.');
    header('Location: /admin/coid_new.php'); exit;
  }

  // Defaults for placeholder identity
  if ($fn === '') $fn = 'Unclaimed';
  if ($ln === '') $ln = 'COID';

  try {
    $pdo->beginTransaction();

    // Ensure COID not taken (coid_lc unique)
    $chk = $pdo->prepare('SELECT id FROM coids WHERE coid_lc = ? LIMIT 1');
    $chk->execute([$coid_lc]);
    $exists = $chk->fetch(PDO::FETCH_ASSOC);
    $chk->closeCursor(); // ✅ critical
    if ($exists) {
      throw new Exception('COID already exists.');
    }

    // Create placeholder user (email NULL). password_hash is required.
    $randPass = bin2hex(random_bytes(16));
    $hash = password_hash($randPass, PASSWORD_DEFAULT);

    $insU = $pdo->prepare('
      INSERT INTO users (email, password_hash, first_name, last_name, is_verified)
      VALUES (NULL, ?, ?, ?, 0)
    ');
    $insU->execute([$hash, $fn, $ln]);
    $userId = (int)$pdo->lastInsertId();
    if ($userId <= 0) throw new Exception('Could not create placeholder user.');

    // Create COID row (your schema requires created_at; is_masked default exists but we set it explicitly)
    $insC = $pdo->prepare('
      INSERT INTO coids (user_id, coid, coid_lc, is_masked, created_at)
      VALUES (?, ?, ?, 0, NOW())
    ');
    $insC->execute([$userId, $coid, $coid_lc]);
    $coidId = (int)$pdo->lastInsertId();
    if ($coidId <= 0) throw new Exception('Could not create COID record.');

    // Optional handles (only for active providers)
    if (!empty($handles)) {
      $provStmt = $pdo->prepare('SELECT id FROM providers WHERE id = ? AND is_active = 1 LIMIT 1');

      foreach ($handles as $pid => $handle) {
        $pid = (int)$pid;
        $h = trim((string)$handle);
        if ($pid <= 0 || $h === '') continue;

        $provStmt->execute([$pid]);
        $okProv = $provStmt->fetch(PDO::FETCH_ASSOC);
        $provStmt->closeCursor(); // ✅ critical
        if (!$okProv) continue;

        $pdo->prepare('
          INSERT INTO user_provider_handles (coid_id, provider_id, handle, created_at, updated_at)
          VALUES (?, ?, ?, NOW(), NOW())
        ')->execute([$coidId, $pid, $h]);
      }
    }

    $pdo->commit();

    flash_add('ok', 'COID /' . htmlspecialchars($coid, ENT_QUOTES, 'UTF-8') . ' created as unclaimed.');
    header('Location: ' . $next);
    exit;

  } catch (\Throwable $e) {

    // ✅ rollback safely (never fatal)
    try {
      if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    } catch (\Throwable $e2) {}

    flash_add('err', 'Create failed: ' . $e->getMessage());
    header('Location: /admin/coid_new.php');
    exit;
  }
}

/* -----------------------------------------------------------
 * GET: show form
 * ---------------------------------------------------------*/
page_head('Admin — New COID');
page_nav();
page_flash();

// Providers list for optional handles
$providers = [];
try {
  $st = $pdo->prepare('SELECT id, display FROM providers WHERE is_active = 1 ORDER BY is_system DESC, display ASC');
  $st->execute();
  $providers = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $st->closeCursor();
} catch (\Throwable $e) {
  $providers = [];
}
?>
<div class="centercol">
  <div class="card">
    <div style="margin-bottom:10px;">
      <a href="/admin/index.php" class="btn">&larr; Back</a>
    </div>

    <h1>Create unclaimed COID</h1>
    <p class="muted">
      Creates a placeholder user (email NULL) and assigns the COID to it.
      A real user can later claim it from the public site.
    </p>

    <form method="post" action="/admin/coid_new.php">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
      <input type="hidden" name="next" value="/admin/index.php">

      <label>COID</label>
      <input type="text" name="coid" required maxlength="64" placeholder="Example: Saeed.123">

      <div class="row" style="gap:10px;">
        <div style="flex:1;">
          <label>First name (optional)</label>
          <input type="text" name="first_name" maxlength="100" placeholder="Unclaimed">
        </div>
        <div style="flex:1;">
          <label>Last name (optional)</label>
          <input type="text" name="last_name" maxlength="100" placeholder="COID">
        </div>
      </div>

      <h3 style="margin-top:14px;">Optional handles</h3>
      <p class="muted" style="margin-top:-6px;">Leave blank to skip.</p>

      <?php if (!$providers): ?>
        <p class="muted">No providers found.</p>
      <?php else: ?>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <?php foreach ($providers as $p): ?>
            <div>
              <label><?php echo htmlspecialchars($p['display'], ENT_QUOTES, 'UTF-8'); ?></label>
              <input type="text" name="handles[<?php echo (int)$p['id']; ?>]" placeholder="@handle or URL">
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="row" style="gap:8px; margin-top:14px;">
        <button type="submit">Create COID</button>
        <a class="btn" href="/admin/index.php">Back</a>
      </div>
    </form>
  </div>
</div>
<?php page_foot(); ?>
