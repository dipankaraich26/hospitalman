<?php
// Initialize auth and functions
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requireRole(['admin']);

$pdo = getDBConnection();

require_once __DIR__ . '/../../includes/header.php';

// Get compliance statistics
$stats = [];

// 1. Total Audit Events (Last 30 Days)
$stmt = $pdo->query("
    SELECT COUNT(*) as total
    FROM audit_logs
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
");
$stats['audit_events'] = $stmt->fetch()['total'] ?? 0;

// 2. Failed Login Attempts (Last 7 Days)
$stmt = $pdo->query("
    SELECT COUNT(*) as total
    FROM audit_logs
    WHERE action = 'login_failed'
    AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
");
$stats['failed_logins'] = $stmt->fetch()['total'] ?? 0;

// 3. Data Access Events (Last 24 Hours)
$stmt = $pdo->query("
    SELECT COUNT(*) as total
    FROM audit_logs
    WHERE action = 'read'
    AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
");
$stats['data_access'] = $stmt->fetch()['total'] ?? 0;

// 4. Data Modifications (Last 7 Days)
$stmt = $pdo->query("
    SELECT COUNT(*) as total
    FROM audit_logs
    WHERE action IN ('create', 'update', 'delete')
    AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
");
$stats['modifications'] = $stmt->fetch()['total'] ?? 0;

// 5. Active Users
$stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE status = 'active'");
$stats['active_users'] = $stmt->fetch()['total'] ?? 0;

// 6. Inactive Sessions (Potential Security Risk)
$stmt = $pdo->query("
    SELECT COUNT(DISTINCT user_id) as total
    FROM audit_logs
    WHERE action = 'login'
    AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    AND user_id NOT IN (
        SELECT DISTINCT user_id FROM audit_logs
        WHERE action = 'logout'
        AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    )
");
$stats['inactive_sessions'] = $stmt->fetch()['total'] ?? 0;

// Get recent compliance events
$recentEvents = $pdo->query("
    SELECT
        al.*,
        u.full_name,
        u.role
    FROM audit_logs al
    LEFT JOIN users u ON al.user_id = u.id
    WHERE al.action IN ('create', 'update', 'delete', 'export', 'login_failed')
    ORDER BY al.created_at DESC
    LIMIT 50
")->fetchAll();

// Get user access summary
$userAccess = $pdo->query("
    SELECT
        u.full_name,
        u.role,
        u.email,
        COUNT(al.id) as access_count,
        MAX(al.created_at) as last_access,
        SUM(CASE WHEN al.action IN ('create', 'update', 'delete') THEN 1 ELSE 0 END) as modifications
    FROM users u
    LEFT JOIN audit_logs al ON u.id = al.user_id
    WHERE u.status = 'active'
    AND al.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY u.id
    ORDER BY access_count DESC
    LIMIT 20
")->fetchAll();

// Get module access breakdown
$moduleAccess = $pdo->query("
    SELECT
        module,
        COUNT(*) as access_count,
        COUNT(DISTINCT user_id) as unique_users
    FROM audit_logs
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    AND module IS NOT NULL
    GROUP BY module
    ORDER BY access_count DESC
")->fetchAll();

// Compliance checks
$complianceChecks = [];

// Check 1: Password Policy
$weakPasswords = $pdo->query("
    SELECT COUNT(*) as total
    FROM users
    WHERE status = 'active'
    AND (password_updated_at IS NULL OR password_updated_at < DATE_SUB(NOW(), INTERVAL 90 DAY))
")->fetch()['total'] ?? 0;
$complianceChecks[] = [
    'name' => 'Password Policy',
    'status' => $weakPasswords == 0 ? 'pass' : 'warning',
    'message' => $weakPasswords == 0
        ? 'All users have recent passwords'
        : "$weakPasswords users have passwords older than 90 days",
    'severity' => $weakPasswords > 5 ? 'high' : ($weakPasswords > 0 ? 'medium' : 'low')
];

// Check 2: Audit Logging
$auditEnabled = $pdo->query("SELECT COUNT(*) as total FROM audit_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)")->fetch()['total'];
$complianceChecks[] = [
    'name' => 'Audit Logging',
    'status' => $auditEnabled > 0 ? 'pass' : 'fail',
    'message' => $auditEnabled > 0
        ? "Audit logging is active ($auditEnabled events in last 24h)"
        : 'No audit events recorded in last 24 hours',
    'severity' => $auditEnabled > 0 ? 'low' : 'high'
];

// Check 3: Data Access Controls
$unauthorizedAccess = $pdo->query("
    SELECT COUNT(*) as total
    FROM audit_logs
    WHERE action = 'access_denied'
    AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
")->fetch()['total'] ?? 0;
$complianceChecks[] = [
    'name' => 'Access Control',
    'status' => $unauthorizedAccess < 10 ? 'pass' : 'warning',
    'message' => $unauthorizedAccess == 0
        ? 'No unauthorized access attempts'
        : "$unauthorizedAccess unauthorized access attempts in last 7 days",
    'severity' => $unauthorizedAccess > 20 ? 'high' : ($unauthorizedAccess > 10 ? 'medium' : 'low')
];

// Check 4: Data Encryption (SSL/HTTPS)
$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
$complianceChecks[] = [
    'name' => 'Data Encryption (HTTPS)',
    'status' => $isHttps ? 'pass' : 'fail',
    'message' => $isHttps ? 'HTTPS is enabled' : 'HTTPS is not enabled - patient data at risk',
    'severity' => $isHttps ? 'low' : 'high'
];

// Check 5: Backup & Recovery
$backupCheck = file_exists(__DIR__ . '/../../backups/latest.sql');
$complianceChecks[] = [
    'name' => 'Data Backup',
    'status' => $backupCheck ? 'pass' : 'warning',
    'message' => $backupCheck ? 'Recent backup found' : 'No recent backup detected',
    'severity' => $backupCheck ? 'low' : 'medium'
];

// Calculate overall compliance score
$totalChecks = count($complianceChecks);
$passedChecks = count(array_filter($complianceChecks, fn($c) => $c['status'] === 'pass'));
$complianceScore = round(($passedChecks / $totalChecks) * 100);
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-md-8">
            <h2><i class="bi bi-shield-check"></i> Compliance & Regulatory Management</h2>
            <p class="text-muted">HIPAA compliance monitoring, data privacy, and regulatory reporting</p>
        </div>
        <div class="col-md-4 text-end">
            <div class="btn-group">
                <button class="btn btn-primary" onclick="generateComplianceReport()">
                    <i class="bi bi-file-earmark-pdf"></i> Generate Report
                </button>
                <button class="btn btn-outline-primary" onclick="exportAuditLog()">
                    <i class="bi bi-download"></i> Export Audit Log
                </button>
            </div>
        </div>
    </div>

    <!-- Compliance Score -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h4 class="mb-2">Overall Compliance Score</h4>
                            <p class="text-muted mb-3">
                                <?php if ($complianceScore >= 80): ?>
                                    <i class="bi bi-check-circle-fill text-success"></i> Your system meets regulatory requirements
                                <?php elseif ($complianceScore >= 60): ?>
                                    <i class="bi bi-exclamation-triangle-fill text-warning"></i> Some compliance issues need attention
                                <?php else: ?>
                                    <i class="bi bi-x-circle-fill text-danger"></i> Critical compliance issues detected
                                <?php endif; ?>
                            </p>
                            <div class="progress" style="height: 30px;">
                                <div class="progress-bar <?= $complianceScore >= 80 ? 'bg-success' : ($complianceScore >= 60 ? 'bg-warning' : 'bg-danger') ?>"
                                     style="width: <?= $complianceScore ?>%">
                                    <strong><?= $complianceScore ?>%</strong>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 text-center">
                            <div style="font-size: 4rem; color: <?= $complianceScore >= 80 ? '#28a745' : ($complianceScore >= 60 ? '#ffc107' : '#dc3545') ?>">
                                <i class="bi bi-<?= $complianceScore >= 80 ? 'shield-check' : ($complianceScore >= 60 ? 'shield-exclamation' : 'shield-x') ?>"></i>
                            </div>
                            <h2 class="mb-0"><?= $passedChecks ?>/<?= $totalChecks ?></h2>
                            <small class="text-muted">Checks Passed</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-4 col-lg-2">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <small class="text-muted">Audit Events (30d)</small>
                    <h3 class="mb-0 text-primary"><?= number_format($stats['audit_events']) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-4 col-lg-2">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <small class="text-muted">Failed Logins (7d)</small>
                    <h3 class="mb-0 text-danger"><?= number_format($stats['failed_logins']) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-4 col-lg-2">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <small class="text-muted">Data Access (24h)</small>
                    <h3 class="mb-0 text-info"><?= number_format($stats['data_access']) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-4 col-lg-2">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <small class="text-muted">Modifications (7d)</small>
                    <h3 class="mb-0 text-warning"><?= number_format($stats['modifications']) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-4 col-lg-2">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <small class="text-muted">Active Users</small>
                    <h3 class="mb-0 text-success"><?= number_format($stats['active_users']) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-4 col-lg-2">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <small class="text-muted">Open Sessions</small>
                    <h3 class="mb-0 text-secondary"><?= number_format($stats['inactive_sessions']) ?></h3>
                </div>
            </div>
        </div>
    </div>

    <!-- Compliance Checks -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="bi bi-clipboard-check"></i> Compliance Checks</h5>
                </div>
                <div class="card-body">
                    <div class="list-group list-group-flush">
                        <?php foreach ($complianceChecks as $check): ?>
                        <div class="list-group-item d-flex justify-content-between align-items-start">
                            <div class="flex-grow-1">
                                <h6 class="mb-1">
                                    <?php if ($check['status'] === 'pass'): ?>
                                        <i class="bi bi-check-circle-fill text-success"></i>
                                    <?php elseif ($check['status'] === 'warning'): ?>
                                        <i class="bi bi-exclamation-triangle-fill text-warning"></i>
                                    <?php else: ?>
                                        <i class="bi bi-x-circle-fill text-danger"></i>
                                    <?php endif; ?>
                                    <?= htmlspecialchars($check['name']) ?>
                                </h6>
                                <p class="mb-0 text-muted"><?= htmlspecialchars($check['message']) ?></p>
                            </div>
                            <span class="badge bg-<?= $check['severity'] === 'high' ? 'danger' : ($check['severity'] === 'medium' ? 'warning' : 'success') ?> rounded-pill">
                                <?= ucfirst($check['severity']) ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <!-- User Access Summary -->
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="bi bi-people"></i> User Access Summary (30 Days)</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                        <table class="table table-sm">
                            <thead class="sticky-top bg-white">
                                <tr>
                                    <th>User</th>
                                    <th>Role</th>
                                    <th>Access Count</th>
                                    <th>Modifications</th>
                                    <th>Last Access</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($userAccess as $user): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($user['full_name']) ?></strong><br>
                                        <small class="text-muted"><?= htmlspecialchars($user['email']) ?></small>
                                    </td>
                                    <td><span class="badge bg-secondary"><?= ucfirst($user['role']) ?></span></td>
                                    <td><?= number_format($user['access_count']) ?></td>
                                    <td>
                                        <span class="badge bg-<?= $user['modifications'] > 100 ? 'warning' : 'info' ?>">
                                            <?= number_format($user['modifications']) ?>
                                        </span>
                                    </td>
                                    <td><small><?= date('M d, H:i', strtotime($user['last_access'])) ?></small></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Module Access Breakdown -->
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="bi bi-grid"></i> Module Access Breakdown</h5>
                </div>
                <div class="card-body">
                    <canvas id="moduleAccessChart" height="300"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Compliance Events -->
    <div class="row">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="bi bi-clock-history"></i> Recent Compliance Events</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover" id="complianceEventsTable">
                            <thead>
                                <tr>
                                    <th>Timestamp</th>
                                    <th>User</th>
                                    <th>Action</th>
                                    <th>Module</th>
                                    <th>Record</th>
                                    <th>IP Address</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentEvents as $event): ?>
                                <tr>
                                    <td><small><?= date('Y-m-d H:i:s', strtotime($event['created_at'])) ?></small></td>
                                    <td>
                                        <strong><?= htmlspecialchars($event['full_name'] ?? $event['username']) ?></strong><br>
                                        <small class="text-muted"><?= ucfirst($event['role'] ?? 'Unknown') ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?=
                                            $event['action'] === 'delete' ? 'danger' :
                                            ($event['action'] === 'create' ? 'success' :
                                            ($event['action'] === 'update' ? 'warning' :
                                            ($event['action'] === 'export' ? 'info' : 'secondary')))
                                        ?>">
                                            <?= strtoupper($event['action']) ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($event['module'] ?? 'N/A') ?></td>
                                    <td>
                                        <small>
                                            <?= htmlspecialchars($event['record_table'] ?? '') ?>
                                            <?php if ($event['record_id']): ?>
                                                #<?= $event['record_id'] ?>
                                            <?php endif; ?>
                                        </small>
                                    </td>
                                    <td><small><?= htmlspecialchars($event['ip_address'] ?? 'N/A') ?></small></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
// Module Access Chart
const moduleData = <?= json_encode($moduleAccess) ?>;
const ctx = document.getElementById('moduleAccessChart').getContext('2d');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: moduleData.map(m => m.module || 'Unknown'),
        datasets: [{
            label: 'Access Count',
            data: moduleData.map(m => m.access_count),
            backgroundColor: 'rgba(13, 110, 253, 0.8)',
            borderColor: 'rgba(13, 110, 253, 1)',
            borderWidth: 1
        }, {
            label: 'Unique Users',
            data: moduleData.map(m => m.unique_users),
            backgroundColor: 'rgba(25, 135, 84, 0.8)',
            borderColor: 'rgba(25, 135, 84, 1)',
            borderWidth: 1
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            y: {
                beginAtZero: true
            }
        }
    }
});

// Initialize DataTable
$(document).ready(function() {
    $('#complianceEventsTable').DataTable({
        order: [[0, 'desc']],
        pageLength: 25
    });
});

// Generate Compliance Report
function generateComplianceReport() {
    window.open('/hospitalman/modules/admin/compliance_report.php', '_blank');
}

// Export Audit Log
function exportAuditLog() {
    window.location.href = '/hospitalman/modules/admin/export_audit.php?format=csv&days=30';
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
