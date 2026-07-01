<?php /* filename: pending_claim.php */
require_once __DIR__ . '/include/security.php';
require_once __DIR__ . '/include/render.php';
require_once __DIR__ . '/include/db.php';

coid_session_start();
security_headers();

$pdo = db();

/* must be logged in */
$userId = 0;
if (!empty($_SESSION['user_id'])) $userId = (int)$_SESSION['user_id'];
elseif (!empty($_SESSION['user']['id'])) $userId = (int)$_SESSION['user']['id'];

if ($userId <= 0) {
  header('Location: /login.php');
  exit;
}

/* User chose WAIT */
if (isset($_GET['wait']) && $_GET['wait'] === '1') {
  unset($_SESSION['allow_create_coid']);
  header('Location: /');
  exit;
}

/* User chose CREATE */
if (isset($_GET['create']) && $_GET['create'] === '1') {
  $_SESSION['allow_create_coid'] = 1;
  header('Location: /dashboard.php');
  exit;
}

/* Find latest pending claim for this user */
$st = $pdo->prepare('
  SELECT cc.id, cc.created_at, c.coid
  FROM coid_claims cc
  JOIN coids c ON c.id = cc.coid_id
  WHERE cc.user_id = ?
    AND cc.status = "pending"
  ORDER BY cc.id DESC
  LIMIT 1
');
$st->execute([$userId]);
$pending = $st->fetch(PDO::FETCH_ASSOC);

/* If no pending claim, release gate flag and go dashboard */
if (!$pending) {
  unset($_SESSION['allow_create_coid']);
  header('Location: /dashboard.php');
  exit;
}

$coid = (string)$pending['coid'];

page_head('Claim pending — careofid');
page_nav();
page_flash();
?>
<div class="centercol">
  <div class="card">
    <h1>Claim request pending</h1>
    <p class="muted">
      Your claim request for <strong>/<?php echo htmlspecialchars($coid, ENT_QUOTES, 'UTF-8'); ?></strong> is still pending admin review.
      <br>Do you want to wait, or create a new COID now?
    </p>

    <div class="row" style="gap:10px;margin-top:12px;flex-wrap:wrap;">
      <a class="btn" href="/pending_claim.php?wait=1">Wait</a>
      <a class="btn" href="/pending_claim.php?create=1">Create a new COID</a>
    </div>

    <p class="muted" style="margin-top:10px;font-size:13px;">
      You will receive an email once admin approves or rejects your claim.
    </p>
  </div>
</div>
<?php page_foot(); ?>
