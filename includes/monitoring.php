<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

const NUCLEUS_MONITORING_DEFAULT_STALE_MINUTES = 10;
const NUCLEUS_MONITORING_DEFAULT_BATCH_SIZE = 10;

function monitoringStaleMinutes(): int
{
    global $pdo;
    $settings = isset($pdo) ? monitoringSettings($pdo) : [];
    $value = (int) ($_ENV["MONITORING_STALE_MINUTES"] ?? ($settings["stale_after_minutes"] ?? NUCLEUS_MONITORING_DEFAULT_STALE_MINUTES));
    return $value > 0 ? $value : NUCLEUS_MONITORING_DEFAULT_STALE_MINUTES;
}

function monitoringStoragePath(string $subPath): string
{
    return __DIR__ . "/../storage/" . ltrim($subPath, "/\\");
}

function monitoringEnsureDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }
}

function monitoringLog(string $message, array $context = []): void
{
    $logPath = monitoringStoragePath("logs/monitoring.log");
    monitoringEnsureDirectory(dirname($logPath));
    $line = "[" . date("Y-m-d H:i:s") . "] " . $message;
    if ($context) {
        $line .= " " . json_encode($context, JSON_UNESCAPED_SLASHES);
    }
    file_put_contents($logPath, $line . PHP_EOL, FILE_APPEND);
}

function monitoringSettings(PDO $pdo): array
{
    static $settings = null;
    if ($settings !== null) {
        return $settings;
    }

    $defaults = [
        "check_interval_minutes" => 5,
        "stale_after_minutes" => 10,
        "failure_threshold" => 3,
        "batch_size" => 10,
        "response_slow_ms" => 3000,
        "retention_days" => 30,
    ];

    try {
        $rows = $pdo->query("SELECT setting_key, setting_value FROM monitoring_settings")->fetchAll();
        foreach ($rows as $row) {
            if (array_key_exists($row["setting_key"], $defaults)) {
                $defaults[$row["setting_key"]] = (int) $row["setting_value"];
            }
        }
    } catch (Throwable $e) {
        error_log("Monitoring settings unavailable: " . $e->getMessage());
    }

    $settings = $defaults;
    return $settings;
}

function monitoringStartRun(PDO $pdo, int $batchSize): int
{
    $stmt = $pdo->prepare("INSERT INTO monitoring_runs (batch_size, status, message) VALUES (?, 'running', 'Monitoring queue started')");
    $stmt->execute([$batchSize]);
    return (int) $pdo->lastInsertId();
}

