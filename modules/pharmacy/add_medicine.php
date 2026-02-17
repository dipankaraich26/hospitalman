<?php
$pageTitle = 'Add Medicine';
require_once __DIR__ . '/../../includes/header.php';
requireRole(['admin', 'pharmacist']);

$pdo = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $pdo->prepare("INSERT INTO medicines (name, generic_name, category, manufacturer, batch_number, quantity_in_stock, unit_price, selling_price, expiry_date, reorder_level) VALUES (?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        trim($_POST['name']),
        trim($_POST['generic_name']),
        trim($_POST['category']),
        trim($_POST['manufacturer']),
        trim($_POST['batch_number']),
        (int) $_POST['quantity_in_stock'],
        (float) $_POST['unit_price'],
        (float) $_POST['selling_price'],
        $_POST['expiry_date'] ?: null,
        (int) $_POST['reorder_level']
    ]);
    setFlashMessage('success', 'Medicine added successfully.');
    header('Location: index.php');
    exit;
}
?>

<div class="page-header">
    <h4><i class="bi bi-plus-circle"></i> Add Medicine</h4>
    <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<div class="card">
    <div class="card-body">
        <form method="POST">
            <div class="row mb-3">
                <div class="col-md-4">
                    <label class="form-label">Medicine Name *</label>
                    <input type="text" name="name" class="form-control" required placeholder="e.g. Amoxicillin 500mg">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Generic Name</label>
                    <input type="text" name="generic_name" class="form-control" placeholder="e.g. Amoxicillin">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Category</label>
                    <input type="text" name="category" class="form-control" list="categories" placeholder="e.g. Antibiotics">
                    <datalist id="categories">
                        <option value="Antibiotics"><option value="Analgesics"><option value="Anti-inflammatory">
                        <option value="Antihypertensives"><option value="Antidiabetics"><option value="Cardiovascular">
                        <option value="Gastrointestinal"><option value="Respiratory"><option value="Neurological">
                        <option value="Antihistamines"><option value="Vitamins"><option value="IV Fluids">
                    </datalist>
                </div>
            </div>
            <div class="row mb-3">
                <div class="col-md-4">
                    <label class="form-label">Manufacturer</label>
                    <input type="text" name="manufacturer" class="form-control">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Batch Number</label>
                    <input type="text" name="batch_number" class="form-control">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Expiry Date</label>
                    <input type="date" name="expiry_date" class="form-control">
                </div>
            </div>
            <div class="row mb-3">
                <div class="col-md-3">
                    <label class="form-label">Quantity in Stock *</label>
                    <input type="number" name="quantity_in_stock" class="form-control" min="0" value="0" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Unit (Buy) Price *</label>
                    <input type="number" name="unit_price" class="form-control" step="0.01" min="0" value="0.00" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Selling Price *</label>
                    <input type="number" name="selling_price" class="form-control" step="0.01" min="0" value="0.00" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Reorder Level *</label>
                    <input type="number" name="reorder_level" class="form-control" min="0" value="10" required>
                </div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Add Medicine</button>
            <a href="index.php" class="btn btn-light ms-2">Cancel</a>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
