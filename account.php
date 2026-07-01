<?php /* filename: account.php */
require_once __DIR__ . '/include/security.php';
require_once __DIR__ . '/include/render.php';
require_once __DIR__ . '/include/auth.php';
require_once __DIR__ . '/include/country_codes.php'; // central country list

coid_session_start();
security_headers();
auth_require_login();
$u = auth_reload_user();

/* -----------------------------
   PREFILL PHONE NUMBER LOGIC
   ----------------------------- */
$prefill_cc = '+1';
$prefill_local = '';

if (!empty($u['phone_e164']) && preg_match('/^\+([0-9]+)$/', $u['phone_e164'], $m)) {
    $digits = $m[1]; // digits after the +

    // Build list of numeric codes without '+'
    $allCodes = array_map(function($c){ return ltrim($c['code'], '+'); }, $COUNTRIES);

    // Sort descending by length for longest-prefix matching
    usort($allCodes, function($a,$b){ return strlen($b)-strlen($a); });

    foreach ($allCodes as $code) {
        if (strpos($digits, $code) === 0) {
            $prefill_cc = '+'.$code;
            $prefill_local = substr($digits, strlen($code));
            break;
        }
    }
}

page_head('Account — careofid');
page_nav();
page_flash();
?>
<h1>Account</h1>

<div class="card">
  <style>
    .phone-row { display:flex; gap:8px; align-items:center; }
    .phone-row select { width: 260px; }
    @media (max-width:600px){
      .phone-row { flex-wrap:wrap; }
      .phone-row select { width:100%; }
    }
    .hint { color:#666; font-size:13px; margin-top:4px; }
    .dp-wrapper { margin-bottom:12px; display:flex; align-items:center; gap:10px; }
    .dp-wrapper img { border-radius:50%; object-fit:cover; border:1px solid #ddd; }

    .dp-crop-area {
      width: 220px;
      height: 220px;
      border-radius: 50%;
      overflow: hidden;
      border: 1px solid #ccc;
      margin-top: 8px;
      margin-bottom: 6px;
      position: relative;
      background: #f5f5f5;
      display: none;
    }
    .dp-crop-area-inner {
      position: absolute;
      inset: 0;
      overflow: hidden;
    }
    .dp-crop-img {
      position: absolute;
      left: 50%;
      top: 50%;
      transform: translate(-50%, -50%);
      max-width: none;
      -webkit-user-drag: none;
      user-select: none;
      cursor: grab;
    }
    .dp-crop-controls {
      display:none;
      margin-bottom:6px;
    }
    .dp-crop-controls input[type=range] {
      width: 220px;
    }
    .dp-crop-buttons {
      display:flex;
      margin-bottom:6px;
      gap:8px;
      flex-wrap:wrap;
    }
  </style>

  <?php if (!empty($u['dp_path'])): ?>
    <div class="dp-wrapper" id="dp_current_wrapper">
      <div style="font-weight:bold;">Current profile picture</div>
      <img
        id="dp_current_preview"
        src="<?php echo htmlspecialchars($u['dp_path'], ENT_QUOTES, 'UTF-8'); ?>"
        alt="Profile picture"
        style="width:80px;height:80px;"
      >
    </div>
  <?php else: ?>
    <div class="dp-wrapper" id="dp_current_wrapper" style="display:none;">
      <div style="font-weight:bold;">Current profile picture</div>
      <img
        id="dp_current_preview"
        src=""
        alt="Profile picture"
        style="width:80px;height:80px;display:none;"
      >
    </div>
  <?php endif; ?>

  <form method="post" action="/actions/account_update_post.php" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token(),ENT_QUOTES); ?>">
    <input type="hidden" name="dp_cropped" id="dp_cropped" value="">

    <label>First name</label>
    <input type="text" name="first_name" required value="<?php echo htmlspecialchars($u['first_name']); ?>">

    <label>Last name</label>
    <input type="text" name="last_name" required value="<?php echo htmlspecialchars($u['last_name']); ?>">

    <label>Phone</label>

    <div class="phone-row">
      <select name="phone_cc">
        <?php foreach ($COUNTRIES as $c): ?>
          <option value="<?php echo htmlspecialchars($c['code']); ?>"
            <?php echo ($c['code'] === $prefill_cc ? 'selected' : ''); ?>>
            <?php echo htmlspecialchars($c['name'].' ('.$c['code'].')'); ?>
          </option>
        <?php endforeach; ?>
      </select>

      <input type="text" name="phone_local"
             value="<?php echo htmlspecialchars($prefill_local); ?>"
             placeholder="rest of number (digits only)">
    </div>

    <div class="hint">Stored in E.164 format (example: +14145551200).</div>

    <label style="margin-top:12px;">Profile picture (optional)</label>
    <input
      type="file"
      name="dp"
      id="dp_file"
      accept="image/*"
      style="margin-bottom:4px;"
    >
    <div class="hint">Choose an image, then drag and zoom to fit inside the circle. Click "Use this picture" to save.</div>

    <!-- Cropping UI -->
    <div id="dp_crop_area" class="dp-crop-area">
      <div class="dp-crop-area-inner">
        <img id="dp_crop_img" class="dp-crop-img" src="" alt="Crop preview">
      </div>
    </div>

    <div id="dp_crop_controls" class="dp-crop-controls">
      <div class="hint" style="margin-top:4px;">Zoom</div>
      <input type="range" id="dp_zoom" min="0.5" max="2" step="0.01" value="1">
    </div>

    <div id="dp_crop_buttons" class="dp-crop-buttons">
      <button type="button" id="dp_use_btn">Use this picture</button>
      <button type="button" id="dp_cancel_btn">Cancel</button>
    </div>

    <div class="row" style="margin-top:12px;">
      <button type="submit">Update</button>
    </div>
  </form>
</div>

<div class="card">
  <h2>Change password</h2>
  <form method="post" action="/actions/account_update_post.php">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token(),ENT_QUOTES); ?>">
    <input type="hidden" name="mode" value="password">
    <label>Current password</label>
    <input type="password" name="current" required>
    <label>New password</label>
    <input type="password" name="password" required minlength="8">
    <div class="row"><button type="submit">Change password</button></div>
  </form>
</div>

<!-- JS must be in a web-accessible folder (NOT /include) -->
<script src="/js/dp_crop.js"></script>

<?php page_foot(); ?>
