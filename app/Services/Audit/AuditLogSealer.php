<?php

declare(strict_types=1);

namespace App\Services\Audit;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\LazyCollection;

/**
 * Tamper evidence for the audit log. Once a day ends, every audit row of that
 * day is folded, in a fixed order, into a keyed SHA-256 (HMAC) that also
 * covers the previous day's seal. Changing, adding or removing a sealed row,
 * or editing a seal, shows up in `audit:verify`; recomputing a seal needs the
 * application key, which the database does not hold.
 *
 * Retention (`data:prune-retention`) removes seals together with the days it
 * prunes, so verification covers exactly the rows still kept.
 */
final class AuditLogSealer
{
    public const TABLE = 'audit_log_seals';

    /** Every audit column, in a fixed order. */
    private const COLUMNS = [
        'id', 'actor_user_id', 'actor_type', 'event_type', 'auditable_type', 'auditable_id', 'organization_id',
        'old_values', 'new_values', 'reason', 'request_id', 'request_ip', 'user_agent', 'created_at',
    ];

    public function available(): bool
    {
        return Schema::hasTable(self::TABLE);
    }

    /**
     * Seal each complete day from the first unsealed one through yesterday.
     *
     * @return list<string> the days sealed
     */
    public function sealPendingDays(?CarbonImmutable $through = null): array
    {
        $through = ($through ?? CarbonImmutable::yesterday())->startOfDay();
        $last = DB::table(self::TABLE)->orderByDesc('sealed_on')->first(['sealed_on', 'digest']);
        $day = $last !== null ? CarbonImmutable::parse($last->sealed_on)->addDay() : $this->firstAuditDay();
        $previous = $last?->digest;
        $sealed = [];

        while ($day !== null && $day->lte($through)) {
            [$count, $digest] = $this->digest($day, $previous, $this->keys()[0]);
            DB::table(self::TABLE)->insert([
                'sealed_on' => $day->toDateString(),
                'row_count' => $count,
                'digest' => $digest,
                'previous_digest' => $previous,
                'key_id' => $this->keyId($this->keys()[0]),
                'created_at' => now(),
            ]);
            $sealed[] = $day->toDateString();
            $previous = $digest;
            $day = $day->addDay();
        }

        return $sealed;
    }

    /**
     * Recompute every seal in the range and check the chain between them.
     *
     * @return list<array{day: string, rows: int, status: string}> status: ok, modified, chain_broken or unknown_key
     */
    public function verify(?string $from = null, ?string $to = null): array
    {
        $keys = collect($this->keys())->keyBy(fn (string $key): string => $this->keyId($key));
        $results = [];
        $before = null;

        $seals = DB::table(self::TABLE)
            ->when($from !== null, fn ($query) => $query->where('sealed_on', '>=', $from))
            ->when($to !== null, fn ($query) => $query->where('sealed_on', '<=', $to))
            ->orderBy('sealed_on')
            ->get();

        foreach ($seals as $seal) {
            $day = CarbonImmutable::parse($seal->sealed_on);
            $key = $keys->get($seal->key_id);
            $status = match (true) {
                $key === null => 'unknown_key',
                $before !== null && $before->digest !== $seal->previous_digest => 'chain_broken',
                default => $this->digest($day, $seal->previous_digest, $key) === [(int) $seal->row_count, $seal->digest] ? 'ok' : 'modified',
            };
            $results[] = ['day' => $day->toDateString(), 'rows' => (int) $seal->row_count, 'status' => $status];
            $before = $seal;
        }

        return $results;
    }

    public function lastSealedDay(): ?string
    {
        $day = DB::table(self::TABLE)->max('sealed_on');

        return $day === null ? null : CarbonImmutable::parse($day)->toDateString();
    }

    /** Drop the seals of days whose rows retention has started to remove. */
    public function forgetThrough(string $day): int
    {
        return DB::table(self::TABLE)->where('sealed_on', '<=', $day)->delete();
    }

    /** @return array{0: int, 1: string} row count and digest */
    private function digest(CarbonImmutable $day, ?string $previous, string $key): array
    {
        $hmac = hash_init('sha256', HASH_HMAC, $key);
        hash_update($hmac, ($previous ?? '')."\x1e".$day->toDateString()."\x1e");
        $count = 0;

        foreach ($this->rowsOn($day) as $row) {
            hash_update($hmac, implode("\x1f", array_map(fn (string $column): string => $row->{$column} === null ? "\0" : (string) $row->{$column}, self::COLUMNS))."\x1e");
            $count++;
        }

        return [$count, hash_final($hmac)];
    }

    /** @return LazyCollection<int, object> */
    private function rowsOn(CarbonImmutable $day): LazyCollection
    {
        return DB::table('audit_logs')
            ->select(self::COLUMNS)
            ->where('created_at', '>=', $day->startOfDay()->toDateTimeString())
            ->where('created_at', '<', $day->addDay()->startOfDay()->toDateTimeString())
            ->orderBy('created_at')
            ->orderBy('id')
            ->lazy(2000);
    }

    private function firstAuditDay(): ?CarbonImmutable
    {
        $first = DB::table('audit_logs')->min('created_at');

        return $first === null ? null : CarbonImmutable::parse($first)->startOfDay();
    }

    /**
     * Seal keys derived from the application key and its previous keys, so a
     * rotated APP_KEY still verifies older seals.
     *
     * @return non-empty-list<string>
     */
    private function keys(): array
    {
        $keys = array_values(array_filter([config('app.key'), ...(array) config('app.previous_keys', [])]));

        return array_map(function (string $appKey): string {
            $raw = str_starts_with($appKey, 'base64:') ? (string) base64_decode(substr($appKey, 7), true) : $appKey;

            return hash_hmac('sha256', 'audit-log-seal-v1', $raw, true);
        }, $keys === [] ? [''] : $keys);
    }

    private function keyId(string $key): string
    {
        return substr(hash('sha256', $key), 0, 16);
    }
}
