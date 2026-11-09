<?php
/**
 * Couriva - Global Authentication Configuration
 */

if (!defined('WEBMAIL_INIT') && php_sapi_name() !== 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Direct access forbidden.');
}

$ldapConfig = file_exists(__DIR__ . '/ldap.php') ? require __DIR__ . '/ldap.php' : [];

return [
    'driver' => getenv('AUTH_DRIVER') !== false ? getenv('AUTH_DRIVER') : 'local',
    'ldap'   => $ldapConfig,
];
