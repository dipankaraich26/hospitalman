<?php
$pageTitle = 'Insurance Providers';
require_once __DIR__ . '/../../includes/header.php';
requireRole(['admin', 'receptionist']);

$pdo = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $stmt = $pdo->prepare("INSERT INTO insurance_providers (name, short_code, contact_person, phone, email, address, coverage_percent, notes) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->execute([
            trim($_POST['name']), strtoupper(trim($_POST['short_code'])),
            trim($_POST['contact_person']), trim($_POST['phone']),
            trim($_POST['email']), trim($_POST['address']),
            (float) $_POST['coverage_percent'], trim($_POST['notes'])
        ]);
        setFlashMessage('success', 'Insurance provider added successfully.');
        header('Location: insurance_providers.php');
        exit;
    }

    if ($action === 'edit') {
        $stmt = $pdo->prepare("UPDATE insurance_providers SET name=?, short_code=?, contact_person=?, phone=?, email=?, address=?, coverage_percent=?, status=?, notes=? WHERE id=?");
        $stmt->execute([
            trim($_POST['name']), strtoupper(trim($_POST['short_code'])),
            trim($_POST['contact_person']), trim($_POST['phone']),
            trim($_POST['email']), trim($_POST['address']),
            (float) $_POST['coverage_percent'], $_POST['status'],
            trim($_POST['notes']), (int) $_POST['id']
        ]);
        setFlashMessage('success', 'Provider updated successfully.');
        header('Location: insurance_providers.php');
        exit;
    }

    if ($action === 'delete') {
        $claimCount = countRows('insurance_claims', 'provider_id = ?', [(int) $_POST['id']]);
        if ($claimCount > 0) {
            setFlashMessage('error', "Cannot delete provider with $claimCount existing claims.");
        } else {
            $pdo->prepare("DELETE FROM insurance_providers WHERE id = ?")->execute([(int) $_POST['id']]);
            setFlashMessage('success', 'Provider deleted.');
        }
        header('Location: insurance_providers.php');
        exit;
    }
}

