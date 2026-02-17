<?php
// Initialize auth and functions before header to allow redirects
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$pdo = getDBConnection();
$isAdmin = in_array($_SESSION['role'], ['admin']);
$currentUserId = $_SESSION['user_id'];

// Handle POST before header include
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'request_leave') {
        // Calculate days count
        $start = new DateTime($_POST['start_date']);
        $end = new DateTime($_POST['end_date']);
        $days = $start->diff($end)->days + 1;

        $stmt = $pdo->prepare("INSERT INTO staff_leaves (staff_id, leave_type, start_date, end_date, days_count, reason, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
        $stmt->execute([
            $currentUserId,
            $_POST['leave_type'],
            $_POST['start_date'],
            $_POST['end_date'],
            $days,
            trim($_POST['reason'])
        ]);
        auditLog('create', 'staff', 'staff_leaves', $pdo->lastInsertId(), null, $_POST);
        setFlashMessage('success', 'Leave request submitted successfully.');
        header('Location: leaves.php');
        exit;
    }

    if ($_POST['action'] === 'approve_leave' && $isAdmin) {
        $stmt = $pdo->prepare("UPDATE staff_leaves SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
        $stmt->execute([$currentUserId, (int) $_POST['id']]);
        auditLog('update', 'staff', 'staff_leaves', (int) $_POST['id'], null, ['status' => 'approved']);
        setFlashMessage('success', 'Leave approved.');
        header('Location: leaves.php');
        exit;
    }

    if ($_POST['action'] === 'reject_leave' && $isAdmin) {
        $stmt = $pdo->prepare("UPDATE staff_leaves SET status = 'rejected', approved_by = ?, approved_at = NOW(), rejection_reason = ? WHERE id = ?");
        $stmt->execute([$currentUserId, trim($_POST['rejection_reason']), (int) $_POST['id']]);
        auditLog('update', 'staff', 'staff_leaves', (int) $_POST['id'], null, ['status' => 'rejected', 'reason' => $_POST['rejection_reason']]);
        setFlashMessage('warning', 'Leave rejected.');
        header('Location: leaves.php');
        exit;
    }

    if ($_POST['action'] === 'cancel_leave') {
        $stmt = $pdo->prepare("UPDATE staff_leaves SET status = 'cancelled' WHERE id = ? AND staff_id = ? AND status = 'pending'");
        $stmt->execute([(int) $_POST['id'], $currentUserId]);
        if ($stmt->rowCount() > 0) {
            auditLog('update', 'staff', 'staff_leaves', (int) $_POST['id'], null, ['status' => 'cancelled']);
            setFlashMessage('info', 'Leave request cancelled.');
        }
        header('Location: leaves.php');
        exit;
    }
}

// Filters
$filterStatus = $_GET['status'] ?? '';
$filterStaff = $_GET['staff_id'] ?? ($isAdmin ? '' : $currentUserId);
$filterType = $_GET['type'] ?? '';

// Build query
$where = "1=1";
$params = [];

if (!$isAdmin) {
    $where .= " AND l.staff_id = ?";
    $params[] = $currentUserId;
} elseif ($filterStaff) {
    $where .= " AND l.staff_id = ?";
    $params[] = $filterStaff;
}

if ($filterStatus) {
    $where .= " AND l.status = ?";
    $params[] = $filterStatus;
}

if ($filterType) {
    $where .= " AND l.leave_type = ?";
    $params[] = $filterType;
}

$stmt = $pdo->prepare("
    SELECT l.*, u.full_name, u.role, a.full_name as approver_name
    FROM staff_leaves l
    JOIN users u ON l.staff_id = u.id
    LEFT JOIN users a ON l.approved_by = a.id
    WHERE $where
    ORDER BY l.created_at DESC
");
$stmt->execute($params);
$leaves = $stmt->fetchAll();

// Get all staff for dropdown (admin only)
$allStaff = [];
if ($isAdmin) {
    $allStaff = $pdo->query("SELECT id, full_name, role FROM users WHERE status = 'active' ORDER BY full_name")->fetchAll();
}

// Leave statistics
$stats = $pdo->prepare("
    SELECT
        COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_count,
        COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved_count,
        SUM(CASE WHEN status = 'approved' THEN days_count ELSE 0 END) as total_days_taken
    FROM staff_leaves
    WHERE staff_id = ? AND YEAR(start_date) = YEAR(CURDATE())
");
$stats->execute([$currentUserId]);
$myStats = $stats->fetch();

// Now include header for HTML output
$pageTitle = 'Leave Management';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h4><i class="bi bi-calendar-x"></i> Leave Management</h4>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#requestLeaveModal">
        <i class="bi bi-plus-lg"></i> Request Leave
    </button>
</div>

<!-- My Leave Statistics -->
<div class="row mb-3">
    <div class="col-md-4">
        <div class="card bg-warning bg-opacity-10 border-warning">
            <div class="card-body">
                <h6 class="text-warning"><i class="bi bi-clock-history"></i> Pending Requests</h6>
                <h3 class="mb-0"><?= $myStats['pending_count'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card bg-success bg-opacity-10 border-success">
            <div class="card-body">
                <h6 class="text-success"><i class="bi bi-check-circle"></i> Approved (This Year)</h6>
                <h3 class="mb-0"><?= $myStats['approved_count'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card bg-info bg-opacity-10 border-info">
            <div class="card-body">
                <h6 class="text-info"><i class="bi bi-calendar-day"></i> Days Taken (This Year)</h6>
                <h3 class="mb-0"><?= $myStats['total_days_taken'] ?> days</h3>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <?php if ($isAdmin): ?>
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
            <?php endif; ?>
            <div class="col-md-3">
                <label class="form-label">Leave Type</label>
                <select name="type" class="form-select">
                    <option value="">All Types</option>
                    <option value="sick" <?= $filterType === 'sick' ? 'selected' : '' ?>>Sick Leave</option>
                    <option value="vacation" <?= $filterType === 'vacation' ? 'selected' : '' ?>>Vacation</option>
                    <option value="personal" <?= $filterType === 'personal' ? 'selected' : '' ?>>Personal</option>
                    <option value="emergency" <?= $filterType === 'emergency' ? 'selected' : '' ?>>Emergency</option>
                    <option value="maternity" <?= $filterType === 'maternity' ? 'selected' : '' ?>>Maternity</option>
                    <option value="paternity" <?= $filterType === 'paternity' ? 'selected' : '' ?>>Paternity</option>
                    <option value="unpaid" <?= $filterType === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="">All Status</option>
                    <option value="pending" <?= $filterStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="approved" <?= $filterStatus === 'approved' ? 'selected' : '' ?>>Approved</option>
                    <option value="rejected" <?= $filterStatus === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                    <option value="cancelled" <?= $filterStatus === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <button type="submit" class="btn btn-outline-primary me-2">Filter</button>
                <a href="leaves.php" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Leave Requests Table -->
<div class="card">
    <div class="card-body">
        <table class="table table-hover data-table">
            <thead>
                <tr>
                    <?php if ($isAdmin): ?><th>Staff</th><?php endif; ?>
                    <th>Leave Type</th>
                    <th>Start Date</th>
                    <th>End Date</th>
                    <th>Days</th>
                    <th>Reason</th>
                    <th>Status</th>
                    <?php if ($isAdmin): ?><th>Approver</th><?php endif; ?>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($leaves)): ?>
                <tr><td colspan="<?= $isAdmin ? 9 : 7 ?>" class="text-center text-muted py-3">No leave requests found</td></tr>
                <?php else: foreach ($leaves as $leave): ?>
                <tr>
                    <?php if ($isAdmin): ?>
                    <td>
                        <strong><?= sanitize($leave['full_name']) ?></strong>
                        <br><small class="text-muted"><?= ucfirst($leave['role']) ?></small>
                    </td>
                    <?php endif; ?>
                    <td><span class="badge bg-secondary"><?= ucfirst($leave['leave_type']) ?></span></td>
                    <td><?= formatDate($leave['start_date']) ?></td>
                    <td><?= formatDate($leave['end_date']) ?></td>
                    <td><strong><?= $leave['days_count'] ?></strong> days</td>
                    <td><?= sanitize($leave['reason']) ?></td>
                    <td>
                        <?php
                        $statusColors = [
                            'pending' => 'warning',
                            'approved' => 'success',
                            'rejected' => 'danger',
                            'cancelled' => 'secondary'
                        ];
                        $color = $statusColors[$leave['status']] ?? 'secondary';
                        ?>
                        <span class="badge bg-<?= $color ?>"><?= ucfirst($leave['status']) ?></span>
                        <?php if ($leave['status'] === 'rejected' && $leave['rejection_reason']): ?>
                        <br><small class="text-muted" title="<?= sanitize($leave['rejection_reason']) ?>">
                            <i class="bi bi-info-circle"></i> Reason
                        </small>
                        <?php endif; ?>
                    </td>
                    <?php if ($isAdmin): ?>
                    <td><?= $leave['approver_name'] ? sanitize($leave['approver_name']) : '-' ?></td>
                    <?php endif; ?>
                    <td>
                        <?php if ($leave['status'] === 'pending'): ?>
                            <?php if ($isAdmin): ?>
                            <div class="btn-group btn-group-sm">
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="action" value="approve_leave">
                                    <input type="hidden" name="id" value="<?= $leave['id'] ?>">
                                    <button class="btn btn-sm btn-success" title="Approve">
                                        <i class="bi bi-check-lg"></i>
                                    </button>
                                </form>
                                <button class="btn btn-sm btn-danger" title="Reject"
                                        data-bs-toggle="modal"
                                        data-bs-target="#rejectModal<?= $leave['id'] ?>">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </div>
                            <!-- Reject Modal -->
                            <div class="modal fade" id="rejectModal<?= $leave['id'] ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Reject Leave Request</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form method="POST">
                                            <div class="modal-body">
                                                <input type="hidden" name="action" value="reject_leave">
                                                <input type="hidden" name="id" value="<?= $leave['id'] ?>">
                                                <div class="mb-3">
                                                    <label class="form-label">Rejection Reason *</label>
                                                    <textarea name="rejection_reason" class="form-control" rows="3" required></textarea>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-danger">Reject Leave</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <?php elseif ($leave['staff_id'] == $currentUserId): ?>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Cancel this leave request?')">
                                <input type="hidden" name="action" value="cancel_leave">
                                <input type="hidden" name="id" value="<?= $leave['id'] ?>">
                                <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-circle"></i> Cancel</button>
                            </form>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Request Leave Modal -->
<div class="modal fade" id="requestLeaveModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Request Leave</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="leaveForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="request_leave">

                    <div class="mb-3">
                        <label class="form-label">Leave Type *</label>
                        <select name="leave_type" class="form-select" required>
                            <option value="">Select Type</option>
                            <option value="sick">Sick Leave</option>
                            <option value="vacation">Vacation</option>
                            <option value="personal">Personal Leave</option>
                            <option value="emergency">Emergency Leave</option>
                            <option value="maternity">Maternity Leave</option>
                            <option value="paternity">Paternity Leave</option>
                            <option value="unpaid">Unpaid Leave</option>
                        </select>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Start Date *</label>
                            <input type="date" name="start_date" id="startDate" class="form-control" min="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">End Date *</label>
                            <input type="date" name="end_date" id="endDate" class="form-control" min="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Reason *</label>
                        <textarea name="reason" class="form-control" rows="3" required></textarea>
                    </div>

                    <div class="alert alert-info mb-0">
                        <i class="bi bi-info-circle"></i> Your leave request will be sent to the admin for approval.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Submit Request</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Ensure end date is after start date
document.getElementById('startDate').addEventListener('change', function() {
    document.getElementById('endDate').min = this.value;
    if (document.getElementById('endDate').value < this.value) {
        document.getElementById('endDate').value = this.value;
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
