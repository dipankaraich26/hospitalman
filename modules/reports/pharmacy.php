<?php
$pageTitle = 'Pharmacy Reports';
require_once __DIR__ . '/../../includes/header.php';
requireRole(['admin']);

$pdo = getDBConnection();
$from = $_GET['from'] ?? date('Y-01-01');
$to = $_GET['to'] ?? date('Y-m-d');

// KPIs
$totalMeds = countRows('medicines');
$totalStock = (int) $pdo->query("SELECT COALESCE(SUM(quantity_in_stock),0) as t FROM medicines")->fetch()['t'];
$costValue = (float) $pdo->query("SELECT COALESCE(SUM(quantity_in_stock * unit_price),0) as t FROM medicines")->fetch()['t'];
$retailValue = (float) $pdo->query("SELECT COALESCE(SUM(quantity_in_stock * selling_price),0) as t FROM medicines")->fetch()['t'];
$potentialProfit = $retailValue - $costValue;
$lowStock = countRows('medicines', 'quantity_in_stock <= reorder_level');
$expired = countRows('medicines', 'expiry_date < CURDATE()');
$expiring90 = countRows('medicines', "expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)");

$purchaseTotal = $pdo->prepare("SELECT COALESCE(SUM(purchase_price),0) as t, COALESCE(SUM(quantity),0) as qty FROM medicine_purchases WHERE purchase_date BETWEEN ? AND ?");
$purchaseTotal->execute([$from, $to]);
$purchaseSum = $purchaseTotal->fetch();

$dispensedTotal = $pdo->prepare("SELECT COALESCE(SUM(md.quantity),0) as qty, COUNT(*) as txns FROM medicine_dispensing md WHERE md.dispensed_at BETWEEN ? AND ?");
$dispensedTotal->execute([$from, $to]);
$dispensedSum = $dispensedTotal->fetch();

