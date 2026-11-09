<?php
/**
 * Couriva - Public Front Controller
 */

define('WEBMAIL_INIT', true);

require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';

// Check simple route mapping if invoked via URL Rewrite
$route = $_GET['route'] ?? '';

if ($route === 'login') {
    require __DIR__ . '/login.php';
    exit;
} elseif ($route === 'logout') {
    require __DIR__ . '/logout.php';
    exit;
} elseif ($route === 'inbox') {
    require __DIR__ . '/inbox.php';
    exit;
}

// Default entry logic:
if (is_authenticated()) {
    header('Location: ' . app_base_url('public/inbox.php'));
    exit;
} else {
    header('Location: ' . app_base_url('public/login.php'));
    exit;
}
