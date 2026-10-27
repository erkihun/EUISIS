<?php

declare(strict_types=1);

/*
 * The load balancers or TLS terminators in front of the Core (ADR 0012), read by
 * Laravel's TrustProxies middleware: their X-Forwarded-For/-Proto/-Host/-Port
 * are believed, so client addresses (sign-in throttling, audit) and HTTPS
 * (HSTS, generated links) are right. Comma-separated addresses or ranges, or
 * "*" for the calling address. Empty = no proxy is trusted.
 */
return [
    'proxies' => env('TRUSTED_PROXIES') ?: null,
];
