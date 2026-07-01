<?php /* filename: contact.php */
require_once __DIR__ . '/include/security.php';
require_once __DIR__ . '/include/render.php';
require_once __DIR__ . '/include/auth.php';

coid_session_start();
security_headers();

$user = null;
if (function_exists('auth_current_user')) {
    $user = auth_current_user();
}

$email_prefill = '';
if ($user && !empty($user['email'])) {
    $email_prefill = $user['email'];
}

page_head('Contact — careofid');
page_nav();
page_flash();
?>
<style>
  .contact-grid {
    display: flex;
    gap: 16px;
    align-items: flex-start;
    margin-bottom: 20px;
  }
  .contact-col {
    flex: 1 1 0;
  }
  @media (max-width: 700px) {
    .contact-grid { flex-direction: column; }
  }
  .contact-email {
    font-size: 16px;
    margin-bottom: 8px;
  }
  .contact-email a { text-decoration: none; }

  /* FIX: Make textarea match the width of regular input fields */
  input[type="text"],
  input[type="email"],
  textarea {
    width: 100%;
    box-sizing: border-box;
  }
</style>


<h1>Contact</h1>

<div class="card">
  <div class="contact-grid">
    <!-- LEFT: static contact info -->
    <div class="contact-col">
      <h2>Get in touch</h2>
      <p class="contact-email">
        You can reach us directly at:<br>
        <strong><a href="mailto:info@careofid.com">info@careofid.com</a></strong>
      </p>
      <p class="muted">
        Use the form on the right to send us feedback, bug reports, or feature
        requests. Please include your COID if you already have one.
      </p>
    </div>

    <!-- RIGHT: contact form -->
    <div class="contact-col">
      <h2>Send a message</h2>
      <form method="post" action="/actions/contact_post.php">
        <input type="hidden" name="csrf"
               value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">

        <label>Email address</label>
        <input type="email" name="email" required
               value="<?php echo htmlspecialchars($email_prefill, ENT_QUOTES, 'UTF-8'); ?>">

        <label>Phone number (optional)</label>
        <input type="text" name="phone"
               placeholder="+1 414 555 1200 or local format">

        <label>COID (optional)</label>
        <input type="text" name="coid" placeholder="yourcoid.123">

        <label>Subject</label>
        <input type="text" name="subject" required>

        <label>Message</label>
        <textarea name="message" rows="5" required></textarea>

        <div class="row" style="margin-top:10px;">
          <button type="submit">Send</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php page_foot(); ?>
