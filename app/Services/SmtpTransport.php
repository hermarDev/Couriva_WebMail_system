<?php
/**
 * Couriva - SMTP Transport Client (Phase 2 Outbound)
 * Implements socket-based RFC 5321 / RFC 5322 SMTP client supporting:
 * - EHLO / HELO
 * - STARTTLS encryption
 * - SMTP AUTH LOGIN
 * - Multi-recipient delivery (To, Cc, Bcc)
 * - MIME multipart / attachments encoding
 */

namespace App\Services;

use RuntimeException;
use InvalidArgumentException;

class SmtpTransport
{
    protected string $host;
    protected int $port;
    protected ?string $encryption; // 'tls', 'ssl', or null
    protected bool $auth;
    protected string $username;
    protected string $password;
    protected int $timeout;
    protected bool $verifyPeer;
    protected bool $verifyPeerName;
    protected bool $allowSelfSigned;
    protected ?string $caFile;

    /** @var resource|null */
    protected $socket = null;

    public function __construct(array $config = [])
    {
        $this->host = $config['host'] ?? '127.0.0.1';
        $this->port = (int)($config['port'] ?? 587);
        $this->encryption = isset($config['encryption']) ? strtolower($config['encryption']) : 'tls';
        $this->auth = !empty($config['auth']);
        $this->username = $config['username'] ?? '';
        $this->password = $config['password'] ?? '';
        $this->timeout = (int)($config['timeout'] ?? 30);
        $this->verifyPeer = array_key_exists('verify_peer', $config) ? (bool)$config['verify_peer'] : true;
        $this->verifyPeerName = array_key_exists('verify_peer_name', $config) ? (bool)$config['verify_peer_name'] : true;
        $this->allowSelfSigned = !empty($config['allow_self_signed']);
        $this->caFile = !empty($config['ca_file']) ? (string)$config['ca_file'] : null;
    }

    /**
     * Send email message via SMTP
     *
     * @param string $fromAddress
     * @param string $fromName
     * @param array $recipients ['to' => [...], 'cc' => [...], 'bcc' => [...]]
     * @param string $subject
     * @param string $body
     * @param array $attachments Array of file descriptors [['path' => ..., 'name' => ..., 'mime' => ...]]
     * @return bool
     * @throws RuntimeException
     */
    public function send(
        string $fromAddress,
        string $fromName,
        array $recipients,
        string $subject,
        string $body,
        array $attachments = []
    ): bool {
        $to = $recipients['to'] ?? [];
        $cc = $recipients['cc'] ?? [];
        $bcc = $recipients['bcc'] ?? [];

        $allRecipients = array_unique(array_filter(array_merge($to, $cc, $bcc)));
        if (empty($allRecipients)) {
            throw new InvalidArgumentException("No valid recipient addresses provided for SMTP delivery.");
        }

        // Read attachments before connecting so missing or unreadable files never send.
        $attachments = $this->prepareAttachments($attachments);

        $this->connect();

        try {
            $clientHost = gethostname() ?: 'couriva.local';
            $this->sendCommand("EHLO {$clientHost}", 250);

            // Handle STARTTLS
            if ($this->encryption === 'tls') {
                $this->sendCommand("STARTTLS", 220);
                $cryptoResult = @stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if (!$cryptoResult) {
                    throw new RuntimeException("Failed to negotiate TLS handshake with SMTP server {$this->host}:{$this->port}");
                }
                // Resend EHLO following TLS negotiation
                $this->sendCommand("EHLO {$clientHost}", 250);
            }

            // Handle AUTH LOGIN
            if ($this->auth && !empty($this->username)) {
                $this->sendCommand("AUTH LOGIN", 334);
                $this->sendCommand(base64_encode($this->username), 334);
                $this->sendCommand(base64_encode($this->password), 235);
            }

            // Envelope Sender
            $this->sendCommand("MAIL FROM:<{$fromAddress}>", 250);

            // Envelope Recipients
            foreach ($allRecipients as $rcpt) {
                $rcpt = trim($rcpt);
                if (!empty($rcpt)) {
                    $this->sendCommand("RCPT TO:<{$rcpt}>", [250, 251]);
                }
            }

            // DATA phase
            $this->sendCommand("DATA", 354);

            $mimeData = $this->buildMimeMessage($fromAddress, $fromName, $to, $cc, $subject, $body, $attachments);
            $this->sendRawData($mimeData);
            $this->sendCommand(".", 250);

            // QUIT
            $this->sendCommand("QUIT", 221);
            return true;
        } finally {
            $this->disconnect();
        }
    }

