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
            'allowed_mimes' => [
                'application/pdf', 'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'image/jpeg', 'image/png', 'image/gif', 'image/webp',
                'text/plain', 'text/csv', 'application/zip', 'application/x-zip-compressed',
            ],
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
     * Return the effective per-file upload limit enforced by PHP and the app.
     */
    public function getEffectiveMaxFileSize(): int
    {
        $limits = [(int)($this->config['max_file_size'] ?? 0)];
        foreach (['upload_max_filesize', 'post_max_size'] as $setting) {
            $value = $this->parseIniBytes((string)ini_get($setting));
            if ($value > 0) {
                $limits[] = $value;
            }
        }

        return min(array_filter($limits, static fn (int $value): bool => $value > 0) ?: [0]);
    }

    /**
     * Validate all uploaded files before any email is persisted or sent.
     *
     * @return array<int, array<string, mixed>>
     */
    public function validateUploadedFiles(array $files): array
    {
        if (empty($files) || !isset($files['name'])) {
            return [];
        }

        $isMultiple = is_array($files['name']);
        $names = $isMultiple ? $files['name'] : [$files['name']];
        $errors = $isMultiple ? ($files['error'] ?? []) : [$files['error'] ?? UPLOAD_ERR_NO_FILE];
        $sizes = $isMultiple ? ($files['size'] ?? []) : [$files['size'] ?? 0];
        $tmpPaths = $isMultiple ? ($files['tmp_name'] ?? []) : [$files['tmp_name'] ?? ''];
        $types = $isMultiple ? ($files['type'] ?? []) : [$files['type'] ?? ''];
        $maxFileSize = $this->getEffectiveMaxFileSize();
        $totalSize = 0;
        $validated = [];

        foreach ($names as $index => $name) {
            $error = (int)($errors[$index] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE && trim((string)$name) === '') {
                continue;
            }
            if ($error !== UPLOAD_ERR_OK) {
                $message = match ($error) {
                    UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'An attachment exceeds the server upload limit of ' . $this->formatBytes($maxFileSize) . '.',
                    UPLOAD_ERR_PARTIAL => 'An attachment upload was incomplete. Please try again.',
                    default => 'An attachment could not be uploaded (error code ' . $error . ').',
                };
                throw new RuntimeException($message);
            }

            $tmpPath = (string)($tmpPaths[$index] ?? '');
            $fileSize = (int)($sizes[$index] ?? 0);
            if ($tmpPath === '' || !is_file($tmpPath) || !is_readable($tmpPath)) {
                throw new RuntimeException('An uploaded attachment is missing or unreadable.');
            }
            if ($maxFileSize > 0 && $fileSize > $maxFileSize) {
                throw new RuntimeException('An attachment exceeds the server upload limit of ' . $this->formatBytes($maxFileSize) . '.');
            }

            $originalName = basename(str_replace("\0", '', (string)$name));
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            if ($ext === '' || in_array($ext, $this->config['disallowed_extensions'], true)) {
                throw new RuntimeException('Executable or unsafe file type is forbidden.');
            }
            if (!in_array($ext, $this->config['allowed_extensions'], true)) {
                throw new RuntimeException("File type .{$ext} is not permitted.");
            }

            // Trust the file content, not the client-supplied name or type.
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $detectedMime = ($finfo ? finfo_file($finfo, $tmpPath) : false) ?: 'application/octet-stream';
            if ($finfo) {
                finfo_close($finfo);
            }
            // finfo reports 0-byte files as x-empty; harmless for plain-text formats.
            if ($detectedMime === 'application/x-empty' || $detectedMime === 'inode/x-empty') {
                $detectedMime = in_array($ext, ['txt', 'csv'], true) ? 'text/plain' : $detectedMime;
            }
            $allowedMimes = $this->config['allowed_mimes'] ?? [];
            if (!empty($allowedMimes) && !in_array($detectedMime, $allowedMimes, true)) {
                throw new RuntimeException('File content type (' . $detectedMime . ') does not match an allowed attachment format.');
            }

            $totalSize += $fileSize;
            $validated[] = [
                'name' => $originalName,
                'type' => (string)($types[$index] ?? ''),
                'detected_mime' => $detectedMime,
                'tmp_name' => $tmpPath,
                'error' => UPLOAD_ERR_OK,
                'size' => $fileSize,
            ];
        }

        $maxTotalSize = (int)($this->config['max_total_size'] ?? 0);
        if ($maxTotalSize > 0 && $totalSize > $maxTotalSize) {
            throw new RuntimeException('The combined attachment size exceeds ' . $this->formatBytes($maxTotalSize) . '.');
        }

        return $validated;
    }

    /**
     * Copy verified source attachment metadata to a sent message and its inbox copies.
     */
    public function copyAttachments(int $sourceEmailId, int $targetEmailId, array $inboxEmailIds = []): int
    {
        if ($sourceEmailId <= 0 || $targetEmailId <= 0) {
            return 0;
        }

        $sourceAttachments = $this->attachmentModel->getByEmailId($sourceEmailId);
        if (empty($sourceAttachments)) {
            return 0;
        }

        $targetEmailIds = array_values(array_unique(array_filter(
            array_map('intval', array_merge([$targetEmailId], $inboxEmailIds)),
            static fn (int $id): bool => $id > 0
        )));
        $insert = $this->pdo->prepare("
            INSERT INTO attachments (email_id, original_filename, stored_filename, filepath, mime_type, filesize, created_at)
            VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ");

        $copied = 0;
        $storageRoot = realpath($this->config['storage_path']);
        foreach ($sourceAttachments as $attachment) {
            $sourcePath = dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR
                . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string)$attachment['filepath']);
            $resolvedSourcePath = realpath($sourcePath);
            if (
                $storageRoot === false
                || $resolvedSourcePath === false
                || !is_file($resolvedSourcePath)
                || !is_readable($resolvedSourcePath)
                || !str_starts_with($resolvedSourcePath, rtrim($storageRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
            ) {
                throw new RuntimeException('A forwarded attachment is missing from storage.');
            }

            foreach ($targetEmailIds as $targetId) {
                $insert->execute([
                    $targetId,
                    $attachment['original_filename'],
                    $attachment['stored_filename'],
                    $attachment['filepath'],
                    $attachment['mime_type'],
                    $attachment['filesize'],
                ]);
            }
            $copied++;
        }

        return $copied;
    }

    /**
     * Validate and store an uploaded file
     */
    public function storeUploadedFile(int $emailId, array $fileArray): array
    {
        $validatedFiles = $this->validateUploadedFiles($fileArray);
        if (empty($validatedFiles)) {
            throw new RuntimeException('No uploaded file was provided.');
        }
        $fileArray = $validatedFiles[0];
        $originalName = $fileArray['name'];
        $fileSize = (int)$fileArray['size'];
        $tmpPath = $fileArray['tmp_name'];
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        // Real MIME type was detected during validation
        $mimeType = $fileArray['detected_mime'];

        // Generate safe unique stored filename
        $storedName = sprintf('att_%d_%s.%s', $emailId, bin2hex(random_bytes(10)), $ext);
        $targetDir = rtrim($this->config['storage_path'], '/\\');
        
        if (!is_dir($targetDir)) {
            if (!mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
                throw new RuntimeException("Failed to create attachment storage directory.");
            }
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

    private function parseIniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        $unit = strtolower(substr($value, -1));
        $number = (float)$value;
        $multiplier = match ($unit) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };

        return (int)($number * $multiplier);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 ** 3) {
            return round($bytes / (1024 ** 3), 1) . ' GB';
        }
        if ($bytes >= 1024 ** 2) {
            return round($bytes / (1024 ** 2), 1) . ' MB';
        }
        return round($bytes / 1024) . ' KB';
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
