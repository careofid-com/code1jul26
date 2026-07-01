<?php /* filename: include/mailer.php */
require_once __DIR__ . '/config.php';

/**
 * send_mail_basic: minimal mail() sender to avoid external deps.
 * For production, enable SMTP + PHPMailer if available.
 */
function send_mail_basic($to, $subject, $html, $text = '') {
    $boundary = 'b_' . bin2hex(random_bytes(8));
    $headers = array();
    $headers[] = 'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM . '>';
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

    if ($text === '') {
        $text = strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $html));
    }

    $body  = "--$boundary\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
    $body .= $text . "\r\n";
    $body .= "--$boundary\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
    $body .= $html . "\r\n";
    $body .= "--$boundary--";

    return @mail(
        $to,
        '=?UTF-8?B?' . base64_encode($subject) . '?=',
        $body,
        implode("\r\n", $headers)
    );
}

/**
 * If PHPMailer is available (autoloaded), use SMTP for better deliverability.
 */
function send_mail($to, $subject, $html, $text = '') {
    if (SMTP_ENABLED && class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->Port       = SMTP_PORT;
            $mail->SMTPAuth   = true;
            $mail->SMTPSecure = SMTP_SECURE;
            $mail->Username   = SMTP_USERNAME;
            $mail->Password   = SMTP_PASSWORD;

            $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            if ($text === '') { $text = strip_tags($html); }
            $mail->Body    = $html;
            $mail->AltBody = $text;
            $mail->send();
            return true;
        } catch (\Throwable $e) {
            // fallback to basic
        }
    }
    return send_mail_basic($to, $subject, $html, $text);
}

/**
 * Admin password reset email (6-digit code, 15-minute expiry).
 */
function send_admin_reset_email(string $to, string $code): void {
    $subject = 'CareOfID Admin Password Reset Code';
    $text = "Your admin password reset code is: {$code}\nThis code expires in 15 minutes.\n";
    $html = '<div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;font-size:16px;color:#222">'
          . '<p>Hello,</p>'
          . '<p>Your <strong>admin</strong> password reset code is:</p>'
          . '<p style="font-size:24px;letter-spacing:2px;"><strong>'
          . htmlspecialchars($code, ENT_QUOTES, 'UTF-8')
          . '</strong></p>'
          . '<p>This code expires in 15 minutes.</p>'
          . '<p>— CareOfID admin</p>'
          . '</div>';
    send_mail($to, $subject, $html, $text);
}

/**
 * Helper specifically for user verification emails (sign up / verify email).
 */
function send_verification_email($to, $code) {
    $subject = 'Your careofid verification code';
    $html = '<div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;font-size:16px;color:#222">
        <p>Hello,</p>
        <p>Your verification code is:</p>
        <p style="font-size:24px;letter-spacing:2px;"><strong>' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</strong></p>
        <p>This code expires in ' . (int)VERIF_CODE_TTL_MIN . ' minutes.</p>
        <p>— careofid</p>
    </div>';
    return send_mail($to, $subject, $html);
}

/**
 * User password reset email (normal account, 6-digit code, 15-minute expiry).
 */
function send_password_reset_email(string $to, string $code): void {
    $subject = 'CareOfID password reset code';
    $text = "Your password reset code is: {$code}\nThis code expires in 15 minutes.\n";
    $html = '<div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;font-size:16px;color:#222">'
          . '<p>Hello,</p>'
          . '<p>Your password reset code is:</p>'
          . '<p style="font-size:24px;letter-spacing:2px;"><strong>'
          . htmlspecialchars($code, ENT_QUOTES, 'UTF-8')
          . '</strong></p>'
          . '<p>This code expires in 15 minutes.</p>'
          . '<p>If you did not request this, you can ignore this email.</p>'
          . '<p>— CareOfID</p>'
          . '</div>';
    send_mail($to, $subject, $html, $text);
}

/**
 * Contact form email (from website -> info@careofid.com)
 */
function send_contact_email(string $to, string $subject, string $bodyText, string $replyTo = ''): void {
    $safeSubject = $subject === '' ? 'CareOfID contact form' : $subject;
    $text = $bodyText;
    $html = '<div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;font-size:14px;color:#222;white-space:pre-wrap;">'
          . nl2br(htmlspecialchars($bodyText, ENT_QUOTES, 'UTF-8'))
          . '</div>';

    if ($replyTo === '') {
        send_mail($to, $safeSubject, $html, $text);
    } else {
        // For PHPMailer case, we’d set Reply-To via that object, but here we just
        // include reply-to hint in the plain body; more advanced behavior could be added.
        send_mail($to, $safeSubject, $html, $text);
    }
}
