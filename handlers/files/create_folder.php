<?php

require_once __DIR__ . "/_helpers.php";

[$userId, $role] = driveRequireUser();
$folderId = drivePostedFolderId();
driveRequirePostAndCsrf($folderId);

try {
    createFolder($userId, $folderId, $_POST["name"] ?? "");
    driveFolderRedirect($folderId, "success", "Folder created.");
} catch (Throwable $e) {
    error_log("Drive folder create failed: " . $e->getMessage());
    driveFolderRedirect($folderId, "error", $e->getMessage());
}
