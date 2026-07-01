<?php /* filename: include/security.php */

if (!defined('CAREOFID_SECURITY_LOADED')) define('CAREOFID_SECURITY_LOADED', 1);

/* -----------------------------------------------------------
 * Basic helpers
 * ---------------------------------------------------------*/
if (!function_exists('is_https')) {
  function is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    if (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) return true;
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') return true;
    return false;
  }
}

if (!function_exists('security_headers')) {
  function security_headers(): void {
    if (headers_sent()) return;

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

    // CSP: compatible with Stripe + inline CSS/JS used across pages.
    // Added Google Ads / Tag Manager allowlist so gtag.js can load & be detected.
    $csp = "default-src 'self'; "
         . "base-uri 'self'; "
         . "form-action 'self'; "
         . "frame-ancestors 'self'; "
         . "img-src 'self' data: https: https://www.googleadservices.com https://googleads.g.doubleclick.net https://www.googletagmanager.com; "
         . "style-src 'self' 'unsafe-inline' https:; "
         . "script-src 'self' 'unsafe-inline' "
             // Stripe
         . "https://js.stripe.com https://checkout.stripe.com "
             // Google Ads / Tag Manager / DoubleClick
         . "https://www.googletagmanager.com https://www.googleadservices.com https://googleads.g.doubleclick.net; "
         . "frame-src 'self' "
             // Stripe
         . "https://js.stripe.com https://checkout.stripe.com "
             // Google Ads sometimes uses iframes / conversions
         . "https://googleads.g.doubleclick.net; "
         . "connect-src 'self' "
             // Stripe
         . "https://api.stripe.com https://checkout.stripe.com "
             // Google Ads / Tag Manager / DoubleClick beacons
         . "https://www.googleadservices.com https://googleads.g.doubleclick.net https://www.googletagmanager.com;";

    header("Content-Security-Policy: {$csp}");
  }
}

if (!function_exists('strict_post_only')) {
  function strict_post_only(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
      http_response_code(405);
      exit('Method Not Allowed');
    }
  }
}

/* -----------------------------------------------------------
 * Sessions
 * ---------------------------------------------------------*/
if (!function_exists('coid_session_start')) {
  function coid_session_start(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
      // Best-effort session hardening (works on shared hosting)
      $params = session_get_cookie_params();
      session_set_cookie_params([
        'lifetime' => 0,
        'path' => $params['path'] ?: '/',
        'domain' => $params['domain'] ?? '',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
      ]);
      @session_start();
    }

    // pending claim gate applies to public (non-admin) session
    if (function_exists('enforce_pending_claim_gate')) {
      enforce_pending_claim_gate();
    }
  }
}

if (!function_exists('admin_session_start')) {
  function admin_session_start(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
      $params = session_get_cookie_params();
      session_set_cookie_params([
        'lifetime' => 0,
        'path' => $params['path'] ?: '/',
        'domain' => $params['domain'] ?? '',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
      ]);
      @session_start();
    }
  }
}

/* -----------------------------------------------------------
 * CSRF
 * ---------------------------------------------------------*/
if (!function_exists('csrf_token')) {
  function csrf_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) {
      @session_start();
    }
    if (empty($_SESSION['__csrf'])) {
      $_SESSION['__csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['__csrf'];
  }
}

if (!function_exists('csrf_verify')) {
  function csrf_verify(?string $token): bool {
    if (session_status() !== PHP_SESSION_ACTIVE) {
      @session_start();
    }
    $sess = (string)($_SESSION['__csrf'] ?? '');
    if (!$token || !$sess) return false;
    return hash_equals($sess, (string)$token);
  }
}

/* -----------------------------------------------------------
 * Pending-claim gate
 * ---------------------------------------------------------*/
if (!function_exists('user_has_pending_claim')) {
  function user_has_pending_claim(int $userId): bool {
    if ($userId <= 0) return false;
    try {
      // db() is defined in include/db.php in your project
      require_once __DIR__ . '/db.php';
      if (!function_exists('db')) return false;
      $pdo = db();
      $st = $pdo->prepare('SELECT 1 FROM coid_claims WHERE user_id = ? AND status = "pending" LIMIT 1');
      $st->execute([$userId]);
      return (bool)$st->fetchColumn();
    } catch (\Throwable $e) {
      return false;
    }
  }
}

if (!function_exists('enforce_pending_claim_gate')) {
  function enforce_pending_claim_gate(): void {
    // Ignore for admin area
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $path = $path ?: '/';
    if (strpos($path, '/admin/') === 0) return;

    // Determine logged-in user id (support both session styles)
    $uid = 0;
    if (!empty($_SESSION['user_id'])) $uid = (int)$_SESSION['user_id'];
    elseif (!empty($_SESSION['user']['id'])) $uid = (int)$_SESSION['user']['id'];
    if ($uid <= 0) return;

    if (!user_has_pending_claim($uid)) return;

    // If user explicitly chose "create new COID", allow dashboard/tools
    if (!empty($_SESSION['allow_create_coid']) && (int)$_SESSION['allow_create_coid'] === 1) {
      return;
    }

    // Allowlist during pending state
    $allow = [
      '/', '/index.php',
      '/claim.php',
      '/pending_claim.php',
      '/verify.php',
      '/logout.php',
      '/login.php',
      '/signup.php',
    ];

    // Allow all action posts (verify submit, claim submit, etc.)
    if (strpos($path, '/actions/') === 0) {
      return;
    }

    // If not allowed, force decision screen
    if (!in_array($path, $allow, true)) {
      header('Location: /pending_claim.php');
      exit;
    }
  }
}
