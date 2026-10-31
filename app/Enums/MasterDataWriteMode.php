<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Who writes Golden Path master data (Core Stage 5, authoritative write mode),
 * read from `core.master_data_writes` (CORE_MASTER_DATA_WRITES):
 *
 *  - core:     this application is the authoritative writer (default; 'open');
 *  - legacy:   before cutover, this deployment is still the writer (writes allowed);
 *  - cutover:  the switch window: master data is read-only ('frozen');
 *  - rollback: returning to the previous writer: read-only until reconciled.
 *
 * Exactly one writer at a time: only core and legacy accept master-data
 * changes. An unknown value fails closed (read-only) so a typo never opens a
 * second writer; production:readiness reports it.
 */
enum MasterDataWriteMode: string
{
    case Core = 'core';
    case Legacy = 'legacy';
    case Cutover = 'cutover';
    case Rollback = 'rollback';

    /** Earlier spellings of the switch, kept so existing environments keep their meaning. */
    private const ALIASES = ['open' => 'core', 'frozen' => 'cutover'];

    public static function current(): self
    {
        return self::fromSetting((string) config('core.master_data_writes', 'core'));
    }

    public static function fromSetting(string $value): self
    {
        $value = strtolower(trim($value));

        return self::tryFrom(self::ALIASES[$value] ?? $value) ?? self::Cutover;
    }

    public static function isKnownSetting(string $value): bool
    {
        $value = strtolower(trim($value));

        return self::tryFrom(self::ALIASES[$value] ?? $value) !== null;
    }

    public function allowsWrites(): bool
    {
        return $this === self::Core || $this === self::Legacy;
    }
}
