<?php
// Initialize auth and functions before header to allow redirects
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requireRole(['admin', 'doctor', 'nurse', 'receptionist']);

$pdo = getDBConnection();
$id = (int) ($_GET['id'] ?? 0);
$patient = getPatientById($id);
if (!$patient) {
    setFlashMessage('error', 'Patient not found.');
    header('Location: index.php');
    exit;
}

// Handle POST before header include to avoid "headers already sent" error
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $oldData = getPatientById($id);
    $stmt = $pdo->prepare("UPDATE patients SET first_name=?, last_name=?, dob=?, gender=?, blood_group=?, phone=?, email=?, address=?, emergency_contact_name=?, emergency_contact_phone=?, insurance_provider=?, insurance_id=? WHERE id=?");
    $stmt->execute([
        trim($_POST['first_name']),
        trim($_POST['last_name']),
        $_POST['dob'] ?: null,
        $_POST['gender'],
        $_POST['blood_group'],
        trim($_POST['phone']),
        trim($_POST['email']),
        trim($_POST['address']),
        trim($_POST['emergency_contact_name']),
        trim($_POST['emergency_contact_phone']),
        trim($_POST['insurance_provider']) ?: null,
        trim($_POST['insurance_id']) ?: null,
        $id
    ]);
    auditLog('update', 'patients', 'patients', $id, $oldData, ['first_name' => $_POST['first_name'], 'last_name' => $_POST['last_name']]);
    setFlashMessage('success', 'Patient updated successfully.');
    header('Location: view.php?id=' . $id);
    exit;
}

// Now include header for HTML output
$pageTitle = 'Edit Patient';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h4><i class="bi bi-pencil-square"></i> Edit Patient - <?= sanitize($patient['patient_id']) ?></h4>
    <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<div class="card">
    <div class="card-body">
        <form method="POST">
            <h6 class="text-muted mb-3">Personal Information</h6>
            <div class="row mb-3">
                <div class="col-md-4">
                    <label class="form-label">First Name *</label>
                    <input type="text" name="first_name" class="form-control" value="<?= sanitize($patient['first_name']) ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Last Name *</label>
                    <input type="text" name="last_name" class="form-control" value="<?= sanitize($patient['last_name']) ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Date of Birth</label>
                    <input type="date" name="dob" class="form-control" value="<?= $patient['dob'] ?? '' ?>">
                </div>
            </div>
            <div class="row mb-3">
                <div class="col-md-4">
                    <label class="form-label">Gender *</label>
                    <select name="gender" class="form-select" required>
                        <?php foreach (['Male','Female','Other'] as $g): ?>
                        <option value="<?= $g ?>" <?= $patient['gender'] === $g ? 'selected' : '' ?>><?= $g ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Blood Group</label>
                    <select name="blood_group" class="form-select">
                        <?php foreach (['Unknown','A+','A-','B+','B-','AB+','AB-','O+','O-'] as $bg): ?>
                        <option value="<?= $bg ?>" <?= $patient['blood_group'] === $bg ? 'selected' : '' ?>><?= $bg ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Phone</label>
                    <input type="text" name="phone" class="form-control" value="<?= sanitize($patient['phone'] ?? '') ?>">
                </div>
            </div>
            <div class="row mb-3">
                <div class="col-md-4">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" value="<?= sanitize($patient['email'] ?? '') ?>">
                </div>
                <div class="col-md-8">
                    <label class="form-label">Address</label>
                    <input type="text" name="address" class="form-control" value="<?= sanitize($patient['address'] ?? '') ?>">
                </div>
            </div>

            <hr>
            <h6 class="text-muted mb-3">Emergency Contact</h6>
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Contact Name</label>
                    <input type="text" name="emergency_contact_name" class="form-control" value="<?= sanitize($patient['emergency_contact_name'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Contact Phone</label>
                    <input type="text" name="emergency_contact_phone" class="form-control" value="<?= sanitize($patient['emergency_contact_phone'] ?? '') ?>">
                </div>
            </div>

            <hr>
            <h6 class="text-muted mb-3">Insurance Information</h6>
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Insurance Provider</label>
                    <input type="text" name="insurance_provider" class="form-control" value="<?= sanitize($patient['insurance_provider'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Insurance ID</label>
                    <input type="text" name="insurance_id" class="form-control" value="<?= sanitize($patient['insurance_id'] ?? '') ?>">
                </div>
            </div>

            <div class="mt-4">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Save Changes</button>
                <a href="view.php?id=<?= $id ?>" class="btn btn-light ms-2">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
