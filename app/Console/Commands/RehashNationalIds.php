<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\User;
use App\Support\Security\NationalIdHash;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Rewrites every national_id_hash from the decrypted national ID with the
 * current key (decision D-1). Idempotent and resumable: rows already holding
 * the right hash are skipped. Run after a deploy that introduces the keyed
 * hash (the 2026_10_11_100200 migration does) and after any change of
 * APP_KEY or NATIONAL_ID_HASH_KEY.
 */
class RehashNationalIds extends Command
{
    protected $signature = 'security:rehash-national-ids {--chunk=500 : Rows per batch}';

    protected $description = 'Recompute national-ID lookup hashes with the current key';

    public function handle(): int
    {
        $chunk = max(1, (int) $this->option('chunk'));

        foreach ([Employee::class => 'employees', User::class => 'users'] as $model => $table) {
            $updated = 0;
            $unreadable = 0;

            $model::query()->whereNotNull('national_id')->select(['id', 'national_id', 'national_id_hash'])
                ->chunkById($chunk, function ($rows) use ($table, &$updated, &$unreadable): void {
                    foreach ($rows as $row) {
                        try {
                            $plain = $row->national_id;
                        } catch (Throwable) {
                            // Stored before encryption: data:encrypt-existing hashes it.
                            $unreadable++;

                            continue;
                        }

                        $hash = NationalIdHash::of($plain === null ? null : (string) $plain);
                        if ($hash !== $row->national_id_hash) {
                            DB::table($table)->where('id', $row->id)->update(['national_id_hash' => $hash]);
                            $updated++;
                        }
                    }
                });

            $this->line("{$table}: {$updated} re-hashed".($unreadable ? ", {$unreadable} unreadable (not encrypted yet)" : ''));
        }

        return self::SUCCESS;
    }
}
