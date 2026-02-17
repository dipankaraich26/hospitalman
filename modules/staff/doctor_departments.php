<?php
// Initialize auth and functions
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requireRole(['admin']);

$pdo = getDBConnection();

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'assign_department') {
        $userId = (int) $_POST['user_id'];
        $departmentId = $_POST['department_id'] ? (int) $_POST['department_id'] : null;

        // Check if staff_info exists
        $checkStmt = $pdo->prepare("SELECT id FROM staff_info WHERE user_id = ?");
        $checkStmt->execute([$userId]);
        $existing = $checkStmt->fetch();

        if ($existing) {
            // Update existing record
            $stmt = $pdo->prepare("UPDATE staff_info SET department_id = ? WHERE user_id = ?");
            $stmt->execute([$departmentId, $userId]);
        } else {
            // Create new staff_info record
            $stmt = $pdo->prepare("INSERT INTO staff_info (user_id, department_id) VALUES (?, ?)");
            $stmt->execute([$userId, $departmentId]);
        }

        auditLog('update', 'staff', 'staff_info', $userId, null, ['department_id' => $departmentId]);
        setFlashMessage('success', 'Department assignment updated successfully.');
        header('Location: doctor_departments.php');
        exit;
    }
}

// Get all doctors with current department
$doctors = $pdo->query("
    SELECT u.id, u.full_name, u.email, u.phone, u.specialization,
           d.id as department_id, d.name as department_name,
           si.employee_id, si.date_of_joining
    FROM users u
    LEFT JOIN staff_info si ON u.id = si.user_id
    LEFT JOIN departments d ON si.department_id = d.id
    WHERE u.role = 'doctor' AND u.status = 'active'
    ORDER BY u.full_name
")->fetchAll();

// Get all departments
$departments = $pdo->query("SELECT id, name FROM departments WHERE status = 'active' ORDER BY name")->fetchAll();

// Department summary
$deptSummary = $pdo->query("
    SELECT d.id, d.name,
           COUNT(si.user_id) as doctor_count
    FROM departments d
    LEFT JOIN staff_info si ON d.id = si.department_id
    LEFT JOIN users u ON si.user_id = u.id AND u.role = 'doctor' AND u.status = 'active'
    WHERE d.status = 'active'
    GROUP BY d.id, d.name
    ORDER BY doctor_count DESC
")->fetchAll();

// Now include header
$pageTitle = 'Doctor Department Assignment';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h4><i class="bi bi-diagram-3"></i> Doctor Department Assignment</h4>
    <a href="schedule.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<!-- Department Summary -->
<div class="row mb-3">
    <div class="col-12">
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h6 class="mb-0"><i class="bi bi-building"></i> Department Summary</h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <?php foreach ($deptSummary as $summary): ?>
                    <div class="col-md-3 mb-2">
                        <div class="card border-primary">
                            <div class="card-body p-2">
                                <h6 class="mb-0"><?= sanitize($summary['name']) ?></h6>
                                <div class="d-flex justify-content-between align-items-center mt-2">
                                    <span class="text-muted">Doctors:</span>
                                    <span class="badge bg-primary fs-6"><?= $summary['doctor_count'] ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Doctor Department Assignment Table -->
<div class="card">
    <div class="card-header">
        <h6 class="mb-0"><i class="bi bi-person-badge"></i> Doctor List & Department Assignments</h6>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover data-table">
                <thead>
                    <tr>
                        <th>Doctor Name</th>
                        <th>Specialization</th>
                        <th>Contact</th>
                        <th>Current Department</th>
                        <th>Employee ID</th>
                        <th>Joining Date</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($doctors as $doctor): ?>
                    <tr>
                        <td>
                            <strong><?= sanitize($doctor['full_name']) ?></strong>
                        </td>
                        <td><?= sanitize($doctor['specialization']) ?></td>
                        <td>
                            <small>
                                <?php if ($doctor['email']): ?>
                                <i class="bi bi-envelope"></i> <?= sanitize($doctor['email']) ?><br>
                                <?php endif; ?>
                                <?php if ($doctor['phone']): ?>
                                <i class="bi bi-telephone"></i> <?= sanitize($doctor['phone']) ?>
                                <?php endif; ?>
                            </small>
                        </td>
                        <td>
                            <?php if ($doctor['department_name']): ?>
                                <span class="badge bg-info"><?= sanitize($doctor['department_name']) ?></span>
                            <?php else: ?>
                                <span class="badge bg-warning">Not Assigned</span>
                            <?php endif; ?>
                        </td>
                        <td><?= sanitize($doctor['employee_id'] ?? '-') ?></td>
                        <td>
                            <?php if ($doctor['date_of_joining']): ?>
                                <?= formatDate($doctor['date_of_joining']) ?>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button class="btn btn-sm btn-outline-primary"
                                    data-bs-toggle="modal"
                                    data-bs-target="#assignModal<?= $doctor['id'] ?>">
                                <i class="bi bi-pencil"></i> Assign
                            </button>

                            <!-- Assign Modal -->
                            <div class="modal fade" id="assignModal<?= $doctor['id'] ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Assign Department</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form method="POST">
                                            <div class="modal-body">
                                                <input type="hidden" name="action" value="assign_department">
                                                <input type="hidden" name="user_id" value="<?= $doctor['id'] ?>">

                                                <div class="alert alert-info">
                                                    <strong>Doctor:</strong> <?= sanitize($doctor['full_name']) ?><br>
                                                    <strong>Specialization:</strong> <?= sanitize($doctor['specialization']) ?>
                                                </div>

                                                <div class="mb-3">
                                                    <label class="form-label">Department *</label>
                                                    <select name="department_id" class="form-select" required>
                                                        <option value="">None (Remove Assignment)</option>
                                                        <?php foreach ($departments as $dept): ?>
                                                        <option value="<?= $dept['id'] ?>"
                                                                <?= $doctor['department_id'] == $dept['id'] ? 'selected' : '' ?>>
                                                            <?= sanitize($dept['name']) ?>
                                                        </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>

                                                <small class="text-muted">
                                                    <i class="bi bi-info-circle"></i>
                                                    This will assign the doctor to the selected department for OPD scheduling.
                                                </small>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-primary">Save Assignment</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
