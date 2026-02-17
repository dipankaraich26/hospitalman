<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/audit.php';

/**
 * Authenticate API request via Bearer token.
 * Returns the user array on success, sends 401 error on failure.
 */
function apiAuth(): array {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
        apiError('Missing or invalid Authorization header. Use: Bearer <api_key>', 401);
    }
    $token = $matches[1];
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT ak.*, u.id as uid, u.username, u.full_name, u.role, u.status
        FROM api_keys ak
        JOIN users u ON ak.user_id = u.id
        WHERE ak.api_key = ? AND ak.is_active = 1 AND u.status = 'active'
    ");
    $stmt->execute([$token]);
    $key = $stmt->fetch();

    if (!$key) {
        apiError('Invalid or inactive API key', 401);
    }
    if ($key['expires_at'] && strtotime($key['expires_at']) < time()) {
        apiError('API key has expired', 401);
    }

    // Update last used
    $pdo->prepare("UPDATE api_keys SET last_used_at = NOW() WHERE id = ?")->execute([$key['id']]);

    // Set session-like data for auditLog compatibility
    $_SESSION['user_id'] = $key['uid'];
    $_SESSION['username'] = $key['username'];
    $_SESSION['user_role'] = $key['role'];

    return [
        'id' => $key['uid'],
        'username' => $key['username'],
        'full_name' => $key['full_name'],
        'role' => $key['role']
    ];
}

/**
 * Send a JSON response and exit.
 */
function jsonResponse(array $data, int $statusCode = 200): void {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Send a JSON error response and exit.
 */
function apiError(string $message, int $statusCode = 400): void {
    http_response_code($statusCode);
    echo json_encode(['error' => true, 'message' => $message]);
    exit;
}

/**
 * Extract pagination parameters from query string.
 */
function getPaginationParams(): array {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    return ['page' => $page, 'limit' => $limit, 'offset' => $offset];
}

/**
 * Extract whitelisted filter parameters from query string.
 */
function getFilterParams(array $allowed): array {
    $filters = [];
    foreach ($allowed as $key) {
        if (isset($_GET[$key]) && $_GET[$key] !== '') {
            $filters[$key] = $_GET[$key];
        }
    }
    return $filters;
}

/**
 * Validate that required fields exist in data array.
 */
function validateRequiredFields(array $data, array $required): void {
    $missing = [];
    foreach ($required as $field) {
        if (!isset($data[$field]) || $data[$field] === '') {
            $missing[] = $field;
        }
    }
    if ($missing) {
        apiError('Missing required fields: ' . implode(', ', $missing), 422);
    }
}

/**
 * Get JSON body from request.
 */
function getRequestBody(): array {
    $body = json_decode(file_get_contents('php://input'), true);
    if ($body === null && json_last_error() !== JSON_ERROR_NONE) {
        apiError('Invalid JSON body', 400);
    }
    return $body ?? [];
}
