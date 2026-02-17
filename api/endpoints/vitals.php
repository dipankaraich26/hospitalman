<?php
$user = apiAuth();
$pdo = getDBConnection();

switch ($method) {
    case 'GET':
        $patientId = (int)($_GET['patient_id'] ?? 0);
        if (!$patientId) apiError('patient_id query parameter required', 400);

        $pagination = getPaginationParams();
        $countStmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM vitals WHERE patient_id = ?");
        $countStmt->execute([$patientId]);
        $total = (int) $countStmt->fetch()['cnt'];

        $stmt = $pdo->prepare("SELECT v.*, u.full_name as recorded_by_name FROM vitals v JOIN users u ON v.recorded_by = u.id WHERE v.patient_id = ? ORDER BY v.recorded_at DESC LIMIT ? OFFSET ?");
        $stmt->bindValue(1, $patientId, PDO::PARAM_INT);
        $stmt->bindValue(2, $pagination['limit'], PDO::PARAM_INT);
        $stmt->bindValue(3, $pagination['offset'], PDO::PARAM_INT);
        $stmt->execute();

        jsonResponse([
            'data' => $stmt->fetchAll(),
            'meta' => ['total' => $total, 'page' => $pagination['page'], 'limit' => $pagination['limit']]
        ]);
        break;

    case 'POST':
        $data = getRequestBody();
        validateRequiredFields($data, ['patient_id', 'recorded_by']);
        $stmt = $pdo->prepare("INSERT INTO vitals (patient_id, appointment_id, blood_pressure, temperature, pulse, weight, height, oxygen_saturation, recorded_by) VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            (int)$data['patient_id'], $data['appointment_id'] ?? null,
            $data['blood_pressure'] ?? null, $data['temperature'] ?? null,
            $data['pulse'] ?? null, $data['weight'] ?? null,
            $data['height'] ?? null, $data['oxygen_saturation'] ?? null,
            (int)$data['recorded_by']
        ]);
        $newId = (int) $pdo->lastInsertId();
        auditLog('create', 'clinical', 'vitals', $newId, null, $data);
        jsonResponse(['data' => ['id' => $newId], 'message' => 'Vitals recorded'], 201);
        break;

    default:
        apiError('Method not allowed', 405);
}
