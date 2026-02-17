<?php
$pageTitle = 'Patient Profile';
require_once __DIR__ . '/../../includes/header.php';
requireRole(['admin', 'doctor', 'nurse', 'receptionist']);

$pdo = getDBConnection();
$id = (int) ($_GET['id'] ?? 0);
$patient = getPatientById($id);
if (!$patient) {
    setFlashMessage('error', 'Patient not found.');
    header('Location: index.php');
    exit;
}

// Get visit history
$visits = $pdo->prepare("
    SELECT a.*, u.full_name as doctor_name, c.diagnosis
    FROM appointments a
    JOIN users u ON a.doctor_id = u.id
    LEFT JOIN consultations c ON c.appointment_id = a.id
    WHERE a.patient_id = ?
    ORDER BY a.appointment_date DESC
    LIMIT 10
");
$visits->execute([$id]);
$visits = $visits->fetchAll();

// Get billing history
$bills = $pdo->prepare("
    SELECT i.*, (SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id = i.id) as paid_amount
    FROM invoices i
    WHERE i.patient_id = ?
    ORDER BY i.invoice_date DESC
    LIMIT 10
");
$bills->execute([$id]);
$bills = $bills->fetchAll();

// Get prescriptions
$prescriptions = $pdo->prepare("
    SELECT pr.*, u.full_name as doctor_name
    FROM prescriptions pr
    JOIN users u ON pr.doctor_id = u.id
    WHERE pr.patient_id = ?
    ORDER BY pr.prescription_date DESC
    LIMIT 10
");
$prescriptions->execute([$id]);
$prescriptions = $prescriptions->fetchAll();

// Latest vitals
$latestVitals = $pdo->prepare("SELECT * FROM vitals WHERE patient_id = ? ORDER BY recorded_at DESC LIMIT 1");
$latestVitals->execute([$id]);
$vitals = $latestVitals->fetch();

// Calculate age
$age = $patient['dob'] ? date_diff(date_create($patient['dob']), date_create('today'))->y : 'N/A';
?>

<div class="page-header">
    <h4><i class="bi bi-person-badge"></i> <?= sanitize($patient['first_name'] . ' ' . $patient['last_name']) ?></h4>
    <div>
        <a href="<?= BASE_URL ?>/modules/clinical/vital_trends.php?patient_id=<?= $id ?>" class="btn btn-info"><i class="bi bi-activity"></i> Vital Trends</a>
        <a href="edit.php?id=<?= $id ?>" class="btn btn-primary"><i class="bi bi-pencil"></i> Edit</a>
        <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back</a>
    </div>
</div>

<!-- Patient Info Card -->
<div class="row mb-4">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">Patient Information</div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-borderless table-sm">
                            <tr><th width="40%">Patient ID:</th><td><strong><?= sanitize($patient['patient_id']) ?></strong></td></tr>
                            <tr><th>Full Name:</th><td><?= sanitize($patient['first_name'] . ' ' . $patient['last_name']) ?></td></tr>
                            <tr><th>Date of Birth:</th><td><?= $patient['dob'] ? formatDate($patient['dob']) : 'N/A' ?></td></tr>
                            <tr><th>Age:</th><td><?= $age ?> years</td></tr>
                            <tr><th>Gender:</th><td><?= $patient['gender'] ?></td></tr>
                            <tr><th>Blood Group:</th><td><span class="badge bg-danger"><?= $patient['blood_group'] ?></span></td></tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-borderless table-sm">
                            <tr><th width="40%">Phone:</th><td><?= sanitize($patient['phone'] ?? 'N/A') ?></td></tr>
                            <tr><th>Email:</th><td><?= sanitize($patient['email'] ?? 'N/A') ?></td></tr>
                            <tr><th>Address:</th><td><?= sanitize($patient['address'] ?? 'N/A') ?></td></tr>
                            <tr><th>Emergency:</th><td><?= sanitize($patient['emergency_contact_name'] ?? 'N/A') ?><br><small><?= sanitize($patient['emergency_contact_phone'] ?? '') ?></small></td></tr>
                            <tr><th>Insurance:</th><td><?= $patient['insurance_provider'] ? sanitize($patient['insurance_provider'] . ' (' . $patient['insurance_id'] . ')') : 'None' ?></td></tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">Latest Vitals</div>
            <div class="card-body">
                <?php if ($vitals): ?>
                <table class="table table-borderless table-sm">
                    <tr><th>BP:</th><td><?= sanitize($vitals['blood_pressure'] ?? 'N/A') ?></td></tr>
                    <tr><th>Temp:</th><td><?= $vitals['temperature'] ?? 'N/A' ?> &deg;C</td></tr>
                    <tr><th>Pulse:</th><td><?= $vitals['pulse'] ?? 'N/A' ?> bpm</td></tr>
                    <tr><th>Weight:</th><td><?= $vitals['weight'] ?? 'N/A' ?> kg</td></tr>
                    <tr><th>Height:</th><td><?= $vitals['height'] ?? 'N/A' ?> cm</td></tr>
                    <tr><th>SpO2:</th><td><?= $vitals['oxygen_saturation'] ?? 'N/A' ?>%</td></tr>
                </table>
                <small class="text-muted">Recorded: <?= formatDateTime($vitals['recorded_at']) ?></small>
                <?php else: ?>
                <p class="text-muted mb-0">No vitals recorded yet.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#visits">Visit History</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#billing">Billing</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#rxTab">Prescriptions</a></li>
</ul>

<div class="tab-content">
    <!-- Visits Tab -->
    <div class="tab-pane fade show active" id="visits">
        <div class="card">
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Date</th><th>Doctor</th><th>Diagnosis</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php if (empty($visits)): ?>
                        <tr><td colspan="4" class="text-center text-muted py-3">No visits recorded</td></tr>
                        <?php else: foreach ($visits as $v): ?>
                        <tr>
                            <td><?= formatDate($v['appointment_date']) ?><br><small class="text-muted"><?= date('h:i A', strtotime($v['appointment_time'])) ?></small></td>
                            <td><?= sanitize($v['doctor_name']) ?></td>
                            <td><?= sanitize($v['diagnosis'] ?? '-') ?></td>
                            <td><?= getStatusBadge($v['status']) ?></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Billing Tab -->
    <div class="tab-pane fade" id="billing">
        <div class="card">
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Invoice</th><th>Date</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php if (empty($bills)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-3">No invoices</td></tr>
                        <?php else: foreach ($bills as $b): ?>
                        <tr>
                            <td><a href="<?= BASE_URL ?>/modules/billing/view_invoice.php?id=<?= $b['id'] ?>"><?= sanitize($b['invoice_number']) ?></a></td>
                            <td><?= formatDate($b['invoice_date']) ?></td>
                            <td><?= formatCurrency($b['total_amount']) ?></td>
                            <td class="text-success"><?= formatCurrency($b['paid_amount']) ?></td>
                            <td class="text-danger"><?= formatCurrency($b['total_amount'] - $b['paid_amount']) ?></td>
                            <td><?= getStatusBadge($b['status']) ?></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Prescriptions Tab -->
    <div class="tab-pane fade" id="rxTab">
        <div class="card">
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Date</th><th>Doctor</th><th>Status</th><th>Items</th></tr></thead>
                    <tbody>
                        <?php if (empty($prescriptions)): ?>
                        <tr><td colspan="4" class="text-center text-muted py-3">No prescriptions</td></tr>
                        <?php else: foreach ($prescriptions as $rx): ?>
                        <?php
                        $items = $pdo->prepare("SELECT pi.*, m.name as med_name FROM prescription_items pi LEFT JOIN medicines m ON pi.medicine_id = m.id WHERE pi.prescription_id = ?");
                        $items->execute([$rx['id']]);
                        $rxItems = $items->fetchAll();
                        ?>
                        <tr>
                            <td><?= formatDate($rx['prescription_date']) ?></td>
                            <td><?= sanitize($rx['doctor_name']) ?></td>
                            <td><?= getStatusBadge($rx['status']) ?></td>
                            <td>
                                <?php foreach ($rxItems as $item): ?>
                                <small><?= sanitize($item['med_name'] ?? 'Unknown') ?> - <?= sanitize($item['dosage']) ?> (<?= sanitize($item['frequency']) ?>)</small><br>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
