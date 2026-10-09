<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Align external_applications.id with personal_access_tokens.tokenable_id.
 *
 * tokenable_id must stay VARCHAR because it holds both bigint user ids and
 * UUID application ids. PostgreSQL refuses `uuid = character varying` without
 * an explicit cast, so the polymorphic withCount()/tokens() join fails with
 * "operator does not exist" whenever an ExternalApplication is involved.
 *
 * Converting this one primary key to VARCHAR makes the comparison valid.
 * Values are unchanged — a uuid casts to its canonical text form — and the
 * api_request_logs foreign key is converted alongside it so the relationship
 * keeps its type agreement.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            // SQLite/MySQL already store these as text-compatible types.
            return;
        }

        // Drop the FK first: its column type changes with the parent key.
        DB::statement('ALTER TABLE api_request_logs DROP CONSTRAINT IF EXISTS api_request_logs_external_application_id_foreign');

        DB::statement('ALTER TABLE external_applications ALTER COLUMN id TYPE VARCHAR(36) USING id::VARCHAR');
        DB::statement('ALTER TABLE api_request_logs ALTER COLUMN external_application_id TYPE VARCHAR(36) USING external_application_id::VARCHAR');

        DB::statement(
            'ALTER TABLE api_request_logs
             ADD CONSTRAINT api_request_logs_external_application_id_foreign
             FOREIGN KEY (external_application_id) REFERENCES external_applications(id) ON DELETE SET NULL'
        );
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE api_request_logs DROP CONSTRAINT IF EXISTS api_request_logs_external_application_id_foreign');
        DB::statement('ALTER TABLE api_request_logs ALTER COLUMN external_application_id TYPE UUID USING external_application_id::UUID');
        DB::statement('ALTER TABLE external_applications ALTER COLUMN id TYPE UUID USING id::UUID');

        DB::statement(
            'ALTER TABLE api_request_logs
             ADD CONSTRAINT api_request_logs_external_application_id_foreign
             FOREIGN KEY (external_application_id) REFERENCES external_applications(id) ON DELETE SET NULL'
        );
    }
};