function monitoringFinishRun(PDO $pdo, int $runId, string $status, int $checked, int $skipped, int $errors, string $message, int $startedAtMs): void
{
    $durationMs = max(0, (int) round(microtime(true) * 1000) - $startedAtMs);
    $stmt = $pdo->prepare("
        UPDATE monitoring_runs
        SET finished_at = NOW(),
            duration_ms = ?,
            checked_count = ?,
            skipped_count = ?,
            error_count = ?,
            status = ?,
            message = ?
        WHERE id = ?
    ");
    $stmt->execute([$durationMs, $checked, $skipped, $errors, $status, $message, $runId]);
}

function monitoringLastRun(PDO $pdo): ?array
{
    try {
        $row = $pdo->query("SELECT * FROM monitoring_runs ORDER BY started_at DESC, id DESC LIMIT 1")->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function monitoringNormalizeDeployStatus(string $status): string
{
    $status = strtolower(trim($status));
    $map = [
        "queued" => "initializing",
        "starting" => "initializing",
        "started" => "initializing",
        "initializing" => "initializing",
        "pulling" => "building",
        "installing" => "building",
        "building" => "building",
        "deploying" => "building",
        "online" => "deployed",
        "success" => "deployed",
        "complete" => "deployed",
        "completed" => "deployed",
        "deployed" => "deployed",
        "warning" => "warning",
        "failed" => "error",
        "failure" => "error",
        "error" => "error",
    ];

    return $map[$status] ?? "";
}

function monitoringNormalizePublicUrl(string $publicUrl): string
{
    $publicUrl = trim($publicUrl);
    if ($publicUrl === "") {
        return "";
    }

    if (!preg_match("~^https?://~i", $publicUrl)) {
        $publicUrl = "https://" . $publicUrl;
    }

    return rtrim($publicUrl, "/");
}

function monitoringEndpointCandidates(string $publicUrl): array
{
    $base = monitoringNormalizePublicUrl($publicUrl);
    if ($base === "") {
        return [];
    }

    return [
        ["source" => "status_json", "url" => $base . "/status.json", "json" => true],
        ["source" => "api_status", "url" => $base . "/api/status", "json" => true],
        ["source" => "version_json", "url" => $base . "/version.json", "json" => true],
        ["source" => "http_only", "url" => $base, "json" => false],
    ];
}

function monitoringFetchEndpoint(string $url): array
{
    $startedAt = microtime(true);
    $statusCode = null;

    try {
        $client = new Client([
            "allow_redirects" => true,
            "connect_timeout" => 4,
            "timeout" => 8,
            "headers" => [
                "Accept" => "application/json, text/html;q=0.9, */*;q=0.8",
                "User-Agent" => "Nucleus-Monitor/1.0",
            ],
            "http_errors" => false,
        ]);

        $response = $client->request("GET", $url);
        $statusCode = $response->getStatusCode();
        $body = (string) $response->getBody();
        $responseTimeMs = (int) round((microtime(true) - $startedAt) * 1000);

        return [
            "ok" => $statusCode >= 200 && $statusCode < 400,
            "statusCode" => $statusCode,
            "body" => $body,
            "responseTimeMs" => $responseTimeMs,
            "error" => $statusCode >= 200 && $statusCode < 400 ? null : "HTTP {$statusCode}",
        ];
    } catch (GuzzleException $e) {
        return [
            "ok" => false,
            "statusCode" => $statusCode,
            "body" => "",
            "responseTimeMs" => (int) round((microtime(true) - $startedAt) * 1000),
            "error" => $e->getMessage(),
        ];
    }
}

function monitoringParseJsonBody(string $body, string $source): array
{
    $json = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($json)) {
        return ["ok" => false, "error" => $source . " JSON parse failed: " . json_last_error_msg()];
    }

    return ["ok" => true, "data" => $json];
}

function monitoringParseTimestamp($value): ?string
{
    if (empty($value) || !is_string($value)) {
        return null;
    }

    try {
        return (new DateTime($value))->format("Y-m-d H:i:s");
    } catch (Throwable $e) {
        return null;
    }
}

function monitoringScalarString($value): ?string
{
    return is_scalar($value) && $value !== "" ? (string) $value : null;
}

function monitoringLatestCheck(PDO $pdo, int $projectId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM deployment_checks WHERE project_id = ? ORDER BY checked_at DESC, id DESC LIMIT 1");
    $stmt->execute([$projectId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function monitoringConsecutiveFailures(PDO $pdo, int $projectId): int
{
    $stmt = $pdo->prepare("SELECT status FROM deployment_checks WHERE project_id = ? ORDER BY checked_at DESC, id DESC LIMIT 25");
    $stmt->execute([$projectId]);
    $count = 0;
    foreach ($stmt->fetchAll() as $row) {
        if (in_array($row["status"], ["warning", "error"], true)) {
            $count++;
            continue;
        }
        break;
    }

    return $count;
}

function monitoringLastSuccessfulCheck(PDO $pdo, int $projectId): ?string
{
    $stmt = $pdo->prepare("SELECT checked_at FROM deployment_checks WHERE project_id = ? AND status = 'deployed' ORDER BY checked_at DESC, id DESC LIMIT 1");
    $stmt->execute([$projectId]);
    $checkedAt = $stmt->fetchColumn();
    return $checkedAt !== false ? (string) $checkedAt : null;
}

function monitoringUptimePercent24h(PDO $pdo, int $projectId): ?float
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total_checks,
               SUM(CASE WHEN status = 'deployed' THEN 1 ELSE 0 END) AS healthy_checks
        FROM deployment_checks
        WHERE project_id = ? AND checked_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    $stmt->execute([$projectId]);
    $row = $stmt->fetch();
    $total = (int) ($row["total_checks"] ?? 0);
    if ($total === 0) {
        return null;
    }

    return round(((int) ($row["healthy_checks"] ?? 0) / $total) * 100, 1);
}

function monitoringFreshness(?string $lastSuccessfulCheck, ?string $remoteUpdatedAt, int $staleMinutes = null): array
{
    $staleMinutes = $staleMinutes ?? monitoringStaleMinutes();
    if (!$lastSuccessfulCheck) {
        return ["state" => "unknown", "label" => "Unknown", "severity" => "warning", "message" => "No successful monitoring check yet."];
    }

    $lastSuccessAt = strtotime($lastSuccessfulCheck);
    if ($lastSuccessAt === false) {
        return ["state" => "unknown", "label" => "Unknown", "severity" => "warning", "message" => "Last successful check timestamp is invalid."];
    }

    $ageSeconds = time() - $lastSuccessAt;
    if ($ageSeconds > ($staleMinutes * 60)) {
        return ["state" => "stale", "label" => "Stale", "severity" => "warning", "message" => "Last successful check is older than {$staleMinutes} minutes."];
    }

    if ($remoteUpdatedAt) {
        $remoteUpdatedTs = strtotime($remoteUpdatedAt);
        if ($remoteUpdatedTs !== false && (time() - $remoteUpdatedTs) > ($staleMinutes * 60)) {
            return ["state" => "possibly_outdated", "label" => "Possibly outdated", "severity" => "warning", "message" => "Remote version timestamp is older than {$staleMinutes} minutes."];
        }
    }

    return ["state" => "fresh", "label" => "Fresh", "severity" => "info", "message" => "Latest successful check is within {$staleMinutes} minutes."];
}

function monitoringHealthBadgeClass(string $state): string
{
    return [
        "fresh" => "bg-emerald-50 text-emerald-700 ring-emerald-600/20",
        "stale" => "bg-amber-50 text-amber-700 ring-amber-600/20",
        "possibly_outdated" => "bg-orange-50 text-orange-700 ring-orange-600/20",
        "unknown" => "bg-slate-100 text-slate-600 ring-slate-500/20",
    ][$state] ?? "bg-slate-100 text-slate-600 ring-slate-500/20";
}

function monitoringDisplayStatus(string $status, string $source): string
{
    if ($status === "deployed" && $source === "http_only") {
        return "Online";
    }

    return ucfirst($status);
}

function monitoringBuildCheckFromJson(string $source, array $response, array $remote): ?array
{
    if ($source === "version_json") {
        $version = monitoringScalarString($remote["version"] ?? null);
        $commit = monitoringScalarString($remote["commit"] ?? $remote["commit_hash"] ?? null);
        $branch = monitoringScalarString($remote["branch"] ?? null);
        $remoteUpdatedAt = monitoringParseTimestamp($remote["updated_at"] ?? $remote["finished_at"] ?? null);

        return [
            "status" => "deployed",
            "http_code" => $response["statusCode"],
            "response_time_ms" => $response["responseTimeMs"],
            "status_source" => "version_json",
            "message" => "Version endpoint available" . ($version ? ": {$version}" : "."),
            "version" => $version,
            "commit_hash" => $commit,
            "branch" => $branch,
            "remote_updated_at" => $remoteUpdatedAt,
        ];
    }

    $status = monitoringNormalizeDeployStatus((string) ($remote["status"] ?? ""));
    if ($status === "") {
        return null;
    }

    $version = monitoringScalarString($remote["version"] ?? null);
    $commit = monitoringScalarString($remote["commit"] ?? $remote["commit_hash"] ?? null);
    $branch = monitoringScalarString($remote["branch"] ?? null);
    $remoteUpdatedAt = monitoringParseTimestamp($remote["updated_at"] ?? $remote["finished_at"] ?? null);
    $message = trim((string) ($remote["message"] ?? "Remote status read from {$source}."));

    return [
        "status" => $status,
        "http_code" => $response["statusCode"],
        "response_time_ms" => $response["responseTimeMs"],
        "status_source" => $source,
        "message" => $message,
        "version" => $version,
        "commit_hash" => $commit,
        "branch" => $branch,
        "remote_updated_at" => $remoteUpdatedAt,
    ];
}

function monitoringSaveCheck(PDO $pdo, int $projectId, array $check): int
{
    $stmt = $pdo->prepare("
        INSERT INTO deployment_checks
            (project_id, status, http_code, response_time_ms, status_source, error_message, version, commit_hash, branch, remote_updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $projectId,
        $check["status"],
        $check["http_code"] ?? null,
        $check["response_time_ms"] ?? null,
        $check["status_source"],
        $check["error_message"] ?? null,
        $check["version"] ?? null,
        $check["commit_hash"] ?? null,
        $check["branch"] ?? null,
        $check["remote_updated_at"] ?? null,
    ]);

    return (int) $pdo->lastInsertId();
}

function monitoringUpdateCurrentStatus(PDO $pdo, int $projectId, array $check): void
{
    $noteParts = [$check["message"] ?? ""];
    if (!empty($check["status_source"])) {
        $noteParts[] = "Source: " . $check["status_source"];
    }
    if (!empty($check["response_time_ms"])) {
        $noteParts[] = $check["response_time_ms"] . "ms";
    }
    if (!empty($check["error_message"])) {
        $noteParts[] = $check["error_message"];
    }
    $note = trim(implode(" | ", array_filter($noteParts)));

    $failures = monitoringConsecutiveFailures($pdo, $projectId);
    $lastSuccessfulCheck = monitoringLastSuccessfulCheck($pdo, $projectId);

    $stmt = $pdo->prepare("
        INSERT INTO project_status
            (project_id, status, last_commit, status_note, checked_at, last_checked_at, last_successful_check_at, consecutive_failures, status_source, response_time_ms)
        VALUES (?, ?, ?, ?, NOW(), NOW(), ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            status = VALUES(status),
            last_commit = VALUES(last_commit),
            status_note = VALUES(status_note),
            checked_at = VALUES(checked_at),
            last_checked_at = VALUES(last_checked_at),
            last_successful_check_at = VALUES(last_successful_check_at),
            consecutive_failures = VALUES(consecutive_failures),
            status_source = VALUES(status_source),
            response_time_ms = VALUES(response_time_ms)
    ");
    $stmt->execute([
        $projectId,
        $check["status"],
        $check["commit_hash"] ?? null,
        $note,
        $lastSuccessfulCheck,
        $failures,
        $check["status_source"] ?? null,
        $check["response_time_ms"] ?? null,
    ]);

    if ($check["status"] === "deployed" && !empty($check["remote_updated_at"])) {
        $stmt = $pdo->prepare("UPDATE projects SET last_updated_at = ?, updated_at = NOW() WHERE project_id = ?");
        $stmt->execute([$check["remote_updated_at"], $projectId]);
        return;
    }

    $stmt = $pdo->prepare("UPDATE projects SET updated_at = NOW() WHERE project_id = ?");
    $stmt->execute([$projectId]);
}

function monitoringOpenAlert(PDO $pdo, int $projectId, string $type, string $message, string $severity): void
{
    $stmt = $pdo->prepare("
        SELECT id
        FROM monitoring_alerts
        WHERE project_id = ? AND alert_type = ? AND is_resolved = 0
        ORDER BY triggered_at DESC
        LIMIT 1
    ");
    $stmt->execute([$projectId, $type]);
    if ($stmt->fetchColumn()) {
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO monitoring_alerts (project_id, alert_type, message, severity)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$projectId, $type, $message, $severity]);
}

function monitoringResolveAlerts(PDO $pdo, int $projectId, array $types): void
{
    if (!$types) {
        return;
    }

    $placeholders = implode(",", array_fill(0, count($types), "?"));
    $stmt = $pdo->prepare("
        UPDATE monitoring_alerts
        SET is_resolved = 1, resolved_at = NOW()
        WHERE project_id = ? AND is_resolved = 0 AND alert_type IN ({$placeholders})
    ");
    $stmt->execute(array_merge([$projectId], $types));
}

function monitoringApplyAlerts(PDO $pdo, int $projectId, array $check, array $freshness): void
{
    if ($check["status"] === "deployed") {
        monitoringResolveAlerts($pdo, $projectId, ["monitoring_failure", "stale_status"]);
    } else {
        $severity = $check["status"] === "error" ? "critical" : "warning";
        monitoringOpenAlert($pdo, $projectId, "monitoring_failure", $check["message"] ?? "Project monitoring check failed.", $severity);
    }

    if (in_array($freshness["state"], ["stale", "possibly_outdated", "unknown"], true)) {
        monitoringOpenAlert($pdo, $projectId, "stale_status", $freshness["message"], "warning");
    } else {
        monitoringResolveAlerts($pdo, $projectId, ["stale_status"]);
    }
}

function monitoringRunProjectCheck(PDO $pdo, array $project): array
{
    $projectId = (int) $project["project_id"];
    $previous = monitoringLatestCheck($pdo, $projectId);
    $check = null;
    $lastError = "No status endpoint configured";

    foreach (monitoringEndpointCandidates((string) $project["public_url"]) as $candidate) {
        $response = monitoringFetchEndpoint($candidate["url"]);

        if ($candidate["json"]) {
            if (!$response["ok"]) {
                $lastError = $response["error"];
                continue;
            }

            $parsed = monitoringParseJsonBody($response["body"], $candidate["source"]);
            if (!$parsed["ok"]) {
                $lastError = $parsed["error"];
                continue;
            }

            if ($candidate["source"] === "version_json") {
                $homepage = monitoringFetchEndpoint(monitoringNormalizePublicUrl((string) $project["public_url"]));
                if (!$homepage["ok"] || trim($homepage["body"]) === "") {
                    $lastError = !$homepage["ok"] ? $homepage["error"] : "Homepage returned an empty response";
                    continue;
                }
                $response["statusCode"] = $homepage["statusCode"];
                $response["responseTimeMs"] = $homepage["responseTimeMs"];
            }

            $check = monitoringBuildCheckFromJson($candidate["source"], $response, $parsed["data"]);
            if ($check !== null) {
                break;
            }

            $lastError = $candidate["source"] . " did not include a recognized status";
            continue;
        }

        $hasBody = trim($response["body"]) !== "";
        if ($response["ok"] && $hasBody) {
            $check = [
                "status" => "deployed",
                "http_code" => $response["statusCode"],
                "response_time_ms" => $response["responseTimeMs"],
                "status_source" => "http_only",
                "message" => ($project["deployment_mode"] ?? "hostinger_git") === "hostinger_git"
                    ? "Hostinger Git mode: no remote status file found."
                    : "Custom webhook mode: remote status unavailable, but homepage is reachable.",
            ];
            break;
        }

        $failureCount = monitoringConsecutiveFailures($pdo, $projectId) + 1;
        $status = $failureCount >= 3 ? "error" : "warning";
        $check = [
            "status" => $status,
            "http_code" => $response["statusCode"],
            "response_time_ms" => $response["responseTimeMs"],
            "status_source" => "http_only",
            "message" => $status === "warning" ? "Homepage failed health check once." : "Homepage failed 3 checks in a row.",
            "error_message" => $hasBody ? $response["error"] : ($response["ok"] ? "Homepage returned an empty response" : $response["error"]),
        ];
        break;
    }

    if ($check === null) {
        $failureCount = monitoringConsecutiveFailures($pdo, $projectId) + 1;
        $check = [
            "status" => $failureCount >= 3 ? "error" : "warning",
            "http_code" => null,
            "response_time_ms" => null,
            "status_source" => "none",
            "message" => "Unable to read project status.",
            "error_message" => $lastError,
        ];
    }

    if (in_array($check["status"], ["warning", "error"], true)) {
        $failureCount = monitoringConsecutiveFailures($pdo, $projectId) + 1;
        $check["status"] = $failureCount >= 3 ? "error" : "warning";
        if ($failureCount < 3) {
            $check["message"] = $check["message"] ?? "Monitoring check failed.";
        } else {
            $check["message"] = $check["message"] ?? "Monitoring check failed 3 checks in a row.";
        }
    }

    $checkId = monitoringSaveCheck($pdo, $projectId, $check);
    monitoringUpdateCurrentStatus($pdo, $projectId, $check);

    if (($previous["status"] ?? null) && in_array($previous["status"], ["warning", "error"], true) && $check["status"] === "deployed") {
        logActivity("deployment_recovered", "Project recovered via " . $check["status_source"], $projectId, $check["version"] ?? null);
    }

    $lastSuccess = monitoringLastSuccessfulCheck($pdo, $projectId);
    $freshness = monitoringFreshness($lastSuccess, $check["remote_updated_at"] ?? null);
    monitoringApplyAlerts($pdo, $projectId, $check, $freshness);

    return [
        "checkId" => $checkId,
        "projectId" => $projectId,
        "status" => $check["status"],
        "message" => $check["message"] ?? "",
        "freshness" => $freshness,
    ];
}

function monitoringSelectProjectsForQueue(PDO $pdo, int $batchSize, bool $force = false): array
{
    $settings = monitoringSettings($pdo);
    $checkInterval = max(1, (int) $settings["check_interval_minutes"]);
    $staleAfter = max(1, (int) $settings["stale_after_minutes"]);
    $responseSlowMs = max(1, (int) $settings["response_slow_ms"]);

    $eligibility = $force
        ? "1=1"
        : "(
            ps.last_checked_at IS NULL
            OR ps.status IN ('warning', 'error')
            OR ps.consecutive_failures > 0
            OR ps.last_successful_check_at IS NULL
            OR ps.last_successful_check_at <= DATE_SUB(NOW(), INTERVAL {$staleAfter} MINUTE)
            OR ps.last_checked_at <= DATE_SUB(NOW(), INTERVAL {$checkInterval} MINUTE)
        )";

    $stmt = $pdo->prepare("
        SELECT p.project_id, p.project_name, p.public_url, COALESCE(p.deployment_mode, 'hostinger_git') AS deployment_mode,
               ps.status, ps.last_checked_at, ps.last_successful_check_at,
               COALESCE(ps.consecutive_failures, 0) AS consecutive_failures,
               ps.response_time_ms,
               (
                    CASE WHEN ps.status = 'error' THEN 100 ELSE 0 END
                  + CASE WHEN ps.status = 'warning' THEN 70 ELSE 0 END
                  + CASE WHEN ps.last_successful_check_at IS NULL OR ps.last_successful_check_at <= DATE_SUB(NOW(), INTERVAL {$staleAfter} MINUTE) THEN 50 ELSE 0 END
                  + CASE WHEN ps.last_checked_at IS NULL THEN 40 ELSE 0 END
                  + CASE WHEN COALESCE(ps.consecutive_failures, 0) > 0 THEN 30 ELSE 0 END
                  + CASE WHEN COALESCE(ps.response_time_ms, 0) > {$responseSlowMs} THEN 20 ELSE 0 END
                  + COALESCE(TIMESTAMPDIFF(MINUTE, ps.last_checked_at, NOW()), 100000)
               ) AS priority_score
        FROM projects p
        LEFT JOIN project_status ps ON ps.project_id = p.project_id
        WHERE p.public_url IS NOT NULL
          AND p.public_url <> ''
          AND {$eligibility}
        ORDER BY priority_score DESC, ps.last_checked_at ASC, p.project_id ASC
        LIMIT {$batchSize}
    ");
    $stmt->execute();
    return $stmt->fetchAll();
}

function monitoringProjectSnapshot(PDO $pdo, int $projectId): ?array
{
    $stmt = $pdo->prepare("
        SELECT p.project_id, p.deployment_mode, p.current_version,
               ps.status, ps.status_note,
               dc.http_code, dc.response_time_ms, dc.status_source, dc.error_message,
               dc.version, dc.commit_hash, dc.branch, dc.remote_updated_at, dc.checked_at AS latest_check_at,
               (SELECT MAX(checked_at) FROM deployment_checks WHERE project_id = p.project_id AND status = 'deployed') AS last_successful_check,
               (SELECT COUNT(*) FROM deployment_checks dcf WHERE dcf.project_id = p.project_id AND dcf.status IN ('warning','error') AND dcf.checked_at > COALESCE((SELECT MAX(dcs.checked_at) FROM deployment_checks dcs WHERE dcs.project_id = p.project_id AND dcs.status = 'deployed'), '1970-01-01')) AS consecutive_failures
        FROM projects p
        LEFT JOIN project_status ps ON ps.project_id = p.project_id
        LEFT JOIN deployment_checks dc ON dc.id = (
            SELECT id FROM deployment_checks WHERE project_id = p.project_id ORDER BY checked_at DESC, id DESC LIMIT 1
        )
        WHERE p.project_id = ?
    ");
    $stmt->execute([$projectId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    $lastSuccess = $row["last_successful_check"] ?? null;
    $freshness = monitoringFreshness($lastSuccess, $row["remote_updated_at"] ?? null);
    $status = $row["status"] ?? "initializing";
    $source = $row["status_source"] ?? "none";
    $uptime = monitoringUptimePercent24h($pdo, $projectId);

    return [
        "success" => true,
        "projectId" => $projectId,
        "deploymentMode" => $row["deployment_mode"] ?? "hostinger_git",
        "status" => $status,
        "message" => $row["status_note"] ?? "",
        "httpCode" => $row["http_code"] ?? null,
        "responseTimeMs" => $row["response_time_ms"] ?? null,
        "statusSource" => $source,
        "errorMessage" => $row["error_message"] ?? null,
        "version" => $row["version"] ?? $row["current_version"] ?? null,
        "commitHash" => $row["commit_hash"] ?? null,
        "branch" => $row["branch"] ?? null,
        "remoteUpdatedAt" => $row["remote_updated_at"] ?? null,
        "latestCheckAt" => $row["latest_check_at"] ?? null,
        "lastSuccessfulCheck" => $lastSuccess,
        "displayLastSuccessfulCheck" => $lastSuccess ? formatNucleusDateTime($lastSuccess) : "Never",
        "consecutiveFailures" => (int) ($row["consecutive_failures"] ?? 0),
        "displayStatus" => monitoringDisplayStatus($status, $source),
        "displayUpdatedAt" => !empty($row["remote_updated_at"]) ? formatNucleusDateTime($row["remote_updated_at"]) : ($lastSuccess ? formatNucleusDateTime($lastSuccess) : "Never"),
        "uptimePercent24h" => $uptime,
        "displayUptimePercent24h" => $uptime === null ? "No checks" : $uptime . "%",
        "freshness" => $freshness,
        "healthState" => $freshness["state"],
        "healthLabel" => $freshness["label"],
        "healthMessage" => $freshness["message"],
    ];
}
