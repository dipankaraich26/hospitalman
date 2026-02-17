<?php
session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/api_helpers.php';

// Parse route
$route = trim($_GET['_route'] ?? '', '/');
$parts = explode('/', $route);
$resource = $parts[0] ?? '';
$id = isset($parts[1]) && is_numeric($parts[1]) ? (int)$parts[1] : null;
$method = $_SERVER['REQUEST_METHOD'];

// Route to endpoint
if ($resource === '') {
    jsonResponse([
        'api' => 'Hospital Management ERP',
        'version' => API_VERSION,
        'endpoints' => ['patients', 'appointments', 'vitals', 'lab_tests', 'medicines', 'invoices', 'payments', 'predictions']
    ]);
}

$endpointFile = __DIR__ . '/endpoints/' . basename($resource) . '.php';
if (!file_exists($endpointFile)) {
    apiError('Resource not found: ' . $resource, 404);
}

require $endpointFile;
