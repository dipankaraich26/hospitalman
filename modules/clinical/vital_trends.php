<?php
$pageTitle = 'Vital Signs Trends';
require_once __DIR__ . '/../../includes/header.php';
requireRole(['admin', 'doctor', 'nurse']);

$pdo = getDBConnection();
$patient_id = (int) ($_GET['patient_id'] ?? 0);
$patient = $patient_id ? getPatientById($patient_id) : null;

$patients = $pdo->query("SELECT id, patient_id, first_name, last_name FROM patients ORDER BY first_name")->fetchAll();

// Fetch all vitals for selected patient (chronological order for charts)
$vitalsData = [];
if ($patient) {
    $stmt = $pdo->prepare("
        SELECT v.*, u.full_name as recorder
        FROM vitals v
        JOIN users u ON v.recorded_by = u.id
        WHERE v.patient_id = ?
        ORDER BY v.recorded_at ASC
    ");
    $stmt->execute([$patient_id]);
    $vitalsData = $stmt->fetchAll();
}

// Compute stats
$stats = [];
if (!empty($vitalsData)) {
    $temps = array_filter(array_column($vitalsData, 'temperature'), fn($v) => $v !== null && $v > 0);
    $pulses = array_filter(array_column($vitalsData, 'pulse'), fn($v) => $v !== null && $v > 0);
    $spo2s = array_filter(array_column($vitalsData, 'oxygen_saturation'), fn($v) => $v !== null && $v > 0);
    $weights = array_filter(array_column($vitalsData, 'weight'), fn($v) => $v !== null && $v > 0);

    if (!empty($temps)) {
        $stats['temperature'] = ['min' => min($temps), 'max' => max($temps), 'avg' => round(array_sum($temps) / count($temps), 1), 'count' => count($temps)];
    }
    if (!empty($pulses)) {
        $stats['pulse'] = ['min' => min($pulses), 'max' => max($pulses), 'avg' => round(array_sum($pulses) / count($pulses), 0), 'count' => count($pulses)];
    }
    if (!empty($spo2s)) {
        $stats['spo2'] = ['min' => min($spo2s), 'max' => max($spo2s), 'avg' => round(array_sum($spo2s) / count($spo2s), 1), 'count' => count($spo2s)];
    }
    if (!empty($weights)) {
        $stats['weight'] = ['min' => min($weights), 'max' => max($weights), 'avg' => round(array_sum($weights) / count($weights), 1), 'count' => count($weights)];
    }
}

// Prepare chart data arrays
$chartLabels = [];
$tempData = [];
$pulseData = [];
$spo2Data = [];
$weightData = [];
$systolicData = [];
$diastolicData = [];

foreach ($vitalsData as $v) {
    $chartLabels[] = date('d M Y H:i', strtotime($v['recorded_at']));
    $tempData[] = $v['temperature'] ?: null;
    $pulseData[] = $v['pulse'] ?: null;
    $spo2Data[] = $v['oxygen_saturation'] ?: null;
    $weightData[] = $v['weight'] ?: null;

    // Parse BP (e.g., "120/80")
    if (!empty($v['blood_pressure']) && strpos($v['blood_pressure'], '/') !== false) {
        [$sys, $dia] = explode('/', $v['blood_pressure']);
        $systolicData[] = (int) trim($sys);
        $diastolicData[] = (int) trim($dia);
    } else {
        $systolicData[] = null;
        $diastolicData[] = null;
    }
}
?>

<div class="page-header">
    <h4><i class="bi bi-activity"></i> Vital Signs Trends</h4>
    <a href="medical_records.php<?= $patient_id ? '?patient_id=' . $patient_id : '' ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Medical Records</a>
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
<div class="alert alert-light border">
    <strong><?= sanitize($patient['first_name'] . ' ' . $patient['last_name']) ?></strong> (<?= sanitize($patient['patient_id']) ?>)
    | Gender: <?= $patient['gender'] ?> | Blood: <span class="badge bg-danger"><?= $patient['blood_group'] ?></span>
    | DOB: <?= $patient['dob'] ? formatDate($patient['dob']) : 'N/A' ?>
    | Readings: <span class="badge bg-primary"><?= count($vitalsData) ?></span>
</div>

<?php if (empty($vitalsData)): ?>
<div class="alert alert-info"><i class="bi bi-info-circle"></i> No vital signs recorded for this patient yet.</div>
<?php else: ?>

<!-- Stats Summary Cards -->
<div class="row mb-4">
    <?php if (isset($stats['temperature'])): ?>
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card border-<?= $stats['temperature']['max'] > 38 ? 'danger' : 'success' ?>">
            <div class="card-body text-center">
                <h6 class="text-muted mb-1"><i class="bi bi-thermometer-half"></i> Temperature</h6>
                <h3 class="mb-1"><?= $stats['temperature']['avg'] ?>&deg;C</h3>
                <small class="text-muted">Min: <?= $stats['temperature']['min'] ?>&deg;C | Max: <?= $stats['temperature']['max'] ?>&deg;C</small>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php if (isset($stats['pulse'])): ?>
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card border-<?= $stats['pulse']['avg'] > 100 ? 'warning' : 'success' ?>">
            <div class="card-body text-center">
                <h6 class="text-muted mb-1"><i class="bi bi-heart-pulse"></i> Pulse Rate</h6>
                <h3 class="mb-1"><?= $stats['pulse']['avg'] ?> bpm</h3>
                <small class="text-muted">Min: <?= $stats['pulse']['min'] ?> | Max: <?= $stats['pulse']['max'] ?></small>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php if (isset($stats['spo2'])): ?>
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card border-<?= $stats['spo2']['min'] < 95 ? 'danger' : 'success' ?>">
            <div class="card-body text-center">
                <h6 class="text-muted mb-1"><i class="bi bi-lungs"></i> SpO2</h6>
                <h3 class="mb-1"><?= $stats['spo2']['avg'] ?>%</h3>
                <small class="text-muted">Min: <?= $stats['spo2']['min'] ?>% | Max: <?= $stats['spo2']['max'] ?>%</small>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php if (isset($stats['weight'])): ?>
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card border-info">
            <div class="card-body text-center">
                <h6 class="text-muted mb-1"><i class="bi bi-speedometer"></i> Weight</h6>
                <h3 class="mb-1"><?= $stats['weight']['avg'] ?> kg</h3>
                <small class="text-muted">Min: <?= $stats['weight']['min'] ?> | Max: <?= $stats['weight']['max'] ?></small>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Blood Pressure Chart -->
<div class="row mb-4">
    <div class="col-lg-6 mb-3">
        <div class="card">
            <div class="card-header"><i class="bi bi-heart-pulse"></i> Blood Pressure Trend</div>
            <div class="card-body"><canvas id="bpChart" height="180"></canvas></div>
        </div>
    </div>
    <div class="col-lg-6 mb-3">
        <div class="card">
            <div class="card-header"><i class="bi bi-thermometer-half"></i> Temperature Trend</div>
            <div class="card-body"><canvas id="tempChart" height="180"></canvas></div>
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-lg-6 mb-3">
        <div class="card">
            <div class="card-header"><i class="bi bi-lungs"></i> SpO2 Trend</div>
            <div class="card-body"><canvas id="spo2Chart" height="180"></canvas></div>
        </div>
    </div>
    <div class="col-lg-6 mb-3">
        <div class="card">
            <div class="card-header"><i class="bi bi-heart"></i> Pulse Rate Trend</div>
            <div class="card-body"><canvas id="pulseChart" height="180"></canvas></div>
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-lg-6 mb-3">
        <div class="card">
            <div class="card-header"><i class="bi bi-speedometer"></i> Weight Trend</div>
            <div class="card-body"><canvas id="weightChart" height="180"></canvas></div>
        </div>
    </div>
    <div class="col-lg-6 mb-3">
        <div class="card">
            <div class="card-header"><i class="bi bi-table"></i> All Readings</div>
            <div class="card-body p-0" style="max-height:350px; overflow-y:auto;">
                <table class="table table-hover table-sm mb-0">
                    <thead class="sticky-top bg-white"><tr><th>Date</th><th>BP</th><th>Temp</th><th>Pulse</th><th>SpO2</th><th>Weight</th></tr></thead>
                    <tbody>
                        <?php foreach (array_reverse($vitalsData) as $v): ?>
                        <tr>
                            <td><small><?= date('d M Y H:i', strtotime($v['recorded_at'])) ?></small></td>
                            <td><?= sanitize($v['blood_pressure'] ?? '-') ?></td>
                            <td class="<?= ($v['temperature'] ?? 0) > 38 ? 'text-danger fw-bold' : '' ?>"><?= $v['temperature'] ?? '-' ?></td>
                            <td class="<?= ($v['pulse'] ?? 0) > 100 ? 'text-warning fw-bold' : '' ?>"><?= $v['pulse'] ?? '-' ?></td>
                            <td class="<?= ($v['oxygen_saturation'] ?? 100) < 95 ? 'text-danger fw-bold' : '' ?>"><?= $v['oxygen_saturation'] ?? '-' ?></td>
                            <td><?= $v['weight'] ?? '-' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var labels = <?= json_encode($chartLabels) ?>;
    var chartOpts = {
        responsive: true,
        plugins: { legend: { display: true, position: 'top' } },
        scales: { x: { display: true, ticks: { maxRotation: 45, maxTicksLimit: 10, font: { size: 10 } } }, y: { beginAtZero: false } },
        elements: { point: { radius: 4, hoverRadius: 6 }, line: { tension: 0.3 } }
    };

    // Blood Pressure
    new Chart(document.getElementById('bpChart'), {
        type: 'line', data: {
            labels: labels,
            datasets: [
                { label: 'Systolic', data: <?= json_encode($systolicData) ?>, borderColor: '#f5576c', backgroundColor: 'rgba(245,87,108,0.1)', fill: false },
                { label: 'Diastolic', data: <?= json_encode($diastolicData) ?>, borderColor: '#4facfe', backgroundColor: 'rgba(79,172,254,0.1)', fill: false }
            ]
        }, options: {
            ...chartOpts,
            plugins: {
                ...chartOpts.plugins,
                annotation: {
                    annotations: {
                        normalHigh: { type: 'line', yMin: 140, yMax: 140, borderColor: 'rgba(245,87,108,0.3)', borderDash: [5,5], borderWidth: 1 },
                        normalLow: { type: 'line', yMin: 90, yMax: 90, borderColor: 'rgba(79,172,254,0.3)', borderDash: [5,5], borderWidth: 1 }
                    }
                }
            }
        }
    });

    // Temperature
    new Chart(document.getElementById('tempChart'), {
        type: 'line', data: {
            labels: labels,
            datasets: [{
                label: 'Temperature (°C)', data: <?= json_encode($tempData) ?>,
                borderColor: '#f5576c', backgroundColor: 'rgba(245,87,108,0.1)', fill: true
            }]
        }, options: {
            ...chartOpts,
            scales: { ...chartOpts.scales, y: { min: 35, max: 42, title: { display: true, text: '°C' } } }
        }
    });

    // SpO2
    new Chart(document.getElementById('spo2Chart'), {
        type: 'line', data: {
            labels: labels,
            datasets: [{
                label: 'SpO2 (%)', data: <?= json_encode($spo2Data) ?>,
                borderColor: '#38ef7d', backgroundColor: 'rgba(56,239,125,0.1)', fill: true
            }]
        }, options: {
            ...chartOpts,
            scales: { ...chartOpts.scales, y: { min: 85, max: 100, title: { display: true, text: '%' } } }
        }
    });

    // Pulse
    new Chart(document.getElementById('pulseChart'), {
        type: 'line', data: {
            labels: labels,
            datasets: [{
                label: 'Pulse (bpm)', data: <?= json_encode($pulseData) ?>,
                borderColor: '#667eea', backgroundColor: 'rgba(102,126,234,0.1)', fill: true
            }]
        }, options: {
            ...chartOpts,
            scales: { ...chartOpts.scales, y: { beginAtZero: false, title: { display: true, text: 'bpm' } } }
        }
    });

    // Weight
    new Chart(document.getElementById('weightChart'), {
        type: 'line', data: {
            labels: labels,
            datasets: [{
                label: 'Weight (kg)', data: <?= json_encode($weightData) ?>,
                borderColor: '#ffd93d', backgroundColor: 'rgba(255,217,61,0.1)', fill: true
            }]
        }, options: {
            ...chartOpts,
            scales: { ...chartOpts.scales, y: { beginAtZero: false, title: { display: true, text: 'kg' } } }
        }
    });
});
</script>

<?php endif; ?>
<?php elseif ($patient_id): ?>
<div class="alert alert-danger">Patient not found.</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
