<?php
require_once __DIR__ . '/../config/database.php';

/**
 * Log an action to the audit trail.
 * Wrapped in try/catch so audit failures never break the main operation.
 */
function auditLog(
    string $action,
    string $module,
    ?string $recordTable = null,
    ?int $recordId = null,
    ?array $oldValues = null,
    ?array $newValues = null
): void {
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, username, action, module, record_table, record_id, old_values, new_values, ip_address, user_agent) VALUES (?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $_SESSION['user_id'] ?? null,
            $_SESSION['username'] ?? 'system',
            $action,
            $module,
            $recordTable,
            $recordId,
            $oldValues ? json_encode($oldValues) : null,
            $newValues ? json_encode($newValues) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)
        ]);
    } catch (\Exception $e) {
        // Silently fail - audit logging should never break the main operation
        error_log('Audit log failed: ' . $e->getMessage());
    }
}
