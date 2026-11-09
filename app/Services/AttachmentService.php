<?php
/**
 * Couriva - Attachment Service
 * Secure handling of file uploads, MIME validation, and stream downloads
 */

namespace App\Services;

use App\Models\Attachment;
use PDO;
use RuntimeException;

class AttachmentService
{
    protected PDO $pdo;
    protected Attachment $attachmentModel;
    protected array $config;

    public function __construct(PDO $pdo, ?array $mailConfig = null)
    {
        $this->pdo = $pdo;
        $this->attachmentModel = new Attachment($pdo);

        if ($mailConfig === null && file_exists(dirname(dirname(__DIR__)) . '/config/mail.php')) {
            $mailConfig = require dirname(dirname(__DIR__)) . '/config/mail.php';
        }

        $this->config = $mailConfig['attachments'] ?? [
            'storage_path'     => dirname(dirname(__DIR__)) . '/storage/attachments',
            'max_file_size'    => 25 * 1024 * 1024,
            'allowed_extensions' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'txt', 'csv', 'zip'],
            'disallowed_extensions' => [
                'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar',
                'exe', 'bat', 'cmd', 'sh', 'bash', 'vbs', 'js', 'jar', 'py', 'pl',
                'msi', 'dll', 'com', 'scr', 'bin', 'cpl', 'gadget', 'ins', 'jse',
                'jsp', 'asp', 'aspx', 'cgi', 'shtml', 'svg', 'htaccess'
            ],
        ];
    }

    /**
     * Validate and store an uploaded file
     */
    public function storeUploadedFile(int $emailId, array $fileArray): array
    {
        if ($fileArray['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException("Upload error code: " . $fileArray['error']);
        }

        $originalName = basename(str_replace("\0", '', $fileArray['name']));
        $fileSize = (int)$fileArray['size'];
        $tmpPath = $fileArray['tmp_name'];

        // Size check
        if ($fileSize > $this->config['max_file_size']) {
            throw new RuntimeException("File exceeds maximum allowed size (" . round($this->config['max_file_size'] / 1048576) . "MB).");
        }

        // Extension check
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (empty($ext) || in_array($ext, $this->config['disallowed_extensions'], true)) {
            throw new RuntimeException("Executable or unsafe file type is forbidden.");
        }
        if (!in_array($ext, $this->config['allowed_extensions'], true)) {
            throw new RuntimeException("File type .{$ext} is not permitted.");
        }

        // Detect real MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $tmpPath) ?: 'application/octet-stream';
        finfo_close($finfo);

        // Generate safe unique stored filename
        $storedName = sprintf('att_%d_%s.%s', $emailId, bin2hex(random_bytes(10)), $ext);
        $targetDir = rtrim($this->config['storage_path'], '/\\');
        
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0775, true);
        }

        $targetPath = $targetDir . DIRECTORY_SEPARATOR . $storedName;

        $moved = is_uploaded_file($tmpPath)
            ? move_uploaded_file($tmpPath, $targetPath)
            : (php_sapi_name() === 'cli' ? (@rename($tmpPath, $targetPath) || @copy($tmpPath, $targetPath)) : false);

        if (!$moved) {
            throw new RuntimeException("Failed to move uploaded file to storage.");
        }

        $relativeFilepath = 'storage/attachments/' . $storedName;

        // Save metadata into database
        $attachmentId = $this->attachmentModel->create([
            'email_id'          => $emailId,
            'original_filename' => $originalName,
            'stored_filename'   => $storedName,
            'filepath'          => $relativeFilepath,
            'mime_type'         => $mimeType,
            'filesize'          => $fileSize,
        ]);

        return [
            'id'                => $attachmentId,
            'original_filename' => $originalName,
            'stored_filename'   => $storedName,
            'filepath'          => $relativeFilepath,
            'filesize'          => $fileSize,
            'mime_type'         => $mimeType,
        ];
    }

    /**
     * Stream attachment safely to browser
     */
    public function downloadAttachment(int $attachmentId): void
    {
        $attachment = $this->attachmentModel->findById($attachmentId);
        if (!$attachment) {
            http_response_code(404);
            exit("Attachment not found.");
        }

        $baseDir = dirname(dirname(__DIR__));
        $fullPath = $baseDir . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $attachment['filepath']);

        $realBase = realpath(dirname(dirname(__DIR__)) . '/storage/attachments');
        $resolved = realpath($fullPath);

        if (!$resolved || !file_exists($resolved) || ($realBase !== false && !str_starts_with($resolved, $realBase))) {
            http_response_code(404);
            exit("Physical attachment file is missing from storage or path is invalid.");
        }

        // Clean headers and stream file
        if (ob_get_level()) {
            ob_end_clean();
        }

        $safeFilename = str_replace(["\r", "\n", '"', ';'], '', basename($attachment['original_filename']));

        header('Content-Description: File Transfer');
        header('Content-Type: ' . ($attachment['mime_type'] ?: 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
        header('X-Content-Type-Options: nosniff');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($resolved));

        readfile($resolved);
        exit;
    }
}
