<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * positions.organization_id had an index but no foreign key, so a position
 * could name an organization that does not exist (master backbone migration,
 * Stage 2). Organizations are only soft-deleted, so the key (no action on
 * delete) changes no application behaviour.
 *
 * Additive and safe to re-run. When orphaned rows exist the key is NOT added
 * and nothing is changed: the migration logs the count, `php artisan
 * backbone:audit-integrity` lists the finding, and after the rows are repaired
 * the key is added by rolling this one migration back and running it again
 * (`migrate:rollback --path=...` then `migrate --path=...`).
 * SQLite (the test database) cannot add a key to an existing table in place
 * and is skipped.
 */
return new class extends Migration
{
    private const NAME = 'positions_organization_id_foreign';

    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite' || $this->hasKey()) {
            return;
        }

        $orphans = DB::table('positions')
            ->whereNotNull('organization_id')
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('organizations')->whereColumn('organizations.id', 'positions.organization_id'))
            ->count();

        if ($orphans > 0) {
            $message = "positions.organization_id foreign key NOT added: {$orphans} position(s) name a missing organization. Run backbone:audit-integrity.";
            Log::warning($message);
            echo $message.PHP_EOL;

            return;
        }

        Schema::table('positions', function (Blueprint $table): void {
            $table->foreign('organization_id', self::NAME)->references('id')->on('organizations');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite' || ! $this->hasKey()) {
            return;
        }

        Schema::table('positions', function (Blueprint $table): void {
            $table->dropForeign(self::NAME);
        });
    }

    private function hasKey(): bool
    {
        return collect(Schema::getForeignKeys('positions'))->contains(fn (array $key): bool => $key['columns'] === ['organization_id']);
    }
};
