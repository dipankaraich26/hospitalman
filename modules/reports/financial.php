<?php
$pageTitle = 'Financial Reports';
require_once __DIR__ . '/../../includes/header.php';
requireRole(['admin']);

$pdo = getDBConnection();
$from = $_GET['from'] ?? date('Y-01-01');
$to = $_GET['to'] ?? date('Y-m-d');

// ===== KPI Metrics =====
$invStmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) as total, COUNT(*) as count FROM invoices WHERE invoice_date BETWEEN ? AND ?");
$invStmt->execute([$from, $to]);
$invSummary = $invStmt->fetch();

$revStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) as total FROM payments WHERE payment_date BETWEEN ? AND ?");
$revStmt->execute([$from, $to]);
$totalRevenue = (float) $revStmt->fetch()['total'];

$outstanding = (float) $invSummary['total'] - $totalRevenue;
$avgInvoice = $invSummary['count'] > 0 ? (float) $invSummary['total'] / $invSummary['count'] : 0;

$paidCount = countRows('invoices', "invoice_date BETWEEN ? AND ? AND status='paid'", [$from, $to]);
$partialCount = countRows('invoices', "invoice_date BETWEEN ? AND ? AND status='partial'", [$from, $to]);
$unpaidCount = countRows('invoices', "invoice_date BETWEEN ? AND ? AND status='unpaid'", [$from, $to]);

