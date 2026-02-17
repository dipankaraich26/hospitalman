<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requireRole(['admin']);

$pdo = getDBConnection();

// Get parameters
$format = $_GET['format'] ?? 'csv';
$days = (int) ($_GET['days'] ?? 30);
$module = $_GET['module'] ?? null;
$action = $_GET['action'] ?? null;

// Build query
$where = ["created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)"];
$params = [$days];

if ($module) {
    $where[] = "module = ?";
    $params[] = $module;
}

if ($action) {
    $where[] = "action = ?";
    $params[] = $action;
}

$sql = "
    SELECT
        al.*,
        u.full_name,
        u.role,
        u.email
    FROM audit_logs al
    LEFT JOIN users u ON al.user_id = u.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY al.created_at DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Set headers for download
$filename = 'audit_log_' . date('Y-m-d_His') . '.' . $format;

if ($format === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');

    // CSV Headers
    fputcsv($output, [
        'ID',
        'Timestamp',
        'User ID',
        'User Name',
        'Role',
        'Email',
        'Action',
        'Module',
        'Record Table',
        'Record ID',
        'IP Address',
        'User Agent'
    ]);

    // CSV Data
    foreach ($logs as $log) {
        fputcsv($output, [
            $log['id'],
            $log['created_at'],
            $log['user_id'],
            $log['full_name'] ?? $log['username'],
            $log['role'] ?? 'Unknown',
            $log['email'] ?? 'N/A',
            $log['action'],
            $log['module'] ?? 'N/A',
            $log['record_table'] ?? 'N/A',
            $log['record_id'] ?? 'N/A',
            $log['ip_address'] ?? 'N/A',
            $log['user_agent'] ?? 'N/A'
        ]);
    }

    fclose($output);

} elseif ($format === 'json') {
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    echo json_encode([
        'export_date' => date('Y-m-d H:i:s'),
        'period_days' => $days,
        'total_records' => count($logs),
        'filters' => [
            'module' => $module,
            'action' => $action
        ],
        'data' => $logs
    ], JSON_PRETTY_PRINT);

} else {
    die('Invalid format');
}

exit;
?>
