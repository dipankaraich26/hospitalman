<?php
// Initialize auth and functions before header to allow redirects
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requireRole(['admin']);

$pdo = getDBConnection();

// Handle POST before header include
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_evaluation') {
        $stmt = $pdo->prepare("INSERT INTO staff_evaluations (staff_id, evaluator_id, evaluation_date, period_start, period_end, performance_score, attendance_score, punctuality_score, teamwork_score, communication_score, overall_rating, strengths, areas_for_improvement, goals_for_next_period, comments) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            (int) $_POST['staff_id'],
            $_SESSION['user_id'],
            $_POST['evaluation_date'],
            $_POST['period_start'],
            $_POST['period_end'],
            (float) $_POST['performance_score'],
            (float) $_POST['attendance_score'],
            (float) $_POST['punctuality_score'],
            (float) $_POST['teamwork_score'],
            (float) $_POST['communication_score'],
            $_POST['overall_rating'],
            trim($_POST['strengths']),
            trim($_POST['areas_for_improvement']),
            trim($_POST['goals_for_next_period']),
            trim($_POST['comments'])
        ]);
        auditLog('create', 'staff', 'staff_evaluations', $pdo->lastInsertId(), null, $_POST);
        setFlashMessage('success', 'Performance evaluation added successfully.');
        header('Location: performance.php');
        exit;
    }

    if ($_POST['action'] === 'delete_evaluation') {
        $pdo->prepare("DELETE FROM staff_evaluations WHERE id = ?")->execute([(int) $_POST['id']]);
        auditLog('delete', 'staff', 'staff_evaluations', (int) $_POST['id'], null, null);
        setFlashMessage('success', 'Evaluation deleted.');
        header('Location: performance.php');
        exit;
    }
}

// Filters
$filterStaff = $_GET['staff_id'] ?? '';
$filterPeriod = $_GET['period'] ?? '';

// Build query for evaluations
$where = "1=1";
$params = [];

if ($filterStaff) {
    $where .= " AND e.staff_id = ?";
    $params[] = $filterStaff;
}

if ($filterPeriod) {
    $year = date('Y');
    if ($filterPeriod === 'q1') {
        $where .= " AND e.evaluation_date BETWEEN ? AND ?";
        $params[] = "$year-01-01";
        $params[] = "$year-03-31";
    } elseif ($filterPeriod === 'q2') {
        $where .= " AND e.evaluation_date BETWEEN ? AND ?";
        $params[] = "$year-04-01";
        $params[] = "$year-06-30";
    } elseif ($filterPeriod === 'q3') {
        $where .= " AND e.evaluation_date BETWEEN ? AND ?";
        $params[] = "$year-07-01";
        $params[] = "$year-09-30";
    } elseif ($filterPeriod === 'q4') {
        $where .= " AND e.evaluation_date BETWEEN ? AND ?";
        $params[] = "$year-10-01";
        $params[] = "$year-12-31";
    }
}

