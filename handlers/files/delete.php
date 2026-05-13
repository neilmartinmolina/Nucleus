<?php

require_once __DIR__ . "/_helpers.php";

[$userId, $role] = driveRequireUser();
$folderId = drivePostedFolderId();
driveRequirePostAndCsrf($folderId);

try {
    $type = (string) ($_POST["type"] ?? "");
    $id = isset($_POST["id"]) && is_numeric($_POST["id"]) ? (int) $_POST["id"] : 0;
    deleteDriveItem($userId, $role, $type, $id);
    driveFolderRedirect($folderId, "success", "Item deleted.");
} catch (Throwable $e) {
    error_log("Drive delete failed: " . $e->getMessage());
    driveFolderRedirect($folderId, "error", $e->getMessage());
}
