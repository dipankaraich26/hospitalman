<?php
$pageTitle = 'Lab Tests';
require_once __DIR__ . '/../../includes/header.php';
requireRole(['admin', 'doctor', 'nurse']);

$pdo = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $stmt = $pdo->prepare("INSERT INTO lab_tests (patient_id, doctor_id, test_name, test_date, status, notes) VALUES (?,?,?,?,?,?)");
        $stmt->execute([
            (int) $_POST['patient_id'],
            (int) $_POST['doctor_id'],
            trim($_POST['test_name']),
            $_POST['test_date'],
            'ordered',
            trim($_POST['notes'])
        ]);
        setFlashMessage('success', 'Lab test ordered successfully.');
        header('Location: lab_tests.php');
        exit;
    }

    if ($action === 'update_result') {
        $stmt = $pdo->prepare("UPDATE lab_tests SET result = ?, status = 'completed', notes = ? WHERE id = ?");
        $stmt->execute([trim($_POST['result']), trim($_POST['notes']), (int)$_POST['id']]);
        setFlashMessage('success', 'Test result updated.');
        header('Location: lab_tests.php');
        exit;
    }

    if ($action === 'delete') {
        $pdo->prepare("DELETE FROM lab_tests WHERE id = ?")->execute([(int)$_POST['id']]);
        setFlashMessage('success', 'Lab test deleted.');
        header('Location: lab_tests.php');
        exit;
    }
}

$filter = $_GET['status'] ?? 'all';
$where = $filter !== 'all' ? "WHERE lt.status = " . $pdo->quote($filter) : "";

$tests = $pdo->query("
    SELECT lt.*, p.first_name, p.last_name, p.patient_id as pid, u.full_name as doctor_name
    FROM lab_tests lt
    JOIN patients p ON lt.patient_id = p.id
    JOIN users u ON lt.doctor_id = u.id
    $where
    ORDER BY lt.created_at DESC
")->fetchAll();

$patients = $pdo->query("SELECT id, patient_id, first_name, last_name FROM patients ORDER BY first_name")->fetchAll();
$doctors = getDoctors();
?>

<div class="page-header">
    <h4><i class="bi bi-clipboard2-data"></i> Lab Tests</h4>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#orderTestModal"><i class="bi bi-plus-lg"></i> Order Test</button>
</div>

<ul class="nav nav-pills mb-3">
    <li class="nav-item"><a class="nav-link <?= $filter === 'all' ? 'active' : '' ?>" href="?status=all">All</a></li>
    <li class="nav-item"><a class="nav-link <?= $filter === 'ordered' ? 'active' : '' ?>" href="?status=ordered">Ordered</a></li>
    <li class="nav-item"><a class="nav-link <?= $filter === 'in_progress' ? 'active' : '' ?>" href="?status=in_progress">In Progress</a></li>
    <li class="nav-item"><a class="nav-link <?= $filter === 'completed' ? 'active' : '' ?>" href="?status=completed">Completed</a></li>
</ul>

<div class="card">
    <div class="card-body">
        <table class="table table-hover data-table">
            <thead>
                <tr><th>Patient</th><th>Test</th><th>Doctor</th><th>Date</th><th>Status</th><th>Result</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($tests as $t): ?>
                <tr>
                    <td><?= sanitize($t['first_name'] . ' ' . $t['last_name']) ?><br><small class="text-muted"><?= sanitize($t['pid']) ?></small></td>
                    <td><strong><?= sanitize($t['test_name']) ?></strong></td>
                    <td><?= sanitize($t['doctor_name']) ?></td>
                    <td><?= formatDate($t['test_date']) ?></td>
                    <td><?= getStatusBadge($t['status']) ?></td>
                    <td><small><?= sanitize($t['result'] ?? '-') ?></small></td>
                    <td>
                        <?php if ($t['status'] !== 'completed'): ?>
                        <button class="btn btn-sm btn-outline-success result-btn"
                                data-bs-toggle="modal" data-bs-target="#resultModal"
                                data-id="<?= $t['id'] ?>" data-test="<?= sanitize($t['test_name']) ?>"
                                data-notes="<?= sanitize($t['notes'] ?? '') ?>">
                            <i class="bi bi-pencil-square"></i> Result
                        </button>
                        <?php endif; ?>
                        <form method="POST" class="d-inline" onsubmit="return confirmDelete('Delete this test?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $t['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Order Test Modal -->
<div class="modal fade" id="orderTestModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="add">
            <div class="modal-header"><h5 class="modal-title">Order Lab Test</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Patient *</label>
                    <select name="patient_id" class="form-select" required>
                        <option value="">Select Patient</option>
                        <?php foreach ($patients as $p): ?>
                        <option value="<?= $p['id'] ?>"><?= sanitize($p['patient_id'] . ' - ' . $p['first_name'] . ' ' . $p['last_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Doctor *</label>
                    <select name="doctor_id" class="form-select" required>
                        <?php foreach ($doctors as $d): ?>
                        <option value="<?= $d['id'] ?>"><?= sanitize($d['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Test Name *</label>
                    <input type="text" name="test_name" class="form-control" required placeholder="e.g. Complete Blood Count">
                </div>
                <div class="mb-3">
                    <label class="form-label">Test Date *</label>
                    <input type="date" name="test_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Order Test</button>
            </div>
        </form>
    </div>
</div>

<!-- Update Result Modal -->
<div class="modal fade" id="resultModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="update_result">
            <input type="hidden" name="id" id="result-id">
            <div class="modal-header"><h5 class="modal-title">Update Test Result</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <p><strong>Test:</strong> <span id="result-test"></span></p>
                <div class="mb-3">
                    <label class="form-label">Result *</label>
                    <textarea name="result" class="form-control" rows="3" required></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" id="result-notes" class="form-control" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-success">Save Result</button>
            </div>
        </form>
    </div>
</div>

<script>
document.querySelectorAll('.result-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.getElementById('result-id').value = this.dataset.id;
        document.getElementById('result-test').textContent = this.dataset.test;
        document.getElementById('result-notes').value = this.dataset.notes;
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
