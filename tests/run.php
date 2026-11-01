<?php
// Plain assertions, so the suite runs on a bare PHP with no Composer install. Exit code 1 on any failure.
declare(strict_types=1);

require __DIR__ . '/../src/Runtime.php';
require __DIR__ . '/../src/Webhook.php';
require __DIR__ . '/../src/Generated/Models.php';
require __DIR__ . '/../src/Generated/Resources.php';

use Calendoro\Calendoro;
use Calendoro\ConnectionException;
use Calendoro\NotFoundException;
use Calendoro\ServerException;
use Calendoro\TimeoutException;
use Calendoro\Webhook;
use Calendoro\WebhookSignatureException;

$log = tempnam(sys_get_temp_dir(), 'calendoro');
$failFile = "$log.fail";
$sock = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
fclose($sock);
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/stub.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, ['STUB_LOG' => $log, 'PHP_CLI_SERVER_WORKERS' => '2']);
register_shutdown_function(fn () => proc_terminate($server));
$url = "http://127.0.0.1:$port";
for ($i = 0; $i < 100 && !@fsockopen('127.0.0.1', $port); $i++) {
    usleep(50000);
}

$failures = 0;
function check(string $name, bool $ok): void
{
    global $failures;
    echo($ok ? 'ok   ' : 'FAIL ') . $name . "\n";
    $failures += $ok ? 0 : 1;
}
function seen(): array
{
    global $log;
    return array_map(fn ($l) => json_decode($l, true), file($log, FILE_IGNORE_NEW_LINES));
}
function reset_stub(int $failNext = 0): void
{
    global $log, $failFile;
    file_put_contents($log, '');
    file_put_contents($failFile, (string) $failNext);
}
function raises(string $class, callable $f): ?\Throwable
{
    try {
        $f();
    } catch (\Throwable $e) {
        return $e instanceof $class ? $e : null;
    }
    return null;
}

$client = new Calendoro(apiKey: 'cdo_key', baseUrl: $url);

reset_stub();
check('api key is bearer', $client->account->getApiIdentity()->organizationId === 'org_1' && seen()[0]['auth'] === 'Bearer cdo_key');
reset_stub();
(new Calendoro(oauthToken: 'cdoa_t', baseUrl: $url))->account->getApiIdentity();
check('oauth token is bearer', seen()[0]['auth'] === 'Bearer cdoa_t');

reset_stub(1);
$client->account->getApiIdentity();
check('retries a 503', count(seen()) === 2);
reset_stub(3);
check('gives up after max retries', raises(ServerException::class, fn () => $client->account->getApiIdentity()) !== null && count(seen()) === 3);

reset_stub(1);
$booking = $client->bookings->createBooking(['eventTypeId' => 'e']);
$s = seen();
check('idempotency key stable across retries', $booking->id === 'bkg_1' && count($s) === 2 && $s[0]['key'] !== null && $s[0]['key'] === $s[1]['key']);
reset_stub();
$client->bookings->createBooking(['eventTypeId' => 'e'], idempotencyKey: 'mine');
check('caller idempotency key', seen()[0]['key'] === 'mine');

reset_stub();
check('write without key not retried on 500', raises(ServerException::class, fn () => $client->authentication->login(['email' => 'a@example.com', 'password' => 'password'])) !== null && count(seen()) === 1 && seen()[0]['key'] === null);

reset_stub();
$ids = [];
foreach ($client->webhooks->listWebhooksIter() as $w) {
    $ids[] = $w->id;
}
check('iterator follows cursor', $ids === ['whk_1', 'whk_2'] && count(seen()) === 2);

reset_stub();
$e = raises(NotFoundException::class, fn () => $client->eventTypes->getEventType('a/b'));
check('typed error carries request id', $e !== null && $e->status === 404 && $e->errorCode === 'not_found' && $e->requestId === 'req_stub' && $e->details === ['x']);
check('path params are encoded', seen()[0]['path'] === '/v1/event-types/a%2Fb');

$fast = new Calendoro(apiKey: 'k', baseUrl: $url, timeout: 0.2, maxRetries: 0);
check('timeout', raises(TimeoutException::class, fn () => $fast->request('GET', '/slow')) !== null);
check('connection failure', raises(ConnectionException::class, fn () => (new Calendoro(apiKey: 'k', baseUrl: 'http://127.0.0.1:1', maxRetries: 0))->account->getApiIdentity()) !== null);

$vectors = json_decode(file_get_contents(__DIR__ . '/../fixtures/webhook-vectors.json'), true);
foreach ($vectors as $v) {
    check('webhook: ' . $v['name'], Webhook::verifySignature($v['header'], $v['secret'], $v['body'], $v['now'], $v['tolerance'] ?? 300) === $v['valid']);
}
$v = $vectors[0];
check('construct event', Webhook::constructEvent($v['body'], $v['header'], $v['secret'], $v['now'])['id'] === 'wev_1');
check('construct event refuses', raises(WebhookSignatureException::class, fn () => Webhook::constructEvent($v['body'], null, $v['secret'])) !== null);

@unlink($log);
@unlink($failFile);
exit($failures > 0 ? 1 : 0);
