<?php
// Initialize auth and functions before header to allow redirects
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requireRole(['admin', 'receptionist']);

$pdo = getDBConnection();

// Handle POST before header include to avoid "headers already sent" error
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo->beginTransaction();
    try {
        $invoice_number = generateInvoiceNumber();
        $patient_id = (int) $_POST['patient_id'];
        $subtotal = 0;

        // Calculate subtotal from items
        foreach ($_POST['description'] as $idx => $desc) {
            if (!empty($desc)) {
                $qty = (int) $_POST['item_qty'][$idx];
                $price = (float) $_POST['item_price'][$idx];
                $subtotal += $qty * $price;
            }
        }

        $discount_percent = (float) ($_POST['discount_percent'] ?? 0);
        $discount_amount = $subtotal * ($discount_percent / 100);
        $tax_percent = (float) ($_POST['tax_percent'] ?? 0);
        $tax_amount = ($subtotal - $discount_amount) * ($tax_percent / 100);
        $total_amount = $subtotal - $discount_amount + $tax_amount;
        $insurance_claim = isset($_POST['insurance_claim']) ? 1 : 0;

        $stmt = $pdo->prepare("INSERT INTO invoices (invoice_number, patient_id, invoice_date, subtotal, discount_percent, discount_amount, tax_amount, total_amount, status, insurance_claim, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $invoice_number, $patient_id, $_POST['invoice_date'],
            $subtotal, $discount_percent, $discount_amount, $tax_amount, $total_amount,
            'unpaid', $insurance_claim, trim($_POST['notes']), $_SESSION['user_id']
        ]);
        $invoice_id = $pdo->lastInsertId();

        // Insert items
        $istmt = $pdo->prepare("INSERT INTO invoice_items (invoice_id, description, category, quantity, unit_price, total_price) VALUES (?,?,?,?,?,?)");
        foreach ($_POST['description'] as $idx => $desc) {
            if (!empty($desc)) {
                $qty = (int) $_POST['item_qty'][$idx];
                $price = (float) $_POST['item_price'][$idx];
                $istmt->execute([
                    $invoice_id, trim($desc), $_POST['item_category'][$idx],
                    $qty, $price, $qty * $price
                ]);
            }
        }

        $pdo->commit();
        auditLog('create', 'billing', 'invoices', (int)$invoice_id, null, ['invoice_number' => $invoice_number, 'total_amount' => $total_amount, 'patient_id' => $patient_id]);
        setFlashMessage('success', "Invoice $invoice_number created successfully.");
        header('Location: view_invoice.php?id=' . $invoice_id);
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        setFlashMessage('error', 'Error creating invoice: ' . $e->getMessage());
    }
}

$patients = $pdo->query("SELECT id, patient_id, first_name, last_name FROM patients ORDER BY first_name")->fetchAll();

