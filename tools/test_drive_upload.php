<?php

define("NUCLEUS_SKIP_CORE_BOOTSTRAP", true);
require_once __DIR__ . "/../includes/DriveStorage.php";

$tmp = tempnam(sys_get_temp_dir(), "nucleus_drive_test_");

try {
    if ($tmp === false) {
        throw new RuntimeException("Unable to create a temporary file.");
    }
    file_put_contents($tmp, "Nucleus drive upload test\n");

    $pdo = getDbConnection();
    ensureDriveStorageSchema($pdo);
    $stmt = $pdo->query("
        SELECT u.userId, r.role_name
        FROM users u
        JOIN roles r ON r.role_id = u.role_id
        ORDER BY CASE WHEN r.role_name = 'admin' THEN 0 ELSE 1 END, u.userId
        LIMIT 1
    ");
    $user = $stmt->fetch();
    if (!$user) {
        throw new RuntimeException("No users found for drive upload test.");
    }

    $uploadedFile = [
        "name" => "nucleus_drive_test.txt",
        "type" => "text/plain",
        "tmp_name" => $tmp,
        "error" => UPLOAD_ERR_OK,
        "size" => filesize($tmp),
    ];

    $fileId = uploadDriveFile((int) $user["userId"], $user["role_name"], null, $uploadedFile);
    [$file, $downloadPath] = downloadDriveFile((int) $user["userId"], $user["role_name"], $fileId);
    if (trim((string) file_get_contents($downloadPath)) !== "Nucleus drive upload test") {
        throw new RuntimeException("Downloaded file content did not match.");
    }
    unlink($downloadPath);
    deleteDriveItem((int) $user["userId"], $user["role_name"], "file", $fileId);

    echo "Drive upload test successful.\n";
    echo "Test user ID: " . (int) $user["userId"] . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Drive upload test failed: " . $e->getMessage() . "\n");
    exit(1);
} finally {
    if (is_string($tmp) && is_file($tmp)) {
        unlink($tmp);
    }
}
