<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P3 (ADR 0010): where each placement came from.
 *
 *  - assignment_type (initial, transfer, promotion, demotion, reassignment,
 *    correction), reason_code, the supporting document, who created and who
 *    approved it, the system it came from with that system's reference, and
 *    the placement it followed.
 *  - is_primary: the Core keeps one current placement per employee, and it is
 *    the primary one. Secondary (acting, secondment) placements are not
 *    supported yet; the column is there so they can be added without
 *    rewriting history.
 *  - Existing rows: their type is read from the reason the earlier code wrote
 *    ('registered' → initial, 'transferred' → transfer, else unspecified); the
 *    source system is 'legacy' because it cannot be known; previous_assignment_id
 *    links each employee's placements in date order. Nothing is invented.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_assignments', function (Blueprint $table): void {
            $table->string('assignment_type', 24)->default('unspecified');
            $table->boolean('is_primary')->default(true);
            $table->string('reason_code', 32)->nullable();
            $table->uuid('source_document_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('source_system', 24)->default('core');
            $table->string('external_reference', 128)->nullable();
            $table->uuid('previous_assignment_id')->nullable();
            $table->index(['previous_assignment_id'], 'ea_previous_idx');
            $table->index(['source_system', 'external_reference'], 'ea_source_reference_idx');
        });

        DB::table('employee_assignments')->update(['source_system' => 'legacy']);
        DB::table('employee_assignments')->where('reason', 'registered')->update(['assignment_type' => 'initial']);
        DB::table('employee_assignments')->where('reason', 'transferred')->update(['assignment_type' => 'transfer']);

        $this->linkPlacements();

        Schema::table('employee_assignments', function (Blueprint $table): void {
            $table->foreign('source_document_id', 'ea_source_document_foreign')->references('id')->on('employee_documents')->restrictOnDelete();
            $table->foreign('previous_assignment_id', 'ea_previous_foreign')->references('id')->on('employee_assignments')->restrictOnDelete();
            $table->foreign('created_by', 'ea_created_by_foreign')->references('id')->on('users')->nullOnDelete();
            $table->foreign('approved_by', 'ea_approved_by_foreign')->references('id')->on('users')->nullOnDelete();
        });
    }

    /** Each employee's placements in date order: every one after the first follows the one before. */
    public function linkPlacements(): void
    {
        // One set-based update where the database allows it (the whole migration: 29 s on 300,000 placements, PostgreSQL).
        $ordered = 'select id, lag(id) over (partition by employee_id order by effective_from, created_at, id) as prev from employee_assignments';
        match (DB::getDriverName()) {
            'pgsql', 'sqlite' => DB::statement("update employee_assignments set previous_assignment_id = x.prev from ({$ordered}) x where x.id = employee_assignments.id and x.prev is not null"),
            'mysql', 'mariadb' => DB::statement("update employee_assignments a join ({$ordered}) x on x.id = a.id set a.previous_assignment_id = x.prev where x.prev is not null"),
            default => $this->linkOneByOne(),
        };
    }

    private function linkOneByOne(): void
    {
        $previous = null;
        DB::table('employee_assignments')->orderBy('employee_id')->orderBy('effective_from')->orderBy('created_at')->orderBy('id')
            ->select(['id', 'employee_id'])->lazy(1000)->each(function ($row) use (&$previous): void {
                if ($previous !== null && $previous->employee_id === $row->employee_id) {
                    DB::table('employee_assignments')->where('id', $row->id)->update(['previous_assignment_id' => $previous->id]);
                }
                $previous = $row;
            });
    }

    public function down(): void
    {
        Schema::table('employee_assignments', function (Blueprint $table): void {
            foreach (['ea_source_document_foreign', 'ea_previous_foreign', 'ea_created_by_foreign', 'ea_approved_by_foreign'] as $foreign) {
                $table->dropForeign($foreign);
            }
            $table->dropIndex('ea_previous_idx');
            $table->dropIndex('ea_source_reference_idx');
            $table->dropColumn(['assignment_type', 'is_primary', 'reason_code', 'source_document_id', 'created_by', 'approved_by', 'approved_at',
                'source_system', 'external_reference', 'previous_assignment_id']);
        });
    }
};
