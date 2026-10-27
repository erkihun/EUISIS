<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Copies of the audit seals' heads kept off the database server (ADR 0012).
 *
 * Every seal (App\Services\AuditChain) is written to the disk named by
 * CORE_AUDIT_HEAD_DISK as heads/<to_sequence>.json, with heads/latest.json
 * naming the last one shipped. Someone who can rewrite the database, even
 * with the seal key, cannot rewrite these too; core:audit-verify compares
 * every seal with its copy and reports a changed, missing or removed seal.
 *
 * Shipping is idempotent and catches up from latest.json, so a failed run is
 * repaired by the next one. A failure is remembered for System Health until a
 * run succeeds.
 */
final class AuditHeads
{
    public const LATEST = 'heads/latest.json';

    /** A seal younger than this may not have been shipped yet; older ones must have a copy. */
    public const GRACE_MINUTES = 30;

    public function configured(): bool
    {
        return (string) config('core.audit_head_disk') !== '';
    }

    public static function path(int $toSequence): string
    {
        return sprintf('heads/%012d.json', $toSequence);
    }

    /** @return int seals shipped */
    public function ship(): int
    {
        if (! $this->configured()) {
            return 0;
        }
        try {
            $disk = $this->disk();
            $through = $this->latest($disk);
            $shipped = 0;
            while (($seals = DB::table('audit_seals')->where('to_sequence', '>', $through)->orderBy('to_sequence')->limit(500)->get())->isNotEmpty()) {
                foreach ($seals as $seal) {
                    $disk->put(self::path((int) $seal->to_sequence), json_encode($this->copy($seal), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                    $through = (int) $seal->to_sequence;
                    $shipped++;
                }
                $disk->put(self::LATEST, json_encode(['to_sequence' => $through, 'updated_at' => now()->toIso8601String()], JSON_THROW_ON_ERROR));
            }
        } catch (Throwable $e) {
            Cache::forever('core.audit_heads.failed_at', now()->toIso8601String());

            throw $e;
        }
        Cache::forever('core.audit_heads.shipped_through', $through);
        Cache::forget('core.audit_heads.failed_at');

        return $shipped;
    }

    /**
     * @return array{ok: bool, checked: int, first_bad_sequence: ?int, message: string}
     */
    public function verify(bool $restored = false): array
    {
        if (! $this->configured()) {
            return ['ok' => true, 'checked' => 0, 'first_bad_sequence' => null, 'message' => 'Seal heads are not shipped off the server (CORE_AUDIT_HEAD_DISK is not set).'];
        }
        $disk = $this->disk();
        $checked = 0;
        $last = 0;
        $grace = now()->subMinutes(self::GRACE_MINUTES);
        foreach (DB::table('audit_seals')->lazyById(500, 'to_sequence') as $seal) {
            $last = (int) $seal->to_sequence;
            $path = self::path($last);
            if (! $disk->exists($path)) {
                if (Carbon::parse($seal->created_at)->lessThan($grace)) {
                    return ['ok' => false, 'checked' => $checked, 'first_bad_sequence' => $last, 'message' => "The seal ending at audit row {$last} has no off-server copy."];
                }

                continue;
            }
            $copy = json_decode((string) $disk->get($path), true);
            $expected = $this->copy($seal);
            foreach (['seal_id', 'from_sequence', 'to_sequence', 'head_hash', 'signature', 'previous_seal_id'] as $field) {
                if (($copy[$field] ?? null) !== $expected[$field]) {
                    return ['ok' => false, 'checked' => $checked, 'first_bad_sequence' => $last, 'message' => "The seal ending at audit row {$last} differs from its off-server copy."];
                }
            }
            $checked++;
        }
        $shipped = $this->latest($disk);
        if ($shipped > $last && $restored) {
            // A restored copy ends where its backup was taken; the live trail was sealed and shipped on after that.
            return ['ok' => true, 'checked' => $checked, 'first_bad_sequence' => null,
                'message' => "All {$checked} off-server seal heads match; the store also holds seals up to audit row {$shipped}, made after this copy."];
        }
        if ($shipped > $last) {
            return ['ok' => false, 'checked' => $checked, 'first_bad_sequence' => $last + 1,
                'message' => "Seals up to audit row {$shipped} were shipped but the database ends at {$last}: sealed rows are missing (or this is a restore to an earlier point)."];
        }

        return ['ok' => true, 'checked' => $checked, 'first_bad_sequence' => null, 'message' => "All {$checked} off-server seal heads match."];
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('core.audit_head_disk'));
    }

    private function latest(Filesystem $disk): int
    {
        return $disk->exists(self::LATEST) ? (int) (json_decode((string) $disk->get(self::LATEST), true)['to_sequence'] ?? 0) : 0;
    }

    /** @return array<string, int|string|null> */
    private function copy(object $seal): array
    {
        return [
            'seal_id' => (string) $seal->id,
            'from_sequence' => (int) $seal->from_sequence,
            'to_sequence' => (int) $seal->to_sequence,
            'row_count' => (int) $seal->row_count,
            'head_hash' => (string) $seal->head_hash,
            'signature' => (string) $seal->signature,
            'previous_seal_id' => $seal->previous_seal_id === null ? null : (string) $seal->previous_seal_id,
            'sealed_at' => (string) $seal->created_at,
            'source' => (string) config('app.url'),
        ];
    }
}
