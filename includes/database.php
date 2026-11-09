<?php
/**
 * Couriva - Database Include Helper
 * Exposes PDO connection object and helpers to application scripts.
 */

if (!defined('WEBMAIL_INIT')) {
    define('WEBMAIL_INIT', true);
}

$dbConfigFile = dirname(__DIR__) . '/config/database.php';

if (!file_exists($dbConfigFile)) {
    throw new RuntimeException("Database configuration file not found at: {$dbConfigFile}");
}

require_once $dbConfigFile;

try {
    $pdo = getDbConnection();
} catch (PDOException $e) {
    // In production, do not leak raw connection details to users
    error_log("Database connection error: " . $e->getMessage());
    die("Database service temporarily unavailable. Please contact the administrator.");
}
