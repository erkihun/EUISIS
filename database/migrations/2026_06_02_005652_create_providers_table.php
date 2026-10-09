<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('providers')) {
            return;
        }

        Schema::create('providers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider_code')->unique();
            $table->foreignUuid('provider_type_id')->constrained('provider_types')->restrictOnDelete();
            $table->string('name_en');
            $table->string('name_am')->nullable();
            $table->foreignUuid('assigned_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('assigned_scope_type')->default('self');
            $table->string('contact_person')->nullable();
            $table->string('phone_number')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('status')->default('active');
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['provider_type_id', 'status']);
            $table->index('assigned_organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('providers');
    }
};
