<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'erpsolution26_hospital_db');
define('DB_USER', 'erpsolution26_erpsolution26');
define('DB_PASS', 'tutushi@2026');

// Dynamic base URL - works on any hosting (XAMPP, cPanel, etc.)
if (!defined('BASE_URL')) {
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME']);
    if (preg_match('#^(/.+?)/(config|modules|includes|api|assets)/#', $scriptName, $m)) {
        // Project is in a subfolder (e.g., /hospitalman)
        define('BASE_URL', $m[1]);
    } else {
        // Project is at the document root
        define('BASE_URL', '');
    }
}

function getDBConnection() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }
    return $pdo;
}
