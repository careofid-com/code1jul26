<?php /* filename: include/validation.php */
require_once __DIR__ . '/config.php';

function is_valid_email($email) {
    return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
}

/**
 * Normalize to E.164 (+14145551200). For US default; extend later for intl.
 * Returns NULL if cannot normalize.
 */
function normalize_phone_e164($phone_raw) {
    if (!is_string($phone_raw)) return null;
    $digits = preg_replace('/\D+/', '', $phone_raw);
    if ($digits === '' || $digits === null) return null;

    // Heuristic for US numbers: 10 or 11 digits (leading 1)
    if (strlen($digits) === 10) {
        return '+1' . $digits;
    } elseif (strlen($digits) === 11 && substr($digits, 0, 1) === '1') {
        return '+'.$digits;
    }
    // If already begins with country code but user added +, try pass-through
    if (substr($phone_raw, 0, 1) === '+' && strlen($digits) >= 10 && strlen($digits) <= 15) {
        return '+'.$digits;
    }
    return null;
}

/**
 * COID rules:
 * - 3..32 chars
 * - Allowed: letters, digits, dot, underscore, hyphen
 * - Must start with a letter
 * - Case-preserving, case-insensitive uniqueness handled via coid_lc column
 */
function is_valid_coid($coid) {
    if (!is_string($coid)) return false;
    $len = strlen($coid);
    if ($len < COID_MIN_LEN || $len > COID_MAX_LEN) return false;
    if (!preg_match('/^[A-Za-z][A-Za-z0-9._-]*$/', $coid)) return false;
    return true;
}

function coid_lc($coid) {
    return mb_strtolower($coid, 'UTF-8');
}

/** Basic sanitization for provider handles; allow @ for sites like YouTube/TikTok */
function sanitize_handle($h) {
    $h = trim($h);
    // Allow typical handle characters & '@' prefix
    $h = preg_replace('/[^A-Za-z0-9._@~-]/', '', $h);
    return $h;
}

/** Escape HTML */
function h($s) {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
