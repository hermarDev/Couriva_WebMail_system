<?php
/**
 * Minimal SMTP server used by the P1 TLS regression tests.
 *
 * It speaks just enough SMTP to drive SmtpTransport through STARTTLS, then
 * returns the expected replies for a single local delivery transaction.
 *
 * Usage:
 *   php tests/fixtures/smtp_tls_server.php <server.crt> <server.key> <port-file>
 */

declare(strict_types=1);

$cert = $argv[1] ?? '';
$key = $argv[2] ?? '';
$portFile = $argv[3] ?? '';

if ($cert === '' || $key === '' || $portFile === '') {
    fwrite(STDERR, "usage: smtp_tls_server.php <cert> <key> <port-file>\n");
    exit(64);
}

$context = stream_context_create([
    'ssl' => [
        'local_cert'       => $cert,
        'local_pk'         => $key,
        'verify_peer'      => false,
        'verify_peer_name' => false,
        'allow_self_signed'=> true,
    ],
]);

$server = @stream_socket_server(
    'tcp://127.0.0.1:0',
    $errno,
    $errstr,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
    $context
);

if (!$server) {
    fwrite(STDERR, "bind failed: [{$errno}] {$errstr}\n");
    exit(1);
}

$address = stream_socket_get_name($server, false);
$port = (int)substr($address, strrpos($address, ':') + 1);
file_put_contents($portFile, (string)$port, LOCK_EX);

$client = @stream_socket_accept($server, 20);
if (!$client) {
    fclose($server);
    exit(2);
}

stream_set_timeout($client, 15);

function smtpWrite($client, string $line): void
{
    fwrite($client, $line . "\r\n");
}

smtpWrite($client, '220 test.local ESMTP ready');

$tls = false;
$authState = 0;
$dataMode = false;

while (!feof($client)) {
    $line = fgets($client);
    if ($line === false) {
        break;
    }

    $trimmed = rtrim($line, "\r\n");

    if ($dataMode) {
        if ($trimmed === '.') {
            $dataMode = false;
            smtpWrite($client, '250 2.0.0 Message accepted');
        }
        continue;
    }

    $upper = strtoupper($trimmed);

    if (str_starts_with($upper, 'EHLO') || str_starts_with($upper, 'HELO')) {
        if (!$tls) {
            smtpWrite($client, '250-test.local');
            smtpWrite($client, '250-STARTTLS');
            smtpWrite($client, '250 AUTH LOGIN');
        } else {
            smtpWrite($client, '250-test.local');
            smtpWrite($client, '250 AUTH LOGIN');
        }
    } elseif ($upper === 'STARTTLS') {
        smtpWrite($client, '220 2.0.0 Ready to start TLS');

        stream_context_set_option($client, 'ssl', 'local_cert', $cert);
        stream_context_set_option($client, 'ssl', 'local_pk', $key);
        stream_context_set_option($client, 'ssl', 'verify_peer', false);
        stream_context_set_option($client, 'ssl', 'verify_peer_name', false);
        stream_context_set_option($client, 'ssl', 'allow_self_signed', true);

        $ok = @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
        if ($ok !== true) {
            fclose($client);
            fclose($server);
            exit(3);
        }
        $tls = true;
    } elseif ($upper === 'AUTH LOGIN') {
        smtpWrite($client, '334 VXNlcm5hbWU6');
        $authState = 1;
    } elseif ($authState === 1) {
        smtpWrite($client, '334 UGFzc3dvcmQ6');
        $authState = 2;
    } elseif ($authState === 2) {
        smtpWrite($client, '235 2.7.0 Authentication successful');
        $authState = 3;
    } elseif (str_starts_with($upper, 'MAIL FROM')) {
        smtpWrite($client, '250 2.1.0 Sender OK');
    } elseif (str_starts_with($upper, 'RCPT TO')) {
        smtpWrite($client, '250 2.1.5 Recipient OK');
    } elseif ($upper === 'DATA') {
        smtpWrite($client, '354 End data with <CR><LF>.<CR><LF>');
        $dataMode = true;
    } elseif ($upper === 'RSET' || $upper === 'NOOP') {
        smtpWrite($client, '250 2.0.0 OK');
    } elseif ($upper === 'QUIT') {
        smtpWrite($client, '221 2.0.0 Bye');
        break;
    } else {
        smtpWrite($client, '250 2.0.0 OK');
    }
}

fclose($client);
fclose($server);
