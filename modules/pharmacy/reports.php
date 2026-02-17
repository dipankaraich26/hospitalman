<?php
$pageTitle = 'Pharmacy Reports';
require_once __DIR__ . '/../../includes/header.php';
requireRole(['admin', 'pharmacist']);

$pdo = getDBConnection();

// Stock Summary
$totalMedicines = countRows('medicines');
$totalStock = $pdo->query("SELECT COALESCE(SUM(quantity_in_stock),0) as total FROM medicines")->fetch()['total'];
$stockValue = $pdo->query("SELECT COALESCE(SUM(quantity_in_stock * unit_price),0) as total FROM medicines")->fetch()['total'];
$sellValue = $pdo->query("SELECT COALESCE(SUM(quantity_in_stock * selling_price),0) as total FROM medicines")->fetch()['total'];
$lowStockCount = countRows('medicines', 'quantity_in_stock <= reorder_level');
$expiredCount = countRows('medicines', 'expiry_date < CURDATE()');
$expiringCount = countRows('medicines', "expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)");

// Stock by category
$byCategory = $pdo->query("
    SELECT category, COUNT(*) as count, SUM(quantity_in_stock) as stock,
           SUM(quantity_in_stock * selling_price) as value
    FROM medicines
    GROUP BY category
    ORDER BY value DESC
")->fetchAll();

// Low stock items
$lowStock = $pdo->query("
    SELECT name, batch_number, quantity_in_stock, reorder_level, selling_price
    FROM medicines
    WHERE quantity_in_stock <= reorder_level
    ORDER BY quantity_in_stock ASC
")->fetchAll();

// Expiring medicines
$expiring = $pdo->query("
    SELECT name, batch_number, expiry_date, quantity_in_stock
    FROM medicines
    WHERE expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)
    ORDER BY expiry_date ASC
")->fetchAll();

// Category chart data
$catLabels = array_column($byCategory, 'category');
$catValues = array_column($byCategory, 'value');
?>

<div class="page-header">
    <h4><i class="bi bi-graph-up"></i> Pharmacy Reports</h4>
</div>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-xl-2 col-md-4 mb-3">
        <div class="stat-card bg-gradient-primary">
            <div class="stat-value"><?= $totalMedicines ?></div>
            <div class="stat-label">Total Items</div>
        </div>
    </div>
    <div class="col-xl-2 col-md-4 mb-3">
        <div class="stat-card bg-gradient-success">
            <div class="stat-value"><?= number_format($totalStock) ?></div>
            <div class="stat-label">Total Stock</div>
        </div>
    </div>
    <div class="col-xl-2 col-md-4 mb-3">
        <div class="stat-card bg-gradient-info">
            <div class="stat-value"><?= formatCurrency($stockValue) ?></div>
            <div class="stat-label">Cost Value</div>
        </div>
    </div>
    <div class="col-xl-2 col-md-4 mb-3">
        <div class="stat-card bg-gradient-info">
            <div class="stat-value"><?= formatCurrency($sellValue) ?></div>
            <div class="stat-label">Retail Value</div>
        </div>
    </div>
    <div class="col-xl-2 col-md-4 mb-3">
        <div class="stat-card bg-gradient-warning">
            <div class="stat-value"><?= $lowStockCount ?></div>
            <div class="stat-label">Low Stock</div>
        </div>
    </div>
    <div class="col-xl-2 col-md-4 mb-3">
        <div class="stat-card bg-gradient-warning">
            <div class="stat-value"><?= $expiredCount + $expiringCount ?></div>
            <div class="stat-label">Expired/Expiring</div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Category breakdown -->
    <div class="col-lg-6 mb-3">
        <div class="card">
            <div class="card-header">Stock Value by Category</div>
            <div class="card-body">
                <canvas id="categoryChart" height="200"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-6 mb-3">
        <div class="card">
            <div class="card-header">Category Breakdown</div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Category</th><th>Items</th><th>Stock</th><th>Value</th></tr></thead>
                    <tbody>
                        <?php foreach ($byCategory as $cat): ?>
                        <tr>
                            <td><?= sanitize($cat['category'] ?? 'Uncategorized') ?></td>
                            <td><?= $cat['count'] ?></td>
                            <td><?= number_format($cat['stock']) ?></td>
                            <td class="fw-bold"><?= formatCurrency($cat['value']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Low Stock -->
    <div class="col-lg-6 mb-3">
        <div class="card border-warning">
            <div class="card-header text-warning"><i class="bi bi-exclamation-triangle"></i> Low Stock Alert (<?= count($lowStock) ?>)</div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Medicine</th><th>Stock</th><th>Reorder</th><th>Needed</th></tr></thead>
                    <tbody>
                        <?php foreach ($lowStock as $ls): ?>
                        <tr>
                            <td><?= sanitize($ls['name']) ?></td>
                            <td class="text-danger fw-bold"><?= $ls['quantity_in_stock'] ?></td>
                            <td><?= $ls['reorder_level'] ?></td>
                            <td class="fw-bold"><?= max(0, $ls['reorder_level'] - $ls['quantity_in_stock']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Expiring -->
    <div class="col-lg-6 mb-3">
        <div class="card border-danger">
            <div class="card-header text-danger"><i class="bi bi-calendar-x"></i> Expiring Within 90 Days (<?= count($expiring) ?>)</div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Medicine</th><th>Batch</th><th>Expiry</th><th>Stock</th></tr></thead>
                    <tbody>
                        <?php if (empty($expiring)): ?>
                        <tr><td colspan="4" class="text-center text-muted py-3">No medicines expiring soon</td></tr>
                        <?php else: foreach ($expiring as $exp): ?>
                        <tr>
                            <td><?= sanitize($exp['name']) ?></td>
                            <td><?= sanitize($exp['batch_number'] ?? '-') ?></td>
                            <td class="text-danger"><?= formatDate($exp['expiry_date']) ?></td>
                            <td><?= $exp['quantity_in_stock'] ?></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    new Chart(document.getElementById('categoryChart'), {
        type: 'bar',
        data: {
            labels: <?= json_encode($catLabels) ?>,
            datasets: [{
                label: 'Stock Value ($)',
                data: <?= json_encode($catValues) ?>,
                backgroundColor: ['#667eea','#38ef7d','#f5576c','#4facfe','#f093fb','#ffd93d','#11998e','#764ba2','#ff6b6b','#48dbfb','#0abde3','#10ac84'],
                borderRadius: 6
            }]
        },
        options: {
            responsive: true,
            indexAxis: 'y',
            plugins: { legend: { display: false } },
            scales: { x: { beginAtZero: true } }
        }
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
