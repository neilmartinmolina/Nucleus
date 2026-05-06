<?php
define("NUCLEUS_SKIP_SESSION_BOOTSTRAP", true);
require_once __DIR__ . "/../includes/core.php";

$isCli = php_sapi_name() === "cli";
if (!$isCli) {
    header("Content-Type: application/json");
}

function queueResponse(int $statusCode, array $payload): void
{
    global $isCli;
    http_response_code($statusCode);
    echo json_encode($payload, JSON_PRETTY_PRINT) . ($isCli ? PHP_EOL : "");
    exit;
}

function queueAuthorized(): bool
{
    if (php_sapi_name() === "cli" || isLocal()) {
        return true;
    }

    $expected = (string) ($_ENV["MONITORING_QUEUE_TOKEN"] ?? "");
    $provided = (string) ($_GET["token"] ?? ($_SERVER["HTTP_X_MONITORING_TOKEN"] ?? ""));
    return $expected !== "" && hash_equals($expected, $provided);
}

if (!queueAuthorized()) {
    queueResponse(403, ["success" => false, "message" => "Monitoring queue is not authorized."]);
}

$settings = monitoringSettings($pdo);
$batchSize = (int) ($_GET["batch"] ?? ($_ENV["MONITORING_BATCH_SIZE"] ?? ($settings["batch_size"] ?? NUCLEUS_MONITORING_DEFAULT_BATCH_SIZE)));
$batchSize = max(1, min($batchSize, 100));
$force = (string) ($_GET["force"] ?? "") === "1";

if ($isCli && !empty($argv)) {
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === "force=1" || $arg === "--force") {
            $force = true;
        } elseif (preg_match('/^batch=(\d+)$/', $arg, $matches)) {
            $batchSize = max(1, min((int) $matches[1], 100));
        }
    }
}

$startedAtMs = (int) round(microtime(true) * 1000);
$lockPath = monitoringStoragePath("locks/monitoring.lock");
monitoringEnsureDirectory(dirname($lockPath));
$lockHandle = fopen($lockPath, "c");

if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    monitoringLog("Monitoring queue already running.");
    $runId = monitoringStartRun($pdo, $batchSize);
    monitoringFinishRun($pdo, $runId, "skipped", 0, 0, 0, "Monitoring queue already running.", $startedAtMs);
    queueResponse(200, [
        "success" => true,
        "status" => "skipped",
        "message" => "Monitoring queue already running.",
        "checked" => 0,
        "skipped" => 0,
        "errors" => 0,
    ]);
}

$runId = monitoringStartRun($pdo, $batchSize);
$checked = 0;
$errors = 0;
$results = [];
$selectedProjectIds = [];

try {
    monitoringLog("Monitoring queue started.", ["runId" => $runId, "batchSize" => $batchSize, "force" => $force]);

    $projects = monitoringSelectProjectsForQueue($pdo, $batchSize, $force);
    $selectedProjectIds = array_map(static fn($project) => (int) $project["project_id"], $projects);
    monitoringLog("Monitoring queue selected projects.", ["runId" => $runId, "projectIds" => $selectedProjectIds]);

    foreach ($projects as $project) {
        try {
            $result = monitoringRunProjectCheck($pdo, $project);
            $checked++;
            $results[] = [
                "projectId" => (int) $project["project_id"],
                "projectName" => $project["project_name"],
                "priorityScore" => isset($project["priority_score"]) ? (float) $project["priority_score"] : null,
                "status" => $result["status"],
                "freshness" => $result["freshness"]["state"],
                "message" => $result["message"],
            ];
        } catch (Throwable $e) {
            $errors++;
            monitoringLog("Monitoring project check failed.", [
                "runId" => $runId,
                "projectId" => (int) $project["project_id"],
                "error" => $e->getMessage(),
            ]);
            $results[] = [
                "projectId" => (int) $project["project_id"],
                "projectName" => $project["project_name"],
                "priorityScore" => isset($project["priority_score"]) ? (float) $project["priority_score"] : null,
                "status" => "error",
                "freshness" => "unknown",
                "message" => $e->getMessage(),
            ];
        }
    }

    $skipped = 0;
    $status = $errors > 0 ? "failed" : "completed";
    $message = $errors > 0 ? "Queue completed with {$errors} errors." : "Queue completed.";
    monitoringFinishRun($pdo, $runId, $status, $checked, $skipped, $errors, $message, $startedAtMs);
    $durationMs = max(0, (int) round(microtime(true) * 1000) - $startedAtMs);
    monitoringLog("Monitoring queue finished.", [
        "runId" => $runId,
        "checked" => $checked,
        "skipped" => $skipped,
        "errors" => $errors,
        "durationMs" => $durationMs,
    ]);

    queueResponse(200, [
        "success" => true,
        "status" => $status,
        "runId" => $runId,
        "checked" => $checked,
        "skipped" => $skipped,
        "errors" => $errors,
        "batchSize" => $batchSize,
        "forced" => $force,
        "selectedProjectIds" => $selectedProjectIds,
        "durationMs" => $durationMs,
        "results" => $results,
    ]);
} catch (Throwable $e) {
    $durationMs = max(0, (int) round(microtime(true) * 1000) - $startedAtMs);
    monitoringFinishRun($pdo, $runId, "failed", $checked, 0, $errors + 1, $e->getMessage(), $startedAtMs);
    monitoringLog("Monitoring queue failed.", ["runId" => $runId, "error" => $e->getMessage(), "durationMs" => $durationMs]);
    queueResponse(500, [
        "success" => false,
        "status" => "failed",
        "runId" => $runId,
        "message" => $e->getMessage(),
        "checked" => $checked,
        "errors" => $errors + 1,
        "durationMs" => $durationMs,
    ]);
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}
