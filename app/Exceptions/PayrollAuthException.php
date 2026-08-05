<?php

namespace App\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * DMPI refused to issue a token.
 *
 * Exists so an auth failure is loud instead of silent: without it `login()`
 * returned an empty token, every later call came back with no usable body, and
 * the sync layer read that as "DMPI has no employees/devices" — indistinguishable
 * from a genuinely empty roster. The message carries DMPI's own wording (e.g.
 * "You've reached the maximum login attempt") so the cause is visible in Server
 * Activity without anyone having to reproduce the request by hand.
 */
class PayrollAuthException extends RuntimeException
{
    public static function fromResponse(Response $response): self
    {
        return new self('Payroll login failed (HTTP '.$response->status().'): '.self::reason($response));
    }

    /** Pull the most specific human-readable line DMPI offered, else a body excerpt. */
    private static function reason(Response $response): string
    {
        $body = $response->json();

        if (is_array($body)) {
            $parts = array_filter([
                $body['title'] ?? null,
                $body['message'] ?? null,
                $body['detail'] ?? null,
                is_string($body['non_field_errors'][0] ?? null) ? $body['non_field_errors'][0] : null,
            ], fn ($part) => is_string($part) && trim($part) !== '');

            if ($parts !== []) {
                return implode(' — ', array_unique($parts));
            }
        }

        $raw = trim($response->body());

        return $raw === ''
            ? 'no token in response and no error detail given.'
            : 'no token in response. Body: '.mb_strimwidth($raw, 0, 300, '…');
    }
}
