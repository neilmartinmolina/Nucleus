<?php

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/ftp.php";
require_once __DIR__ . "/Storage/StorageManager.php";

function ensureDriveStorageSchema(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS drive_folders (
            folder_id INT AUTO_INCREMENT PRIMARY KEY,
            parent_id INT NULL,
            owner_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            ftp_path TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_owner_id (owner_id),
            INDEX idx_parent_id (parent_id),
            CONSTRAINT fk_drive_folders_parent FOREIGN KEY (parent_id) REFERENCES drive_folders(folder_id) ON DELETE RESTRICT,
            CONSTRAINT fk_drive_folders_owner FOREIGN KEY (owner_id) REFERENCES users(userId) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS drive_files (
            file_id INT AUTO_INCREMENT PRIMARY KEY,
            folder_id INT NULL,
            owner_id INT NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            stored_name VARCHAR(255) NOT NULL,
            ftp_path TEXT NOT NULL,
            mime_type VARCHAR(100),
            extension VARCHAR(30),
            file_size BIGINT NOT NULL,
            uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_owner_id (owner_id),
            INDEX idx_folder_id (folder_id),
            INDEX idx_original_name (original_name),
            CONSTRAINT fk_drive_files_folder FOREIGN KEY (folder_id) REFERENCES drive_folders(folder_id) ON DELETE RESTRICT,
            CONSTRAINT fk_drive_files_owner FOREIGN KEY (owner_id) REFERENCES users(userId) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function driveIsAdminRole(?string $role): bool
{
    return in_array($role, ["admin", "superadmin"], true);
}

function driveSanitizeDisplayName(string $name): string
{
    $name = basename(str_replace("\\", "/", $name));
    $name = trim(preg_replace('/\s+/', " ", $name));
    $name = preg_replace('/[<>:"|?*\x00-\x1F]/', "_", $name) ?: "";
    if ($name === "" || $name === "." || $name === ".." || str_contains($name, "../") || str_contains($name, "..")) {
        throw new InvalidArgumentException("Invalid name.");
    }
    return substr($name, 0, 180);
}

function driveUserRootPath(int $userId): string
{
    return "users/" . $userId;
}

function driveFolderPath(int $userId, int $folderId): string
{
    return driveUserRootPath($userId) . "/folders/" . $folderId;
}

function getCurrentFolder($folderId): ?array
{
    global $pdo;
    ensureDriveStorageSchema($pdo);
    if (!$folderId) {
        return null;
    }
    $stmt = $pdo->prepare("
        SELECT f.*, u.fullName AS owner_name
        FROM drive_folders f
        LEFT JOIN users u ON u.userId = f.owner_id
        WHERE f.folder_id = ?
    ");
    $stmt->execute([(int) $folderId]);
    $folder = $stmt->fetch();
    return $folder ?: null;
}

function driveCanAccessFolder(int $userId, ?string $role, ?array $folder): bool
{
    if (!$folder) {
        return true;
    }
    return driveIsAdminRole($role) || (int) $folder["owner_id"] === $userId;
}

function driveBreadcrumbs(?int $folderId): array
{
    global $pdo;
    ensureDriveStorageSchema($pdo);
    $crumbs = [["folder_id" => null, "name" => "My Drive"]];
    $seen = [];
    while ($folderId) {
        if (isset($seen[$folderId])) {
            break;
        }
        $seen[$folderId] = true;
        $stmt = $pdo->prepare("SELECT folder_id, parent_id, name FROM drive_folders WHERE folder_id = ?");
        $stmt->execute([$folderId]);
        $folder = $stmt->fetch();
        if (!$folder) {
            break;
        }
        array_splice($crumbs, 1, 0, [[
            "folder_id" => (int) $folder["folder_id"],
            "name" => $folder["name"],
        ]]);
        $folderId = $folder["parent_id"] !== null ? (int) $folder["parent_id"] : null;
    }
    return $crumbs;
}

function listDriveItems($userId, $role, $folderId, $search = null): array
{
    global $pdo;
    ensureDriveStorageSchema($pdo);
    $folderId = $folderId ? (int) $folderId : null;
    $search = trim((string) $search);
    $ownerSql = driveIsAdminRole($role) ? "" : " AND f.owner_id = ?";
    $params = $folderId ? [$folderId] : [];
    if (!$folderId) {
        $folderWhere = "f.parent_id IS NULL";
    } else {
        $folderWhere = "f.parent_id = ?";
    }
    if ($search !== "") {
        $folderWhere .= " AND f.name LIKE ?";
        $params[] = "%" . $search . "%";
    }
    if (!driveIsAdminRole($role)) {
        $params[] = (int) $userId;
    }
    $stmt = $pdo->prepare("
        SELECT 'folder' AS item_type, f.folder_id AS item_id, f.name, NULL AS file_size,
               'folder' AS mime_type, f.created_at AS item_date, f.owner_id, u.fullName AS owner_name
        FROM drive_folders f
        LEFT JOIN users u ON u.userId = f.owner_id
        WHERE {$folderWhere}{$ownerSql}
        ORDER BY f.name ASC
    ");
    $stmt->execute($params);
    $folders = $stmt->fetchAll();

    $ownerSql = driveIsAdminRole($role) ? "" : " AND df.owner_id = ?";
    $params = $folderId ? [$folderId] : [];
    $fileWhere = $folderId ? "df.folder_id = ?" : "df.folder_id IS NULL";
    if ($search !== "") {
        $fileWhere .= " AND df.original_name LIKE ?";
        $params[] = "%" . $search . "%";
    }
    if (!driveIsAdminRole($role)) {
        $params[] = (int) $userId;
    }
    $stmt = $pdo->prepare("
        SELECT 'file' AS item_type, df.file_id AS item_id, df.original_name AS name,
               df.file_size, df.mime_type, df.uploaded_at AS item_date, df.owner_id, u.fullName AS owner_name
        FROM drive_files df
        LEFT JOIN users u ON u.userId = df.owner_id
        WHERE {$fileWhere}{$ownerSql}
        ORDER BY df.original_name ASC
    ");
    $stmt->execute($params);
    return array_merge($folders, $stmt->fetchAll());
}

function createFolder($userId, $folderId, $name): int
{
    global $pdo;
    ensureDriveStorageSchema($pdo);
    $userId = (int) $userId;
    $folderId = $folderId ? (int) $folderId : null;
    $name = driveSanitizeDisplayName((string) $name);
    if ($folderId) {
        $parent = getCurrentFolder($folderId);
        $role = $_SESSION["role"] ?? null;
        if (!$parent || !driveCanAccessFolder($userId, $role, $parent)) {
            throw new RuntimeException("Parent folder was not found.");
        }
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO drive_folders (parent_id, owner_id, name, ftp_path) VALUES (?, ?, ?, '')");
        $stmt->execute([$folderId, $userId, $name]);
        $newFolderId = (int) $pdo->lastInsertId();
        $ftpPath = driveFolderPath($userId, $newFolderId);
        $stmt = $pdo->prepare("UPDATE drive_folders SET ftp_path = ? WHERE folder_id = ?");
        $stmt->execute([$ftpPath, $newFolderId]);
        createFtpDirectory($ftpPath);
        $pdo->commit();
        return $newFolderId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function getUsedStorageBytes($userId): int
{
    global $pdo;
    ensureDriveStorageSchema($pdo);
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(file_size), 0) FROM drive_files WHERE owner_id = ?");
    $stmt->execute([(int) $userId]);
    return (int) $stmt->fetchColumn();
}

function getQuotaBytesForRole($role): int
{
    return driveIsAdminRole($role) ? ADMIN_QUOTA_BYTES : HANDLER_QUOTA_BYTES;
}

function canUploadFile($userId, $role, $incomingFileSize): bool
{
    return getUsedStorageBytes((int) $userId) + (int) $incomingFileSize <= getQuotaBytesForRole($role);
}

function uploadDriveFile($userId, $role, $folderId, $uploadedFile): int
{
    global $pdo;
    ensureDriveStorageSchema($pdo);
    if (!is_array($uploadedFile) || ($uploadedFile["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException("Choose a valid file to upload.");
    }
    $size = (int) ($uploadedFile["size"] ?? 0);
    if ($size <= 0 || $size > UPLOAD_MAX_BYTES) {
        throw new RuntimeException("File size exceeds the allowed limit.");
    }
    if (!canUploadFile($userId, $role, $size)) {
        throw new RuntimeException("Storage quota would be exceeded.");
    }
    $tmp = (string) ($uploadedFile["tmp_name"] ?? "");
    if ($tmp === "" || !is_file($tmp)) {
        throw new RuntimeException("Uploaded file could not be read.");
    }

    $originalName = StorageManager::safeOriginalFilename((string) ($uploadedFile["name"] ?? "file"));
    if (StorageManager::isBlockedExtension($originalName)) {
        throw new RuntimeException("This file type is not allowed.");
    }
    $storedName = StorageManager::safeStoredFilename($originalName);
    $folderId = $folderId ? (int) $folderId : null;
    if ($folderId) {
        $folder = getCurrentFolder($folderId);
        if (!$folder || !driveCanAccessFolder((int) $userId, $role, $folder)) {
            throw new RuntimeException("Folder was not found.");
        }
        $basePath = $folder["ftp_path"];
    } else {
        $basePath = driveUserRootPath((int) $userId);
    }
    $ftpPath = $basePath . "/" . $storedName;
    $mimeType = StorageManager::detectMimeType($tmp);
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $uploaded = false;

    uploadFileToFtp($tmp, $ftpPath);
    $uploaded = true;

    try {
        $stmt = $pdo->prepare("
            INSERT INTO drive_files (folder_id, owner_id, original_name, stored_name, ftp_path, mime_type, extension, file_size)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$folderId, (int) $userId, $originalName, $storedName, $ftpPath, $mimeType, $extension, $size]);
        return (int) $pdo->lastInsertId();
    } catch (Throwable $e) {
        if ($uploaded) {
            try {
                deleteFileFromFtp($ftpPath);
            } catch (Throwable $cleanupError) {
                error_log("Drive orphan cleanup failed: " . $cleanupError->getMessage());
            }
        }
        throw $e;
    }
}

function driveFileForAccess($userId, $role, $fileId): array
{
    global $pdo;
    ensureDriveStorageSchema($pdo);
    $stmt = $pdo->prepare("SELECT * FROM drive_files WHERE file_id = ?");
    $stmt->execute([(int) $fileId]);
    $file = $stmt->fetch();
    if (!$file || (!driveIsAdminRole($role) && (int) $file["owner_id"] !== (int) $userId)) {
        throw new RuntimeException("File not found.");
    }
    return $file;
}

function downloadDriveFile($userId, $role, $fileId): array
{
    $file = driveFileForAccess((int) $userId, $role, (int) $fileId);
    $temp = tempnam(sys_get_temp_dir(), "nucleus_drive_");
    if ($temp === false) {
        throw new RuntimeException("Unable to create temporary download file.");
    }
    downloadFileFromFtp($file["ftp_path"], $temp);
    return [$file, $temp];
}

function renameDriveItem($userId, $role, $type, $id, $newName): void
{
    global $pdo;
    ensureDriveStorageSchema($pdo);
    $newName = driveSanitizeDisplayName((string) $newName);
    if ($type === "file") {
        driveFileForAccess((int) $userId, $role, (int) $id);
        $stmt = $pdo->prepare("UPDATE drive_files SET original_name = ? WHERE file_id = ?");
        $stmt->execute([$newName, (int) $id]);
        return;
    }
    if ($type === "folder") {
        $folder = getCurrentFolder((int) $id);
        if (!driveCanAccessFolder((int) $userId, $role, $folder)) {
            throw new RuntimeException("Folder not found.");
        }
        $stmt = $pdo->prepare("UPDATE drive_folders SET name = ? WHERE folder_id = ?");
        $stmt->execute([$newName, (int) $id]);
        return;
    }
    throw new InvalidArgumentException("Invalid drive item type.");
}

function deleteDriveItem($userId, $role, $type, $id): void
{
    global $pdo;
    ensureDriveStorageSchema($pdo);
    if ($type === "file") {
        $file = driveFileForAccess((int) $userId, $role, (int) $id);
        deleteFileFromFtp($file["ftp_path"]);
        $stmt = $pdo->prepare("DELETE FROM drive_files WHERE file_id = ?");
        $stmt->execute([(int) $id]);
        return;
    }
    if ($type === "folder") {
        $folder = getCurrentFolder((int) $id);
        if (!driveCanAccessFolder((int) $userId, $role, $folder)) {
            throw new RuntimeException("Folder not found.");
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM drive_files WHERE folder_id = ?");
        $stmt->execute([(int) $id]);
        $fileCount = (int) $stmt->fetchColumn();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM drive_folders WHERE parent_id = ?");
        $stmt->execute([(int) $id]);
        $folderCount = (int) $stmt->fetchColumn();
        if ($fileCount > 0 || $folderCount > 0) {
            throw new RuntimeException("Only empty folders can be deleted.");
        }
        deleteFtpDirectory($folder["ftp_path"]);
        $stmt = $pdo->prepare("DELETE FROM drive_folders WHERE folder_id = ?");
        $stmt->execute([(int) $id]);
        return;
    }
    throw new InvalidArgumentException("Invalid drive item type.");
}

function formatDriveBytes(int $bytes): string
{
    $units = ["B", "KB", "MB", "GB", "TB"];
    $value = max(0, $bytes);
    $unit = 0;
    while ($value >= 1024 && $unit < count($units) - 1) {
        $value /= 1024;
        $unit++;
    }
    return ($unit === 0 ? (string) $value : number_format($value, 1)) . " " . $units[$unit];
}
