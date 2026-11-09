<?php

declare(strict_types=1);

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Investigation subjects of a public complaint (brief §61–62) and controlled
 * referral requests to the discipline domain (§59–60). A subject never
 * changes the complaint's target; a referral is a request record that the
 * discipline module may later link to a case it creates — nothing here
 * creates one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_feedback_subject_employees', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('submission_id')->constrained('employee_service_feedback')->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignUuid('employee_assignment_id')->nullable()->constrained('employee_assignments')->nullOnDelete();
            $table->string('subject_role', 120)->nullable();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('added_at');

            $table->unique(['submission_id', 'employee_id'], 'pfse_submission_employee_unique');
            $table->index(['employee_id'], 'pfse_employee_idx');
        });

        Schema::create('public_feedback_referrals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('submission_id')->constrained('employee_service_feedback')->cascadeOnDelete();
            $table->string('kind', 32)->default('discipline');
            $table->string('status', 32)->default('requested');
            $table->text('notes')->nullable();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('requested_at');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_notes')->nullable();
            // Set by the discipline module when it opens a case from an accepted referral; never here.
            $table->uuid('disciplinary_case_id')->nullable();
            $table->timestamps();

            $table->index(['submission_id', 'status'], 'pfr_submission_status_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE public_feedback_referrals ADD CONSTRAINT pfr_kind_chk CHECK (kind IN ('discipline'))");
            DB::statement("ALTER TABLE public_feedback_referrals ADD CONSTRAINT pfr_status_chk CHECK (status IN ('requested', 'accepted', 'declined', 'withdrawn'))");
        }

        if (DB::pretending()) {
            return;
        }

        $catalog = require database_path('seeders/data/permissions.php');

        foreach ($catalog as $entry) {
            if ($entry['name'] === 'service_feedback.refer_discipline') {
                Permission::firstOrCreate(['name' => $entry['name'], 'guard_name' => 'web'], $entry);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('public_feedback_referrals');
        Schema::dropIfExists('public_feedback_subject_employees');
    }
};
