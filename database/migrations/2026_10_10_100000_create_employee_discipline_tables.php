<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Employee Discipline (Regulation 201/2018 Part Two and Three) as its own
 * domain, plus the shared statutory-deadline snapshot table and committee
 * seat metadata (Arts. 5, 7 and 30). Additive: no grievance or public
 * feedback table changes meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statutory_deadlines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('subject_type');
            $table->uuid('subject_id');
            $table->string('rule_key', 60);
            $table->string('legal_reference', 80);
            $table->string('basis', 20);
            $table->unsignedSmallInteger('amount');
            $table->timestamp('started_at');
            $table->timestamp('due_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['rule_key', 'due_at']);
        });

        Schema::table('grievance_committee_members', function (Blueprint $table): void {
            // appointed (by the head) | elected (by employees) | hr_representative
            $table->string('selection_source', 30)->nullable()->after('role');
            $table->unsignedTinyInteger('term_number')->default(1)->after('selection_source');
        });

        Schema::create('disciplinary_cases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('case_number', 60)->nullable()->unique();
            $table->foreignUuid('accused_employee_id')->constrained('employees')->restrictOnDelete();
            // Submission-time placement; a later transfer never rewrites it.
            $table->foreignUuid('accused_assignment_id')->nullable()->constrained('employee_assignments')->nullOnDelete();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUuid('organization_unit_id')->nullable()->constrained('organization_units')->nullOnDelete();
            $table->foreignUuid('position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->string('track', 20);
            $table->string('severity', 20);
            $table->string('status', 30)->default('draft');
            $table->foreignUuid('committee_id')->nullable()->constrained('grievance_committees')->nullOnDelete();
            $table->text('summary')->nullable();
            // Optional origin: a reviewed public complaint or a grievance referral. Never converted in place.
            $table->string('source_type', 40)->nullable();
            $table->uuid('source_id')->nullable();
            $table->foreignId('charge_signed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('charged_at')->nullable();
            $table->timestamp('summons_served_at')->nullable();
            $table->string('summons_method', 30)->nullable();
            $table->timestamp('hearing_at')->nullable();
            $table->string('hearing_location')->nullable();
            $table->boolean('in_absentia')->default(false);
            $table->boolean('additional_evidence_required')->default(false);
            $table->text('final_statement')->nullable();
            $table->timestamp('final_statement_at')->nullable();
            $table->boolean('final_statement_declined')->default(false);
            $table->timestamp('report_submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('discontinued_at')->nullable();
            $table->text('discontinuance_reason')->nullable();
            $table->timestamp('terminated_at')->nullable();
            $table->text('termination_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['accused_employee_id', 'status']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('disciplinary_case_members', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('case_id')->constrained('disciplinary_cases')->cascadeOnDelete();
            $table->foreignUuid('committee_member_id')->nullable()->constrained('grievance_committee_members')->nullOnDelete();
            $table->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $table->string('role', 20);
            $table->string('selection_source', 30)->nullable();
            $table->boolean('is_active')->default(true);
            // Art. 9(1)-(2): case-only removal for conflict, with a case-only replacement.
            $table->timestamp('recused_at')->nullable();
            $table->text('recusal_reason')->nullable();
            $table->foreignId('recusal_decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('replaces_member_id')->nullable();
            $table->timestamps();

            $table->index(['case_id', 'is_active']);
            $table->index(['employee_id']);
        });

        Schema::table('disciplinary_case_members', function (Blueprint $table): void {
            $table->foreign('replaces_member_id')->references('id')->on('disciplinary_case_members')->nullOnDelete();
        });

        Schema::create('disciplinary_charges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('case_id')->constrained('disciplinary_cases')->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->string('title');
            $table->text('particulars');
            $table->date('occurred_on');
            $table->string('occurred_place');
            $table->string('violated_provision');
            $table->text('evidence_list');
            $table->string('plea', 20)->nullable();
            $table->text('plea_statement')->nullable();
            $table->string('finding', 30)->nullable();
            $table->text('finding_notes')->nullable();
            $table->timestamps();

            $table->unique(['case_id', 'sequence']);
        });

        Schema::create('disciplinary_responses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('case_id')->constrained('disciplinary_cases')->cascadeOnDelete();
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('objection_ground', 30)->nullable();
            $table->text('objection_text')->nullable();
            $table->string('objection_decision', 20)->nullable();
            $table->text('objection_decision_notes')->nullable();
            $table->foreignId('objection_decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('objection_decided_at')->nullable();
            $table->text('defense_evidence_list')->nullable();
            $table->string('acknowledged_name');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at');
            $table->timestamps();

            $table->unique(['case_id', 'version']);
        });

        Schema::create('disciplinary_evidence', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('case_id')->constrained('disciplinary_cases')->cascadeOnDelete();
            $table->foreignUuid('charge_id')->nullable()->constrained('disciplinary_charges')->nullOnDelete();
            $table->string('side', 20);
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('held_by')->nullable();
            $table->string('disk', 30)->nullable();
            $table->string('path')->nullable();
            $table->string('original_name')->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['case_id', 'side']);
        });

        Schema::create('disciplinary_witnesses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('case_id')->constrained('disciplinary_cases')->cascadeOnDelete();
            $table->string('called_by', 20);
            $table->string('name');
            $table->unsignedSmallInteger('age')->nullable();
            $table->string('address')->nullable();
            $table->string('occupation')->nullable();
            $table->text('relationship_disclosure')->nullable();
            $table->text('statement')->nullable();
            $table->text('cross_examination')->nullable();
            $table->text('committee_questions')->nullable();
            $table->boolean('written_testimony')->default(false);
            $table->boolean('heard_in_absence_of_other_party')->default(false);
            $table->timestamp('heard_at')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('disciplinary_recommendations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('case_id')->constrained('disciplinary_cases')->cascadeOnDelete();
            $table->unsignedSmallInteger('version');
            $table->string('finding', 30);
            $table->string('recommended_penalty', 30)->nullable();
            $table->unsignedSmallInteger('penalty_amount')->nullable();
            $table->text('gravity_assessment')->nullable();
            $table->text('conduct_assessment')->nullable();
            $table->text('prior_record_assessment')->nullable();
            $table->text('reasoning');
            $table->string('status', 20)->default('draft');
            $table->unsignedTinyInteger('members_present')->nullable();
            $table->unsignedTinyInteger('votes_for')->nullable();
            $table->unsignedTinyInteger('votes_against')->nullable();
            $table->boolean('tie_broken_by_chair')->default(false);
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('adopted_at')->nullable();
            $table->timestamps();

            $table->unique(['case_id', 'version']);
        });

        Schema::create('disciplinary_recommendation_votes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('recommendation_id')->constrained('disciplinary_recommendations')->cascadeOnDelete();
            $table->foreignUuid('case_member_id')->constrained('disciplinary_case_members')->cascadeOnDelete();
            $table->string('vote', 10);
            $table->text('dissent_reason')->nullable();
            $table->timestamps();

            $table->unique(['recommendation_id', 'case_member_id']);
        });

        Schema::create('disciplinary_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('case_id')->constrained('disciplinary_cases')->cascadeOnDelete();
            $table->foreignUuid('recommendation_id')->nullable()->constrained('disciplinary_recommendations')->nullOnDelete();
            $table->string('action', 20);
            $table->string('finding', 30)->nullable();
            $table->string('penalty', 30)->nullable();
            $table->unsignedSmallInteger('penalty_amount')->nullable();
            $table->text('reason');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('decided_by_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('decided_at');
            $table->string('delivery_method', 30)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->foreignId('delivered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->date('effective_on')->nullable();
            $table->timestamps();

            $table->index(['case_id', 'decided_at']);
        });

        Schema::create('disciplinary_status_histories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('case_id')->constrained('disciplinary_cases')->cascadeOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamp('created_at');

            $table->index(['case_id', 'created_at']);
        });
    }

    public function down(): void
    {
        if (DB::table('disciplinary_cases')->exists()) {
            throw new RuntimeException('Disciplinary cases exist. Export them before rolling back this migration; legal case history is never dropped automatically.');
        }

        foreach ([
            'disciplinary_status_histories', 'disciplinary_decisions', 'disciplinary_recommendation_votes',
            'disciplinary_recommendations', 'disciplinary_witnesses', 'disciplinary_evidence',
            'disciplinary_responses', 'disciplinary_charges', 'disciplinary_case_members', 'disciplinary_cases',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('grievance_committee_members', function (Blueprint $table): void {
            $table->dropColumn(['selection_source', 'term_number']);
        });

        Schema::dropIfExists('statutory_deadlines');
    }
};
