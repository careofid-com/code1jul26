<?php /* filename: actions/contact_post.php */
require_once __DIR__ . '/../include/security.php';
require_once __DIR__ . '/../include/render.php';
require_once __DIR__ . '/../include/auth.php';
require_once __DIR__ . '/../include/mailer.php';
require_once __DIR__ . '/../include/db.php';

coid_session_start();
security_headers();
strict_post_only();

if (!csrf_verify($_POST['csrf'] ?? '')) {
    http_response_code(400);
    exit('Bad CSRF');
}

$email   = trim((string)($_POST['email'] ?? ''));
$phone   = trim((string)($_POST['phone'] ?? ''));
$coid    = trim((string)($_POST['coid'] ?? ''));
$subject = trim((string)($_POST['subject'] ?? ''));
$message = trim((string)($_POST['message'] ?? ''));

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $subject === '' || $message === '') {
    flash_add('err', 'Please provide a valid email, subject, and message.');
    header('Location: /contact.php');
    exit;
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

$bodyLines = [
    "New contact form submission from careofid.com",
    "",
    "From email: {$email}",
    "Phone: " . ($phone !== '' ? $phone : '(not provided)'),
    "COID: " . ($coid !== '' ? $coid : '(not provided)'),
    "",
    "Subject: {$subject}",
    "",
    "Message:",
    $message,
    "",
    "----- Meta -----",
    "IP: {$ip}",
    "User-Agent: {$ua}",
];

$body = implode("\n", $bodyLines);
$to   = 'info@careofid.com';
$mailSubject = '[CareOfID Contact] ' . $subject;

/* Try dedicated mailer helper if you add one, otherwise fallback to mail() */
if (function_exists('send_contact_email')) {
    // If you implement send_contact_email($to, $subject, $body)
    @send_contact_email($to, $mailSubject, $body);
} else {
    // Simple fallback using mail()
    @mail(
        $to,
        $mailSubject,
        $body,
        "From: {$email}\r\nReply-To: {$email}\r\n"
    );
}

/* Optional: you could log into DB as well if you want later. */

flash_add('ok', 'Thank you! Your message has been submitted.');

/* Decide where to send the user: dashboard if logged in, otherwise home */
$dest = '/';
if (function_exists('auth_current_user')) {
    $user = auth_current_user();
    if ($user) {
        $dest = '/dashboard';
    }
}

header('Location: ' . $dest);
exit;
