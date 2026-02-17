<?php
$pageTitle = 'Appointments';
require_once __DIR__ . '/../../includes/header.php';
requireRole(['admin', 'doctor', 'nurse']);

$pdo = getDBConnection();

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_status') {
        $apptId = (int)$_POST['id'];
        $stmt = $pdo->prepare("UPDATE appointments SET status = ? WHERE id = ?");
        $stmt->execute([$_POST['status'], $apptId]);
        auditLog('update', 'clinical', 'appointments', $apptId, null, ['status' => $_POST['status']]);
        setFlashMessage('success', 'Appointment status updated.');
        header('Location: index.php');
        exit;
    }
    if ($_POST['action'] === 'delete') {
        $apptId = (int)$_POST['id'];
        auditLog('delete', 'clinical', 'appointments', $apptId);
        $stmt = $pdo->prepare("DELETE FROM appointments WHERE id = ?");
        $stmt->execute([$apptId]);
        setFlashMessage('success', 'Appointment deleted.');
        header('Location: index.php');
        exit;
    }
}

$filter = $_GET['filter'] ?? 'today';
$where = "1=1";
if ($filter === 'today') $where = "a.appointment_date = CURDATE()";
elseif ($filter === 'upcoming') $where = "a.appointment_date >= CURDATE() AND a.status = 'scheduled'";
elseif ($filter === 'past') $where = "a.appointment_date < CURDATE()";

$appointments = $pdo->query("
    SELECT a.*, p.first_name, p.last_name, p.patient_id as pid, u.full_name as doctor_name, u.specialization
    FROM appointments a
    JOIN patients p ON a.patient_id = p.id
    JOIN users u ON a.doctor_id = u.id
    WHERE $where
    ORDER BY a.appointment_date DESC, a.appointment_time
")->fetchAll();
?>

<div class="page-header">
    <h4><i class="bi bi-calendar3"></i> Appointments</h4>
    <a href="appointment.php" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Book Appointment</a>
</div>

<!-- Filter Tabs -->
<ul class="nav nav-pills mb-3">
    <li class="nav-item"><a class="nav-link <?= $filter === 'today' ? 'active' : '' ?>" href="?filter=today">Today</a></li>
    <li class="nav-item"><a class="nav-link <?= $filter === 'upcoming' ? 'active' : '' ?>" href="?filter=upcoming">Upcoming</a></li>
    <li class="nav-item"><a class="nav-link <?= $filter === 'past' ? 'active' : '' ?>" href="?filter=past">Past</a></li>
    <li class="nav-item"><a class="nav-link <?= $filter === 'all' ? 'active' : '' ?>" href="?filter=all">All</a></li>
</ul>

<div class="card">
    <div class="card-body">
        <table class="table table-hover data-table">
            <thead>
                <tr>
                    <th>Patient</th>
                    <th>Doctor</th>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Status</th>
                    <th>Notes</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($appointments as $a): ?>
                <tr>
                    <td>
                        <a href="<?= BASE_URL ?>/modules/patients/view.php?id=<?= $a['patient_id'] ?>">
                            <?= sanitize($a['first_name'] . ' ' . $a['last_name']) ?>
                        </a>
                        <br><small class="text-muted"><?= sanitize($a['pid']) ?></small>
                    </td>
                    <td><?= sanitize($a['doctor_name']) ?><br><small class="text-muted"><?= sanitize($a['specialization'] ?? '') ?></small></td>
                    <td><?= formatDate($a['appointment_date']) ?></td>
                    <td><?= date('h:i A', strtotime($a['appointment_time'])) ?></td>
                    <td><?= getStatusBadge($a['status']) ?></td>
                    <td><small><?= sanitize($a['notes'] ?? '-') ?></small></td>
                    <td>
                        <?php if ($a['status'] === 'scheduled'): ?>
                        <div class="btn-group btn-group-sm">
                            <a href="consultation.php?appointment_id=<?= $a['id'] ?>" class="btn btn-outline-success" title="Start Consultation"><i class="bi bi-clipboard2-pulse"></i></a>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="id" value="<?= $a['id'] ?>">
                                <input type="hidden" name="status" value="completed">
                                <button class="btn btn-outline-primary" title="Mark Complete"><i class="bi bi-check-lg"></i></button>
                            </form>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="id" value="<?= $a['id'] ?>">
                                <input type="hidden" name="status" value="cancelled">
                                <button class="btn btn-outline-danger" title="Cancel"><i class="bi bi-x-lg"></i></button>
                            </form>
                        </div>
                        <?php endif; ?>
                        <form method="POST" class="d-inline" onsubmit="return confirmDelete('Delete this appointment?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $a['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
