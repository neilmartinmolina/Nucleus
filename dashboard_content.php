<?php
require_once __DIR__ . "/includes/core.php";

$roleManager = new RoleManager($pdo);
[$accessWhere, $accessParams] = $roleManager->projectAccessSql("p");
$todayWhere = $accessWhere ? $accessWhere . " AND DATE(p.last_updated_at) = CURDATE()" : " WHERE DATE(p.last_updated_at) = CURDATE()";

$todayQuery = "
    SELECT p.*, ps.status, ps.status_note, ps.updated_by AS updatedBy, u.fullName,
           dc.response_time_ms, dc.status_source, dc.version AS check_version, dc.remote_updated_at,
           dc.commit_hash, dc.checked_at AS latest_check_at,
           (SELECT MAX(checked_at) FROM deployment_checks WHERE project_id = p.project_id AND status = 'deployed') AS last_successful_check,
           (SELECT COUNT(*) FROM deployment_checks dcf WHERE dcf.project_id = p.project_id AND dcf.status IN ('warning','error') AND dcf.checked_at > COALESCE((SELECT MAX(dcs.checked_at) FROM deployment_checks dcs WHERE dcs.project_id = p.project_id AND dcs.status = 'deployed'), '1970-01-01')) AS consecutive_failures
    FROM projects p
    LEFT JOIN project_status ps ON ps.project_id = p.project_id
    LEFT JOIN users u ON ps.updated_by = u.userId
    LEFT JOIN deployment_checks dc ON dc.id = (SELECT id FROM deployment_checks WHERE project_id = p.project_id ORDER BY checked_at DESC, id DESC LIMIT 1)
    {$todayWhere}
    ORDER BY p.last_updated_at DESC
    LIMIT 50
";
$todayStmt = $pdo->prepare($todayQuery);
$todayStmt->execute($accessParams);
$today = $todayStmt->fetchAll();

