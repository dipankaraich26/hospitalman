<?php
$pageTitle = 'Clinical Reports';
require_once __DIR__ . '/../../includes/header.php';
requireRole(['admin']);

$pdo = getDBConnection();
$from = $_GET['from'] ?? date('Y-01-01');
$to = $_GET['to'] ?? date('Y-m-d');

// KPIs
$totalAppts = countRows('appointments', 'appointment_date BETWEEN ? AND ?', [$from, $to]);
$completed = countRows('appointments', "appointment_date BETWEEN ? AND ? AND status='completed'", [$from, $to]);
$cancelled = countRows('appointments', "appointment_date BETWEEN ? AND ? AND status='cancelled'", [$from, $to]);
$totalConsult = countRows('consultations', 'created_at BETWEEN ? AND ?', [$from, $to]);
$totalLab = countRows('lab_tests', 'test_date BETWEEN ? AND ?', [$from, $to]);
$completedLab = countRows('lab_tests', "test_date BETWEEN ? AND ? AND status='completed'", [$from, $to]);
$completionRate = $totalAppts > 0 ? round(($completed / $totalAppts) * 100, 1) : 0;

// Appointments by Doctor
$byDoctor = $pdo->prepare("
    SELECT u.full_name, u.specialization,
           COUNT(a.id) as appointments,
           SUM(CASE WHEN a.status='completed' THEN 1 ELSE 0 END) as completed,
           COUNT(DISTINCT c.id) as consultations
    FROM users u
    LEFT JOIN appointments a ON u.id = a.doctor_id AND a.appointment_date BETWEEN ? AND ?
    LEFT JOIN consultations c ON u.id = c.doctor_id AND c.created_at BETWEEN ? AND ?
    WHERE u.role = 'doctor' AND u.status = 'active'
    GROUP BY u.id ORDER BY appointments DESC
");
$byDoctor->execute([$from, $to, $from, $to]);
$doctorData = $byDoctor->fetchAll();

// Daily Appointment Trend
$dailyAppts = $pdo->prepare("
    SELECT appointment_date as day, COUNT(*) as count
    FROM appointments WHERE appointment_date BETWEEN ? AND ?
    GROUP BY appointment_date ORDER BY appointment_date
");
$dailyAppts->execute([$from, $to]);
$dailyApptData = $dailyAppts->fetchAll();

// Appointments by Day of Week
$byDow = $pdo->prepare("
    SELECT DAYNAME(appointment_date) as dow, COUNT(*) as count
    FROM appointments WHERE appointment_date BETWEEN ? AND ?
    GROUP BY dow ORDER BY FIELD(dow, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday')
");
$byDow->execute([$from, $to]);
$dowData = $byDow->fetchAll();

// Appointments by Time Slot
$byTime = $pdo->prepare("
    SELECT
        CASE
            WHEN HOUR(appointment_time) BETWEEN 8 AND 10 THEN '8-10 AM'
            WHEN HOUR(appointment_time) BETWEEN 11 AND 12 THEN '11-12 PM'
            WHEN HOUR(appointment_time) BETWEEN 13 AND 15 THEN '1-3 PM'
            WHEN HOUR(appointment_time) BETWEEN 16 AND 18 THEN '4-6 PM'
            ELSE 'Other'
        END as time_slot, COUNT(*) as count
    FROM appointments WHERE appointment_date BETWEEN ? AND ?
    GROUP BY time_slot ORDER BY FIELD(time_slot, '8-10 AM','11-12 PM','1-3 PM','4-6 PM','Other')
");
$byTime->execute([$from, $to]);
$timeData = $byTime->fetchAll();

// Top Lab Tests
$topTests = $pdo->prepare("
    SELECT test_name, COUNT(*) as count,
           SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) as done
    FROM lab_tests WHERE test_date BETWEEN ? AND ?
    GROUP BY test_name ORDER BY count DESC LIMIT 10
");
$topTests->execute([$from, $to]);
$testData = $topTests->fetchAll();

// Monthly Consultation Trend
$monthlyConsult = $pdo->query("
    SELECT DATE_FORMAT(created_at, '%b') as label, COUNT(*) as count
    FROM consultations WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m') ORDER BY DATE_FORMAT(created_at, '%Y-%m')
")->fetchAll();

// ===== VITAL SIGNS ANALYTICS =====
$totalVitals = countRows('vitals', 'recorded_at BETWEEN ? AND ?', [$from, $to]);

// Average vitals in period
$avgVitals = $pdo->prepare("
    SELECT
        ROUND(AVG(temperature), 1) as avg_temp,
        ROUND(AVG(pulse), 0) as avg_pulse,
        ROUND(AVG(oxygen_saturation), 1) as avg_spo2,
        ROUND(AVG(weight), 1) as avg_weight,
        COUNT(*) as total_readings
    FROM vitals WHERE recorded_at BETWEEN ? AND ?
        AND temperature IS NOT NULL
");
$avgVitals->execute([$from, $to]);
$avgV = $avgVitals->fetch();

// Abnormal readings count
$abnormalTemp = countRows('vitals', 'recorded_at BETWEEN ? AND ? AND temperature > 38', [$from, $to]);
$abnormalPulse = countRows('vitals', 'recorded_at BETWEEN ? AND ? AND (pulse > 100 OR pulse < 60)', [$from, $to]);
$abnormalSpo2 = countRows('vitals', 'recorded_at BETWEEN ? AND ? AND oxygen_saturation < 95', [$from, $to]);
$abnormalBP = $pdo->prepare("
    SELECT COUNT(*) as cnt FROM vitals
    WHERE recorded_at BETWEEN ? AND ?
    AND blood_pressure IS NOT NULL
    AND (CAST(SUBSTRING_INDEX(blood_pressure, '/', 1) AS UNSIGNED) > 140
         OR CAST(SUBSTRING_INDEX(blood_pressure, '/', -1) AS UNSIGNED) > 90)
");
$abnormalBP->execute([$from, $to]);
$abnormalBPCount = (int) $abnormalBP->fetch()['cnt'];

// Monthly average temperature trend
$monthlyTemp = $pdo->query("
    SELECT DATE_FORMAT(recorded_at, '%b') as label,
           ROUND(AVG(temperature), 1) as avg_temp,
           ROUND(AVG(pulse), 0) as avg_pulse,
           ROUND(AVG(oxygen_saturation), 1) as avg_spo2
    FROM vitals WHERE recorded_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    AND temperature IS NOT NULL
    GROUP BY DATE_FORMAT(recorded_at, '%Y-%m')
    ORDER BY DATE_FORMAT(recorded_at, '%Y-%m')
")->fetchAll();

// Temperature distribution
$tempDistribution = $pdo->prepare("
    SELECT
        CASE
            WHEN temperature < 36 THEN 'Hypothermia (<36°C)'
            WHEN temperature BETWEEN 36 AND 37.5 THEN 'Normal (36-37.5°C)'
            WHEN temperature BETWEEN 37.6 AND 38.5 THEN 'Low Fever (37.6-38.5°C)'
            WHEN temperature > 38.5 THEN 'High Fever (>38.5°C)'
        END as temp_range,
        COUNT(*) as count
    FROM vitals WHERE recorded_at BETWEEN ? AND ? AND temperature IS NOT NULL
    GROUP BY temp_range
    ORDER BY FIELD(temp_range, 'Hypothermia (<36°C)', 'Normal (36-37.5°C)', 'Low Fever (37.6-38.5°C)', 'High Fever (>38.5°C)')
");
$tempDistribution->execute([$from, $to]);
$tempDist = $tempDistribution->fetchAll();

// SpO2 distribution
$spo2Distribution = $pdo->prepare("
    SELECT
        CASE
            WHEN oxygen_saturation >= 98 THEN 'Excellent (98-100%)'
            WHEN oxygen_saturation BETWEEN 95 AND 97 THEN 'Normal (95-97%)'
            WHEN oxygen_saturation BETWEEN 90 AND 94 THEN 'Low (90-94%)'
            WHEN oxygen_saturation < 90 THEN 'Critical (<90%)'
        END as spo2_range,
        COUNT(*) as count
    FROM vitals WHERE recorded_at BETWEEN ? AND ? AND oxygen_saturation IS NOT NULL
    GROUP BY spo2_range
    ORDER BY FIELD(spo2_range, 'Excellent (98-100%)', 'Normal (95-97%)', 'Low (90-94%)', 'Critical (<90%)')
");
$spo2Distribution->execute([$from, $to]);
$spo2Dist = $spo2Distribution->fetchAll();

// Patients with most abnormal readings
$abnormalPatients = $pdo->prepare("
    SELECT p.patient_id, p.first_name, p.last_name, p.id,
           COUNT(*) as abnormal_count,
           SUM(CASE WHEN v.temperature > 38 THEN 1 ELSE 0 END) as fever_count,
           SUM(CASE WHEN v.oxygen_saturation < 95 THEN 1 ELSE 0 END) as low_spo2_count,
           SUM(CASE WHEN v.pulse > 100 OR v.pulse < 60 THEN 1 ELSE 0 END) as abnormal_pulse_count
    FROM vitals v
    JOIN patients p ON v.patient_id = p.id
    WHERE v.recorded_at BETWEEN ? AND ?
    AND (v.temperature > 38 OR v.oxygen_saturation < 95 OR v.pulse > 100 OR v.pulse < 60)
    GROUP BY p.id
    ORDER BY abnormal_count DESC
    LIMIT 10
");
$abnormalPatients->execute([$from, $to]);
$abnormalPats = $abnormalPatients->fetchAll();
?>

<div class="page-header">
    <h4><i class="bi bi-clipboard2-pulse"></i> Clinical Reports</h4>
    <div class="no-print">
        <a href="export.php?type=clinical&from=<?= $from ?>&to=<?= $to ?>" class="btn btn-outline-success"><i class="bi bi-download"></i> Export CSV</a>
        <button onclick="window.print()" class="btn btn-outline-primary"><i class="bi bi-printer"></i> Print</button>
        <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Overview</a>
    </div>
</div>

<div class="card mb-4 no-print">
    <div class="card-body py-2">
        <form method="GET" class="row align-items-center g-2">
            <div class="col-auto"><label class="form-label mb-0">Period:</label></div>
            <div class="col-auto"><input type="date" name="from" class="form-control form-control-sm" value="<?= $from ?>"></div>
            <div class="col-auto">to</div>
            <div class="col-auto"><input type="date" name="to" class="form-control form-control-sm" value="<?= $to ?>"></div>
            <div class="col-auto"><button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-filter"></i> Apply</button></div>
        </form>
    </div>
</div>

<!-- KPIs -->
<div class="row mb-4">
    <div class="col-xl-2 col-md-4 mb-3"><div class="stat-card bg-gradient-primary"><div class="stat-value"><?= $totalAppts ?></div><div class="stat-label">Appointments</div></div></div>
    <div class="col-xl-2 col-md-4 mb-3"><div class="stat-card bg-gradient-success"><div class="stat-value"><?= $completionRate ?>%</div><div class="stat-label">Completion Rate</div></div></div>
    <div class="col-xl-2 col-md-4 mb-3"><div class="stat-card bg-gradient-warning"><div class="stat-value"><?= $cancelled ?></div><div class="stat-label">Cancelled</div></div></div>
    <div class="col-xl-2 col-md-4 mb-3"><div class="stat-card bg-gradient-info"><div class="stat-value"><?= $totalConsult ?></div><div class="stat-label">Consultations</div></div></div>
    <div class="col-xl-2 col-md-4 mb-3"><div class="stat-card bg-gradient-primary"><div class="stat-value"><?= $totalLab ?></div><div class="stat-label">Lab Tests</div></div></div>
    <div class="col-xl-2 col-md-4 mb-3"><div class="stat-card bg-gradient-success"><div class="stat-value"><?= $completedLab ?></div><div class="stat-label">Labs Done</div></div></div>
</div>

<!-- Charts -->
<div class="row mb-4">
    <div class="col-lg-8 mb-3">
        <div class="card"><div class="card-header">Consultation Trend (12 Months)</div>
            <div class="card-body"><canvas id="consultChart" height="100"></canvas></div>
        </div>
    </div>
    <div class="col-lg-4 mb-3">
        <div class="card"><div class="card-header">Appointments by Day of Week</div>
            <div class="card-body"><canvas id="dowChart" height="200"></canvas></div>
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-lg-4 mb-3">
        <div class="card"><div class="card-header">Peak Hours</div>
            <div class="card-body"><canvas id="timeChart" height="220"></canvas></div>
        </div>
    </div>
    <!-- Doctor Performance -->
    <div class="col-lg-8 mb-3">
        <div class="card">
            <div class="card-header"><i class="bi bi-person-badge"></i> Doctor Performance</div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Doctor</th><th>Specialization</th><th>Appointments</th><th>Completed</th><th>Rate</th><th>Consultations</th></tr></thead>
                    <tbody>
                        <?php foreach ($doctorData as $doc): ?>
                        <?php $rate = $doc['appointments'] > 0 ? round(($doc['completed'] / $doc['appointments']) * 100) : 0; ?>
                        <tr>
                            <td><strong><?= sanitize($doc['full_name']) ?></strong></td>
                            <td><small><?= sanitize($doc['specialization'] ?? '-') ?></small></td>
                            <td><?= $doc['appointments'] ?></td>
                            <td><?= $doc['completed'] ?></td>
                            <td>
                                <div class="progress" style="height:18px; min-width:60px;">
                                    <div class="progress-bar bg-<?= $rate >= 80 ? 'success' : ($rate >= 50 ? 'warning' : 'danger') ?>" style="width:<?= $rate ?>%"><?= $rate ?>%</div>
                                </div>
                            </td>
                            <td><span class="badge bg-primary"><?= $doc['consultations'] ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ===== VITAL SIGNS ANALYTICS ===== -->
<h5 class="mt-4 mb-3"><i class="bi bi-activity"></i> Vital Signs Analytics</h5>

<!-- Vital KPIs -->
<div class="row mb-3">
    <div class="col-md-3 mb-2"><div class="card text-center border-primary"><div class="card-body py-3"><h4 class="mb-0 text-primary"><?= $totalVitals ?></h4><small class="text-muted">Total Readings</small></div></div></div>
    <div class="col-md-3 mb-2"><div class="card text-center border-danger"><div class="card-body py-3"><h4 class="mb-0 text-danger"><?= $abnormalTemp ?></h4><small class="text-muted">Fever Cases (>38°C)</small></div></div></div>
    <div class="col-md-3 mb-2"><div class="card text-center border-warning"><div class="card-body py-3"><h4 class="mb-0 text-warning"><?= $abnormalPulse ?></h4><small class="text-muted">Abnormal Pulse</small></div></div></div>
    <div class="col-md-3 mb-2"><div class="card text-center border-danger"><div class="card-body py-3"><h4 class="mb-0 text-danger"><?= $abnormalSpo2 ?></h4><small class="text-muted">Low SpO2 (<95%)</small></div></div></div>
</div>

<!-- Average Vital Stats -->
<?php if ($avgV && $avgV['total_readings'] > 0): ?>
<div class="row mb-4">
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stat-card bg-gradient-info">
            <div class="stat-value"><?= $avgV['avg_temp'] ?? '-' ?>&deg;C</div>
            <div class="stat-label">Avg Temperature</div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stat-card bg-gradient-primary">
            <div class="stat-value"><?= $avgV['avg_pulse'] ?? '-' ?> bpm</div>
            <div class="stat-label">Avg Pulse Rate</div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stat-card bg-gradient-success">
            <div class="stat-value"><?= $avgV['avg_spo2'] ?? '-' ?>%</div>
            <div class="stat-label">Avg SpO2</div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stat-card bg-gradient-warning">
            <div class="stat-value"><?= $abnormalBPCount ?></div>
            <div class="stat-label">High BP Readings</div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Vital Trend Charts -->
<div class="row mb-4">
    <div class="col-lg-8 mb-3">
        <div class="card">
            <div class="card-header">Monthly Vital Signs Averages (12 Months)</div>
            <div class="card-body"><canvas id="vitalTrendChart" height="120"></canvas></div>
        </div>
    </div>
    <div class="col-lg-4 mb-3">
        <div class="card">
            <div class="card-header">Temperature Distribution</div>
            <div class="card-body"><canvas id="tempDistChart" height="200"></canvas></div>
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-lg-4 mb-3">
        <div class="card">
            <div class="card-header">SpO2 Distribution</div>
            <div class="card-body"><canvas id="spo2DistChart" height="220"></canvas></div>
        </div>
    </div>
    <div class="col-lg-8 mb-3">
        <div class="card">
            <div class="card-header"><i class="bi bi-exclamation-triangle text-warning"></i> Patients with Most Abnormal Readings</div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Patient</th><th>ID</th><th>Total Abnormal</th><th>Fever</th><th>Low SpO2</th><th>Abnormal Pulse</th><th>Action</th></tr></thead>
                    <tbody>
                        <?php foreach ($abnormalPats as $ap): ?>
                        <tr>
                            <td><strong><?= sanitize($ap['first_name'] . ' ' . $ap['last_name']) ?></strong></td>
                            <td><?= sanitize($ap['patient_id']) ?></td>
                            <td><span class="badge bg-danger"><?= $ap['abnormal_count'] ?></span></td>
                            <td class="text-danger"><?= $ap['fever_count'] ?></td>
                            <td class="text-warning"><?= $ap['low_spo2_count'] ?></td>
                            <td><?= $ap['abnormal_pulse_count'] ?></td>
                            <td><a href="<?= BASE_URL ?>/modules/clinical/vital_trends.php?patient_id=<?= $ap['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-activity"></i> Trends</a></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($abnormalPats)): ?><tr><td colspan="7" class="text-muted text-center py-3">No abnormal readings</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Top Lab Tests -->
<div class="card">
    <div class="card-header"><i class="bi bi-clipboard2-data"></i> Most Ordered Lab Tests</div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead><tr><th>#</th><th>Test Name</th><th>Ordered</th><th>Completed</th><th>Pending</th></tr></thead>
            <tbody>
                <?php foreach ($testData as $i => $t): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><strong><?= sanitize($t['test_name']) ?></strong></td>
                    <td><?= $t['count'] ?></td>
                    <td class="text-success"><?= $t['done'] ?></td>
                    <td class="text-warning"><?= $t['count'] - $t['done'] ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($testData)): ?><tr><td colspan="5" class="text-muted text-center py-3">No data</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    new Chart(document.getElementById('consultChart'), {
        type: 'bar', data: {
            labels: <?= json_encode(array_column($monthlyConsult, 'label')) ?>,
            datasets: [{ label: 'Consultations', data: <?= json_encode(array_column($monthlyConsult, 'count')) ?>,
                backgroundColor: 'rgba(79,172,254,0.7)', borderRadius: 6 }]
        }, options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
    });

    new Chart(document.getElementById('dowChart'), {
        type: 'radar', data: {
            labels: <?= json_encode(array_column($dowData, 'dow')) ?>,
            datasets: [{ label: 'Appointments', data: <?= json_encode(array_column($dowData, 'count')) ?>,
                borderColor: '#667eea', backgroundColor: 'rgba(102,126,234,0.2)', pointBackgroundColor: '#667eea' }]
        }, options: { responsive: true, plugins: { legend: { display: false } }, scales: { r: { beginAtZero: true } } }
    });

    new Chart(document.getElementById('timeChart'), {
        type: 'bar', data: {
            labels: <?= json_encode(array_column($timeData, 'time_slot')) ?>,
            datasets: [{ label: 'Appointments', data: <?= json_encode(array_column($timeData, 'count')) ?>,
                backgroundColor: ['#667eea','#38ef7d','#f5576c','#4facfe','#ccc'], borderRadius: 6 }]
        }, options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
    });

    // Vital Signs Monthly Trend
    <?php if (!empty($monthlyTemp)): ?>
    new Chart(document.getElementById('vitalTrendChart'), {
        type: 'line', data: {
            labels: <?= json_encode(array_column($monthlyTemp, 'label')) ?>,
            datasets: [
                { label: 'Avg Temp (°C)', data: <?= json_encode(array_column($monthlyTemp, 'avg_temp')) ?>,
                    borderColor: '#f5576c', backgroundColor: 'rgba(245,87,108,0.05)', fill: false, tension: 0.3, yAxisID: 'y' },
                { label: 'Avg Pulse (bpm)', data: <?= json_encode(array_column($monthlyTemp, 'avg_pulse')) ?>,
                    borderColor: '#667eea', backgroundColor: 'rgba(102,126,234,0.05)', fill: false, tension: 0.3, yAxisID: 'y1' },
                { label: 'Avg SpO2 (%)', data: <?= json_encode(array_column($monthlyTemp, 'avg_spo2')) ?>,
                    borderColor: '#38ef7d', backgroundColor: 'rgba(56,239,125,0.05)', fill: false, tension: 0.3, yAxisID: 'y2' }
            ]
        }, options: {
            responsive: true, interaction: { mode: 'index', intersect: false },
            scales: {
                y: { position: 'left', title: { display: true, text: '°C' }, min: 35, max: 40 },
                y1: { position: 'right', title: { display: true, text: 'bpm' }, grid: { drawOnChartArea: false } },
                y2: { display: false }
            }
        }
    });
    <?php endif; ?>

    // Temperature Distribution
    <?php if (!empty($tempDist)): ?>
    new Chart(document.getElementById('tempDistChart'), {
        type: 'doughnut', data: {
            labels: <?= json_encode(array_column($tempDist, 'temp_range')) ?>,
            datasets: [{ data: <?= json_encode(array_column($tempDist, 'count')) ?>,
                backgroundColor: ['#4facfe','#38ef7d','#ffd93d','#f5576c'] }]
        }, options: { responsive: true, plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } } }
    });
    <?php endif; ?>

    // SpO2 Distribution
    <?php if (!empty($spo2Dist)): ?>
    new Chart(document.getElementById('spo2DistChart'), {
        type: 'doughnut', data: {
            labels: <?= json_encode(array_column($spo2Dist, 'spo2_range')) ?>,
            datasets: [{ data: <?= json_encode(array_column($spo2Dist, 'count')) ?>,
                backgroundColor: ['#38ef7d','#4facfe','#ffd93d','#f5576c'] }]
        }, options: { responsive: true, plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } } }
    });
    <?php endif; ?>
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
