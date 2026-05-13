<?php

require_once __DIR__ . "/../config.php";

function connectFtp()
{
    if (!function_exists("ftp_connect")) {
        throw new RuntimeException("PHP FTP extension is not enabled.");
    }

    if (FTP_STORAGE_HOST === "" || FTP_STORAGE_USERNAME === "") {
        throw new RuntimeException("FTP server is not configured.");
    }

    $connection = ftp_connect(FTP_STORAGE_HOST, FTP_STORAGE_PORT, FTP_STORAGE_TIMEOUT);
    if (!$connection) {
        throw new RuntimeException("Unable to reach FTP server.");
    }

    if (!@ftp_login($connection, FTP_STORAGE_USERNAME, FTP_STORAGE_PASSWORD)) {
        ftp_close($connection);
        throw new RuntimeException("Unable to log in to FTP server.");
    }

    if (!@ftp_pasv($connection, FTP_STORAGE_PASSIVE_MODE)) {
        ftp_close($connection);
        throw new RuntimeException("Unable to set FTP passive mode.");
    }

    return $connection;
}

function closeFtp($connection): void
{
    if ($connection) {
        ftp_close($connection);
    }
}

function ftpRemotePath(string $remotePath): string
{
    $path = str_replace("\\", "/", trim($remotePath));
    $path = ltrim($path, "/");
    if ($path === "" || str_contains($path, "../") || str_contains($path, "..")) {
        throw new InvalidArgumentException("Invalid FTP path.");
    }
    return rtrim("/" . trim(FTP_STORAGE_ROOT_PATH, "/"), "/") . "/" . $path;
}

function ftpRelativePath(string $remotePath): string
{
    $path = str_replace("\\", "/", trim($remotePath));
    $path = ltrim($path, "/");
    if ($path === "" || str_contains($path, "../") || str_contains($path, "..")) {
        throw new InvalidArgumentException("Invalid FTP path.");
    }
    return $path;
}

function ensureFtpDirectory($connectionOrDirectory, ?string $remoteDirectory = null): void
{
    if ($remoteDirectory === null) {
        $connection = connectFtp();
        try {
            $probePath = ftpRemotePath(trim((string) $connectionOrDirectory, "/") . "/.probe");
            ensureFtpDirectory($connection, dirname($probePath));
        } finally {
            ftp_close($connection);
        }
        return;
    }

    $connection = $connectionOrDirectory;
    $directory = str_replace("\\", "/", trim($remoteDirectory, "/"));
    $segments = array_values(array_filter(explode("/", $directory)));
    $current = "";
    foreach ($segments as $segment) {
        $current .= "/" . $segment;
        if (@ftp_chdir($connection, $current)) {
            continue;
        }
        if (!@ftp_mkdir($connection, $current) && !@ftp_chdir($connection, $current)) {
            throw new RuntimeException("Unable to create FTP directory.");
        }
    }
}

function uploadFileToFtp(string $localTmpPath, string $remotePath): void
{
    if (!is_file($localTmpPath)) {
        throw new RuntimeException("Local upload file does not exist.");
    }

    $connection = connectFtp();
    try {
        $fullPath = ftpRemotePath($remotePath);
        ensureFtpDirectory($connection, dirname($fullPath));
        if (!ftp_put($connection, $fullPath, $localTmpPath, FTP_BINARY)) {
            throw new RuntimeException("Failed to upload file to FTP server.");
        }
    } finally {
        ftp_close($connection);
    }
}

function createFtpDirectory(string $remotePath): void
{
    ensureFtpDirectory($remotePath);
}

function downloadFileFromFtp(string $remotePath, string $localTmpPath): void
{
    $connection = connectFtp();
    try {
        if (!ftp_get($connection, $localTmpPath, ftpRemotePath($remotePath), FTP_BINARY)) {
            throw new RuntimeException("Failed to download file from FTP server.");
        }
    } finally {
        ftp_close($connection);
    }
}

function deleteFileFromFtp(string $remotePath): void
{
    $connection = connectFtp();
    try {
        $fullPath = ftpRemotePath($remotePath);
        if (ftp_size($connection, $fullPath) >= 0 && !ftp_delete($connection, $fullPath)) {
            throw new RuntimeException("Failed to delete file from FTP server.");
        }
    } finally {
        ftp_close($connection);
    }
}

function deleteFtpDirectory(string $remotePath): void
{
    $connection = connectFtp();
    try {
        $fullPath = ftpRemotePath($remotePath);
        if (@ftp_chdir($connection, $fullPath) && !@ftp_rmdir($connection, $fullPath)) {
            throw new RuntimeException("Failed to delete FTP directory.");
        }
    } finally {
        closeFtp($connection);
    }
}

function ftpFileExists(string $remotePath): bool
{
    $connection = connectFtp();
    try {
        return ftp_size($connection, ftpRemotePath($remotePath)) >= 0;
    } finally {
        closeFtp($connection);
    }
}
