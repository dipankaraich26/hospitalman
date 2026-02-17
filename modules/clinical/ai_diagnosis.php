<?php
// Initialize auth and functions
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/ai_diagnosis.php';
requireLogin();
requireRole(['admin', 'doctor']);

$pdo = getDBConnection();

// Handle diagnosis request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'diagnose') {
    try {
        $patientId = (int) $_POST['patient_id'];
        $symptomIds = isset($_POST['symptoms']) ? array_map('intval', $_POST['symptoms']) : [];

        // Validation
        if (empty($patientId)) {
            $_SESSION['error'] = 'Please select a patient';
            header('Location: ai_diagnosis.php');
            exit;
        }

        if (empty($symptomIds)) {
            $_SESSION['error'] = 'Please select at least one symptom';
            header('Location: ai_diagnosis.php?patient=' . $patientId);
            exit;
        }

        $vitalSigns = [
            'temperature' => !empty($_POST['temperature']) ? (float) $_POST['temperature'] : null,
            'blood_pressure_systolic' => !empty($_POST['bp_systolic']) ? (int) $_POST['bp_systolic'] : null,
            'blood_pressure_diastolic' => !empty($_POST['bp_diastolic']) ? (int) $_POST['bp_diastolic'] : null,
            'heart_rate' => !empty($_POST['heart_rate']) ? (int) $_POST['heart_rate'] : null,
            'respiratory_rate' => !empty($_POST['respiratory_rate']) ? (int) $_POST['respiratory_rate'] : null,
            'oxygen_saturation' => !empty($_POST['oxygen_saturation']) ? (int) $_POST['oxygen_saturation'] : null,
        ];

        $aiSuggestions = diagnoseFromSymptoms($pdo, $symptomIds, $vitalSigns, $patientId);
        $similarCases = findSimilarCases($pdo, $symptomIds, 5);

        // Save diagnosis record
        $recordId = saveDiagnosisRecord(
            $pdo,
            $patientId,
            $_SESSION['user_id'],
            $symptomIds,
            $vitalSigns,
            $aiSuggestions
        );

        $_SESSION['diagnosis_result'] = [
            'suggestions' => $aiSuggestions,
            'similar_cases' => $similarCases,
            'record_id' => $recordId
        ];

        header('Location: ai_diagnosis.php?patient=' . $patientId);
        exit;

    } catch (Exception $e) {
        $_SESSION['error'] = 'Error analyzing symptoms: ' . $e->getMessage();
        header('Location: ai_diagnosis.php');
        exit;
    }
}

// Handle save final diagnosis
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_diagnosis') {
    $recordId = (int) $_POST['record_id'];
    $diagnosis = trim($_POST['diagnosis']);
    $notes = trim($_POST['notes'] ?? '');

    $stmt = $pdo->prepare("
        UPDATE ai_diagnosis_records
        SET doctor_diagnosis = ?, notes = ?
        WHERE id = ?
    ");
    $stmt->execute([$diagnosis, $notes, $recordId]);

    $_SESSION['success'] = 'Diagnosis saved successfully';
    header('Location: ai_diagnosis.php');
    exit;
}

require_once __DIR__ . '/../../includes/header.php';

// Get patient if specified
$selectedPatient = null;
if (isset($_GET['patient'])) {
    $stmt = $pdo->prepare("SELECT * FROM patients WHERE id = ?");
    $stmt->execute([$_GET['patient']]);
    $selectedPatient = $stmt->fetch();
}

