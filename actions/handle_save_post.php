<?php /* filename: actions/handle_save_post.php */
require_once __DIR__ . '/../include/security.php';
require_once __DIR__ . '/../include/auth.php';
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/render.php';
require_once __DIR__ . '/../include/validation.php';

coid_session_start();
security_headers();
strict_post_only();
auth_require_login();

if (!csrf_verify(isset($_POST['csrf']) ? $_POST['csrf'] : '')) {
    http_response_code(400);
    exit('Bad CSRF');
}

$u   = auth_reload_user();
$uid = isset($u['id']) ? (int)$u['id'] : 0;

$coid_id = isset($_POST['coid_id']) ? (int)$_POST['coid_id'] : 0;
if ($uid <= 0 || $coid_id <= 0) {
    flash_add('err', 'Invalid request.');
    header('Location: /handles');
    exit;
}

$pdo = db();

try {
    // 1) Ensure this COID belongs to the current user
    $st = $pdo->prepare('SELECT id, coid, user_id FROM coids WHERE id = ? LIMIT 1');
    $st->execute(array($coid_id));
    $co = $st->fetch();
    if (!$co || (int)$co['user_id'] !== $uid) {
        flash_add('err', 'You are not allowed to edit handles for this COID.');
        header('Location: /handles');
        exit;
    }

    // 2) Get latest user record with plan info
    $st = $pdo->prepare('SELECT email, plan_status FROM users WHERE id = ? LIMIT 1');
    $st->execute(array($uid));
    $urow = $st->fetch();

    $email       = $urow ? (string)$urow['email'] : '';
    $planStatus  = $urow && isset($urow['plan_status']) && $urow['plan_status'] !== null
                   ? (string)$urow['plan_status']
                   : 'free';

    // Special rule: any *@careofid.com email is treated as paid (test/staff)
    $isStaffDomain = false;
    if ($email !== '') {
        $isStaffDomain = (bool)preg_match('/@careofid\.com$/i', $email);
    }

    // Determine provider limit
    if ($isStaffDomain || $planStatus === 'paid') {
        $planLabel      = 'paid';
        $providersLimit = 20;
    } else {
        $planLabel      = 'free';
        $providersLimit = 4;
    }

    // 3) Collect posted handles (provider_id => handle string)
    $postedHandles = array();
    if (isset($_POST['handle']) && is_array($_POST['handle'])) {
        foreach ($_POST['handle'] as $pid => $val) {
            $pid_int = (int)$pid;
            $postedHandles[$pid_int] = trim((string)$val);
        }
    }

    // 4) Determine which providers this user is allowed to update:
    //    - active
    //    - global (created_by_user_id IS NULL) OR owned by this user
    $st = $pdo->prepare('SELECT id, slug, url_pattern, created_by_user_id, is_system
                         FROM providers
                         WHERE is_active = 1
                           AND (created_by_user_id IS NULL OR created_by_user_id = ?)');
    $st->execute(array($uid));
    $allowedProviders = $st->fetchAll();

    // Map provider id -> provider row
    $allowedMap = array();
    foreach ($allowedProviders as $p) {
        $allowedMap[(int)$p['id']] = $p;
    }

    // 5) Enforce plan-based provider limit (count non-empty handles after update)
    $finalNonEmpty = 0;
    foreach ($allowedMap as $pid => $prov) {
        $newHandle = isset($postedHandles[$pid]) ? $postedHandles[$pid] : '';
        if ($newHandle !== '') {
            $finalNonEmpty++;
        }
    }

    if ($finalNonEmpty > $providersLimit) {
        // Do NOT change DB, just inform user
        if ($planLabel === 'paid') {
            flash_add('err',
                'Paid plan allows up to ' . (int)$providersLimit . ' providers. ' .
                'Please remove some handles or contact support.');
        } else {
            flash_add('err',
                'Free plan allows up to ' . (int)$providersLimit . ' providers. ' .
                'Please upgrade to add more.');
        }
        header('Location: /handles');
        exit;
    }

    // 6) Load existing handles for this COID
    $st = $pdo->prepare('SELECT id, provider_id, handle
                         FROM user_provider_handles
                         WHERE coid_id = ?');
    $st->execute(array($coid_id));
    $existing = $st->fetchAll();

    $existingMap = array(); // provider_id => row
    foreach ($existing as $row) {
        $existingMap[(int)$row['provider_id']] = $row;
    }

    // 7) Apply updates inside a transaction
    $pdo->beginTransaction();

    foreach ($allowedMap as $pid => $prov) {
        $newHandle   = isset($postedHandles[$pid]) ? $postedHandles[$pid] : '';
        $hasExisting = isset($existingMap[$pid]);
        $existingRow = $hasExisting ? $existingMap[$pid] : null;

        if ($newHandle === '') {
            // If empty → delete any existing row
            if ($hasExisting) {
                $del = $pdo->prepare('DELETE FROM user_provider_handles WHERE id = ?');
                $del->execute(array((int)$existingRow['id']));
            }
        } else {
            if ($hasExisting) {
                // Update existing handle
                $upd = $pdo->prepare('UPDATE user_provider_handles
                                      SET handle = ?, updated_at = NOW()
                                      WHERE id = ?');
                $upd->execute(array($newHandle, (int)$existingRow['id']));
            } else {
                // Insert new handle
                $ins = $pdo->prepare('INSERT INTO user_provider_handles
                                      (coid_id, provider_id, handle, created_at, updated_at)
                                      VALUES (?, ?, ?, NOW(), NOW())');
                $ins->execute(array($coid_id, $pid, $newHandle));
            }
        }
    }

    $pdo->commit();

    flash_add('ok', 'Handles updated.');
    header('Location: /handles');
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Optionally log $e somewhere
    flash_add('err', 'Could not update handles. Please try again.');
    header('Location: /handles');
    exit;
}
