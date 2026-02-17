<?php
// Initialize auth and functions before header to allow redirects
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requireRole(['admin']);

$pdo = getDBConnection();

// Handle POST before header include
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_resource') {
        $stmt = $pdo->prepare("INSERT INTO resources (name, resource_type, category, serial_number, department_id, status, purchase_date, warranty_expiry, maintenance_schedule, location, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            trim($_POST['name']),
            $_POST['resource_type'],
            trim($_POST['category']) ?: null,
            trim($_POST['serial_number']) ?: null,
            $_POST['department_id'] ?: null,
            $_POST['status'],
            $_POST['purchase_date'] ?: null,
            $_POST['warranty_expiry'] ?: null,
            trim($_POST['maintenance_schedule']) ?: null,
            trim($_POST['location']) ?: null,
            trim($_POST['notes']) ?: null
        ]);
        auditLog('create', 'staff', 'resources', $pdo->lastInsertId(), null, $_POST);
        setFlashMessage('success', 'Resource added successfully.');
        header('Location: resources.php');
        exit;
    }

    if ($_POST['action'] === 'allocate_resource') {
        $resourceId = (int) $_POST['resource_id'];
        $staffId = (int) $_POST['staff_id'];

        // Create allocation record
        $stmt = $pdo->prepare("INSERT INTO resource_allocations (resource_id, staff_id, allocated_at, purpose, allocated_by, status) VALUES (?,?,?,?,?,'active')");
        $stmt->execute([
            $resourceId,
            $staffId,
            $_POST['allocated_at'],
            trim($_POST['purpose']),
            $_SESSION['user_id']
        ]);

        // Update resource status
        $pdo->prepare("UPDATE resources SET status = 'in-use', assigned_to = ? WHERE id = ?")->execute([$staffId, $resourceId]);

        auditLog('create', 'staff', 'resource_allocations', $pdo->lastInsertId(), null, $_POST);
        setFlashMessage('success', 'Resource allocated successfully.');
        header('Location: resources.php');
        exit;
    }

    if ($_POST['action'] === 'return_resource') {
        $allocationId = (int) $_POST['allocation_id'];
        $resourceId = (int) $_POST['resource_id'];

        // Update allocation record
        $stmt = $pdo->prepare("UPDATE resource_allocations SET returned_at = NOW(), status = ?, notes = ? WHERE id = ?");
        $stmt->execute([
            $_POST['return_status'],
            trim($_POST['return_notes']) ?: null,
            $allocationId
        ]);

        // Update resource status based on return condition
        $newStatus = ($_POST['return_status'] === 'returned') ? 'available' : $_POST['return_status'];
        $pdo->prepare("UPDATE resources SET status = ?, assigned_to = NULL WHERE id = ?")->execute([$newStatus, $resourceId]);

        auditLog('update', 'staff', 'resource_allocations', $allocationId, null, $_POST);
        setFlashMessage('success', 'Resource returned successfully.');
        header('Location: resources.php');
        exit;
    }

    if ($_POST['action'] === 'update_maintenance') {
        $resourceId = (int) $_POST['resource_id'];
        $pdo->prepare("UPDATE resources SET last_maintenance_date = ?, status = 'available' WHERE id = ?")->execute([
            $_POST['maintenance_date'],
            $resourceId
        ]);
        auditLog('update', 'staff', 'resources', $resourceId, null, ['maintenance_date' => $_POST['maintenance_date']]);
        setFlashMessage('success', 'Maintenance record updated.');
        header('Location: resources.php');
        exit;
    }

    if ($_POST['action'] === 'delete_resource') {
        $pdo->prepare("DELETE FROM resources WHERE id = ?")->execute([(int) $_POST['id']]);
        auditLog('delete', 'staff', 'resources', (int) $_POST['id'], null, null);
        setFlashMessage('success', 'Resource deleted.');
        header('Location: resources.php');
        exit;
    }
}

// Filters
$filterType = $_GET['type'] ?? '';
$filterStatus = $_GET['status'] ?? '';
$filterDept = $_GET['dept_id'] ?? '';

// Build query for resources
$where = "1=1";
$params = [];

if ($filterType) {
    $where .= " AND r.resource_type = ?";
    $params[] = $filterType;
}

