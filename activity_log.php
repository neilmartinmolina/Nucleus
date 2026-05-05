<?php
require_once __DIR__ . "/includes/core.php";

if (!isAuthenticated()) {
    echo "<div class=\"p-8 text-center\"><p class=\"text-slate-600\">Please login to continue</p></div>";
    exit;
}

if (!hasPermission("view_activity_logs")) {
    echo "<div class=\"p-8 text-center\"><p class=\"text-slate-600\">You do not have permission to view the activity log.</p></div>";
    exit;
}
?>
<div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6">
  <div>
    <h1 class="text-2xl font-bold text-slate-800">Activity Log</h1>
    <p class="text-sm text-slate-500">Track subject, request, project, and update activity</p>
  </div>
</div>

<div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
  <div class="overflow-x-auto lg:overflow-x-visible">
    <div class="nucleus-table-inner px-3 sm:px-4">
    <table id="activityLogsTable" class="data-table w-full" data-server-side="true" data-ajax="handlers/datatables/activity_logs.php" data-page-length="10" data-order-column="5" data-order-direction="desc" data-empty="No activity logged yet">
      <thead class="bg-slate-50">
        <tr class="text-left text-sm text-slate-600 border-b border-slate-200">
          <th class="pb-3 pl-6 pr-4 font-semibold">Action</th>
          <th class="pb-3 pr-4 font-semibold">Project</th>
          <th class="pb-3 pr-4 font-semibold">Subject</th>
          <th class="pb-3 pr-4 font-semibold">Actor</th>
          <th class="pb-3 pr-4 font-semibold">Note</th>
          <th class="pb-3 pr-4 font-semibold">When</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100"></tbody>
    </table>
    </div>
  </div>
</div>
