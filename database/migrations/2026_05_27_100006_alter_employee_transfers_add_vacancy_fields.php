<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_transfers', function (Blueprint $table): void {
            $table->string('transfer_source')->default('direct')->after('status');
            $table->foreignUuid('vacancy_application_id')->nullable()->after('transfer_source')->constrained('vacancy_applications');
            $table->foreignUuid('vacancy_announcement_id')->nullable()->after('vacancy_application_id')->constrained('vacancy_announcements');

            $table->index('transfer_source');
        });
    }

    public function down(): void
    {
        Schema::table('employee_transfers', function (Blueprint $table): void {
            $table->dropIndex(['transfer_source']);
            $table->dropConstrainedForeignUuid('vacancy_announcement_id');
            $table->dropConstrainedForeignUuid('vacancy_application_id');
            $table->dropColumn('transfer_source');
        });
    }
};
