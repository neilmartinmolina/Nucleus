<?php

require_once __DIR__ . "/_helpers.php";

header("Content-Type: application/json");
[$userId, $role] = driveRequireUser();
$used = getUsedStorageBytes($userId);
$quota = getQuotaBytesForRole($role);

echo json_encode([
    "success" => true,
    "used_bytes" => $used,
    "quota_bytes" => $quota,
    "percent_used" => $quota > 0 ? round(($used / $quota) * 100, 1) : 0,
]);
