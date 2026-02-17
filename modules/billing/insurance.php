<?php
$pageTitle = 'Insurance Claims';
require_once __DIR__ . '/../../includes/header.php';
requireRole(['admin', 'receptionist']);

$pdo = getDBConnection();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'submit_claim') {
        // Generate claim number
        $maxId = $pdo->query("SELECT COALESCE(MAX(id),0) + 1 as next_id FROM insurance_claims")->fetch()['next_id'];
        $claim_number = 'CLM-' . date('Y') . '-' . str_pad($maxId, 4, '0', STR_PAD_LEFT);

        $stmt = $pdo->prepare("INSERT INTO insurance_claims (claim_number, invoice_id, patient_id, provider_id, policy_number, claim_date, claim_amount, status, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)");

        $invoice_id = (int) $_POST['invoice_id'];
        $inv = $pdo->prepare("SELECT patient_id, total_amount FROM invoices WHERE id = ?");
        $inv->execute([$invoice_id]);
        $inv = $inv->fetch();

        $provider_id = (int) $_POST['provider_id'];
        $provCoverage = $pdo->prepare("SELECT coverage_percent FROM insurance_providers WHERE id = ?");
        $provCoverage->execute([$provider_id]);
        $coverage = $provCoverage->fetch()['coverage_percent'] ?? 80;

        $claim_amount = (float) $_POST['claim_amount'];

        $stmt->execute([
            $claim_number, $invoice_id, $inv['patient_id'],
            $provider_id, trim($_POST['policy_number']),
            $_POST['claim_date'], $claim_amount,
            'submitted', trim($_POST['notes']), $_SESSION['user_id']
        ]);

        // Mark invoice as insurance claim
        $pdo->prepare("UPDATE invoices SET insurance_claim = 1 WHERE id = ?")->execute([$invoice_id]);

        setFlashMessage('success', "Claim $claim_number submitted successfully.");
        header('Location: insurance.php');
        exit;
    }

    if ($action === 'update_status') {
        $id = (int) $_POST['claim_id'];
        $new_status = $_POST['new_status'];
        $approved_amount = (float) ($_POST['approved_amount'] ?? 0);

        $updates = "status = ?";
        $params = [$new_status];

        if (in_array($new_status, ['approved', 'partially_approved'])) {
            $updates .= ", approved_amount = ?";
            $params[] = $approved_amount;
        }

        if ($new_status === 'rejected') {
            $updates .= ", rejection_reason = ?";
            $params[] = trim($_POST['rejection_reason'] ?? '');
        }

        if ($new_status === 'paid') {
            $updates .= ", settlement_date = ?, settlement_reference = ?";
            $params[] = $_POST['settlement_date'] ?? date('Y-m-d');
            $params[] = trim($_POST['settlement_reference'] ?? '');

            // Auto-record payment on the invoice
            $claim = $pdo->prepare("SELECT invoice_id, approved_amount FROM insurance_claims WHERE id = ?");
            $claim->execute([$id]);
            $claimData = $claim->fetch();
            if ($claimData) {
                $payAmt = $approved_amount > 0 ? $approved_amount : $claimData['approved_amount'];
                $pdo->prepare("INSERT INTO payments (invoice_id, payment_date, amount, payment_method, reference_number, received_by, notes) VALUES (?,CURDATE(),?,'insurance',?,?,?)")
                     ->execute([$claimData['invoice_id'], $payAmt, trim($_POST['settlement_reference'] ?? ''), $_SESSION['user_id'], 'Insurance settlement']);

                // Update invoice status
                $totalPaid = $pdo->prepare("SELECT COALESCE(SUM(amount),0) as total FROM payments WHERE invoice_id = ?");
                $totalPaid->execute([$claimData['invoice_id']]);
                $paid = $totalPaid->fetch()['total'];
                $invTotal = $pdo->prepare("SELECT total_amount FROM invoices WHERE id = ?");
                $invTotal->execute([$claimData['invoice_id']]);
                $total = $invTotal->fetch()['total_amount'];
                $invStatus = $paid >= $total ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid');
                $pdo->prepare("UPDATE invoices SET status = ? WHERE id = ?")->execute([$invStatus, $claimData['invoice_id']]);
            }
        }

        $params[] = $id;
        $pdo->prepare("UPDATE insurance_claims SET $updates WHERE id = ?")->execute($params);

        setFlashMessage('success', 'Claim status updated successfully.');
        header('Location: insurance.php');
        exit;
    }

    if ($action === 'delete_claim') {
        $pdo->prepare("DELETE FROM insurance_claims WHERE id = ?")->execute([(int) $_POST['claim_id']]);
        setFlashMessage('success', 'Claim deleted.');
        header('Location: insurance.php');
        exit;
    }
}

