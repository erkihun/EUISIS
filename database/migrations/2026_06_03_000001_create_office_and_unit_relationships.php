<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('institution_offices', function (Blueprint $table): void {
            if (! Schema::hasColumn('institution_offices', 'structural_organization_id')) {
                $table->foreignUuid('structural_organization_id')
                    ->nullable()
                    ->after('institution_id')
                    ->constrained('organizations')
                    ->nullOnDelete();
                $table->index('structural_organization_id');
            }
        });

        Schema::create('institution_office_relationships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('source_office_id')->constrained('institution_offices')->cascadeOnDelete();
            $table->string('target_type')->index();
            $table->uuid('target_id');
            $table->string('relationship_type')->index();
            $table->boolean('is_primary')->default(false)->index();
            $table->date('effective_from')->nullable()->index();
            $table->date('effective_to')->nullable()->index();
            $table->string('status')->default('active')->index();
            $table->text('notes_en')->nullable();
            $table->text('notes_am')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['target_type', 'target_id']);
            $table->index(['source_office_id', 'relationship_type', 'status'], 'io_rel_source_type_status_idx');
            $table->index(['effective_from', 'effective_to'], 'io_rel_effective_idx');
        });

        Schema::create('organization_unit_relationships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('source_unit_id')->constrained('organization_units')->cascadeOnDelete();
            $table->string('target_type')->index();
            $table->uuid('target_id');
            $table->string('relationship_type')->index();
            $table->boolean('is_primary')->default(false)->index();
            $table->date('effective_from')->nullable()->index();
            $table->date('effective_to')->nullable()->index();
            $table->string('status')->default('active')->index();
            $table->text('notes_en')->nullable();
            $table->text('notes_am')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['target_type', 'target_id']);
            $table->index(['source_unit_id', 'relationship_type', 'status'], 'ou_rel_source_type_status_idx');
            $table->index(['effective_from', 'effective_to'], 'ou_rel_effective_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_unit_relationships');
        Schema::dropIfExists('institution_office_relationships');

        Schema::table('institution_offices', function (Blueprint $table): void {
            if (Schema::hasColumn('institution_offices', 'structural_organization_id')) {
                $table->dropForeign(['structural_organization_id']);
                $table->dropIndex(['structural_organization_id']);
                $table->dropColumn('structural_organization_id');
            }
        });
    }
};
