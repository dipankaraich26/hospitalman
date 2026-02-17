<?php
$user = apiAuth();
$pdo = getDBConnection();

switch ($method) {
    case 'GET':
        if ($id) {
            $stmt = $pdo->prepare("
                SELECT a.*, p.first_name, p.last_name, p.patient_id as pid, u.full_name as doctor_name
                FROM appointments a
                JOIN patients p ON a.patient_id = p.id
                JOIN users u ON a.doctor_id = u.id
                WHERE a.id = ?
            ");
            $stmt->execute([$id]);
            $appt = $stmt->fetch();
            if (!$appt) apiError('Appointment not found', 404);
            jsonResponse(['data' => $appt]);
        } else {
            $pagination = getPaginationParams();
            $filters = getFilterParams(['status', 'doctor_id', 'patient_id', 'date']);

            $where = '1=1';
            $params = [];
            if (isset($filters['status'])) { $where .= " AND a.status = ?"; $params[] = $filters['status']; }
            if (isset($filters['doctor_id'])) { $where .= " AND a.doctor_id = ?"; $params[] = (int)$filters['doctor_id']; }
            if (isset($filters['patient_id'])) { $where .= " AND a.patient_id = ?"; $params[] = (int)$filters['patient_id']; }
            if (isset($filters['date'])) { $where .= " AND a.appointment_date = ?"; $params[] = $filters['date']; }

            $countStmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM appointments a WHERE $where");
            $countStmt->execute($params);
            $total = (int) $countStmt->fetch()['cnt'];

            $stmt = $pdo->prepare("
                SELECT a.*, p.first_name, p.last_name, p.patient_id as pid, u.full_name as doctor_name
                FROM appointments a
                JOIN patients p ON a.patient_id = p.id
                JOIN users u ON a.doctor_id = u.id
                WHERE $where ORDER BY a.appointment_date DESC, a.appointment_time LIMIT ? OFFSET ?
            ");
            $allParams = array_merge($params, [$pagination['limit'], $pagination['offset']]);
            foreach ($allParams as $i => $p) {
                $stmt->bindValue($i + 1, $p, is_int($p) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $stmt->execute();

            jsonResponse([
                'data' => $stmt->fetchAll(),
                'meta' => ['total' => $total, 'page' => $pagination['page'], 'limit' => $pagination['limit'], 'pages' => ceil($total / $pagination['limit'])]
            ]);
        }
        break;

    case 'POST':
        $data = getRequestBody();
        validateRequiredFields($data, ['patient_id', 'doctor_id', 'appointment_date', 'appointment_time']);
        $stmt = $pdo->prepare("INSERT INTO appointments (patient_id, doctor_id, appointment_date, appointment_time, status, notes) VALUES (?,?,?,?,?,?)");
        $stmt->execute([(int)$data['patient_id'], (int)$data['doctor_id'], $data['appointment_date'], $data['appointment_time'], $data['status'] ?? 'scheduled', $data['notes'] ?? null]);
        $newId = (int) $pdo->lastInsertId();
        auditLog('create', 'clinical', 'appointments', $newId, null, $data);
        jsonResponse(['data' => ['id' => $newId], 'message' => 'Appointment created'], 201);
        break;

    case 'PUT':
        if (!$id) apiError('Appointment ID required', 400);
        $data = getRequestBody();
        $stmt = $pdo->prepare("SELECT * FROM appointments WHERE id = ?");
        $stmt->execute([$id]);
        $old = $stmt->fetch();
        if (!$old) apiError('Appointment not found', 404);

        $fields = ['patient_id','doctor_id','appointment_date','appointment_time','status','notes'];
        $sets = []; $vals = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $data)) { $sets[] = "$f = ?"; $vals[] = $data[$f]; }
        }
        if (empty($sets)) apiError('No fields to update', 422);
        $vals[] = $id;
        $pdo->prepare("UPDATE appointments SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
        auditLog('update', 'clinical', 'appointments', $id, $old, $data);
        jsonResponse(['message' => 'Appointment updated']);
        break;

    case 'DELETE':
        if (!$id) apiError('Appointment ID required', 400);
        $stmt = $pdo->prepare("SELECT * FROM appointments WHERE id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) apiError('Appointment not found', 404);
        auditLog('delete', 'clinical', 'appointments', $id);
        $pdo->prepare("DELETE FROM appointments WHERE id = ?")->execute([$id]);
        jsonResponse(['message' => 'Appointment deleted']);
        break;

    default:
        apiError('Method not allowed', 405);
}
