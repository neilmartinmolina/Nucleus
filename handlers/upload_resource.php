<?php
define("NUCLEUS_SKIP_DIRECT_ACCESS_REDIRECT", true);
require_once __DIR__ . "/../includes/core.php";

header("Content-Type: application/json");

function uploadResourceResponse(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

if (!isAuthenticated()) {
    uploadResourceResponse(401, ["success" => false, "message" => "Not authenticated."]);
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    uploadResourceResponse(405, ["success" => false, "message" => "Upload requires POST."]);
}

if (!checkCSRF($_POST["csrf_token"] ?? "")) {
    uploadResourceResponse(403, ["success" => false, "message" => "Invalid security token."]);
}

$projectId = isset($_POST["project_id"]) && is_numeric($_POST["project_id"]) ? (int) $_POST["project_id"] : null;
if (!$projectId) {
    uploadResourceResponse(400, ["success" => false, "message" => "Project is required."]);
}

$roleManager = new RoleManager($pdo);
if (!$roleManager->canAccessProject($_SESSION["userId"], $projectId) || !hasPermission("update_project")) {
    uploadResourceResponse(403, ["success" => false, "message" => "You do not have permission to upload resources for this project."]);
}

if (empty($_FILES["resource_file"]) || !is_array($_FILES["resource_file"])) {
    uploadResourceResponse(400, ["success" => false, "message" => "Choose a file to upload."]);
}

$file = $_FILES["resource_file"];
if (($file["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    uploadResourceResponse(400, ["success" => false, "message" => "Upload failed. Please choose a valid file."]);
}

$size = (int) ($file["size"] ?? 0);
if ($size <= 0 || $size > RESOURCE_MAX_FILE_SIZE) {
    uploadResourceResponse(400, ["success" => false, "message" => "File size exceeds the allowed limit."]);
}

$originalFilename = StorageManager::safeOriginalFilename((string) ($file["name"] ?? "resource"));
if (StorageManager::isBlockedExtension($originalFilename)) {
    uploadResourceResponse(400, ["success" => false, "message" => "This file type is not allowed for resources."]);
}

$currentUsage = resourceProjectUsageBytes($pdo, $projectId);
if (($currentUsage + $size) > RESOURCE_PROJECT_QUOTA_BYTES) {
    uploadResourceResponse(400, ["success" => false, "message" => "Project resource quota would be exceeded."]);
}

if (!canUserUpload($_SESSION["userId"], $size)) {
    uploadResourceResponse(400, ["success" => false, "message" => "Your storage quota would be exceeded."]);
}

$tempPath = (string) ($file["tmp_name"] ?? "");
if ($tempPath === "" || !is_file($tempPath)) {
    uploadResourceResponse(400, ["success" => false, "message" => "Uploaded file could not be read."]);
}

$driver = StorageManager::defaultDriver();
$storedFilename = StorageManager::safeStoredFilename($originalFilename);
$destinationPath = StorageManager::destinationPath($projectId, $storedFilename);
$mimeType = StorageManager::detectMimeType($tempPath);
$uploadedToStorage = false;

try {
    $storage = StorageManager::driver($driver);
    $storage->put($tempPath, $destinationPath);
    $uploadedToStorage = true;

    $stmt = $pdo->prepare("
        INSERT INTO resource_files
            (project_id, uploaded_by, storage_driver, storage_path, file_size, mime_type, original_filename, stored_filename)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $projectId,
        $_SESSION["userId"],
        $driver,
        $destinationPath,
        $size,
        $mimeType,
        $originalFilename,
        $storedFilename,
    ]);
    $resourceFileId = (int) $pdo->lastInsertId();
    logActivity("resource_uploaded", "Uploaded resource {$originalFilename}", $projectId);

    uploadResourceResponse(201, [
        "success" => true,
        "message" => "Resource uploaded.",
        "resource_file_id" => $resourceFileId,
        "original_filename" => $originalFilename,
        "file_size" => $size,
        "mime_type" => $mimeType,
        "storage_driver" => $driver,
    ]);
} catch (Throwable $e) {
    if ($uploadedToStorage) {
        try {
            $storage->delete($destinationPath);
        } catch (Throwable $cleanupError) {
            error_log("Resource orphan cleanup failed: " . $cleanupError->getMessage());
        }
    }
    error_log("Resource upload failed: " . $e->getMessage());
    uploadResourceResponse(500, ["success" => false, "message" => "Resource could not be stored."]);
}
