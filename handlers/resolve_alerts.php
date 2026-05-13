<?php
define("NUCLEUS_SKIP_DIRECT_ACCESS_REDIRECT", true);
require_once __DIR__ . "/../includes/core.php";

header("Content-Type: application/json");

function resolveAlertsResponse(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

if (!isAuthenticated()) {
    resolveAlertsResponse(401, ["success" => false, "message" => "Not authenticated."]);
}

if (!checkCSRF($_POST["csrf_token"] ?? "")) {
    resolveAlertsResponse(403, ["success" => false, "message" => "Invalid security token."]);
}

$roleManager = new RoleManager($pdo);
$role = $roleManager->getUserRole($_SESSION["userId"] ?? null);
if (!in_array($role, ["admin", "superadmin"], true)) {
    resolveAlertsResponse(403, ["success" => false, "message" => "Only administrators can resolve alerts."]);
}

$alertIds = array_values(array_unique(array_filter(array_map("intval", $_POST["alert_ids"] ?? []))));
if (!$alertIds) {
    resolveAlertsResponse(400, ["success" => false, "message" => "Select at least one unresolved alert."]);
}

$placeholders = implode(",", array_fill(0, count($alertIds), "?"));
$stmt = $pdo->prepare("
    UPDATE monitoring_alerts
    SET is_resolved = 1, resolved_at = NOW()
    WHERE is_resolved = 0 AND id IN ({$placeholders})
");
$stmt->execute($alertIds);

logActivity("monitoring_alerts_resolved", "Resolved " . $stmt->rowCount() . " monitoring alerts");

resolveAlertsResponse(200, [
    "success" => true,
    "page" => "alerts",
    "message" => $stmt->rowCount() . " alerts resolved.",
]);
