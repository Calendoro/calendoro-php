# Calendoro for PHP

**Status: not yet published to Packagist.** The install command below works after the first release. Until then, use this folder directly.

Every method is generated from the Calendoro OpenAPI document. PHP 8.1+, needs only `ext-curl` and `ext-json`.

```
composer require calendoro/calendoro
```

```php
use Calendoro\Calendoro;

$client = new Calendoro(apiKey: 'cdo_...'); // or oauthToken: 'cdoa_...'

$me = $client->account->getApiIdentity();
echo $me->organizationId;

foreach ($client->webhooks->listWebhooksIter() as $hook) { // follows nextCursor
    echo $hook->url;
}

$booking = $client->bookings->createBooking(
    ['eventTypeId' => '...', 'startAt' => '2026-11-02T15:00:00Z', 'timeZone' => 'Europe/Berlin', 'invitee' => ['name' => 'Ivy', 'email' => 'ivy@example.com']],
    idempotencyKey: 'order-1234', // generated for you if omitted
);
```

Responses are typed classes in `Calendoro\Models`. Bodies may be a model or an array of wire names; use an array to send an explicit null.

**Retries**: `maxRetries:` (default 2), exponential backoff with full jitter, honoring `Retry-After`. GET, PUT, DELETE and calls with an idempotency key retry on network errors, 408, 429 and 5xx. Other writes retry only on 429, since a failed one may have taken effect.

**Timeouts**: `timeout:` seconds, on the client (default 30) or any call.

**Errors**: `ApiException` has `status`, `errorCode`, `getMessage()`, `details`, `requestId`. Subclasses: `ValidationException`, `AuthenticationException`, `PermissionDeniedException`, `NotFoundException`, `ConflictException`, `RateLimitException`, `ServerException`. Network failures throw `ConnectionException` or `TimeoutException`.

**Webhooks**: pass the raw request body.

```php
$event = \Calendoro\Webhook::constructEvent(file_get_contents('php://input'), $_SERVER['HTTP_CALENDORO_SIGNATURE'] ?? null, 'whsec_...');
```

Methods documented as needing a user session refuse API keys and OAuth tokens; use `sessionToken:`.
