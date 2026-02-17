<?php
$pageTitle = 'Dashboard';
require_once __DIR__ . '/../../includes/header.php';

$pdo = getDBConnection();

// Stats
$totalPatients = countRows('patients');
$todayAppointments = countRows('appointments', "appointment_date = CURDATE() AND status = 'scheduled'");
$pendingInvoices = countRows('invoices', "status IN ('unpaid','partial')");
$lowStockMeds = countRows('medicines', 'quantity_in_stock <= reorder_level');

// Monthly revenue (last 6 months)
$revenueStmt = $pdo->query("
    SELECT DATE_FORMAT(payment_date, '%Y-%m') as month,
           DATE_FORMAT(payment_date, '%b %Y') as label,
           SUM(amount) as total
    FROM payments
    WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY month
    ORDER BY month
");
$revenueData = $revenueStmt->fetchAll();
$revenueLabels = array_column($revenueData, 'label');
$revenueValues = array_column($revenueData, 'total');

// Patient registrations (last 6 months)
$patientTrendStmt = $pdo->query("
    SELECT DATE_FORMAT(created_at, '%Y-%m') as month,
           DATE_FORMAT(created_at, '%b %Y') as label,
           COUNT(*) as total
    FROM patients
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY month
    ORDER BY month
");
$patientTrend = $patientTrendStmt->fetchAll();
$trendLabels = array_column($patientTrend, 'label');
$trendValues = array_column($patientTrend, 'total');

// Billing by category
$categoryStmt = $pdo->query("
    SELECT category, SUM(total_price) as total
    FROM invoice_items
    GROUP BY category
    ORDER BY total DESC
");
$categoryData = $categoryStmt->fetchAll();
$catLabels = array_map(function($c) { return ucfirst($c['category']); }, $categoryData);
$catValues = array_column($categoryData, 'total');

// Recent appointments
$recentAppts = $pdo->query("
    SELECT a.*, p.first_name, p.last_name, p.patient_id as pid, u.full_name as doctor_name
    FROM appointments a
    JOIN patients p ON a.patient_id = p.id
    JOIN users u ON a.doctor_id = u.id
    WHERE a.appointment_date >= CURDATE()
    ORDER BY a.appointment_date, a.appointment_time
    LIMIT 5
")->fetchAll();

// Recent payments
$recentPayments = $pdo->query("
    SELECT py.*, i.invoice_number, p.first_name, p.last_name
    FROM payments py
    JOIN invoices i ON py.invoice_id = i.id
    JOIN patients p ON i.patient_id = p.id
    ORDER BY py.created_at DESC
    LIMIT 5
")->fetchAll();

// Expiry alerts (medicines expiring within 90 days)
$expiryAlerts = $pdo->query("
    SELECT name, batch_number, expiry_date, quantity_in_stock
    FROM medicines
    WHERE expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)
    AND expiry_date >= CURDATE()
    ORDER BY expiry_date
    LIMIT 5
")->fetchAll();

// Predictive Analytics — revenue forecast overlay + alerts
require_once __DIR__ . '/../../includes/analytics.php';
$revForecast = [];
$predictiveAlerts = [];
try {
    if (count($revenueValues) >= 3) {
        $revRegression = linearRegression(array_map('floatval', $revenueValues), 3);
        $lastMonth = end($revenueData)['month'] ?? date('Y-m');
        for ($i = 1; $i <= 3; $i++) {
            $revForecast[] = [
                'label' => date('M Y', strtotime($lastMonth . '-01 +' . $i . ' months')),
                'value' => $revRegression['predicted'][$i - 1]
            ];
        }
    }
    $predictiveAlerts = generatePredictiveAlerts($pdo);
} catch (\Exception $e) {
    // Predictions are non-critical
}
?>

<div class="page-header">
    <h4><i class="bi bi-speedometer2"></i> Dashboard</h4>
    <span class="text-muted">Welcome, <?= sanitize($currentUser['full_name']) ?></span>
</div>

<!-- Stats Cards -->
<div class="row mb-4">
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stat-card bg-gradient-primary">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-value"><?= $totalPatients ?></div>
                    <div class="stat-label">Total Patients</div>
                </div>
                <i class="bi bi-people stat-icon"></i>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stat-card bg-gradient-success">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-value"><?= $todayAppointments ?></div>
                    <div class="stat-label">Today's Appointments</div>
                </div>
                <i class="bi bi-calendar-check stat-icon"></i>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stat-card bg-gradient-warning">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-value"><?= $pendingInvoices ?></div>
                    <div class="stat-label">Pending Invoices</div>
                </div>
                <i class="bi bi-receipt stat-icon"></i>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stat-card bg-gradient-info">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-value"><?= $lowStockMeds ?></div>
                    <div class="stat-label">Low Stock Medicines</div>
                </div>
                <i class="bi bi-capsule stat-icon"></i>
            </div>
        </div>
    </div>
</div>

<!-- Predictive Alerts -->
<?php if (!empty($predictiveAlerts)): ?>
<div class="row mb-4">
    <div class="col-12">
        <div class="card border-info">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-lightning"></i> Predictive Alerts</span>
                <a href="<?= BASE_URL ?>/modules/dashboard/predictions.php" class="btn btn-sm btn-outline-info">View All Predictions</a>
            </div>
            <div class="card-body py-2">
                <?php foreach (array_slice($predictiveAlerts, 0, 3) as $pa): ?>
                <div class="d-flex align-items-center py-1">
                    <i class="bi bi-<?= $pa['icon'] ?> text-<?= $pa['type'] ?> me-2 fs-5"></i>
                    <span><?= sanitize($pa['message']) ?></span>
                    <span class="badge bg-secondary ms-auto"><?= ucfirst($pa['module']) ?></span>
                </div>
                <?php endforeach; ?>
                <?php if (count($predictiveAlerts) > 3): ?>
                <div class="text-center mt-1">
                    <a href="<?= BASE_URL ?>/modules/dashboard/predictions.php" class="text-muted small">+<?= count($predictiveAlerts) - 3 ?> more alerts</a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Charts Row -->
<div class="row mb-4">
    <div class="col-lg-8 mb-3">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-bar-chart"></i> Monthly Revenue</span>
            </div>
            <div class="card-body">
                <canvas id="revenueChart" height="100"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-4 mb-3">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-pie-chart"></i> Billing by Category
            </div>
            <div class="card-body">
                <canvas id="categoryChart" height="200"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Recent Activity Row -->
<div class="row">
    <div class="col-lg-6 mb-3">
        <div class="card">
            <div class="card-header d-flex justify-content-between">
                <span><i class="bi bi-calendar3"></i> Upcoming Appointments</span>
                <?php if (canAccess('clinical')): ?>
                <a href="<?= BASE_URL ?>/modules/clinical/index.php" class="btn btn-sm btn-outline-primary">View All</a>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr><th>Patient</th><th>Doctor</th><th>Date/Time</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentAppts)): ?>
                        <tr><td colspan="4" class="text-center text-muted py-3">No upcoming appointments</td></tr>
                        <?php else: foreach ($recentAppts as $appt): ?>
                        <tr>
                            <td><?= sanitize($appt['first_name'] . ' ' . $appt['last_name']) ?></td>
                            <td><?= sanitize($appt['doctor_name']) ?></td>
                            <td><?= formatDate($appt['appointment_date']) ?><br><small class="text-muted"><?= date('h:i A', strtotime($appt['appointment_time'])) ?></small></td>
                            <td><?= getStatusBadge($appt['status']) ?></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-6 mb-3">
        <div class="card">
            <div class="card-header d-flex justify-content-between">
                <span><i class="bi bi-cash-stack"></i> Recent Payments</span>
                <?php if (canAccess('billing')): ?>
                <a href="<?= BASE_URL ?>/modules/billing/payments.php" class="btn btn-sm btn-outline-primary">View All</a>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr><th>Invoice</th><th>Patient</th><th>Amount</th><th>Method</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentPayments)): ?>
                        <tr><td colspan="4" class="text-center text-muted py-3">No recent payments</td></tr>
                        <?php else: foreach ($recentPayments as $pay): ?>
                        <tr>
                            <td><strong><?= sanitize($pay['invoice_number']) ?></strong></td>
                            <td><?= sanitize($pay['first_name'] . ' ' . $pay['last_name']) ?></td>
                            <td class="text-success fw-bold"><?= formatCurrency($pay['amount']) ?></td>
                            <td><span class="badge bg-secondary"><?= ucfirst($pay['payment_method']) ?></span></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($expiryAlerts)): ?>
