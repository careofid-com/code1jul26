<?php /* filename: include/db.php */
require_once __DIR__ . '/config.php';

function find_coid_row_public(string $q) {
  $co = trim($q);
  if ($co === '') return null;

  $pdo = db();

  // Try exact (case-insensitive) on coid_lc
  $st = $pdo->prepare('
    SELECT c.*, u.id AS user_id, u.email, u.first_name, u.last_name
    FROM coids c
    JOIN users u ON u.id = c.user_id
    WHERE u.deleted_at IS NULL
      AND c.is_masked = 0
      AND c.coid_lc = ?
    LIMIT 1
  ');
  $st->execute([mb_strtolower($co, 'UTF-8')]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  $st->closeCursor(); // ✅ prevent "unbuffered query" issues
  if ($row) return $row;

  // Fallback: exact case (if you want)
  $st = $pdo->prepare('
    SELECT c.*, u.id AS user_id, u.email, u.first_name, u.last_name
    FROM coids c
    JOIN users u ON u.id = c.user_id
    WHERE u.deleted_at IS NULL
      AND c.is_masked = 0
      AND c.coid = ?
    LIMIT 1
  ');
  $st->execute([$co]);
  $row2 = $st->fetch(PDO::FETCH_ASSOC);
  $st->closeCursor(); // ✅ prevent "unbuffered query" issues
  return $row2 ?: null;
}

function db() {
  static $pdo = null;
  if ($pdo instanceof PDO) return $pdo;

  $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;

  $opts = array(
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,

    // ✅ GLOBAL FIX for: SQLSTATE[HY000]: General error: 2014 unbuffered queries
    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
  );

  try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $opts);
  } catch (Throwable $e) {
    http_response_code(500);
    exit('Database connection error.');
  }

  return $pdo;
}
