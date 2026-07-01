<?php /* filename: include/cleanup.php */
require_once __DIR__ . '/db.php';

/**
 * Delete a placeholder user ONLY if:
 * - email is NULL/empty/"null"
 * - user owns no COIDs
 *
 * Returns true if deleted, false otherwise.
 */
function delete_orphan_placeholder_user($user_id) {
    $user_id = (int)$user_id;
    if ($user_id <= 0) return false;

    $pdo = db();

    // Must be placeholder
    $st = $pdo->prepare('SELECT id, email FROM users WHERE id = ? LIMIT 1');
    $st->execute(array($user_id));
    $u = $st->fetch();
    if (!$u) return false;

    $email = $u['email'];
    $isPlaceholder = ($email === null || $email === '' || strtolower((string)$email) === 'null');
    if (!$isPlaceholder) return false;

    // Must own ZERO COIDs
    $st2 = $pdo->prepare('SELECT COUNT(*) AS n FROM coids WHERE user_id = ?');
    $st2->execute(array($user_id));
    $n = (int)($st2->fetch()['n'] ?? 0);
    if ($n > 0) return false;

    // Safe to delete
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute(array($user_id));
    return true;
}
