<?php /* filename: include/auth.php */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

function auth_current_user() {
    return isset($_SESSION['user']) ? $_SESSION['user'] : null;
}

function auth_current_user_id() {
    $u = auth_current_user();
    return $u ? intval($u['id']) : 0;
}

function auth_reload_user() {
    $uid = auth_current_user_id();
    if (!$uid) return null;
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute(array($uid));
    $u = $stmt->fetch();
    if ($u) $_SESSION['user'] = $u;
    return $u;
}

function auth_require_login() {
    if (!auth_current_user_id()) {
        header('Location: /login?next=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }
}

function auth_is_admin() {
    $u = auth_current_user();
    return $u && $u['role'] === 'admin';
}

function auth_login($email, $password) {
    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute(array($email));
    $u = $stmt->fetch();
    $ok = false;
    if ($u && password_verify($password, $u['password_hash'])) {
        $ok = true;
    }
    // log attempt
    try {
        $la = db()->prepare('INSERT INTO login_attempts (ip, email, success) VALUES (?, ?, ?)');
        $la->execute(array(client_ip_bin(), $email, $ok ? 1 : 0));
    } catch (Throwable $e) {}
    if (!$ok) return false;

    coid_session_start();
    session_regenerate_id(true);
    $_SESSION['user'] = $u;
    return true;
}

function auth_logout() {
    coid_session_start();
    $_SESSION = array();
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], true, true);
    }
    session_destroy();
}

function user_by_id($id) {
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute(array($id));
    return $st->fetch();
}

function user_by_email($email) {
    $st = db()->prepare('SELECT * FROM users WHERE email = ?');
    $st->execute(array($email));
    return $st->fetch();
}
