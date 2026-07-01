<?php
/* filename: qr_logo.php
 * Generates a QR code for https://careofid.com/{coid}?via=qr
 * Overlays /images/logo.png in the center (if possible).
 * NEW: Renders careofid.com/{COID} ABOVE the QR (outside the QR area) in the same PNG.
 */

require_once __DIR__ . '/include/db.php';
require_once __DIR__ . '/include/phpqrcode/phpqrcode.php';

// IMPORTANT: Do NOT start sessions or call security_headers() here.
// This file must output ONLY image/png and never redirect.

$coidIn = isset($_GET['coid']) ? trim((string)$_GET['coid']) : '';
if ($coidIn === '') {
  http_response_code(400);
  exit('No COID provided');
}

if (!function_exists('find_coid_row_public')) {
  http_response_code(500);
  exit('Missing find_coid_row_public()');
}

$row = find_coid_row_public($coidIn);
if (!$row) {
  http_response_code(404);
  exit('Not found');
}

$coid = (string)$row['coid']; // preserve case exactly

// QR encoded target
$target = 'https://careofid.com/' . rawurlencode($coid) . '?via=qr';

// Text displayed above the QR (human-readable)
$displayText = 'careofid.com/' . $coid;

// Generate QR PNG into memory
ob_start();
QRcode::png($target, null, QR_ECLEVEL_H, 8, 2);
$qrPng = ob_get_clean();

if ($qrPng === false || $qrPng === '') {
  http_response_code(500);
  exit('QR generation failed');
}

function qr_out_headers() {
  header('Content-Type: image/png');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
}

$logoPath = __DIR__ . '/images/logo.png';

if (function_exists('imagecreatefromstring')) {
  $qrImg0 = @imagecreatefromstring($qrPng);
  if ($qrImg0) {

    // Convert QR to truecolor (stable drawing)
    $qrW = imagesx($qrImg0);
    $qrH = imagesy($qrImg0);

    // --- TOP HEADER AREA (text above QR) ---
    $padTop = 14;
    $padSides = 14;
    $gap = 10;

    // choose a GD built-in font (reliable)
    $font = 5;
    $charW = imagefontwidth($font);
    $charH = imagefontheight($font);

    // Compute header height (one line)
    $headerH = $padTop + $charH + $gap;

    // Final canvas
    $outW = $qrW + ($padSides * 2);
    $outH = $headerH + $qrH + $padTop;

    $out = imagecreatetruecolor($outW, $outH);
    imagealphablending($out, false);
    imagesavealpha($out, true);

    $white = imagecolorallocate($out, 255, 255, 255);
    $black = imagecolorallocate($out, 0, 0, 0);

    imagefilledrectangle($out, 0, 0, $outW, $outH, $white);

    // ---- Draw text centered on top ----
    $maxChars = (int)floor(($outW - 20) / max(1, $charW));
    $text = $displayText;

    // truncate if too long (keep end visible)
    if ($maxChars > 0 && strlen($text) > $maxChars) {
      if ($maxChars < 10) {
        $text = substr($text, 0, $maxChars);
      } else {
        $keepStart = (int)max(6, floor(($maxChars - 3) * 0.60));
        $keepEnd   = (int)max(3, ($maxChars - 3) - $keepStart);
        $text = substr($text, 0, $keepStart) . '...' . substr($text, -$keepEnd);
      }
    }

    $tw = strlen($text) * $charW;
    $tx = (int)round(($outW - $tw) / 2);
    $ty = (int)round($padTop);

    imagestring($out, $font, $tx, $ty, $text, $black);

    // ---- Paste QR under the header ----
    $qrX = $padSides;
    $qrY = $headerH;

    // Copy QR pixels (keeps QR pure)
    imagecopy($out, $qrImg0, $qrX, $qrY, 0, 0, $qrW, $qrH);

    // ---- Overlay logo on the QR (optional) ----
    if (function_exists('imagecreatefrompng') && is_file($logoPath)) {
      $logo = @imagecreatefrompng($logoPath);
      if ($logo) {
        $lw = imagesx($logo);
        $lh = imagesy($logo);

        $newLW = (int)round($qrW * 0.22);
        if ($newLW > 0 && $lw > 0) {
          $scale = $lw / $newLW;
          $newLH = (int)round($lh / $scale);

          $dstX = (int)round($qrX + ($qrW - $newLW) / 2);
          $dstY = (int)round($qrY + ($qrH - $newLH) / 2);

          imagealphablending($out, true);
          imagesavealpha($out, true);
          imagealphablending($logo, true);
          imagesavealpha($logo, true);

          // White pad behind logo for readability
          $pad = (int)round($newLW * 0.10);
          $bgX1 = max(0, $dstX - $pad);
          $bgY1 = max(0, $dstY - $pad);
          $bgX2 = min($outW - 1, $dstX + $newLW + $pad);
          $bgY2 = min($outH - 1, $dstY + $newLH + $pad);

          $white2 = imagecolorallocate($out, 255, 255, 255);
          imagefilledrectangle($out, $bgX1, $bgY1, $bgX2, $bgY2, $white2);

          imagecopyresampled($out, $logo, $dstX, $dstY, 0, 0, $newLW, $newLH, $lw, $lh);
          imagedestroy($logo);
        }
      }
    }

    imagedestroy($qrImg0);

    qr_out_headers();
    imagepng($out);
    imagedestroy($out);
    exit;
  }
}

// Fallback: serve plain QR (no logo / no header text)
qr_out_headers();
echo $qrPng;
