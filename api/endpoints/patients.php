<?php
$user = apiAuth();
$pdo = getDBConnection();

switch ($method) {
    case 'GET':
        if ($id) {
            $stmt = $pdo->prepare("SELECT * FROM patients WHERE id = ?");
            $stmt->execute([$id]);
            $patient = $stmt->fetch();
            if (!$patient) apiError('Patient not found', 404);
            jsonResponse(['data' => $patient]);
        } else {
            $pagination = getPaginationParams();
            $filters = getFilterParams(['gender', 'blood_group', 'insurance_provider']);
            $search = $_GET['search'] ?? '';

            $where = '1=1';
            $params = [];
            if ($search) {
                $where .= " AND (first_name LIKE ? OR last_name LIKE ? OR patient_id LIKE ? OR phone LIKE ?)";
                $s = "%$search%";
                $params = array_merge($params, [$s, $s, $s, $s]);
            }
            foreach ($filters as $k => $v) {
                $where .= " AND $k = ?";
                $params[] = $v;
            }

            $countStmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM patients WHERE $where");
            $countStmt->execute($params);
            $total = (int) $countStmt->fetch()['cnt'];

            $stmt = $pdo->prepare("SELECT * FROM patients WHERE $where ORDER BY created_at DESC LIMIT ? OFFSET ?");
            $stmt->bindValue(count($params) + 1, $pagination['limit'], PDO::PARAM_INT);
            $stmt->bindValue(count($params) + 2, $pagination['offset'], PDO::PARAM_INT);
            foreach ($params as $i => $p) { $stmt->bindValue($i + 1, $p); }
            $stmt->execute();

            jsonResponse([
                'data' => $stmt->fetchAll(),
                'meta' => ['total' => $total, 'page' => $pagination['page'], 'limit' => $pagination['limit'], 'pages' => ceil($total / $pagination['limit'])]
            ]);
        }
        break;

    case 'POST':
        $data = getRequestBody();
        validateRequiredFields($data, ['first_name', 'last_name', 'gender']);
        require_once __DIR__ . '/../../includes/functions.php';
        $patientId = generatePatientId();
        $stmt = $pdo->prepare("INSERT INTO patients (patient_id, first_name, last_name, dob, gender, blood_group, phone, email, address, emergency_contact_name, emergency_contact_phone, insurance_provider, insurance_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $patientId, $data['first_name'], $data['last_name'],
            $data['dob'] ?? null, $data['gender'], $data['blood_group'] ?? 'Unknown',
            $data['phone'] ?? null, $data['email'] ?? null, $data['address'] ?? null,
            $data['emergency_contact_name'] ?? null, $data['emergency_contact_phone'] ?? null,
            $data['insurance_provider'] ?? null, $data['insurance_id'] ?? null
        ]);
        $newId = (int) $pdo->lastInsertId();
        auditLog('create', 'patients', 'patients', $newId, null, $data);
        $stmt = $pdo->prepare("SELECT * FROM patients WHERE id = ?");
        $stmt->execute([$newId]);
        jsonResponse(['data' => $stmt->fetch(), 'message' => 'Patient created'], 201);
        break;

    case 'PUT':
        if (!$id) apiError('Patient ID required', 400);
        $data = getRequestBody();
        $stmt = $pdo->prepare("SELECT * FROM patients WHERE id = ?");
        $stmt->execute([$id]);
        $old = $stmt->fetch();
        if (!$old) apiError('Patient not found', 404);

        $fields = ['first_name','last_name','dob','gender','blood_group','phone','email','address','emergency_contact_name','emergency_contact_phone','insurance_provider','insurance_id'];
        $sets = [];
        $vals = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $data)) {
                $sets[] = "$f = ?";
                $vals[] = $data[$f];
            }
        }
        if (empty($sets)) apiError('No fields to update', 422);
        $vals[] = $id;
        $pdo->prepare("UPDATE patients SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
        auditLog('update', 'patients', 'patients', $id, $old, $data);
        $stmt = $pdo->prepare("SELECT * FROM patients WHERE id = ?");
        $stmt->execute([$id]);
        jsonResponse(['data' => $stmt->fetch(), 'message' => 'Patient updated']);
        break;

    case 'DELETE':
        if (!$id) apiError('Patient ID required', 400);
        $stmt = $pdo->prepare("SELECT * FROM patients WHERE id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) apiError('Patient not found', 404);
        auditLog('delete', 'patients', 'patients', $id);
        $pdo->prepare("DELETE FROM patients WHERE id = ?")->execute([$id]);
        jsonResponse(['message' => 'Patient deleted']);
        break;

    default:
        apiError('Method not allowed', 405);
}