    /**
     * Establish socket connection to SMTP server
     */
    protected function connect(): void
    {
        $prefix = ($this->encryption === 'ssl') ? 'ssl://' : 'tcp://';
        $remoteSocket = $prefix . $this->host . ':' . $this->port;

        $sslOptions = [
            'verify_peer'      => $this->verifyPeer,
            'verify_peer_name' => $this->verifyPeerName,
            'allow_self_signed'=> $this->allowSelfSigned,
            'SNI_enabled'      => true,
        ];

        if ($this->caFile !== null && $this->caFile !== '') {
            $sslOptions['cafile'] = $this->caFile;
        }

        // Explicit development-only escape hatch. Disabling peer verification
        // also disables hostname verification and permits self-signed certs.
        if (!$this->verifyPeer) {
            $sslOptions['verify_peer_name'] = false;
            $sslOptions['allow_self_signed'] = true;
        }

        $context = stream_context_create(['ssl' => $sslOptions]);

        $errNo = 0;
        $errStr = '';
        $this->socket = @stream_socket_client(
            $remoteSocket,
            $errNo,
            $errStr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$this->socket) {
            throw new RuntimeException("Could not connect to SMTP server ({$remoteSocket}): [{$errNo}] {$errStr}");
        }

        stream_set_timeout($this->socket, $this->timeout);

        // Expect 220 banner greeting
        $response = $this->readResponse();
        $this->checkResponseCode($response, 220);
    }

    /**
     * Send command and verify expected response code(s)
     *
     * @param string $command
     * @param int|array $expectedCode
     * @return string
     */
    protected function sendCommand(string $command, int|array $expectedCode): string
    {
        if (!$this->socket) {
            throw new RuntimeException("SMTP socket is not connected.");
        }

        fwrite($this->socket, $command . "\r\n");
        $response = $this->readResponse();
        $this->checkResponseCode($response, $expectedCode, $command);
        return $response;
    }

    /**
     * Send raw payload (e.g. DATA buffer)
     */
    protected function sendRawData(string $data): void
    {
        if (!$this->socket) {
            throw new RuntimeException("SMTP socket is not connected.");
        }

        // Dot stuffing: prepend extra dot to any line starting with a dot
        $lines = explode("\r\n", $data);
        foreach ($lines as &$line) {
            if (str_starts_with($line, '.')) {
                $line = '.' . $line;
            }
        }
        unset($line);

        $payload = implode("\r\n", $lines) . "\r\n";
        fwrite($this->socket, $payload);
    }

    /**
     * Read multiline SMTP response
     */
    protected function readResponse(): string
    {
        $response = '';
        while (!feof($this->socket)) {
            $line = fgets($this->socket, 1024);
            if ($line === false) {
                break;
            }
            $response .= $line;
            // Check if end of response: code followed by space (e.g. "250 Ok\r\n")
            if (preg_match('/^[0-9]{3} /', $line)) {
                break;
            }
        }
        return trim($response);
    }

    /**
     * Check if response code matches expected code(s)
     */
    protected function checkResponseCode(string $response, int|array $expectedCode, string $cmd = ''): void
    {
        $code = (int)substr($response, 0, 3);
        $expected = is_array($expectedCode) ? $expectedCode : [$expectedCode];

        if (!in_array($code, $expected, true)) {
            $msg = "SMTP command failed: " . ($cmd ? "[{$cmd}] " : "") . "Response: {$response}";
            throw new RuntimeException($msg);
        }
    }

    /**
     * Validate and read attachments before any SMTP connection is made.
     */
    protected function prepareAttachments(array $attachments): array
    {
        $prepared = [];

        foreach ($attachments as $att) {
            $path = is_array($att) ? ($att['path'] ?? '') : '';
            if (empty($path) || !is_file($path) || !is_readable($path)) {
                throw new RuntimeException("SMTP attachment is missing or unreadable: {$path}");
            }

            $content = @file_get_contents($path);
            if ($content === false) {
                throw new RuntimeException("SMTP attachment is missing or unreadable: {$path}");
            }

            $att['path'] = $path;
            $att['content'] = $content;
            $prepared[] = $att;
        }

        return $prepared;
    }

