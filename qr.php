<?php /* filename: qr.php */
require_once __DIR__ . '/include/security.php';
require_once __DIR__ . '/include/router.php';
require_once __DIR__ . '/include/db.php';

coid_session_start();
security_headers();

// Validate input
$coid = isset($_GET['coid']) ? trim((string)$_GET['coid']) : '';
if ($coid === '') { http_response_code(400); exit('Bad request'); }

// Ensure COID exists and get its exact case
$row = find_coid_row($coid);
if (!$row) { http_response_code(404); exit('Not found'); }

// Target URL for the QR code
$targetUrl = 'https://careofid.com/' . $row['coid'];

// Use Google Chart QR as the generator (server-side fetch, served from your origin -> CSP safe)
$qrApi = 'https://chart.googleapis.com/chart?cht=qr&chs=300x300&chld=L|0&chl=' . urlencode($targetUrl);

// Fetch binary PNG
$png = null;

// Prefer cURL if available
if (function_exists('curl_init')) {
    $ch = curl_init($qrApi);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    $png = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http !== 200) { $png = null; }
} elseif (ini_get('allow_url_fopen')) {
    $png = @file_get_contents($qrApi);
}

// Fallback (very small 1x1 gray pixel) if upstream fails
if ($png === false || $png === null) {
    // 1x1 PNG pixel
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAucB9d3l1T8AAAAASUVORK5CYII=');
}

// Serve image
header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400'); // cache 1 day
echo $png;
