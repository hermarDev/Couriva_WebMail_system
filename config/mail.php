<?php
/**
 * Couriva - Mail Service & Attachment Configuration
 * Supports Phase 1 (Database-backed internal mail) and Phase 2 (SMTP/IMAP protocol integration)
 */

// Prevent direct script execution
if (!defined('WEBMAIL_INIT') && php_sapi_name() !== 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Direct access forbidden.');
}

// SMTP TLS verification defaults on. SMTP_INSECURE_TLS=true is a
// development-only escape hatch and must never be used in production.
$smtpInsecureTls = getenv('APP_ENV') === 'development'
    && getenv('SMTP_INSECURE_TLS') === 'true';

return [
    /*
    |--------------------------------------------------------------------------
    | Default Mail Driver
    |--------------------------------------------------------------------------
    | Options:
    |   - 'database' : Phase 1 self-contained relational messaging (default)
    |   - 'smtp'     : Outbound SMTP socket / PHPMailer transport
    */
    'driver' => getenv('MAIL_DRIVER') !== false ? getenv('MAIL_DRIVER') : 'database',

    /*
    |--------------------------------------------------------------------------
    | Default Domain
    |--------------------------------------------------------------------------
    */
    'domain' => getenv('MAIL_DOMAIN') !== false ? getenv('MAIL_DOMAIN') : 'couriva.local',

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address & Name
    |--------------------------------------------------------------------------
    */
    'from' => [
        'address' => getenv('MAIL_FROM_ADDRESS') !== false ? getenv('MAIL_FROM_ADDRESS') : 'no-reply@couriva.local',
        'name'    => getenv('MAIL_FROM_NAME') !== false ? getenv('MAIL_FROM_NAME') : 'Couriva',
    ],

    /*
    |--------------------------------------------------------------------------
    | SMTP Server Settings (Phase 2 Outbound)
    |--------------------------------------------------------------------------
    */
    'smtp' => [
        'host'       => getenv('SMTP_HOST') !== false ? getenv('SMTP_HOST') : '127.0.0.1',
        'port'       => getenv('SMTP_PORT') !== false ? (int)getenv('SMTP_PORT') : 587,
        'encryption' => getenv('SMTP_ENCRYPTION') !== false ? getenv('SMTP_ENCRYPTION') : 'tls', // 'tls', 'ssl', or null
        'auth'       => getenv('SMTP_AUTH') !== 'false',
        'username'   => getenv('SMTP_USER') !== false ? getenv('SMTP_USER') : '',
        'password'   => getenv('SMTP_PASS') !== false ? getenv('SMTP_PASS') : '',
        'timeout'    => getenv('SMTP_TIMEOUT') !== false ? (int)getenv('SMTP_TIMEOUT') : 30,
        'verify_peer'       => !$smtpInsecureTls && getenv('SMTP_VERIFY_PEER') !== 'false',
        'verify_peer_name'  => !$smtpInsecureTls && getenv('SMTP_VERIFY_PEER_NAME') !== 'false',
        'allow_self_signed' => $smtpInsecureTls,
        'ca_file'           => getenv('SMTP_CA_FILE') !== false ? (string)getenv('SMTP_CA_FILE') : null,
    ],

    /*
    |--------------------------------------------------------------------------
    | IMAP Server Settings (Phase 2 Inbound Sync)
    |--------------------------------------------------------------------------
    */
    'imap' => [
        'host'          => getenv('IMAP_HOST') !== false ? getenv('IMAP_HOST') : '127.0.0.1',
        'port'          => getenv('IMAP_PORT') !== false ? (int)getenv('IMAP_PORT') : 993,
        'encryption'    => getenv('IMAP_ENCRYPTION') !== false ? getenv('IMAP_ENCRYPTION') : 'ssl',
        'validate_cert' => getenv('IMAP_VALIDATE_CERT') === 'true',
    ],

    /*
    |--------------------------------------------------------------------------
    | Attachment Security & Storage Settings
    |--------------------------------------------------------------------------
    */
    'attachments' => [
        'storage_path'     => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'attachments',
        'max_file_size'    => 25 * 1024 * 1024, // 25 MB max per file
        'max_total_size'   => 50 * 1024 * 1024, // 50 MB max per email compose
        'allowed_mimes'    => [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'text/plain',
            'text/csv',
            'application/zip',
            'application/x-zip-compressed',
        ],
        'allowed_extensions' => [
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'txt', 'csv', 'zip',
        ],
        'disallowed_extensions' => [
            'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar',
            'exe', 'bat', 'cmd', 'sh', 'bash', 'vbs', 'js', 'jar', 'py', 'pl',
            'msi', 'dll', 'com', 'scr', 'bin', 'cpl', 'gadget', 'ins', 'jse',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Mailbox UI & Pagination Settings
    |--------------------------------------------------------------------------
    */
    'ui' => [
        'per_page'      => 25,
        'auto_refresh'  => 60, // seconds for auto polling new mail
    ],
];
