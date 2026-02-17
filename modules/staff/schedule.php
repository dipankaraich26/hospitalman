<?php
// Initialize auth and functions before header to allow redirects
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requireRole(['admin']);

$pdo = getDBConnection();

// Handle POST before header include
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_schedule') {
        $stmt = $pdo->prepare("INSERT INTO staff_schedules (staff_id, schedule_date, shift_type, start_time, end_time, department_id, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            (int) $_POST['staff_id'],
            $_POST['schedule_date'],
            $_POST['shift_type'],
            $_POST['start_time'],
            $_POST['end_time'],
            $_POST['department_id'] ?: null,
            trim($_POST['notes']),
            $_SESSION['user_id']
        ]);
        setFlashMessage('success', 'Schedule added successfully.');
        header('Location: schedule.php');
        exit;
    }

    if ($_POST['action'] === 'delete_schedule') {
        $pdo->prepare("DELETE FROM staff_schedules WHERE id = ?")->execute([(int) $_POST['id']]);
        setFlashMessage('success', 'Schedule deleted.');
        header('Location: schedule.php');
        exit;
    }
}

// Filters
$filterDate = $_GET['date'] ?? date('Y-m-d');
$filterStaff = $_GET['staff_id'] ?? '';
$filterShift = $_GET['shift'] ?? '';

// Build query
$where = "1=1";
$params = [];

if ($filterDate) {
    $where .= " AND s.schedule_date = ?";
    $params[] = $filterDate;
}
if ($filterStaff) {
    $where .= " AND s.staff_id = ?";
    $params[] = $filterStaff;
}
if ($filterShift) {
    $where .= " AND s.shift_type = ?";
    $params[] = $filterShift;
}

$stmt = $pdo->prepare("
    SELECT s.*, u.full_name, u.role, u.specialization, d.name as dept_name
    FROM staff_schedules s
    JOIN users u ON s.staff_id = u.id
    LEFT JOIN departments d ON s.department_id = d.id
    WHERE $where
    ORDER BY s.schedule_date DESC, s.start_time
");
$stmt->execute($params);
$schedules = $stmt->fetchAll();

// Get all staff for dropdown
$allStaff = $pdo->query("SELECT id, full_name, role FROM users WHERE status = 'active' ORDER BY full_name")->fetchAll();

// Get departments
$departments = $pdo->query("SELECT id, name FROM departments WHERE status = 'active' ORDER BY name")->fetchAll();

// Now include header for HTML output
$pageTitle = 'Staff Schedules';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h4><i class="bi bi-calendar-week"></i> Staff Schedules</h4>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addScheduleModal">
        <i class="bi bi-plus-lg"></i> Add Schedule
    </button>
</div>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Date</label>
                <input type="date" name="date" class="form-control" value="<?= sanitize($filterDate) ?>">
            </div>
            <div class="col-md-3">
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
            <div class="col-md-3">
                <label class="form-label">Shift</label>
                <select name="shift" class="form-select">
                    <option value="">All Shifts</option>
                    <option value="morning" <?= $filterShift === 'morning' ? 'selected' : '' ?>>Morning</option>
                    <option value="afternoon" <?= $filterShift === 'afternoon' ? 'selected' : '' ?>>Afternoon</option>
                    <option value="evening" <?= $filterShift === 'evening' ? 'selected' : '' ?>>Evening</option>
                    <option value="night" <?= $filterShift === 'night' ? 'selected' : '' ?>>Night</option>
                    <option value="on-call" <?= $filterShift === 'on-call' ? 'selected' : '' ?>>On-Call</option>
                </select>
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <button type="submit" class="btn btn-outline-primary me-2">Filter</button>
                <a href="schedule.php" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Schedules Table -->
<div class="card">
    <div class="card-body">
        <table class="table table-hover data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Staff Member</th>
                    <th>Role</th>
                    <th>Shift</th>
                    <th>Time</th>
                    <th>Department</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($schedules)): ?>
                <tr><td colspan="8" class="text-center text-muted py-3">No schedules found</td></tr>
                <?php else: foreach ($schedules as $sch): ?>
                <tr>
                    <td><?= formatDate($sch['schedule_date']) ?></td>
                    <td>
                        <strong><?= sanitize($sch['full_name']) ?></strong>
                        <?php if ($sch['specialization']): ?>
                        <br><small class="text-muted"><?= sanitize($sch['specialization']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge bg-info"><?= ucfirst($sch['role']) ?></span></td>
                    <td><span class="badge bg-secondary"><?= ucfirst($sch['shift_type']) ?></span></td>
                    <td><?= date('h:i A', strtotime($sch['start_time'])) ?> - <?= date('h:i A', strtotime($sch['end_time'])) ?></td>
                    <td><?= $sch['dept_name'] ?? '-' ?></td>
                    <td><?= getStatusBadge($sch['status']) ?></td>
                    <td>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this schedule?')">
                            <input type="hidden" name="action" value="delete_schedule">
                            <input type="hidden" name="id" value="<?= $sch['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add Schedule Modal -->
<div class="modal fade" id="addScheduleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Staff Schedule</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_schedule">

                    <div class="mb-3">
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

                    <div class="mb-3">
                        <label class="form-label">Schedule Date *</label>
                        <input type="date" name="schedule_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Shift Type *</label>
                        <select name="shift_type" class="form-select" required>
                            <option value="morning">Morning (6 AM - 2 PM)</option>
                            <option value="afternoon">Afternoon (2 PM - 10 PM)</option>
                            <option value="evening">Evening (4 PM - 12 AM)</option>
                            <option value="night">Night (10 PM - 6 AM)</option>
                            <option value="on-call">On-Call (24 Hours)</option>
                        </select>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Start Time *</label>
                            <input type="time" name="start_time" class="form-control" value="06:00" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">End Time *</label>
                            <input type="time" name="end_time" class="form-control" value="14:00" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Department</label>
                        <select name="department_id" class="form-select">
                            <option value="">None</option>
                            <?php foreach ($departments as $dept): ?>
                            <option value="<?= $dept['id'] ?>"><?= sanitize($dept['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Schedule</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
