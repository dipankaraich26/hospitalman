<?php
$pageTitle = 'Purchases';
require_once __DIR__ . '/../../includes/header.php';
requireRole(['admin', 'pharmacist']);

$pdo = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $medicine_id = (int) $_POST['medicine_id'];
        $quantity = (int) $_POST['quantity'];

        $stmt = $pdo->prepare("INSERT INTO medicine_purchases (medicine_id, supplier, quantity, purchase_price, purchase_date, invoice_number, created_by) VALUES (?,?,?,?,?,?,?)");
        $stmt->execute([
            $medicine_id, trim($_POST['supplier']), $quantity,
            (float) $_POST['purchase_price'], $_POST['purchase_date'],
            trim($_POST['invoice_number']), $_SESSION['user_id']
        ]);

        // Update stock
        $pdo->prepare("UPDATE medicines SET quantity_in_stock = quantity_in_stock + ? WHERE id = ?")
             ->execute([$quantity, $medicine_id]);

        auditLog('create', 'pharmacy', 'medicine_purchases', (int)$pdo->lastInsertId(), null, ['medicine_id' => $medicine_id, 'quantity' => $quantity, 'price' => $_POST['purchase_price']]);
        setFlashMessage('success', 'Purchase recorded and stock updated.');
        header('Location: purchase.php');
        exit;
    }

    if ($action === 'delete') {
        $delId = (int)$_POST['id'];
        auditLog('delete', 'pharmacy', 'medicine_purchases', $delId);
        $pdo->prepare("DELETE FROM medicine_purchases WHERE id = ?")->execute([$delId]);
        setFlashMessage('success', 'Purchase record deleted.');
        header('Location: purchase.php');
        exit;
    }
}

$purchases = $pdo->query("
    SELECT mp.*, m.name as med_name, u.full_name as created_by_name
    FROM medicine_purchases mp
    JOIN medicines m ON mp.medicine_id = m.id
    JOIN users u ON mp.created_by = u.id
    ORDER BY mp.purchase_date DESC
")->fetchAll();

$medicines = $pdo->query("SELECT id, name FROM medicines ORDER BY name")->fetchAll();
?>

<div class="page-header">
    <h4><i class="bi bi-truck"></i> Purchase Orders</h4>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addPurchaseModal"><i class="bi bi-plus-lg"></i> New Purchase</button>
</div>

<div class="card">
    <div class="card-body">
        <table class="table table-hover data-table">
            <thead>
                <tr><th>Date</th><th>Medicine</th><th>Supplier</th><th>Qty</th><th>Price</th><th>Invoice #</th><th>By</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($purchases as $p): ?>
                <tr>
                    <td><?= formatDate($p['purchase_date']) ?></td>
                    <td><strong><?= sanitize($p['med_name']) ?></strong></td>
                    <td><?= sanitize($p['supplier'] ?? '-') ?></td>
                    <td><?= $p['quantity'] ?></td>
                    <td><?= formatCurrency($p['purchase_price']) ?></td>
                    <td><?= sanitize($p['invoice_number'] ?? '-') ?></td>
                    <td><?= sanitize($p['created_by_name']) ?></td>
                    <td>
                        <form method="POST" class="d-inline" onsubmit="return confirmDelete('Delete this purchase record?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $p['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add Purchase Modal -->
<div class="modal fade" id="addPurchaseModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="add">
            <div class="modal-header"><h5 class="modal-title">Record Purchase</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Medicine *</label>
                    <select name="medicine_id" class="form-select" required>
                        <option value="">Select Medicine</option>
                        <?php foreach ($medicines as $m): ?>
                        <option value="<?= $m['id'] ?>"><?= sanitize($m['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Supplier</label>
                    <input type="text" name="supplier" class="form-control" placeholder="Supplier name">
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Quantity *</label>
                        <input type="number" name="quantity" class="form-control" min="1" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Total Purchase Price *</label>
                        <input type="number" name="purchase_price" class="form-control" step="0.01" min="0" required>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Purchase Date *</label>
                        <input type="date" name="purchase_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Invoice Number</label>
                        <input type="text" name="invoice_number" class="form-control">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Record Purchase</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
