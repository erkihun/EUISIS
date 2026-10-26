<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Tamper evidence for the audit trail (ADR 0010).
 *
 * Sealing gives every unsealed row, oldest first, the next sequence number,
 * the SHA-256 of its stored content and a chain hash (SHA-256 of the previous
 * chain hash and its own), then signs the range with the seal key (HMAC). The
 * database refuses any later change to a sealed row (append-only trigger).
 * Each seal's head is also written to the application log, so a copy of the
 * chain's state lives outside the database.
 *
 * Verifying recomputes every sealed row's content hash and the chain in
 * sequence order, checks the sequence has no gap and every seal's head and
 * signature, and reports the first sequence that does not match.
 */
final class AuditChain
{
    /** The stored columns a row's hash covers, in a fixed order. */
    public const CONTENT = ['id', 'actor_user_id', 'action', 'subject_type', 'subject_id', 'organization_id', 'old_values', 'new_values', 'reason',
        'request_id', 'request_ip', 'user_agent', 'source', 'api_client_id', 'break_glass_grant_id', 'created_at'];

    private const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /** @return int rows sealed */
    public function seal(int $limit = 5000): int
    {
        return DB::transaction(function () use ($limit): int {
            $last = DB::table('audit_seals')->orderByDesc('to_sequence')->lockForUpdate()->first();
            $sequence = (int) ($last->to_sequence ?? 0);
            $chain = $sequence === 0 ? self::GENESIS : (string) DB::table('core_audit_logs')->where('sequence', $sequence)->value('chain_hash');
            $rows = DB::table('core_audit_logs')->whereNull('sequence')->orderBy('created_at')->orderBy('id')->limit($limit)->lockForUpdate()->get(self::CONTENT);
            if ($rows->isEmpty()) {
                return 0;
            }
            $sealId = (string) Str::uuid7();
            $from = $sequence + 1;
            foreach ($rows as $row) {
                $sequence++;
                $hash = $this->recordHash($row);
                $chain = hash('sha256', $chain.$hash);
                DB::table('core_audit_logs')->where('id', $row->id)->whereNull('sequence')
                    ->update(['sequence' => $sequence, 'record_hash' => $hash, 'chain_hash' => $chain, 'audit_seal_id' => $sealId]);
            }
            DB::table('audit_seals')->insert([
                'id' => $sealId, 'from_sequence' => $from, 'to_sequence' => $sequence, 'row_count' => $rows->count(), 'head_hash' => $chain,
                'signature' => $this->sign($from, $sequence, $chain, $last->head_hash ?? self::GENESIS), 'previous_seal_id' => $last->id ?? null, 'created_at' => now(),
            ]);
            Log::info('Audit trail sealed.', ['seal_id' => $sealId, 'from_sequence' => $from, 'to_sequence' => $sequence, 'head_hash' => $chain]);

            return $rows->count();
        });
    }

    /**
     * @return array{ok: bool, checked: int, first_bad_sequence: ?int, message: string}
     */
    public function verify(): array
    {
        $chain = self::GENESIS;
        $expected = 1;
        $checked = 0;
        $fail = fn (int $sequence, string $message): array => ['ok' => false, 'checked' => $checked, 'first_bad_sequence' => $sequence, 'message' => $message];

        $seals = DB::table('audit_seals')->orderBy('to_sequence')->get()->keyBy('to_sequence');
        $previousHead = self::GENESIS;
        $result = null;
        DB::table('core_audit_logs')->whereNotNull('sequence')->select([...self::CONTENT, 'sequence', 'record_hash', 'chain_hash'])
            ->lazyById(1000, 'sequence')->each(function ($row) use (&$chain, &$expected, &$checked, &$previousHead, &$result, $seals, $fail): bool {
                if ((int) $row->sequence !== $expected) {
                    $result = $fail($expected, "Sequence {$expected} is missing: an audit row was removed.");

                    return false;
                }
                $hash = $this->recordHash($row);
                if (! hash_equals((string) $row->record_hash, $hash)) {
                    $result = $fail($expected, "Audit row {$expected} was changed after it was sealed.");

                    return false;
                }
                $chain = hash('sha256', $chain.$hash);
                if (! hash_equals((string) $row->chain_hash, $chain)) {
                    $result = $fail($expected, "The chain breaks at audit row {$expected}.");

                    return false;
                }
                if (($seal = $seals->get($expected)) !== null) {
                    if (! hash_equals((string) $seal->head_hash, $chain)
                        || ! hash_equals((string) $seal->signature, $this->sign((int) $seal->from_sequence, (int) $seal->to_sequence, $chain, $previousHead))) {
                        $result = $fail($expected, "The seal ending at audit row {$expected} does not match.");

                        return false;
                    }
                    $previousHead = $chain;
                }
                $checked++;
                $expected++;

                return true;
            });
        if ($result !== null) {
            return $result;
        }
        $lastSeal = $seals->last();
        if ($lastSeal !== null && (int) $lastSeal->to_sequence !== $expected - 1) {
            return $fail($expected, 'Sealed audit rows are missing at the end of the trail.');
        }

        return ['ok' => true, 'checked' => $checked, 'first_bad_sequence' => null, 'message' => "All {$checked} sealed audit rows match."];
    }

    /** @return array{sealed_through: int, last_sealed_at: ?string, unsealed: int, last_verification: ?object} */
    public function status(): array
    {
        $last = DB::table('audit_seals')->orderByDesc('to_sequence')->first();

        return [
            'sealed_through' => (int) ($last->to_sequence ?? 0),
            'last_sealed_at' => $last?->created_at,
            'unsealed' => DB::table('core_audit_logs')->whereNull('sequence')->count(),
            'last_verification' => DB::table('audit_verifications')->orderByDesc('id')->first(),
        ];
    }

    private function recordHash(object $row): string
    {
        $values = [];
        foreach (self::CONTENT as $column) {
            $value = $row->{$column} ?? null;
            $values[$column] = $value === null ? null : (string) $value;
        }

        return hash('sha256', json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function sign(int $from, int $to, string $head, string $previousHead): string
    {
        $key = (string) config('core.audit_seal_key');
        $key = $key !== '' ? $key : hash('sha256', 'core-audit-seal|'.config('app.key'));

        return hash_hmac('sha256', "{$from}|{$to}|{$head}|{$previousHead}", $key);
    }
}
