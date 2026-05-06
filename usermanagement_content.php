<?php
require_once __DIR__ . "/includes/core.php";

if (!isAuthenticated() || !hasPermission("manage_users")) {
    header("Location: dashboard.php?page=dashboard");
    exit;
}

$users = $pdo->query("
    SELECT u.*, r.role_name AS role
    FROM users u
    JOIN roles r ON r.role_id = u.role_id
    ORDER BY u.username ASC
")->fetchAll();

$roleManager = new RoleManager($pdo);
?>
<div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6">
  <div>
    <h1 class="text-2xl font-bold text-slate-800">User Management</h1>
    <p class="text-sm text-slate-500">Manage system users</p>
  </div>
  <a href="dashboard.php?page=create-user" class="bg-cta text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-opacity-90 transition-colors flex items-center gap-2">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
    New User
  </a>
</div>

<div>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
      <div class="p-6 border-b border-slate-100">
        <h3 class="text-lg font-semibold text-slate-800">Existing Users</h3>
        <div class="mt-4">
          <label for="userSearch" class="mb-2 block text-sm font-medium text-slate-700">Search Users</label>
          <input id="userSearch" type="search" data-table-search="#usersTable" placeholder="Search by username, name, role, or access" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none transition focus:border-cta focus:ring-2 focus:ring-cta/20">
        </div>
      </div>
      <div class="overflow-x-auto lg:overflow-x-visible">
        <div class="nucleus-table-inner px-3 sm:px-4">
        <table id="usersTable" class="data-table w-full" data-page-length="10" data-order-column="0" data-order-direction="asc" data-empty="No users found">
          <thead class="bg-slate-50">
            <tr class="text-left text-sm text-slate-600 border-b border-slate-200">
              <th class="pb-3 pl-6 pr-4 font-semibold">Username</th>
              <th class="pb-3 pr-4 font-semibold">Full Name</th>
              <th class="pb-3 pr-4 font-semibold">Role</th>
              <th class="pb-3 pr-4 font-semibold">Access</th>
              <th class="no-sort pb-3 pr-6 font-semibold">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <?php foreach($users as $u): ?>
            <tr class="border-b border-slate-50 hover:bg-slate-50">
              <td class="py-3 pl-6 pr-4 font-medium text-slate-800"><?php echo htmlspecialchars($u["username"]); ?></td>
              <td class="py-3 pr-4 text-slate-600"><?php echo htmlspecialchars($u["fullName"]); ?></td>
              <td class="py-3 pr-4"><span class="px-2 py-1 rounded text-sm font-medium badge-<?php echo $u["role"]; ?>"><?php echo ucfirst($u["role"]); ?></span></td>
              <td class="py-3 pr-4 text-slate-600"><?php echo count($roleManager->getUserPermissions($u["userId"])); ?> role permissions</td>
              <td class="py-3 pr-6"><a href="dashboard.php?page=manage-user&edit=<?php echo $u["userId"]; ?>" class="text-cta text-sm font-medium">Manage</a></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      </div>
    </div>
</div>

<!-- Badge CSS for status and role badges -->
<style>
/* Status badges */
.badge-updated { background:#d1fae5; color:#065f46; }
.badge-updating { background:#fef3c7; color:#92400e; }
.badge-issue { background:#fee2e2; color:#991b1b; }
/* Role badges */
.badge-admin { background:#fee2e2; color:#991b1b; }
.badge-handler { background:#fef3c7; color:#92400e; }
.badge-visitor { background:#d1fae5; color:#065f46; }
</style>
