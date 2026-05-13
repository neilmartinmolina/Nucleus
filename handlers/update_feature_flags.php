<?php
define("NUCLEUS_SKIP_DIRECT_ACCESS_REDIRECT", true);
require_once __DIR__ . "/../includes/core.php";

header("Content-Type: application/json");

function featureFlagsResponse(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

if (!isAuthenticated()) {
    featureFlagsResponse(401, ["success" => false, "message" => "Not authenticated."]);
}

if (!isAdminLike()) {
    featureFlagsResponse(403, ["success" => false, "message" => "Only administrators can update feature controls."]);
}

if (!checkCSRF($_POST["csrf_token"] ?? "")) {
    featureFlagsResponse(403, ["success" => false, "message" => "Invalid security token."]);
}

$featureKeys = $_POST["feature_key"] ?? [];
$messages = $_POST["maintenance_message"] ?? [];
$enabled = array_flip($_POST["is_enabled"] ?? []);

if (!is_array($featureKeys)) {
    featureFlagsResponse(400, ["success" => false, "message" => "Invalid feature control payload."]);
}

$stmt = $pdo->prepare("
    UPDATE feature_flags
    SET is_enabled = ?, maintenance_message = ?, updated_by = ?, updated_at = NOW()
    WHERE feature_key = ?
");
$changes = 0;
foreach ($featureKeys as $featureKey) {
    $featureKey = (string) $featureKey;
    $isEnabled = isset($enabled[$featureKey]) ? 1 : 0;
    $message = trim((string) ($messages[$featureKey] ?? ""));
    $stmt->execute([$isEnabled, $message !== "" ? $message : null, $_SESSION["userId"], $featureKey]);
    $changes += $stmt->rowCount();
}

logActivity("feature_flags_updated", "Updated feature maintenance controls");

featureFlagsResponse(200, [
    "success" => true,
    "page" => "settings",
    "message" => "Feature controls updated.",
    "updated_count" => $changes,
]);
