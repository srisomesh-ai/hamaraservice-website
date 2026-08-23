<?php
// ═══════════════════════════════════════════════════════
// HamaraService — Image Upload API
//   POST ?action=provider_photo  — provider uploads/changes their photo
//   Accepts: multipart file 'photo' OR JSON {image: "data:image/...;base64,..."}
//   Auth: Bearer JWT (provider). Saves to /uploads/providers/{id}.jpg
// ═══════════════════════════════════════════════════════
require_once __DIR__ . '/db.php';

$action = $_GET['action'] ?? '';
$db     = getDB();

// auto-migrate photo column
try { $db->exec("ALTER TABLE providers ADD COLUMN photo VARCHAR(255) NULL"); } catch (Exception $e) {}

switch ($action) {

  case 'provider_photo': {
    $prov = requireProvider();
    $pid  = $prov['id'];

    $dir = __DIR__ . '/../uploads/providers';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    $binary = null;
    $ext = 'jpg';

    // Option A: multipart file
    if (!empty($_FILES['photo']['tmp_name'])) {
      $binary = file_get_contents($_FILES['photo']['tmp_name']);
      $type = mime_content_type($_FILES['photo']['tmp_name']);
      if (strpos($type, 'png') !== false) $ext = 'png';
      elseif (strpos($type, 'webp') !== false) $ext = 'webp';
    } else {
      // Option B: base64 data URL in JSON
      $b = getBody();
      $img = $b['image'] ?? '';
      if (preg_match('/^data:image\/(\w+);base64,(.+)$/s', $img, $m)) {
        $ext = ($m[1] === 'png') ? 'png' : (($m[1] === 'webp') ? 'webp' : 'jpg');
        $binary = base64_decode($m[2]);
      }
    }

    if (!$binary) err('No image provided');
    if (strlen($binary) > 5 * 1024 * 1024) err('Image too large (max 5MB)');

    // sanitize id for filename
    $safe = preg_replace('/[^A-Za-z0-9\-]/', '_', $pid);
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
