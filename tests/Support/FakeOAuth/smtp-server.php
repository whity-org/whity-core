<?php

declare(strict_types=1);

/**
 * A local SMTP server that insists on a well-formed XOAUTH2 sign-in (WHIT-901).
 *
 * Usage: php smtp-server.php <port> <dir>
 *
 * It accepts the tokens listed in <dir>/accepted-tokens, one per line, refuses
 * mail from a session that has not signed in, and appends to <dir>/auth.log and
 * <dir>/delivered.log. A refused token goes the way real servers do it: a 334
 * challenge, an empty line from the client, then 535.
 */

if (!isset($argv)) {
    fwrite(STDERR, "Run this from the command line.
");
    exit(2);
}

[$_, $port, $dir] = $argv;

$server = stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "bind failed: $errstr\n");
    exit(1);
}

$log = static function (string $file, string $line) use ($dir): void {
    file_put_contents($dir . '/' . $file, $line . "\n", FILE_APPEND | LOCK_EX);
};

// @phpstan-ignore-next-line while.alwaysTrue (it runs until the test terminates it)
while (true) {
    $conn = @stream_socket_accept($server, 3600);
    if ($conn === false) {
        continue;
    }
    stream_set_timeout($conn, 10);
    fwrite($conn, "220 fake ESMTP\r\n");
    $signedIn = false;

    while (($line = fgets($conn)) !== false) {
        $line = rtrim($line, "\r\n");

        if (str_starts_with($line, 'EHLO')) {
            fwrite($conn, "250-fake\r\n250 AUTH XOAUTH2\r\n");
        } elseif (str_starts_with($line, 'AUTH XOAUTH2 ')) {
            $decoded = (string) base64_decode(substr($line, 13), true);
            if (preg_match('/^user=([^\x01]+)\x01auth=Bearer ([^\x01]+)\x01\x01$/D', $decoded, $m) !== 1) {
                $log('auth.log', 'malformed');
                fwrite($conn, "501 malformed XOAUTH2\r\n");
                continue;
            }
            $accepted = file($dir . '/accepted-tokens', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            if (in_array($m[2], $accepted, true)) {
                $log('auth.log', 'ok ' . $m[1] . ' ' . $m[2]);
                $signedIn = true;
                fwrite($conn, "235 2.7.0 accepted\r\n");
            } else {
                $log('auth.log', 'refused ' . $m[1] . ' ' . $m[2]);
                fwrite($conn, '334 ' . base64_encode('{"status":"401"}') . "\r\n");
                fgets($conn);
                fwrite($conn, "535 5.7.8 token refused\r\n");
            }
        } elseif (str_starts_with($line, 'MAIL FROM')) {
            fwrite($conn, $signedIn ? "250 ok\r\n" : "530 sign in first\r\n");
        } elseif (str_starts_with($line, 'RCPT TO')) {
            fwrite($conn, "250 ok\r\n");
        } elseif ($line === 'DATA') {
            fwrite($conn, "354 go\r\n");
            $subject = '';
            while (($body = fgets($conn)) !== false && rtrim($body, "\r\n") !== '.') {
                if (str_starts_with($body, 'Subject: ')) {
                    $subject = rtrim(substr($body, 9), "\r\n");
                }
            }
            $log('delivered.log', $subject);
            fwrite($conn, "250 queued\r\n");
        } elseif ($line === 'QUIT') {
            fwrite($conn, "221 bye\r\n");
            break;
        } else {
            fwrite($conn, "502 not implemented\r\n");
        }
    }
    fclose($conn);
}
