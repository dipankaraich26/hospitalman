<?php
$pageTitle = 'User Management';
require_once __DIR__ . '/../../includes/header.php';
requireRole(['admin']);

$pdo = getDBConnection();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $username = trim($_POST['username']);
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $full_name = trim($_POST['full_name']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        $role = $_POST['role'];
        $specialization = trim($_POST['specialization'] ?? '');

        $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $check->execute([$username]);
        if ($check->fetch()) {
            setFlashMessage('error', 'Username already exists.');
        } else {
            $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, email, phone, role, specialization) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$username, $password, $full_name, $email, $phone, $role, $specialization ?: null]);
            auditLog('create', 'users', 'users', (int)$pdo->lastInsertId(), null, ['username' => $username, 'role' => $role]);
            setFlashMessage('success', 'User created successfully.');
        }
        header('Location: manage_users.php');
        exit;
    }

    if ($action === 'edit') {
        $id = (int) $_POST['id'];
        $full_name = trim($_POST['full_name']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        $role = $_POST['role'];
        $status = $_POST['status'];
        $specialization = trim($_POST['specialization'] ?? '');

        $stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, phone = ?, role = ?, status = ?, specialization = ? WHERE id = ?");
        $stmt->execute([$full_name, $email, $phone, $role, $status, $specialization ?: null, $id]);

        if (!empty($_POST['password'])) {
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$password, $id]);
        }
        auditLog('update', 'users', 'users', $id, null, ['full_name' => $full_name, 'role' => $role, 'status' => $status]);
        setFlashMessage('success', 'User updated successfully.');
        header('Location: manage_users.php');
        exit;
    }

    if ($action === 'delete') {
        $id = (int) $_POST['id'];
        if ($id === $_SESSION['user_id']) {
            setFlashMessage('error', 'You cannot delete your own account.');
        } else {
            auditLog('delete', 'users', 'users', $id);
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$id]);
            setFlashMessage('success', 'User deleted successfully.');
        }
        header('Location: manage_users.php');
        exit;
    }
}

$users = $pdo->query("SELECT * FROM users ORDER BY created_at DESC")->fetchAll();
?>

<div class="page-header">
    <h4><i class="bi bi-person-gear"></i> User Management</h4>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
        <i class="bi bi-plus-lg"></i> Add User
    </button>
</div>

<div class="card">
    <div class="card-body">
        <table class="table data-table">
            <thead>
                <tr>
                    <th>Username</th>
                    <th>Full Name</th>
                    <th>Role</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                <tr>
                    <td><?= sanitize($user['username']) ?></td>
                    <td><?= sanitize($user['full_name']) ?></td>
                    <td><span class="badge bg-info"><?= ucfirst($user['role']) ?></span></td>
                    <td><?= sanitize($user['email'] ?? '') ?></td>
                    <td><?= sanitize($user['phone'] ?? '') ?></td>
                    <td><?= getStatusBadge($user['status']) ?></td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary edit-user-btn"
                                data-bs-toggle="modal" data-bs-target="#editUserModal"
                                data-id="<?= $user['id'] ?>"
                                data-username="<?= sanitize($user['username']) ?>"
                                data-fullname="<?= sanitize($user['full_name']) ?>"
                                data-email="<?= sanitize($user['email'] ?? '') ?>"
                                data-phone="<?= sanitize($user['phone'] ?? '') ?>"
                                data-role="<?= $user['role'] ?>"
                                data-status="<?= $user['status'] ?>"
                                data-specialization="<?= sanitize($user['specialization'] ?? '') ?>">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <?php if ($user['id'] !== $_SESSION['user_id']): ?>
                        <form method="POST" class="d-inline" onsubmit="return confirmDelete('Delete this user?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $user['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="add">
            <div class="modal-header">
                <h5 class="modal-title">Add New User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Username *</label>
                    <input type="text" name="username" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Password *</label>
                    <input type="password" name="password" class="form-control" required minlength="6">
                </div>
                <div class="mb-3">
                    <label class="form-label">Full Name *</label>
                    <input type="text" name="full_name" class="form-control" required>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" class="form-control">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Role *</label>
                    <select name="role" class="form-select" required>
                        <option value="receptionist">Receptionist</option>
                        <option value="nurse">Nurse</option>
                        <option value="doctor">Doctor</option>
                        <option value="pharmacist">Pharmacist</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Specialization (Doctors only)</label>
                    <input type="text" name="specialization" class="form-control" placeholder="e.g. Cardiology">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Create User</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit User Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="edit-id">
            <div class="modal-header">
                <h5 class="modal-title">Edit User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Username</label>
                    <input type="text" id="edit-username" class="form-control" disabled>
                </div>
                <div class="mb-3">
                    <label class="form-label">New Password (leave blank to keep)</label>
                    <input type="password" name="password" class="form-control" minlength="6">
                </div>
                <div class="mb-3">
                    <label class="form-label">Full Name *</label>
                    <input type="text" name="full_name" id="edit-fullname" class="form-control" required>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" id="edit-email" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" id="edit-phone" class="form-control">
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Role *</label>
                        <select name="role" id="edit-role" class="form-select" required>
                            <option value="receptionist">Receptionist</option>
                            <option value="nurse">Nurse</option>
                            <option value="doctor">Doctor</option>
                            <option value="pharmacist">Pharmacist</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Status *</label>
                        <select name="status" id="edit-status" class="form-select" required>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Specialization</label>
                    <input type="text" name="specialization" id="edit-specialization" class="form-control">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
document.querySelectorAll('.edit-user-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.getElementById('edit-id').value = this.dataset.id;
        document.getElementById('edit-username').value = this.dataset.username;
        document.getElementById('edit-fullname').value = this.dataset.fullname;
        document.getElementById('edit-email').value = this.dataset.email;
        document.getElementById('edit-phone').value = this.dataset.phone;
        document.getElementById('edit-role').value = this.dataset.role;
        document.getElementById('edit-status').value = this.dataset.status;
        document.getElementById('edit-specialization').value = this.dataset.specialization;
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
