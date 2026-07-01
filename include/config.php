<?php /* filename: include/config.php */
if (!defined('COID_BOOT')) { define('COID_BOOT', 1); }

/**
 * App configuration (DB, email, security).
 * NOTE: Rotate secrets after testing. Keep this file out of web access (blocked in .htaccess).
 */

define('APP_NAME', 'careofid');
define('APP_BASE_URL', 'https://careofid.com'); // no trailing slash

# --- Database ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'pakregistry_carecomdb');
define('DB_USER', 'pakregistry_carecomuser');
define('DB_PASS', 'Car@20730');
define('DB_CHARSET', 'utf8mb4');

# --- Email ---
define('MAIL_FROM', 'info@careofid.com');
define('MAIL_FROM_NAME', 'careofid');
# If you install PHPMailer via cPanel or vendor, you can enable SMTP here.
define('SMTP_ENABLED', false);       // set true if PHPMailer available + SMTP ready
define('SMTP_HOST', 'mail.careofid.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'info@careofid.com');
define('SMTP_PASSWORD', 'CHANGE_ME'); // set in cPanel
define('SMTP_SECURE', 'tls');         // 'tls' or 'ssl'

# --- Security ---
define('SESSION_NAME', 'coid_sid');
define('CSRF_KEY', '__csrf');              // session key
define('VERIF_CODE_TTL_MIN', 15);          // verification code validity
define('LOGIN_MAX_ATTEMPTS', 7);           // soft threshold before backoff
define('RATE_WINDOW_SEC', 900);            // 15 minutes window
define('RATE_LIMIT_SIGNUP', 10);           // per IP per window
define('RATE_LIMIT_LOGIN',  20);
define('RATE_LIMIT_EMAIL',  8);            // send verification/resend

# --- COID rules ---
define('COID_MIN_LEN', 3);
define('COID_MAX_LEN', 32);

# --- Misc ---
define('TIMEZONE_DEFAULT', 'America/Chicago');
date_default_timezone_set(TIMEZONE_DEFAULT);



// Stripe webhook secret (from Stripe Dashboard → Developers → Webhooks)
// Replace the placeholder with your real "Signing secret" that starts with "whsec_..."
if (!defined('STRIPE_WEBHOOK_SECRET')) {
    define('STRIPE_WEBHOOK_SECRET', 'whsec_cEHD1auyQldjU5UgyxpSC63y6aKpdsjX');
}


