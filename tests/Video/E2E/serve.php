<?php

declare(strict_types=1);

/**
 * Router for `php -S` that serves the E2E sources the way object storage does.
 *
 * PHP's built-in server answers a Range header with the whole file, and an MP4
 * whose index sits at the end cannot be read without seeking, so this honours
 * single byte ranges with a 206. Every ranged answer is appended to the log
 * named by UTOPIA_SERVE_LOG, which is how a test proves seeking happened.
 */
$root = (string) \getenv('UTOPIA_SERVE_ROOT');
$log = (string) \getenv('UTOPIA_SERVE_LOG');
$uri = $_SERVER['REQUEST_URI'] ?? '';
$name = \basename((string) \parse_url(\is_string($uri) ? $uri : '', PHP_URL_PATH));
$path = $root.'/'.$name;

if ($name === '' || ! \is_file($path)) {
    \http_response_code(404);

    return true;
}

$size = (int) \filesize($path);
$start = 0;
$end = $size - 1;

\header('Accept-Ranges: bytes');
\header('Content-Type: application/octet-stream');

$range = $_SERVER['HTTP_RANGE'] ?? '';

if (\is_string($range) && \preg_match('/^bytes=(\d*)-(\d*)$/', $range, $match) === 1) {
    if ($match[1] === '') {
        $start = \max(0, $size - (int) $match[2]);
    } else {
        $start = (int) $match[1];
        $end = $match[2] === '' ? $end : \min($end, (int) $match[2]);
    }

    if ($start > $end) {
        \http_response_code(416);
        \header('Content-Range: bytes */'.$size);

        return true;
    }

    \http_response_code(206);
    \header('Content-Range: bytes '.$start.'-'.$end.'/'.$size);

    if ($log !== '') {
        \file_put_contents($log, $start.'-'.$end."\n", FILE_APPEND | LOCK_EX);
    }
}

\header('Content-Length: '.($end - $start + 1));

if ($_SERVER['REQUEST_METHOD'] === 'HEAD') {
    return true;
}

$handle = \fopen($path, 'rb');

if ($handle === false) {
    return true;
}

\fseek($handle, $start);
$left = $end - $start + 1;

while ($left > 0 && ! \feof($handle)) {
    $chunk = (string) \fread($handle, \min(65536, $left));
    $left -= \strlen($chunk);
    echo $chunk;
}

\fclose($handle);

return true;
