<?php
define("NUCLEUS_SKIP_DIRECT_ACCESS_REDIRECT", true);
require_once __DIR__ . "/../includes/core.php";

header("Content-Type: application/json");

function monitoringSettingsResponse(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

if (!isAuthenticated()) {
    monitoringSettingsResponse(401, ["success" => false, "message" => "Not authenticated."]);
}

if (!isAdminLike()) {
    monitoringSettingsResponse(403, ["success" => false, "message" => "Only administrators can update monitoring settings."]);
}

if (!checkCSRF($_POST["csrf_token"] ?? "")) {
    monitoringSettingsResponse(403, ["success" => false, "message" => "Invalid security token."]);
}

function postIntSetting(string $key, int $default, int $min, int $max): int
{
    $value = filter_input(INPUT_POST, $key, FILTER_VALIDATE_INT);
    if ($value === false || $value === null) {
        return $default;
    }
    return max($min, min((int) $value, $max));
}

$current = monitoringSettings($pdo);
$settings = [
    "scheduler_mode" => monitoringNormalizeSchedulerMode((string) ($_POST["scheduler_mode"] ?? "")),
    "check_interval_minutes" => postIntSetting("check_interval_minutes", (int) ($current["check_interval_minutes"] ?? 5), 1, 1440),
    "stale_after_minutes" => postIntSetting("stale_after_minutes", (int) ($current["stale_after_minutes"] ?? 10), 1, 1440),
    "failure_threshold" => postIntSetting("failure_threshold", (int) ($current["failure_threshold"] ?? 3), 1, 25),
    "batch_size" => postIntSetting("batch_size", (int) ($current["batch_size"] ?? 10), 1, 100),
    "response_slow_ms" => postIntSetting("response_slow_ms", (int) ($current["response_slow_ms"] ?? 3000), 100, 60000),
];

$stmt = $pdo->prepare("
    INSERT INTO monitoring_settings (setting_key, setting_value)
    VALUES (?, ?)
    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
");

foreach ($settings as $key => $value) {
    $stmt->execute([$key, (string) $value]);
}

logActivity("monitoring_settings_updated", "Updated monitoring scheduler settings");

monitoringSettingsResponse(200, [
    "success" => true,
    "page" => "settings",
    "message" => "Monitoring settings updated.",
]);