if ($filterStatus) {
    $where .= " AND r.status = ?";
    $params[] = $filterStatus;
}

if ($filterDept) {
    $where .= " AND r.department_id = ?";
    $params[] = $filterDept;
}

$stmt = $pdo->prepare("
    SELECT r.*, d.name as dept_name, u.full_name as assigned_name
    FROM resources r
    LEFT JOIN departments d ON r.department_id = d.id
    LEFT JOIN users u ON r.assigned_to = u.id
    WHERE $where
    ORDER BY r.name
");
$stmt->execute($params);
$resources = $stmt->fetchAll();

// Get active allocations
$activeAllocations = $pdo->query("
    SELECT a.*, r.name as resource_name, r.resource_type, u.full_name, u.role
    FROM resource_allocations a
    JOIN resources r ON a.resource_id = r.id
    JOIN users u ON a.staff_id = u.id
    WHERE a.status = 'active'
    ORDER BY a.allocated_at DESC
")->fetchAll();

// Get departments
$departments = $pdo->query("SELECT id, name FROM departments WHERE status = 'active' ORDER BY name")->fetchAll();

// Get all staff
$allStaff = $pdo->query("SELECT id, full_name, role FROM users WHERE status = 'active' ORDER BY full_name")->fetchAll();

// Resource statistics
$stats = $pdo->query("
    SELECT
        COUNT(*) as total_resources,
        COUNT(CASE WHEN status = 'available' THEN 1 END) as available_count,
        COUNT(CASE WHEN status = 'in-use' THEN 1 END) as in_use_count,
        COUNT(CASE WHEN status = 'maintenance' THEN 1 END) as maintenance_count,
        COUNT(CASE WHEN resource_type = 'equipment' THEN 1 END) as equipment_count,
        COUNT(CASE WHEN resource_type = 'room' THEN 1 END) as room_count,
        COUNT(CASE WHEN resource_type = 'vehicle' THEN 1 END) as vehicle_count
    FROM resources
")->fetch();

// Now include header for HTML output
$pageTitle = 'Resource Management';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h4><i class="bi bi-box-seam"></i> Resource Management</h4>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addResourceModal">
        <i class="bi bi-plus-lg"></i> Add Resource
    </button>
</div>

<!-- Resource Statistics -->
<div class="row mb-3">
    <div class="col-md-3">
        <div class="card bg-primary bg-opacity-10 border-primary">
            <div class="card-body">
                <h6 class="text-primary"><i class="bi bi-box-seam"></i> Total Resources</h6>
                <h3 class="mb-0"><?= $stats['total_resources'] ?></h3>
                <small class="text-muted">
                    Equipment: <?= $stats['equipment_count'] ?> | Rooms: <?= $stats['room_count'] ?> | Vehicles: <?= $stats['vehicle_count'] ?>
                </small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-success bg-opacity-10 border-success">
            <div class="card-body">
                <h6 class="text-success"><i class="bi bi-check-circle"></i> Available</h6>
                <h3 class="mb-0"><?= $stats['available_count'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-warning bg-opacity-10 border-warning">
            <div class="card-body">
                <h6 class="text-warning"><i class="bi bi-arrow-repeat"></i> In Use</h6>
                <h3 class="mb-0"><?= $stats['in_use_count'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-danger bg-opacity-10 border-danger">
            <div class="card-body">
                <h6 class="text-danger"><i class="bi bi-tools"></i> Maintenance</h6>
                <h3 class="mb-0"><?= $stats['maintenance_count'] ?></h3>
            </div>
        </div>
    </div>
</div>

<!-- Active Allocations -->
<?php if (!empty($activeAllocations)): ?>
<div class="card mb-3">
    <div class="card-header bg-warning bg-opacity-10"><i class="bi bi-arrow-right-circle"></i> Active Allocations</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-hover">
                <thead>
                    <tr>
                        <th>Resource</th>
                        <th>Type</th>
                        <th>Allocated To</th>
                        <th>Purpose</th>
                        <th>Since</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($activeAllocations as $alloc): ?>
                    <tr>
                        <td><strong><?= sanitize($alloc['resource_name']) ?></strong></td>
                        <td><span class="badge bg-secondary"><?= ucfirst($alloc['resource_type']) ?></span></td>
                        <td>
                            <?= sanitize($alloc['full_name']) ?>
                            <br><small class="text-muted"><?= ucfirst($alloc['role']) ?></small>
                        </td>
                        <td><?= sanitize($alloc['purpose']) ?></td>
                        <td><?= date('M d, Y', strtotime($alloc['allocated_at'])) ?></td>
                        <td>
                            <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal"
                                    data-bs-target="#returnModal<?= $alloc['id'] ?>">
                                <i class="bi bi-arrow-return-left"></i> Return
                            </button>

                            <!-- Return Modal -->
                            <div class="modal fade" id="returnModal<?= $alloc['id'] ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Return Resource</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form method="POST">
                                            <div class="modal-body">
                                                <input type="hidden" name="action" value="return_resource">
                                                <input type="hidden" name="allocation_id" value="<?= $alloc['id'] ?>">
                                                <input type="hidden" name="resource_id" value="<?= $alloc['resource_id'] ?>">

                                                <div class="alert alert-info">
                                                    <strong>Resource:</strong> <?= sanitize($alloc['resource_name']) ?><br>
                                                    <strong>Currently with:</strong> <?= sanitize($alloc['full_name']) ?>
                                                </div>

                                                <div class="mb-3">
                                                    <label class="form-label">Return Status *</label>
                                                    <select name="return_status" class="form-select" required>
                                                        <option value="returned">Returned (Good Condition)</option>
                                                        <option value="damaged">Damaged</option>
                                                        <option value="lost">Lost</option>
                                                        <option value="maintenance">Needs Maintenance</option>
                                                    </select>
                                                </div>

                                                <div class="mb-3">
                                                    <label class="form-label">Notes</label>
                                                    <textarea name="return_notes" class="form-control" rows="2"></textarea>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-success">Process Return</button>
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
<?php endif; ?>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Resource Type</label>
                <select name="type" class="form-select">
                    <option value="">All Types</option>
                    <option value="equipment" <?= $filterType === 'equipment' ? 'selected' : '' ?>>Equipment</option>
                    <option value="room" <?= $filterType === 'room' ? 'selected' : '' ?>>Room</option>
                    <option value="vehicle" <?= $filterType === 'vehicle' ? 'selected' : '' ?>>Vehicle</option>
                    <option value="other" <?= $filterType === 'other' ? 'selected' : '' ?>>Other</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="">All Status</option>
                    <option value="available" <?= $filterStatus === 'available' ? 'selected' : '' ?>>Available</option>
                    <option value="in-use" <?= $filterStatus === 'in-use' ? 'selected' : '' ?>>In Use</option>
                    <option value="maintenance" <?= $filterStatus === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
                    <option value="retired" <?= $filterStatus === 'retired' ? 'selected' : '' ?>>Retired</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Department</label>
                <select name="dept_id" class="form-select">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $dept): ?>
                    <option value="<?= $dept['id'] ?>" <?= $filterDept == $dept['id'] ? 'selected' : '' ?>>
                        <?= sanitize($dept['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <button type="submit" class="btn btn-outline-primary me-2">Filter</button>
                <a href="resources.php" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Resources Table -->
<div class="card">
    <div class="card-body">
        <table class="table table-hover data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Type</th>
                    <th>Category</th>
                    <th>Serial #</th>
                    <th>Department</th>
                    <th>Status</th>
                    <th>Assigned To</th>
                    <th>Location</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($resources)): ?>
                <tr><td colspan="9" class="text-center text-muted py-3">No resources found</td></tr>
                <?php else: foreach ($resources as $res): ?>
                <tr>
                    <td><strong><?= sanitize($res['name']) ?></strong></td>
                    <td><span class="badge bg-secondary"><?= ucfirst($res['resource_type']) ?></span></td>
                    <td><?= sanitize($res['category'] ?? '-') ?></td>
                    <td><small class="text-muted"><?= sanitize($res['serial_number'] ?? '-') ?></small></td>
                    <td><?= sanitize($res['dept_name'] ?? '-') ?></td>
                    <td>
                        <?php
                        $statusColors = [
                            'available' => 'success',
                            'in-use' => 'warning',
                            'maintenance' => 'danger',
                            'retired' => 'secondary'
                        ];
                        $color = $statusColors[$res['status']] ?? 'secondary';
                        ?>
                        <span class="badge bg-<?= $color ?>"><?= ucfirst($res['status']) ?></span>
                    </td>
                    <td><?= $res['assigned_name'] ? sanitize($res['assigned_name']) : '-' ?></td>
                    <td><?= sanitize($res['location'] ?? '-') ?></td>
                    <td>
                        <?php if ($res['status'] === 'available'): ?>
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal"
                                data-bs-target="#allocateModal<?= $res['id'] ?>">
                            <i class="bi bi-arrow-right"></i> Allocate
                        </button>
                        <?php endif; ?>

                        <?php if ($res['status'] === 'maintenance'): ?>
                        <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal"
                                data-bs-target="#maintenanceModal<?= $res['id'] ?>">
                            <i class="bi bi-tools"></i> Complete
                        </button>
                        <?php endif; ?>

                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this resource?')">
                            <input type="hidden" name="action" value="delete_resource">
                            <input type="hidden" name="id" value="<?= $res['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>

                        <!-- Allocate Modal -->
                        <div class="modal fade" id="allocateModal<?= $res['id'] ?>" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Allocate Resource</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <form method="POST">
                                        <div class="modal-body">
                                            <input type="hidden" name="action" value="allocate_resource">
                                            <input type="hidden" name="resource_id" value="<?= $res['id'] ?>">

                                            <div class="alert alert-info">
                                                <strong>Resource:</strong> <?= sanitize($res['name']) ?><br>
                                                <strong>Type:</strong> <?= ucfirst($res['resource_type']) ?>
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label">Allocate To *</label>
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
                                                <label class="form-label">Allocation Date *</label>
                                                <input type="datetime-local" name="allocated_at" class="form-control"
                                                       value="<?= date('Y-m-d\TH:i') ?>" required>
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label">Purpose *</label>
                                                <input type="text" name="purpose" class="form-control"
                                                       placeholder="e.g., Surgery, Patient care, Transport" required>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-primary">Allocate</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- Maintenance Complete Modal -->
                        <div class="modal fade" id="maintenanceModal<?= $res['id'] ?>" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Complete Maintenance</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <form method="POST">
                                        <div class="modal-body">
                                            <input type="hidden" name="action" value="update_maintenance">
                                            <input type="hidden" name="resource_id" value="<?= $res['id'] ?>">

                                            <div class="alert alert-info">
                                                <strong>Resource:</strong> <?= sanitize($res['name']) ?>
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label">Maintenance Date *</label>
                                                <input type="date" name="maintenance_date" class="form-control"
                                                       value="<?= date('Y-m-d') ?>" required>
                                            </div>

                                            <p class="text-muted">
                                                <i class="bi bi-info-circle"></i> This will mark the resource as available.
                                            </p>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-success">Complete Maintenance</button>
                                        </div>
                                    </form>
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

<!-- Add Resource Modal -->
<div class="modal fade" id="addResourceModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Resource</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_resource">

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Resource Name *</label>
                            <input type="text" name="name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Type *</label>
                            <select name="resource_type" class="form-select" required>
                                <option value="">Select Type</option>
                                <option value="equipment">Equipment</option>
                                <option value="room">Room</option>
                                <option value="vehicle">Vehicle</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Category</label>
                            <input type="text" name="category" class="form-control" placeholder="e.g., Medical, IT, Transport">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Serial Number</label>
                            <input type="text" name="serial_number" class="form-control">
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Department</label>
                            <select name="department_id" class="form-select">
                                <option value="">None</option>
                                <?php foreach ($departments as $dept): ?>
                                <option value="<?= $dept['id'] ?>"><?= sanitize($dept['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status *</label>
                            <select name="status" class="form-select" required>
                                <option value="available">Available</option>
                                <option value="maintenance">Maintenance</option>
                                <option value="retired">Retired</option>
                            </select>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Purchase Date</label>
                            <input type="date" name="purchase_date" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Warranty Expiry</label>
                            <input type="date" name="warranty_expiry" class="form-control">
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Maintenance Schedule</label>
                            <input type="text" name="maintenance_schedule" class="form-control"
                                   placeholder="e.g., Monthly, Quarterly, Annually">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Location</label>
                            <input type="text" name="location" class="form-control" placeholder="e.g., Room 101, Basement">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Resource</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
