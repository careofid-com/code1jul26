<?php /* filename: billing_cancel.php */
require_once __DIR__ . '/include/security.php';
require_once __DIR__ . '/include/render.php';
require_once __DIR__ . '/include/auth.php';

coid_session_start();
security_headers();

page_head('Payment cancelled — careofid');
page_nav();
page_flash();
?>
<style>
  .centercol { max-width: 640px; margin: 0 auto; }
  .card-cancel {
    border:1px solid #fee2e2;
    background:#fef2f2;
    border-radius:12px;
    padding:16px;
    margin-top:14px;
  }
  .card-cancel h1 {
    margin-top:0;
    font-size:20px;
    color:#991b1b;
  }
  .card-cancel p {
    font-size:14px;
    color:#7f1d1d;
    margin:6px 0;
  }
  .card-muted {
    border:1px solid #e5e7eb;
    background:#f9fafb;
    border-radius:10px;
    padding:12px;
    margin-top:10px;
    font-size:13px;
    color:#4b5563;
  }
  @media (max-width:600px){
    .card-cancel h1 { font-size:18px; }
    .card-cancel p, .card-muted { font-size:12px; }
  }
</style>

<div class="centercol">
  <div class="card-cancel">
    <h1>Payment cancelled</h1>
    <p>
      It looks like you cancelled the checkout or closed the payment window.
      Your CareOfID plan remains on the current level (Free or Paid).
    </p>
  </div>

  <div class="card-muted">
    <p>
      You can continue using CareOfID as usual. If you change your mind,
      you can upgrade again from the <a href="/handles">Update handles</a> page.
    </p>
    <p style="margin-top:6px;">
      <a class="btn" href="/handles">Back to Update handles</a>
    </p>
  </div>
</div>

<?php
page_foot();
