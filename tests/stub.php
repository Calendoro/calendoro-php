<?php
// Router for `php -S`. Writes each request to the log named by STUB_LOG so the test can inspect it.
$path = $_SERVER['REQUEST_URI'];
$method = $_SERVER['REQUEST_METHOD'];
$log = getenv('STUB_LOG');
file_put_contents($log, json_encode(['method' => $method, 'path' => $path, 'auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? null, 'key' => $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null]) . "\n", FILE_APPEND | LOCK_EX);
header('Content-Type: application/json');
header('X-Request-Id: req_stub');
$fail = getenv('STUB_LOG') . '.fail';
$left = is_file($fail) ? (int) file_get_contents($fail) : 0;
function reply(int $status, string $body): never
{
    http_response_code($status);
    echo $body;
    exit;
}
if ($left > 0) {
    file_put_contents($fail, (string) ($left - 1));
    header('Retry-After: 0');
    reply(503, '{"error":{"code":"stub_failure","message":"try again"}}');
}
if (str_starts_with($path, '/slow')) {
    sleep(2);
}
if (str_starts_with($path, '/v1/webhooks')) {
    reply(200, str_contains($path, 'cursor=p2') ? '{"data":[{"id":"whk_2"}],"nextCursor":null}' : '{"data":[{"id":"whk_1"}],"nextCursor":"p2"}');
}
if ($path === '/v1/me') {
    reply(200, '{"organizationId":"org_1"}');
}
if ($path === '/v1/bookings' && $method === 'POST') {
    reply(201, '{"id":"bkg_1","status":"confirmed"}');
}
if (in_array($path, ['/v1/event-types', '/v1/auth/login'], true) && $method === 'POST') {
    reply(500, '{"error":{"code":"internal","message":"boom"}}');
}
reply(404, '{"error":{"code":"not_found","message":"nope","details":["x"]}}');