    /**
     * Build RFC 5322 MIME message
     */
    protected function buildMimeMessage(
        string $fromAddress,
        string $fromName,
        array $to,
        array $cc,
        string $subject,
        string $body,
        array $attachments = []
    ): string {
        $headers = [];
        $fromFormatted = !empty($fromName) ? "=?UTF-8?B?" . base64_encode($fromName) . "?= <{$fromAddress}>" : "<{$fromAddress}>";
        $headers[] = "Date: " . date('r');
        $headers[] = "From: {$fromFormatted}";
        $headers[] = "To: " . implode(', ', $to);

        if (!empty($cc)) {
            $headers[] = "Cc: " . implode(', ', $cc);
        }

        $headers[] = "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=";
        $headers[] = "Message-ID: <" . bin2hex(random_bytes(16)) . "@" . (parse_url($fromAddress, PHP_URL_HOST) ?: 'couriva.local') . ">";
        $headers[] = "MIME-Version: 1.0";

        if (empty($attachments)) {
            $headers[] = "Content-Type: text/plain; charset=UTF-8; format=flowed";
            $headers[] = "Content-Transfer-Encoding: 8bit";
            return implode("\r\n", $headers) . "\r\n\r\n" . $body;
        }

        // Multipart message with attachments
        $boundary = "==_Multipart_Boundary_" . bin2hex(random_bytes(12));
        $headers[] = "Content-Type: multipart/mixed; boundary=\"{$boundary}\"";

        $mime = implode("\r\n", $headers) . "\r\n\r\n";
        $mime .= "This is a multi-part message in MIME format.\r\n\r\n";

        // Plain text body part
        $mime .= "--{$boundary}\r\n";
        $mime .= "Content-Type: text/plain; charset=UTF-8; format=flowed\r\n";
        $mime .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $mime .= $body . "\r\n\r\n";

        // Attachment parts
        foreach ($attachments as $att) {
            $path = $att['path'] ?? '';
            $filename = $att['name'] ?? basename($path);
            $mimeType = $att['mime'] ?? 'application/octet-stream';

            $content = $att['content'] ?? file_get_contents($path);
            $encodedContent = chunk_split(base64_encode($content));

            $mime .= "--{$boundary}\r\n";
            $mime .= "Content-Type: {$mimeType}; name=\"{$filename}\"\r\n";
            $mime .= "Content-Transfer-Encoding: base64\r\n";
            $mime .= "Content-Disposition: attachment; filename=\"{$filename}\"\r\n\r\n";
            $mime .= $encodedContent . "\r\n";
        }

        $mime .= "--{$boundary}--\r\n";
        return $mime;
    }

    /**
     * Send email with per-recipient tracking for delivery status
     *
     * @param string $fromAddress
     * @param string $fromName
     * @param array $recipients ['to' => [...], 'cc' => [...], 'bcc' => [...]]
     * @param string $subject
     * @param string $body
     * @param array $attachments
     * @param string $messageId Pre-generated Message-ID for idempotency
     * @return array ['recipients' => [email => ['accepted' => bool, 'response' => string, 'code' => int]]]
     * @throws RuntimeException
     */
    public function sendWithTracking(
        string $fromAddress,
        string $fromName,
        array $recipients,
        string $subject,
        string $body,
        array $attachments = [],
        string $messageId = ''
    ): array {
        $to = $recipients['to'] ?? [];
        $cc = $recipients['cc'] ?? [];
        $bcc = $recipients['bcc'] ?? [];

        $allRecipients = array_unique(array_filter(array_merge($to, $cc, $bcc)));
        if (empty($allRecipients)) {
            throw new InvalidArgumentException("No valid recipient addresses provided for SMTP delivery.");
        }

        // Read attachments before connecting
        $attachments = $this->prepareAttachments($attachments);

        $this->connect();

        try {
            $clientHost = gethostname() ?: 'couriva.local';
            $this->sendCommand("EHLO {$clientHost}", 250);

            // Handle STARTTLS
            if ($this->encryption === 'tls') {
                $this->sendCommand("STARTTLS", 220);
                $cryptoResult = @stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if (!$cryptoResult) {
                    throw new RuntimeException("Failed to negotiate TLS handshake with SMTP server {$this->host}:{$this->port}");
                }
                $this->sendCommand("EHLO {$clientHost}", 250);
            }

            // Handle AUTH LOGIN
            if ($this->auth && !empty($this->username)) {
                $this->sendCommand("AUTH LOGIN", 334);
                $this->sendCommand(base64_encode($this->username), 334);
                $this->sendCommand(base64_encode($this->password), 235);
            }

            // Envelope Sender
            $this->sendCommand("MAIL FROM:<{$fromAddress}>", 250);

            // Envelope Recipients - track each RCPT response individually
            $recipientResults = [];
            foreach ($allRecipients as $rcpt) {
                $rcpt = trim($rcpt);
                if (!empty($rcpt)) {
                    try {
                        $response = $this->sendCommand("RCPT TO:<{$rcpt}>", [250, 251]);
                        $code = (int)substr($response, 0, 3);
                        $recipientResults[$rcpt] = ['accepted' => true, 'response' => $response, 'code' => $code];
                    } catch (RuntimeException $e) {
                        $code = 0;
                        if (preg_match('/Response: .*?([0-9]{3})/', $e->getMessage(), $m)) {
                            $code = (int)$m[1];
                        }
                        $recipientResults[$rcpt] = ['accepted' => false, 'response' => $e->getMessage(), 'code' => $code];
                    }
                }
            }

            // DATA phase - use provided messageId or generate one
            if (empty($messageId)) {
                $messageId = '<' . bin2hex(random_bytes(16)) . '@' . (parse_url($fromAddress, PHP_URL_HOST) ?: 'couriva.local') . '>';
            }

            $this->sendCommand("DATA", 354);

            $mimeData = $this->buildMimeMessageWithId($fromAddress, $fromName, $to, $cc, $subject, $body, $attachments, $messageId);
            $this->sendRawData($mimeData);
            $dataResponse = $this->sendCommand(".", 250);
            $dataCode = (int)substr($dataResponse, 0, 3);

            // QUIT
            $this->sendCommand("QUIT", 221);

            // Merge DATA result into each recipient (if DATA failed, all fail)
            if ($dataCode !== 250) {
                foreach ($recipientResults as $rcpt => &$result) {
                    if ($result['accepted']) {
                        $result['accepted'] = false;
                        $result['response'] = $dataResponse;
                        $result['code'] = $dataCode;
                    }
                }
                unset($result);
            }

            return ['recipients' => $recipientResults, 'message_id' => $messageId, 'data_response' => $dataResponse, 'data_code' => $dataCode];
        } finally {
            $this->disconnect();
        }
    }

