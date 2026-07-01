<?php /* filename: privacy.php */
require_once __DIR__ . '/include/security.php';
require_once __DIR__ . '/include/render.php';

coid_session_start();
security_headers();

page_head('Privacy Policy — careofid');
page_nav();
page_flash();
?>
<div class="card">
  <h1>Privacy Policy</h1>
  <p><em>Last updated: <?php echo date('F j, Y'); ?></em></p>

  <p>
    CareOfID (“we”, “our”, “us”) provides a platform that allows users to create a unique CareOfID (COID),
    manage their social and web profiles, and share a unified digital identity. This Privacy Policy explains
    how we collect, use, store, and protect your information when you use:
  </p>
  <ul>
    <li>The CareOfID website (https://careofid.com)</li>
    <li>The CareOfID Android application (WebView wrapper)</li>
    <li>Any related features, forwarding services, QR codes, and admin tools</li>
  </ul>

  <h2>1. Information We Collect</h2>

  <h3>1.1 Information You Provide</h3>
  <p>When you create and manage your account, you may provide:</p>
  <ul>
    <li>First and last name</li>
    <li>Email address</li>
    <li>Phone number (optional)</li>
    <li>Your chosen COID username</li>
    <li>Social media handles and profile URLs</li>
    <li>Messages sent via our contact or support forms</li>
  </ul>

  <h3>1.2 Automatically Collected Information</h3>
  <p>When you use CareOfID, we may automatically collect:</p>
  <ul>
    <li>Device and browser information</li>
    <li>IP address and approximate region</li>
    <li>Pages visited and referral URLs</li>
    <li>Search terms used on our platform</li>
    <li>Non-identifiable events such as profile views and link clicks</li>
  </ul>

  <h3>1.3 Information from the Android App</h3>
  <p>
    The CareOfID Android app displays https://careofid.com in a secure WebView. The app itself does not collect
    additional data beyond what the website collects. The app does <strong>not</strong> request access to contacts,
    location, photos, files, camera, or microphone. Only Internet access is used to load the site.
  </p>

  <h2>2. How We Use Your Information</h2>
  <p>We use your information to:</p>
  <ul>
    <li>Create and manage your CareOfID account</li>
    <li>Display your COID profile and associated links</li>
    <li>Provide URL and QR-based forwarding to your selected profiles</li>
    <li>Improve search, lookup accuracy, and platform performance</li>
    <li>Monitor usage in aggregate for capacity planning and security</li>
    <li>Respond to support requests and contact form submissions</li>
  </ul>
  <p>We do <strong>not</strong> sell your personal data.</p>

  <h2>3. Sharing of Information</h2>
  <p>We may share information only in the following situations:</p>
  <ul>
    <li>With service providers who help us operate the platform (e.g., email delivery, hosting, analytics)</li>
    <li>When required by law, regulation, or legal process</li>
    <li>To investigate and prevent misuse, fraud, or security incidents</li>
  </ul>
  <p>
    We do not sell or rent your personal information to third parties. Social links you choose to make public
    through your COID profile may be visible to anyone who visits your public profile URL.
  </p>

  <h2>4. Cookies and Similar Technologies</h2>
  <p>
    CareOfID uses technical cookies and similar technologies to maintain login sessions, protect against CSRF,
    and improve site performance. We may use basic analytics to understand traffic patterns in aggregate.
    We do not use cookies for interest-based advertising.
  </p>

  <h2>5. Data Retention</h2>
  <p>
    We retain your account data for as long as your account remains active or as needed to provide the service.
    We may retain certain log and backup data for a reasonable period for security, legal, and operational reasons.
    If you request deletion of your account, we will delete or anonymize your personal data subject to any
    legal obligations we have to retain it.
  </p>

  <h2>6. Security</h2>
  <p>
    We use reasonable technical and organizational measures to protect your information, including HTTPS
    encryption, secure password hashing, access controls, and internal safeguards around admin tools.
    However, no system can ever be completely secure, and we cannot guarantee absolute security.
  </p>

  <h2>7. Your Rights and Choices</h2>
  <p>Depending on your location, you may have rights to:</p>
  <ul>
    <li>Access and update your account information</li>
    <li>Change or delete your COID and associated links</li>
    <li>Request deletion of your account</li>
  </ul>
  <p>
    Most updates can be made directly via your account dashboard. For other requests, contact us at
    <a href="mailto:info@careofid.com">info@careofid.com</a>.
  </p>

  <h2>8. Children’s Privacy</h2>
  <p>
    CareOfID is not directed to children under 13, and we do not knowingly collect personal information
    from children under 13. If you believe a child has provided us with personal information, please contact us
    so we can take appropriate action.
  </p>

  <h2>9. Changes to This Policy</h2>
  <p>
    We may update this Privacy Policy from time to time. When we make material changes, we will update the
    “Last updated” date at the top of this page. Your continued use of CareOfID after changes become effective
    means you accept the revised Policy.
  </p>

  <h2>10. Contact Us</h2>
  <p>
    If you have questions, concerns, or requests regarding this Privacy Policy or your data, you can contact us at:
  </p>
  <p>
    Email: <a href="mailto:info@careofid.com">info@careofid.com</a>
  </p>
</div>
<?php
page_foot();
