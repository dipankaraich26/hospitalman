<?php
$pageTitle = 'Medical Records';
require_once __DIR__ . '/../../includes/header.php';
requireRole(['admin', 'doctor', 'nurse']);

$pdo = getDBConnection();

$patient_id = (int) ($_GET['patient_id'] ?? 0);
$patient = $patient_id ? getPatientById($patient_id) : null;

$patients = $pdo->query("SELECT id, patient_id, first_name, last_name FROM patients ORDER BY first_name")->fetchAll();
?>

<div class="page-header">
    <h4><i class="bi bi-journal-medical"></i> Medical Records</h4>
</div>

<!-- Patient Selector -->
<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row align-items-end">
            <div class="col-md-6">
                <label class="form-label">Select Patient</label>
                <select name="patient_id" class="form-select" onchange="this.form.submit()">
                    <option value="">-- Select Patient --</option>
                    <?php foreach ($patients as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= $patient_id === $p['id'] ? 'selected' : '' ?>>
                        <?= sanitize($p['patient_id'] . ' - ' . $p['first_name'] . ' ' . $p['last_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>
</div>

<?php if ($patient): ?>
<?php
$consultations = $pdo->prepare("
    SELECT c.*, u.full_name as doctor_name, u.specialization
    FROM consultations c
    JOIN users u ON c.doctor_id = u.id
    WHERE c.patient_id = ?
    ORDER BY c.created_at DESC
");
$consultations->execute([$patient_id]);
$consultations = $consultations->fetchAll();

$allVitals = $pdo->prepare("SELECT v.*, u.full_name as recorder FROM vitals v JOIN users u ON v.recorded_by = u.id WHERE v.patient_id = ? ORDER BY v.recorded_at DESC");
$allVitals->execute([$patient_id]);
$allVitals = $allVitals->fetchAll();

$labTests = $pdo->prepare("SELECT lt.*, u.full_name as doctor_name FROM lab_tests lt JOIN users u ON lt.doctor_id = u.id WHERE lt.patient_id = ? ORDER BY lt.test_date DESC");
$labTests->execute([$patient_id]);
$labTests = $labTests->fetchAll();
?>

<div class="alert alert-light border">
    <strong><?= sanitize($patient['first_name'] . ' ' . $patient['last_name']) ?></strong> (<?= sanitize($patient['patient_id']) ?>)
    | Gender: <?= $patient['gender'] ?> | Blood: <span class="badge bg-danger"><?= $patient['blood_group'] ?></span>
    | DOB: <?= $patient['dob'] ? formatDate($patient['dob']) : 'N/A' ?>
</div>

<ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#consTab">Consultations (<?= count($consultations) ?>)</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#vitalsTab">Vitals (<?= count($allVitals) ?>)</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#labTab">Lab Tests (<?= count($labTests) ?>)</a></li>
</ul>

<div class="tab-content">
    <!-- Consultations -->
    <div class="tab-pane fade show active" id="consTab">
        <?php if (empty($consultations)): ?>
        <div class="alert alert-info">No consultations recorded.</div>
        <?php else: foreach ($consultations as $c): ?>
        <div class="card mb-2">
            <div class="card-header d-flex justify-content-between">
                <span><i class="bi bi-calendar3"></i> <?= formatDateTime($c['created_at']) ?></span>
                <span class="text-muted"><?= sanitize($c['doctor_name']) ?> (<?= sanitize($c['specialization'] ?? '') ?>)</span>
            </div>
            <div class="card-body">
                <p><strong>Symptoms:</strong> <?= sanitize($c['symptoms']) ?></p>
                <p><strong>Diagnosis:</strong> <?= sanitize($c['diagnosis']) ?></p>
                <?php if ($c['notes']): ?><p><strong>Notes:</strong> <?= sanitize($c['notes']) ?></p><?php endif; ?>

                <?php
                $rxItems = $pdo->prepare("
                    SELECT pi.*, m.name as med_name FROM prescription_items pi
                    LEFT JOIN medicines m ON pi.medicine_id = m.id
                    JOIN prescriptions pr ON pi.prescription_id = pr.id
                    WHERE pr.consultation_id = ?
                ");
                $rxItems->execute([$c['id']]);
                $items = $rxItems->fetchAll();
                ?>
                <?php if (!empty($items)): ?>
                <hr>
                <strong>Prescription:</strong>
                <table class="table table-sm mt-1">
                    <thead><tr><th>Medicine</th><th>Dosage</th><th>Frequency</th><th>Duration</th><th>Qty</th></tr></thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                        <tr>
                            <td><?= sanitize($item['med_name'] ?? 'Unknown') ?></td>
                            <td><?= sanitize($item['dosage']) ?></td>
                            <td><?= sanitize($item['frequency']) ?></td>
                            <td><?= sanitize($item['duration']) ?></td>
                            <td><?= $item['quantity'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; endif; ?>
    </div>

    <!-- Vitals -->
    <div class="tab-pane fade" id="vitalsTab">
        <div class="mb-2 text-end">
            <a href="vital_trends.php?patient_id=<?= $patient_id ?>" class="btn btn-sm btn-info"><i class="bi bi-activity"></i> View Vital Trends Charts</a>
        </div>
        <div class="card">
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Date</th><th>BP</th><th>Temp</th><th>Pulse</th><th>Weight</th><th>Height</th><th>SpO2</th><th>By</th></tr></thead>
                    <tbody>
                        <?php foreach ($allVitals as $v): ?>
                        <tr>
                            <td><?= formatDateTime($v['recorded_at']) ?></td>
                            <td><?= sanitize($v['blood_pressure'] ?? '-') ?></td>
                            <td><?= $v['temperature'] ?? '-' ?></td>
                            <td><?= $v['pulse'] ?? '-' ?></td>
                            <td><?= $v['weight'] ?? '-' ?></td>
                            <td><?= $v['height'] ?? '-' ?></td>
                            <td><?= $v['oxygen_saturation'] ?? '-' ?></td>
                            <td><?= sanitize($v['recorder']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Lab Tests -->
    <div class="tab-pane fade" id="labTab">
        <div class="card">
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Date</th><th>Test</th><th>Doctor</th><th>Status</th><th>Result</th></tr></thead>
                    <tbody>
                        <?php foreach ($labTests as $lt): ?>
                        <tr>
                            <td><?= formatDate($lt['test_date']) ?></td>
                            <td><strong><?= sanitize($lt['test_name']) ?></strong></td>
                            <td><?= sanitize($lt['doctor_name']) ?></td>
                            <td><?= getStatusBadge($lt['status']) ?></td>
                            <td><small><?= sanitize($lt['result'] ?? '-') ?></small></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php elseif ($patient_id): ?>
<div class="alert alert-danger">Patient not found.</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
