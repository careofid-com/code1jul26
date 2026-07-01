<?php /* filename: about.php */
require_once __DIR__ . '/include/security.php';
require_once __DIR__ . '/include/render.php';

coid_session_start();
security_headers();

page_head('About — careofid');
page_nav();
page_flash();
?>

<div class="card">
  <style>
    .about-text {
      font-size: 15px;
      line-height: 1.55;
      color: #333;
    }
    .about-title {
      font-size: 22px;
      font-weight: 600;
      margin-bottom: 6px;
    }
    .about-sub {
      font-size: 14px;
      color: #666;
      margin-bottom: 18px;
    }
    @media (max-width:560px) {
      .about-title { font-size: 20px; }
      .about-text { font-size: 14px; }
      .about-sub { font-size: 13px; }
    }
  </style>

  <h1 class="about-title">About CareOfID</h1>
  <div class="about-sub">One ID. Every Social Link.</div>

  <div class="about-text">
    CareOfID is a digital identity platform built to simplify how people share and manage their online presence. 
    It provides a single, memorable COID that brings all social profiles, websites, and digital destinations together 
    in one trusted place.

    <br><br>

    Instead of sending multiple links to different platforms, users share one COID that instantly directs visitors 
    to the correct profile or website. Each COID has a secure, personalized page displaying verified handles, 
    optional websites, and a scannable QR code for seamless sharing online and offline<a href="https://www.careofid.com/admin" style="text-decoration:none;">.</a>


    <br><br>

    CareOfID is designed with reliability, simplicity, and security at its core. All accounts require email 
    verification, and users manage their identity through an intuitive dashboard that allows them to add, update, 
    or remove handles at any time. New platforms can be introduced by users or administrators, ensuring the system 
    adapts as the digital world evolves.

    <br><br>

    Whether you are a professional, creator, or business owner, CareOfID helps you present a consistent, clear, 
    and credible digital identity. Our mission is to make online discovery effortless and trustworthy, with 
    future enhancements focused on mobile apps, deeper integrations, and richer personalization options.

    <br><br>

    CareOfID continues to evolve with one simple idea at its heart:  
    bringing all your digital connections together under a single, reliable identity.
  </div>
</div>

<?php page_foot(); ?>
