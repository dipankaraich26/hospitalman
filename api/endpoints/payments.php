<?php
$user = apiAuth();
$pdo = getDBConnection();

switch ($method) {
    case 'GET':
        $pagination = getPaginationParams();
        $filters = getFilterParams(['invoice_id', 'payment_method']);
        $from = $_GET['from'] ?? null;
        $to = $_GET['to'] ?? null;

        $where = '1=1'; $params = [];
        foreach ($filters as $k => $v) {
            $where .= " AND py.$k = ?";
            $params[] = is_numeric($v) ? (int)$v : $v;
        }
        if ($from) { $where .= " AND py.payment_date >= ?"; $params[] = $from; }
        if ($to) { $where .= " AND py.payment_date <= ?"; $params[] = $to; }

        $countStmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM payments py WHERE $where");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['cnt'];

        $stmt = $pdo->prepare("
            SELECT py.*, i.invoice_number, p.first_name, p.last_name, u.full_name as receiver
            FROM payments py
            JOIN invoices i ON py.invoice_id = i.id
            JOIN patients p ON i.patient_id = p.id
            JOIN users u ON py.received_by = u.id
            WHERE $where ORDER BY py.created_at DESC LIMIT ? OFFSET ?
        ");
        $allParams = array_merge($params, [$pagination['limit'], $pagination['offset']]);
        foreach ($allParams as $i => $p) {
            $stmt->bindValue($i + 1, $p, is_int($p) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        jsonResponse(['data' => $stmt->fetchAll(), 'meta' => ['total' => $total, 'page' => $pagination['page'], 'limit' => $pagination['limit']]]);
        break;

    case 'POST':
        $data = getRequestBody();
        validateRequiredFields($data, ['invoice_id', 'amount', 'payment_date', 'payment_method']);
        $invoiceId = (int)$data['invoice_id'];

        $stmt = $pdo->prepare("INSERT INTO payments (invoice_id, payment_date, amount, payment_method, reference_number, received_by, notes) VALUES (?,?,?,?,?,?,?)");
        $stmt->execute([$invoiceId, $data['payment_date'], (float)$data['amount'], $data['payment_method'], $data['reference_number'] ?? null, $user['id'], $data['notes'] ?? null]);

        // Update invoice status
        $totalPaid = $pdo->prepare("SELECT COALESCE(SUM(amount),0) as total FROM payments WHERE invoice_id = ?");
        $totalPaid->execute([$invoiceId]);
        $paid = (float)$totalPaid->fetch()['total'];

        $invTotal = $pdo->prepare("SELECT total_amount FROM invoices WHERE id = ?");
        $invTotal->execute([$invoiceId]);
        $total = (float)$invTotal->fetch()['total_amount'];

        $status = 'unpaid';
        if ($paid >= $total) $status = 'paid';
        elseif ($paid > 0) $status = 'partial';
        $pdo->prepare("UPDATE invoices SET status = ? WHERE id = ?")->execute([$status, $invoiceId]);

        auditLog('create', 'billing', 'payments', null, null, ['invoice_id' => $invoiceId, 'amount' => $data['amount']]);
        jsonResponse(['message' => 'Payment recorded', 'invoice_status' => $status], 201);
        break;

    default:
        apiError('Method not allowed', 405);
}
