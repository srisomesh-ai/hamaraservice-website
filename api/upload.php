<?php
// ═══════════════════════════════════════════════════════
// HamaraService — Image Upload API
//   POST ?action=provider_photo  — provider uploads/changes their photo
//   Accepts: multipart file 'photo' OR JSON {image: "data:image/...;base64,..."}
//   Auth: Bearer JWT (provider). Saves to /uploads/providers/{id}.jpg
// ═══════════════════════════════════════════════════════
require_once __DIR__ . '/db.php';
setCorsHeaders();

define('UPLOAD_MAX_BYTES', 5 * 1024 * 1024);

$action = $_GET['action'] ?? '';
$db     = getDB();

// auto-migrate photo column (once)
runMigrationsOnce($db, 'upload_v1', ["ALTER TABLE providers ADD COLUMN photo VARCHAR(255) NULL"]);

// Validate that $binary really is a JPEG/PNG/WebP image; returns the extension.
function detectImageExt($binary) {
  $info = @getimagesizefromstring($binary);
  if (!$info || empty($info[0]) || empty($info[1])) return null;
  if ($info[0] > 6000 || $info[1] > 6000) return null;
  $map = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png'];
  if (defined('IMAGETYPE_WEBP')) $map[IMAGETYPE_WEBP] = 'webp';
  $ext = $map[$info[2]] ?? null;
  if ($ext && class_exists('finfo')) {
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($binary);
    if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) return null;
  }
  return $ext;
}

switch ($action) {

  case 'provider_photo': {
    $prov = requireProvider();
    $pid  = $prov['id'];

    $dir = __DIR__ . '/../uploads/providers';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    $binary = null;

    // Option A: multipart file
    if (!empty($_FILES['photo']['tmp_name'])) {
      if (($_FILES['photo']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) err('Upload failed');
      if (!is_uploaded_file($_FILES['photo']['tmp_name'])) err('Upload failed');
      if (filesize($_FILES['photo']['tmp_name']) > UPLOAD_MAX_BYTES) err('Image too large (max 5MB)');
      $binary = file_get_contents($_FILES['photo']['tmp_name']);
    } else {
      // Option B: base64 data URL in JSON
      $b = getBody();
      $img = (string)($b['image'] ?? '');
      if (strlen($img) > UPLOAD_MAX_BYTES * 1.4 + 100) err('Image too large (max 5MB)');
      if (preg_match('/^data:image\/(\w+);base64,(.+)$/s', $img, $m)) {
        $binary = base64_decode($m[2], true);
      }
    }

    if (!$binary) err('No image provided');
    if (strlen($binary) > UPLOAD_MAX_BYTES) err('Image too large (max 5MB)');

    // Extension from the actual content, never from the client
    $ext = detectImageExt($binary);
    if (!$ext) err('Only JPEG, PNG or WebP images are allowed');

    // sanitize id for filename
    $safe = preg_replace('/[^A-Za-z0-9\-]/', '_', $pid);
    // remove an older photo with a different extension
    foreach (['jpg','png','webp'] as $e) {
      if ($e !== $ext && is_file("$dir/$safe.$e")) @unlink("$dir/$safe.$e");
    }
    $file = $safe . '.' . $ext;
    $path = $dir . '/' . $file;

    if (file_put_contents($path, $binary) === false) err('Could not save image');

    $url = '/uploads/providers/' . $file;
    $db->prepare("UPDATE providers SET photo = ? WHERE id = ?")->execute([$url, $pid]);

    ok(['photo' => $url]);
  }

  default:
    err('Invalid action');
}
