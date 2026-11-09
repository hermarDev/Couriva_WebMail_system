<?php
/**
 * Couriva - Logout Controller
 */

define('WEBMAIL_INIT', true);

require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';

perform_logout();

set_flash('info', 'You have been successfully signed out.');
header('Location: ' . app_base_url('public/login.php'));
exit;
