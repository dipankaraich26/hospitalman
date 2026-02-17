<?php
$pageTitle = 'Patient Reports';
require_once __DIR__ . '/../../includes/header.php';
requireRole(['admin']);

$pdo = getDBConnection();
$from = $_GET['from'] ?? date('Y-01-01');
$to = $_GET['to'] ?? date('Y-m-d');

// Total patients
$total = countRows('patients');
$newInPeriod = countRows('patients', 'created_at BETWEEN ? AND ?', [$from, $to]);

// Gender distribution
$genderData = $pdo->query("SELECT gender, COUNT(*) as count FROM patients GROUP BY gender")->fetchAll();

// Age distribution
$ageData = $pdo->query("
    SELECT
        CASE
            WHEN TIMESTAMPDIFF(YEAR, dob, CURDATE()) < 18 THEN '0-17'
            WHEN TIMESTAMPDIFF(YEAR, dob, CURDATE()) BETWEEN 18 AND 30 THEN '18-30'
            WHEN TIMESTAMPDIFF(YEAR, dob, CURDATE()) BETWEEN 31 AND 45 THEN '31-45'
            WHEN TIMESTAMPDIFF(YEAR, dob, CURDATE()) BETWEEN 46 AND 60 THEN '46-60'
            WHEN TIMESTAMPDIFF(YEAR, dob, CURDATE()) > 60 THEN '60+'
            ELSE 'Unknown'
        END as age_group,
        COUNT(*) as count
    FROM patients
    WHERE dob IS NOT NULL
    GROUP BY age_group
    ORDER BY FIELD(age_group, '0-17','18-30','31-45','46-60','60+','Unknown')
")->fetchAll();

// Blood group distribution
$bloodData = $pdo->query("SELECT blood_group, COUNT(*) as count FROM patients WHERE blood_group != 'Unknown' GROUP BY blood_group ORDER BY count DESC")->fetchAll();

// Monthly registration trend
$regTrend = $pdo->query("
    SELECT DATE_FORMAT(created_at, '%Y-%m') as month, DATE_FORMAT(created_at, '%b %Y') as label, COUNT(*) as count
    FROM patients WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY month ORDER BY month
")->fetchAll();

// Insurance coverage
$insured = countRows('patients', 'insurance_provider IS NOT NULL AND insurance_provider != ""');
$uninsured = $total - $insured;

// Top insurance providers
$topIns = $pdo->query("
    SELECT insurance_provider, COUNT(*) as count FROM patients
    WHERE insurance_provider IS NOT NULL AND insurance_provider != ''
    GROUP BY insurance_provider ORDER BY count DESC LIMIT 10
")->fetchAll();

// Patients with most visits
$frequentPatients = $pdo->prepare("
    SELECT p.patient_id, p.first_name, p.last_name,
           COUNT(a.id) as visits,
           MAX(a.appointment_date) as last_visit
    FROM patients p
    JOIN appointments a ON p.id = a.patient_id
    WHERE a.appointment_date BETWEEN ? AND ?
    GROUP BY p.id ORDER BY visits DESC LIMIT 10
");
$frequentPatients->execute([$from, $to]);
$freqPats = $frequentPatients->fetchAll();

// Top diagnoses
$topDiag = $pdo->prepare("
    SELECT diagnosis, COUNT(*) as count FROM consultations
    WHERE created_at BETWEEN ? AND ? AND diagnosis IS NOT NULL AND diagnosis != ''
    GROUP BY diagnosis ORDER BY count DESC LIMIT 10
");
$topDiag->execute([$from, $to]);
$diagnoses = $topDiag->fetchAll();
?>

<div class="page-header">
    <h4><i class="bi bi-people"></i> Patient Reports</h4>
    <div class="no-print">
        <a href="export.php?type=patients" class="btn btn-outline-success"><i class="bi bi-download"></i> Export CSV</a>
        <button onclick="window.print()" class="btn btn-outline-primary"><i class="bi bi-printer"></i> Print</button>
        <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Overview</a>
    </div>
</div>

<!-- Filter -->
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

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-3 mb-3"><div class="stat-card bg-gradient-primary"><div class="stat-value"><?= $total ?></div><div class="stat-label">Total Patients</div></div></div>
    <div class="col-md-3 mb-3"><div class="stat-card bg-gradient-success"><div class="stat-value"><?= $newInPeriod ?></div><div class="stat-label">New in Period</div></div></div>
    <div class="col-md-3 mb-3"><div class="stat-card bg-gradient-info"><div class="stat-value"><?= $insured ?></div><div class="stat-label">Insured</div></div></div>
    <div class="col-md-3 mb-3"><div class="stat-card bg-gradient-warning"><div class="stat-value"><?= $uninsured ?></div><div class="stat-label">Uninsured</div></div></div>
</div>

<!-- Charts -->
<div class="row mb-4">
    <div class="col-lg-8 mb-3">
        <div class="card"><div class="card-header">Monthly Registration Trend</div>
            <div class="card-body"><canvas id="regChart" height="100"></canvas></div>
        </div>
    </div>
    <div class="col-lg-4 mb-3">
        <div class="card"><div class="card-header">Gender Distribution</div>
            <div class="card-body"><canvas id="genderChart" height="200"></canvas></div>
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-lg-4 mb-3">
        <div class="card"><div class="card-header">Age Distribution</div>
            <div class="card-body"><canvas id="ageChart" height="220"></canvas></div>
        </div>
    </div>
    <div class="col-lg-4 mb-3">
        <div class="card"><div class="card-header">Blood Group Distribution</div>
            <div class="card-body"><canvas id="bloodChart" height="220"></canvas></div>
        </div>
    </div>
    <div class="col-lg-4 mb-3">
        <div class="card"><div class="card-header">Insurance Coverage</div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Provider</th><th>Patients</th></tr></thead>
                    <tbody>
                        <?php foreach ($topIns as $ins): ?>
                        <tr><td><?= sanitize($ins['insurance_provider']) ?></td><td><span class="badge bg-info"><?= $ins['count'] ?></span></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Tables -->
<div class="row">
    <div class="col-lg-6 mb-3">
        <div class="card">
            <div class="card-header"><i class="bi bi-arrow-repeat"></i> Most Frequent Visitors</div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Patient</th><th>ID</th><th>Visits</th><th>Last Visit</th></tr></thead>
                    <tbody>
                        <?php foreach ($freqPats as $fp): ?>
                        <tr>
                            <td><strong><?= sanitize($fp['first_name'] . ' ' . $fp['last_name']) ?></strong></td>
                            <td><?= sanitize($fp['patient_id']) ?></td>
                            <td><span class="badge bg-primary"><?= $fp['visits'] ?></span></td>
                            <td><?= formatDate($fp['last_visit']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-6 mb-3">
        <div class="card">
            <div class="card-header"><i class="bi bi-clipboard2-data"></i> Top 10 Diagnoses</div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead><tr><th>#</th><th>Diagnosis</th><th>Cases</th></tr></thead>
                    <tbody>
                        <?php foreach ($diagnoses as $i => $d): ?>
                        <tr><td><?= $i + 1 ?></td><td><?= sanitize($d['diagnosis']) ?></td><td><span class="badge bg-warning text-dark"><?= $d['count'] ?></span></td></tr>
                        <?php endforeach; ?>
                        <?php if (empty($diagnoses)): ?><tr><td colspan="3" class="text-muted text-center py-3">No data</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    new Chart(document.getElementById('regChart'), {
        type: 'line', data: {
            labels: <?= json_encode(array_column($regTrend, 'label')) ?>,
            datasets: [{ label: 'New Patients', data: <?= json_encode(array_column($regTrend, 'count')) ?>,
                borderColor: '#667eea', backgroundColor: 'rgba(102,126,234,0.1)', fill: true, tension: 0.3 }]
        }, options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
    });

    new Chart(document.getElementById('genderChart'), {
        type: 'doughnut', data: {
            labels: <?= json_encode(array_column($genderData, 'gender')) ?>,
            datasets: [{ data: <?= json_encode(array_column($genderData, 'count')) ?>, backgroundColor: ['#4facfe','#f093fb','#ffd93d'] }]
        }, options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
    });

    new Chart(document.getElementById('ageChart'), {
        type: 'bar', data: {
            labels: <?= json_encode(array_column($ageData, 'age_group')) ?>,
            datasets: [{ label: 'Patients', data: <?= json_encode(array_column($ageData, 'count')) ?>,
                backgroundColor: ['#667eea','#38ef7d','#4facfe','#f093fb','#ffd93d','#ccc'], borderRadius: 6 }]
        }, options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
    });

    new Chart(document.getElementById('bloodChart'), {
        type: 'polarArea', data: {
            labels: <?= json_encode(array_column($bloodData, 'blood_group')) ?>,
            datasets: [{ data: <?= json_encode(array_column($bloodData, 'count')) ?>,
                backgroundColor: ['#f5576c','#667eea','#38ef7d','#4facfe','#f093fb','#ffd93d','#ff6b6b','#48dbfb'] }]
        }, options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
