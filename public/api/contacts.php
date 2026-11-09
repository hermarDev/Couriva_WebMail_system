<?php
/**
 * Couriva - AJAX API: Contacts Typeahead & Listing
 * Returns JSON array of user contacts for address book auto-completion
 */

define('WEBMAIL_INIT', true);

header('Content-Type: application/json; charset=utf-8');

require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/database.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/app/Models/Contact.php';

// Authentication Check
if (!is_authenticated()) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

require_api_password_change();

$userId = (int)$_SESSION['user_id'];
$q = trim($_GET['q'] ?? '');

$contactModel = new \App\Models\Contact($pdo);
$contacts = $contactModel->getByUserId($userId, !empty($q) ? $q : null);

$response = [];
foreach ($contacts as $contact) {
    $response[] = [
        'id'      => (int)$contact['id'],
        'name'    => $contact['contact_name'],
        'email'   => $contact['contact_email'],
        'company' => $contact['company'] ?? '',
        'phone'   => $contact['phone'] ?? '',
    ];
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
