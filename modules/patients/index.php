<?php
$pageTitle = 'Patients';
require_once __DIR__ . '/../../includes/header.php';
requireRole(['admin', 'doctor', 'nurse', 'receptionist']);

$pdo = getDBConnection();
$patients = $pdo->query("SELECT * FROM patients ORDER BY created_at DESC")->fetchAll();
?>

<div class="page-header">
    <h4><i class="bi bi-people"></i> Patient Records</h4>
    <div>
        <div class="btn-group me-2">
            <a href="export_excel.php" class="btn btn-outline-success" title="Export to Excel">
                <i class="bi bi-file-earmark-spreadsheet"></i> Export Excel
            </a>
            <a href="import_excel.php" class="btn btn-outline-primary" title="Import from Excel">
                <i class="bi bi-upload"></i> Import Excel
            </a>
            <a href="download_template.php" class="btn btn-outline-secondary" title="Download Excel Template">
                <i class="bi bi-download"></i> Template
            </a>
        </div>
        <a href="add.php" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Register Patient</a>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <table class="table table-hover data-table">
            <thead>
                <tr>
                    <th>Patient ID</th>
                    <th>Name</th>
                    <th>Gender</th>
                    <th>Phone</th>
                    <th>Blood Group</th>
                    <th>Insurance</th>
                    <th>Registered</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($patients as $p): ?>
                <tr>
                    <td><strong><?= sanitize($p['patient_id']) ?></strong></td>
                    <td><?= sanitize($p['first_name'] . ' ' . $p['last_name']) ?></td>
                    <td><?= $p['gender'] ?></td>
                    <td><?= sanitize($p['phone'] ?? '-') ?></td>
                    <td><span class="badge bg-danger"><?= $p['blood_group'] ?></span></td>
                    <td><?= $p['insurance_provider'] ? sanitize($p['insurance_provider']) : '<span class="text-muted">None</span>' ?></td>
                    <td><?= formatDate($p['created_at']) ?></td>
                    <td>
                        <a href="view.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-info" title="View"><i class="bi bi-eye"></i></a>
                        <a href="edit.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="bi bi-pencil"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
