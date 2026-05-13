<?php
require_once __DIR__ . "/config.php";

$tab = $_GET["tab"] ?? "dashboard";

if (!isAuthenticated()) {
    http_response_code(401);
    header("X-Nucleus-Auth-Expired: 1");
    echo "<div class=\"p-8 text-center\"><p class=\"text-slate-600\">Please login to continue</p></div>";
    exit;
}

$featureMap = [
    "dashboard" => "dashboard",
    "folders" => "subjects",
    "view-folder" => "subjects",
    "create-subject" => "subjects",
    "websites" => "projects",
    "files" => "files",
    "create-project" => "projects",
    "project-form" => "projects",
    "project-details" => "projects",
    "requests" => "requests",
    "settings" => "settings",
    "alerts" => "alerts",
    "logs" => "logs",
];
$featureKey = $featureMap[$tab] ?? null;
if ($featureKey && !isFeatureEnabled($featureKey) && !shouldBypassMaintenance()) {
    renderMaintenanceCard($featureKey);
    exit;
}

switch ($tab) {
    case "dashboard":
        require_once __DIR__ . "/dashboard_content.php";
        break;
    case "folders":
        require_once __DIR__ . "/folders_content.php";
        break;
    case "websites":
        require_once __DIR__ . "/websites_content.php";
        break;
    case "files":
        require_once __DIR__ . "/files_content.php";
        break;
    case "create-subject":
        require_once __DIR__ . "/createsubject.php";
        break;
    case "create-project":
        require_once __DIR__ . "/createproject.php";
        break;
    case "create-user":
        require_once __DIR__ . "/createuser.php";
        break;
    case "manage-user":
        require_once __DIR__ . "/manageuser.php";
        break;
    case "project-form":
        require_once __DIR__ . "/createproject.php";
        break;
    case "project-details":
        require_once __DIR__ . "/project_details.php";
        break;
    case "view-folder":
        require_once __DIR__ . "/view-folder.php";
        break;
    case "usermanagement":
        require_once __DIR__ . "/usermanagement_content.php";
        break;
    case "requests":
        require_once __DIR__ . "/requests_content.php";
        break;
    case "settings":
        require_once __DIR__ . "/settings_content.php";
        break;
    case "alerts":
        require_once __DIR__ . "/alerts_content.php";
        break;
    case "logs":
        require_once __DIR__ . "/activity_log.php";
        break;
    default:
        require_once __DIR__ . "/dashboard_content.php";
        break;
}
?>
