<?php /* filename: activity.php */
require_once __DIR__ . '/include/security.php';
require_once __DIR__ . '/include/render.php';
require_once __DIR__ . '/include/auth.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/analytics.php';
require_once __DIR__ . '/include/providers.php';

coid_session_start();
security_headers();
auth_require_login();
$user = auth_reload_user();

// Load user COID
$st = db()->prepare('SELECT * FROM coids WHERE user_id = ? LIMIT 1');
$st->execute(array($user['id']));
$co = $st->fetch();

page_head('Activity — careofid');
page_nav();
page_flash();

echo '<h1>Activity</h1>';
echo '<p class="muted" style="margin-top:4px;margin-bottom:16px;">';
echo '<a href="/dashboard">Back to Dashboard</a> · ';
echo '<a href="/handles">Update Handles</a> · ';
echo '<a href="/account">Account</a>';
echo '</p>';

if (!$co) {
    echo '<div class="card">';
    echo '<p>You have not created a COID yet. Create your COID first to see activity.</p>';
    echo '<p><a class="btn" href="/dashboard">Go to Dashboard</a></p>';
    echo '</div>';
    page_foot();
    exit;
}

$coid_id = (int)$co['id'];
$stats   = coid_stats_last_30d($coid_id);

echo '<div class="card">';
echo '<h2>Summary (last 30 days)</h2>';

if ($stats['views_30d'] === 0 && $stats['forwards_30d'] === 0 && $stats['last_activity'] === null) {
    echo '<p class="muted">No activity recorded yet. Share your COID to start seeing views and link clicks.</p>';
} else {
    echo '<p>Profile views: <strong>' . (int)$stats['views_30d'] . '</strong></p>';
    echo '<p>Link clicks: <strong>' . (int)$stats['forwards_30d'] . '</strong></p>';

    if (!empty($stats['top_providers'])) {
        echo '<p>Top links: ';
        $parts = array();
        foreach ($stats['top_providers'] as $tp) {
            $parts[] = h($tp['display']) . ' (' . (int)$tp['count'] . ')';
        }
        echo implode(', ', $parts);
        echo '</p>';
    }

    if (!empty($stats['last_activity'])) {
        $last = htmlspecialchars($stats['last_activity'], ENT_QUOTES, 'UTF-8');
        echo '<p class="muted" style="margin-top:8px;">Last activity: ' . $last . '</p>';
    }
}

echo '</div>';

/* Detailed recent events */
echo '<div class="card">';
echo '<h2>Detailed events</h2>';

$pdo = db();
$rows = array();
try {
    $sql = 'SELECT e.created_at, e.event_type, e.source, e.user_agent,
                   p.display AS provider_display
            FROM coid_events e
            LEFT JOIN providers p ON p.id = e.provider_id
            WHERE e.coid_id = ?
            ORDER BY e.created_at DESC
            LIMIT 50';
    $st = $pdo->prepare($sql);
    $st->execute(array($coid_id));
    $rows = $st->fetchAll();
} catch (\Throwable $e) {
    $rows = array();
}

if (!$rows) {
    echo '<p class="muted">No events yet.</p>';
} else {
    echo '<div style="overflow-x:auto;">';
    echo '<table border="0" cellpadding="6" cellspacing="0" style="width:100%;border-collapse:collapse;font-size:14px;">';
    echo '<thead>';
    echo '<tr style="border-bottom:1px solid #ddd;">';
    echo '<th align="left">Time</th>';
    echo '<th align="left">Type</th>';
    echo '<th align="left">Source</th>';
    echo '<th align="left">Link</th>';
    echo '<th align="left">User agent (short)</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';

    foreach ($rows as $r) {
        $time  = htmlspecialchars($r['created_at'], ENT_QUOTES, 'UTF-8');

        // Event type
        $etype = (int)$r['event_type'];
        if ($etype === COID_EVENT_PROFILE_VIEW) {
            $etypeText = 'Profile view';
        } elseif ($etype === COID_EVENT_FORWARD) {
            $etypeText = 'Link click';
        } else {
            $etypeText = 'Other';
        }

        // Source
        $src = (int)$r['source'];
        if ($src === COID_SOURCE_WEB) {
            $srcText = 'Web';
        } elseif ($src === COID_SOURCE_QR) {
            $srcText = 'QR';
        } elseif ($src === COID_SOURCE_SHARE) {
            $srcText = 'Share';
        } else {
            $srcText = 'Unknown';
        }

        // Provider / link label
        $provLabel = $r['provider_display'] ? $r['provider_display'] : '';
        if ($etype === COID_EVENT_PROFILE_VIEW) {
            $linkLabel = 'Profile';
        } else {
            $linkLabel = $provLabel !== '' ? $provLabel : '—';
        }

        // User agent short
        $ua = $r['user_agent'] ? $r['user_agent'] : '';
        $uaShort = $ua;
        if (strlen($uaShort) > 50) {
            $uaShort = substr($uaShort, 0, 47) . '...';
        }
        $uaShort = htmlspecialchars($uaShort, ENT_QUOTES, 'UTF-8');

        echo '<tr style="border-top:1px solid #f0f0f0;">';
        echo '<td>' . $time . '</td>';
        echo '<td>' . h($etypeText) . '</td>';
        echo '<td>' . h($srcText) . '</td>';
        echo '<td>' . h($linkLabel) . '</td>';
        echo '<td>' . $uaShort . '</td>';
        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';
    echo '</div>';
}

echo '</div>';

page_foot();
