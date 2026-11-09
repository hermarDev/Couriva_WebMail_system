<?php
/**
 * Couriva - Database Configuration & PDO Factory
 * Supports Windows Server 2019 IIS, Apache/LAMPP, and environment-driven configurations.
 */

// Prevent direct script execution if accessed outside PHP runtime
if (!defined('WEBMAIL_INIT') && php_sapi_name() !== 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Direct access forbidden.');
}

$dbConfig = [
    'host'     => getenv('DB_HOST') !== false ? getenv('DB_HOST') : '127.0.0.1',
    'port'     => getenv('DB_PORT') !== false ? (int)getenv('DB_PORT') : 3306,
    'dbname'   => getenv('DB_NAME') !== false ? getenv('DB_NAME') : 'webmail',
    'username' => getenv('DB_USER') !== false ? getenv('DB_USER') : 'root',
    'password' => getenv('DB_PASS') !== false ? getenv('DB_PASS') : '',
    'charset'  => getenv('DB_CHARSET') !== false ? getenv('DB_CHARSET') : 'utf8mb4',
    'options'  => [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
    ],
];

/**
 * Returns a shared PDO instance (Singleton pattern).
 *
 * @param array|null $overrideConfig Optional config overrides
 * @return PDO
 * @throws PDOException
 */
function getDbConnection(?array $overrideConfig = null): PDO
{
    static $pdoInstance = null;

    if ($pdoInstance === null || $overrideConfig !== null) {
        global $dbConfig;
        $cfg = array_merge($dbConfig, $overrideConfig ?? []);

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'],
            $cfg['port'],
            $cfg['dbname'],
            $cfg['charset']
        );

        $pdo = new PDO($dsn, $cfg['username'], $cfg['password'], $cfg['options']);

        // Keep DATETIME comparisons and TIMESTAMP reads in UTC regardless of
        // the host's system timezone. Presentation converts to the user's
        // saved timezone with format_user_datetime().
        $pdo->exec("SET time_zone = '+00:00'");

        if ($overrideConfig === null) {
            $pdoInstance = $pdo;
        } else {
            return $pdo;
        }
    }

    return $pdoInstance;
}

return $dbConfig;
