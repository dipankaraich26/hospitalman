<?php
$pageTitle = 'Medicine Inventory';
require_once __DIR__ . '/../../includes/header.php';
requireRole(['admin', 'pharmacist']);

$pdo = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'delete') {
    $delId = (int)$_POST['id'];
    auditLog('delete', 'pharmacy', 'medicines', $delId);
    $pdo->prepare("DELETE FROM medicines WHERE id = ?")->execute([$delId]);
    setFlashMessage('success', 'Medicine deleted.');
    header('Location: index.php');
    exit;
}

$filter = $_GET['filter'] ?? 'all';
$where = "";
if ($filter === 'low_stock') $where = "WHERE quantity_in_stock <= reorder_level";
elseif ($filter === 'expiring') $where = "WHERE expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY) AND expiry_date >= CURDATE()";
elseif ($filter === 'expired') $where = "WHERE expiry_date < CURDATE()";

$medicines = $pdo->query("SELECT * FROM medicines $where ORDER BY name")->fetchAll();
?>

<div class="page-header">
    <h4><i class="bi bi-capsule"></i> Medicine Inventory</h4>
    <div>
        <a href="import.php" class="btn btn-outline-success"><i class="bi bi-upload"></i> Import CSV</a>
        <a href="add_medicine.php" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Add Medicine</a>
    </div>
</div>

<ul class="nav nav-pills mb-3">
    <li class="nav-item"><a class="nav-link <?= $filter === 'all' ? 'active' : '' ?>" href="?filter=all">All (<?= countRows('medicines') ?>)</a></li>
    <li class="nav-item"><a class="nav-link <?= $filter === 'low_stock' ? 'active' : '' ?> text-warning" href="?filter=low_stock">Low Stock</a></li>
    <li class="nav-item"><a class="nav-link <?= $filter === 'expiring' ? 'active' : '' ?> text-danger" href="?filter=expiring">Expiring Soon</a></li>
    <li class="nav-item"><a class="nav-link <?= $filter === 'expired' ? 'active' : '' ?>" href="?filter=expired">Expired</a></li>
</ul>

<div class="card">
    <div class="card-body">
        <table class="table table-hover data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Category</th>
                    <th>Batch</th>
                    <th>Stock</th>
                    <th>Buy Price</th>
                    <th>Sell Price</th>
                    <th>Expiry</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($medicines as $m): ?>
                <?php
                $isLow = $m['quantity_in_stock'] <= $m['reorder_level'];
                $isExpired = $m['expiry_date'] && strtotime($m['expiry_date']) < time();
                $isExpiring = $m['expiry_date'] && !$isExpired && strtotime($m['expiry_date']) <= strtotime('+90 days');
                ?>
                <tr>
                    <td>
                        <strong><?= sanitize($m['name']) ?></strong>
                        <?php if ($m['generic_name']): ?><br><small class="text-muted"><?= sanitize($m['generic_name']) ?></small><?php endif; ?>
                    </td>
                    <td><?= sanitize($m['category'] ?? '-') ?></td>
                    <td><?= sanitize($m['batch_number'] ?? '-') ?></td>
                    <td>
                        <span class="<?= $isLow ? 'text-danger fw-bold' : '' ?>"><?= $m['quantity_in_stock'] ?></span>
                        <?php if ($isLow): ?><br><small class="text-danger">Reorder: <?= $m['reorder_level'] ?></small><?php endif; ?>
                    </td>
                    <td><?= formatCurrency($m['unit_price']) ?></td>
                    <td><?= formatCurrency($m['selling_price']) ?></td>
                    <td class="<?= $isExpired ? 'text-danger' : ($isExpiring ? 'text-warning' : '') ?>">
                        <?= $m['expiry_date'] ? formatDate($m['expiry_date']) : 'N/A' ?>
                    </td>
                    <td>
                        <?php if ($isExpired): ?><span class="badge bg-danger">Expired</span>
                        <?php elseif ($isExpiring): ?><span class="badge bg-warning">Expiring</span>
                        <?php elseif ($isLow): ?><span class="badge bg-warning">Low Stock</span>
                        <?php else: ?><span class="badge bg-success">OK</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="edit_medicine.php?id=<?= $m['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                        <form method="POST" class="d-inline" onsubmit="return confirmDelete('Delete this medicine?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $m['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
