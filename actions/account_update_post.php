<?php /* filename: actions/account_update_post.php */
require_once __DIR__ . '/../include/security.php';
require_once __DIR__ . '/../include/auth.php';
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/render.php';
require_once __DIR__ . '/../include/validation.php';
require_once __DIR__ . '/../include/dp_upload.php'; // NEW: profile picture helper

coid_session_start();
security_headers();
strict_post_only();
auth_require_login();

if (!csrf_verify(isset($_POST['csrf']) ? $_POST['csrf'] : '')) {
    http_response_code(400);
    exit('Bad CSRF');
}

$mode = isset($_POST['mode']) ? $_POST['mode'] : 'profile';
$uid  = auth_current_user_id();

try {
    if ($mode === 'password') {
        // -----------------------------
        // Change password
        // -----------------------------
        $cur = isset($_POST['current']) ? $_POST['current'] : '';
        $npw = isset($_POST['password']) ? $_POST['password'] : '';
        if (strlen($npw) < 8) {
            flash_add('err','New password too short.');
            header('Location:/account'); exit;
        }

        $st = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $st->execute(array($uid));
        $row = $st->fetch();
        if (!$row || !password_verify($cur, $row['password_hash'])) {
            flash_add('err','Current password is incorrect.');
            header('Location:/account'); exit;
        }
        $hash = password_hash($npw, PASSWORD_DEFAULT);
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute(array($hash, $uid));
        flash_add('ok','Password changed.');
        header('Location:/account'); exit;

    } else {
        // -----------------------------
        // Profile update (name, phone, DP)
        // -----------------------------
        $first = isset($_POST['first_name']) ? trim($_POST['first_name']) : '';
        $last  = isset($_POST['last_name']) ? trim($_POST['last_name']) : '';
        $cc    = isset($_POST['phone_cc']) ? trim($_POST['phone_cc']) : '';
        $local = isset($_POST['phone_local']) ? trim($_POST['phone_local']) : '';

        if ($first === '' || $last === '') {
            flash_add('err','Enter your name.');
            header('Location:/account'); exit;
        }

        // Build phone_e164 (or null)
        $phone_e164 = null;
        if ($cc !== '' || $local !== '') {
            $cc_digits    = preg_replace('/[^0-9+]/', '', $cc);
            if ($cc_digits !== '' && $cc_digits[0] !== '+') {
                $cc_digits = '+' . $cc_digits;
            }
            $local_digits = preg_replace('/\D+/', '', $local);
            $raw = ($cc_digits ?: '+') . $local_digits;
            $phone_e164 = normalize_phone_e164($raw);
            if ($phone_e164 === null) {
                flash_add('err','Invalid phone number.');
                header('Location:/account'); exit;
            }
        }

        $pdo = db();

        // Fetch current dp_path to preserve if no new upload
        $stCur = $pdo->prepare('SELECT dp_path FROM users WHERE id = ? LIMIT 1');
        $stCur->execute(array($uid));
        $curRow = $stCur->fetch();
        $current_dp_path = $curRow && isset($curRow['dp_path']) ? $curRow['dp_path'] : null;

        // Handle DP upload (this will trust dp_cropped if provided)
        $new_dp_path = handle_dp_upload($uid, $current_dp_path);

        // Update profile
        $sql = 'UPDATE users SET first_name = ?, last_name = ?, phone_e164 = ?';
        $params = array($first, $last, $phone_e164);

        if ($new_dp_path !== $current_dp_path) {
            $sql .= ', dp_path = ?';
            $params[] = $new_dp_path;
        }

        $sql .= ' WHERE id = ?';
        $params[] = $uid;

        $stUpd = $pdo->prepare($sql);
        $stUpd->execute($params);

        auth_reload_user(); // so $_SESSION["user"] has updated dp_path etc.
        flash_add('ok','Profile updated.');
        header('Location:/account'); exit;
    }
} catch (Throwable $e) {
    // You can log $e->getMessage() server-side if needed
    flash_add('err','Update failed.');
    header('Location:/account'); exit;
}
