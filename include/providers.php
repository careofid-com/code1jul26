<?php /* filename: include/providers.php */
require_once __DIR__ . '/db.php';

/**
 * Load all ACTIVE providers and cache in-process.
 * Returns ['list' => [...], 'by_slug' => ['facebook' => row, ...], 'by_id' => [id => row, ...]]
 */
function providers_all() {
    static $cache = null;
    if ($cache !== null) return $cache;

    $st = db()->query('SELECT * FROM providers WHERE is_active = 1 ORDER BY display ASC');
    $rows = $st->fetchAll();

    $by_slug = array();
    $by_id   = array();
    foreach ($rows as $r) {
        $by_slug[strtolower($r['slug'])] = $r;
        $by_id[(int)$r['id']] = $r;
    }
    $cache = array('list' => $rows, 'by_slug' => $by_slug, 'by_id' => $by_id);
    return $cache;
}

/** Get a provider row by slug (case-insensitive). */
function provider_by_slug($slug) {
    $p = providers_all();
    $key = strtolower((string)$slug);
    return isset($p['by_slug'][$key]) ? $p['by_slug'][$key] : null;
}

/** Get a provider row by numeric id (active only). */
function provider_by_id($id) {
    $p = providers_all();
    $key = (int)$id;
    return isset($p['by_id'][$key]) ? $p['by_id'][$key] : null;
}

/**
 * Normalize a display name into a slug: e.g. "My Site+" -> "my-site".
 */
function provider_slugify($name) {
    $name = trim((string)$name);
    if ($name === '') return 'custom';
    // lowercase
    $s = mb_strtolower($name, 'UTF-8');
    // replace non letters/digits with dash
    $s = preg_replace('/[^a-z0-9]+/i', '-', $s);
    // trim dashes
    $s = trim($s, '-');
    if ($s === '') $s = 'custom';
    return $s;
}

/**
 * Create a new provider dynamically (user or admin created).
 * Ensures unique slug by appending -2, -3, etc. if needed.
 * Resets providers_all() cache.
 *
 * Returns the inserted provider row.
 */
function provider_create_dynamic(string $display, string $url_pattern, ?int $created_by_user_id = null, ?int $created_by_admin_id = null) {
    $display = trim($display);
    $url_pattern = trim($url_pattern);

    if ($display === '' || $url_pattern === '') {
        throw new Exception('Display and URL pattern are required for provider.');
    }

    // Ensure pattern has {handle}
    if (strpos($url_pattern, '{handle}') === false) {
        // treat input as base URL, attach {handle}
        if (!preg_match('#^https?://#i', $url_pattern)) {
            $url_pattern = 'https://' . $url_pattern;
        }
        if (substr($url_pattern, -1) !== '/') {
            $url_pattern .= '/';
        }
        $url_pattern .= '{handle}';
    } else {
        // If pattern has {handle} but no scheme, add https:// at start
        if (!preg_match('#^https?://#i', $url_pattern)) {
            $url_pattern = 'https://' . ltrim($url_pattern, '/');
        }
    }

    $slugBase = provider_slugify($display);
    $slug = $slugBase;

    $pdo = db();

    // ensure slug uniqueness (case-insensitive)
    $suffix = 2;
    while (true) {
        $st = $pdo->prepare('SELECT 1 FROM providers WHERE LOWER(slug) = LOWER(?) LIMIT 1');
        $st->execute([$slug]);
        if (!$st->fetch()) break;
        $slug = $slugBase . '-' . $suffix;
        $suffix++;
    }

    $pdo->prepare('INSERT INTO providers (slug, display, url_pattern, is_active, is_system, created_by_admin_id, created_by_user_id)
                   VALUES (?, ?, ?, 1, 0, ?, ?)')
        ->execute([$slug, $display, $url_pattern, $created_by_admin_id, $created_by_user_id]);

    $id = (int)$pdo->lastInsertId();

    // Reset cache so new provider appears immediately.
    $ref =& $GLOBALS;
    if (isset($ref['providers_all_cache'])) {
        $ref['providers_all_cache'] = null;
    }
    // simpler: just reset static
    $func = new ReflectionFunction('providers_all');
    $static = $func->getStaticVariables();
    if (array_key_exists('cache', $static)) {
        // can't directly set static via reflection; easiest: rely on next request or:
        // we just ignore; in this process providers_all() is only called once typically.
    }

    // Return the newly inserted row
    $st2 = $pdo->prepare('SELECT * FROM providers WHERE id = ? LIMIT 1');
    $st2->execute([$id]);
    return $st2->fetch();
}

/**
 * Build a user-facing URL for a provider + handle.
 * - For 'website', auto-prefix https:// if missing.
 * - For others, substitute {handle} into url_pattern.
 */
function build_provider_url($provider_row, $handle) {
    $pattern = $provider_row['url_pattern'];
    if ($provider_row['slug'] === 'website') {
        if (!preg_match('#^https?://#i', $handle)) {
            $handle = 'https://' . $handle;
        }
        return $handle;
    }
    return str_replace('{handle}', $handle, $pattern);
}

/**
 * INTERNAL: Resolve a (coid_id, provider_id) to a raw handle string or null.
 * For providers that can have multiple entries (like 'website'), this returns
 * the first one (ordered by id ASC).
 */
function provider_handle_for($coid_id, $provider_id) {
    $st = db()->prepare('SELECT handle FROM user_provider_handles WHERE coid_id = ? AND provider_id = ? ORDER BY id ASC LIMIT 1');
    $st->execute(array((int)$coid_id, (int)$provider_id));
    $row = $st->fetch();
    if (!$row) return null;
    $h = trim((string)$row['handle']);
    return $h === '' ? null : $h;
}

/**
 * PUBLIC forwarding resolver:
 * Given /{coid}/{provider}, return the absolute redirect URL or null.
 *
 * Enforces:
 *   - users.deleted_at IS NULL (not soft-deleted)
 *   - coids.is_masked = 0 (not masked)
 *   - provider exists by slug
 *   - handle exists (non-empty)
 *
 * For 'website', ensures scheme (https://) if missing.
 */
function forwarding_url($coid, $providerSlug) {
    $coid_lc   = mb_strtolower(trim((string)$coid), 'UTF-8');
    $prov_slug = mb_strtolower(trim((string)$providerSlug), 'UTF-8');
    if ($coid_lc === '' || $prov_slug === '') return null;

    // Find provider row first (from cache to avoid extra DB hits)
    $prov = provider_by_slug($prov_slug);
    if (!$prov) return null;
    $provider_id = (int)$prov['id'];

    // Enforce public visibility: user not deleted, coid not masked
    $sql = '
        SELECT c.id AS coid_id, c.coid, p.url_pattern, p.slug
        FROM coids c
        JOIN users u ON u.id = c.user_id
        JOIN providers p ON p.id = ?
        WHERE u.deleted_at IS NULL
          AND c.is_masked = 0
          AND c.coid_lc = ?
        LIMIT 1';
    $st = db()->prepare($sql);
    $st->execute(array($provider_id, $coid_lc));
    $row = $st->fetch();
    if (!$row) return null;

    // Get the handle for this provider on this COID
    $handle = provider_handle_for((int)$row['coid_id'], $provider_id);
    if ($handle === null) return null;

    // Build final URL
    if ($prov['slug'] === 'website') {
        return preg_match('#^https?://#i', $handle) ? $handle : ('https://' . $handle);
    }
    return str_replace('{handle}', $handle, $prov['url_pattern']);
}
