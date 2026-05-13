<?php

require_once __DIR__ . "/_helpers.php";

header("Content-Type: application/json");
[$userId, $role] = driveRequireUser();
$folderId = isset($_GET["folder_id"]) && is_numeric($_GET["folder_id"]) ? (int) $_GET["folder_id"] : null;
$search = trim((string) ($_GET["search"] ?? ""));

try {
    $folder = getCurrentFolder($folderId);
    if ($folderId && !driveCanAccessFolder($userId, $role, $folder)) {
        throw new RuntimeException("Folder not found.");
    }
    echo json_encode([
        "success" => true,
        "items" => listDriveItems($userId, $role, $folderId, $search),
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
