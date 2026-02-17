<?php
// Initialize auth and functions before header to allow redirects
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requireRole(['admin', 'doctor', 'nurse', 'receptionist']);

$pdo = getDBConnection();

// Handle POST before header include to avoid "headers already sent" error
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $pdo->prepare("INSERT INTO appointments (patient_id, doctor_id, appointment_date, appointment_time, status, notes) VALUES (?, ?, ?, ?, 'scheduled', ?)");
    $stmt->execute([
        (int) $_POST['patient_id'],
        (int) $_POST['doctor_id'],
        $_POST['appointment_date'],
        $_POST['appointment_time'],
        trim($_POST['notes'])
    ]);
    setFlashMessage('success', 'Appointment booked successfully.');
    header('Location: index.php');
    exit;
}

$patients = $pdo->query("SELECT id, patient_id, first_name, last_name FROM patients ORDER BY first_name")->fetchAll();
$doctors = getDoctors();

// Now include header for HTML output
$pageTitle = 'Book Appointment';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h4><i class="bi bi-calendar-plus"></i> Book Appointment</h4>
    <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<div class="card">
    <div class="card-body">
        <form method="POST">
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Patient *</label>
                    <select name="patient_id" class="form-select" required>
                        <option value="">Select Patient</option>
                        <?php foreach ($patients as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= ($_GET['patient_id'] ?? '') == $p['id'] ? 'selected' : '' ?>>
                            <?= sanitize($p['patient_id'] . ' - ' . $p['first_name'] . ' ' . $p['last_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Doctor *</label>
                    <select name="doctor_id" class="form-select" required>
                        <option value="">Select Doctor</option>
                        <?php foreach ($doctors as $d): ?>
                        <option value="<?= $d['id'] ?>"><?= sanitize($d['full_name'] . ' (' . ($d['specialization'] ?? 'General') . ')') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="row mb-3">
                <div class="col-md-4">
                    <label class="form-label">Date *</label>
                    <input type="date" name="appointment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Time *</label>
                    <input type="time" name="appointment_time" class="form-control" value="09:00" required>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="3" placeholder="Reason for visit..."></textarea>
            </div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Book Appointment</button>
            <a href="index.php" class="btn btn-light ms-2">Cancel</a>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