    /**
     * Build RFC 5322 MIME message with specific Message-ID
     */
    protected function buildMimeMessageWithId(
        string $fromAddress,
        string $fromName,
        array $to,
        array $cc,
        string $subject,
        string $body,
        array $attachments = [],
        string $messageId
    ): string {
        $headers = [];
        $fromFormatted = !empty($fromName) ? "=?UTF-8?B?" . base64_encode($fromName) . "?= <{$fromAddress}>" : "<{$fromAddress}>";
        $headers[] = "Date: " . date('r');
        $headers[] = "From: {$fromFormatted}";
        $headers[] = "To: " . implode(', ', $to);

        if (!empty($cc)) {
            $headers[] = "Cc: " . implode(', ', $cc);
        }

        $headers[] = "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=";
        $headers[] = "Message-ID: {$messageId}";
        $headers[] = "MIME-Version: 1.0";

        if (empty($attachments)) {
            $headers[] = "Content-Type: text/plain; charset=UTF-8; format=flowed";
            $headers[] = "Content-Transfer-Encoding: 8bit";
            return implode("\r\n", $headers) . "\r\n\r\n" . $body;
        }

        // Multipart message with attachments
        $boundary = "==_Multipart_Boundary_" . bin2hex(random_bytes(12));
        $headers[] = "Content-Type: multipart/mixed; boundary=\"{$boundary}\"";

        $mime = implode("\r\n", $headers) . "\r\n\r\n";
        $mime .= "This is a multi-part message in MIME format.\r\n\r\n";

        // Plain text body part
        $mime .= "--{$boundary}\r\n";
        $mime .= "Content-Type: text/plain; charset=UTF-8; format=flowed\r\n";
        $mime .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $mime .= $body . "\r\n\r\n";

        // Attachment parts
        foreach ($attachments as $att) {
            $path = $att['path'] ?? '';
            $filename = $att['name'] ?? basename($path);
            $mimeType = $att['mime'] ?? 'application/octet-stream';

            $content = $att['content'] ?? file_get_contents($path);
            $encodedContent = chunk_split(base64_encode($content));

            $mime .= "--{$boundary}\r\n";
            $mime .= "Content-Type: {$mimeType}; name=\"{$filename}\"\r\n";
            $mime .= "Content-Transfer-Encoding: base64\r\n";
            $mime .= "Content-Disposition: attachment; filename=\"{$filename}\"\r\n\r\n";
            $mime .= $encodedContent . "\r\n";
        }

        $mime .= "--{$boundary}--\r\n";
        return $mime;
    }

    /**
     * Terminate and close socket
     */
    protected function disconnect(): void
    {
        if ($this->socket && is_resource($this->socket)) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }
}
