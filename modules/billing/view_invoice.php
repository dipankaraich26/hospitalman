<?php
$pageTitle = 'View Invoice';
require_once __DIR__ . '/../../includes/header.php';
requireRole(['admin', 'receptionist']);

$pdo = getDBConnection();
$id = (int) ($_GET['id'] ?? 0);

$invoice = $pdo->prepare("
    SELECT i.*, p.first_name, p.last_name, p.patient_id as pid, p.phone, p.email, p.address, p.insurance_provider, p.insurance_id,
           u.full_name as created_by_name
    FROM invoices i
    JOIN patients p ON i.patient_id = p.id
    JOIN users u ON i.created_by = u.id
    WHERE i.id = ?
");
$invoice->execute([$id]);
$invoice = $invoice->fetch();

if (!$invoice) {
    setFlashMessage('error', 'Invoice not found.');
    header('Location: index.php');
    exit;
}

$items = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id");
$items->execute([$id]);
$items = $items->fetchAll();

$payments = $pdo->prepare("SELECT py.*, u.full_name as receiver FROM payments py JOIN users u ON py.received_by = u.id WHERE py.invoice_id = ? ORDER BY py.payment_date");
$payments->execute([$id]);
$payments = $payments->fetchAll();

$totalPaid = array_sum(array_column($payments, 'amount'));
$balance = $invoice['total_amount'] - $totalPaid;
?>

<div class="page-header no-print">
    <h4><i class="bi bi-receipt"></i> Invoice <?= sanitize($invoice['invoice_number']) ?></h4>
    <div>
        <a href="export_invoice_pdf.php?id=<?= $id ?>" class="btn btn-outline-success"><i class="bi bi-file-earmark-pdf"></i> PDF</a>
        <button onclick="window.print()" class="btn btn-outline-primary"><i class="bi bi-printer"></i> Print</button>
        <?php if ($invoice['status'] !== 'paid'): ?>
        <a href="payments.php?invoice_id=<?= $id ?>" class="btn btn-success"><i class="bi bi-cash"></i> Record Payment</a>
        <?php endif; ?>
        <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back</a>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <!-- Invoice Header -->
        <div class="row mb-4">
            <div class="col-md-6">
                <h3 class="text-primary"><i class="bi bi-hospital"></i> Hospital ERP</h3>
                <p class="text-muted mb-0">123 Medical Center Drive<br>Springfield, ST 12345<br>Tel: (555) 000-0000</p>
            </div>
            <div class="col-md-6 text-md-end">
                <h4>INVOICE</h4>
                <p>
                    <strong><?= sanitize($invoice['invoice_number']) ?></strong><br>
                    Date: <?= formatDate($invoice['invoice_date']) ?><br>
                    Status: <?= getStatusBadge($invoice['status']) ?>
                </p>
            </div>
        </div>

        <hr>

        <!-- Bill To -->
        <div class="row mb-4">
            <div class="col-md-6">
                <h6 class="text-muted">Bill To:</h6>
                <strong><?= sanitize($invoice['first_name'] . ' ' . $invoice['last_name']) ?></strong> (<?= sanitize($invoice['pid']) ?>)<br>
                <?php if ($invoice['phone']): ?><?= sanitize($invoice['phone']) ?><br><?php endif; ?>
                <?php if ($invoice['email']): ?><?= sanitize($invoice['email']) ?><br><?php endif; ?>
                <?php if ($invoice['address']): ?><?= sanitize($invoice['address']) ?><br><?php endif; ?>
                <?php if ($invoice['insurance_provider']): ?>
                <span class="badge bg-info mt-1">Insurance: <?= sanitize($invoice['insurance_provider']) ?> (<?= sanitize($invoice['insurance_id']) ?>)</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Items Table -->
        <table class="table table-bordered">
            <thead class="table-light">
                <tr><th>#</th><th>Description</th><th>Category</th><th class="text-center">Qty</th><th class="text-end">Unit Price</th><th class="text-end">Total</th></tr>
            </thead>
            <tbody>
                <?php foreach ($items as $i => $item): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><?= sanitize($item['description']) ?></td>
                    <td><span class="badge bg-secondary"><?= ucfirst($item['category']) ?></span></td>
                    <td class="text-center"><?= $item['quantity'] ?></td>
                    <td class="text-end"><?= formatCurrency($item['unit_price']) ?></td>
                    <td class="text-end"><?= formatCurrency($item['total_price']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr><td colspan="5" class="text-end">Subtotal:</td><td class="text-end"><?= formatCurrency($invoice['subtotal']) ?></td></tr>
                <?php if ($invoice['discount_amount'] > 0): ?>
                <tr><td colspan="5" class="text-end">Discount (<?= $invoice['discount_percent'] ?>%):</td><td class="text-end text-danger">-<?= formatCurrency($invoice['discount_amount']) ?></td></tr>
                <?php endif; ?>
                <?php if ($invoice['tax_amount'] > 0): ?>
                <tr><td colspan="5" class="text-end">Tax:</td><td class="text-end"><?= formatCurrency($invoice['tax_amount']) ?></td></tr>
                <?php endif; ?>
                <tr class="table-primary"><td colspan="5" class="text-end fw-bold">Total:</td><td class="text-end fw-bold fs-5"><?= formatCurrency($invoice['total_amount']) ?></td></tr>
                <tr><td colspan="5" class="text-end">Paid:</td><td class="text-end text-success"><?= formatCurrency($totalPaid) ?></td></tr>
                <tr><td colspan="5" class="text-end fw-bold">Balance Due:</td><td class="text-end fw-bold <?= $balance > 0 ? 'text-danger' : 'text-success' ?>"><?= formatCurrency($balance) ?></td></tr>
            </tfoot>
        </table>

        <?php if ($invoice['notes']): ?>
        <p><strong>Notes:</strong> <?= sanitize($invoice['notes']) ?></p>
        <?php endif; ?>

        <!-- Payment History -->
        <?php if (!empty($payments)): ?>
        <hr>
        <h6>Payment History</h6>
        <table class="table table-sm">
            <thead><tr><th>Date</th><th>Amount</th><th>Method</th><th>Reference</th><th>Received By</th></tr></thead>
            <tbody>
                <?php foreach ($payments as $pay): ?>
                <tr>
                    <td><?= formatDate($pay['payment_date']) ?></td>
                    <td class="text-success fw-bold"><?= formatCurrency($pay['amount']) ?></td>
                    <td><span class="badge bg-secondary"><?= ucfirst($pay['payment_method']) ?></span></td>
                    <td><?= sanitize($pay['reference_number'] ?? '-') ?></td>
                    <td><?= sanitize($pay['receiver']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <div class="text-center text-muted mt-4">
            <small>Created by: <?= sanitize($invoice['created_by_name']) ?> on <?= formatDateTime($invoice['created_at']) ?></small>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
