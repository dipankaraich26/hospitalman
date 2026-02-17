<?php
$pageTitle = 'Consultation';
require_once __DIR__ . '/../../includes/header.php';
requireRole(['admin', 'doctor']);

$pdo = getDBConnection();
$appointment_id = (int) ($_GET['appointment_id'] ?? 0);

// Load appointment info if provided
$appointment = null;
$patient = null;
if ($appointment_id) {
    $stmt = $pdo->prepare("
        SELECT a.*, p.id as pat_id, p.patient_id as pid, p.first_name, p.last_name, p.dob, p.gender, p.blood_group
        FROM appointments a JOIN patients p ON a.patient_id = p.id WHERE a.id = ?
    ");
    $stmt->execute([$appointment_id]);
    $appointment = $stmt->fetch();
    if ($appointment) $patient = getPatientById($appointment['pat_id']);
}

// Handle consultation + vitals + prescription submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo->beginTransaction();
    try {
        $patient_id = (int) $_POST['patient_id'];
        $doctor_id = $_SESSION['user_id'];
        $appt_id = (int) ($_POST['appointment_id'] ?? 0) ?: null;

        // Record vitals
        if (!empty($_POST['blood_pressure']) || !empty($_POST['temperature'])) {
            $vstmt = $pdo->prepare("INSERT INTO vitals (patient_id, appointment_id, blood_pressure, temperature, pulse, weight, height, oxygen_saturation, recorded_by) VALUES (?,?,?,?,?,?,?,?,?)");
            $vstmt->execute([
                $patient_id, $appt_id,
                trim($_POST['blood_pressure']),
                $_POST['temperature'] ?: null,
                $_POST['pulse'] ?: null,
                $_POST['weight'] ?: null,
                $_POST['height'] ?: null,
                $_POST['oxygen_saturation'] ?: null,
                $doctor_id
            ]);
        }

        // Create consultation
        $cstmt = $pdo->prepare("INSERT INTO consultations (appointment_id, patient_id, doctor_id, symptoms, diagnosis, notes) VALUES (?,?,?,?,?,?)");
        $cstmt->execute([$appt_id, $patient_id, $doctor_id, trim($_POST['symptoms']), trim($_POST['diagnosis']), trim($_POST['consultation_notes'])]);
        $consultation_id = $pdo->lastInsertId();

        // Create prescription if medicines were added
        if (!empty($_POST['med_id']) && is_array($_POST['med_id'])) {
            $pstmt = $pdo->prepare("INSERT INTO prescriptions (consultation_id, patient_id, doctor_id, prescription_date, status) VALUES (?,?,?,CURDATE(),'pending')");
            $pstmt->execute([$consultation_id, $patient_id, $doctor_id]);
            $prescription_id = $pdo->lastInsertId();

            $istmt = $pdo->prepare("INSERT INTO prescription_items (prescription_id, medicine_id, dosage, frequency, duration, quantity, instructions) VALUES (?,?,?,?,?,?,?)");
            foreach ($_POST['med_id'] as $idx => $med_id) {
                if ($med_id) {
                    $istmt->execute([
                        $prescription_id,
                        (int) $med_id,
                        trim($_POST['dosage'][$idx] ?? ''),
                        trim($_POST['frequency'][$idx] ?? ''),
                        trim($_POST['duration'][$idx] ?? ''),
                        (int) ($_POST['quantity'][$idx] ?? 1),
                        trim($_POST['instructions'][$idx] ?? '')
                    ]);
                }
            }
        }

        // Update appointment status
        if ($appt_id) {
            $pdo->prepare("UPDATE appointments SET status = 'completed' WHERE id = ?")->execute([$appt_id]);
        }

        $pdo->commit();
        auditLog('create', 'clinical', 'consultations', (int)$consultation_id, null, ['patient_id' => $patient_id, 'symptoms' => $_POST['symptoms'], 'diagnosis' => $_POST['diagnosis']]);
        setFlashMessage('success', 'Consultation saved successfully.');
        header('Location: index.php');
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        setFlashMessage('error', 'Error saving consultation: ' . $e->getMessage());
    }
}

$patients = $pdo->query("SELECT id, patient_id, first_name, last_name FROM patients ORDER BY first_name")->fetchAll();
$medicines = $pdo->query("SELECT id, name, generic_name, selling_price FROM medicines WHERE quantity_in_stock > 0 ORDER BY name")->fetchAll();
?>

<div class="page-header">
    <h4><i class="bi bi-clipboard2-pulse"></i> Consultation</h4>
    <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<?php if ($patient): ?>
<div class="alert alert-info">
    <strong>Patient:</strong> <?= sanitize($patient['first_name'] . ' ' . $patient['last_name']) ?> (<?= sanitize($patient['patient_id']) ?>)
    | <strong>Gender:</strong> <?= $patient['gender'] ?> | <strong>Blood Group:</strong> <?= $patient['blood_group'] ?>
</div>
<?php endif; ?>

