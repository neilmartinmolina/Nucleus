<?php
define("NUCLEUS_SKIP_DIRECT_ACCESS_REDIRECT", true);
require_once __DIR__ . "/../includes/core.php";

header("Content-Type: application/json");

function deleteResourceResponse(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

if (!isAuthenticated()) {
    deleteResourceResponse(401, ["success" => false, "message" => "Not authenticated."]);
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    deleteResourceResponse(405, ["success" => false, "message" => "Delete requires POST."]);
}

if (!checkCSRF($_POST["csrf_token"] ?? "")) {
    deleteResourceResponse(403, ["success" => false, "message" => "Invalid security token."]);
}

$resourceFileId = isset($_POST["id"]) && is_numeric($_POST["id"]) ? (int) $_POST["id"] : null;
if (!$resourceFileId) {
    deleteResourceResponse(400, ["success" => false, "message" => "Resource is required."]);
}

$stmt = $pdo->prepare("SELECT * FROM resource_files WHERE resource_file_id = ? AND is_deleted = 0");
$stmt->execute([$resourceFileId]);
$resource = $stmt->fetch();
if (!$resource) {
    deleteResourceResponse(404, ["success" => false, "message" => "Resource was not found."]);
}

$roleManager = new RoleManager($pdo);
$role = $roleManager->getUserRole($_SESSION["userId"] ?? null);
$isOwner = (int) $resource["uploaded_by"] === (int) $_SESSION["userId"];
$isAdmin = in_array($role, ["admin", "superadmin"], true);
if (!$isOwner && !$isAdmin) {
    deleteResourceResponse(403, ["success" => false, "message" => "Only the owner or an administrator can delete this resource."]);
}

try {
    $storage = StorageManager::driver($resource["storage_driver"]);
    $storage->delete($resource["storage_path"]);

    $stmt = $pdo->prepare("
        UPDATE resource_files
        SET is_deleted = 1, deleted_at = NOW(), deleted_by = ?
        WHERE resource_file_id = ?
    ");
    $stmt->execute([$_SESSION["userId"], $resourceFileId]);
    logActivity("resource_deleted", "Deleted resource " . $resource["original_filename"], (int) $resource["project_id"]);

    deleteResourceResponse(200, ["success" => true, "message" => "Resource deleted."]);
} catch (Throwable $e) {
    error_log("Resource delete failed: " . $e->getMessage());
    deleteResourceResponse(500, ["success" => false, "message" => "Resource could not be deleted."]);
}
