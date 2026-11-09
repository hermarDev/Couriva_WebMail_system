<?php
/**
 * Couriva - AJAX API: Mail Organization (Labels & Folders)
 */

define('WEBMAIL_INIT', true);

header('Content-Type: application/json; charset=utf-8');

require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/database.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/app/Services/OrganizationService.php';

if (!is_authenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

require_api_password_change();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!verify_csrf_token()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid or expired security token']);
    exit;
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$input = [];
if (stripos($contentType, 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?: [];
} else {
    $input = $_POST;
}

$action = trim((string)($input['action'] ?? ''));
$userId = (int)$_SESSION['user_id'];
$orgService = new \App\Services\OrganizationService($pdo);

try {
    if ($action === 'create_label') {
        $name = trim((string)($input['name'] ?? ''));
        $color = trim((string)($input['color'] ?? '#6366f1'));
        if ($name === '') {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'Label name is required']);
            exit;
        }
        $id = $orgService->createLabel($userId, $name, $color);
        echo json_encode([
            'success' => true,
            'message' => 'Label created successfully',
            'item' => [
                'id' => $id,
                'name' => $name,
                'color' => $color,
                'type' => 'label',
                'url' => app_base_url('public/inbox.php?folder=label&label=' . $id),
            ]
        ]);
        exit;
    }

    if ($action === 'create_folder') {
        $name = trim((string)($input['name'] ?? ''));
        $color = trim((string)($input['color'] ?? '#64748b'));
        if ($name === '') {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'Folder name is required']);
            exit;
        }
        $id = $orgService->createFolder($userId, $name, $color);
        echo json_encode([
            'success' => true,
            'message' => 'Folder created successfully',
            'item' => [
                'id' => $id,
                'name' => $name,
                'color' => $color,
                'type' => 'folder',
                'url' => app_base_url('public/inbox.php?folder=custom&id=' . $id),
            ]
        ]);
        exit;
    }

    if ($action === 'update_label') {
        $id = (int)($input['label_id'] ?? 0);
        $name = trim((string)($input['name'] ?? ''));
        $color = trim((string)($input['color'] ?? '#6366f1'));
        if ($name === '') {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'Label name is required']);
            exit;
        }
        $updated = $orgService->updateLabel($userId, $id, $name, $color);
        if (!$updated) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Label not found']);
            exit;
        }
        echo json_encode(['success' => true, 'message' => 'Label updated successfully']);
        exit;
    }

    if ($action === 'update_folder') {
        $id = (int)($input['folder_id'] ?? 0);
        $name = trim((string)($input['name'] ?? ''));
        $color = trim((string)($input['color'] ?? '#64748b'));
        if ($name === '') {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'Folder name is required']);
            exit;
        }
        $updated = $orgService->updateFolder($userId, $id, $name, $color);
        if (!$updated) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Folder not found']);
            exit;
        }
        echo json_encode(['success' => true, 'message' => 'Folder updated successfully']);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unknown action']);
} catch (\InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('Organization API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'A server error occurred']);
}