<form method="POST">
    <input type="hidden" name="appointment_id" value="<?= $appointment_id ?>">

    <?php if (!$patient): ?>
    <div class="card mb-3">
        <div class="card-header">Select Patient</div>
        <div class="card-body">
            <select name="patient_id" class="form-select" required>
                <option value="">Select Patient</option>
                <?php foreach ($patients as $p): ?>
                <option value="<?= $p['id'] ?>"><?= sanitize($p['patient_id'] . ' - ' . $p['first_name'] . ' ' . $p['last_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <?php else: ?>
    <input type="hidden" name="patient_id" value="<?= $patient['id'] ?>">
    <?php endif; ?>

    <!-- Vitals -->
    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-heart-pulse"></i> Vitals</div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-2 mb-2">
                    <label class="form-label">Blood Pressure</label>
                    <input type="text" name="blood_pressure" class="form-control" placeholder="120/80">
                </div>
                <div class="col-md-2 mb-2">
                    <label class="form-label">Temp (&deg;C)</label>
                    <input type="number" name="temperature" class="form-control" step="0.1" placeholder="37.0">
                </div>
                <div class="col-md-2 mb-2">
                    <label class="form-label">Pulse (bpm)</label>
                    <input type="number" name="pulse" class="form-control" placeholder="72">
                </div>
                <div class="col-md-2 mb-2">
                    <label class="form-label">Weight (kg)</label>
                    <input type="number" name="weight" class="form-control" step="0.1" placeholder="70.0">
                </div>
                <div class="col-md-2 mb-2">
                    <label class="form-label">Height (cm)</label>
                    <input type="number" name="height" class="form-control" step="0.1" placeholder="170.0">
                </div>
                <div class="col-md-2 mb-2">
                    <label class="form-label">SpO2 (%)</label>
                    <input type="number" name="oxygen_saturation" class="form-control" placeholder="98">
                </div>
            </div>
        </div>
    </div>

    <!-- Consultation -->
    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-journal-medical"></i> Consultation Details</div>
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label">Symptoms *</label>
                <textarea name="symptoms" class="form-control" rows="2" required placeholder="Describe presenting symptoms..."></textarea>
            </div>
            <div class="mb-3">
                <label class="form-label">Diagnosis *</label>
                <textarea name="diagnosis" class="form-control" rows="2" required placeholder="Clinical diagnosis..."></textarea>
            </div>
            <div class="mb-3">
                <label class="form-label">Notes</label>
                <textarea name="consultation_notes" class="form-control" rows="2" placeholder="Additional clinical notes..."></textarea>
            </div>
        </div>
    </div>

    <!-- Prescription -->
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-prescription2"></i> Prescription</span>
            <button type="button" class="btn btn-sm btn-outline-primary" id="addMedRow"><i class="bi bi-plus"></i> Add Medicine</button>
        </div>
        <div class="card-body">
            <table class="table" id="prescriptionTable">
                <thead>
                    <tr>
                        <th>Medicine</th>
                        <th>Dosage</th>
                        <th>Frequency</th>
                        <th>Duration</th>
                        <th>Qty</th>
                        <th>Instructions</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="med-row">
                        <td>
                            <select name="med_id[]" class="form-select form-select-sm">
                                <option value="">Select Medicine</option>
                                <?php foreach ($medicines as $m): ?>
                                <option value="<?= $m['id'] ?>"><?= sanitize($m['name']) ?> (<?= formatCurrency($m['selling_price']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><input type="text" name="dosage[]" class="form-control form-control-sm" placeholder="500mg"></td>
                        <td><input type="text" name="frequency[]" class="form-control form-control-sm" placeholder="Twice daily"></td>
                        <td><input type="text" name="duration[]" class="form-control form-control-sm" placeholder="7 days"></td>
                        <td><input type="number" name="quantity[]" class="form-control form-control-sm" value="1" min="1" style="width:70px"></td>
                        <td><input type="text" name="instructions[]" class="form-control form-control-sm" placeholder="After meals"></td>
                        <td><button type="button" class="btn btn-sm btn-outline-danger remove-row"><i class="bi bi-x"></i></button></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="mb-4">
        <button type="submit" class="btn btn-primary btn-lg"><i class="bi bi-check-lg"></i> Save Consultation</button>
        <a href="index.php" class="btn btn-light btn-lg ms-2">Cancel</a>
    </div>
</form>

<script>
document.getElementById('addMedRow').addEventListener('click', function() {
    var row = document.querySelector('.med-row').cloneNode(true);
    row.querySelectorAll('input').forEach(function(i) { i.value = ''; });
    row.querySelector('select').value = '';
    row.querySelector('input[name="quantity[]"]').value = '1';
    document.querySelector('#prescriptionTable tbody').appendChild(row);
});

document.querySelector('#prescriptionTable').addEventListener('click', function(e) {
    if (e.target.closest('.remove-row')) {
        var rows = document.querySelectorAll('.med-row');
        if (rows.length > 1) e.target.closest('.med-row').remove();
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
