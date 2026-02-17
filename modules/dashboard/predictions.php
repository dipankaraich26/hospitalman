<?php
$pageTitle = 'Predictive Analytics';
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/analytics.php';
requireRole(['admin', 'doctor']);

$pdo = getDBConnection();

// --- Admission Forecast (12-month history → 3-month prediction) ---
$admissionStmt = $pdo->query("
    SELECT DATE_FORMAT(appointment_date, '%Y-%m') as month,
           DATE_FORMAT(appointment_date, '%b %Y') as label,
           COUNT(*) as total
    FROM appointments
    WHERE appointment_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY month
    ORDER BY month
");
$admissionData = $admissionStmt->fetchAll();
$admLabels = array_column($admissionData, 'label');
$admValues = array_map('intval', array_column($admissionData, 'total'));

$admSMA = simpleMovingAverage($admValues, 3, 3);
$admRegression = linearRegression(array_map('floatval', $admValues), 3);

// Generate forecast labels
$lastMonth = !empty($admissionData) ? end($admissionData)['month'] : date('Y-m');
$forecastLabels = [];
for ($i = 1; $i <= 3; $i++) {
    $forecastLabels[] = date('M Y', strtotime($lastMonth . '-01 +' . $i . ' months'));
}

// --- Revenue Prediction (12-month history → 3-month forecast) ---
$revenueStmt = $pdo->query("
    SELECT DATE_FORMAT(payment_date, '%Y-%m') as month,
           DATE_FORMAT(payment_date, '%b %Y') as label,
           SUM(amount) as total
    FROM payments
    WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY month
    ORDER BY month
");
$revenueData = $revenueStmt->fetchAll();
$revLabels = array_column($revenueData, 'label');
$revValues = array_map('floatval', array_column($revenueData, 'total'));

$revSMA = simpleMovingAverage($revValues, 3, 3);
$revRegression = linearRegression($revValues, 3);

// --- Stock-out Predictions ---
$medicines = $pdo->query("
    SELECT id, name, quantity_in_stock, reorder_level
    FROM medicines
    WHERE quantity_in_stock > 0
    ORDER BY name
")->fetchAll();

$stockPredictions = [];
foreach ($medicines as $med) {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(quantity), 0) as total
        FROM medicine_dispensing
        WHERE medicine_id = ? AND dispensed_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
    ");
    $stmt->execute([$med['id']]);
    $dispensed = (int) $stmt->fetch()['total'];

    if ($dispensed > 0) {
        $prediction = predictStockout($med['quantity_in_stock'], [$dispensed], 90);
        $stockPredictions[] = array_merge($prediction, [
            'name' => $med['name'],
            'current_stock' => $med['quantity_in_stock'],
            'reorder_level' => $med['reorder_level']
        ]);
    }
}

// Sort by days until stockout
usort($stockPredictions, fn($a, $b) => $a['days_until_stockout'] - $b['days_until_stockout']);

// --- Predictive Alerts ---
$alerts = generatePredictiveAlerts($pdo);
?>

<div class="page-header">
    <h4><i class="bi bi-graph-up-arrow"></i> Predictive Analytics</h4>
    <a href="<?= BASE_URL ?>/modules/dashboard/index.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> Back to Dashboard
    </a>
</div>

<!-- Alert Summary -->
<?php if (!empty($alerts)): ?>
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-bell"></i> Predictive Alerts
                <span class="badge bg-danger ms-2"><?= count($alerts) ?></span>
            </div>
            <div class="card-body">
                <?php foreach ($alerts as $alert): ?>
                <div class="alert alert-<?= $alert['type'] ?> d-flex align-items-center mb-2" role="alert">
                    <i class="bi bi-<?= $alert['icon'] ?> me-2 fs-5"></i>
                    <div>
                        <?= sanitize($alert['message']) ?>
                        <span class="badge bg-secondary ms-2"><?= ucfirst($alert['module']) ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Charts Row 1: Admissions + Revenue -->
<div class="row mb-4">
    <div class="col-lg-6 mb-3">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-calendar-check"></i> Admission Forecast</span>
                <small class="text-muted">R² = <?= $admRegression['r_squared'] ?></small>
            </div>
            <div class="card-body">
                <canvas id="admissionChart" height="180"></canvas>
                <div class="mt-2">
                    <small class="text-muted">
                        <span style="color: rgba(102,126,234,1);">&#9632;</span> Historical
                        <span class="ms-2" style="color: rgba(102,126,234,0.5);">&#9632;</span> Predicted (Linear Regression)
                        <span class="ms-2" style="color: rgba(56,239,125,0.7);">&#9632;</span> Moving Average
                    </small>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-6 mb-3">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-currency-dollar"></i> Revenue Prediction</span>
                <small class="text-muted">R² = <?= $revRegression['r_squared'] ?></small>
            </div>
            <div class="card-body">
                <canvas id="revenueChart" height="180"></canvas>
                <div class="mt-2">
                    <small class="text-muted">
                        <span style="color: rgba(102,126,234,0.8);">&#9632;</span> Historical
                        <span class="ms-2" style="color: rgba(245,87,108,0.6);">&#9632;</span> Predicted (Regression)
                        <span class="ms-2" style="color: rgba(56,239,125,0.7);">&#9632;</span> Trend Line
                    </small>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Stock-out Predictions Table -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-capsule"></i> Medicine Stock-out Predictions</span>
                <span>
                    <span class="badge bg-danger">Critical (&lt;7 days)</span>
                    <span class="badge bg-warning text-dark">Warning (&lt;30 days)</span>
                    <span class="badge bg-success">Safe (&gt;30 days)</span>
                </span>
            </div>
            <div class="card-body">
                <table class="table table-hover data-table">
                    <thead>
                        <tr>
                            <th>Medicine</th>
                            <th>Current Stock</th>
                            <th>Reorder Level</th>
                            <th>Daily Usage Rate</th>
                            <th>Days Until Stockout</th>
                            <th>Predicted Stockout Date</th>
                            <th>Confidence</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($stockPredictions)): ?>
                        <tr><td colspan="8" class="text-center text-muted py-3">No dispensing data available for predictions</td></tr>
                        <?php else: foreach ($stockPredictions as $sp): ?>
                        <?php
                        $rowClass = '';
                        $statusBadge = '';
                        if ($sp['days_until_stockout'] <= 7) {
                            $rowClass = 'table-danger';
                            $statusBadge = '<span class="badge bg-danger">Critical</span>';
                        } elseif ($sp['days_until_stockout'] <= 30) {
                            $rowClass = 'table-warning';
                            $statusBadge = '<span class="badge bg-warning text-dark">Warning</span>';
                        } else {
                            $statusBadge = '<span class="badge bg-success">Safe</span>';
                        }
                        ?>
                        <tr class="<?= $rowClass ?>">
                            <td><strong><?= sanitize($sp['name']) ?></strong></td>
                            <td><?= $sp['current_stock'] ?></td>
                            <td><?= $sp['reorder_level'] ?></td>
                            <td><?= $sp['daily_rate'] ?> units/day</td>
                            <td><strong><?= $sp['days_until_stockout'] ?></strong> days</td>
                            <td><?= $sp['stockout_date'] ? formatDate($sp['stockout_date']) : 'N/A' ?></td>
                            <td>
                                <?php
                                $confBadge = match($sp['confidence']) {
                                    'high' => 'bg-success',
                                    'medium' => 'bg-warning text-dark',
                                    default => 'bg-secondary'
                                };
                                ?>
                                <span class="badge <?= $confBadge ?>"><?= ucfirst($sp['confidence']) ?></span>
                            </td>
                            <td><?= $statusBadge ?></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Model Summary -->
<div class="row mb-4">
    <div class="col-md-6 mb-3">
        <div class="card">
            <div class="card-header"><i class="bi bi-info-circle"></i> Admission Model Summary</div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr><td>Data Points</td><td><strong><?= count($admValues) ?></strong> months</td></tr>
                    <tr><td>Slope (Trend)</td><td><strong><?= $admRegression['slope'] > 0 ? '+' : '' ?><?= $admRegression['slope'] ?></strong> patients/month</td></tr>
                    <tr><td>R² (Fit Quality)</td><td>
                        <strong><?= $admRegression['r_squared'] ?></strong>
                        <?php if ($admRegression['r_squared'] >= 0.7): ?><span class="badge bg-success">Good</span>
                        <?php elseif ($admRegression['r_squared'] >= 0.4): ?><span class="badge bg-warning text-dark">Fair</span>
                        <?php else: ?><span class="badge bg-secondary">Low</span>
                        <?php endif; ?>
                    </td></tr>
                    <tr><td>3-Month Forecast</td><td><strong><?= implode(', ', $admRegression['predicted']) ?></strong></td></tr>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-6 mb-3">
        <div class="card">
            <div class="card-header"><i class="bi bi-info-circle"></i> Revenue Model Summary</div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr><td>Data Points</td><td><strong><?= count($revValues) ?></strong> months</td></tr>
                    <tr><td>Slope (Trend)</td><td><strong><?= $revRegression['slope'] > 0 ? '+' : '' ?><?= formatCurrency($revRegression['slope']) ?></strong>/month</td></tr>
                    <tr><td>R² (Fit Quality)</td><td>
                        <strong><?= $revRegression['r_squared'] ?></strong>
                        <?php if ($revRegression['r_squared'] >= 0.7): ?><span class="badge bg-success">Good</span>
                        <?php elseif ($revRegression['r_squared'] >= 0.4): ?><span class="badge bg-warning text-dark">Fair</span>
                        <?php else: ?><span class="badge bg-secondary">Low</span>
                        <?php endif; ?>
                    </td></tr>
                    <tr><td>3-Month Forecast</td><td><strong><?= implode(', ', array_map('formatCurrency', $revRegression['predicted'])) ?></strong></td></tr>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const historicalLabels = <?= json_encode($admLabels) ?>;
    const forecastLabels = <?= json_encode($forecastLabels) ?>;
    const allAdmLabels = historicalLabels.concat(forecastLabels);

    // --- Admission Forecast Chart ---
    const admHistorical = <?= json_encode($admValues) ?>;
    const admPredicted = <?= json_encode($admRegression['predicted']) ?>;
    const admSmaSmoothed = <?= json_encode($admSMA['smoothed']) ?>;

    // Build datasets: historical line (solid) + predicted (dashed)
    const admHistLine = admHistorical.concat(Array(forecastLabels.length).fill(null));
    const admPredLine = Array(admHistorical.length - 1).fill(null).concat([admHistorical[admHistorical.length - 1]]).concat(admPredicted);
    const admSmaLine = admSmaSmoothed.concat(Array(forecastLabels.length).fill(null));

    new Chart(document.getElementById('admissionChart'), {
        type: 'line',
        data: {
            labels: allAdmLabels,
            datasets: [
                {
                    label: 'Actual Admissions',
                    data: admHistLine,
                    borderColor: 'rgba(102, 126, 234, 1)',
                    backgroundColor: 'rgba(102, 126, 234, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.3,
                    pointRadius: 4
                },
                {
                    label: 'Predicted (Regression)',
                    data: admPredLine,
                    borderColor: 'rgba(102, 126, 234, 0.5)',
                    borderWidth: 2,
                    borderDash: [8, 4],
                    fill: false,
                    tension: 0.3,
                    pointRadius: 5,
                    pointStyle: 'triangle'
                },
                {
                    label: 'Moving Average',
                    data: admSmaLine,
                    borderColor: 'rgba(56, 239, 125, 0.7)',
                    borderWidth: 1.5,
                    borderDash: [4, 2],
                    fill: false,
                    tension: 0.3,
                    pointRadius: 0
                }
            ]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, title: { display: true, text: 'Appointments' } },
                x: { title: { display: true, text: 'Month' } }
            }
        }
    });

    // --- Revenue Prediction Chart ---
    const revHistLabels = <?= json_encode($revLabels) ?>;
    const allRevLabels = revHistLabels.concat(forecastLabels);
    const revHistorical = <?= json_encode($revValues) ?>;
    const revPredicted = <?= json_encode($revRegression['predicted']) ?>;
    const revSmaSmoothed = <?= json_encode($revSMA['smoothed']) ?>;

    // Bar data: historical solid bars + predicted lighter bars
    const revBarData = revHistorical.concat(Array(forecastLabels.length).fill(null));
    const revBarPredData = Array(revHistorical.length).fill(null).concat(revPredicted);

    // Trend line from SMA
    const revTrendLine = revSmaSmoothed.concat(Array(forecastLabels.length).fill(null));

    new Chart(document.getElementById('revenueChart'), {
        type: 'bar',
        data: {
            labels: allRevLabels,
            datasets: [
                {
                    label: 'Actual Revenue',
                    data: revBarData,
                    backgroundColor: 'rgba(102, 126, 234, 0.8)',
                    borderColor: 'rgba(102, 126, 234, 1)',
                    borderWidth: 1,
                    borderRadius: 6
                },
                {
                    label: 'Predicted Revenue',
                    data: revBarPredData,
                    backgroundColor: 'rgba(245, 87, 108, 0.4)',
                    borderColor: 'rgba(245, 87, 108, 0.8)',
                    borderWidth: 1,
                    borderRadius: 6,
                    borderDash: [4, 4]
                },
                {
                    label: 'Trend (SMA)',
                    data: revTrendLine,
                    type: 'line',
                    borderColor: 'rgba(56, 239, 125, 0.7)',
                    borderWidth: 2,
                    fill: false,
                    tension: 0.4,
                    pointRadius: 0
                }
            ]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, title: { display: true, text: 'Revenue ($)' } },
                x: { title: { display: true, text: 'Month' } }
            }
        }
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
