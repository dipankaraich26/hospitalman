<?php
$pageTitle = 'Analytics Overview';
require_once __DIR__ . '/../../includes/header.php';
requireRole(['admin']);

$pdo = getDBConnection();

$from = $_GET['from'] ?? date('Y-01-01');
$to = $_GET['to'] ?? date('Y-m-d');

// ===== KPI Metrics =====
$totalPatients = countRows('patients');
$newPatients = countRows('patients', 'created_at BETWEEN ? AND ?', [$from, $to]);
$totalAppointments = countRows('appointments', 'appointment_date BETWEEN ? AND ?', [$from, $to]);
$completedAppts = countRows('appointments', "appointment_date BETWEEN ? AND ? AND status='completed'", [$from, $to]);
$cancelledAppts = countRows('appointments', "appointment_date BETWEEN ? AND ? AND status='cancelled'", [$from, $to]);

$revStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) as total FROM payments WHERE payment_date BETWEEN ? AND ?");
$revStmt->execute([$from, $to]);
$totalRevenue = (float) $revStmt->fetch()['total'];

$invStmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) as total FROM invoices WHERE invoice_date BETWEEN ? AND ?");
$invStmt->execute([$from, $to]);
$totalInvoiced = (float) $invStmt->fetch()['total'];

$outstanding = $totalInvoiced - $totalRevenue;
$collectionRate = $totalInvoiced > 0 ? round(($totalRevenue / $totalInvoiced) * 100, 1) : 0;

$totalConsultations = countRows('consultations', 'created_at BETWEEN ? AND ?', [$from, $to]);
$totalLabTests = countRows('lab_tests', 'test_date BETWEEN ? AND ?', [$from, $to]);
$totalDispensed = countRows('medicine_dispensing', 'dispensed_at BETWEEN ? AND ?', [$from, $to]);