$providers = $pdo->query("
    SELECT ip.*,
           (SELECT COUNT(*) FROM insurance_claims WHERE provider_id = ip.id) as total_claims,
           (SELECT COALESCE(SUM(claim_amount),0) FROM insurance_claims WHERE provider_id = ip.id) as total_claimed,
           (SELECT COALESCE(SUM(approved_amount),0) FROM insurance_claims WHERE provider_id = ip.id AND status IN ('approved','partially_approved','paid')) as total_approved
    FROM insurance_providers ip
    ORDER BY ip.name
")->fetchAll();
?>

<div class="page-header">
    <h4><i class="bi bi-building"></i> Insurance Providers</h4>
    <div>
        <a href="insurance.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back to Claims</a>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addProviderModal"><i class="bi bi-plus-lg"></i> Add Provider</button>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <table class="table table-hover data-table">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Provider Name</th>
                    <th>Contact</th>
                    <th>Coverage %</th>
                    <th>Claims</th>
                    <th>Total Claimed</th>
                    <th>Approved</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($providers as $prov): ?>
                <tr>
                    <td><span class="badge bg-info"><?= sanitize($prov['short_code']) ?></span></td>
                    <td>
                        <strong><?= sanitize($prov['name']) ?></strong>
                        <?php if ($prov['contact_person']): ?><br><small class="text-muted"><?= sanitize($prov['contact_person']) ?></small><?php endif; ?>
                    </td>
                    <td>
                        <?= sanitize($prov['phone'] ?? '-') ?>
                        <?php if ($prov['email']): ?><br><small><?= sanitize($prov['email']) ?></small><?php endif; ?>
                    </td>
                    <td><strong><?= $prov['coverage_percent'] ?>%</strong></td>
                    <td><?= $prov['total_claims'] ?></td>
                    <td><?= formatCurrency($prov['total_claimed']) ?></td>
                    <td class="text-success"><?= formatCurrency($prov['total_approved']) ?></td>
                    <td><?= getStatusBadge($prov['status']) ?></td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary edit-prov-btn"
                                data-bs-toggle="modal" data-bs-target="#editProviderModal"
                                data-id="<?= $prov['id'] ?>"
                                data-name="<?= sanitize($prov['name']) ?>"
                                data-code="<?= sanitize($prov['short_code']) ?>"
                                data-contact="<?= sanitize($prov['contact_person'] ?? '') ?>"
                                data-phone="<?= sanitize($prov['phone'] ?? '') ?>"
                                data-email="<?= sanitize($prov['email'] ?? '') ?>"
                                data-address="<?= sanitize($prov['address'] ?? '') ?>"
                                data-coverage="<?= $prov['coverage_percent'] ?>"
                                data-status="<?= $prov['status'] ?>"
                                data-notes="<?= sanitize($prov['notes'] ?? '') ?>">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <form method="POST" class="d-inline" onsubmit="return confirmDelete('Delete this provider?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $prov['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add Provider Modal -->
<div class="modal fade" id="addProviderModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="add">
            <div class="modal-header">
                <h5 class="modal-title">Add Insurance Provider</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row mb-3">
                    <div class="col-md-8">
                        <label class="form-label">Provider Name *</label>
                        <input type="text" name="name" class="form-control" required placeholder="e.g. BlueCross BlueShield">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Short Code *</label>
                        <input type="text" name="short_code" class="form-control" required placeholder="e.g. BCBS" maxlength="20" style="text-transform:uppercase">
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="form-label">Contact Person</label>
                        <input type="text" name="contact_person" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Address</label>
                    <input type="text" name="address" class="form-control">
                </div>
                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="form-label">Coverage Percentage *</label>
                        <div class="input-group">
                            <input type="number" name="coverage_percent" class="form-control" step="0.01" min="0" max="100" value="80.00" required>
                            <span class="input-group-text">%</span>
                        </div>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Add Provider</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Provider Modal -->
<div class="modal fade" id="editProviderModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="edit-prov-id">
            <div class="modal-header">
                <h5 class="modal-title">Edit Insurance Provider</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row mb-3">
                    <div class="col-md-8">
                        <label class="form-label">Provider Name *</label>
                        <input type="text" name="name" id="edit-prov-name" class="form-control" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Short Code *</label>
                        <input type="text" name="short_code" id="edit-prov-code" class="form-control" required maxlength="20" style="text-transform:uppercase">
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="form-label">Contact Person</label>
                        <input type="text" name="contact_person" id="edit-prov-contact" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" id="edit-prov-phone" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" id="edit-prov-email" class="form-control">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Address</label>
                    <input type="text" name="address" id="edit-prov-address" class="form-control">
                </div>
                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="form-label">Coverage Percentage *</label>
                        <div class="input-group">
                            <input type="number" name="coverage_percent" id="edit-prov-coverage" class="form-control" step="0.01" min="0" max="100" required>
                            <span class="input-group-text">%</span>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Status *</label>
                        <select name="status" id="edit-prov-status" class="form-select" required>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" id="edit-prov-notes" class="form-control" rows="2"></textarea>
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
document.querySelectorAll('.edit-prov-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.getElementById('edit-prov-id').value = this.dataset.id;
        document.getElementById('edit-prov-name').value = this.dataset.name;
        document.getElementById('edit-prov-code').value = this.dataset.code;
        document.getElementById('edit-prov-contact').value = this.dataset.contact;
        document.getElementById('edit-prov-phone').value = this.dataset.phone;
        document.getElementById('edit-prov-email').value = this.dataset.email;
        document.getElementById('edit-prov-address').value = this.dataset.address;
        document.getElementById('edit-prov-coverage').value = this.dataset.coverage;
        document.getElementById('edit-prov-status').value = this.dataset.status;
        document.getElementById('edit-prov-notes').value = this.dataset.notes;
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
