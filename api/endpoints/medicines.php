<?php
$user = apiAuth();
$pdo = getDBConnection();

switch ($method) {
    case 'GET':
        if ($id) {
            $stmt = $pdo->prepare("SELECT * FROM medicines WHERE id = ?");
            $stmt->execute([$id]);
            $med = $stmt->fetch();
            if (!$med) apiError('Medicine not found', 404);
            jsonResponse(['data' => $med]);
        } else {
            $pagination = getPaginationParams();
            $filters = getFilterParams(['category']);
            $lowStock = isset($_GET['low_stock']) && $_GET['low_stock'] === '1';
            $search = $_GET['search'] ?? '';

            $where = '1=1'; $params = [];
            if ($search) {
                $where .= " AND (name LIKE ? OR generic_name LIKE ?)";
                $s = "%$search%";
                $params = array_merge($params, [$s, $s]);
            }
            foreach ($filters as $k => $v) { $where .= " AND $k = ?"; $params[] = $v; }
            if ($lowStock) { $where .= " AND quantity_in_stock <= reorder_level"; }

            $countStmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM medicines WHERE $where");
            $countStmt->execute($params);
            $total = (int) $countStmt->fetch()['cnt'];

            $stmt = $pdo->prepare("SELECT * FROM medicines WHERE $where ORDER BY name LIMIT ? OFFSET ?");
            $allParams = array_merge($params, [$pagination['limit'], $pagination['offset']]);
            foreach ($allParams as $i => $p) {
                $stmt->bindValue($i + 1, $p, is_int($p) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $stmt->execute();
            jsonResponse(['data' => $stmt->fetchAll(), 'meta' => ['total' => $total, 'page' => $pagination['page'], 'limit' => $pagination['limit']]]);
        }
        break;

    case 'PUT':
        if (!$id) apiError('Medicine ID required', 400);
        $data = getRequestBody();
        $stmt = $pdo->prepare("SELECT * FROM medicines WHERE id = ?");
        $stmt->execute([$id]);
        $old = $stmt->fetch();
        if (!$old) apiError('Medicine not found', 404);

        $fields = ['name','generic_name','category','manufacturer','batch_number','quantity_in_stock','unit_price','selling_price','expiry_date','reorder_level'];
        $sets = []; $vals = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $data)) { $sets[] = "$f = ?"; $vals[] = $data[$f]; }
        }
        if (empty($sets)) apiError('No fields to update', 422);
        $vals[] = $id;
        $pdo->prepare("UPDATE medicines SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
        auditLog('update', 'pharmacy', 'medicines', $id, $old, $data);
        jsonResponse(['message' => 'Medicine updated']);
        break;

    default:
        apiError('Method not allowed', 405);
}
