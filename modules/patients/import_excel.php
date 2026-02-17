<?php
// Handle import before header
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requireRole(['admin', 'receptionist']);

$pdo = getDBConnection();
$results = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['excel_file'])) {
    require_once __DIR__ . '/../../includes/excel_helpers.php';

    try {
        $file = $_FILES['excel_file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('File upload failed.');
        }

        $allowedExtensions = ['xlsx', 'xls', 'csv'];
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedExtensions)) {
            throw new Exception('Invalid file type. Please upload .xlsx, .xls, or .csv file.');
        }

        // Import the file
        $data = excelImport($file['tmp_name']);

        if (empty($data)) {
            throw new Exception('No data found in the file.');
        }

        // Validate and import patients
        $imported = 0;
        $errors = [];
        $pdo->beginTransaction();

        foreach ($data as $index => $row) {
            $rowNum = $index + 2; // +2 because index starts at 0 and header is row 1

            // Validate required fields
            if (empty($row['First Name']) || empty($row['Last Name']) || empty($row['Gender'])) {
                $errors[] = "Row $rowNum: Missing required fields (First Name, Last Name, Gender)";
                continue;
            }

            // Validate gender
            if (!in_array($row['Gender'], ['Male', 'Female', 'Other'])) {
                $errors[] = "Row $rowNum: Invalid gender. Must be Male, Female, or Other.";
                continue;
            }

            // Generate patient ID
            $lastPatient = $pdo->query("SELECT patient_id FROM patients ORDER BY id DESC LIMIT 1")->fetch();
            if ($lastPatient) {
                $lastNum = (int) substr($lastPatient['patient_id'], 1);
                $patientId = 'P' . str_pad($lastNum + 1, 6, '0', STR_PAD_LEFT);
            } else {
                $patientId = 'P000001';
            }

            try {
                $stmt = $pdo->prepare("
                    INSERT INTO patients (
                        patient_id, first_name, last_name, dob, gender, blood_group,
                        phone, email, address, emergency_contact_name, emergency_contact_phone,
                        insurance_provider, insurance_id, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");

                $stmt->execute([
                    $patientId,
                    trim($row['First Name']),
                    trim($row['Last Name']),
                    !empty($row['Date of Birth']) ? $row['Date of Birth'] : null,
                    $row['Gender'],
                    !empty($row['Blood Group']) ? $row['Blood Group'] : 'Unknown',
                    trim($row['Phone'] ?? ''),
                    trim($row['Email'] ?? ''),
                    trim($row['Address'] ?? ''),
                    trim($row['Emergency Contact Name'] ?? ''),
                    trim($row['Emergency Contact Phone'] ?? ''),
                    trim($row['Insurance Provider'] ?? '') ?: null,
                    trim($row['Insurance ID'] ?? '') ?: null
                ]);

                $imported++;
            } catch (PDOException $e) {
                $errors[] = "Row $rowNum: Database error - " . $e->getMessage();
            }
        }

        $pdo->commit();

        $results = [
            'success' => true,
            'imported' => $imported,
            'total' => count($data),
            'errors' => $errors
        ];

        if ($imported > 0) {
            auditLog('create', 'patients', 'patients', null, null, ['action' => 'bulk_import', 'count' => $imported]);
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $results = [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

// Now include header
$pageTitle = 'Import Patients from Excel';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h4><i class="bi bi-file-earmark-spreadsheet"></i> Import Patients from Excel</h4>
    <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back to Patients</a>
</div>

<?php if ($results): ?>
<div class="row mb-4">
    <div class="col-12">
        <?php if ($results['success']): ?>
        <div class="alert alert-success">
            <h5><i class="bi bi-check-circle"></i> Import Completed</h5>
            <p class="mb-0">
                Successfully imported <strong><?= $results['imported'] ?></strong> out of <strong><?= $results['total'] ?></strong> records.
            </p>
        </div>

        <?php if (!empty($results['errors'])): ?>
        <div class="alert alert-warning">
            <h6><i class="bi bi-exclamation-triangle"></i> Errors (<?= count($results['errors']) ?>)</h6>
            <ul class="mb-0">
                <?php foreach (array_slice($results['errors'], 0, 10) as $error): ?>
                <li><?= sanitize($error) ?></li>
                <?php endforeach; ?>
                <?php if (count($results['errors']) > 10): ?>
                <li><em>... and <?= count($results['errors']) - 10 ?> more errors</em></li>
                <?php endif; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <div class="alert alert-danger">
            <h5><i class="bi bi-x-circle"></i> Import Failed</h5>
            <p class="mb-0"><?= sanitize($results['error']) ?></p>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-upload"></i> Upload Excel File
            </div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label class="form-label">Select Excel File (.xlsx, .xls, or .csv)</label>
                        <input type="file" name="excel_file" class="form-control" accept=".xlsx,.xls,.csv" required>
                        <div class="form-text">
                            Maximum file size: 5MB. Supported formats: Excel (.xlsx, .xls) or CSV (.csv)
                        </div>
                    </div>

                    <div class="alert alert-info">
                        <h6><i class="bi bi-info-circle"></i> Required Columns</h6>
                        <ul class="mb-0">
                            <li><strong>First Name</strong> - Required</li>
                            <li><strong>Last Name</strong> - Required</li>
                            <li><strong>Gender</strong> - Required (Male, Female, or Other)</li>
                            <li>Date of Birth - Optional (YYYY-MM-DD format)</li>
                            <li>Blood Group - Optional</li>
                            <li>Phone, Email, Address - Optional</li>
                            <li>Emergency Contact Name & Phone - Optional</li>
                            <li>Insurance Provider & Insurance ID - Optional</li>
                        </ul>
                    </div>

                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-upload"></i> Import Patients
                    </button>
                    <a href="download_template.php" class="btn btn-outline-primary">
                        <i class="bi bi-download"></i> Download Excel Template
                    </a>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card border-primary">
            <div class="card-header bg-primary text-white">
                <i class="bi bi-lightbulb"></i> Import Tips
            </div>
            <div class="card-body">
                <h6>Before Importing:</h6>
                <ol class="small">
                    <li>Download the Excel template for correct format</li>
                    <li>Fill in your patient data</li>
                    <li>Ensure dates are in YYYY-MM-DD format</li>
                    <li>Gender must be Male, Female, or Other</li>
                    <li>Remove sample data rows</li>
                </ol>

                <hr>

                <h6>Patient ID:</h6>
                <p class="small mb-0">Patient IDs (P000001, P000002, etc.) are automatically generated. Do not include them in your import file.</p>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
