<?php

declare(strict_types=1);

/**
 * A local stand-in for a provider's token endpoint (WHIT-901), run with `php -S`.
 *
 * It behaves as scenario.json in FAKE_OAUTH_DIR says, and logs every request to
 * requests.log so a test can count them and read what was sent.
 */

$dir = (string) getenv('FAKE_OAUTH_DIR');
$scenario = json_decode((string) file_get_contents($dir . '/scenario.json'), true);

parse_str((string) file_get_contents('php://input'), $form);
$log = $dir . '/requests.log';
file_put_contents(
    $log,
    json_encode(['path' => $_SERVER['REQUEST_URI'], 'form' => $form]) . "\n",
    FILE_APPEND | LOCK_EX,
);
$count = count((array) file($log));

if (($scenario['delay_ms'] ?? 0) > 0) {
    usleep((int) $scenario['delay_ms'] * 1000);
}

header('Content-Type: application/json');

$mode = (string) ($scenario['mode'] ?? 'ok');
if ($mode === 'server_error') {
    http_response_code(503);
    echo 'upstream is unhappy';
    return;
}
if ($mode !== 'ok') {
    http_response_code(400);
    echo json_encode([
        'error' => $mode,
        'error_description' => 'PROVIDER-WORDS-MUST-NOT-LEAK',
    ]);
    return;
}

if (($form['grant_type'] ?? '') !== 'refresh_token' || ($form['refresh_token'] ?? '') === '') {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_request']);
    return;
}

$response = ['access_token' => 'tok-' . $count, 'expires_in' => $scenario['expires_in'] ?? 3600, 'token_type' => 'Bearer'];
if (($scenario['rotate'] ?? false) === true) {
    $response['refresh_token'] = 'rotated-refresh-' . $count;
}
echo json_encode($response);
