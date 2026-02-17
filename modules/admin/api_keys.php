<?php
// Initialize auth and functions
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requireRole(['admin']);

$pdo = getDBConnection();
$pageTitle = 'API Keys';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'generate') {
        $apiKey = bin2hex(random_bytes(32));
        $stmt = $pdo->prepare("INSERT INTO api_keys (user_id, api_key, name, expires_at) VALUES (?,?,?,?)");
        $stmt->execute([
            (int)$_POST['user_id'],
            $apiKey,
            trim($_POST['name']),
            $_POST['expires_at'] ?: null
        ]);
        auditLog('create', 'admin', 'api_keys', (int)$pdo->lastInsertId(), null, ['name' => $_POST['name']]);
        setFlashMessage('success', 'API Key generated: ' . $apiKey);
        header('Location: api_keys.php');
        exit;
    }

    if ($action === 'toggle') {
        $keyId = (int)$_POST['id'];
        $pdo->prepare("UPDATE api_keys SET is_active = NOT is_active WHERE id = ?")->execute([$keyId]);
        auditLog('update', 'admin', 'api_keys', $keyId, null, ['toggled' => true]);
        setFlashMessage('success', 'API key status updated.');
        header('Location: api_keys.php');
        exit;
    }

    if ($action === 'delete') {
        $keyId = (int)$_POST['id'];
        auditLog('delete', 'admin', 'api_keys', $keyId);
        $pdo->prepare("DELETE FROM api_keys WHERE id = ?")->execute([$keyId]);
        setFlashMessage('success', 'API key deleted.');
        header('Location: api_keys.php');
        exit;
    }
}

// Include header after POST processing to prevent "headers already sent" error
require_once __DIR__ . '/../../includes/header.php';

$keys = $pdo->query("
    SELECT ak.*, u.full_name, u.username
    FROM api_keys ak
    JOIN users u ON ak.user_id = u.id
    ORDER BY ak.created_at DESC
")->fetchAll();

$users = $pdo->query("SELECT id, full_name, username FROM users WHERE status = 'active' ORDER BY full_name")->fetchAll();
?>

<div class="page-header">
    <h4><i class="bi bi-key"></i> API Keys</h4>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#generateModal"><i class="bi bi-plus-lg"></i> Generate Key</button>
</div>

<div class="card mb-3">
    <div class="card-body">
        <p class="text-muted mb-0">API Base URL: <code><?= 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') ?><?= BASE_URL ?>/api/</code> | Auth: <code>Authorization: Bearer &lt;api_key&gt;</code></p>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <table class="table table-hover data-table">
            <thead>
                <tr><th>Name</th><th>User</th><th>Key (masked)</th><th>Status</th><th>Last Used</th><th>Expires</th><th>Created</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($keys as $k): ?>
                <tr>
                    <td><strong><?= sanitize($k['name']) ?></strong></td>
                    <td><?= sanitize($k['full_name']) ?></td>
                    <td><code><?= substr($k['api_key'], 0, 8) ?>...<?= substr($k['api_key'], -4) ?></code></td>
                    <td>
                        <?php if ($k['is_active']): ?>
                        <span class="badge bg-success">Active</span>
                        <?php else: ?>
                        <span class="badge bg-danger">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $k['last_used_at'] ? formatDateTime($k['last_used_at']) : 'Never' ?></td>
                    <td><?= $k['expires_at'] ? formatDate($k['expires_at']) : 'Never' ?></td>
                    <td><?= formatDate($k['created_at']) ?></td>
                    <td>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= $k['id'] ?>">
                            <button class="btn btn-sm btn-outline-<?= $k['is_active'] ? 'warning' : 'success' ?>" title="<?= $k['is_active'] ? 'Deactivate' : 'Activate' ?>">
                                <i class="bi bi-<?= $k['is_active'] ? 'pause' : 'play' ?>"></i>
                            </button>
                        </form>
                        <form method="POST" class="d-inline" onsubmit="return confirmDelete('Delete this API key?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $k['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Generate Key Modal -->
<div class="modal fade" id="generateModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="generate">
            <div class="modal-header"><h5 class="modal-title">Generate API Key</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Key Name *</label>
                    <input type="text" name="name" class="form-control" required placeholder="e.g. Mobile App, External System">
                </div>
                <div class="mb-3">
                    <label class="form-label">Assign to User *</label>
                    <select name="user_id" class="form-select" required>
                        <option value="">Select User</option>
                        <?php foreach ($users as $u): ?>
                        <option value="<?= $u['id'] ?>"><?= sanitize($u['full_name']) ?> (<?= sanitize($u['username']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Expires At (optional)</label>
                    <input type="date" name="expires_at" class="form-control">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Generate Key</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
