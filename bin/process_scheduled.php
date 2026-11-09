<?php
/**
 * Couriva - Scheduled Mail Worker
 *
 * Delivers scheduled messages that are due. Run every minute from cron or
 * Task Scheduler:
 *
 *   * * * * * php /path/to/Web_Mail_System/bin/process_scheduled.php
 *
 * On Windows Task Scheduler, run:
 *   C:\xampp\php\php.exe C:\path\to\Web_Mail_System\bin\process_scheduled.php
 *
 * The web UI also performs a rate-limited best-effort run, but cron is the
 * reliable production path.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This worker may only run from the command line.');
}

define('WEBMAIL_INIT', true);

require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/app/Models/User.php';
require_once dirname(__DIR__) . '/app/Models/Email.php';
require_once dirname(__DIR__) . '/app/Models/Attachment.php';
require_once dirname(__DIR__) . '/app/Services/AttachmentService.php';
require_once dirname(__DIR__) . '/app/Services/SmtpTransport.php';
require_once dirname(__DIR__) . '/app/Services/MailService.php';
require_once dirname(__DIR__) . '/app/Services/ScheduledMailService.php';

$service = new \App\Services\ScheduledMailService($pdo);
$totals = ['processed' => 0, 'sent' => 0, 'failed' => 0];

try {
    // Drain the queue in bounded batches so a large backlog cannot exhaust
    // memory or hold a single worker forever.
    for ($batch = 0; $batch < 20; $batch++) {
        $result = $service->processDue(25);
        $totals['processed'] += $result['processed'];
        $totals['sent'] += $result['sent'];
        $totals['failed'] += $result['failed'];

        if ($result['processed'] === 0) {
            break;
        }
    }

    echo sprintf(
        "[%s] Scheduled worker: processed=%d sent=%d failed=%d\n",
        date('Y-m-d H:i:s'),
        $totals['processed'],
        $totals['sent'],
        $totals['failed']
    );
    exit($totals['failed'] > 0 ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] Scheduled worker error: ' . $e->getMessage() . PHP_EOL);
    exit(2);
}
