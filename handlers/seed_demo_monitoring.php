<?php
define("NUCLEUS_SKIP_SESSION_BOOTSTRAP", true);
require_once __DIR__ . "/../includes/core.php";

if (php_sapi_name() !== "cli" || !isLocal()) {
    http_response_code(403);
    echo "Demo monitoring seeder is CLI-only and local-only." . PHP_EOL;
    exit(1);
}

$projects = $pdo->query("
    SELECT project_id, project_name
    FROM projects
    ORDER BY project_id ASC
    LIMIT 5
")->fetchAll();

if (!$projects) {
    echo "No projects found. Create a project before seeding demo monitoring data." . PHP_EOL;
    exit(0);
}

$statuses = ["deployed", "deployed", "warning", "error", "deployed", "deployed", "warning", "deployed"];
$sources = ["http_only", "status_json", "api_status", "version_json"];
$insertCheck = $pdo->prepare("
    INSERT INTO deployment_checks
      (project_id, checked_at, status, http_code, response_time_ms, status_source, error_message, version, commit_hash, branch, remote_updated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");
$upsertStatus = $pdo->prepare("
    INSERT INTO project_status
      (project_id, status, status_note, checked_at, last_checked_at, last_successful_check_at, consecutive_failures, status_source, response_time_ms)
    VALUES (?, ?, ?, NOW(), NOW(), ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
      status = VALUES(status),
      status_note = VALUES(status_note),
      checked_at = VALUES(checked_at),
      last_checked_at = VALUES(last_checked_at),
      last_successful_check_at = VALUES(last_successful_check_at),
      consecutive_failures = VALUES(consecutive_failures),
      status_source = VALUES(status_source),
      response_time_ms = VALUES(response_time_ms)
");
$insertAlert = $pdo->prepare("
    INSERT INTO monitoring_alerts (project_id, alert_type, message, severity, is_resolved, triggered_at, resolved_at)
    VALUES (?, ?, ?, ?, ?, ?, ?)
");

$createdChecks = 0;
$createdAlerts = 0;

foreach ($projects as $projectIndex => $project) {
    $projectId = (int) $project["project_id"];
    $lastSuccess = null;
    $consecutiveFailures = 0;
    $latestStatus = "deployed";
    $latestResponse = 0;
    $latestSource = "http_only";

    for ($i = 0; $i < 32; $i++) {
        $status = $statuses[($i + $projectIndex) % count($statuses)];
        $checkedAt = date("Y-m-d H:i:s", strtotime("-" . (31 - $i) * 3 . " hours"));
        $spike = ($i % 9 === 0) ? 2200 + ($projectIndex * 350) : 0;
        $responseMs = 180 + (($i * 73 + $projectIndex * 91) % 820) + $spike;
        $source = $sources[($i + $projectIndex) % count($sources)];
        $message = null;
        $httpCode = 200;

        if ($status === "warning") {
            $message = "Demo warning: response time crossed the review threshold.";
            $httpCode = 200;
            $consecutiveFailures++;
        } elseif ($status === "error") {
            $message = "Demo error: homepage check failed during synthetic monitoring.";
            $httpCode = 503;
            $consecutiveFailures++;
        } else {
            $lastSuccess = $checkedAt;
            $consecutiveFailures = 0;
        }

        $insertCheck->execute([
            $projectId,
            $checkedAt,
            $status,
            $httpCode,
            $responseMs,
            $source,
            $message,
            "v1." . $projectIndex . "." . $i,
            substr(sha1($projectId . ":" . $i . ":demo"), 0, 12),
            "main",
            $checkedAt,
        ]);
        $createdChecks++;
        $latestStatus = $status;
        $latestResponse = $responseMs;
        $latestSource = $source;
    }

    $upsertStatus->execute([
        $projectId,
        $latestStatus,
        "Demo monitoring state seeded for presentation.",
        $lastSuccess,
        $consecutiveFailures,
        $latestSource,
        $latestResponse,
    ]);

    $alertAt = date("Y-m-d H:i:s", strtotime("-" . (2 + $projectIndex) . " hours"));
    $insertAlert->execute([
        $projectId,
        "demo_response_spike",
        "Demo alert: response time spike detected during the seeded timeline.",
        $projectIndex % 2 === 0 ? "warning" : "critical",
        0,
        $alertAt,
        null,
    ]);
    $createdAlerts++;

    $insertAlert->execute([
        $projectId,
        "demo_recovered",
        "Demo alert: previous warning was reviewed and resolved.",
        "info",
        1,
        date("Y-m-d H:i:s", strtotime("-4 days")),
        date("Y-m-d H:i:s", strtotime("-3 days")),
    ]);
    $createdAlerts++;
}

monitoringLog("Demo monitoring data seeded.", [
    "projects" => count($projects),
    "checks" => $createdChecks,
    "alerts" => $createdAlerts,
]);

echo "Seeded {$createdChecks} deployment checks and {$createdAlerts} alerts for " . count($projects) . " projects." . PHP_EOL;
