<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institution_offices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('geographic_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->uuid('parent_office_id')->nullable()->index();
            $table->string('office_level')->index();
            $table->string('office_code', 50)->unique();
            $table->string('name_en')->nullable();
            $table->string('name_am')->nullable();
            $table->string('short_name_en')->nullable();
            $table->string('short_name_am')->nullable();
            $table->string('assigned_scope_type')->default('self');
            $table->boolean('is_head_office')->default(false);
            $table->string('status')->default('active')->index();
            $table->date('opened_on')->nullable();
            $table->date('closed_on')->nullable();
            $table->string('address_en')->nullable();
            $table->string('address_am')->nullable();
            $table->string('phone_number')->nullable();
            $table->string('email')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('institution_id');
            $table->index(['institution_id', 'office_level']);
            $table->index(['institution_id', 'status']);
        });

        Schema::table('institution_offices', function (Blueprint $table): void {
            $table->foreign('parent_office_id')
                ->references('id')
                ->on('institution_offices')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('institution_offices');
    }
};