// ===== Daily Revenue =====
$daily = $pdo->prepare("
    SELECT payment_date as day, SUM(amount) as total
    FROM payments WHERE payment_date BETWEEN ? AND ?
    GROUP BY payment_date ORDER BY payment_date
");
$daily->execute([$from, $to]);
$dailyData = $daily->fetchAll();

// ===== Monthly Comparison =====
$monthly = $pdo->query("
    SELECT DATE_FORMAT(payment_date, '%Y-%m') as month, DATE_FORMAT(payment_date, '%b %Y') as label,
           SUM(amount) as revenue
    FROM payments WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY month ORDER BY month
")->fetchAll();

// ===== Revenue by Payment Method =====
$byMethod = $pdo->prepare("
    SELECT payment_method, SUM(amount) as total, COUNT(*) as count
    FROM payments WHERE payment_date BETWEEN ? AND ?
    GROUP BY payment_method ORDER BY total DESC
");
$byMethod->execute([$from, $to]);
$methodData = $byMethod->fetchAll();

// ===== Revenue by Category =====
$byCat = $pdo->prepare("
    SELECT ii.category, SUM(ii.total_price) as total, COUNT(*) as items
    FROM invoice_items ii JOIN invoices i ON ii.invoice_id = i.id
    WHERE i.invoice_date BETWEEN ? AND ?
    GROUP BY ii.category ORDER BY total DESC
");
$byCat->execute([$from, $to]);
$catData = $byCat->fetchAll();

// ===== Invoice Status Distribution =====
$invStatus = $pdo->prepare("
    SELECT status, COUNT(*) as count, SUM(total_amount) as amount
    FROM invoices WHERE invoice_date BETWEEN ? AND ?
    GROUP BY status
");
$invStatus->execute([$from, $to]);
$statusData = $invStatus->fetchAll();

// ===== Top 10 Highest Invoices =====
$topInvoices = $pdo->prepare("
    SELECT i.invoice_number, i.invoice_date, i.total_amount, i.status,
           p.first_name, p.last_name, p.patient_id as pid,
           (SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id = i.id) as paid
    FROM invoices i JOIN patients p ON i.patient_id = p.id
    WHERE i.invoice_date BETWEEN ? AND ?
    ORDER BY i.total_amount DESC LIMIT 10
");
$topInvoices->execute([$from, $to]);
$topInv = $topInvoices->fetchAll();

// ===== Insurance vs Self-Pay =====
$insRevenue = $pdo->prepare("SELECT COALESCE(SUM(amount),0) as total FROM payments WHERE payment_method='insurance' AND payment_date BETWEEN ? AND ?");
$insRevenue->execute([$from, $to]);
$insRev = (float) $insRevenue->fetch()['total'];
$selfPayRev = $totalRevenue - $insRev;
?>

<div class="page-header">
    <h4><i class="bi bi-cash-stack"></i> Financial Reports</h4>
    <div class="no-print">
        <a href="export.php?type=financial&from=<?= $from ?>&to=<?= $to ?>" class="btn btn-outline-success"><i class="bi bi-download"></i> Export CSV</a>
        <button onclick="window.print()" class="btn btn-outline-primary"><i class="bi bi-printer"></i> Print</button>
        <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Overview</a>
    </div>
</div>

<div class="card mb-4 no-print">
    <div class="card-body py-2">
        <form method="GET" class="row align-items-center g-2">
            <div class="col-auto"><label class="form-label mb-0">Period:</label></div>
            <div class="col-auto"><input type="date" name="from" class="form-control form-control-sm" value="<?= $from ?>"></div>
            <div class="col-auto">to</div>
            <div class="col-auto"><input type="date" name="to" class="form-control form-control-sm" value="<?= $to ?>"></div>
            <div class="col-auto"><button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-filter"></i> Apply</button></div>
            <div class="col-auto">
                <a href="?from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-d') ?>" class="btn btn-outline-secondary btn-sm">This Month</a>
                <a href="?from=<?= date('Y-01-01') ?>&to=<?= date('Y-m-d') ?>" class="btn btn-outline-secondary btn-sm">YTD</a>
            </div>
        </form>
    </div>
</div>

<!-- KPIs -->
<div class="row mb-4">
    <div class="col-xl-3 col-md-6 mb-3"><div class="stat-card bg-gradient-primary"><div class="stat-value"><?= formatCurrency($invSummary['total']) ?></div><div class="stat-label">Total Invoiced</div></div></div>
    <div class="col-xl-3 col-md-6 mb-3"><div class="stat-card bg-gradient-success"><div class="stat-value"><?= formatCurrency($totalRevenue) ?></div><div class="stat-label">Total Collected</div></div></div>
    <div class="col-xl-3 col-md-6 mb-3"><div class="stat-card bg-gradient-warning"><div class="stat-value"><?= formatCurrency($outstanding) ?></div><div class="stat-label">Outstanding</div></div></div>
    <div class="col-xl-3 col-md-6 mb-3"><div class="stat-card bg-gradient-info"><div class="stat-value"><?= formatCurrency($avgInvoice) ?></div><div class="stat-label">Avg Invoice</div></div></div>
</div>

<!-- Charts -->
<div class="row mb-4">
    <div class="col-lg-8 mb-3">
        <div class="card"><div class="card-header">Monthly Revenue Trend</div>
            <div class="card-body"><canvas id="monthlyChart" height="100"></canvas></div>
        </div>
    </div>
    <div class="col-lg-4 mb-3">
        <div class="card"><div class="card-header">Payment Methods</div>
            <div class="card-body"><canvas id="methodChart" height="200"></canvas></div>
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-lg-4 mb-3">
        <div class="card"><div class="card-header">Revenue by Category</div>
            <div class="card-body"><canvas id="catChart" height="220"></canvas></div>
        </div>
    </div>
    <div class="col-lg-4 mb-3">
        <div class="card"><div class="card-header">Invoice Status</div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Status</th><th>Count</th><th>Amount</th></tr></thead>
                    <tbody>
                        <?php foreach ($statusData as $s): ?>
                        <tr>
                            <td><?= getStatusBadge($s['status']) ?></td>
                            <td><?= $s['count'] ?></td>
                            <td class="fw-bold"><?= formatCurrency($s['amount']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card mt-3">
            <div class="card-header">Insurance vs Self-Pay</div>
            <div class="card-body"><canvas id="insChart" height="150"></canvas></div>
        </div>
    </div>
    <div class="col-lg-4 mb-3">
        <div class="card"><div class="card-header">Payment Method Breakdown</div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Method</th><th>Txns</th><th>Amount</th></tr></thead>
                    <tbody>
                        <?php foreach ($methodData as $m): ?>
                        <tr>
                            <td><span class="badge bg-secondary"><?= ucfirst($m['payment_method']) ?></span></td>
                            <td><?= $m['count'] ?></td>
                            <td class="fw-bold"><?= formatCurrency($m['total']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Top Invoices Table -->
<div class="card">
    <div class="card-header"><i class="bi bi-trophy"></i> Top 10 Highest Invoices</div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead><tr><th>Invoice</th><th>Patient</th><th>Date</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th></tr></thead>
            <tbody>
                <?php foreach ($topInv as $inv): ?>
                <tr>
                    <td><a href="/hospitalman/modules/billing/view_invoice.php?id=<?= $inv['invoice_number'] ?>"><strong><?= sanitize($inv['invoice_number']) ?></strong></a></td>
                    <td><?= sanitize($inv['first_name'] . ' ' . $inv['last_name']) ?></td>
                    <td><?= formatDate($inv['invoice_date']) ?></td>
                    <td class="fw-bold"><?= formatCurrency($inv['total_amount']) ?></td>
                    <td class="text-success"><?= formatCurrency($inv['paid']) ?></td>
                    <td class="<?= ($inv['total_amount'] - $inv['paid']) > 0 ? 'text-danger' : '' ?>"><?= formatCurrency($inv['total_amount'] - $inv['paid']) ?></td>
                    <td><?= getStatusBadge($inv['status']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    new Chart(document.getElementById('monthlyChart'), {
        type: 'bar', data: {
            labels: <?= json_encode(array_column($monthly, 'label')) ?>,
            datasets: [{ label: 'Revenue', data: <?= json_encode(array_column($monthly, 'revenue')) ?>,
                backgroundColor: 'rgba(102,126,234,0.7)', borderRadius: 6 }]
        }, options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
    });

    new Chart(document.getElementById('methodChart'), {
        type: 'doughnut', data: {
            labels: <?= json_encode(array_map(fn($m) => ucfirst($m['payment_method']), $methodData)) ?>,
            datasets: [{ data: <?= json_encode(array_column($methodData, 'total')) ?>,
                backgroundColor: ['#667eea','#38ef7d','#f5576c','#4facfe','#ffd93d'] }]
        }, options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
    });

    new Chart(document.getElementById('catChart'), {
        type: 'bar', data: {
            labels: <?= json_encode(array_map(fn($c) => ucfirst($c['category']), $catData)) ?>,
            datasets: [{ label: 'Revenue', data: <?= json_encode(array_column($catData, 'total')) ?>,
                backgroundColor: ['#667eea','#38ef7d','#f5576c','#4facfe','#f093fb','#ffd93d'], borderRadius: 6 }]
        }, options: { responsive: true, indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true } } }
    });

    new Chart(document.getElementById('insChart'), {
        type: 'doughnut', data: {
            labels: ['Insurance', 'Self-Pay'],
            datasets: [{ data: [<?= $insRev ?>, <?= $selfPayRev ?>], backgroundColor: ['#4facfe','#38ef7d'] }]
        }, options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
