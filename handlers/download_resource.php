<?php
define("NUCLEUS_SKIP_DIRECT_ACCESS_REDIRECT", true);
require_once __DIR__ . "/../includes/core.php";

function downloadResourceError(int $statusCode, string $message): void
{
    http_response_code($statusCode);
    header("Content-Type: text/plain; charset=utf-8");
    echo $message;
    exit;
}

if (!isAuthenticated()) {
    downloadResourceError(401, "Not authenticated.");
}

$resourceFileId = isset($_GET["id"]) && is_numeric($_GET["id"]) ? (int) $_GET["id"] : null;
if (!$resourceFileId) {
    downloadResourceError(400, "Resource is required.");
}

$stmt = $pdo->prepare("
    SELECT rf.*, p.project_name
    FROM resource_files rf
    JOIN projects p ON p.project_id = rf.project_id
    WHERE rf.resource_file_id = ? AND rf.is_deleted = 0
");
$stmt->execute([$resourceFileId]);
$resource = $stmt->fetch();
if (!$resource) {
    downloadResourceError(404, "Resource was not found.");
}

$roleManager = new RoleManager($pdo);
if (!$roleManager->canAccessProject($_SESSION["userId"], (int) $resource["project_id"])) {
    downloadResourceError(403, "You do not have access to this resource.");
}

try {
    $storage = StorageManager::driver($resource["storage_driver"]);
    $stream = $storage->getStream($resource["storage_path"]);
    $size = $storage->size($resource["storage_path"]) ?? (int) $resource["file_size"];

    header("Content-Type: " . ($resource["mime_type"] ?: "application/octet-stream"));
    header("Content-Length: " . $size);
    header("Content-Disposition: attachment; filename=\"" . addcslashes($resource["original_filename"], "\\\"") . "\"");
    header("X-Content-Type-Options: nosniff");
    header("Cache-Control: private, max-age=0, must-revalidate");

    while (!feof($stream)) {
        echo fread($stream, 8192);
        flush();
    }
    fclose($stream);
    exit;
} catch (Throwable $e) {
    error_log("Resource download failed: " . $e->getMessage());
    downloadResourceError(404, "Resource file could not be read.");
}
