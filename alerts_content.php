<?php
require_once __DIR__ . "/includes/core.php";

$roleManager = new RoleManager($pdo);
[$accessWhere, $accessParams] = $roleManager->projectAccessSql("p");
$isAdmin = in_array($roleManager->getUserRole($_SESSION["userId"] ?? null), ["admin", "superadmin"], true);

$statusFilter = $_GET["status"] ?? "unresolved";
$severityFilter = $_GET["severity"] ?? "";
$projectFilter = isset($_GET["projectId"]) && is_numeric($_GET["projectId"]) ? (int) $_GET["projectId"] : null;

$where = [];
$params = [];
if ($accessWhere !== "") {
    $where[] = preg_replace('/^\s*WHERE\s+/i', '', $accessWhere);
    $params = array_merge($params, $accessParams);
}
if ($statusFilter === "resolved") {
    $where[] = "ma.is_resolved = 1";
} elseif ($statusFilter !== "all") {
    $where[] = "ma.is_resolved = 0";
}
if (in_array($severityFilter, ["info", "warning", "critical"], true)) {
    $where[] = "ma.severity = ?";
    $params[] = $severityFilter;
}
if ($projectFilter) {
    $where[] = "ma.project_id = ?";
    $params[] = $projectFilter;
}
$whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";