// Now include header for HTML output
$pageTitle = 'Create Invoice';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h4><i class="bi bi-receipt-cutoff"></i> Create Invoice</h4>
    <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<form method="POST" id="invoiceForm">
    <div class="row">
        <div class="col-lg-8">
            <!-- Invoice Items -->
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Invoice Items</span>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="addItemRow"><i class="bi bi-plus"></i> Add Item</button>
                </div>
                <div class="card-body">
                    <table class="table" id="itemsTable">
                        <thead>
                            <tr><th>Description</th><th>Category</th><th>Qty</th><th>Price</th><th>Total</th><th></th></tr>
                        </thead>
                        <tbody>
                            <tr class="item-row">
                                <td><input type="text" name="description[]" class="form-control form-control-sm" required placeholder="Service/item description"></td>
                                <td>
                                    <select name="item_category[]" class="form-select form-select-sm">
                                        <option value="consultation">Consultation</option>
                                        <option value="lab">Lab Test</option>
                                        <option value="medicine">Medicine</option>
                                        <option value="room">Room</option>
                                        <option value="procedure">Procedure</option>
                                        <option value="other">Other</option>
                                    </select>
                                </td>
                                <td><input type="number" name="item_qty[]" class="form-control form-control-sm item-qty" value="1" min="1" style="width:70px" required></td>
                                <td><input type="number" name="item_price[]" class="form-control form-control-sm item-price" step="0.01" min="0" value="0.00" style="width:100px" required></td>
                                <td class="item-total fw-bold">₹0.00</td>
                                <td><button type="button" class="btn btn-sm btn-outline-danger remove-item"><i class="bi bi-x"></i></button></td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr><td colspan="4" class="text-end fw-bold">Subtotal:</td><td id="subtotalDisplay" class="fw-bold">₹0.00</td><td></td></tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <!-- Invoice Details -->
            <div class="card mb-3">
                <div class="card-header">Invoice Details</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Patient *</label>
                        <select name="patient_id" class="form-select" required>
                            <option value="">Select Patient</option>
                            <?php foreach ($patients as $p): ?>
                            <option value="<?= $p['id'] ?>"><?= sanitize($p['patient_id'] . ' - ' . $p['first_name'] . ' ' . $p['last_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Date *</label>
                        <input type="date" name="invoice_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Discount (%)</label>
                        <input type="number" name="discount_percent" id="discountPercent" class="form-control" step="0.01" min="0" max="100" value="0">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Tax (%)</label>
                        <input type="number" name="tax_percent" id="taxPercent" class="form-control" step="0.01" min="0" value="0">
                    </div>
                    <div class="form-check mb-3">
                        <input type="checkbox" name="insurance_claim" class="form-check-input" id="insuranceClaim">
                        <label class="form-check-label" for="insuranceClaim">Insurance Claim</label>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
                    </div>

                    <hr>
                    <div class="d-flex justify-content-between mb-1"><span>Subtotal:</span><span id="sumSubtotal">₹0.00</span></div>
                    <div class="d-flex justify-content-between mb-1"><span>Discount:</span><span id="sumDiscount" class="text-danger">-₹0.00</span></div>
                    <div class="d-flex justify-content-between mb-1"><span>Tax:</span><span id="sumTax">₹0.00</span></div>
                    <hr>
                    <div class="d-flex justify-content-between"><strong>Total:</strong><strong id="sumTotal" class="text-primary fs-5">₹0.00</strong></div>

                    <button type="submit" class="btn btn-primary w-100 mt-3"><i class="bi bi-check-lg"></i> Create Invoice</button>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
// Format number in Indian Rupee format with Indian numbering system
function formatINR(amount) {
    var isNegative = amount < 0;
    amount = Math.abs(amount);

    var rupees = Math.floor(amount);
    var paise = Math.round((amount - rupees) * 100);

    // Format according to Indian numbering system
    var rupeesStr = rupees.toString();
    var lastThree = rupeesStr.substring(rupeesStr.length - 3);
    var otherNumbers = rupeesStr.substring(0, rupeesStr.length - 3);

    if (otherNumbers !== '') {
        lastThree = ',' + lastThree;
    }

    var formattedAmount = otherNumbers.replace(/\B(?=(\d{2})+(?!\d))/g, ',') + lastThree;

    // Add paise
    formattedAmount += '.' + paise.toString().padStart(2, '0');

    return (isNegative ? '-' : '') + '₹' + formattedAmount;
}

function recalculate() {
    var subtotal = 0;
    document.querySelectorAll('.item-row').forEach(function(row) {
        var qty = parseFloat(row.querySelector('.item-qty').value) || 0;
        var price = parseFloat(row.querySelector('.item-price').value) || 0;
        var total = qty * price;
        row.querySelector('.item-total').textContent = formatINR(total);
        subtotal += total;
    });
    var discountPct = parseFloat(document.getElementById('discountPercent').value) || 0;
    var taxPct = parseFloat(document.getElementById('taxPercent').value) || 0;
    var discount = subtotal * (discountPct / 100);
    var tax = (subtotal - discount) * (taxPct / 100);
    var grandTotal = subtotal - discount + tax;

    document.getElementById('subtotalDisplay').textContent = formatINR(subtotal);
    document.getElementById('sumSubtotal').textContent = formatINR(subtotal);
    document.getElementById('sumDiscount').textContent = '-' + formatINR(discount);
    document.getElementById('sumTax').textContent = formatINR(tax);
    document.getElementById('sumTotal').textContent = formatINR(grandTotal);
}

document.getElementById('addItemRow').addEventListener('click', function() {
    var row = document.querySelector('.item-row').cloneNode(true);
    row.querySelectorAll('input[type="text"]').forEach(function(i) { i.value = ''; });
    row.querySelector('.item-qty').value = '1';
    row.querySelector('.item-price').value = '0.00';
    row.querySelector('.item-total').textContent = '₹0.00';
    document.querySelector('#itemsTable tbody').appendChild(row);
    bindEvents();
});

function bindEvents() {
    document.querySelectorAll('.item-qty, .item-price').forEach(function(input) {
        input.removeEventListener('input', recalculate);
        input.addEventListener('input', recalculate);
    });
    document.querySelectorAll('.remove-item').forEach(function(btn) {
        btn.onclick = function() {
            if (document.querySelectorAll('.item-row').length > 1) {
                btn.closest('.item-row').remove();
                recalculate();
            }
        };
    });
}

document.getElementById('discountPercent').addEventListener('input', recalculate);
document.getElementById('taxPercent').addEventListener('input', recalculate);
bindEvents();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
