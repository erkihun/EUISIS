<?php

declare(strict_types=1);

namespace App\Services\Transport;

use App\Models\SystemSetting;

/**
 * The transport switches an administrator sets on Transport › Settings,
 * read by the settings page and enforced by the scan service.
 *
 * - require_pass_for_scan: a boarding needs an active pass for the provider.
 * - allow_pay_as_you_go:   without a pass, the boarding is still accepted and
 *                          recorded as pay-as-you-go (billable) instead of
 *                          being refused.
 * - scan_nonce_required:   every scan request must carry a client nonce (the
 *                          replay guard); when off the server makes one.
 */
final class TransportSettings
{
    /** @var array<string, bool> */
    public const DEFAULTS = [
        'require_pass_for_scan' => true,
        'allow_pay_as_you_go' => false,
        'scan_nonce_required' => true,
    ];

    /** @var array<string, bool>|null */
    private ?array $resolved = null;

    /** @return array<string, bool> */
    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $rows = SystemSetting::query()
            ->where('group', 'transport')
            ->whereIn('key', array_keys(self::DEFAULTS))
            ->get()
            ->keyBy('key');

        return $this->resolved = collect(self::DEFAULTS)
            ->map(fn (bool $default, string $key): bool => (bool) ($rows->get($key)?->typedValue() ?? $default))
            ->all();
    }

    public function requirePass(): bool
    {
        return $this->all()['require_pass_for_scan'];
    }

    public function allowPayAsYouGo(): bool
    {
        return $this->all()['allow_pay_as_you_go'];
    }

    public function nonceRequired(): bool
    {
        return $this->all()['scan_nonce_required'];
    }

    public function forget(): void
    {
        $this->resolved = null;
    }
}
