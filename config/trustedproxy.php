<?php

declare(strict_types=1);

/*
 * Reverse proxies / load balancers allowed to set X-Forwarded-For, -Proto,
 * -Host and -Port. Read by Laravel's TrustProxies middleware on every request.
 *
 * Behind a TLS-terminating proxy this must list the proxy, or every visitor
 * appears to come from the proxy's address: per-IP login lockouts and rate
 * limits then apply to all users at once, audit logs record the proxy's IP,
 * and HTTPS-only behaviour (HSTS, secure URLs) is skipped.
 *
 * APP_TRUSTED_PROXIES: comma-separated IPs or CIDR ranges ("10.0.0.10,10.0.1.0/24"),
 * or "*" to trust the immediate peer. Unset trusts no proxy, so a server that
 * faces the internet directly cannot be given a forged client IP.
 */
$proxies = trim((string) env('APP_TRUSTED_PROXIES', ''));

return [
    'proxies' => match (true) {
        $proxies === '' => null,
        $proxies === '*' => '*',
        default => array_values(array_filter(array_map('trim', explode(',', $proxies)))),
    },
];
