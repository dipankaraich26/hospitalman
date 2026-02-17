<?php
// Initialize auth and functions before header to allow redirects
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requireRole(['admin', 'doctor', 'nurse', 'receptionist']);

$pdo = getDBConnection();

// Handle POST before header include to avoid "headers already sent" error
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $patient_id = generatePatientId();
    $stmt = $pdo->prepare("INSERT INTO patients (patient_id, first_name, last_name, dob, gender, blood_group, phone, email, address, emergency_contact_name, emergency_contact_phone, insurance_provider, insurance_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $patient_id,
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
        trim($_POST['insurance_id']) ?: null
    ]);
    $newId = (int) $pdo->lastInsertId();
    auditLog('create', 'patients', 'patients', $newId, null, ['patient_id' => $patient_id, 'first_name' => $_POST['first_name'], 'last_name' => $_POST['last_name']]);
    setFlashMessage('success', "Patient registered successfully. ID: $patient_id");
    header('Location: index.php');
    exit;
}

// Now include header for HTML output
$pageTitle = 'Register Patient';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h4><i class="bi bi-person-plus"></i> Register New Patient</h4>
    <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<div class="card">
    <div class="card-body">
        <form method="POST">
            <h6 class="text-muted mb-3">Personal Information</h6>
            <div class="row mb-3">
                <div class="col-md-4">
                    <label class="form-label">First Name *</label>
                    <input type="text" name="first_name" class="form-control" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Last Name *</label>
                    <input type="text" name="last_name" class="form-control" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Date of Birth</label>
                    <input type="date" name="dob" class="form-control">
                </div>
            </div>
            <div class="row mb-3">
                <div class="col-md-4">
                    <label class="form-label">Gender *</label>
                    <select name="gender" class="form-select" required>
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Blood Group</label>
                    <select name="blood_group" class="form-select">
                        <option value="Unknown">Unknown</option>
                        <option value="A+">A+</option><option value="A-">A-</option>
                        <option value="B+">B+</option><option value="B-">B-</option>
                        <option value="AB+">AB+</option><option value="AB-">AB-</option>
                        <option value="O+">O+</option><option value="O-">O-</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Phone</label>
                    <input type="text" name="phone" class="form-control">
                </div>
            </div>
            <div class="row mb-3">
                <div class="col-md-4">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control">
                </div>
                <div class="col-md-8">
                    <label class="form-label">Address</label>
                    <input type="text" name="address" class="form-control">
                </div>
            </div>

            <hr>
            <h6 class="text-muted mb-3">Emergency Contact</h6>
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Contact Name</label>
                    <input type="text" name="emergency_contact_name" class="form-control">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Contact Phone</label>
                    <input type="text" name="emergency_contact_phone" class="form-control">
                </div>
            </div>

            <hr>
            <h6 class="text-muted mb-3">Insurance Information</h6>
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Insurance Provider</label>
                    <input type="text" name="insurance_provider" class="form-control" placeholder="e.g. BlueCross">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Insurance ID</label>
                    <input type="text" name="insurance_id" class="form-control">
                </div>
            </div>

            <div class="mt-4">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Register Patient</button>
                <a href="index.php" class="btn btn-light ms-2">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
