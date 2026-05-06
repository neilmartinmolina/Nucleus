<?php
require_once __DIR__ . "/includes/core.php";

$projectId = isset($_GET["projectId"]) && is_numeric($_GET["projectId"]) ? (int) $_GET["projectId"] : null;
if (!$projectId) {
    echo "<div class=\"p-8 text-center\"><p class=\"text-slate-600\">Invalid project request.</p></div>";
    exit;
}

$roleManager = new RoleManager($pdo);
if (!$roleManager->canAccessProject($_SESSION["userId"], $projectId)) {
    echo "<div class=\"p-8 text-center\"><p class=\"text-slate-600\">Project not found or access denied.</p></div>";
    exit;
}

$stmt = $pdo->prepare("
    SELECT p.*, s.subject_code AS subjectCode, ps.status, ps.status_note, ps.last_commit,
           dc.http_code, dc.response_time_ms, dc.status_source, dc.error_message,
           dc.version AS check_version, dc.commit_hash, dc.branch, dc.remote_updated_at,
           dc.checked_at AS latest_check_at,
           (SELECT MAX(checked_at) FROM deployment_checks WHERE project_id = p.project_id AND status = 'deployed') AS last_successful_check,
           (SELECT COUNT(*) FROM deployment_checks dcf WHERE dcf.project_id = p.project_id AND dcf.status IN ('warning','error') AND dcf.checked_at > COALESCE((SELECT MAX(dcs.checked_at) FROM deployment_checks dcs WHERE dcs.project_id = p.project_id AND dcs.status = 'deployed'), '1970-01-01')) AS consecutive_failures
    FROM projects p
    LEFT JOIN subjects s ON s.subject_id = p.subject_id
    LEFT JOIN project_status ps ON ps.project_id = p.project_id
    LEFT JOIN deployment_checks dc ON dc.id = (SELECT id FROM deployment_checks WHERE project_id = p.project_id ORDER BY checked_at DESC, id DESC LIMIT 1)
    WHERE p.project_id = ?
");
$stmt->execute([$projectId]);
$project = $stmt->fetch();

if (!$project) {
    echo "<div class=\"p-8 text-center\"><p class=\"text-slate-600\">Project not found.</p></div>";
    exit;
}

$checksStmt = $pdo->prepare("SELECT * FROM deployment_checks WHERE project_id = ? ORDER BY checked_at DESC, id DESC LIMIT 50");
$checksStmt->execute([$projectId]);
$checks = $checksStmt->fetchAll();

$failuresStmt = $pdo->prepare("SELECT * FROM deployment_checks WHERE project_id = ? AND status IN ('warning', 'error') ORDER BY checked_at DESC, id DESC LIMIT 10");
$failuresStmt->execute([$projectId]);
$failures = $failuresStmt->fetchAll();

$trendStmt = $pdo->prepare("SELECT checked_at, response_time_ms, status FROM deployment_checks WHERE project_id = ? AND response_time_ms IS NOT NULL ORDER BY checked_at DESC, id DESC LIMIT 12");
$trendStmt->execute([$projectId]);
$responseTrend = array_reverse($trendStmt->fetchAll());

$versionHistoryStmt = $pdo->prepare("SELECT checked_at, version, commit_hash, branch FROM deployment_checks WHERE project_id = ? AND (version IS NOT NULL OR commit_hash IS NOT NULL) ORDER BY checked_at DESC, id DESC LIMIT 10");
$versionHistoryStmt->execute([$projectId]);
$versionHistory = $versionHistoryStmt->fetchAll();

$timelineStmt = $pdo->prepare("SELECT status, COUNT(*) AS count FROM deployment_checks WHERE project_id = ? AND checked_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) GROUP BY status");
$timelineStmt->execute([$projectId]);
$timelineCounts = [];
foreach ($timelineStmt->fetchAll() as $row) {
    $timelineCounts[$row["status"]] = (int) $row["count"];
}

$status = $project["status"] ?? "initializing";
$freshness = monitoringFreshness($project["last_successful_check"] ?? null, $project["remote_updated_at"] ?? null);
$uptime = monitoringUptimePercent24h($pdo, $projectId);
$maxResponseTime = 0;
foreach ($responseTrend as $trend) {
    $maxResponseTime = max($maxResponseTime, (int) $trend["response_time_ms"]);
}
?>
<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
  <div>
    <div class="mb-2 text-sm text-slate-500">
      <a href="dashboard.php?page=websites" class="font-medium text-slate-600 hover:text-navy">Projects</a>
      <span>/</span>
      <span><?php echo htmlspecialchars($project["project_name"]); ?></span>
    </div>
    <h1 class="text-2xl font-bold text-slate-800"><?php echo htmlspecialchars($project["project_name"]); ?></h1>
    <p class="text-sm text-slate-500"><?php echo htmlspecialchars(deploymentModeLabel($project["deployment_mode"] ?? "hostinger_git")); ?></p>
  </div>
  <a href="dashboard.php?page=create-project&websiteId=<?php echo $projectId; ?>" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800">Edit Project</a>
</div>

<section class="mb-6 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
  <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
    <p class="text-sm font-medium text-slate-500">Current Status</p>
    <div class="mt-2">
      <span data-project-status-id="<?php echo $projectId; ?>" title="<?php echo htmlspecialchars($project["status_note"] ?? ""); ?>" class="px-2 py-1 rounded text-sm font-medium badge-<?php echo htmlspecialchars($status); ?>"><?php echo ucfirst(htmlspecialchars($status)); ?></span>
    </div>
    <p class="mt-2 text-xs text-slate-500"><?php echo htmlspecialchars($project["status_note"] ?? "No status note yet."); ?></p>
  </div>
  <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
    <p class="text-sm font-medium text-slate-500">Response Time</p>
    <p data-status-response-time class="mt-2 text-2xl font-bold text-slate-900"><?php echo $project["response_time_ms"] ? htmlspecialchars($project["response_time_ms"] . " ms") : "-"; ?></p>
    <p class="mt-1 text-xs text-slate-500">HTTP <?php echo htmlspecialchars($project["http_code"] ?? "-"); ?> · <span data-status-source><?php echo htmlspecialchars($project["status_source"] ?? "-"); ?></span></p>
  </div>
  <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
    <p class="text-sm font-medium text-slate-500">Last Successful Check</p>
    <p data-last-successful-check class="mt-2 text-2xl font-bold text-slate-900"><?php echo htmlspecialchars(formatNucleusDateTime($project["last_successful_check"])); ?></p>
    <p class="mt-1 text-xs text-slate-500">Consecutive failures: <span data-consecutive-failures><?php echo (int) ($project["consecutive_failures"] ?? 0); ?></span></p>
  </div>
  <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
    <p class="text-sm font-medium text-slate-500">Health Freshness</p>
    <p class="mt-2"><span data-health-state="<?php echo htmlspecialchars($freshness["state"]); ?>" title="<?php echo htmlspecialchars($freshness["message"]); ?>" class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset <?php echo monitoringHealthBadgeClass($freshness["state"]); ?>"><?php echo htmlspecialchars($freshness["label"]); ?></span></p>
    <p class="mt-2 text-xs text-slate-500">Uptime 24h: <span data-uptime-24h><?php echo $uptime === null ? "No checks" : htmlspecialchars($uptime . "%"); ?></span></p>
  </div>
</section>

<section class="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
  <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
    <p class="text-sm font-medium text-slate-500">Latest Version / Commit</p>
    <p data-latest-version class="mt-2 text-lg font-bold text-slate-900"><?php echo htmlspecialchars($project["check_version"] ?? $project["current_version"] ?? "-"); ?></p>
    <p data-latest-commit class="mt-1 text-xs text-slate-500"><?php echo htmlspecialchars(!empty($project["commit_hash"]) ? substr($project["commit_hash"], 0, 12) : "No commit yet"); ?></p>
  </div>
  <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm lg:col-span-2">
    <p class="text-sm font-medium text-slate-500">Status Timeline - Last 24h</p>
    <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-5">
      <?php foreach (["deployed", "warning", "error", "building", "initializing"] as $timelineStatus): ?>
      <div class="rounded-lg border border-slate-100 bg-slate-50 p-3">
        <p class="text-xs font-medium uppercase text-slate-500"><?php echo htmlspecialchars($timelineStatus); ?></p>
        <p class="mt-1 text-xl font-bold text-slate-900"><?php echo (int) ($timelineCounts[$timelineStatus] ?? 0); ?></p>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="mb-6 grid grid-cols-1 gap-4 xl:grid-cols-2">
  <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
    <h2 class="text-lg font-semibold text-slate-800">Response Time Trend</h2>
    <div class="mt-4 space-y-3">
      <?php if (!$responseTrend): ?><p class="text-sm text-slate-500">No response time checks recorded yet.</p><?php endif; ?>
      <?php foreach ($responseTrend as $trend): ?>
      <?php $barWidth = $maxResponseTime > 0 ? max(5, (int) round(((int) $trend["response_time_ms"] / $maxResponseTime) * 100)) : 0; ?>
      <div>
        <div class="mb-1 flex items-center justify-between text-xs text-slate-500">
          <span><?php echo htmlspecialchars(formatNucleusDateTime($trend["checked_at"])); ?></span>
          <span><?php echo (int) $trend["response_time_ms"]; ?> ms</span>
        </div>
        <div class="h-2 overflow-hidden rounded-full bg-slate-100">
          <div class="h-full rounded-full bg-cta" style="width: <?php echo $barWidth; ?>%"></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
    <h2 class="text-lg font-semibold text-slate-800">Version / Commit History</h2>
    <div class="mt-4 space-y-3">
      <?php if (!$versionHistory): ?><p class="text-sm text-slate-500">No version or commit checks recorded yet.</p><?php endif; ?>
      <?php foreach ($versionHistory as $history): ?>
      <div class="rounded-lg border border-slate-100 bg-slate-50 p-3">
        <p class="text-sm font-medium text-slate-800"><?php echo htmlspecialchars($history["version"] ?? "No version"); ?></p>
        <p class="mt-1 text-xs text-slate-500"><?php echo htmlspecialchars(!empty($history["commit_hash"]) ? substr($history["commit_hash"], 0, 12) : "No commit"); ?><?php if (!empty($history["branch"])): ?> · <?php echo htmlspecialchars($history["branch"]); ?><?php endif; ?></p>
        <p class="mt-1 text-xs text-slate-400"><?php echo htmlspecialchars(formatNucleusDateTime($history["checked_at"])); ?></p>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="mb-6 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
  <h2 class="text-lg font-semibold text-slate-800">Last 10 Failures</h2>
  <div class="mt-4 space-y-3">
    <?php if (!$failures): ?><p class="text-sm text-slate-500">No warning or error checks recorded.</p><?php endif; ?>
    <?php foreach ($failures as $failure): ?>
    <div class="rounded-lg border border-slate-100 bg-slate-50 p-3">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <span class="px-2 py-1 rounded text-sm font-medium badge-<?php echo htmlspecialchars($failure["status"]); ?>"><?php echo ucfirst(htmlspecialchars($failure["status"])); ?></span>
        <span class="text-xs text-slate-500"><?php echo htmlspecialchars(formatNucleusDateTime($failure["checked_at"])); ?></span>
      </div>
      <p class="mt-2 text-sm text-slate-600"><?php echo htmlspecialchars($failure["error_message"] ?: "No error message recorded."); ?></p>
    </div>
    <?php endforeach; ?>
  </div>
</section>

<section class="rounded-xl border border-slate-200 bg-white shadow-sm">
  <div class="border-b border-slate-100 p-6">
    <h2 class="text-lg font-semibold text-slate-800">Recent Check History</h2>
    <p class="mt-1 text-sm text-slate-500">Latest 50 monitoring checks recorded for this project.</p>
  </div>
  <div class="overflow-x-auto lg:overflow-x-visible">
    <div class="nucleus-table-inner px-3 sm:px-4">
    <table id="projectChecksTable" class="data-table w-full" data-page-length="10" data-order-column="0" data-order-direction="desc" data-empty="No deployment checks recorded yet">
      <thead class="bg-slate-50">
        <tr class="text-left text-sm text-slate-600 border-b border-slate-200">
          <th class="pb-3 pl-6 pr-4 font-semibold">Checked At</th>
          <th class="pb-3 pr-4 font-semibold">Status</th>
          <th class="pb-3 pr-4 font-semibold">HTTP</th>
          <th class="pb-3 pr-4 font-semibold">Response</th>
          <th class="pb-3 pr-4 font-semibold">Source</th>
          <th class="pb-3 pr-4 font-semibold">Commit</th>
          <th class="pb-3 pr-4 font-semibold">Branch</th>
          <th class="pb-3 pr-6 font-semibold">Message</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100">
        <?php foreach ($checks as $check): ?>
        <tr class="hover:bg-slate-50">
          <td class="py-3 pl-6 pr-4 text-sm text-slate-600"><?php echo htmlspecialchars(formatNucleusDateTime($check["checked_at"])); ?></td>
          <td class="py-3 pr-4"><span class="px-2 py-1 rounded text-sm font-medium badge-<?php echo htmlspecialchars($check["status"]); ?>"><?php echo ucfirst(htmlspecialchars($check["status"])); ?></span></td>
          <td class="py-3 pr-4 text-sm text-slate-600"><?php echo htmlspecialchars($check["http_code"] ?? "-"); ?></td>
          <td class="py-3 pr-4 text-sm text-slate-600"><?php echo $check["response_time_ms"] ? htmlspecialchars($check["response_time_ms"] . " ms") : "-"; ?></td>
          <td class="py-3 pr-4 text-sm text-slate-600"><?php echo htmlspecialchars($check["status_source"]); ?></td>
          <td class="py-3 pr-4 text-sm text-slate-600"><?php echo htmlspecialchars(!empty($check["commit_hash"]) ? substr($check["commit_hash"], 0, 12) : "-"); ?></td>
          <td class="py-3 pr-4 text-sm text-slate-600"><?php echo htmlspecialchars($check["branch"] ?? "-"); ?></td>
          <td class="py-3 pr-6 text-sm text-slate-600"><?php echo htmlspecialchars($check["error_message"] ?: "OK"); ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</section>

<style>
.badge-initializing { background:#e0f2fe; color:#075985; }
.badge-building { background:#fef3c7; color:#92400e; }
.badge-deployed { background:#d1fae5; color:#065f46; }
.badge-warning { background:#ffedd5; color:#9a3412; }
.badge-error { background:#fee2e2; color:#991b1b; }
</style>
