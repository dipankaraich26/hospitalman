<?php
// Initialize auth and functions before header to allow redirects
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requireRole(['admin', 'receptionist']);

$pdo = getDBConnection();

// Handle POST before header include to avoid "headers already sent" error
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'add_payment') {
    $invoice_id = (int) $_POST['invoice_id'];
    $amount = (float) $_POST['amount'];

    $stmt = $pdo->prepare("INSERT INTO payments (invoice_id, payment_date, amount, payment_method, reference_number, received_by, notes) VALUES (?,?,?,?,?,?,?)");
    $stmt->execute([
        $invoice_id, $_POST['payment_date'], $amount,
        $_POST['payment_method'], trim($_POST['reference_number']) ?: null,
        $_SESSION['user_id'], trim($_POST['payment_notes'])
    ]);

    // Update invoice status
    $totalPaid = $pdo->prepare("SELECT COALESCE(SUM(amount),0) as total FROM payments WHERE invoice_id = ?");
    $totalPaid->execute([$invoice_id]);
    $paid = $totalPaid->fetch()['total'];

    $invTotal = $pdo->prepare("SELECT total_amount FROM invoices WHERE id = ?");
    $invTotal->execute([$invoice_id]);
    $total = $invTotal->fetch()['total_amount'];

    $status = 'unpaid';
    if ($paid >= $total) $status = 'paid';
    elseif ($paid > 0) $status = 'partial';

    $pdo->prepare("UPDATE invoices SET status = ? WHERE id = ?")->execute([$status, $invoice_id]);
    auditLog('create', 'billing', 'payments', null, null, ['invoice_id' => $invoice_id, 'amount' => $amount, 'method' => $_POST['payment_method']]);

    setFlashMessage('success', 'Payment recorded successfully.');
    header('Location: view_invoice.php?id=' . $invoice_id);
    exit;
}

$invoice_id = (int) ($_GET['invoice_id'] ?? 0);

// If specific invoice selected, show payment form
if ($invoice_id) {
    $inv = $pdo->prepare("
        SELECT i.*, p.first_name, p.last_name, p.patient_id as pid,
               (SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id = i.id) as paid_amount
        FROM invoices i JOIN patients p ON i.patient_id = p.id WHERE i.id = ?
    ");
    $inv->execute([$invoice_id]);
    $inv = $inv->fetch();
}

// All payments list
$allPayments = $pdo->query("
    SELECT py.*, i.invoice_number, p.first_name, p.last_name, u.full_name as receiver
    FROM payments py
    JOIN invoices i ON py.invoice_id = i.id
    JOIN patients p ON i.patient_id = p.id
    JOIN users u ON py.received_by = u.id
    ORDER BY py.created_at DESC
")->fetchAll();

// Now include header for HTML output
$pageTitle = 'Payments';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h4><i class="bi bi-cash-stack"></i> Payments</h4>
</div>

<?php if (isset($inv) && $inv): ?>
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-plus-circle"></i> Record Payment - <?= sanitize($inv['invoice_number']) ?></div>
    <div class="card-body">
        <div class="alert alert-info">
            <strong>Patient:</strong> <?= sanitize($inv['first_name'] . ' ' . $inv['last_name']) ?> (<?= sanitize($inv['pid']) ?>) |
            <strong>Invoice Total:</strong> <?= formatCurrency($inv['total_amount']) ?> |
            <strong>Paid:</strong> <?= formatCurrency($inv['paid_amount']) ?> |
            <strong>Balance:</strong> <span class="text-danger fw-bold"><?= formatCurrency($inv['total_amount'] - $inv['paid_amount']) ?></span>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add_payment">
            <input type="hidden" name="invoice_id" value="<?= $invoice_id ?>">
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label class="form-label">Amount *</label>
                    <input type="number" name="amount" class="form-control" step="0.01" min="0.01" max="<?= $inv['total_amount'] - $inv['paid_amount'] ?>" value="<?= $inv['total_amount'] - $inv['paid_amount'] ?>" required>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Payment Date *</label>
                    <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Method *</label>
                    <select name="payment_method" class="form-select" required>
                        <option value="cash">Cash</option>
                        <option value="card">Card</option>
                        <option value="insurance">Insurance</option>
                        <option value="bank_transfer">Bank Transfer</option>
                        <option value="online">Online</option>
                    </select>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Reference #</label>
                    <input type="text" name="reference_number" class="form-control" placeholder="Transaction ID">
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">Notes</label>
                <input type="text" name="payment_notes" class="form-control">
            </div>
            <button type="submit" class="btn btn-success"><i class="bi bi-check-lg"></i> Record Payment</button>
            <a href="index.php" class="btn btn-light ms-2">Cancel</a>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">All Payments</div>
    <div class="card-body">
        <table class="table table-hover data-table">
            <thead>
                <tr><th>Date</th><th>Invoice</th><th>Patient</th><th>Amount</th><th>Method</th><th>Reference</th><th>Received By</th></tr>
            </thead>
            <tbody>
                <?php foreach ($allPayments as $pay): ?>
                <tr>
                    <td><?= formatDate($pay['payment_date']) ?></td>
                    <td><a href="view_invoice.php?id=<?= $pay['invoice_id'] ?>"><?= sanitize($pay['invoice_number']) ?></a></td>
                    <td><?= sanitize($pay['first_name'] . ' ' . $pay['last_name']) ?></td>
                    <td class="text-success fw-bold"><?= formatCurrency($pay['amount']) ?></td>
                    <td><span class="badge bg-secondary"><?= ucfirst($pay['payment_method']) ?></span></td>
                    <td><?= sanitize($pay['reference_number'] ?? '-') ?></td>
                    <td><?= sanitize($pay['receiver']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
