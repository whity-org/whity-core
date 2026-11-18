<?php

declare(strict_types=1);

/**
 * A local server for StreamHttpClientTest (WHIT-1058), run with `php -S`.
 *
 * /__whoami answers with FAKE_HTTP_ID, so the test knows the server on its
 * port is its own (WHIT-1161).
 */

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

if ($path === '/__whoami') {
    echo (string) getenv('FAKE_HTTP_ID');
    return;
}

if ($path === '/echo') {
    header('Content-Type: application/json');
    echo json_encode([
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'content_type' => $_SERVER['CONTENT_TYPE'] ?? '',
        'x_test' => $_SERVER['HTTP_X_TEST'] ?? '',
        'body' => (string) file_get_contents('php://input'),
    ]);
    return;
}

if ($path === '/headers') {
    http_response_code(429);
    header('Retry-After: 30');
    header('X-Twice: one', false);
    header('X-Twice: two', false);
    echo '{"error":"slow down"}';
    return;
}

if ($path === '/host') {
    echo (string) ($_SERVER['HTTP_HOST'] ?? '');
    return;
}

if ($path === '/redirect') {
    header('Location: /echo', true, 302);
    return;
}

if ($path === '/missing') {
    http_response_code(404);
    echo '{"error":"nope"}';
    return;
}

if ($path === '/big') {
    echo str_repeat('x', 4096);
    return;
}

if ($path === '/slow') {
    sleep(3);
    echo 'late';
    return;
}

http_response_code(500);
echo 'unknown path';
