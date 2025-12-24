<?php
require_once __DIR__ . '/../../includes/site-settings.php';

$db = getDB();
$favicon = getSiteFaviconData($db);
?>
<link rel="icon" href="<?= htmlspecialchars($favicon['href']) ?>" type="<?= $favicon['type'] ?>">
<link rel="apple-touch-icon" href="<?= htmlspecialchars($favicon['apple_touch']) ?>">
<link rel="manifest" href="/admin/manifest.json">
