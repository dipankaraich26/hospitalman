<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'hospital_db');
define('DB_USER', 'root');
define('DB_PASS', '');

// Dynamic base URL - works on any hosting (XAMPP, cPanel, etc.)
if (!defined('BASE_URL')) {
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME']);
    // Find "config/" or "modules/" or "includes/" or "api/" in the path to determine project root
    if (preg_match('#^(.*?)/(config|modules|includes|api|assets)/#', $scriptName, $m)) {
        define('BASE_URL', $m[1]);
    } else {
        // Script is at the project root (e.g., index.php)
        define('BASE_URL', rtrim(str_replace('\\', '/', dirname($scriptName)), '/'));
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
