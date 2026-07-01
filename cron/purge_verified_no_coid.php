<?php
/* filename: cron/purge_verified_no_coid.php

   Purpose:
   - Permanently deletes VERIFIED user accounts that did not create any COID
     within 24 hours of verification.

   Intended to run via cPanel Cron (hourly/daily).
   This is a CLI-safe script (no session / no output on success).
*/

require_once __DIR__ . '/../include/db.php';

// Best-effort DP file cleanup
function coid_try_delete_dp_file($publicPath) {
  if (!is_string($publicPath) || $publicPath === '') return;
  if (strpos($publicPath, '/uploads/dp/') !== 0) return;
  $base = realpath(__DIR__ . '/..');
  if ($base === false) $base = dirname(__DIR__);
  $abs = $base . $publicPath;
  if (is_file($abs)) @unlink($abs);
}

$pdo = db();

try {
  // Find verified users with NO COID and verification older than 24 hours.
  // We use email_verifications.consumed_at as the verification timestamp.
  $st = $pdo->query(
    "SELECT u.id, u.dp_path
       FROM users u
       LEFT JOIN coids c ON c.user_id = u.id
       LEFT JOIN (
          SELECT user_id, MAX(consumed_at) AS verified_at
            FROM email_verifications
           WHERE consumed_at IS NOT NULL
           GROUP BY user_id
       ) ev ON ev.user_id = u.id
      WHERE u.deleted_at IS NULL
        AND u.role = 'user'
        AND u.email IS NOT NULL
        AND u.is_verified = 1
        AND c.id IS NULL
        AND ev.verified_at IS NOT NULL
        AND ev.verified_at < (NOW() - INTERVAL 24 HOUR)
      ORDER BY u.id ASC"
  );

  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  if (!$rows) {
    exit(0);
  }

  foreach ($rows as $r) {
    $uid = (int)$r['id'];
    $dp  = isset($r['dp_path']) ? (string)$r['dp_path'] : '';

    try {
      $pdo->beginTransaction();

      // Re-check under lock (race-safe)
      $stU = $pdo->prepare('SELECT id, dp_path FROM users WHERE id=? AND deleted_at IS NULL AND role=\'user\' LIMIT 1 FOR UPDATE');
      $stU->execute([$uid]);
      $u = $stU->fetch(PDO::FETCH_ASSOC);
      if (!$u) {
        $pdo->rollBack();
        continue;
      }

      $stC = $pdo->prepare('SELECT id FROM coids WHERE user_id=? LIMIT 1');
      $stC->execute([$uid]);
      if ($stC->fetchColumn()) {
        $pdo->rollBack();
        continue; // user created COID after we selected them
      }

      // Purge related rows
      $pdo->prepare('DELETE FROM coid_claims WHERE user_id=?')->execute([$uid]);
      $pdo->prepare('DELETE FROM password_resets WHERE user_id=?')->execute([$uid]);
      $pdo->prepare('DELETE FROM email_verifications WHERE user_id=?')->execute([$uid]);
      $pdo->prepare('DELETE FROM audit_logs WHERE target_user_id=?')->execute([$uid]);

      // Delete user
      $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$uid]);

      $pdo->commit();

      // Best-effort DP cleanup after commit
      $dpPath = isset($u['dp_path']) ? (string)$u['dp_path'] : $dp;
      if ($dpPath !== '') {
        coid_try_delete_dp_file($dpPath);
      }

    } catch (Throwable $e2) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      error_log('purge_verified_no_coid: failed uid=' . $uid . ' err=' . $e2->getMessage());
      // continue with next user
    }
  }

  exit(0);

} catch (Throwable $e) {
  error_log('purge_verified_no_coid: fatal err=' . $e->getMessage());
  exit(1);
}
