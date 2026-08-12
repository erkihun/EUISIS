<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * audit_logs.auditable_id is polymorphic: it stores UUID keys for domain
 * entities and bigint keys for users (e.g. mfa.challenge_succeeded audits the
 * User itself). A native Postgres uuid column rejects "1", which crashed every
 * audit write whose subject is a User (500 on /mfa/challenge). On MySQL the
 * column was char(36), so mixed key types worked silently — varchar restores
 * that behaviour on every driver, and also makes the audit-log ILIKE search
 * valid on Postgres (ilike on uuid is a type error).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            // uuid -> varchar is an explicit cast, so ALTER TYPE needs USING.
            DB::statement('ALTER TABLE audit_logs ALTER COLUMN auditable_id TYPE varchar(36) USING auditable_id::text');

            return;
        }

        Schema::table('audit_logs', function ($table): void {
            $table->string('auditable_id', 36)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            // Only safe while every stored value is a valid uuid; user-key
            // rows written after the fix would block this cast, so clear the
            // non-uuid rows' ids first.
            DB::statement("UPDATE audit_logs SET auditable_id = NULL WHERE auditable_id IS NOT NULL AND auditable_id !~ '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$'");
            DB::statement('ALTER TABLE audit_logs ALTER COLUMN auditable_id TYPE uuid USING auditable_id::uuid');

            return;
        }

        Schema::table('audit_logs', function ($table): void {
            $table->uuid('auditable_id')->nullable()->change();
        });
    }
};
