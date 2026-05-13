<?php

require_once __DIR__ . "/_helpers.php";

[$userId, $role] = driveRequireUser();
$fileId = isset($_GET["id"]) && is_numeric($_GET["id"]) ? (int) $_GET["id"] : 0;
if (!$fileId) {
    http_response_code(400);
    exit("File is required.");
}

$tempPath = null;
try {
    [$file, $tempPath] = downloadDriveFile($userId, $role, $fileId);
    header("Content-Type: " . ($file["mime_type"] ?: "application/octet-stream"));
    header("Content-Length: " . filesize($tempPath));
    header("Content-Disposition: attachment; filename=\"" . addcslashes($file["original_name"], "\\\"") . "\"");
    header("X-Content-Type-Options: nosniff");
    header("Cache-Control: private, max-age=0, must-revalidate");
    readfile($tempPath);
} catch (Throwable $e) {
    error_log("Drive download failed: " . $e->getMessage());
    http_response_code(404);
    echo "File could not be downloaded.";
} finally {
    if (is_string($tempPath) && is_file($tempPath)) {
        unlink($tempPath);
    }
}
