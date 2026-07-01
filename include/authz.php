<?php /* filename: include/authz.php */
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/db.php';

/**
 * Admin auth / authorization helpers.
 * Admins live in a separate session key: $_SESSION['admin'].
 *
 * IMPORTANT:
 * admin_session_start() is defined in include/security.php
 * and MUST NOT be redeclared here.
 */

/** ---- Session helpers (admins in a separate session key) ---- */

/**
 * @return array|null  ['id'=>int,'email'=>string,'role'=>string,'first_name'=>string,'last_name'=>string]
 */
function admin_current(): ?array {
    return (isset($_SESSION['admin']) && is_array($_SESSION['admin']))
        ? $_SESSION['admin']
        : null;
}

/**
 * Store minimal admin info into session.
 *
 * Expected columns in $admin (from DB row):
 *  - id, email, role, first_name, last_name
 */
function admin_set_session(array $admin): void {
    $_SESSION['admin'] = array(
        'id'         => isset($admin['id']) ? (int)$admin['id'] : 0,
        'email'      => isset($admin['email']) ? (string)$admin['email'] : '',
        'role'       => isset($admin['role']) ? (string)$admin['role'] : 'viewer',
        'first_name' => isset($admin['first_name']) ? (string)$admin['first_name'] : '',
        'last_name'  => isset($admin['last_name']) ? (string)$admin['last_name'] : '',
    );
}

/** Clear admin session (used on admin logout). */
function admin_clear_session(): void {
    if (isset($_SESSION['admin'])) {
        $_SESSION['admin'] = null;
    }
}

/** Require admin to be logged in; otherwise redirect to admin login. */
function admin_require_login(): void {
    if (!admin_current()) {
        header('Location: /admin/login.php');
        exit;
    }
}

/** ---- Role ranking / comparisons ---- */

/**
 * Rank roles so we can compare.
 * Higher number = more privileges.
 *
 * We treat legacy 'editor' exactly like 'viewer'.
 */
function role_rank(string $role): int {
    $role = strtolower($role);
    switch ($role) {
        case 'viewer':
        case 'editor':
            return 1;
        case 'admin':
            return 2;
        case 'superadmin':
            return 3;
        default:
            return 0;
    }
}

/**
 * Ensure current admin has at least $minRole.
 *
 * Example:
 *   require_admin_role('admin');      // admin or superadmin
 *   require_admin_role('superadmin'); // only superadmin
 */
function require_admin_role(string $minRole): void {
    $cur = admin_current();
    if (!$cur) {
        header('Location: /admin/login.php');
        exit;
    }
    if (role_rank($cur['role']) < role_rank($minRole)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

/** ---- User edit policy (prevents undefined function fatal) ---- */

if (!function_exists('can_edit_user_fields')) {
  /**
   * Decide if an admin role can edit the requested user fields.
   *
   * @param string $actorRole
   * @param array  $fields
   * @return bool
   */
  function can_edit_user_fields($actorRole, $fields) {
    $role = strtolower((string)$actorRole);
    if ($role === 'editor') $role = 'viewer';

    $fields = is_array($fields) ? $fields : array();

    if ($role === 'viewer') return false;
    if ($role === 'superadmin') return true;

    $allowed = array(
      'first_name',
      'last_name',
      'email',
      'phone_e164',
      'coid',
      'is_masked',
      'handles',
      'deleted_at',
      'is_verified',
      'plan_status',
    );

    foreach ($fields as $f) {
      if ($f !== '' && !in_array($f, $allowed, true)) {
        return false;
      }
    }
    return true;
  }
}

/** ---- Creation & edit policies ---- */

/**
 * Can an admin with role $actorRole create a staff member with role $newRole?
 */
function can_create_role(string $actorRole, string $newRole): bool {
    $actorRole = strtolower($actorRole);
    $newRole   = strtolower($newRole);

    if ($actorRole === 'superadmin') {
        return in_array($newRole, array('admin', 'viewer', 'editor'), true);
    }
    if ($actorRole === 'admin') {
        return in_array($newRole, array('viewer', 'editor'), true);
    }
    return false;
}

/**
 * Can $actor edit $target admin record?
 */
function can_edit_admin(array $actor, array $target): bool {
    if (empty($actor) || empty($target)) return false;

    $actorId    = (int)($actor['id'] ?? 0);
    $targetId   = (int)($target['id'] ?? 0);
    $actorRank  = role_rank((string)($actor['role'] ?? ''));
    $targetRank = role_rank((string)($target['role'] ?? ''));

    if ($actorId && $actorId === $targetId) return true;
    if ($actorRank > $targetRank && $actorRank > 0) return true;

    return false;
}

/** ---- Audit log (best-effort) ---- */

function audit_log(string $action, array $opts = array()): void {
    try {
        $cur = admin_current();
        $actorId = $cur ? (int)$cur['id'] : null;

        $pdo = db();
        $stmt = $pdo->prepare(
            'INSERT INTO admin_audit
             (actor_admin_id, target_user_id, target_admin_id, action, details_json)
             VALUES (?, ?, ?, ?, ?)'
        );

        $detailsJson = !empty($opts['details'])
            ? json_encode($opts['details'], JSON_UNESCAPED_SLASHES)
            : null;

        $stmt->execute(array(
            $actorId,
            $opts['target_user_id'] ?? null,
            $opts['target_admin_id'] ?? null,
            $action,
            $detailsJson,
        ));
    } catch (\Throwable $e) {
        // silent
    }
}

