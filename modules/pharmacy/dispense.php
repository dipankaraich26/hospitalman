<?php
$pageTitle = 'Dispense Medicine';
require_once __DIR__ . '/../../includes/header.php';
requireRole(['admin', 'pharmacist']);

$pdo = getDBConnection();

// Handle dispensing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'dispense') {
    $prescription_id = (int) $_POST['prescription_id'];
    $pdo->beginTransaction();
    try {
        $items = $pdo->prepare("SELECT * FROM prescription_items WHERE prescription_id = ?");
        $items->execute([$prescription_id]);
        $items = $items->fetchAll();

        foreach ($items as $item) {
            if ($item['medicine_id']) {
                // Record dispensing
                $pdo->prepare("INSERT INTO medicine_dispensing (prescription_id, medicine_id, quantity, dispensed_by) VALUES (?,?,?,?)")
                     ->execute([$prescription_id, $item['medicine_id'], $item['quantity'], $_SESSION['user_id']]);

                // Reduce stock
                $pdo->prepare("UPDATE medicines SET quantity_in_stock = GREATEST(0, quantity_in_stock - ?) WHERE id = ?")
                     ->execute([$item['quantity'], $item['medicine_id']]);
            }
        }

        // Update prescription status
        $pdo->prepare("UPDATE prescriptions SET status = 'dispensed' WHERE id = ?")
             ->execute([$prescription_id]);

        $pdo->commit();
        auditLog('create', 'pharmacy', 'medicine_dispensing', $prescription_id, null, ['prescription_id' => $prescription_id, 'items_count' => count($items)]);
        setFlashMessage('success', 'Prescription dispensed successfully.');
    } catch (Exception $e) {
        $pdo->rollBack();
        setFlashMessage('error', 'Error dispensing: ' . $e->getMessage());
    }
    header('Location: dispense.php');
    exit;
}

// Pending prescriptions
$pending = $pdo->query("
    SELECT pr.*, p.first_name, p.last_name, p.patient_id as pid, u.full_name as doctor_name
    FROM prescriptions pr
    JOIN patients p ON pr.patient_id = p.id
    JOIN users u ON pr.doctor_id = u.id
    WHERE pr.status = 'pending'
    ORDER BY pr.created_at DESC
")->fetchAll();

// Recent dispensing history
$history = $pdo->query("
    SELECT md.*, m.name as med_name, pr.id as rx_id, p.first_name, p.last_name, u.full_name as dispensed_by_name
    FROM medicine_dispensing md
    JOIN medicines m ON md.medicine_id = m.id
    LEFT JOIN prescriptions pr ON md.prescription_id = pr.id
    LEFT JOIN patients p ON pr.patient_id = p.id
    JOIN users u ON md.dispensed_by = u.id
    ORDER BY md.dispensed_at DESC
    LIMIT 50
")->fetchAll();
?>

<div class="page-header">
    <h4><i class="bi bi-prescription2"></i> Dispense Medicine</h4>
</div>

<!-- Pending Prescriptions -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-clock-history"></i> Pending Prescriptions (<?= count($pending) ?>)</div>
    <div class="card-body">
        <?php if (empty($pending)): ?>
        <p class="text-muted mb-0">No pending prescriptions.</p>
        <?php else: ?>
        <?php foreach ($pending as $rx): ?>
        <?php
        $rxItems = $pdo->prepare("SELECT pi.*, m.name as med_name, m.quantity_in_stock FROM prescription_items pi LEFT JOIN medicines m ON pi.medicine_id = m.id WHERE pi.prescription_id = ?");
        $rxItems->execute([$rx['id']]);
        $rxItems = $rxItems->fetchAll();
        ?>
        <div class="card mb-2 border">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <div>
                    <strong>Rx #<?= $rx['id'] ?></strong> -
                    <?= sanitize($rx['first_name'] . ' ' . $rx['last_name']) ?> (<?= sanitize($rx['pid']) ?>)
                    <span class="text-muted ms-2">Dr. <?= sanitize($rx['doctor_name']) ?></span>
                    <span class="text-muted ms-2"><?= formatDate($rx['prescription_date']) ?></span>
                </div>
                <form method="POST" onsubmit="return confirm('Dispense all medicines in this prescription?')">
                    <input type="hidden" name="action" value="dispense">
                    <input type="hidden" name="prescription_id" value="<?= $rx['id'] ?>">
                    <button class="btn btn-sm btn-success"><i class="bi bi-check-lg"></i> Dispense All</button>
                </form>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Medicine</th><th>Dosage</th><th>Frequency</th><th>Duration</th><th>Qty</th><th>Stock</th></tr></thead>
                    <tbody>
                        <?php foreach ($rxItems as $item): ?>
                        <tr>
                            <td><?= sanitize($item['med_name'] ?? 'Unknown') ?></td>
                            <td><?= sanitize($item['dosage'] ?? '') ?></td>
                            <td><?= sanitize($item['frequency'] ?? '') ?></td>
                            <td><?= sanitize($item['duration'] ?? '') ?></td>
                            <td><?= $item['quantity'] ?></td>
                            <td>
                                <?php if ($item['quantity_in_stock'] !== null): ?>
                                <span class="<?= $item['quantity_in_stock'] < $item['quantity'] ? 'text-danger fw-bold' : 'text-success' ?>">
                                    <?= $item['quantity_in_stock'] ?>
                                </span>
                                <?php else: ?>-<?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Dispensing History -->
<div class="card">
    <div class="card-header">Recent Dispensing History</div>
    <div class="card-body">
        <table class="table table-hover data-table">
            <thead>
                <tr><th>Date</th><th>Patient</th><th>Medicine</th><th>Qty</th><th>Dispensed By</th></tr>
            </thead>
            <tbody>
                <?php foreach ($history as $h): ?>
                <tr>
                    <td><?= formatDateTime($h['dispensed_at']) ?></td>
                    <td><?= sanitize(($h['first_name'] ?? '') . ' ' . ($h['last_name'] ?? '')) ?></td>
                    <td><?= sanitize($h['med_name']) ?></td>
                    <td><?= $h['quantity'] ?></td>
                    <td><?= sanitize($h['dispensed_by_name']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
