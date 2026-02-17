<?php
require_once __DIR__ . '/../config/database.php';

function generatePatientId(): string {
    $pdo = getDBConnection();
    $stmt = $pdo->query("SELECT MAX(id) as max_id FROM patients");
    $result = $stmt->fetch();
    $next = ($result['max_id'] ?? 0) + 1;
    return 'PAT-' . str_pad($next, 4, '0', STR_PAD_LEFT);
}

function generateInvoiceNumber(): string {
    $pdo = getDBConnection();
    $stmt = $pdo->query("SELECT MAX(id) as max_id FROM invoices");
    $result = $stmt->fetch();
    $next = ($result['max_id'] ?? 0) + 1;
    return 'INV-' . str_pad($next, 4, '0', STR_PAD_LEFT);
}

function formatDate(string $date): string {
    return date('d M Y', strtotime($date));
}

function formatDateTime(string $datetime): string {
    return date('d M Y H:i', strtotime($datetime));
}

function formatCurrency(float $amount): string {
    // Indian Rupee formatting with Indian numbering system
    // Format: ₹1,00,000.00 (1 lakh), ₹10,00,000.00 (10 lakhs), ₹1,00,00,000.00 (1 crore)

    $isNegative = $amount < 0;
    $amount = abs($amount);

    // Split into rupees and paise
    $rupees = floor($amount);
    $paise = round(($amount - $rupees) * 100);

    // Format according to Indian numbering system
    $formattedAmount = '';
    $rupeesStr = (string) $rupees;
    $length = strlen($rupeesStr);

    if ($length <= 3) {
        // Less than or equal to 999
        $formattedAmount = $rupeesStr;
    } else {
        // First 3 digits from right
        $lastThree = substr($rupeesStr, -3);
        $remaining = substr($rupeesStr, 0, -3);

        // Add commas every 2 digits for remaining
        $formattedAmount = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $remaining) . ',' . $lastThree;
    }

    // Add paise (decimal places)
    $formattedAmount .= '.' . str_pad($paise, 2, '0', STR_PAD_LEFT);

    // Add rupee symbol and negative sign if needed
    return ($isNegative ? '-' : '') . '₹' . $formattedAmount;
}

function sanitize(string $input): string {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function setFlashMessage(string $type, string $message): void {
    $_SESSION[$type] = $message;
}

function getFlashMessage(string $type): ?string {
    if (isset($_SESSION[$type])) {
        $msg = $_SESSION[$type];
        unset($_SESSION[$type]);
        return $msg;
    }
    return null;
}

function getDoctors(): array {
    $pdo = getDBConnection();
    $stmt = $pdo->query("SELECT id, full_name, specialization FROM users WHERE role = 'doctor' AND status = 'active' ORDER BY full_name");
    return $stmt->fetchAll();
}

function getPatientById(int $id): ?array {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT * FROM patients WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function getUserById(int $id): ?array {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT id, username, full_name, email, phone, role, specialization, status FROM users WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function countRows(string $table, string $where = '1=1', array $params = []): int {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM $table WHERE $where");
    $stmt->execute($params);
    $result = $stmt->fetch();
    return (int) $result['cnt'];
}

function getStatusBadge(string $status): string {
    $badges = [
        'scheduled'          => 'primary',
        'completed'          => 'success',
        'cancelled'          => 'danger',
        'pending'            => 'warning',
        'dispensed'          => 'success',
        'ordered'            => 'info',
        'in_progress'        => 'primary',
        'paid'               => 'success',
        'partial'            => 'warning',
        'unpaid'             => 'danger',
        'active'             => 'success',
        'inactive'           => 'secondary',
        'submitted'          => 'info',
        'under_review'       => 'primary',
        'approved'           => 'success',
        'partially_approved' => 'warning',
        'rejected'           => 'danger'
    ];
    $class = $badges[$status] ?? 'secondary';
    return '<span class="badge bg-' . $class . '">' . ucfirst($status) . '</span>';
}