$alertsStmt = $pdo->prepare("
    SELECT ma.*, p.project_name
    FROM monitoring_alerts ma
    JOIN projects p ON p.project_id = ma.project_id
    {$whereSql}
    ORDER BY ma.is_resolved ASC, FIELD(ma.severity, 'critical', 'warning', 'info'), ma.triggered_at DESC
    LIMIT 150
");
$alertsStmt->execute($params);
$alerts = $alertsStmt->fetchAll();

$projectStmt = $pdo->prepare("SELECT p.project_id, p.project_name FROM projects p {$accessWhere} ORDER BY p.project_name ASC");
$projectStmt->execute($accessParams);
$projects = $projectStmt->fetchAll();

$countsStmt = $pdo->prepare("
    SELECT
      SUM(CASE WHEN ma.is_resolved = 0 THEN 1 ELSE 0 END) AS unresolved,
      SUM(CASE WHEN ma.is_resolved = 1 THEN 1 ELSE 0 END) AS resolved,
      SUM(CASE WHEN ma.severity = 'critical' AND ma.is_resolved = 0 THEN 1 ELSE 0 END) AS critical_open
    FROM monitoring_alerts ma
    JOIN projects p ON p.project_id = ma.project_id
    {$whereSql}
");
$countsStmt->execute($params);
$counts = $countsStmt->fetch() ?: ["unresolved" => 0, "resolved" => 0, "critical_open" => 0];

function alertFilterUrl(array $overrides): string
{
    $params = array_merge($_GET, $overrides);
    $params["page"] = "alerts";
    foreach ($params as $key => $value) {
        if ($value === "" || $value === null) {
            unset($params[$key]);
        }
    }
    return "dashboard.php?" . http_build_query($params);
}
?>
<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
  <div>
    <h1 class="text-2xl font-bold text-slate-800">Alert Center</h1>
    <p class="text-sm text-slate-500">Operational monitoring alerts from project checks.</p>
  </div>
  <div class="flex flex-wrap gap-2 text-sm">
    <span class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-slate-600">Open: <strong class="text-slate-900"><?php echo (int) ($counts["unresolved"] ?? 0); ?></strong></span>
    <span class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-slate-600">Critical: <strong class="text-red-700"><?php echo (int) ($counts["critical_open"] ?? 0); ?></strong></span>
  </div>
</div>

<section class="mb-6 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
  <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
    <div class="flex flex-wrap gap-2">
      <?php foreach (["unresolved" => "Unresolved", "resolved" => "Resolved", "all" => "All"] as $value => $label): ?>
      <a href="<?php echo htmlspecialchars(alertFilterUrl(["status" => $value])); ?>" class="rounded-lg px-3 py-2 text-sm font-medium <?php echo $statusFilter === $value ? "bg-slate-900 text-white" : "border border-slate-200 text-slate-600 hover:bg-slate-50"; ?>"><?php echo htmlspecialchars($label); ?></a>
      <?php endforeach; ?>
    </div>
    <form method="GET" action="dashboard.php" class="grid gap-3 sm:grid-cols-3">
      <input type="hidden" name="page" value="alerts">
      <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter); ?>">
      <select name="severity" class="rounded-lg border border-slate-200 px-3 py-2 text-sm">
        <option value="">All severities</option>
        <?php foreach (["critical", "warning", "info"] as $severity): ?>
        <option value="<?php echo $severity; ?>" <?php echo $severityFilter === $severity ? "selected" : ""; ?>><?php echo ucfirst($severity); ?></option>
        <?php endforeach; ?>
      </select>
      <select name="projectId" class="rounded-lg border border-slate-200 px-3 py-2 text-sm">
        <option value="">All projects</option>
        <?php foreach ($projects as $project): ?>
        <option value="<?php echo (int) $project["project_id"]; ?>" <?php echo $projectFilter === (int) $project["project_id"] ? "selected" : ""; ?>><?php echo htmlspecialchars($project["project_name"]); ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white">Apply Filters</button>
    </form>
  </div>
</section>

<form method="POST" action="handlers/resolve_alerts.php" data-return-page="alerts" class="rounded-xl border border-slate-200 bg-white shadow-sm">
  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>">
  <div class="flex flex-col gap-3 border-b border-slate-100 p-5 sm:flex-row sm:items-center sm:justify-between">
    <div>
      <h2 class="text-lg font-semibold text-slate-800">Monitoring Alerts</h2>
      <p class="text-sm text-slate-500">Resolve alerts once the project state has been reviewed.</p>
    </div>
    <?php if ($isAdmin): ?>
    <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800">Resolve Selected</button>
    <?php endif; ?>
  </div>
  <div class="overflow-x-auto">
    <table class="w-full text-left">
      <thead class="bg-slate-50 text-xs uppercase text-slate-500">
        <tr>
          <th class="px-5 py-3"><?php if ($isAdmin): ?><input type="checkbox" data-alert-select-all><?php endif; ?></th>
          <th class="px-5 py-3">Project</th>
          <th class="px-5 py-3">Severity</th>
          <th class="px-5 py-3">Type</th>
          <th class="px-5 py-3">Message</th>
          <th class="px-5 py-3">Status</th>
          <th class="px-5 py-3">Triggered</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100">
        <?php foreach ($alerts as $alert): ?>
        <tr class="hover:bg-slate-50">
          <td class="px-5 py-4"><?php if ($isAdmin && !$alert["is_resolved"]): ?><input type="checkbox" name="alert_ids[]" value="<?php echo (int) $alert["id"]; ?>" data-alert-checkbox><?php endif; ?></td>
          <td class="px-5 py-4 text-sm font-medium text-slate-800"><?php echo htmlspecialchars($alert["project_name"]); ?></td>
          <td class="px-5 py-4"><span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset <?php echo monitoringSeverityBadgeClass($alert["severity"]); ?>"><?php echo ucfirst(htmlspecialchars($alert["severity"])); ?></span></td>
          <td class="px-5 py-4 text-sm text-slate-600"><?php echo htmlspecialchars(str_replace("_", " ", $alert["alert_type"])); ?></td>
          <td class="px-5 py-4 text-sm text-slate-600"><?php echo htmlspecialchars($alert["message"]); ?></td>
          <td class="px-5 py-4 text-sm text-slate-600"><?php echo $alert["is_resolved"] ? "Resolved " . htmlspecialchars(formatNucleusDateTime($alert["resolved_at"])) : "Unresolved"; ?></td>
          <td class="px-5 py-4 text-sm text-slate-500"><?php echo htmlspecialchars(formatNucleusDateTime($alert["triggered_at"])); ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$alerts): ?>
        <tr>
          <td colspan="7" class="px-5 py-10 text-center text-sm text-slate-500">No alerts match the current filters.</td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</form>

<script>
(function() {
  const selectAll = document.querySelector('[data-alert-select-all]');
  if (!selectAll) return;
  selectAll.addEventListener('change', function() {
    document.querySelectorAll('[data-alert-checkbox]').forEach(checkbox => {
      checkbox.checked = selectAll.checked;
    });
  });
})();
</script>
