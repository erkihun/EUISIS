<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('field_work_requests', function (Blueprint $table): void {
            $table->foreignId('supervisor_user_id')->nullable()->after('submitted_at')->constrained('users')->nullOnDelete();
            $table->foreignUuid('supervisor_employee_id')->nullable()->after('supervisor_user_id')->constrained('employees')->nullOnDelete();
            $table->string('supervisor_name_snapshot', 255)->nullable()->after('supervisor_employee_id');
            $table->string('supervisor_employee_number_snapshot', 64)->nullable()->after('supervisor_name_snapshot');
            $table->index(['status', 'supervisor_user_id'], 'fwr_status_supervisor_idx');
        });
    }

    public function down(): void
    {
        Schema::table('field_work_requests', function (Blueprint $table): void {
            $table->dropIndex('fwr_status_supervisor_idx');
            $table->dropConstrainedForeignId('supervisor_employee_id');
            $table->dropConstrainedForeignId('supervisor_user_id');
            $table->dropColumn(['supervisor_name_snapshot', 'supervisor_employee_number_snapshot']);
        });
    }
};
