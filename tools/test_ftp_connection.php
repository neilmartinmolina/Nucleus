<?php

define("NUCLEUS_SKIP_CORE_BOOTSTRAP", true);
require_once __DIR__ . "/../includes/ftp.php";

$remoteDirectory = "connection-tests";
$remoteFile = $remoteDirectory . "/nucleus_ftp_test_" . date("Ymd_His") . ".txt";
$localFile = tempnam(sys_get_temp_dir(), "nucleus_ftp_upload_");
$downloadFile = tempnam(sys_get_temp_dir(), "nucleus_ftp_download_");

try {
    if ($localFile === false || $downloadFile === false) {
        throw new RuntimeException("Unable to create temporary test files.");
    }

    file_put_contents($localFile, "Nucleus FTP connection test\n");
    ensureFtpDirectory($remoteDirectory);
    uploadFileToFtp($localFile, $remoteFile);
    downloadFileFromFtp($remoteFile, $downloadFile);

    if (trim((string) file_get_contents($downloadFile)) !== "Nucleus FTP connection test") {
        throw new RuntimeException("Downloaded FTP test file did not match the uploaded content.");
    }

    deleteFileFromFtp($remoteFile);

    echo "FTP connection successful.\n";
    echo "Host: " . FTP_STORAGE_HOST . ":" . FTP_STORAGE_PORT . "\n";
    echo "Root: " . FTP_STORAGE_ROOT_PATH . "\n";
    echo "Passive mode: " . (FTP_STORAGE_PASSIVE_MODE ? "true" : "false") . "\n";
} catch (Throwable $e) {
    if (isset($remoteFile)) {
        try {
            deleteFileFromFtp($remoteFile);
        } catch (Throwable $cleanupError) {
        }
    }
    fwrite(STDERR, "FTP connection failed: " . $e->getMessage() . "\n");
    exit(1);
} finally {
    if (is_string($localFile) && is_file($localFile)) {
        unlink($localFile);
    }
    if (is_string($downloadFile) && is_file($downloadFile)) {
        unlink($downloadFile);
    }
}
