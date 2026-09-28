<?php

// Loopback fixture: first event is available well before the response ends.
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) {
    fwrite(STDERR, $error);
    exit(1);
}
echo stream_socket_get_name($server, false)."\n";
flush();
$client = stream_socket_accept($server, 5);
if ($client === false) {
    exit(2);
}
$length = 0;
while (($line = fgets($client)) !== false && trim($line) !== '') {
    if (preg_match('/^Content-Length:\s*(\d+)/i', $line, $matches)) {
        $length = (int) $matches[1];
    }
}
while ($length > 0 && ($part = fread($client, $length)) !== false && $part !== '') {
    $length -= strlen($part);
}
fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: application/x-ndjson\r\nTransfer-Encoding: chunked\r\nConnection: keep-alive\r\n\r\n");
$first = json_encode(['type' => 'token', 'content' => 'first'])."\n";
fwrite($client, dechex(strlen($first))."\r\n".$first."\r\n");
fflush($client);
usleep(1500000);
$last = json_encode(['type' => 'token', 'content' => 'last'])."\n";
fwrite($client, dechex(strlen($last))."\r\n".$last."\r\n0\r\n\r\n");
fclose($client);
fclose($server);