// Filters
$filter = $_GET['status'] ?? 'all';
$where = $filter !== 'all' ? "WHERE ic.status = " . $pdo->quote($filter) : "";

// Fetch claims
$claims = $pdo->query("
    SELECT ic.*, p.first_name, p.last_name, p.patient_id as pid,
           ip.name as provider_name, ip.short_code,
           i.invoice_number, i.total_amount as invoice_total
    FROM insurance_claims ic
    JOIN patients p ON ic.patient_id = p.id
    JOIN insurance_providers ip ON ic.provider_id = ip.id
    JOIN invoices i ON ic.invoice_id = i.id
    $where
    ORDER BY ic.created_at DESC
")->fetchAll();

// Stats
$totalClaims = count($claims);
$totalClaimed = array_sum(array_column($claims, 'claim_amount'));
$totalApproved = array_sum(array_column($claims, 'approved_amount'));
$paidClaims = array_filter($claims, fn($c) => $c['status'] === 'paid');
$totalSettled = array_sum(array_column($paidClaims, 'approved_amount'));
$pendingClaims = array_filter($claims, fn($c) => in_array($c['status'], ['submitted', 'under_review']));

// For submit form
$invoicesForClaim = $pdo->query("
    SELECT i.id, i.invoice_number, i.total_amount, i.patient_id,
           p.first_name, p.last_name, p.patient_id as pid, p.insurance_provider, p.insurance_id
    FROM invoices i
    JOIN patients p ON i.patient_id = p.id
    WHERE i.id NOT IN (SELECT invoice_id FROM insurance_claims)
    AND p.insurance_provider IS NOT NULL
    ORDER BY i.created_at DESC
")->fetchAll();

$providers = $pdo->query("SELECT * FROM insurance_providers WHERE status = 'active' ORDER BY name")->fetchAll();
?>

<div class="page-header">
    <h4><i class="bi bi-shield-check"></i> Insurance Claims</h4>
    <div>
        <a href="insurance_providers.php" class="btn btn-outline-secondary"><i class="bi bi-building"></i> Providers</a>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#submitClaimModal"><i class="bi bi-plus-lg"></i> Submit Claim</button>
    </div>
</div>

<!-- Stats Cards -->
<div class="row mb-4">
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stat-card bg-gradient-primary">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-value"><?= $totalClaims ?></div>
                    <div class="stat-label">Total Claims</div>
                </div>
                <i class="bi bi-file-earmark-medical stat-icon"></i>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stat-card bg-gradient-info">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-value"><?= formatCurrency($totalClaimed) ?></div>
                    <div class="stat-label">Total Claimed</div>
                </div>
                <i class="bi bi-cash stat-icon"></i>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stat-card bg-gradient-success">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-value"><?= formatCurrency($totalApproved) ?></div>
                    <div class="stat-label">Approved Amount</div>
                </div>
                <i class="bi bi-check-circle stat-icon"></i>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stat-card bg-gradient-warning">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-value"><?= count($pendingClaims) ?></div>
                    <div class="stat-label">Pending Review</div>
                </div>
                <i class="bi bi-hourglass-split stat-icon"></i>
            </div>
        </div>
    </div>
</div>

<!-- Filter Tabs -->
<ul class="nav nav-pills mb-3">
    <li class="nav-item"><a class="nav-link <?= $filter === 'all' ? 'active' : '' ?>" href="?status=all">All</a></li>
    <li class="nav-item"><a class="nav-link <?= $filter === 'submitted' ? 'active' : '' ?>" href="?status=submitted">Submitted</a></li>
    <li class="nav-item"><a class="nav-link <?= $filter === 'under_review' ? 'active' : '' ?>" href="?status=under_review">Under Review</a></li>
    <li class="nav-item"><a class="nav-link <?= $filter === 'approved' ? 'active' : '' ?>" href="?status=approved">Approved</a></li>
    <li class="nav-item"><a class="nav-link <?= $filter === 'partially_approved' ? 'active' : '' ?>" href="?status=partially_approved">Partial</a></li>
    <li class="nav-item"><a class="nav-link <?= $filter === 'rejected' ? 'active' : '' ?>" href="?status=rejected">Rejected</a></li>
    <li class="nav-item"><a class="nav-link <?= $filter === 'paid' ? 'active' : '' ?>" href="?status=paid">Settled/Paid</a></li>
</ul>

<!-- Claims Table -->
<div class="card">
    <div class="card-body">
        <table class="table table-hover data-table">
            <thead>
                <tr>
                    <th>Claim #</th>
                    <th>Patient</th>
                    <th>Provider</th>
                    <th>Invoice</th>
                    <th>Claim Date</th>
                    <th>Claimed</th>
                    <th>Approved</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($claims as $c): ?>
                <tr>
                    <td><strong><?= sanitize($c['claim_number']) ?></strong></td>
                    <td>
                        <?= sanitize($c['first_name'] . ' ' . $c['last_name']) ?>
                        <br><small class="text-muted"><?= sanitize($c['pid']) ?></small>
                    </td>
                    <td>
                        <span class="badge bg-info"><?= sanitize($c['short_code']) ?></span>
                        <br><small><?= sanitize($c['provider_name']) ?></small>
                    </td>
                    <td><a href="view_invoice.php?id=<?= $c['invoice_id'] ?>"><?= sanitize($c['invoice_number']) ?></a></td>
                    <td><?= formatDate($c['claim_date']) ?></td>
                    <td><?= formatCurrency($c['claim_amount']) ?></td>
                    <td class="<?= $c['approved_amount'] > 0 ? 'text-success fw-bold' : '' ?>">
                        <?= formatCurrency($c['approved_amount']) ?>
                    </td>
                    <td><?= getStatusBadge($c['status']) ?></td>
                    <td>
                        <!-- Update Status Button -->
                        <button class="btn btn-sm btn-outline-primary update-claim-btn"
                                data-bs-toggle="modal" data-bs-target="#updateClaimModal"
                                data-id="<?= $c['id'] ?>"
                                data-claim="<?= sanitize($c['claim_number']) ?>"
                                data-status="<?= $c['status'] ?>"
                                data-amount="<?= $c['claim_amount'] ?>"
                                data-approved="<?= $c['approved_amount'] ?>">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <!-- View Details -->
                        <button class="btn btn-sm btn-outline-info view-claim-btn"
                                data-bs-toggle="modal" data-bs-target="#viewClaimModal"
                                data-claim='<?= json_encode($c) ?>'>
                            <i class="bi bi-eye"></i>
                        </button>
                        <!-- Delete -->
                        <form method="POST" class="d-inline" onsubmit="return confirmDelete('Delete this claim?')">
                            <input type="hidden" name="action" value="delete_claim">
                            <input type="hidden" name="claim_id" value="<?= $c['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Submit Claim Modal -->
<div class="modal fade" id="submitClaimModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="submit_claim">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-shield-plus"></i> Submit Insurance Claim</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <?php if (empty($invoicesForClaim)): ?>
                <div class="alert alert-info">No eligible invoices found. Invoices must belong to patients with insurance and not already have a claim.</div>
                <?php else: ?>
                <div class="mb-3">
                    <label class="form-label">Invoice *</label>
                    <select name="invoice_id" id="claimInvoice" class="form-select" required>
                        <option value="">Select Invoice</option>
                        <?php foreach ($invoicesForClaim as $inv): ?>
                        <option value="<?= $inv['id'] ?>"
                                data-amount="<?= $inv['total_amount'] ?>"
                                data-patient="<?= sanitize($inv['first_name'] . ' ' . $inv['last_name']) ?>"
                                data-policy="<?= sanitize($inv['insurance_id'] ?? '') ?>"
                                data-provider="<?= sanitize($inv['insurance_provider'] ?? '') ?>">
                            <?= sanitize($inv['invoice_number']) ?> - <?= sanitize($inv['first_name'] . ' ' . $inv['last_name']) ?> (<?= formatCurrency($inv['total_amount']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="claimDetails" style="display:none;">
                    <div class="alert alert-light border mb-3">
                        <strong>Patient:</strong> <span id="claimPatient"></span> |
                        <strong>Invoice Total:</strong> <span id="claimInvTotal"></span> |
                        <strong>Provider on File:</strong> <span id="claimProvOnFile"></span>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Insurance Provider *</label>
                            <select name="provider_id" class="form-select" required>
                                <option value="">Select Provider</option>
                                <?php foreach ($providers as $prov): ?>
                                <option value="<?= $prov['id'] ?>" data-coverage="<?= $prov['coverage_percent'] ?>">
                                    <?= sanitize($prov['name']) ?> (<?= $prov['coverage_percent'] ?>% coverage)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Policy Number *</label>
                            <input type="text" name="policy_number" id="claimPolicyNum" class="form-control" required>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Claim Date *</label>
                            <input type="date" name="claim_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Claim Amount *</label>
                            <input type="number" name="claim_amount" id="claimAmount" class="form-control" step="0.01" min="0.01" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Additional claim details..."></textarea>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <?php if (!empty($invoicesForClaim)): ?>
                <button type="submit" class="btn btn-primary"><i class="bi bi-send"></i> Submit Claim</button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Update Claim Status Modal -->
<div class="modal fade" id="updateClaimModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="claim_id" id="upd-claim-id">
            <div class="modal-header">
                <h5 class="modal-title">Update Claim: <span id="upd-claim-number"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">New Status *</label>
                    <select name="new_status" id="upd-new-status" class="form-select" required>
                        <option value="submitted">Submitted</option>
                        <option value="under_review">Under Review</option>
                        <option value="approved">Approved</option>
                        <option value="partially_approved">Partially Approved</option>
                        <option value="rejected">Rejected</option>
                        <option value="paid">Settled / Paid</option>
                    </select>
                </div>
                <div class="mb-3" id="approvedAmountGroup">
                    <label class="form-label">Approved Amount</label>
                    <input type="number" name="approved_amount" id="upd-approved-amount" class="form-control" step="0.01" min="0">
                    <small class="text-muted">Claimed: <span id="upd-claimed-amount"></span></small>
                </div>
                <div class="mb-3" id="rejectionGroup" style="display:none;">
                    <label class="form-label">Rejection Reason</label>
                    <textarea name="rejection_reason" class="form-control" rows="2"></textarea>
                </div>
                <div id="settlementGroup" style="display:none;">
                    <div class="mb-3">
                        <label class="form-label">Settlement Date</label>
                        <input type="date" name="settlement_date" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Settlement Reference</label>
                        <input type="text" name="settlement_reference" class="form-control" placeholder="e.g. BCBS-PAY-12345">
                    </div>
                    <div class="alert alert-success py-2">
                        <i class="bi bi-info-circle"></i> A payment will be automatically recorded on the invoice.
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Update Status</button>
            </div>
        </form>
    </div>
</div>

<!-- View Claim Details Modal -->
<div class="modal fade" id="viewClaimModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-shield-check"></i> Claim Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewClaimBody"></div>
        </div>
    </div>
</div>

<script>
// Submit Claim - show details when invoice selected
document.getElementById('claimInvoice')?.addEventListener('change', function() {
    var opt = this.options[this.selectedIndex];
    var details = document.getElementById('claimDetails');
    if (this.value) {
        details.style.display = 'block';
        document.getElementById('claimPatient').textContent = opt.dataset.patient;
        document.getElementById('claimInvTotal').textContent = '$' + parseFloat(opt.dataset.amount).toFixed(2);
        document.getElementById('claimProvOnFile').textContent = opt.dataset.provider || 'N/A';
        document.getElementById('claimPolicyNum').value = opt.dataset.policy || '';
        document.getElementById('claimAmount').value = parseFloat(opt.dataset.amount).toFixed(2);
    } else {
        details.style.display = 'none';
    }
});

// Update Claim modal populate
document.querySelectorAll('.update-claim-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.getElementById('upd-claim-id').value = this.dataset.id;
        document.getElementById('upd-claim-number').textContent = this.dataset.claim;
        document.getElementById('upd-new-status').value = this.dataset.status;
        document.getElementById('upd-approved-amount').value = parseFloat(this.dataset.approved).toFixed(2);
        document.getElementById('upd-claimed-amount').textContent = '$' + parseFloat(this.dataset.amount).toFixed(2);
        toggleStatusFields();
    });
});

// Toggle fields based on status
document.getElementById('upd-new-status')?.addEventListener('change', toggleStatusFields);
function toggleStatusFields() {
    var status = document.getElementById('upd-new-status').value;
    document.getElementById('approvedAmountGroup').style.display =
        ['approved','partially_approved','paid'].includes(status) ? 'block' : 'none';
    document.getElementById('rejectionGroup').style.display =
        status === 'rejected' ? 'block' : 'none';
    document.getElementById('settlementGroup').style.display =
        status === 'paid' ? 'block' : 'none';
}

// View Claim detail modal
document.querySelectorAll('.view-claim-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var c = JSON.parse(this.dataset.claim);
        var statusLabels = {
            submitted: '<span class="badge bg-info">Submitted</span>',
            under_review: '<span class="badge bg-primary">Under Review</span>',
            approved: '<span class="badge bg-success">Approved</span>',
            partially_approved: '<span class="badge bg-warning">Partially Approved</span>',
            rejected: '<span class="badge bg-danger">Rejected</span>',
            paid: '<span class="badge bg-success">Settled / Paid</span>'
        };
        var html = '<table class="table table-borderless">';
        html += '<tr><th width="35%">Claim Number:</th><td><strong>' + c.claim_number + '</strong></td></tr>';
        html += '<tr><th>Patient:</th><td>' + c.first_name + ' ' + c.last_name + ' (' + c.pid + ')</td></tr>';
        html += '<tr><th>Provider:</th><td>' + c.provider_name + ' (' + c.short_code + ')</td></tr>';
        html += '<tr><th>Policy Number:</th><td>' + c.policy_number + '</td></tr>';
        html += '<tr><th>Invoice:</th><td>' + c.invoice_number + ' - $' + parseFloat(c.invoice_total).toFixed(2) + '</td></tr>';
        html += '<tr><th>Claim Date:</th><td>' + c.claim_date + '</td></tr>';
        html += '<tr><th>Claimed Amount:</th><td>$' + parseFloat(c.claim_amount).toFixed(2) + '</td></tr>';
        html += '<tr><th>Approved Amount:</th><td class="text-success fw-bold">$' + parseFloat(c.approved_amount).toFixed(2) + '</td></tr>';
        html += '<tr><th>Status:</th><td>' + (statusLabels[c.status] || c.status) + '</td></tr>';
        if (c.rejection_reason) html += '<tr><th>Rejection Reason:</th><td class="text-danger">' + c.rejection_reason + '</td></tr>';
        if (c.settlement_date) html += '<tr><th>Settlement Date:</th><td>' + c.settlement_date + '</td></tr>';
        if (c.settlement_reference) html += '<tr><th>Settlement Ref:</th><td>' + c.settlement_reference + '</td></tr>';
        if (c.notes) html += '<tr><th>Notes:</th><td>' + c.notes + '</td></tr>';
        html += '</table>';
        document.getElementById('viewClaimBody').innerHTML = html;
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
