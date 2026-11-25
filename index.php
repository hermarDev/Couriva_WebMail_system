<?php
/**
 * Couriva - Root Entry Point
 * Safely forwards incoming web traffic to public/index.php
 */

define('WEBMAIL_ROOT', __DIR__);

// If invoked via CLI, output system status
if (php_sapi_name() === 'cli') {
    echo "Couriva (Windows Server 2019 IIS & LAMPP Ready)\n";
    echo "Root Directory: " . __DIR__ . "\n";
    exit(0);
}

// Compute the dynamic web base path
$scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
$targetUrl = ($scriptDir === '' ? '' : $scriptDir) . '/public/index.php';

// Preserve existing query strings if present
if (!empty($_SERVER['QUERY_STRING'])) {
    $targetUrl .= '?' . $_SERVER['QUERY_STRING'];
}

// Perform redirect to front controller
// Strip the PHP version banner before responding (this entry point never
// loads includes/functions.php, where send_security_headers() would do it).
if (function_exists('header_remove')) {
    header_remove('X-Powered-By');
}
header('Location: ' . $targetUrl, true, 302);
exit;
