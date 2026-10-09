<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_unit_types', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('name_en');
            $table->string('name_am')->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_am')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('organization_units', function (Blueprint $table): void {
            $table->uuid('organization_unit_type_id')->nullable()->after('unit_type');
            $table->foreign('organization_unit_type_id')
                ->references('id')
                ->on('organization_unit_types')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('organization_units', function (Blueprint $table): void {
            $table->dropForeign(['organization_unit_type_id']);
            $table->dropColumn('organization_unit_type_id');
        });

        Schema::dropIfExists('organization_unit_types');
    }
};
