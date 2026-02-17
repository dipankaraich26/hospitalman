<?php
$user = apiAuth();
$pdo = getDBConnection();

switch ($method) {
    case 'GET':
        $pagination = getPaginationParams();
        $filters = getFilterParams(['patient_id', 'status', 'doctor_id']);
        $where = '1=1'; $params = [];
        foreach ($filters as $k => $v) {
            $where .= " AND lt.$k = ?";
            $params[] = is_numeric($v) ? (int)$v : $v;
        }
        $countStmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM lab_tests lt WHERE $where");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['cnt'];

        $stmt = $pdo->prepare("
            SELECT lt.*, p.first_name, p.last_name, u.full_name as doctor_name
            FROM lab_tests lt
            JOIN patients p ON lt.patient_id = p.id
            JOIN users u ON lt.doctor_id = u.id
            WHERE $where ORDER BY lt.test_date DESC LIMIT ? OFFSET ?
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
        validateRequiredFields($data, ['patient_id', 'doctor_id', 'test_name', 'test_date']);
        $stmt = $pdo->prepare("INSERT INTO lab_tests (patient_id, doctor_id, test_name, test_date, status, notes) VALUES (?,?,?,?,?,?)");
        $stmt->execute([(int)$data['patient_id'], (int)$data['doctor_id'], $data['test_name'], $data['test_date'], $data['status'] ?? 'ordered', $data['notes'] ?? null]);
        $newId = (int) $pdo->lastInsertId();
        auditLog('create', 'clinical', 'lab_tests', $newId, null, $data);
        jsonResponse(['data' => ['id' => $newId], 'message' => 'Lab test ordered'], 201);
        break;

    case 'PUT':
        if (!$id) apiError('Lab test ID required', 400);
        $data = getRequestBody();
        $stmt = $pdo->prepare("SELECT * FROM lab_tests WHERE id = ?");
        $stmt->execute([$id]);
        $old = $stmt->fetch();
        if (!$old) apiError('Lab test not found', 404);

        $fields = ['status','result','notes'];
        $sets = []; $vals = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $data)) { $sets[] = "$f = ?"; $vals[] = $data[$f]; }
        }
        if (empty($sets)) apiError('No fields to update', 422);
        $vals[] = $id;
        $pdo->prepare("UPDATE lab_tests SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
        auditLog('update', 'clinical', 'lab_tests', $id, $old, $data);
        jsonResponse(['message' => 'Lab test updated']);
        break;

    default:
        apiError('Method not allowed', 405);
}
