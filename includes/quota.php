<?php

require_once __DIR__ . "/db.php";

function getUserQuotaBytes($userId): int
{
    return ADMIN_QUOTA_BYTES;
}

function getUserUsedStorageBytes($userId): int
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(file_size), 0)
        FROM resource_files
        WHERE uploaded_by = ? AND is_deleted = 0
    ");
    $stmt->execute([(int) $userId]);
    return (int) $stmt->fetchColumn();
}

function canUserUpload($userId, $incomingFileSize): bool
{
    $incomingFileSize = (int) $incomingFileSize;
    if ($incomingFileSize < 0) {
        return false;
    }
    return (getUserUsedStorageBytes($userId) + $incomingFileSize) <= getUserQuotaBytes($userId);
}
