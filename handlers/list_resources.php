<?php
define("NUCLEUS_SKIP_DIRECT_ACCESS_REDIRECT", true);
require_once __DIR__ . "/../includes/core.php";

header("Content-Type: application/json");

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Not authenticated."]);
    exit;
}

$projectId = isset($_GET["project_id"]) && is_numeric($_GET["project_id"]) ? (int) $_GET["project_id"] : null;
if (!$projectId) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Project is required."]);
    exit;
}

$roleManager = new RoleManager($pdo);
if (!$roleManager->canAccessProject($_SESSION["userId"], $projectId)) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "You do not have access to this project."]);
    exit;
}

$stmt = $pdo->prepare("
    SELECT rf.resource_file_id, rf.original_filename, rf.file_size, rf.mime_type, rf.storage_driver,
           rf.created_at, u.fullName AS uploaded_by_name
    FROM resource_files rf
    LEFT JOIN users u ON u.userId = rf.uploaded_by
    WHERE rf.project_id = ? AND rf.is_deleted = 0
    ORDER BY rf.created_at DESC, rf.resource_file_id DESC
");
$stmt->execute([$projectId]);

echo json_encode([
    "success" => true,
    "resources" => array_map(static function (array $row): array {
        return [
            "id" => (int) $row["resource_file_id"],
            "original_filename" => $row["original_filename"],
            "file_size" => (int) $row["file_size"],
            "mime_type" => $row["mime_type"],
            "storage_driver" => $row["storage_driver"],
            "created_at" => $row["created_at"],
            "uploaded_by_name" => $row["uploaded_by_name"],
            "download_url" => "handlers/download_resource.php?id=" . (int) $row["resource_file_id"],
        ];
    }, $stmt->fetchAll()),
]);
