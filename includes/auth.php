<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/audit.php';

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']);
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: /hospitalman/modules/auth/login.php');
        exit;
    }
}

function requireRole(array $roles): void {
    requireLogin();
    if (!in_array($_SESSION['user_role'], $roles)) {
        $_SESSION['error'] = 'You do not have permission to access this page.';
        header('Location: /hospitalman/modules/dashboard/index.php');
        exit;
    }
}

function getCurrentUser(): ?array {
    if (!isLoggedIn()) return null;
    if (!isset($_SESSION['user_role'])) {
        session_destroy();
        header('Location: /hospitalman/modules/auth/login.php');
        exit;
    }
    return [
        'id' => $_SESSION['user_id'],
        'username' => $_SESSION['username'] ?? '',
        'full_name' => $_SESSION['full_name'] ?? '',
        'role' => $_SESSION['user_role'] ?? '',
        'email' => $_SESSION['user_email'] ?? ''
    ];
}

function loginUser(string $username, string $password): bool {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND status = 'active'");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['user_email'] = $user['email'];
        auditLog('login', 'auth', 'users', (int)$user['id']);
        return true;
    }
    return false;
}

function logoutUser(): void {
    auditLog('logout', 'auth');
    session_destroy();
    header('Location: /hospitalman/modules/auth/login.php');
    exit;
}

// Role permission map
function canAccess(string $module): bool {
    $permissions = [
        'dashboard'  => ['admin', 'doctor', 'nurse', 'pharmacist', 'receptionist'],
        'patients'   => ['admin', 'doctor', 'nurse', 'receptionist'],
        'clinical'   => ['admin', 'doctor', 'nurse'],
        'billing'    => ['admin', 'receptionist'],
        'pharmacy'   => ['admin', 'pharmacist'],
        'reports'    => ['admin'],
        'users'      => ['admin'],
        'admin'      => ['admin']
    ];
    $role = $_SESSION['user_role'] ?? '';
    return isset($permissions[$module]) && in_array($role, $permissions[$module]);
}