$allStmt = $pdo->prepare("
    SELECT p.*, ps.status, ps.status_note, ps.updated_by AS updatedBy, u.fullName,
           dc.response_time_ms, dc.status_source, dc.version AS check_version, dc.remote_updated_at,
           dc.commit_hash, dc.checked_at AS latest_check_at,
           (SELECT MAX(checked_at) FROM deployment_checks WHERE project_id = p.project_id AND status = 'deployed') AS last_successful_check,
           (SELECT COUNT(*) FROM deployment_checks dcf WHERE dcf.project_id = p.project_id AND dcf.status IN ('warning','error') AND dcf.checked_at > COALESCE((SELECT MAX(dcs.checked_at) FROM deployment_checks dcs WHERE dcs.project_id = p.project_id AND dcs.status = 'deployed'), '1970-01-01')) AS consecutive_failures
    FROM projects p
    LEFT JOIN project_status ps ON ps.project_id = p.project_id
    LEFT JOIN users u ON ps.updated_by = u.userId
    LEFT JOIN deployment_checks dc ON dc.id = (SELECT id FROM deployment_checks WHERE project_id = p.project_id ORDER BY checked_at DESC, id DESC LIMIT 1)
    {$accessWhere}
    ORDER BY p.project_name ASC
    LIMIT 25
");
$allStmt->execute($accessParams);
$all = $allStmt->fetchAll();

$countStmt = $pdo->prepare("SELECT COUNT(*) as c FROM projects p {$accessWhere}");
$countStmt->execute($accessParams);
$totalWebsites = $countStmt->fetch()["c"];
$totalFolders = $pdo->query("SELECT COUNT(*) as c FROM subjects")->fetch()["c"];
$totalUsers = $pdo->query("SELECT COUNT(*) as c FROM users")->fetch()["c"];
$updatedToday = count($today);
$monitoringSettings = monitoringSettings($pdo);
$lastMonitoringRun = monitoringLastRun($pdo);
$monitoringStaleAfter = max(1, (int) ($monitoringSettings["stale_after_minutes"] ?? 10));
$monitoringRunAgeMinutes = null;
$monitoringIsBroken = false;
$monitoringWarning = "";
if (!$lastMonitoringRun) {
    $monitoringIsBroken = true;
    $monitoringWarning = "No monitoring queue run has been recorded yet.";
} else {
    $monitoringRunAgeMinutes = max(0, (int) floor((time() - strtotime($lastMonitoringRun["started_at"])) / 60));
    if (($lastMonitoringRun["status"] ?? "") === "failed") {
        $monitoringIsBroken = true;
        $monitoringWarning = "The last monitoring queue run failed.";
    } elseif ($monitoringRunAgeMinutes > $monitoringStaleAfter) {
        $monitoringIsBroken = true;
        $monitoringWarning = "The monitoring queue has not run within {$monitoringStaleAfter} minutes.";
    }
}
$cronCommand = "php " . str_replace("\\", "/", __DIR__) . "/handlers/run_monitoring_queue.php batch=" . (int) ($monitoringSettings["batch_size"] ?? 10);
?>

 <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
  <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 hover:shadow-md transition-shadow">
    <div class="flex items-center justify-between px-1">
      <div class="pr-4"><p class="text-sm font-medium text-slate-500">Total Projects</p>
      <p class="text-2xl font-bold text-slate-800 mt-1"><?php echo $totalWebsites; ?></p></div>
      <div class="w-12 h-12 rounded-xl bg-blue-100 flex items-center justify-center ml-4">
        <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"></path></svg>
      </div>
    </div>
  </div>
  <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 hover:shadow-md transition-shadow">
    <div class="flex items-center justify-between px-1">
      <div class="pr-4"><p class="text-sm font-medium text-slate-500">Subjects</p>
      <p class="text-2xl font-bold text-slate-800 mt-1"><?php echo $totalFolders; ?></p></div>
      <div class="w-12 h-12 rounded-xl bg-green-100 flex items-center justify-center ml-4">
        <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path></svg>
      </div>
    </div>
  </div>
  <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 hover:shadow-md transition-shadow">
    <div class="flex items-center justify-between px-1">
      <div class="pr-4"><p class="text-sm font-medium text-slate-500">Users</p>
      <p class="text-2xl font-bold text-slate-800 mt-1"><?php echo $totalUsers; ?></p></div>
      <div class="w-12 h-12 rounded-xl bg-purple-100 flex items-center justify-center ml-4">
        <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
      </div>
    </div>
  </div>
  <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 hover:shadow-md transition-shadow">
    <div class="flex items-center justify-between px-1">
      <div class="pr-4"><p class="text-sm font-medium text-slate-500">Updated Today</p>
      <p class="text-2xl font-bold text-slate-800 mt-1"><?php echo $updatedToday; ?></p></div>
      <div class="w-12 h-12 rounded-xl bg-teal-100 flex items-center justify-center ml-4">
        <svg class="w-6 h-6 text-teal-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"></path></svg>
      </div>
    </div>
  </div>
</div>
<section class="mb-8 rounded-xl border <?php echo $monitoringIsBroken ? "border-amber-200 bg-amber-50" : "border-slate-200 bg-white"; ?> p-6 shadow-sm">
  <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
    <div>
      <div class="flex flex-wrap items-center gap-2">
        <h2 class="text-lg font-semibold text-slate-800">Monitoring Diagnostics</h2>
        <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset <?php echo $monitoringIsBroken ? "bg-amber-100 text-amber-800 ring-amber-600/20" : "bg-emerald-50 text-emerald-700 ring-emerald-600/20"; ?>">
          <?php echo $monitoringIsBroken ? "Attention needed" : "Healthy"; ?>
        </span>
      </div>
      <p class="mt-1 text-sm text-slate-500">Queue should run every 1-5 minutes depending on project count. Browser polling only reads DB state.</p>
      <?php if ($monitoringWarning): ?>
      <p class="mt-3 rounded-lg border border-amber-200 bg-white/70 p-3 text-sm text-amber-800"><?php echo htmlspecialchars($monitoringWarning); ?></p>
      <?php endif; ?>
    </div>
    <code class="block max-w-full overflow-x-auto rounded-lg bg-slate-900 px-3 py-2 text-xs text-white"><?php echo htmlspecialchars($cronCommand); ?></code>
  </div>
  <div class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-5">
    <div class="rounded-lg border border-slate-200 bg-white p-3">
      <p class="text-xs font-medium uppercase text-slate-500">Last Run</p>
      <p class="mt-1 text-sm font-semibold text-slate-800"><?php echo $lastMonitoringRun ? htmlspecialchars(formatNucleusDateTime($lastMonitoringRun["started_at"])) : "Never"; ?></p>
    </div>
    <div class="rounded-lg border border-slate-200 bg-white p-3">
      <p class="text-xs font-medium uppercase text-slate-500">Status</p>
      <p class="mt-1 text-sm font-semibold text-slate-800"><?php echo htmlspecialchars($lastMonitoringRun["status"] ?? "none"); ?></p>
    </div>
    <div class="rounded-lg border border-slate-200 bg-white p-3">
      <p class="text-xs font-medium uppercase text-slate-500">Duration</p>
      <p class="mt-1 text-sm font-semibold text-slate-800"><?php echo isset($lastMonitoringRun["duration_ms"]) ? (int) $lastMonitoringRun["duration_ms"] . " ms" : "-"; ?></p>
    </div>
    <div class="rounded-lg border border-slate-200 bg-white p-3">
      <p class="text-xs font-medium uppercase text-slate-500">Checked</p>
      <p class="mt-1 text-sm font-semibold text-slate-800"><?php echo (int) ($lastMonitoringRun["checked_count"] ?? 0); ?></p>
    </div>
    <div class="rounded-lg border border-slate-200 bg-white p-3">
      <p class="text-xs font-medium uppercase text-slate-500">Errors</p>
      <p class="mt-1 text-sm font-semibold text-slate-800"><?php echo (int) ($lastMonitoringRun["error_count"] ?? 0); ?></p>
    </div>
  </div>
</section>
 <div class="bg-white rounded-xl shadow-sm border border-slate-200 mb-8">
  <div class="p-6 border-b border-slate-100">
    <h3 class="text-lg font-semibold text-slate-800">Recent Activity</h3>
    <p class="text-sm text-slate-500 mt-1">Projects updated today</p>
  </div>
  <div class="overflow-x-auto lg:overflow-x-visible p-1">
    <div class="nucleus-table-inner px-3 sm:px-4">
    <table id="recentActivityTable" class="data-table w-full" data-page-length="5" data-order-column="2" data-order-direction="desc" data-empty="No websites updated today">
 <thead><tr class="text-left text-sm text-slate-600 border-b border-slate-100">
          <th class="pb-4 pl-4 pr-4 font-semibold">Project</th>
          <th class="pb-4 pr-4 font-semibold">Updated By</th>
          <th class="pb-4 pr-4 font-semibold">Time</th>
        </tr></thead>
      <tbody>
<?php foreach($today as $r): ?>
 <tr class="border-b border-slate-50 hover:bg-slate-50 transition-colors">
   <td class="py-4 pl-4 pr-4 font-medium text-slate-800"><?php echo htmlspecialchars($r["project_name"]); ?></td>
   <td class="py-4 pr-4 text-slate-600"><?php echo htmlspecialchars(displayUpdatedBy($r)); ?></td>
   <td class="py-4 pr-4 text-slate-500 text-sm"><?php echo htmlspecialchars(formatNucleusDateTime($r["last_updated_at"])); ?></td>
 </tr>
<?php endforeach; ?>
</tbody>
    </table>
    </div>
  </div>
</div>

 <div class="bg-white rounded-xl shadow-sm border border-slate-200">
  <div class="p-6 border-b border-slate-100 flex items-center justify-between">
    <div><h3 class="text-lg font-semibold text-slate-800">All Projects</h3>
    <p class="text-sm text-slate-500 mt-1">Manage academic project sites</p></div>
    <div class="flex flex-wrap items-center gap-2">
      <button type="button" data-refresh-statuses class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 disabled:cursor-wait disabled:opacity-60">Refresh Status</button>
      <a href="dashboard.php?page=websites" class="bg-slate-900 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-slate-800 transition-colors">View All</a>
    </div>
  </div>
  <div class="overflow-x-auto lg:overflow-x-visible p-1">
    <div class="nucleus-table-inner px-3 sm:px-4">
    <table id="dashboardProjectsTable" class="data-table w-full" data-scroll-y="300px" data-page-length="10" data-order-column="0" data-order-direction="asc" data-empty="No website projects found">
 <thead><tr class="text-left text-sm text-slate-600 border-b border-slate-100">
         <th class="pb-4 pl-4 pr-4 font-semibold">Project</th>
         <th class="pb-4 pr-4 font-semibold">Status</th>
         <th class="pb-4 pr-4 font-semibold">Health</th>
         <?php if (hasPermission("update_project")): ?><th class="no-sort pb-4 pr-4 font-semibold">Action</th><?php endif; ?>
       </tr></thead>
      <tbody>
<?php foreach($all as $r): ?>
<?php
  $freshness = monitoringFreshness($r["last_successful_check"] ?? null, $r["remote_updated_at"] ?? null);
  $uptime = monitoringUptimePercent24h($pdo, (int) $r["project_id"]);
?>
 <tr class="border-b border-slate-50 hover:bg-slate-50 transition-colors">
   <td class="py-4 pl-4 pr-4 font-medium text-slate-800"><?php echo htmlspecialchars($r["project_name"]); ?></td>
   <td class="py-4 pr-4">
     <span data-project-status-id="<?php echo (int) $r["project_id"]; ?>" title="<?php echo htmlspecialchars($r["status_note"] ?? ""); ?>" class="px-2 py-1 rounded text-sm font-medium badge-<?php echo htmlspecialchars($r["status"] ?? "initializing"); ?>"><?php echo ucfirst(htmlspecialchars($r["status"] ?? "initializing")); ?></span>
     <div class="mt-1 text-xs text-slate-500"><?php echo htmlspecialchars(deploymentModeLabel($r["deployment_mode"] ?? "hostinger_git")); ?></div>
   </td>
   <td class="py-4 pr-4 text-xs text-slate-500">
     <div class="mb-2"><span data-health-state="<?php echo htmlspecialchars($freshness["state"]); ?>" title="<?php echo htmlspecialchars($freshness["message"]); ?>" class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset <?php echo monitoringHealthBadgeClass($freshness["state"]); ?>"><?php echo htmlspecialchars($freshness["label"]); ?></span></div>
     <div><span data-status-response-time><?php echo $r["response_time_ms"] ? htmlspecialchars($r["response_time_ms"] . " ms") : "—"; ?></span> · <span data-status-source><?php echo htmlspecialchars($r["status_source"] ?? "—"); ?></span></div>
     <div>Last OK: <span data-last-successful-check><?php echo htmlspecialchars(formatNucleusDateTime($r["last_successful_check"])); ?></span></div>
     <div>Failures: <span data-consecutive-failures><?php echo (int) ($r["consecutive_failures"] ?? 0); ?></span></div>
     <div>Uptime 24h: <span data-uptime-24h><?php echo $uptime === null ? "No checks" : htmlspecialchars($uptime . "%"); ?></span></div>
     <div>Latest: <?php echo htmlspecialchars($r["check_version"] ?? "—"); ?><?php if (!empty($r["commit_hash"])): ?> · <?php echo htmlspecialchars(substr($r["commit_hash"], 0, 12)); ?><?php endif; ?></div>
   </td>
    <?php if (hasPermission("update_project")): ?><td class="py-4 pr-4"><button class="status-select px-4 py-2 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm transition-colors border border-slate-200 cursor-pointer whitespace-nowrap" data-website-id="<?php echo (int) $r["project_id"]; ?>">Update</button></td><?php endif; ?>
 </tr>
<?php endforeach; ?>
</tbody>
    </table>
    </div>
  </div>
</div>

   <style>
    .badge-initializing { background:#e0f2fe; color:#075985; }
    .badge-building { background:#fef3c7; color:#92400e; }
    .badge-deployed { background:#d1fae5; color:#065f46; }
    .badge-warning { background:#ffedd5; color:#9a3412; }
    .badge-error { background:#fee2e2; color:#991b1b; }
    .data-table td { padding-top:1rem !important; padding-bottom:1rem !important; }
    .data-table th { padding-top:0.75rem !important; padding-bottom:0.75rem !important; }
    .data-table td:first-child, .data-table th:first-child { padding-left:1.5rem !important; }
    .data-table td:last-child, .data-table th:last-child { padding-right:1.5rem !important; }
    table.dataTable td.dataTables_empty { padding-left:1.5rem !important; padding-right:1.5rem !important; text-align: left !important; }
    .dataTables_scrollBody td.dataTables_empty { padding-left:1.5rem !important; padding-right:1.5rem !important; }
  </style>
