<?php

require_once __DIR__ . "/_helpers.php";

[$userId, $role] = driveRequireUser();
$folderId = drivePostedFolderId();
driveRequirePostAndCsrf($folderId);

try {
    uploadDriveFile($userId, $role, $folderId, $_FILES["drive_file"] ?? null);
    driveFolderRedirect($folderId, "success", "File uploaded.");
} catch (Throwable $e) {
    error_log("Drive upload failed: " . $e->getMessage());
    driveFolderRedirect($folderId, "error", $e->getMessage());
}
