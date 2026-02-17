<?php
$pageTitle = 'Billing Reports';
require_once __DIR__ . '/../../includes/header.php';
requireRole(['admin', 'receptionist']);

$pdo = getDBConnection();

$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');

// Revenue summary
$revStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) as total FROM payments WHERE payment_date BETWEEN ? AND ?");
$revStmt->execute([$from, $to]);
$totalRevenue = $revStmt->fetch()['total'];

$invStmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) as total FROM invoices WHERE invoice_date BETWEEN ? AND ?");
$invStmt->execute([$from, $to]);
$totalInvoiced = $invStmt->fetch()['total'];

$outstanding = $totalInvoiced - $totalRevenue;

// Revenue by payment method
$methodStmt = $pdo->prepare("SELECT payment_method, SUM(amount) as total, COUNT(*) as cnt FROM payments WHERE payment_date BETWEEN ? AND ? GROUP BY payment_method ORDER BY total DESC");
$methodStmt->execute([$from, $to]);
$byMethod = $methodStmt->fetchAll();

// Revenue by category
$catStmt = $pdo->prepare("
    SELECT ii.category, SUM(ii.total_price) as total
    FROM invoice_items ii
    JOIN invoices i ON ii.invoice_id = i.id
    WHERE i.invoice_date BETWEEN ? AND ?
    GROUP BY ii.category ORDER BY total DESC
");
$catStmt->execute([$from, $to]);
$byCategory = $catStmt->fetchAll();

// Daily revenue for chart
$dailyStmt = $pdo->prepare("SELECT payment_date, SUM(amount) as total FROM payments WHERE payment_date BETWEEN ? AND ? GROUP BY payment_date ORDER BY payment_date");
$dailyStmt->execute([$from, $to]);
$dailyData = $dailyStmt->fetchAll();
$dailyLabels = array_column($dailyData, 'payment_date');
$dailyValues = array_column($dailyData, 'total');
?>

<div class="page-header">
    <h4><i class="bi bi-graph-up"></i> Billing Reports</h4>
</div>

<!-- Date Filter -->
<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row align-items-end">
            <div class="col-md-3">
                <label class="form-label">From</label>
                <input type="date" name="from" class="form-control" value="<?= $from ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">To</label>
                <input type="date" name="to" class="form-control" value="<?= $to ?>">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-filter"></i> Filter</button>
            </div>
        </form>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-4">
        <div class="stat-card bg-gradient-primary">
            <div class="stat-value"><?= formatCurrency($totalInvoiced) ?></div>
            <div class="stat-label">Total Invoiced</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card bg-gradient-success">
            <div class="stat-value"><?= formatCurrency($totalRevenue) ?></div>
            <div class="stat-label">Total Collected</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card bg-gradient-warning">
            <div class="stat-value"><?= formatCurrency($outstanding) ?></div>
            <div class="stat-label">Outstanding</div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Daily Revenue Chart -->
    <div class="col-lg-8 mb-3">
        <div class="card">
            <div class="card-header">Daily Revenue</div>
            <div class="card-body">
                <canvas id="dailyChart" height="120"></canvas>
            </div>
        </div>
    </div>

    <!-- By Payment Method -->
    <div class="col-lg-4 mb-3">
        <div class="card">
            <div class="card-header">By Payment Method</div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Method</th><th>Count</th><th>Amount</th></tr></thead>
                    <tbody>
                        <?php foreach ($byMethod as $m): ?>
                        <tr>
                            <td><span class="badge bg-secondary"><?= ucfirst($m['payment_method']) ?></span></td>
                            <td><?= $m['cnt'] ?></td>
                            <td class="fw-bold"><?= formatCurrency($m['total']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header">By Category</div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Category</th><th>Amount</th></tr></thead>
                    <tbody>
                        <?php foreach ($byCategory as $cat): ?>
                        <tr>
                            <td><?= ucfirst($cat['category']) ?></td>
                            <td class="fw-bold"><?= formatCurrency($cat['total']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    new Chart(document.getElementById('dailyChart'), {
        type: 'line',
        data: {
            labels: <?= json_encode($dailyLabels) ?>,
            datasets: [{
                label: 'Revenue ($)',
                data: <?= json_encode($dailyValues) ?>,
                borderColor: '#667eea',
                backgroundColor: 'rgba(102,126,234,0.1)',
                fill: true,
                tension: 0.3
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true } }
        }
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
