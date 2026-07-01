<?php /* filename: stripe_webhook.php */
require_once __DIR__ . '/include/security.php';
require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/config.php';

security_headers(); // Safe headers; no session needed for webhook

// Stripe sends JSON in the raw body
$payload = file_get_contents('php://input');
$sigHeader = isset($_SERVER['HTTP_STRIPE_SIGNATURE']) ? $_SERVER['HTTP_STRIPE_SIGNATURE'] : '';

if (!defined('STRIPE_WEBHOOK_SECRET') || !STRIPE_WEBHOOK_SECRET) {
    http_response_code(500);
    echo 'Webhook secret not configured';
    exit;
}

/**
 * Verify Stripe signature manually (similar to stripe-php library).
 *
 * Header format:
 *   t=timestamp,v1=signature1,v1=signature2,...
 *
 * We compute: HMAC_SHA256("t.payload", webhook_secret)
 * and compare against each v1.
 */
function coid_verify_stripe_signature($payload, $sigHeader, $secret)
{
    if (!$sigHeader) return false;

    $parts = explode(',', $sigHeader);
    $timestamp = null;
    $signatures = array();

    foreach ($parts as $part) {
        $kv = explode('=', trim($part), 2);
        if (count($kv) !== 2) continue;
        $key = $kv[0];
        $val = $kv[1];

        if ($key === 't') {
            $timestamp = $val;
        } elseif ($key === 'v1') {
            $signatures[] = $val;
        }
    }

    if ($timestamp === null || empty($signatures)) {
        return false;
    }

    // Optional: protect against very old timestamps (5 minutes window)
    $t = (int)$timestamp;
    if ($t > 0) {
        $now = time();
        $tolerance = 5 * 60; // 5 minutes
        if (abs($now - $t) > $tolerance) {
            // You may comment this out during testing if time-skew is an issue
            return false;
        }
    }

    $signedPayload = $timestamp . '.' . $payload;
    $computed = hash_hmac('sha256', $signedPayload, $secret);

    foreach ($signatures as $sig) {
        if (hash_equals($computed, $sig)) {
            return true;
        }
    }
    return false;
}

// 1) Verify signature
if (!coid_verify_stripe_signature($payload, $sigHeader, STRIPE_WEBHOOK_SECRET)) {
    http_response_code(400);
    echo 'Invalid signature';
    exit;
}

// 2) Decode event JSON
$event = json_decode($payload, true);
if (!is_array($event) || !isset($event['type'])) {
    http_response_code(400);
    echo 'Invalid payload';
    exit;
}

$type = $event['type'];

// 3) We only care (for now) about checkout.session.completed
if ($type !== 'checkout.session.completed') {
    // For other event types, just acknowledge
    http_response_code(200);
    echo 'Ignored event type';
    exit;
}

// 4) Extract session object and email
if (!isset($event['data']['object']) || !is_array($event['data']['object'])) {
    http_response_code(400);
    echo 'Invalid event object';
    exit;
}

$session = $event['data']['object'];
$email   = '';

// Stripe may put email in different fields
if (isset($session['customer_details']['email']) && $session['customer_details']['email'] !== '') {
    $email = (string)$session['customer_details']['email'];
} elseif (isset($session['customer_email']) && $session['customer_email'] !== '') {
    $email = (string)$session['customer_email'];
}

if ($email === '') {
    // Nothing to map; acknowledge to Stripe
    http_response_code(200);
    echo 'No email found';
    exit;
}

try {
    $pdo = db();

    // 5) Find user by email
    $st = $pdo->prepare('SELECT id, plan_status FROM users WHERE email = ? LIMIT 1');
    $st->execute(array($email));
    $user = $st->fetch();

    if (!$user) {
        // Payment from an email that is not a registered user → ignore gracefully
        http_response_code(200);
        echo 'No matching user';
        exit;
    }

    $uid         = (int)$user['id'];
    $planStatus  = isset($user['plan_status']) ? (string)$user['plan_status'] : 'free';

    // 6) Mark as paid (idempotent: doing it twice is harmless)
    if ($planStatus !== 'paid') {
        $up = $pdo->prepare('UPDATE users
                             SET plan_status = "paid",
                                 plan_activated_at = NOW()
                             WHERE id = ?');
        $up->execute(array($uid));
    }

    http_response_code(200);
    echo 'User upgraded to paid';
    exit;

} catch (Throwable $e) {
    // For production, you might log this error to a file:
    // error_log('Stripe webhook error: ' . $e->getMessage());
    http_response_code(500);
    echo 'Server error';
    exit;
}
