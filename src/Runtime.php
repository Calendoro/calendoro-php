<?php

declare(strict_types=1);

namespace Calendoro;

class CalendoroException extends \RuntimeException
{
}

class ConnectionException extends CalendoroException
{
}

class TimeoutException extends ConnectionException
{
}

/** The API answered with an error. Quote $requestId to support. */
class ApiException extends CalendoroException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly mixed $details = null,
        public readonly ?string $requestId = null,
        public readonly ?float $retryAfter = null,
    ) {
        parent::__construct("{$status} {$errorCode}: {$message}" . ($requestId ? " (request {$requestId})" : ''));
    }
}

class ValidationException extends ApiException
{
}
class AuthenticationException extends ApiException
{
}
class PermissionDeniedException extends ApiException
{
}
class NotFoundException extends ApiException
{
}
class ConflictException extends ApiException
{
}
class RateLimitException extends ApiException
{
}
class ServerException extends ApiException
{
}

/** Typed request and response object. Null properties are not sent; to send an explicit null, pass an array as the body. */
abstract class Model
{
    public const FIELDS = [];

    public static function coerce(mixed $value, mixed $type): mixed
    {
        if ($value === null || $type === null) {
            return $value;
        }
        if (is_string($type)) {
            return is_array($value) ? $type::fromArray($value) : $value;
        }
        return is_array($value) ? array_map(fn ($v) => self::coerce($v, $type[1]), $value) : $value;
    }

    public static function fromArray(array $data): static
    {
        $m = new static();
        foreach (static::FIELDS as $prop => [$wire, $type]) {
            $m->{$prop} = self::coerce($data[$wire] ?? null, $type);
        }
        return $m;
    }

    public function toArray(): array
    {
        $out = [];
        foreach (static::FIELDS as $prop => [$wire]) {
            if ($this->{$prop} !== null) {
                $out[$wire] = self::dump($this->{$prop});
            }
        }
        return $out;
    }

    public static function dump(mixed $v): mixed
    {
        if ($v instanceof self) {
            return $v->toArray() ?: new \stdClass();
        }
        return is_array($v) ? array_map(self::dump(...), $v) : $v;
    }
}

abstract class BaseClient
{
    private const RETRYABLE = [408, 429, 500, 502, 503, 504];
    public const VERSION = '0.1.0';
    private ?string $token;
    private string $baseUrl;

    public function __construct(
        ?string $apiKey = null,
        ?string $oauthToken = null,
        ?string $sessionToken = null,
        string $baseUrl = 'https://api.calendoro.app',
        private float $timeout = 30.0,
        private int $maxRetries = 2,
    ) {
        $this->token = $apiKey ?? $oauthToken ?? $sessionToken;
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function request(string $method, string $path, array $pathParams = [], array $query = [], mixed $body = null, bool $idempotent = false, ?string $idempotencyKey = null, ?float $timeout = null, mixed $type = null): mixed
    {
        foreach ($pathParams as $k => $v) {
            $path = str_replace('{' . $k . '}', rawurlencode((string) $v), $path);
        }
        $qs = [];
        foreach ($query as $k => $v) {
            foreach (is_array($v) ? $v : [$v] as $item) {
                if ($item !== null) {
                    $qs[] = rawurlencode((string) $k) . '=' . rawurlencode(is_bool($item) ? ($item ? 'true' : 'false') : (string) $item);
                }
            }
        }
        $url = $this->baseUrl . $path . ($qs ? '?' . implode('&', $qs) : '');
        $headers = ['Accept: application/json', 'User-Agent: calendoro-php/' . self::VERSION];
        if ($this->token !== null) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }
        if ($idempotent) {
            $headers[] = 'Idempotency-Key: ' . ($idempotencyKey ?? bin2hex(random_bytes(16))); // one key for every retry of this call
        }
        $payload = null;
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $payload = $body === [] ? '{}' : json_encode(Model::dump($body), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        // A POST or PATCH without a key may already have taken effect, so only a 429, which the server refused up front, is safe to repeat.
        $safe = $idempotent || in_array($method, ['GET', 'PUT', 'DELETE'], true);
        for ($attempt = 0; ; $attempt++) {
            try {
                return Model::coerce($this->once($method, $url, $headers, $payload, $timeout ?? $this->timeout), $type);
            } catch (ApiException $e) {
                if ($attempt >= $this->maxRetries || !in_array($e->status, self::RETRYABLE, true) || (!$safe && $e->status !== 429)) {
                    throw $e;
                }
                $this->pause($e->retryAfter !== null ? min($e->retryAfter, 60.0) : $this->backoff($attempt));
            } catch (ConnectionException $e) {
                if ($attempt >= $this->maxRetries || !$safe) {
                    throw $e;
                }
                $this->pause($this->backoff($attempt));
            }
        }
    }

    public function paginate(string $method, string $path, array $pathParams = [], array $query = [], ?float $timeout = null, mixed $type = null): \Generator
    {
        while (true) {
            $page = $this->request($method, $path, pathParams: $pathParams, query: $query, timeout: $timeout);
            foreach ($page['data'] ?? [] as $item) {
                yield Model::coerce($item, $type);
            }
            if (empty($page['nextCursor'])) {
                return;
            }
            $query['cursor'] = $page['nextCursor'];
        }
    }

    /** Exponential backoff with full jitter. */
    private function backoff(int $attempt): float
    {
        return (mt_rand() / mt_getrandmax()) * min(8.0, 0.5 * (2 ** $attempt));
    }

    private function pause(float $seconds): void
    {
        usleep((int) ($seconds * 1000000));
    }

    private function once(string $method, string $url, array $headers, ?string $payload, float $timeout): mixed
    {
        $responseHeaders = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT_MS => (int) ($timeout * 1000),
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HEADERFUNCTION => function ($_, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            throw $errno === CURLE_OPERATION_TIMEDOUT ? new TimeoutException($error) : new ConnectionException($error);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $decoded = $raw === '' ? null : json_decode($raw, true);
        if ($status >= 400) {
            throw $this->apiError($status, $decoded, $responseHeaders);
        }
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
    }

    private function apiError(int $status, mixed $decoded, array $headers): ApiException
    {
        $err = is_array($decoded) && is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
        $class = $status >= 500 ? ServerException::class : ([
            400 => ValidationException::class,
            401 => AuthenticationException::class,
            403 => PermissionDeniedException::class,
            404 => NotFoundException::class,
            409 => ConflictException::class,
            429 => RateLimitException::class,
        ][$status] ?? ApiException::class);
        $retry = $headers['retry-after'] ?? null;
        return new $class($status, (string) ($err['code'] ?? 'http_error'), (string) ($err['message'] ?? "HTTP {$status}"), $err['details'] ?? null, $headers['x-request-id'] ?? ($err['requestId'] ?? null), is_numeric($retry) ? max(0.0, (float) $retry) : null);
    }
}