<div class="row">
    <div class="col-12">
        <div class="card border-warning">
            <div class="card-header text-warning">
                <i class="bi bi-exclamation-triangle"></i> Medicine Expiry Alerts (within 90 days)
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Medicine</th><th>Batch</th><th>Expiry Date</th><th>Stock</th></tr></thead>
                    <tbody>
                        <?php foreach ($expiryAlerts as $med): ?>
                        <tr>
                            <td><?= sanitize($med['name']) ?></td>
                            <td><?= sanitize($med['batch_number']) ?></td>
                            <td class="text-danger"><?= formatDate($med['expiry_date']) ?></td>
                            <td><?= $med['quantity_in_stock'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Revenue Chart with Forecast Overlay
    const revHistLabels = <?= json_encode($revenueLabels) ?>;
    const revForecastData = <?= json_encode($revForecast) ?>;
    const revAllLabels = revHistLabels.concat(revForecastData.map(f => f.label));
    const revHistValues = <?= json_encode($revenueValues) ?>.concat(Array(revForecastData.length).fill(null));
    const revPredValues = Array(revHistLabels.length).fill(null).concat(revForecastData.map(f => f.value));

    const revDatasets = [{
        label: 'Revenue ($)',
        data: revHistValues,
        backgroundColor: 'rgba(102, 126, 234, 0.8)',
        borderColor: 'rgba(102, 126, 234, 1)',
        borderWidth: 1,
        borderRadius: 6
    }];

    if (revForecastData.length > 0) {
        revDatasets.push({
            label: 'Forecast ($)',
            data: revPredValues,
            backgroundColor: 'rgba(245, 87, 108, 0.4)',
            borderColor: 'rgba(245, 87, 108, 0.8)',
            borderWidth: 1,
            borderRadius: 6,
            borderDash: [4, 4]
        });
    }

    new Chart(document.getElementById('revenueChart'), {
        type: 'bar',
        data: {
            labels: revAllLabels,
            datasets: revDatasets
        },
        options: {
            responsive: true,
            plugins: { legend: { display: revForecastData.length > 0, position: 'top' } },
            scales: { y: { beginAtZero: true } }
        }
    });

    // Category Pie Chart
    new Chart(document.getElementById('categoryChart'), {
        type: 'doughnut',
        data: {
            labels: <?= json_encode($catLabels) ?>,
            datasets: [{
                data: <?= json_encode($catValues) ?>,
                backgroundColor: ['#667eea','#38ef7d','#f5576c','#4facfe','#f093fb','#ffd93d']
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { position: 'bottom', labels: { padding: 15 } } }
        }
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