$stmt = $pdo->prepare("
    SELECT e.*, u.full_name, u.role, ev.full_name as evaluator_name
    FROM staff_evaluations e
    JOIN users u ON e.staff_id = u.id
    JOIN users ev ON e.evaluator_id = ev.id
    WHERE $where
    ORDER BY e.evaluation_date DESC
");
$stmt->execute($params);
$evaluations = $stmt->fetchAll();

// Get all active staff for dropdown
$allStaff = $pdo->query("SELECT id, full_name, role FROM users WHERE status = 'active' ORDER BY full_name")->fetchAll();

// Staff workload summary (current month)
$workloadStats = $pdo->query("
    SELECT w.staff_id, u.full_name, u.role,
           SUM(w.appointments_count) as total_appointments,
           SUM(w.consultations_count) as total_consultations,
           SUM(w.procedures_count) as total_procedures,
           SUM(w.hours_worked) as total_hours,
           SUM(w.overtime_hours) as total_overtime
    FROM staff_workload w
    JOIN users u ON w.staff_id = u.id
    WHERE MONTH(w.work_date) = MONTH(CURDATE()) AND YEAR(w.work_date) = YEAR(CURDATE())
    GROUP BY w.staff_id, u.full_name, u.role
    ORDER BY total_hours DESC
    LIMIT 10
")->fetchAll();

// Performance statistics
$perfStats = $pdo->query("
    SELECT
        COUNT(*) as total_evaluations,
        AVG((performance_score + attendance_score + punctuality_score + teamwork_score + communication_score) / 5) as avg_score,
        COUNT(CASE WHEN overall_rating = 'excellent' THEN 1 END) as excellent_count,
        COUNT(CASE WHEN overall_rating = 'good' THEN 1 END) as good_count,
        COUNT(CASE WHEN overall_rating = 'satisfactory' THEN 1 END) as satisfactory_count,
        COUNT(CASE WHEN overall_rating = 'needs improvement' THEN 1 END) as needs_improvement_count
    FROM staff_evaluations
    WHERE YEAR(evaluation_date) = YEAR(CURDATE())
")->fetch();

// Now include header for HTML output
$pageTitle = 'Staff Performance';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h4><i class="bi bi-graph-up"></i> Staff Performance</h4>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addEvaluationModal">
        <i class="bi bi-plus-lg"></i> Add Evaluation
    </button>
</div>

<!-- Performance Statistics -->
<div class="row mb-3">
    <div class="col-md-3">
        <div class="card bg-primary bg-opacity-10 border-primary">
            <div class="card-body">
                <h6 class="text-primary"><i class="bi bi-clipboard-data"></i> Total Evaluations (This Year)</h6>
                <h3 class="mb-0"><?= $perfStats['total_evaluations'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-success bg-opacity-10 border-success">
            <div class="card-body">
                <h6 class="text-success"><i class="bi bi-star-fill"></i> Average Score</h6>
                <h3 class="mb-0"><?= number_format($perfStats['avg_score'], 2) ?> / 5.00</h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-warning bg-opacity-10 border-warning">
            <div class="card-body">
                <h6 class="text-warning"><i class="bi bi-trophy"></i> Excellent Ratings</h6>
                <h3 class="mb-0"><?= $perfStats['excellent_count'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-info bg-opacity-10 border-info">
            <div class="card-body">
                <h6 class="text-info"><i class="bi bi-hand-thumbs-up"></i> Good Ratings</h6>
                <h3 class="mb-0"><?= $perfStats['good_count'] ?></h3>
            </div>
        </div>
    </div>
</div>

<!-- Workload Summary (Current Month) -->
<div class="card mb-3">
    <div class="card-header"><i class="bi bi-speedometer2"></i> Staff Workload - Current Month</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-hover">
                <thead>
                    <tr>
                        <th>Staff Member</th>
                        <th>Role</th>
                        <th>Appointments</th>
                        <th>Consultations</th>
                        <th>Procedures</th>
                        <th>Hours Worked</th>
                        <th>Overtime</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($workloadStats)): ?>
                    <tr><td colspan="7" class="text-center text-muted">No workload data for this month</td></tr>
                    <?php else: foreach ($workloadStats as $ws): ?>
                    <tr>
                        <td><strong><?= sanitize($ws['full_name']) ?></strong></td>
                        <td><span class="badge bg-info"><?= ucfirst($ws['role']) ?></span></td>
                        <td><?= $ws['total_appointments'] ?></td>
                        <td><?= $ws['total_consultations'] ?></td>
                        <td><?= $ws['total_procedures'] ?></td>
                        <td><strong><?= number_format($ws['total_hours'], 1) ?>h</strong></td>
                        <td><?= number_format($ws['total_overtime'], 1) ?>h</td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Staff Member</label>
                <select name="staff_id" class="form-select">
                    <option value="">All Staff</option>
                    <?php foreach ($allStaff as $staff): ?>
                    <option value="<?= $staff['id'] ?>" <?= $filterStaff == $staff['id'] ? 'selected' : '' ?>>
                        <?= sanitize($staff['full_name']) ?> (<?= ucfirst($staff['role']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Period</label>
                <select name="period" class="form-select">
                    <option value="">All Periods</option>
                    <option value="q1" <?= $filterPeriod === 'q1' ? 'selected' : '' ?>>Q1 (Jan-Mar)</option>
                    <option value="q2" <?= $filterPeriod === 'q2' ? 'selected' : '' ?>>Q2 (Apr-Jun)</option>
                    <option value="q3" <?= $filterPeriod === 'q3' ? 'selected' : '' ?>>Q3 (Jul-Sep)</option>
                    <option value="q4" <?= $filterPeriod === 'q4' ? 'selected' : '' ?>>Q4 (Oct-Dec)</option>
                </select>
            </div>
            <div class="col-md-4 d-flex align-items-end">
                <button type="submit" class="btn btn-outline-primary me-2">Filter</button>
                <a href="performance.php" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Evaluations Table -->
<div class="card">
    <div class="card-header">Performance Evaluations</div>
    <div class="card-body">
        <table class="table table-hover data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Staff Member</th>
                    <th>Period</th>
                    <th>Overall Rating</th>
                    <th>Scores</th>
                    <th>Evaluator</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($evaluations)): ?>
                <tr><td colspan="7" class="text-center text-muted py-3">No evaluations found</td></tr>
                <?php else: foreach ($evaluations as $eval): ?>
                <tr>
                    <td><?= formatDate($eval['evaluation_date']) ?></td>
                    <td>
                        <strong><?= sanitize($eval['full_name']) ?></strong>
                        <br><small class="text-muted"><?= ucfirst($eval['role']) ?></small>
                    </td>
                    <td>
                        <?= formatDate($eval['period_start']) ?><br>to<br><?= formatDate($eval['period_end']) ?>
                    </td>
                    <td>
                        <?php
                        $ratingColors = [
                            'excellent' => 'success',
                            'good' => 'primary',
                            'satisfactory' => 'info',
                            'needs improvement' => 'warning',
                            'unsatisfactory' => 'danger'
                        ];
                        $color = $ratingColors[$eval['overall_rating']] ?? 'secondary';
                        ?>
                        <span class="badge bg-<?= $color ?>"><?= ucfirst($eval['overall_rating']) ?></span>
                    </td>
                    <td>
                        <small>
                            <strong>Performance:</strong> <?= number_format($eval['performance_score'], 2) ?><br>
                            <strong>Attendance:</strong> <?= number_format($eval['attendance_score'], 2) ?><br>
                            <strong>Punctuality:</strong> <?= number_format($eval['punctuality_score'], 2) ?><br>
                            <strong>Teamwork:</strong> <?= number_format($eval['teamwork_score'], 2) ?><br>
                            <strong>Communication:</strong> <?= number_format($eval['communication_score'], 2) ?><br>
                            <strong class="text-primary">Average: <?= number_format(($eval['performance_score'] + $eval['attendance_score'] + $eval['punctuality_score'] + $eval['teamwork_score'] + $eval['communication_score']) / 5, 2) ?> / 5.00</strong>
                        </small>
                    </td>
                    <td><?= sanitize($eval['evaluator_name']) ?></td>
                    <td>
                        <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#viewModal<?= $eval['id'] ?>">
                            <i class="bi bi-eye"></i>
                        </button>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this evaluation?')">
                            <input type="hidden" name="action" value="delete_evaluation">
                            <input type="hidden" name="id" value="<?= $eval['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>

                        <!-- View Details Modal -->
                        <div class="modal fade" id="viewModal<?= $eval['id'] ?>" tabindex="-1">
                            <div class="modal-dialog modal-lg">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Evaluation Details - <?= sanitize($eval['full_name']) ?></h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="row mb-3">
                                            <div class="col-md-6">
                                                <strong>Evaluation Period:</strong><br>
                                                <?= formatDate($eval['period_start']) ?> to <?= formatDate($eval['period_end']) ?>
                                            </div>
                                            <div class="col-md-6">
                                                <strong>Overall Rating:</strong><br>
                                                <span class="badge bg-<?= $color ?>"><?= ucfirst($eval['overall_rating']) ?></span>
                                            </div>
                                        </div>
                                        <hr>
                                        <div class="mb-3">
                                            <strong>Strengths:</strong>
                                            <p><?= nl2br(sanitize($eval['strengths'])) ?></p>
                                        </div>
                                        <div class="mb-3">
                                            <strong>Areas for Improvement:</strong>
                                            <p><?= nl2br(sanitize($eval['areas_for_improvement'])) ?></p>
                                        </div>
                                        <div class="mb-3">
                                            <strong>Goals for Next Period:</strong>
                                            <p><?= nl2br(sanitize($eval['goals_for_next_period'])) ?></p>
                                        </div>
                                        <?php if ($eval['comments']): ?>
                                        <div class="mb-3">
                                            <strong>Additional Comments:</strong>
                                            <p><?= nl2br(sanitize($eval['comments'])) ?></p>
                                        </div>
                                        <?php endif; ?>
                                        <hr>
                                        <small class="text-muted">
                                            Evaluated by: <?= sanitize($eval['evaluator_name']) ?> on <?= formatDate($eval['evaluation_date']) ?>
                                        </small>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add Evaluation Modal -->
<div class="modal fade" id="addEvaluationModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Performance Evaluation</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_evaluation">

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Staff Member *</label>
                            <select name="staff_id" class="form-select" required>
                                <option value="">Select Staff</option>
                                <?php foreach ($allStaff as $staff): ?>
                                <option value="<?= $staff['id'] ?>">
                                    <?= sanitize($staff['full_name']) ?> (<?= ucfirst($staff['role']) ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Evaluation Date *</label>
                            <input type="date" name="evaluation_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Period Start *</label>
                            <input type="date" name="period_start" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Period End *</label>
                            <input type="date" name="period_end" class="form-control" required>
                        </div>
                    </div>

                    <h6 class="mb-3">Performance Scores (0.00 - 5.00)</h6>

                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Performance Score *</label>
                            <input type="number" name="performance_score" class="form-control" step="0.01" min="0" max="5" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Attendance Score *</label>
                            <input type="number" name="attendance_score" class="form-control" step="0.01" min="0" max="5" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Punctuality Score *</label>
                            <input type="number" name="punctuality_score" class="form-control" step="0.01" min="0" max="5" required>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Teamwork Score *</label>
                            <input type="number" name="teamwork_score" class="form-control" step="0.01" min="0" max="5" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Communication Score *</label>
                            <input type="number" name="communication_score" class="form-control" step="0.01" min="0" max="5" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Overall Rating *</label>
                        <select name="overall_rating" class="form-select" required>
                            <option value="">Select Rating</option>
                            <option value="excellent">Excellent</option>
                            <option value="good">Good</option>
                            <option value="satisfactory">Satisfactory</option>
                            <option value="needs improvement">Needs Improvement</option>
                            <option value="unsatisfactory">Unsatisfactory</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Strengths *</label>
                        <textarea name="strengths" class="form-control" rows="2" required></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Areas for Improvement *</label>
                        <textarea name="areas_for_improvement" class="form-control" rows="2" required></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Goals for Next Period *</label>
                        <textarea name="goals_for_next_period" class="form-control" rows="2" required></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Additional Comments</label>
                        <textarea name="comments" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Evaluation</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
