<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfer_announcements', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->foreignUuid('position_id')->constrained('positions');

            $table->string('grade_level')->nullable();
            $table->decimal('salary_min', 12, 2)->nullable();
            $table->decimal('salary_max', 12, 2)->nullable();
            $table->unsignedSmallInteger('number_of_vacancies')->default(1);

            $table->json('eligibility_rules')->nullable();
            $table->json('required_documents')->nullable();

            $table->date('opening_date');
            $table->date('closing_date');

            $table->string('status', 20)->default('draft')->index();

            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status']);
            $table->index(['status', 'closing_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_announcements');
    }
};
