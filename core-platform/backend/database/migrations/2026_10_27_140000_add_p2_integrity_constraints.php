<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Database guarantees for P2 (ADR 0009), behind the actions' own checks:
 *  - one card request waiting for a decision per employee (a partial unique
 *    index; a generated column on MySQL/MariaDB);
 *  - an evidence version that is archived or replaced is never current.
 * Existing rows are checked first: the migration stops, naming what to fix,
 * rather than drop or change anything.
 */
return new class extends Migration
{
    private const PENDING = "'draft', 'submitted', 'verified'";

    public function up(): void
    {
        $duplicates = DB::table('card_requests')->whereIn('status', ['draft', 'submitted', 'verified'])
            ->groupBy('employee_id')->havingRaw('count(*) > 1')->pluck('employee_id');
        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException('Employees with more than one card request waiting: '.$duplicates->implode(', ')
                .'. Reject or cancel the extra requests, then run the migration again.');
        }
        if (DB::table('employee_documents')->where('is_current', true)->whereIn('status', ['archived', 'replaced'])->exists()) {
            throw new RuntimeException('Some archived or replaced evidence versions are still marked current; correct them first.');
        }

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('CREATE UNIQUE INDEX card_requests_one_pending_per_employee ON card_requests (employee_id) WHERE status IN ('.self::PENDING.')');
        } else {
            Schema::table('card_requests', function (Blueprint $table): void {
                $table->uuid('pending_employee_id')->nullable()->storedAs('case when status in ('.self::PENDING.') then employee_id end');
                $table->unique(['pending_employee_id'], 'card_requests_one_pending_per_employee');
            });
        }
        if (DB::getDriverName() !== 'sqlite') {
            $true = DB::getDriverName() === 'pgsql' ? 'true' : '1';
            DB::statement("ALTER TABLE employee_documents ADD CONSTRAINT employee_documents_withdrawn_not_current CHECK (NOT (is_current = {$true} AND status IN ('archived', 'replaced')))");
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS card_requests_one_pending_per_employee');
        } else {
            Schema::table('card_requests', function (Blueprint $table): void {
                $table->dropUnique('card_requests_one_pending_per_employee');
                $table->dropColumn('pending_employee_id');
            });
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE employee_documents DROP CONSTRAINT employee_documents_withdrawn_not_current');
        }
    }
};