// Stock by Category
$byCat = $pdo->query("
    SELECT category, COUNT(*) as items, SUM(quantity_in_stock) as stock,
           SUM(quantity_in_stock * unit_price) as cost_val,
           SUM(quantity_in_stock * selling_price) as retail_val
    FROM medicines GROUP BY category ORDER BY retail_val DESC
")->fetchAll();

// Top Dispensed Medicines
$topDispensed = $pdo->prepare("
    SELECT m.name, m.category, SUM(md.quantity) as total_dispensed, COUNT(md.id) as times
    FROM medicine_dispensing md
    JOIN medicines m ON md.medicine_id = m.id
    WHERE md.dispensed_at BETWEEN ? AND ?
    GROUP BY md.medicine_id ORDER BY total_dispensed DESC LIMIT 10
");
$topDispensed->execute([$from, $to]);
$topDisp = $topDispensed->fetchAll();

// Top Purchased Medicines
$topPurchased = $pdo->prepare("
    SELECT m.name, SUM(mp.quantity) as total_qty, SUM(mp.purchase_price) as total_cost
    FROM medicine_purchases mp
    JOIN medicines m ON mp.medicine_id = m.id
    WHERE mp.purchase_date BETWEEN ? AND ?
    GROUP BY mp.medicine_id ORDER BY total_cost DESC LIMIT 10
");
$topPurchased->execute([$from, $to]);
$topPurch = $topPurchased->fetchAll();

// Monthly Dispensing Trend
$monthlyDisp = $pdo->query("
    SELECT DATE_FORMAT(dispensed_at, '%b') as label, SUM(quantity) as qty
    FROM medicine_dispensing WHERE dispensed_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(dispensed_at, '%Y-%m') ORDER BY DATE_FORMAT(dispensed_at, '%Y-%m')
")->fetchAll();

// Monthly Purchase Trend
$monthlyPurch = $pdo->query("
    SELECT DATE_FORMAT(purchase_date, '%b') as label, SUM(purchase_price) as cost
    FROM medicine_purchases WHERE purchase_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(purchase_date, '%Y-%m') ORDER BY DATE_FORMAT(purchase_date, '%Y-%m')
")->fetchAll();

// Low Stock Items
$lowStockItems = $pdo->query("
    SELECT name, category, quantity_in_stock, reorder_level, selling_price,
           (reorder_level - quantity_in_stock) as needed
    FROM medicines WHERE quantity_in_stock <= reorder_level ORDER BY quantity_in_stock ASC LIMIT 15
")->fetchAll();

// Expiring Items
$expiringItems = $pdo->query("
    SELECT name, batch_number, expiry_date, quantity_in_stock,
           DATEDIFF(expiry_date, CURDATE()) as days_left
    FROM medicines WHERE expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 180 DAY)
    ORDER BY expiry_date ASC LIMIT 15
")->fetchAll();
?>

<div class="page-header">
    <h4><i class="bi bi-capsule"></i> Pharmacy Reports</h4>
    <div class="no-print">
        <a href="export.php?type=pharmacy" class="btn btn-outline-success"><i class="bi bi-download"></i> Export CSV</a>
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
        </form>
    </div>
</div>

<!-- KPIs Row 1 -->
<div class="row mb-3">
    <div class="col-xl-3 col-md-6 mb-3"><div class="stat-card bg-gradient-primary"><div class="stat-value"><?= $totalMeds ?></div><div class="stat-label">Total Medicines</div></div></div>
    <div class="col-xl-3 col-md-6 mb-3"><div class="stat-card bg-gradient-success"><div class="stat-value"><?= formatCurrency($retailValue) ?></div><div class="stat-label">Retail Stock Value</div></div></div>
    <div class="col-xl-3 col-md-6 mb-3"><div class="stat-card bg-gradient-info"><div class="stat-value"><?= formatCurrency($potentialProfit) ?></div><div class="stat-label">Potential Profit</div></div></div>
    <div class="col-xl-3 col-md-6 mb-3"><div class="stat-card bg-gradient-warning"><div class="stat-value"><?= $lowStock + $expired ?></div><div class="stat-label">Alerts (Low/Expired)</div></div></div>
</div>

<!-- KPIs Row 2 -->
<div class="row mb-4">
    <div class="col-md-3 mb-2"><div class="card text-center"><div class="card-body py-3"><h4 class="mb-0"><?= number_format($purchaseSum['qty']) ?></h4><small class="text-muted">Units Purchased</small></div></div></div>
    <div class="col-md-3 mb-2"><div class="card text-center"><div class="card-body py-3"><h4 class="mb-0"><?= formatCurrency($purchaseSum['t']) ?></h4><small class="text-muted">Purchase Spend</small></div></div></div>
    <div class="col-md-3 mb-2"><div class="card text-center"><div class="card-body py-3"><h4 class="mb-0"><?= number_format($dispensedSum['qty']) ?></h4><small class="text-muted">Units Dispensed</small></div></div></div>
    <div class="col-md-3 mb-2"><div class="card text-center"><div class="card-body py-3"><h4 class="mb-0"><?= $dispensedSum['txns'] ?></h4><small class="text-muted">Dispense Txns</small></div></div></div>
</div>

<!-- Charts -->
<div class="row mb-4">
    <div class="col-lg-8 mb-3">
        <div class="card"><div class="card-header">Dispensing & Purchase Trends (12 Months)</div>
            <div class="card-body"><canvas id="trendChart" height="100"></canvas></div>
        </div>
    </div>
    <div class="col-lg-4 mb-3">
        <div class="card"><div class="card-header">Stock Value by Category</div>
            <div class="card-body"><canvas id="catChart" height="200"></canvas></div>
        </div>
    </div>
</div>

<!-- Tables -->
<div class="row mb-4">
    <div class="col-lg-6 mb-3">
        <div class="card">
            <div class="card-header"><i class="bi bi-arrow-up-circle"></i> Top Dispensed Medicines</div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead><tr><th>#</th><th>Medicine</th><th>Category</th><th>Qty</th><th>Times</th></tr></thead>
                    <tbody>
                        <?php foreach ($topDisp as $i => $td): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><strong><?= sanitize($td['name']) ?></strong></td>
                            <td><small><?= sanitize($td['category'] ?? '-') ?></small></td>
                            <td><span class="badge bg-primary"><?= $td['total_dispensed'] ?></span></td>
                            <td><?= $td['times'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($topDisp)): ?><tr><td colspan="5" class="text-muted text-center py-3">No data</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-6 mb-3">
        <div class="card">
            <div class="card-header"><i class="bi bi-truck"></i> Top Purchases by Cost</div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead><tr><th>#</th><th>Medicine</th><th>Qty</th><th>Total Cost</th></tr></thead>
                    <tbody>
                        <?php foreach ($topPurch as $i => $tp): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><strong><?= sanitize($tp['name']) ?></strong></td>
                            <td><?= number_format($tp['total_qty']) ?></td>
                            <td class="fw-bold"><?= formatCurrency($tp['total_cost']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($topPurch)): ?><tr><td colspan="4" class="text-muted text-center py-3">No data</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Category Breakdown Table -->
<div class="card mb-4">
    <div class="card-header">Stock Summary by Category</div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead><tr><th>Category</th><th>Items</th><th>Units</th><th>Cost Value</th><th>Retail Value</th><th>Margin</th></tr></thead>
            <tbody>
                <?php foreach ($byCat as $cat): ?>
                <?php $margin = $cat['retail_val'] > 0 ? round((($cat['retail_val'] - $cat['cost_val']) / $cat['retail_val']) * 100, 1) : 0; ?>
                <tr>
                    <td><strong><?= sanitize($cat['category'] ?? 'Uncategorized') ?></strong></td>
                    <td><?= $cat['items'] ?></td>
                    <td><?= number_format($cat['stock']) ?></td>
                    <td><?= formatCurrency($cat['cost_val']) ?></td>
                    <td><?= formatCurrency($cat['retail_val']) ?></td>
                    <td>
                        <div class="progress" style="height:18px; min-width:60px;">
                            <div class="progress-bar bg-<?= $margin >= 50 ? 'success' : ($margin >= 30 ? 'warning' : 'danger') ?>" style="width:<?= min($margin, 100) ?>%"><?= $margin ?>%</div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Alerts -->
<div class="row">
    <div class="col-lg-6 mb-3">
        <div class="card border-warning">
            <div class="card-header text-warning"><i class="bi bi-exclamation-triangle"></i> Low Stock Alert (<?= count($lowStockItems) ?>)</div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Medicine</th><th>Stock</th><th>Reorder</th><th>Need</th></tr></thead>
                    <tbody>
                        <?php foreach ($lowStockItems as $ls): ?>
                        <tr>
                            <td><?= sanitize($ls['name']) ?></td>
                            <td class="text-danger fw-bold"><?= $ls['quantity_in_stock'] ?></td>
                            <td><?= $ls['reorder_level'] ?></td>
                            <td><span class="badge bg-danger"><?= max(0, $ls['needed']) ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($lowStockItems)): ?><tr><td colspan="4" class="text-muted text-center py-3">No low stock items</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-6 mb-3">
        <div class="card border-danger">
            <div class="card-header text-danger"><i class="bi bi-calendar-x"></i> Expiry Watchlist (<?= count($expiringItems) ?>)</div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Medicine</th><th>Batch</th><th>Expiry</th><th>Days Left</th><th>Stock</th></tr></thead>
                    <tbody>
                        <?php foreach ($expiringItems as $ei): ?>
                        <tr>
                            <td><?= sanitize($ei['name']) ?></td>
                            <td><small><?= sanitize($ei['batch_number'] ?? '-') ?></small></td>
                            <td class="text-danger"><?= formatDate($ei['expiry_date']) ?></td>
                            <td><span class="badge bg-<?= $ei['days_left'] <= 30 ? 'danger' : ($ei['days_left'] <= 90 ? 'warning' : 'secondary') ?>"><?= $ei['days_left'] ?>d</span></td>
                            <td><?= $ei['quantity_in_stock'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($expiringItems)): ?><tr><td colspan="5" class="text-muted text-center py-3">No expiring items</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    new Chart(document.getElementById('trendChart'), {
        type: 'bar', data: {
            labels: <?= json_encode(array_column($monthlyDisp, 'label')) ?>,
            datasets: [
                { label: 'Units Dispensed', data: <?= json_encode(array_column($monthlyDisp, 'qty')) ?>,
                    backgroundColor: 'rgba(102,126,234,0.7)', borderRadius: 6, yAxisID: 'y' },
                { label: 'Purchase Cost ($)', data: <?= json_encode(array_column($monthlyPurch, 'cost')) ?>,
                    type: 'line', borderColor: '#f5576c', backgroundColor: 'rgba(245,87,108,0.1)', fill: true, tension: 0.3, yAxisID: 'y1' }
            ]
        }, options: {
            responsive: true, interaction: { mode: 'index', intersect: false },
            scales: {
                y: { beginAtZero: true, position: 'left', title: { display: true, text: 'Units' } },
                y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'Cost ($)' } }
            }
        }
    });

    new Chart(document.getElementById('catChart'), {
        type: 'doughnut', data: {
            labels: <?= json_encode(array_column($byCat, 'category')) ?>,
            datasets: [{ data: <?= json_encode(array_column($byCat, 'retail_val')) ?>,
                backgroundColor: ['#667eea','#38ef7d','#f5576c','#4facfe','#f093fb','#ffd93d','#11998e','#764ba2','#ff6b6b','#48dbfb','#0abde3','#10ac84'] }]
        }, options: { responsive: true, plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } } } }
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
