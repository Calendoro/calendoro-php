<?php

declare(strict_types=1);

namespace Calendoro;

class WebhookSignatureException extends CalendoroException
{
}

/** Webhook signature verification, matching packages/webhooks. */
final class Webhook
{
    /** True when the Calendoro-Signature header signs the raw body with $secret recently enough. False, never an exception, on bad input. */
    public static function verifySignature(string|array|null $header, string $secret, string $body, ?int $now = null, int $tolerance = 300): bool
    {
        if ($header === null) {
            return false;
        }
        $text = is_array($header) ? implode(',', $header) : $header;
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $text) as $part) {
            $i = strpos($part, '=');
            if ($i === false) {
                continue;
            }
            $key = trim(substr($part, 0, $i));
            $value = trim(substr($part, $i + 1));
            if ($key === 't' && preg_match('/^[0-9]{1,18}$/D', $value) === 1) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }
        if ($timestamp === null || abs(($now ?? time()) - $timestamp) > $tolerance) {
            return false;
        }
        $expected = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, strtolower($signature))) {
                return true;
            }
        }
        return false;
    }

    /** Verifies a delivery and returns the parsed event. Throws WebhookSignatureException otherwise. Pass the raw request body. */
    public static function constructEvent(string $body, string|array|null $header, string $secret, ?int $now = null, int $tolerance = 300): array
    {
        if (!self::verifySignature($header, $secret, $body, $now, $tolerance)) {
            throw new WebhookSignatureException('invalid webhook signature');
        }
        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }
}
