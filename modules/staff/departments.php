<?php
// Initialize auth and functions
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requireRole(['admin']);

$pdo = getDBConnection();

require_once __DIR__ . '/../../includes/header.php';

// Handle department operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action'])) {
            if ($_POST['action'] === 'add') {
                // Add new department
                $name = trim($_POST['name']);
                $description = trim($_POST['description'] ?? '');
                $status = $_POST['status'] ?? 'active';

                if (empty($name)) {
                    throw new Exception('Department name is required');
                }

                // Check if department already exists
                $checkStmt = $pdo->prepare("SELECT id FROM departments WHERE name = ?");
                $checkStmt->execute([$name]);
                if ($checkStmt->fetch()) {
                    throw new Exception('Department with this name already exists');
                }

                $stmt = $pdo->prepare("INSERT INTO departments (name, description, status, created_at) VALUES (?, ?, ?, NOW())");
                $stmt->execute([$name, $description, $status]);

                $_SESSION['success'] = 'Department added successfully';
                header('Location: departments.php');
                exit;

            } elseif ($_POST['action'] === 'edit') {
                // Edit department
                $id = (int) $_POST['department_id'];
                $name = trim($_POST['name']);
                $description = trim($_POST['description'] ?? '');
                $status = $_POST['status'] ?? 'active';

                if (empty($name)) {
                    throw new Exception('Department name is required');
                }

                // Check if another department has this name
                $checkStmt = $pdo->prepare("SELECT id FROM departments WHERE name = ? AND id != ?");
                $checkStmt->execute([$name, $id]);
                if ($checkStmt->fetch()) {
                    throw new Exception('Another department with this name already exists');
                }

                $stmt = $pdo->prepare("UPDATE departments SET name = ?, description = ?, status = ? WHERE id = ?");
                $stmt->execute([$name, $description, $status, $id]);

                $_SESSION['success'] = 'Department updated successfully';
                header('Location: departments.php');
                exit;

            } elseif ($_POST['action'] === 'toggle_status') {
                // Toggle department status
                $id = (int) $_POST['department_id'];

                $stmt = $pdo->prepare("UPDATE departments SET status = IF(status = 'active', 'inactive', 'active') WHERE id = ?");
                $stmt->execute([$id]);

                $_SESSION['success'] = 'Department status updated successfully';
                header('Location: departments.php');
                exit;
            }
        }
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
        header('Location: departments.php');
        exit;
    }
}

// Get all departments with doctor and staff counts
$departments = $pdo->query("
    SELECT
        d.*,
        COUNT(DISTINCT CASE WHEN u.role = 'doctor' THEN si.user_id END) as doctor_count,
        COUNT(DISTINCT CASE WHEN u.role != 'doctor' THEN si.user_id END) as staff_count
    FROM departments d
    LEFT JOIN staff_info si ON d.id = si.department_id
    LEFT JOIN users u ON si.user_id = u.id
    GROUP BY d.id
    ORDER BY d.status DESC, d.name ASC
")->fetchAll();

// Get statistics
$totalDepts = count($departments);
$activeDepts = count(array_filter($departments, fn($d) => $d['status'] === 'active'));
$totalDoctors = array_sum(array_column($departments, 'doctor_count'));
$totalStaff = array_sum(array_column($departments, 'staff_count'));
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-md-8">
            <h2><i class="fas fa-building"></i> Department Management</h2>
            <p class="text-muted">Manage hospital departments and organizational structure</p>
        </div>
        <div class="col-md-4 text-end">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addDepartmentModal">
                <i class="fas fa-plus"></i> Add Department
            </button>
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
            <?= htmlspecialchars($_SESSION['error']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-0">Total Departments</h6>
                            <h3 class="mb-0 mt-2"><?= $totalDepts ?></h3>
                        </div>
                        <i class="fas fa-building fa-3x opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-0">Active Departments</h6>
                            <h3 class="mb-0 mt-2"><?= $activeDepts ?></h3>
                        </div>
                        <i class="fas fa-check-circle fa-3x opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-0">Total Doctors</h6>
                            <h3 class="mb-0 mt-2"><?= $totalDoctors ?></h3>
                        </div>
                        <i class="fas fa-user-md fa-3x opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-0">Total Staff</h6>
                            <h3 class="mb-0 mt-2"><?= $totalStaff ?></h3>
                        </div>
                        <i class="fas fa-users fa-3x opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Departments Table -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">All Departments</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" id="departmentsTable">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Department Name</th>
                            <th>Description</th>
                            <th>Doctors</th>
                            <th>Staff</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($departments as $dept): ?>
                        <tr>
                            <td><?= $dept['id'] ?></td>
                            <td>
                                <strong><?= htmlspecialchars($dept['name']) ?></strong>
                            </td>
                            <td>
                                <small class="text-muted">
                                    <?= htmlspecialchars($dept['description'] ?? 'No description') ?>
                                </small>
                            </td>
                            <td>
                                <span class="badge bg-info">
                                    <?= $dept['doctor_count'] ?> Doctor<?= $dept['doctor_count'] != 1 ? 's' : '' ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-secondary">
                                    <?= $dept['staff_count'] ?> Staff
                                </span>
                            </td>
                            <td>
                                <?php if ($dept['status'] === 'active'): ?>
                                    <span class="badge bg-success">Active</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <small class="text-muted">
                                    <?= date('M d, Y', strtotime($dept['created_at'])) ?>
                                </small>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-outline-primary"
                                            onclick="editDepartment(<?= htmlspecialchars(json_encode($dept)) ?>)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="POST" style="display: inline;"
                                          onsubmit="return confirm('Are you sure you want to change the status of this department?')">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="department_id" value="<?= $dept['id'] ?>">
                                        <button type="submit" class="btn btn-outline-<?= $dept['status'] === 'active' ? 'warning' : 'success' ?>">
                                            <i class="fas fa-<?= $dept['status'] === 'active' ? 'ban' : 'check' ?>"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Add Department Modal -->
<div class="modal fade" id="addDepartmentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Department</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="name" class="form-label">Department Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="status" class="form-label">Status</label>
                        <select class="form-select" id="status" name="status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Department</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Department Modal -->
<div class="modal fade" id="editDepartmentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Department</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="department_id" id="edit_department_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_name" class="form-label">Department Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_description" class="form-label">Description</label>
                        <textarea class="form-control" id="edit_description" name="description" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="edit_status" class="form-label">Status</label>
                        <select class="form-select" id="edit_status" name="status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Department</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Initialize DataTable
$(document).ready(function() {
    $('#departmentsTable').DataTable({
        order: [[0, 'desc']],
        pageLength: 25
    });
});

// Edit department function
function editDepartment(dept) {
    document.getElementById('edit_department_id').value = dept.id;
    document.getElementById('edit_name').value = dept.name;
    document.getElementById('edit_description').value = dept.description || '';
    document.getElementById('edit_status').value = dept.status;

    new bootstrap.Modal(document.getElementById('editDepartmentModal')).show();
}
</script>

<?php require_once '../../includes/footer.php'; ?>
