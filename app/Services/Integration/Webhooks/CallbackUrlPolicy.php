<?php

declare(strict_types=1);

namespace App\Services\Integration\Webhooks;

/**
 * SSRF guard for webhook callback URLs (docs/integration/webhook-security.md).
 *
 * Checked when a subscription is saved AND again before every delivery:
 *   - https only (plain http only with integration.webhooks.allow_http, for
 *     local development);
 *   - no credentials in the URL, no unusual ports below 1024 other than 443/80;
 *   - every address the host resolves to must be public: loopback, private,
 *     link-local (incl. 169.254.169.254 metadata), carrier-grade NAT,
 *     multicast, reserved, unspecified and their IPv6 equivalents are refused
 *     — unless the host is on integration.webhooks.allowed_internal_hosts.
 *
 * resolve() returns the vetted address so the HTTP client connects to exactly
 * that IP (CURLOPT_RESOLVE): a DNS answer that changes between check and
 * connect (rebinding) cannot redirect the request inward. Redirects are never
 * followed.
 */
final class CallbackUrlPolicy
{
    /** @return string|null error code, null when acceptable */
    public function check(string $url): ?string
    {
        return $this->resolve($url)['error'];
    }

    /**
     * @return array{error: string|null, host: string|null, port: int|null, ip: string|null}
     */
    public function resolve(string $url): array
    {
        $fail = fn (string $error): array => ['error' => $error, 'host' => null, 'port' => null, 'ip' => null];

        if (strlen($url) > 2048) {
            return $fail('url_too_long');
        }

        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return $fail('invalid_url');
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'https' && ! ($scheme === 'http' && config('integration.webhooks.allow_http'))) {
            return $fail('https_required');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return $fail('credentials_in_url');
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if ($port < 1 || $port > 65535 || ($port < 1024 && ! in_array($port, [80, 443], true))) {
            return $fail('port_not_allowed');
        }

        $internalAllowed = in_array($host, array_map('strtolower', (array) config('integration.webhooks.allowed_internal_hosts', [])), true);

        if (in_array($host, ['localhost', 'localhost.localdomain'], true) || str_ends_with($host, '.localhost') || str_ends_with($host, '.internal') || str_ends_with($host, '.local')) {
            return $internalAllowed ? ['error' => null, 'host' => $host, 'port' => $port, 'ip' => null] : $fail('destination_not_allowed');
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->lookup($host);
        if ($addresses === []) {
            return $fail('unresolvable_host');
        }

        foreach ($addresses as $ip) {
            if (! $internalAllowed && ! self::isPublic($ip)) {
                return $fail('destination_not_allowed');
            }
        }

        return ['error' => null, 'host' => $host, 'port' => $port, 'ip' => $addresses[0]];
    }

    public static function isPublic(string $ip): bool
    {
        // IPv4-mapped IPv6 (::ffff:127.0.0.1) is judged as the IPv4 it carries.
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m) === 1) {
            $ip = $m[1];
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($ip);
            foreach ([['100.64.0.0', 10], ['169.254.0.0', 16], ['192.0.0.0', 24], ['192.0.2.0', 24], ['198.18.0.0', 15], ['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4], ['0.0.0.0', 8], ['127.0.0.0', 8]] as [$net, $bits]) {
                $mask = -1 << (32 - $bits);
                if (($long & $mask) === (ip2long($net) & $mask)) {
                    return false;
                }
            }

            return true;
        }

        $lower = strtolower($ip);

        return ! ($lower === '::' || $lower === '::1'
            || str_starts_with($lower, 'fe8') || str_starts_with($lower, 'fe9') || str_starts_with($lower, 'fea') || str_starts_with($lower, 'feb')
            || str_starts_with($lower, 'fc') || str_starts_with($lower, 'fd') || str_starts_with($lower, 'ff')
            || str_starts_with($lower, '2001:db8') || str_starts_with($lower, '64:ff9b'));
    }

    /** @return list<string> */
    protected function lookup(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        $ips = [];
        foreach ($records as $record) {
            if (isset($record['ip'])) {
                $ips[] = $record['ip'];
            } elseif (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        if ($ips === []) {
            $v4 = @gethostbynamel($host);
            $ips = is_array($v4) ? $v4 : [];
        }

        return array_values(array_unique($ips));
    }
}
