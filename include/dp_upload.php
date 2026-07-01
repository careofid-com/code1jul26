<?php
/* filename: include/dp_upload.php
   Profile picture upload helper for CareOfID.

   Rules:
   - If dp_cropped (base64 data URL) is present, we TRUST it and save as-is.
   - Only if dp_cropped is empty do we look at $_FILES['dp'] and auto-crop.
   - Returns the public path (/uploads/dp/xxx.png) or the existing path on failure.
*/

// Prevent direct access
if (basename(__FILE__) === basename(isset($_SERVER['SCRIPT_FILENAME']) ? $_SERVER['SCRIPT_FILENAME'] : '')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Ensure uploads/dp directory exists and return its absolute path.
 */
function coid_dp_upload_dir() {
    $base = realpath(__DIR__ . '/..');
    if ($base === false) {
        $base = dirname(__DIR__);
    }
    $dir = $base . '/uploads/dp';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

/**
 * Store a base64 data URL (png/jpeg) as file for a given user.
 * Returns public path (/uploads/dp/...) or null on failure.
 */
function coid_dp_store_from_base64($userId, $dataUrl) {
    if (!is_string($dataUrl) || $dataUrl === '') return null;

    if (!preg_match('#^data:image/(png|jpeg);base64,#i', $dataUrl, $m)) {
        return null;
    }

    $ext = strtolower($m[1]);
    if ($ext === 'jpeg') $ext = 'jpg';

    $raw = substr($dataUrl, strlen($m[0]));
    $bin = base64_decode($raw, true);
    if ($bin === false) return null;

    // Hard limit: 5 MB
    if (strlen($bin) > 5 * 1024 * 1024) return null;

    $dir = coid_dp_upload_dir();
    $fn  = 'dp_' . intval($userId) . '_' . time() . '.' . $ext;
    $path = $dir . '/' . $fn;

    if (file_put_contents($path, $bin) === false) {
        return null;
    }

    return '/uploads/dp/' . $fn;
}

/**
 * Fallback: handle a raw file upload ($_FILES['dp']) with simple square crop.
 * Returns public path or existing path on failure.
 */
function coid_dp_store_from_file($userId, $currentPath) {
    if (!isset($_FILES['dp']) || !is_array($_FILES['dp'])) {
        return $currentPath;
    }
    if ($_FILES['dp']['error'] !== UPLOAD_ERR_OK) {
        return $currentPath;
    }

    $tmp = $_FILES['dp']['tmp_name'];
    if (!is_uploaded_file($tmp)) {
        return $currentPath;
    }

    $info = @getimagesize($tmp);
    if ($info === false) return $currentPath;

    $mime = isset($info['mime']) ? strtolower($info['mime']) : '';
    if ($mime !== 'image/jpeg' && $mime !== 'image/png') {
        return $currentPath;
    }

    $data = @file_get_contents($tmp);
    if ($data === false) return $currentPath;

    $src = @imagecreatefromstring($data);
    if (!$src) return $currentPath;

    $w = imagesx($src);
    $h = imagesy($src);

    // Center square crop
    $size = min($w, $h);
    $srcX = (int)(($w - $size) / 2);
    $srcY = (int)(($h - $size) / 2);

    $dstSize = 400; // square
    $dst = imagecreatetruecolor($dstSize, $dstSize);
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefill($dst, 0, 0, $white);

    imagecopyresampled($dst, $src, 0, 0, $srcX, $srcY, $dstSize, $dstSize, $size, $size);

    $dir = coid_dp_upload_dir();
    $fn  = 'dp_' . intval($userId) . '_' . time() . '.jpg';
    $path = $dir . '/' . $fn;

    imagejpeg($dst, $path, 90);
    imagedestroy($dst);
    imagedestroy($src);

    return '/uploads/dp/' . $fn;
}

/**
 * Main helper used by signup/account update.
 *
 * @param int         $userId      User ID
 * @param string|null $currentPath Existing dp_path from DB (if any)
 * @return string|null             New or existing dp_path
 */
function handle_dp_upload($userId, $currentPath = null) {
    // 1) Prefer dp_cropped if present (this is exactly what user chose in the cropper)
    $base64 = isset($_POST['dp_cropped']) ? (string)$_POST['dp_cropped'] : '';
    if ($base64 !== '') {
        $new = coid_dp_store_from_base64($userId, $base64);
        if ($new !== null) {
            return $new;
        }
        // If base64 was invalid, silently fall through to file upload
    }

    // 2) Fallback: regular file upload (no JS crop)
    return coid_dp_store_from_file($userId, $currentPath);
}
