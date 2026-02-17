<?php
$pageTitle = 'Import Medicines';
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/export_helpers.php';
requireRole(['admin', 'pharmacist']);

$pdo = getDBConnection();
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $import = csvImport($_FILES['csv_file'], ['name', 'quantity_to_add']);

    if ($import['errors'] && empty($import['rows'])) {
        setFlashMessage('error', implode('<br>', $import['errors']));
    } else {
        $updated = 0;
        $notFound = [];

        foreach ($import['rows'] as $row) {
            $name = trim($row['name']);
            $stmt = $pdo->prepare("SELECT id FROM medicines WHERE name = ?");
            $stmt->execute([$name]);
            $med = $stmt->fetch();

            if (!$med) {
                $notFound[] = $name;
                continue;
            }

            $qty = (int)$row['quantity_to_add'];
            $sets = ["quantity_in_stock = quantity_in_stock + ?"];
            $vals = [$qty];

            if (!empty($row['unit_price'])) { $sets[] = "unit_price = ?"; $vals[] = (float)$row['unit_price']; }
            if (!empty($row['selling_price'])) { $sets[] = "selling_price = ?"; $vals[] = (float)$row['selling_price']; }
            if (!empty($row['reorder_level'])) { $sets[] = "reorder_level = ?"; $vals[] = (int)$row['reorder_level']; }

            $vals[] = $med['id'];
            $pdo->prepare("UPDATE medicines SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
            $updated++;
        }

        auditLog('import', 'pharmacy', 'medicines', null, null, ['updated' => $updated]);
        $result = ['updated' => $updated, 'not_found' => $notFound, 'errors' => $import['errors']];
        setFlashMessage('success', "$updated medicines updated." . (count($notFound) ? ' ' . count($notFound) . ' not found.' : ''));
    }
}
?>

<div class="page-header">
    <h4><i class="bi bi-upload"></i> Import Medicine Stock</h4>
    <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<div class="row">
    <div class="col-lg-6">
        <div class="card mb-3">
            <div class="card-header">Upload CSV File</div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label class="form-label">CSV File *</label>
                        <input type="file" name="csv_file" class="form-control" accept=".csv" required>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-upload"></i> Import</button>
                    <a href="templates/medicine_import_template.csv" class="btn btn-outline-info ms-2"><i class="bi bi-download"></i> Download Template</a>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card mb-3">
            <div class="card-header">Instructions</div>
            <div class="card-body">
                <ul class="mb-0">
                    <li>Medicines are matched by <strong>exact name</strong></li>
                    <li>Required: <strong>name, quantity_to_add</strong></li>
                    <li>quantity_to_add is <em>added</em> to current stock</li>
                    <li>Optional: unit_price, selling_price, reorder_level (overwrites if provided)</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php if ($result): ?>
<div class="card">
    <div class="card-header">Import Results</div>
    <div class="card-body">
        <p class="text-success"><strong><?= $result['updated'] ?></strong> medicines updated.</p>
        <?php if ($result['not_found']): ?>
        <p class="text-warning"><strong><?= count($result['not_found']) ?></strong> medicines not found:</p>
        <ul class="text-warning small">
            <?php foreach ($result['not_found'] as $name): ?>
            <li><?= sanitize($name) ?></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
