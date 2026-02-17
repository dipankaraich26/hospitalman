<?php
$pageTitle = 'Invoices';
require_once __DIR__ . '/../../includes/header.php';
requireRole(['admin', 'receptionist']);

$pdo = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'delete') {
    $pdo->prepare("DELETE FROM invoices WHERE id = ?")->execute([(int)$_POST['id']]);
    setFlashMessage('success', 'Invoice deleted.');
    header('Location: index.php');
    exit;
}

$filter = $_GET['status'] ?? 'all';
$where = $filter !== 'all' ? "WHERE i.status = " . $pdo->quote($filter) : "";

$invoices = $pdo->query("
    SELECT i.*, p.first_name, p.last_name, p.patient_id as pid,
           (SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id = i.id) as paid_amount
    FROM invoices i
    JOIN patients p ON i.patient_id = p.id
    $where
    ORDER BY i.created_at DESC
")->fetchAll();
?>

<div class="page-header">
    <h4><i class="bi bi-receipt"></i> Invoices</h4>
    <a href="create_invoice.php" class="btn btn-primary"><i class="bi bi-plus-lg"></i> New Invoice</a>
</div>

<ul class="nav nav-pills mb-3">
    <li class="nav-item"><a class="nav-link <?= $filter === 'all' ? 'active' : '' ?>" href="?status=all">All</a></li>
    <li class="nav-item"><a class="nav-link <?= $filter === 'unpaid' ? 'active' : '' ?>" href="?status=unpaid">Unpaid</a></li>
    <li class="nav-item"><a class="nav-link <?= $filter === 'partial' ? 'active' : '' ?>" href="?status=partial">Partial</a></li>
    <li class="nav-item"><a class="nav-link <?= $filter === 'paid' ? 'active' : '' ?>" href="?status=paid">Paid</a></li>
</ul>

<div class="card">
    <div class="card-body">
        <table class="table table-hover data-table">
            <thead>
                <tr><th>Invoice #</th><th>Patient</th><th>Date</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($invoices as $inv): ?>
                <?php $balance = $inv['total_amount'] - $inv['paid_amount']; ?>
                <tr>
                    <td><strong><?= sanitize($inv['invoice_number']) ?></strong></td>
                    <td><?= sanitize($inv['first_name'] . ' ' . $inv['last_name']) ?><br><small class="text-muted"><?= sanitize($inv['pid']) ?></small></td>
                    <td><?= formatDate($inv['invoice_date']) ?></td>
                    <td><?= formatCurrency($inv['total_amount']) ?></td>
                    <td class="text-success"><?= formatCurrency($inv['paid_amount']) ?></td>
                    <td class="<?= $balance > 0 ? 'text-danger' : '' ?>"><?= formatCurrency($balance) ?></td>
                    <td><?= getStatusBadge($inv['status']) ?></td>
                    <td>
                        <a href="view_invoice.php?id=<?= $inv['id'] ?>" class="btn btn-sm btn-outline-info"><i class="bi bi-eye"></i></a>
                        <?php if ($inv['status'] !== 'paid'): ?>
                        <a href="payments.php?invoice_id=<?= $inv['id'] ?>" class="btn btn-sm btn-outline-success"><i class="bi bi-cash"></i></a>
                        <?php endif; ?>
                        <form method="POST" class="d-inline" onsubmit="return confirmDelete('Delete this invoice?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $inv['id'] ?>">
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
