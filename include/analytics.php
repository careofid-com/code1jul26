<?php /* filename: include/analytics.php */
require_once __DIR__ . '/db.php';

/**
 * Event type constants
 * 1 = profile view
 * 2 = forward (click on specific provider link)
 */
const COID_EVENT_PROFILE_VIEW = 1;
const COID_EVENT_FORWARD      = 2;

/**
 * Source constants
 * 1 = web (normal click / visit)
 * 2 = QR (came from a QR code)
 * 3 = share (came from a share link, if we add later)
 */
const COID_SOURCE_WEB   = 1;
const COID_SOURCE_QR    = 2;
const COID_SOURCE_SHARE = 3;

/**
 * Log an event for a COID.
 *
 * @param int      $coid_id     Required COID ID
 * @param int|null $provider_id Provider ID or null (for profile views)
 * @param int      $event_type  One of the COID_EVENT_* constants
 * @param int|null $source      One of the COID_SOURCE_* constants or null
 */
function log_coid_event($coid_id, $provider_id, $event_type, $source = null) {
    $coid_id    = (int)$coid_id;
    $event_type = (int)$event_type;

    if ($coid_id <= 0 || $event_type <= 0) {
        return;
    }

    $provider_id = ($provider_id !== null) ? (int)$provider_id : null;
    $source      = ($source !== null) ? (int)$source : null;

    $ua  = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 190) : null;
    $ref = isset($_SERVER['HTTP_REFERER'])    ? substr((string)$_SERVER['HTTP_REFERER'], 0, 190)    : null;

    try {
        $sql = 'INSERT INTO coid_events (coid_id, provider_id, event_type, source, user_agent, referer)
                VALUES (?, ?, ?, ?, ?, ?)';
        $st  = db()->prepare($sql);
        $st->execute(array($coid_id, $provider_id, $event_type, $source, $ua, $ref));
    } catch (\Exception $e) {
        // If anything goes wrong, record to PHP error log but never break the site.
        error_log('coid_events insert failed: ' . $e->getMessage());
    } catch (\Error $e) {
        error_log('coid_events insert error: ' . $e->getMessage());
    }
}

/**
 * Get simple 30-day stats for a COID.
 *
 * Returns array:
 * [
 *   'views_30d'      => int,
 *   'forwards_30d'   => int,
 *   'top_providers'  => [ ['display' => 'Facebook', 'count' => 12], ... ],
 *   'last_activity'  => string|null  // 'YYYY-MM-DD HH:MM:SS' or null
 * ]
 */
function coid_stats_last_30d($coid_id) {
    $coid_id = (int)$coid_id;
    $out = array(
        'views_30d'     => 0,
        'forwards_30d'  => 0,
        'top_providers' => array(),
        'last_activity' => null,
    );
    if ($coid_id <= 0) {
        return $out;
    }

    $pdo = db();

    // 1) Counts by event_type for last 30 days
    try {
        $sql = 'SELECT event_type, COUNT(*) AS c
                FROM coid_events
                WHERE coid_id = ?
                  AND created_at >= (NOW() - INTERVAL 30 DAY)
                GROUP BY event_type';
        $st = $pdo->prepare($sql);
        $st->execute(array($coid_id));
        foreach ($st->fetchAll() as $row) {
            $et = (int)$row['event_type'];
            $c  = (int)$row['c'];
            if ($et === COID_EVENT_PROFILE_VIEW) {
                $out['views_30d'] = $c;
            } elseif ($et === COID_EVENT_FORWARD) {
                $out['forwards_30d'] = $c;
            }
        }
    } catch (\Throwable $e) {
        // keep defaults
    }

    // 2) Top providers by forward count (last 30 days)
    try {
        $sql = 'SELECT p.display, e.provider_id, COUNT(*) AS c
                FROM coid_events e
                JOIN providers p ON p.id = e.provider_id
                WHERE e.coid_id = ?
                  AND e.provider_id IS NOT NULL
                  AND e.event_type = ?
                  AND e.created_at >= (NOW() - INTERVAL 30 DAY)
                GROUP BY e.provider_id, p.display
                ORDER BY c DESC
                LIMIT 5';
        $st = $pdo->prepare($sql);
        $st->execute(array($coid_id, COID_EVENT_FORWARD));
        $rows = $st->fetchAll();
        foreach ($rows as $r) {
            $out['top_providers'][] = array(
                'display' => (string)$r['display'],
                'count'   => (int)$r['c'],
            );
        }
    } catch (\Throwable $e) {
        // ignore
    }

    // 3) Last activity (any event)
    try {
        $sql = 'SELECT MAX(created_at) AS last_ts
                FROM coid_events
                WHERE coid_id = ?';
        $st = $pdo->prepare($sql);
        $st->execute(array($coid_id));
        $row = $st->fetch();
        if ($row && !empty($row['last_ts'])) {
            $out['last_activity'] = (string)$row['last_ts'];
        }
    } catch (\Throwable $e) {
        // ignore
    }

    return $out;
}
