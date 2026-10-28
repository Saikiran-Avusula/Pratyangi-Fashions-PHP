<?php
declare(strict_types=1);

// A tiny SMTP server for tests ONLY: `php tests/fake_smtp/server.php <port> <state dir>`. Plain text on 127.0.0.1 (no TLS), one client at a time.
// Accepts AUTH PLAIN/LOGIN with any login and writes one JSON line per delivered message to <dir>/messages.log: {user, from, to, data}.
// <dir>/reject.flag -> RCPT answers 550. <dir>/ready is created once it listens.
[, $port, $dir] = $argv + [null, '0', sys_get_temp_dir()];
$server = stream_socket_server('tcp://127.0.0.1:' . (int) $port, $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "cannot listen\n");
    exit(1);
}
file_put_contents($dir . '/ready', '1');
while (true) {
    $conn = @stream_socket_accept($server, 60);
    if ($conn === false) {
        continue;
    }
    $say = static fn (string $line) => fwrite($conn, $line . "\r\n");
    $say('220 fake.test ESMTP');
    $user = '';
    $from = '';
    $to = [];
    while (($line = fgets($conn)) !== false) {
        $line = rtrim($line, "\r\n");
        $cmd = strtoupper(substr($line, 0, 4));
        if ($cmd === 'EHLO' || $cmd === 'HELO') {
            $say('250-fake.test');
            $say('250 AUTH PLAIN LOGIN');
        } elseif ($cmd === 'AUTH') {
            if (stripos($line, 'AUTH LOGIN') === 0) {
                $say('334 VXNlcm5hbWU6');
                $user = (string) base64_decode(trim((string) fgets($conn)));
                $say('334 UGFzc3dvcmQ6');
                fgets($conn);
            } else {
                $parts = explode("\0", (string) base64_decode(substr($line, 11)));
                $user = $parts[1] ?? '';
            }
            $say('235 ok');
        } elseif ($cmd === 'MAIL') {
            $from = $line;
            $say('250 ok');
        } elseif ($cmd === 'RCPT') {
            if (is_file($dir . '/reject.flag')) {
                $say('550 no such user');
            } else {
                $to[] = $line;
                $say('250 ok');
            }
        } elseif ($cmd === 'DATA') {
            $say('354 go on');
            $data = '';
            while (($l = fgets($conn)) !== false && rtrim($l, "\r\n") !== '.') {
                $data .= $l;
            }
            file_put_contents($dir . '/messages.log', json_encode(['user' => $user, 'from' => $from, 'to' => $to, 'data' => $data]) . "\n", FILE_APPEND);
            $say('250 queued');
        } elseif ($cmd === 'QUIT') {
            $say('221 bye');
            break;
        } else {
            $say('250 ok');
        }
    }
    fclose($conn);
}
