<?php /* filename: 404.php */
require_once __DIR__ . '/include/security.php';
require_once __DIR__ . '/include/render.php';

coid_session_start();
security_headers();
http_response_code(404);

page_head('Page not found — CareOfID');
page_nav();
page_flash();
?>
<div class="centercol">
  <div class="card">
    <h1>We couldn’t find that page.</h1>
    <p class="muted">The page you requested doesn’t exist or may have moved.</p>
    <div class="row" style="margin-top:12px; gap:10px;">
      <a class="btn" href="/">Go to Home</a>
      <a class="btn secondary" href="/contact">Contact</a>
    </div>
  </div>
</div>
<?php page_foot(); ?>
