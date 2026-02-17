<?php
$user = apiAuth();
$pdo = getDBConnection();

switch ($method) {
    case 'GET':
        if ($id) {
            $stmt = $pdo->prepare("
                SELECT i.*, p.first_name, p.last_name, p.patient_id as pid
                FROM invoices i JOIN patients p ON i.patient_id = p.id WHERE i.id = ?
            ");
            $stmt->execute([$id]);
            $invoice = $stmt->fetch();
            if (!$invoice) apiError('Invoice not found', 404);

            // Get items and payments
            $items = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ?");
            $items->execute([$id]);
            $invoice['items'] = $items->fetchAll();

            $payments = $pdo->prepare("SELECT py.*, u.full_name as receiver FROM payments py JOIN users u ON py.received_by = u.id WHERE py.invoice_id = ?");
            $payments->execute([$id]);
            $invoice['payments'] = $payments->fetchAll();

            jsonResponse(['data' => $invoice]);
        } else {
            $pagination = getPaginationParams();
            $filters = getFilterParams(['status', 'patient_id']);
            $from = $_GET['from'] ?? null;
            $to = $_GET['to'] ?? null;

            $where = '1=1'; $params = [];
            foreach ($filters as $k => $v) {
                $where .= " AND i.$k = ?";
                $params[] = is_numeric($v) ? (int)$v : $v;
            }
            if ($from) { $where .= " AND i.invoice_date >= ?"; $params[] = $from; }
            if ($to) { $where .= " AND i.invoice_date <= ?"; $params[] = $to; }

            $countStmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM invoices i WHERE $where");
            $countStmt->execute($params);
            $total = (int) $countStmt->fetch()['cnt'];

            $stmt = $pdo->prepare("
                SELECT i.*, p.first_name, p.last_name, p.patient_id as pid
                FROM invoices i JOIN patients p ON i.patient_id = p.id
                WHERE $where ORDER BY i.created_at DESC LIMIT ? OFFSET ?
            ");
            $allParams = array_merge($params, [$pagination['limit'], $pagination['offset']]);
            foreach ($allParams as $i => $p) {
                $stmt->bindValue($i + 1, $p, is_int($p) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $stmt->execute();
            jsonResponse(['data' => $stmt->fetchAll(), 'meta' => ['total' => $total, 'page' => $pagination['page'], 'limit' => $pagination['limit']]]);
        }
        break;

    case 'POST':
        $data = getRequestBody();
        validateRequiredFields($data, ['patient_id', 'invoice_date', 'items']);
        require_once __DIR__ . '/../../includes/functions.php';

        $pdo->beginTransaction();
        try {
            $invoiceNumber = generateInvoiceNumber();
            $subtotal = 0;
            foreach ($data['items'] as $item) {
                $subtotal += ($item['quantity'] ?? 1) * ($item['unit_price'] ?? 0);
            }
            $discountPct = (float)($data['discount_percent'] ?? 0);
            $discountAmt = $subtotal * ($discountPct / 100);
            $taxPct = (float)($data['tax_percent'] ?? 0);
            $taxAmt = ($subtotal - $discountAmt) * ($taxPct / 100);
            $totalAmt = $subtotal - $discountAmt + $taxAmt;

            $stmt = $pdo->prepare("INSERT INTO invoices (invoice_number, patient_id, invoice_date, subtotal, discount_percent, discount_amount, tax_amount, total_amount, status, insurance_claim, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$invoiceNumber, (int)$data['patient_id'], $data['invoice_date'], $subtotal, $discountPct, $discountAmt, $taxAmt, $totalAmt, 'unpaid', $data['insurance_claim'] ?? 0, $data['notes'] ?? null, $user['id']]);
            $invoiceId = (int)$pdo->lastInsertId();

            $istmt = $pdo->prepare("INSERT INTO invoice_items (invoice_id, description, category, quantity, unit_price, total_price) VALUES (?,?,?,?,?,?)");
            foreach ($data['items'] as $item) {
                $qty = (int)($item['quantity'] ?? 1);
                $price = (float)($item['unit_price'] ?? 0);
                $istmt->execute([$invoiceId, $item['description'], $item['category'] ?? 'other', $qty, $price, $qty * $price]);
            }

            $pdo->commit();
            auditLog('create', 'billing', 'invoices', $invoiceId, null, ['invoice_number' => $invoiceNumber, 'total' => $totalAmt]);
            jsonResponse(['data' => ['id' => $invoiceId, 'invoice_number' => $invoiceNumber], 'message' => 'Invoice created'], 201);
        } catch (Exception $e) {
            $pdo->rollBack();
            apiError('Error creating invoice: ' . $e->getMessage(), 500);
        }
        break;

    default:
        apiError('Method not allowed', 405);
}
