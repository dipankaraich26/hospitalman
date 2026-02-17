<?php
$pageTitle = 'Import Patients';
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/export_helpers.php';
requireRole(['admin', 'receptionist']);

$pdo = getDBConnection();
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $import = csvImport($_FILES['csv_file'], ['first_name', 'last_name', 'gender']);

    if ($import['errors'] && empty($import['rows'])) {
        setFlashMessage('error', implode('<br>', $import['errors']));
    } else {
        $pdo->beginTransaction();
        try {
            $imported = 0;
            $stmt = $pdo->prepare("INSERT INTO patients (patient_id, first_name, last_name, dob, gender, blood_group, phone, email, address, emergency_contact_name, emergency_contact_phone, insurance_provider, insurance_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");

            foreach ($import['rows'] as $row) {
                $patientId = generatePatientId();
                $stmt->execute([
                    $patientId,
                    trim($row['first_name']),
                    trim($row['last_name']),
                    !empty($row['dob']) ? $row['dob'] : null,
                    $row['gender'],
                    $row['blood_group'] ?? 'Unknown',
                    $row['phone'] ?? null,
                    $row['email'] ?? null,
                    $row['address'] ?? null,
                    $row['emergency_contact_name'] ?? null,
                    $row['emergency_contact_phone'] ?? null,
                    !empty($row['insurance_provider']) ? $row['insurance_provider'] : null,
                    !empty($row['insurance_id']) ? $row['insurance_id'] : null
                ]);
                $imported++;
            }

            $pdo->commit();
            auditLog('import', 'patients', 'patients', null, null, ['imported' => $imported]);
            $result = ['success' => $imported, 'errors' => $import['errors']];
            setFlashMessage('success', "$imported patients imported successfully." . (count($import['errors']) ? ' ' . count($import['errors']) . ' rows had errors.' : ''));
        } catch (Exception $e) {
            $pdo->rollBack();
            setFlashMessage('error', 'Import failed: ' . $e->getMessage());
        }
    }
}
?>

<div class="page-header">
    <h4><i class="bi bi-upload"></i> Import Patients</h4>
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
                    <a href="templates/patient_import_template.csv" class="btn btn-outline-info ms-2"><i class="bi bi-download"></i> Download Template</a>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card mb-3">
            <div class="card-header">Instructions</div>
            <div class="card-body">
                <ul class="mb-0">
                    <li>Download the template CSV file first</li>
                    <li>Required columns: <strong>first_name, last_name, gender</strong></li>
                    <li>Gender values: Male, Female, or Other</li>
                    <li>Date format for DOB: YYYY-MM-DD</li>
                    <li>Blood groups: A+, A-, B+, B-, AB+, AB-, O+, O-, Unknown</li>
                    <li>Patient IDs are auto-generated</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php if ($result): ?>
<div class="card">
    <div class="card-header">Import Results</div>
    <div class="card-body">
        <p class="text-success"><strong><?= $result['success'] ?></strong> patients imported successfully.</p>
        <?php if ($result['errors']): ?>
        <p class="text-danger"><strong><?= count($result['errors']) ?></strong> rows had errors:</p>
        <ul class="text-danger small">
            <?php foreach (array_slice($result['errors'], 0, 20) as $err): ?>
            <li><?= sanitize($err) ?></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
