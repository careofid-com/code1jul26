<?php /* filename: actions/provider_create_post.php */
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

$display     = isset($_POST['display']) ? trim($_POST['display']) : '';
$url_pattern = isset($_POST['url_pattern']) ? trim($_POST['url_pattern']) : '';

/* Basic validation */
if ($display === '' || $url_pattern === '') {
    flash_add('err', 'Display name and URL pattern are required.');
    header('Location: /handles');
    exit;
}

try {
    $pdo = db();

    // Current user (creator of private provider)
    $u   = auth_reload_user();
    $uid = isset($u['id']) ? (int)$u['id'] : 0;
    if ($uid <= 0) {
        flash_add('err', 'Could not determine current user.');
        header('Location: /handles');
        exit;
    }

    /* ---------------------------------------------
       1) Generate a base slug from display name
          - lowercase
          - replace non [a-z0-9] with '-'
          - trim edge dashes
       --------------------------------------------- */
    $baseSlug = strtolower($display);
    $baseSlug = preg_replace('/[^a-z0-9]+/', '-', $baseSlug);
    $baseSlug = trim($baseSlug, '-');

    if ($baseSlug === '') {
        $baseSlug = 'prov';
    }

    // Ensure slug matches allowed pattern
    if (!preg_match('/^[a-z0-9_-]+$/', $baseSlug)) {
        // fallback if somehow still invalid
        $baseSlug = 'prov';
    }

    // 2) Ensure slug is unique (global uniqueness)
    $finalSlug   = $baseSlug;
    $slugChanged = false;

    $st = $pdo->prepare('SELECT id FROM providers WHERE slug = ? LIMIT 1');
    $st->execute(array($finalSlug));
    if ($st->fetch()) {
        $suffix    = 2;
        $maxSuffix = 99;
        while ($suffix <= $maxSuffix) {
            $candidate = $baseSlug . $suffix;
            $st2 = $pdo->prepare('SELECT id FROM providers WHERE slug = ? LIMIT 1');
            $st2->execute(array($candidate));
            if (!$st2->fetch()) {
                $finalSlug   = $candidate;
                $slugChanged = true;
                break;
            }
            $suffix++;
        }

        if (!$slugChanged) {
            flash_add('err', 'Could not generate a unique internal slug. Please try a slightly different display name.');
            header('Location: /handles');
            exit;
        }
    }

    // 3) Insert as active, non-system, created_by_user_id = this user
    $st = $pdo->prepare('INSERT INTO providers 
        (slug, display, url_pattern, is_active, is_system, created_by_admin_id, created_by_user_id)
        VALUES (?, ?, ?, 1, 0, NULL, ?)');
    $st->execute(array($finalSlug, $display, $url_pattern, $uid));

    if ($slugChanged) {
        flash_add('ok',
          'New provider added. (An internal variant of the name was used to keep things unique.) ' .
          'You can now add a handle for it.');
    } else {
        flash_add('ok', 'New provider added. You can now add a handle for it.');
    }

    header('Location: /handles');
    exit;

} catch (Throwable $e) {
    // Optionally log $e somewhere
    flash_add('err', 'Could not create provider. Please try again.');
    header('Location: /handles');
    exit;
}