// Get all patients for dropdown
$patients = $pdo->query("
    SELECT
        id,
        first_name,
        last_name,
        TIMESTAMPDIFF(YEAR, dob, CURDATE()) as age,
        gender
    FROM patients
    ORDER BY first_name, last_name
")->fetchAll();

// Get all symptoms grouped by category
$symptoms = $pdo->query("
    SELECT *
    FROM symptoms
    WHERE is_active = 1
    ORDER BY category, name
")->fetchAll();

$symptomsByCategory = [];
foreach ($symptoms as $symptom) {
    $symptomsByCategory[$symptom['category']][] = $symptom;
}

// Get diagnosis result if available
$diagnosisResult = $_SESSION['diagnosis_result'] ?? null;
unset($_SESSION['diagnosis_result']);

// Get AI accuracy stats
$accuracyStats = getAIAccuracyStats($pdo, 30);

// Get recent diagnoses
$recentDiagnoses = $pdo->query("
    SELECT
        adr.*,
        p.first_name,
        p.last_name,
        u.full_name as doctor_name
    FROM ai_diagnosis_records adr
    INNER JOIN patients p ON adr.patient_id = p.id
    INNER JOIN users u ON adr.doctor_id = u.id
    ORDER BY adr.created_at DESC
    LIMIT 20
")->fetchAll();
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-md-8">
            <h2><i class="bi bi-activity"></i> AI-Assisted Diagnosis</h2>
            <p class="text-muted">Intelligent diagnosis suggestions based on symptoms and medical history</p>
        </div>
        <div class="col-md-4 text-end">
            <div class="card bg-primary text-white">
                <div class="card-body p-2">
                    <small>AI Accuracy (30 days)</small>
                    <h4 class="mb-0"><?= $accuracyStats['accuracy_rate'] ?>%</h4>
                    <small><?= $accuracyStats['total_diagnoses'] ?> diagnoses</small>
                </div>
            </div>
        </div>
    </div>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?= htmlspecialchars($_SESSION['success']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($_SESSION['error']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <div class="row">
        <!-- Input Form -->
        <div class="col-lg-5 mb-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-gradient-primary text-white">
                    <h5 class="mb-0"><i class="bi bi-input-cursor"></i> Patient & Symptoms</h5>
                </div>
                <div class="card-body">
                    <form method="POST" id="diagnosisForm">
                        <input type="hidden" name="action" value="diagnose">

                        <!-- Patient Selection -->
                        <div class="mb-3">
                            <label for="patient_id" class="form-label">
                                Select Patient <span class="text-danger">*</span>
                            </label>
                            <select class="form-select" id="patient_id" name="patient_id" required>
                                <option value="">Choose patient...</option>
                                <?php foreach ($patients as $patient): ?>
                                    <option value="<?= $patient['id'] ?>"
                                            <?= $selectedPatient && $selectedPatient['id'] == $patient['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']) ?>
                                        (<?= $patient['age'] ?>Y, <?= $patient['gender'] ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Vital Signs -->
                        <div class="mb-3">
                            <label class="form-label fw-bold">Vital Signs (Optional)</label>
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <input type="number" class="form-control form-control-sm" name="temperature"
                                           placeholder="Temp (°C)" step="0.1" min="35" max="45">
                                </div>
                                <div class="col-md-3">
                                    <input type="number" class="form-control form-control-sm" name="bp_systolic"
                                           placeholder="BP Sys" min="60" max="250">
                                </div>
                                <div class="col-md-3">
                                    <input type="number" class="form-control form-control-sm" name="bp_diastolic"
                                           placeholder="BP Dia" min="40" max="150">
                                </div>
                                <div class="col-md-6">
                                    <input type="number" class="form-control form-control-sm" name="heart_rate"
                                           placeholder="Heart Rate (bpm)" min="40" max="200">
                                </div>
                                <div class="col-md-6">
                                    <input type="number" class="form-control form-control-sm" name="respiratory_rate"
                                           placeholder="Resp. Rate" min="8" max="40">
                                </div>
                                <div class="col-md-6">
                                    <input type="number" class="form-control form-control-sm" name="oxygen_saturation"
                                           placeholder="O2 Sat (%)" min="70" max="100">
                                </div>
                            </div>
                        </div>

                        <!-- Symptoms Selection -->
                        <div class="mb-3">
                            <label class="form-label fw-bold">
                                Select Symptoms <span class="text-danger">*</span>
                            </label>
                            <div class="symptom-selector" style="max-height: 400px; overflow-y: auto; border: 1px solid #ddd; border-radius: 5px; padding: 10px;">
                                <?php foreach ($symptomsByCategory as $category => $categorySymptoms): ?>
                                    <div class="mb-3">
                                        <h6 class="text-primary mb-2">
                                            <i class="bi bi-chevron-right"></i> <?= ucfirst($category) ?>
                                        </h6>
                                        <?php foreach ($categorySymptoms as $symptom): ?>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox"
                                                       name="symptoms[]" value="<?= $symptom['id'] ?>"
                                                       id="symptom_<?= $symptom['id'] ?>">
                                                <label class="form-check-label" for="symptom_<?= $symptom['id'] ?>">
                                                    <?= htmlspecialchars($symptom['name']) ?>
                                                    <?php if ($symptom['severity_indicator'] === 'emergency'): ?>
                                                        <span class="badge bg-danger badge-sm">Emergency</span>
                                                    <?php elseif ($symptom['severity_indicator'] === 'high'): ?>
                                                        <span class="badge bg-warning badge-sm">High</span>
                                                    <?php endif; ?>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <small class="text-muted">Select all symptoms the patient is experiencing</small>
                        </div>

                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-search"></i> Analyze Symptoms
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- AI Suggestions -->
        <div class="col-lg-7">
            <?php if ($diagnosisResult): ?>
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-gradient-success text-white">
                        <h5 class="mb-0"><i class="bi bi-cpu"></i> AI Diagnosis Suggestions</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($diagnosisResult['suggestions'])): ?>
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle"></i>
                                No matching diseases found. Please verify symptoms or consult medical references.
                            </div>
                        <?php else: ?>
                            <?php foreach ($diagnosisResult['suggestions'] as $index => $suggestion): ?>
                                <div class="card mb-3 border-start border-<?= $index === 0 ? 'success' : 'secondary' ?> border-3">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <div>
                                                <h5 class="mb-1">
                                                    <?= $index + 1 ?>. <?= htmlspecialchars($suggestion['disease_name']) ?>
                                                    <?php if ($suggestion['icd_code']): ?>
                                                        <small class="text-muted">(<?= $suggestion['icd_code'] ?>)</small>
                                                    <?php endif; ?>
                                                </h5>
                                                <div class="mb-2">
                                                    <span class="badge bg-<?= $suggestion['category'] === 'infectious' ? 'danger' : ($suggestion['category'] === 'chronic' ? 'warning' : 'info') ?>">
                                                        <?= ucfirst($suggestion['category']) ?>
                                                    </span>
                                                    <span class="badge bg-<?= $suggestion['severity'] === 'critical' ? 'danger' : ($suggestion['severity'] === 'severe' ? 'warning' : 'secondary') ?>">
                                                        <?= ucfirst($suggestion['severity']) ?>
                                                    </span>
                                                    <?php if (isset($suggestion['is_recurring']) && $suggestion['is_recurring']): ?>
                                                        <span class="badge bg-info">Recurring Condition</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="text-end">
                                                <div class="display-6 text-<?= $suggestion['confidence'] >= 70 ? 'success' : ($suggestion['confidence'] >= 50 ? 'warning' : 'danger') ?>">
                                                    <?= $suggestion['confidence'] ?>%
                                                </div>
                                                <small class="text-muted">Confidence</small>
                                            </div>
                                        </div>

                                        <p class="text-muted mb-2"><?= htmlspecialchars($suggestion['description']) ?></p>

                                        <div class="row g-2 mb-2">
                                            <div class="col-md-6">
                                                <small>
                                                    <i class="bi bi-check-circle text-success"></i>
                                                    Matched <?= $suggestion['matched_symptoms'] ?>/<?= $suggestion['total_symptoms'] ?> symptoms
                                                    (<?= $suggestion['match_percentage'] ?>%)
                                                </small>
                                            </div>
                                            <div class="col-md-6">
                                                <small>
                                                    <i class="bi bi-graph-up text-info"></i>
                                                    Prevalence: <?= $suggestion['prevalence'] ?>% of population
                                                </small>
                                            </div>
                                        </div>

                                        <?php if (isset($suggestion['vital_alerts'])): ?>
                                            <div class="alert alert-info alert-sm p-2 mb-2">
                                                <strong>Vital Signs Analysis:</strong>
                                                <?php foreach ($suggestion['vital_alerts'] as $alert): ?>
                                                    <div><i class="bi bi-info-circle"></i> <?= htmlspecialchars($alert) ?></div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>

                                        <details class="mb-2">
                                            <summary class="fw-bold text-primary" style="cursor: pointer;">
                                                <i class="bi bi-list-check"></i> All Symptoms for this Disease
                                            </summary>
                                            <div class="mt-2">
                                                <?php foreach ($suggestion['all_symptoms'] as $sym): ?>
                                                    <span class="badge bg-<?= $sym['is_primary'] ? 'primary' : 'secondary' ?> me-1 mb-1">
                                                        <?= htmlspecialchars($sym['name']) ?> (<?= $sym['probability'] ?>%)
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                        </details>

                                        <?php if (!empty($suggestion['treatments'])): ?>
                                            <details>
                                                <summary class="fw-bold text-success" style="cursor: pointer;">
                                                    <i class="bi bi-capsule"></i> Treatment Recommendations
                                                </summary>
                                                <div class="mt-2">
                                                    <?php foreach ($suggestion['treatments'] as $treatment): ?>
                                                        <div class="mb-2">
                                                            <span class="badge bg-<?= $treatment['priority'] === 'immediate' ? 'danger' : ($treatment['priority'] === 'high' ? 'warning' : 'info') ?>">
                                                                <?= ucfirst($treatment['priority']) ?> Priority
                                                            </span>
                                                            <span class="badge bg-secondary"><?= ucfirst($treatment['treatment_type']) ?></span>
                                                            <?php if ($treatment['success_rate']): ?>
                                                                <span class="badge bg-success"><?= $treatment['success_rate'] ?>% Success Rate</span>
                                                            <?php endif; ?>
                                                            <p class="mb-0 mt-1"><small><?= htmlspecialchars($treatment['recommendation']) ?></small></p>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </details>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <!-- Save Final Diagnosis -->
                            <div class="card border-primary">
                                <div class="card-body">
                                    <h6 class="mb-3">Final Diagnosis</h6>
                                    <form method="POST">
                                        <input type="hidden" name="action" value="save_diagnosis">
                                        <input type="hidden" name="record_id" value="<?= $diagnosisResult['record_id'] ?>">
                                        <div class="mb-3">
                                            <label class="form-label">Diagnosis <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" name="diagnosis" required
                                                   placeholder="Enter final diagnosis">
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Notes</label>
                                            <textarea class="form-control" name="notes" rows="3"
                                                      placeholder="Additional notes or observations"></textarea>
                                        </div>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-save"></i> Save Diagnosis
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Similar Cases -->
                <?php if (!empty($diagnosisResult['similar_cases'])): ?>
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-gradient-info text-white">
                            <h6 class="mb-0"><i class="bi bi-journal-medical"></i> Similar Past Cases</h6>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Patient</th>
                                            <th>Diagnosis</th>
                                            <th>Doctor</th>
                                            <th>Similarity</th>
                                            <th>Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($diagnosisResult['similar_cases'] as $case): ?>
                                            <tr>
                                                <td><?= $case['patient_age'] ?>Y, <?= $case['patient_gender'] ?></td>
                                                <td><?= htmlspecialchars($case['diagnosis']) ?></td>
                                                <td><small><?= htmlspecialchars($case['doctor_name']) ?></small></td>
                                                <td>
                                                    <span class="badge bg-<?= $case['similarity'] >= 70 ? 'success' : 'secondary' ?>">
                                                        <?= $case['similarity'] ?>%
                                                    </span>
                                                </td>
                                                <td><small><?= date('M d, Y', strtotime($case['date'])) ?></small></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <div class="card border-0 shadow-sm">
                    <div class="card-body text-center py-5">
                        <i class="bi bi-robot display-1 text-muted"></i>
                        <h4 class="mt-3 text-muted">Select patient and symptoms to get AI diagnosis suggestions</h4>
                        <p class="text-muted">
                            The AI engine will analyze symptoms, vital signs, and patient history to provide
                            intelligent diagnosis recommendations with confidence scores.
                        </p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Diagnoses -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="bi bi-clock-history"></i> Recent Diagnoses</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover" id="recentDiagnosesTable">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Patient</th>
                                    <th>Doctor</th>
                                    <th>AI Confidence</th>
                                    <th>Diagnosis</th>
                                    <th>Accuracy</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentDiagnoses as $diagnosis): ?>
                                    <tr>
                                        <td><small><?= date('M d, Y H:i', strtotime($diagnosis['created_at'])) ?></small></td>
                                        <td><?= htmlspecialchars($diagnosis['first_name'] . ' ' . $diagnosis['last_name']) ?></td>
                                        <td><small><?= htmlspecialchars($diagnosis['doctor_name']) ?></small></td>
                                        <td>
                                            <?php if ($diagnosis['confidence_score']): ?>
                                                <span class="badge bg-<?= $diagnosis['confidence_score'] >= 70 ? 'success' : 'warning' ?>">
                                                    <?= round($diagnosis['confidence_score']) ?>%
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($diagnosis['doctor_diagnosis'] ?? 'Pending') ?></td>
                                        <td>
                                            <?php if ($diagnosis['was_accurate'] === 1): ?>
                                                <i class="bi bi-check-circle-fill text-success"></i> Accurate
                                            <?php elseif ($diagnosis['was_accurate'] === 0): ?>
                                                <i class="bi bi-x-circle-fill text-danger"></i> Inaccurate
                                            <?php else: ?>
                                                <span class="text-muted">Not confirmed</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.bg-gradient-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}
.bg-gradient-success {
    background: linear-gradient(135deg, #56ab2f 0%, #a8e063 100%);
}
.bg-gradient-info {
    background: linear-gradient(135deg, #2193b0 0%, #6dd5ed 100%);
}
</style>

<script>
$(document).ready(function() {
    $('#recentDiagnosesTable').DataTable({
        order: [[0, 'desc']],
        pageLength: 10
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
