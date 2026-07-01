<?php /* filename: signup.php */
require_once __DIR__ . '/include/security.php';
require_once __DIR__ . '/include/render.php';
require_once __DIR__ . '/include/auth.php';
require_once __DIR__ . '/include/country_codes.php';

coid_session_start();
security_headers();
$next = isset($_GET['next']) ? (string)$_GET['next'] : '/dashboard';
// Allow only site-relative paths to prevent open redirect
if ($next === '' || preg_match('~^(https?:)?//~i', $next) || strpos($next, "\n") !== false || strpos($next, "\r") !== false) {
  $next = '/dashboard';
}
if ($next[0] !== '/') $next = '/' . ltrim($next, '/');

/* If already logged in, optionally redirect to dashboard */
if (!empty($_SESSION['user']) && isset($_SESSION['user']['id'])) {
    // header('Location: /dashboard.php'); exit;
}

page_head('Sign up � careofid');
page_nav();
page_flash();

/* Default phone prefill */
$prefill_cc    = '+1';
$prefill_local = '';

?>
<h1>Sign up</h1>

<div class="card">
  <style>
    .phone-row { display:flex; gap:8px; align-items:center; }
    .phone-row select { width: 260px; }
    @media (max-width:600px){
      .phone-row { flex-wrap:wrap; }
      .phone-row select { width:100%; }
    }
    .hint { color:#666; font-size:13px; margin-top:4px; }
    .dp-wrapper { margin-bottom:12px; display:none; align-items:center; gap:10px; }
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

  <!-- Preview of chosen DP after "Use this picture" -->
  <div class="dp-wrapper" id="dp_current_wrapper">
    <div style="font-weight:bold;">Profile picture preview</div>
    <img
      id="dp_current_preview"
      src=""
      alt="Profile picture"
      style="width:80px;height:80px;display:none;"
    >
  </div>

  <form method="post" action="/actions/signup_post.php" enctype="multipart/form-data">
    
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token(),ENT_QUOTES); ?>">
    <input type="hidden" name="next" value="<?php echo htmlspecialchars($next, ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="dp_cropped" id="dp_cropped" value="">

    <label>Email</label>
    <input type="email" name="email" required>

    <label>First name</label>
    <input type="text" name="first_name" required>

    <label>Last name</label>
    <input type="text" name="last_name" required>

    <label>Phone (optional)</label>
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
    <div class="hint">Stored in E.164 format (example: +14145551200). Leave blank if you prefer not to share.</div>

    <label style="margin-top:12px;">Password</label>
    <input type="password" name="password" required minlength="8">
    <div class="hint">Minimum 8 characters.</div>

    <label style="margin-top:12px;">Profile picture (optional)</label>
    <input
      type="file"
      name="dp"
      id="dp_file"
      accept="image/*"
      style="margin-bottom:4px;"
    >
    <div class="hint">Choose an image, then drag and zoom to fit inside the circle. Click "Use this picture" to save.</div>

    <!-- Cropping UI (same structure as account.php) -->
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
      <button type="submit">Sign up</button>
    </div>
  </form>
</div>

<script src="/js/dp_crop.js"></script>

<?php page_foot(); ?>