// ===== Monthly Revenue Trend (12 months) =====
$revenueTrend = $pdo->query("
    SELECT DATE_FORMAT(payment_date, '%Y-%m') as month,
           DATE_FORMAT(payment_date, '%b') as label,
           SUM(amount) as revenue
    FROM payments
    WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY month ORDER BY month
")->fetchAll();

// ===== Monthly Patient Registration =====
$patientTrend = $pdo->query("
    SELECT DATE_FORMAT(created_at, '%Y-%m') as month,
           DATE_FORMAT(created_at, '%b') as label,
           COUNT(*) as count
    FROM patients
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY month ORDER BY month
")->fetchAll();

// ===== Revenue by Department =====
$deptRevenue = $pdo->prepare("
    SELECT ii.category, SUM(ii.total_price) as total
    FROM invoice_items ii
    JOIN invoices i ON ii.invoice_id = i.id
    WHERE i.invoice_date BETWEEN ? AND ?
    GROUP BY ii.category ORDER BY total DESC
");
$deptRevenue->execute([$from, $to]);
$deptData = $deptRevenue->fetchAll();

// ===== Appointments by Status =====
$apptByStatus = $pdo->prepare("
    SELECT status, COUNT(*) as count FROM appointments
    WHERE appointment_date BETWEEN ? AND ? GROUP BY status
");
$apptByStatus->execute([$from, $to]);
$apptStatus = $apptByStatus->fetchAll();

// ===== Top 5 Doctors by Consultations =====
$topDoctors = $pdo->prepare("
    SELECT u.full_name, u.specialization, COUNT(*) as consultations
    FROM consultations c
    JOIN users u ON c.doctor_id = u.id
    WHERE c.created_at BETWEEN ? AND ?
    GROUP BY c.doctor_id ORDER BY consultations DESC LIMIT 5
");
$topDoctors->execute([$from, $to]);
$topDocs = $topDoctors->fetchAll();

// ===== Top 5 Diagnoses =====
$topDiag = $pdo->prepare("
    SELECT diagnosis, COUNT(*) as count FROM consultations
    WHERE created_at BETWEEN ? AND ? AND diagnosis IS NOT NULL AND diagnosis != ''
    GROUP BY diagnosis ORDER BY count DESC LIMIT 5
");
$topDiag->execute([$from, $to]);
$topDiagnoses = $topDiag->fetchAll();
?>

<div class="page-header">
    <h4><i class="bi bi-graph-up-arrow"></i> Analytics Overview</h4>
    <button class="btn btn-outline-primary no-print" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
</div>

<!-- Date Range Filter -->
<div class="card mb-4 no-print">
    <div class="card-body py-2">
        <form method="GET" class="row align-items-center g-2">
            <div class="col-auto"><label class="form-label mb-0">Period:</label></div>
            <div class="col-auto">
                <input type="date" name="from" class="form-control form-control-sm" value="<?= $from ?>">
            </div>
            <div class="col-auto">to</div>
            <div class="col-auto">
                <input type="date" name="to" class="form-control form-control-sm" value="<?= $to ?>">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-filter"></i> Apply</button>
            </div>
            <div class="col-auto">
                <a href="?from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-d') ?>" class="btn btn-outline-secondary btn-sm">This Month</a>
                <a href="?from=<?= date('Y-01-01') ?>&to=<?= date('Y-m-d') ?>" class="btn btn-outline-secondary btn-sm">This Year</a>
            </div>
        </form>
    </div>
</div>

<!-- KPI Cards Row 1 -->
<div class="row mb-3">
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stat-card bg-gradient-primary">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-value"><?= formatCurrency($totalRevenue) ?></div>
                    <div class="stat-label">Total Revenue</div>
                </div>
                <i class="bi bi-currency-dollar stat-icon"></i>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stat-card bg-gradient-success">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-value"><?= $collectionRate ?>%</div>
                    <div class="stat-label">Collection Rate</div>
                </div>
                <i class="bi bi-percent stat-icon"></i>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stat-card bg-gradient-warning">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-value"><?= formatCurrency($outstanding) ?></div>
                    <div class="stat-label">Outstanding</div>
                </div>
                <i class="bi bi-exclamation-triangle stat-icon"></i>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stat-card bg-gradient-info">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-value"><?= $newPatients ?></div>
                    <div class="stat-label">New Patients</div>
                </div>
                <i class="bi bi-person-plus stat-icon"></i>
            </div>
        </div>
    </div>
</div>

<!-- KPI Cards Row 2 -->
<div class="row mb-4">
    <div class="col-xl-2 col-md-4 mb-2">
        <div class="card text-center"><div class="card-body py-3"><h4 class="mb-0"><?= $totalAppointments ?></h4><small class="text-muted">Appointments</small></div></div>
    </div>
    <div class="col-xl-2 col-md-4 mb-2">
        <div class="card text-center"><div class="card-body py-3"><h4 class="mb-0 text-success"><?= $completedAppts ?></h4><small class="text-muted">Completed</small></div></div>
    </div>
    <div class="col-xl-2 col-md-4 mb-2">
        <div class="card text-center"><div class="card-body py-3"><h4 class="mb-0 text-danger"><?= $cancelledAppts ?></h4><small class="text-muted">Cancelled</small></div></div>
    </div>
    <div class="col-xl-2 col-md-4 mb-2">
        <div class="card text-center"><div class="card-body py-3"><h4 class="mb-0"><?= $totalConsultations ?></h4><small class="text-muted">Consultations</small></div></div>
    </div>
    <div class="col-xl-2 col-md-4 mb-2">
        <div class="card text-center"><div class="card-body py-3"><h4 class="mb-0"><?= $totalLabTests ?></h4><small class="text-muted">Lab Tests</small></div></div>
    </div>
    <div class="col-xl-2 col-md-4 mb-2">
        <div class="card text-center"><div class="card-body py-3"><h4 class="mb-0"><?= $totalDispensed ?></h4><small class="text-muted">Dispensed</small></div></div>
    </div>
</div>

<!-- Charts Row -->
<div class="row mb-4">
    <div class="col-lg-8 mb-3">
        <div class="card">
            <div class="card-header">Revenue & Patient Trends (12 Months)</div>
            <div class="card-body"><canvas id="trendChart" height="110"></canvas></div>
        </div>
    </div>
    <div class="col-lg-4 mb-3">
        <div class="card">
            <div class="card-header">Revenue by Department</div>
            <div class="card-body"><canvas id="deptChart" height="220"></canvas></div>
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-lg-4 mb-3">
        <div class="card">
            <div class="card-header">Appointment Status</div>
            <div class="card-body"><canvas id="apptChart" height="220"></canvas></div>
        </div>
    </div>
    <div class="col-lg-4 mb-3">
        <div class="card">
            <div class="card-header"><i class="bi bi-trophy"></i> Top Doctors</div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Doctor</th><th>Specialization</th><th>Consults</th></tr></thead>
                    <tbody>
                        <?php foreach ($topDocs as $doc): ?>
                        <tr>
                            <td><strong><?= sanitize($doc['full_name']) ?></strong></td>
                            <td><small><?= sanitize($doc['specialization'] ?? '-') ?></small></td>
                            <td><span class="badge bg-primary"><?= $doc['consultations'] ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($topDocs)): ?><tr><td colspan="3" class="text-muted text-center py-3">No data</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-4 mb-3">
        <div class="card">
            <div class="card-header"><i class="bi bi-clipboard2-data"></i> Top Diagnoses</div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Diagnosis</th><th>Count</th></tr></thead>
                    <tbody>
                        <?php foreach ($topDiagnoses as $d): ?>
                        <tr>
                            <td><?= sanitize($d['diagnosis']) ?></td>
                            <td><span class="badge bg-warning text-dark"><?= $d['count'] ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($topDiagnoses)): ?><tr><td colspan="2" class="text-muted text-center py-3">No data</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Quick Links -->
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">Detailed Reports</div>
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-md-3 mb-2">
                        <a href="patients.php" class="btn btn-outline-primary w-100 py-3">
                            <i class="bi bi-people fs-3 d-block mb-1"></i> Patient Reports
                        </a>
                    </div>
                    <div class="col-md-3 mb-2">
                        <a href="financial.php" class="btn btn-outline-success w-100 py-3">
                            <i class="bi bi-cash-stack fs-3 d-block mb-1"></i> Financial Reports
                        </a>
                    </div>
                    <div class="col-md-3 mb-2">
                        <a href="clinical.php" class="btn btn-outline-info w-100 py-3">
                            <i class="bi bi-clipboard2-pulse fs-3 d-block mb-1"></i> Clinical Reports
                        </a>
                    </div>
                    <div class="col-md-3 mb-2">
                        <a href="pharmacy.php" class="btn btn-outline-warning w-100 py-3">
                            <i class="bi bi-capsule fs-3 d-block mb-1"></i> Pharmacy Reports
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Combined Revenue + Patient Trend
    var revLabels = <?= json_encode(array_column($revenueTrend, 'label')) ?>;
    var patLabels = <?= json_encode(array_column($patientTrend, 'label')) ?>;
    var allLabels = [...new Set([...revLabels, ...patLabels])];

    new Chart(document.getElementById('trendChart'), {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_column($revenueTrend, 'label')) ?>,
            datasets: [{
                label: 'Revenue ($)',
                data: <?= json_encode(array_column($revenueTrend, 'revenue')) ?>,
                backgroundColor: 'rgba(102,126,234,0.7)',
                borderRadius: 6,
                yAxisID: 'y'
            }, {
                label: 'New Patients',
                data: <?= json_encode(array_column($patientTrend, 'count')) ?>,
                type: 'line',
                borderColor: '#38ef7d',
                backgroundColor: 'rgba(56,239,125,0.1)',
                fill: true,
                tension: 0.3,
                yAxisID: 'y1'
            }]
        },
        options: {
            responsive: true,
            interaction: { mode: 'index', intersect: false },
            scales: {
                y: { beginAtZero: true, position: 'left', title: { display: true, text: 'Revenue ($)' } },
                y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'Patients' } }
            }
        }
    });

    // Department Doughnut
    new Chart(document.getElementById('deptChart'), {
        type: 'doughnut',
        data: {
            labels: <?= json_encode(array_map(fn($d) => ucfirst($d['category']), $deptData)) ?>,
            datasets: [{ data: <?= json_encode(array_column($deptData, 'total')) ?>,
                backgroundColor: ['#667eea','#38ef7d','#f5576c','#4facfe','#f093fb','#ffd93d'] }]
        },
        options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
    });

    // Appointment Status Pie
    new Chart(document.getElementById('apptChart'), {
        type: 'pie',
        data: {
            labels: <?= json_encode(array_map(fn($a) => ucfirst($a['status']), $apptStatus)) ?>,
            datasets: [{ data: <?= json_encode(array_column($apptStatus, 'count')) ?>,
                backgroundColor: ['#4facfe','#38ef7d','#f5576c'] }]
        },
        options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
