<?php
define("NUCLEUS_SKIP_DIRECT_ACCESS_REDIRECT", true);
require_once __DIR__ . "/../includes/core.php";

header("Content-Type: application/json");

function maskValue(?string $value, int $visible = 2): string
{
    $value = (string) $value;
    if ($value === "") {
        return "not configured";
    }
    if (strlen($value) <= $visible) {
        return str_repeat("*", strlen($value));
    }
    return substr($value, 0, $visible) . str_repeat("*", max(3, strlen($value) - $visible));
}

function safeWritableStatus(string $path): array
{
    return [
        "exists" => is_dir($path),
        "writable" => is_dir($path) && is_writable($path),
    ];
}

if (!isAuthenticated() || !isAdminLike()) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Only administrators can test connections."]);
    exit;
}

$db = [
    "host" => maskValue(DB_HOST),
    "name" => DB_NAME,
    "user" => maskValue(DB_USER),
    "connected" => false,
    "version" => null,
];
try {
    $db["version"] = (string) $pdo->query("SELECT VERSION()")->fetchColumn();
    $db["connected"] = true;
} catch (Throwable $e) {
    $db["safe_error"] = "Database connection test failed.";
}

$storage = [
    "active_driver" => StorageManager::defaultDriver(),
    "resources" => safeWritableStatus(STORAGE_LOCAL_ROOT),
    "logs" => safeWritableStatus(__DIR__ . "/../storage/logs"),
    "locks" => safeWritableStatus(__DIR__ . "/../storage/locks"),
];

$ftp = [
    "host" => FTP_STORAGE_HOST ?: "not configured",
    "port" => FTP_STORAGE_PORT,
    "username" => maskValue(FTP_STORAGE_USERNAME),
    "root_path" => FTP_STORAGE_ROOT_PATH ?: "/",
    "passive_mode" => FTP_STORAGE_PASSIVE_MODE,
    "configured" => FTP_STORAGE_HOST !== "" && FTP_STORAGE_USERNAME !== "",
    "connected" => false,
];
if ($ftp["configured"]) {
    try {
        $ftpStorage = StorageManager::driver("ftp");
        $ftp["connected"] = $ftpStorage->exists("__nucleus_connection_probe__") || true;
    } catch (Throwable $e) {
        $ftp["safe_error"] = "FTP connection test failed.";
    }
}

$lastRun = monitoringLastRun($pdo);
$settings = monitoringSettings($pdo);
$monitoring = [
    "scheduler_mode" => monitoringNormalizeSchedulerMode((string) ($settings["scheduler_mode"] ?? "")),
    "last_run" => $lastRun ? formatNucleusDateTime($lastRun["started_at"]) : "Never",
    "last_status" => $lastRun["status"] ?? "none",
    "lock_state" => monitoringLockState(),
    "cron_command" => monitoringCronCommand($settings),
];

$git = [
    "app_url" => APP_URL ?: "not configured",
    "webhook_url" => projectWebhookUrl(),
    "metadata_status" => "Read-only monitoring metadata is enabled.",
];

echo json_encode([
    "success" => true,
    "database" => $db,
    "file_storage" => $storage,
    "ftp" => $ftp,
    "monitoring" => $monitoring,
    "git_metadata" => $git,
]);
