<?php
$pageTitle = 'Audit Logs';
require_once __DIR__ . '/../../includes/header.php';
requireRole(['admin']);

$pdo = getDBConnection();

// Filters
$filterUser = $_GET['user_id'] ?? '';
$filterModule = $_GET['module'] ?? '';
$filterAction = $_GET['action'] ?? '';
$filterFrom = $_GET['from'] ?? date('Y-m-01');
$filterTo = $_GET['to'] ?? date('Y-m-d');

$where = "al.created_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)";
$params = [$filterFrom, $filterTo];

if ($filterUser) {
    $where .= " AND al.user_id = ?";
    $params[] = (int) $filterUser;
}
if ($filterModule) {
    $where .= " AND al.module = ?";
    $params[] = $filterModule;
}
if ($filterAction) {
    $where .= " AND al.action = ?";
    $params[] = $filterAction;
}

$stmt = $pdo->prepare("
    SELECT al.*, u.full_name
    FROM audit_logs al
    LEFT JOIN users u ON al.user_id = u.id
    WHERE $where
    ORDER BY al.created_at DESC
    LIMIT 500
");
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Get filter options
$users = $pdo->query("SELECT id, full_name, username FROM users ORDER BY full_name")->fetchAll();
$modules = $pdo->query("SELECT DISTINCT module FROM audit_logs ORDER BY module")->fetchAll(PDO::FETCH_COLUMN);
$actions = ['create','read','update','delete','login','logout','export','import'];
?>

<div class="page-header">
    <h4><i class="bi bi-journal-text"></i> Audit Logs</h4>
    <a href="<?= BASE_URL ?>/modules/reports/export.php?type=audit_logs&from=<?= $filterFrom ?>&to=<?= $filterTo ?>" class="btn btn-outline-success no-print"><i class="bi bi-download"></i> Export CSV</a>
</div>

<!-- Filters -->
<div class="card mb-3 no-print">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label">From</label>
                <input type="date" name="from" class="form-control" value="<?= $filterFrom ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">To</label>
                <input type="date" name="to" class="form-control" value="<?= $filterTo ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">User</label>
                <select name="user_id" class="form-select">
                    <option value="">All Users</option>
                    <?php foreach ($users as $u): ?>
                    <option value="<?= $u['id'] ?>" <?= $filterUser == $u['id'] ? 'selected' : '' ?>><?= sanitize($u['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Module</label>
                <select name="module" class="form-select">
                    <option value="">All Modules</option>
                    <?php foreach ($modules as $mod): ?>
                    <option value="<?= $mod ?>" <?= $filterModule === $mod ? 'selected' : '' ?>><?= ucfirst($mod) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Action</label>
                <select name="action" class="form-select">
                    <option value="">All Actions</option>
                    <?php foreach ($actions as $act): ?>
                    <option value="<?= $act ?>" <?= $filterAction === $act ? 'selected' : '' ?>><?= ucfirst($act) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel"></i> Filter</button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <table class="table table-hover data-table">
            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>User</th>
                    <th>Action</th>
                    <th>Module</th>
                    <th>Table</th>
                    <th>Record ID</th>
                    <th>IP Address</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                <tr>
                    <td><small><?= formatDateTime($log['created_at']) ?></small></td>
                    <td><?= sanitize($log['full_name'] ?? $log['username'] ?? 'System') ?></td>
                    <td>
                        <?php
                        $actionBadges = [
                            'create' => 'success', 'read' => 'info', 'update' => 'primary',
                            'delete' => 'danger', 'login' => 'secondary', 'logout' => 'secondary',
                            'export' => 'warning', 'import' => 'warning'
                        ];
                        $badge = $actionBadges[$log['action']] ?? 'secondary';
                        ?>
                        <span class="badge bg-<?= $badge ?>"><?= ucfirst($log['action']) ?></span>
                    </td>
                    <td><?= ucfirst(sanitize($log['module'])) ?></td>
                    <td><?= sanitize($log['record_table'] ?? '-') ?></td>
                    <td><?= $log['record_id'] ?? '-' ?></td>
                    <td><small><?= sanitize($log['ip_address'] ?? '-') ?></small></td>
                    <td>
                        <?php if ($log['old_values'] || $log['new_values']): ?>
                        <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#detailModal<?= $log['id'] ?>">
                            <i class="bi bi-eye"></i>
                        </button>
                        <?php else: ?>-<?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Detail Modals -->
<?php foreach ($logs as $log): ?>
<?php if ($log['old_values'] || $log['new_values']): ?>
<div class="modal fade" id="detailModal<?= $log['id'] ?>" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Audit Detail - <?= ucfirst($log['action']) ?> <?= sanitize($log['record_table'] ?? '') ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <?php if ($log['old_values']): ?>
                    <div class="col-md-6">
                        <h6 class="text-danger">Old Values</h6>
                        <pre class="bg-light p-3 rounded" style="max-height:300px;overflow:auto;font-size:0.8rem"><?= sanitize(json_encode(json_decode($log['old_values']), JSON_PRETTY_PRINT)) ?></pre>
                    </div>
                    <?php endif; ?>
                    <?php if ($log['new_values']): ?>
                    <div class="col-md-6">
                        <h6 class="text-success">New Values</h6>
                        <pre class="bg-light p-3 rounded" style="max-height:300px;overflow:auto;font-size:0.8rem"><?= sanitize(json_encode(json_decode($log['new_values']), JSON_PRETTY_PRINT)) ?></pre>
                    </div>
                    <?php endif; ?>
                </div>
                <hr>
                <small class="text-muted">
                    User Agent: <?= sanitize($log['user_agent'] ?? 'N/A') ?>
                </small>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
<?php endforeach; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
