<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Grievance Management — migrate step. Moves first-generation rows into the
 * case/stage/decision/letter model created by 2026_09_28_100000. Idempotent:
 * every insert is keyed on something the legacy row already had (grievance +
 * assignment order, legacy_response_id, legacy_decision_letter_id), so a
 * re-run after a partial failure skips what is already there.
 *
 * Legacy tables are read, never modified or dropped.
 */
return new class extends Migration
{
    /** Legacy case status → current vocabulary. */
    private const STATUS_MAP = [
        'requirement_incomplete' => 'returned_for_correction',
        'requirement_fulfilled' => 'under_review',
        'in_progress' => 'under_review',
        'response_drafted' => 'decision_drafting',
        'response_compiled' => 'pending_approval',
        'awaiting_approval' => 'pending_approval',
        'approved' => 'decision_issued',
        'rejected' => 'under_review',
        'escalated' => 'under_review',
        'tribunal_referred' => 'referred_external',
    ];

    /** Legacy response status → decision status. */
    private const RESPONSE_MAP = [
        'draft' => 'draft',
        'compiled' => 'pending_executive_approval',
        'awaiting_manager_approval' => 'pending_executive_approval',
        'approved_by_manager' => 'approved',
        'rejected_by_manager' => 'returned_for_correction',
        'issued' => 'issued',
    ];

    public function up(): void
    {
        DB::table('grievance_committee_members')->where('role', 'secretary')->update(['role' => 'writer']);

        $this->backfillSlaProfiles();
        $this->backfillStages();
        $this->backfillDecisions();
        $this->backfillLetters();
        $this->mapStatuses();
    }

    public function down(): void
    {
        // Restores what up() changed in place; rows it created are dropped
        // with their tables by the expand migration's down().
        DB::table('grievance_committee_members')->where('role', 'writer')->update(['role' => 'secretary']);

        foreach (self::STATUS_MAP as $legacy => $current) {
            // Not a perfect inverse (several legacy values map to one); only
            // rows still carrying the backfill marker are reverted.
            DB::table('grievances')->where('status', $current)->whereNotNull('metadata')
                ->where('metadata', 'like', '%"legacy_status":"'.$legacy.'"%')
                ->update(['status' => $legacy]);
        }
    }

    private function backfillSlaProfiles(): void
    {
        foreach (DB::table('grievance_sla_rules')->orderBy('created_at')->get() as $rule) {
            $name = 'Legacy SLA rule '.substr((string) $rule->id, 0, 8);
            if (DB::table('grievance_sla_profiles')->where('name_en', $name)->exists()) {
                continue;
            }

            DB::table('grievance_sla_profiles')->insert([
                'id' => (string) Str::uuid7(),
                'name_en' => $name,
                'name_am' => null,
                'purpose' => 'resolution',
                'organization_id' => $rule->organization_id,
                'handler_type' => 'committee',
                'handler_id' => null,
                'category_id' => null,
                'resolution_days' => (int) $rule->working_days_limit,
                'day_type' => 'working_days',
                'start_point' => 'on_assignment',
                'warning_thresholds' => json_encode(['percent' => [50], 'days_remaining' => [1], 'due_today' => true]),
                'auto_escalate' => true,
                'priority' => $rule->organization_id === null ? 200 : 100,
                'effective_from' => Carbon::parse($rule->created_at ?? now())->toDateString(),
                'effective_to' => null,
                'is_active' => $rule->status === 'active',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function backfillStages(): void
    {
        $grievanceIds = DB::table('grievance_assignments')->distinct()->pluck('grievance_id');

        foreach ($grievanceIds as $grievanceId) {
            if (DB::table('grievance_case_stages')->where('grievance_id', $grievanceId)->exists()) {
                continue;
            }

            DB::transaction(function () use ($grievanceId): void {
                $grievance = DB::table('grievances')->where('id', $grievanceId)->first();
                if ($grievance === null) {
                    return;
                }

                $assignments = DB::table('grievance_assignments')->where('grievance_id', $grievanceId)
                    ->orderBy('assigned_at')->orderBy('created_at')->get();
                $final = in_array($grievance->status, ['closed', 'withdrawn', 'approved', 'tribunal_referred'], true);

                $previous = null;
                $currentId = null;
                $currentHandler = null;
                foreach ($assignments->values() as $index => $assignment) {
                    $committee = $assignment->committee_id
                        ? DB::table('grievance_committees')->where('id', $assignment->committee_id)->first()
                        : null;
                    if ($committee === null) {
                        continue;
                    }

                    $isCurrent = (bool) $assignment->is_current && ! $final;
                    $stageId = (string) Str::uuid7();
                    DB::table('grievance_case_stages')->insert([
                        'id' => $stageId,
                        'grievance_id' => $grievanceId,
                        'stage_no' => $index + 1,
                        'handler_type' => 'committee',
                        'handler_id' => $committee->id,
                        'organization_id' => $committee->organization_id,
                        'organization_unit_id' => $committee->organization_unit_id,
                        'committee_id' => $committee->id,
                        'from_stage_id' => $previous,
                        'movement_type' => $previous === null ? 'initial_assignment' : 'reassigned',
                        'movement_reason' => $assignment->notes,
                        'moved_by' => $assignment->assigned_by_user_id,
                        'status' => $isCurrent ? 'under_review' : ($final ? 'closed' : 'reassigned'),
                        'received_at' => $assignment->assigned_at,
                        'sla_days' => null,
                        'sla_day_type' => 'working_days',
                        'sla_start_point' => 'on_assignment',
                        'sla_started_at' => $assignment->assigned_at,
                        'due_at' => $assignment->due_at,
                        'original_due_at' => $assignment->due_at,
                        'auto_escalate' => $isCurrent,
                        'completed_at' => $isCurrent ? null : ($grievance->closed_at ?? $assignment->updated_at),
                        'is_current' => $isCurrent,
                        'created_at' => $assignment->created_at ?? now(),
                        'updated_at' => now(),
                    ]);

                    $this->snapshotMembers($stageId, $committee->id, (string) $assignment->assigned_at);

                    $previous = $stageId;
                    if ($isCurrent) {
                        $currentId = $stageId;
                        $currentHandler = $committee->id;
                    }
                }

                $lastStage = $previous;
                DB::table('grievances')->where('id', $grievanceId)->update([
                    'current_stage_id' => $currentId ?? $lastStage,
                    'current_handler_type' => $currentId !== null ? 'committee' : null,
                    'current_handler_id' => $currentHandler,
                    'accepted_at' => $grievance->requirement_checked_at,
                ]);
            });
        }
    }

    private function snapshotMembers(string $stageId, string $committeeId, string $asOf): void
    {
        $date = Carbon::parse($asOf)->toDateString();
        $members = DB::table('grievance_committee_members')
            ->where('committee_id', $committeeId)
            ->where('status', 'active')
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            ->get();

        foreach ($members as $member) {
            DB::table('grievance_stage_members')->insertOrIgnore([
                'id' => (string) Str::uuid7(),
                'case_stage_id' => $stageId,
                'employee_id' => $member->employee_id,
                'committee_member_id' => $member->id,
                'role' => $member->role === 'secretary' ? 'writer' : $member->role,
                'source' => 'committee',
                'is_active' => true,
                'joined_at' => $asOf,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function backfillDecisions(): void
    {
        $responses = DB::table('grievance_responses')->orderBy('grievance_id')->orderBy('revision_round')->orderBy('created_at')->get();

        foreach ($responses as $response) {
            if (DB::table('grievance_decisions')->where('legacy_response_id', $response->id)->exists()) {
                continue;
            }

            $stage = DB::table('grievance_case_stages')->where('grievance_id', $response->grievance_id)
                ->when($response->committee_id, fn ($q) => $q->where('committee_id', $response->committee_id))
                ->orderByDesc('stage_no')->first();
            if ($stage === null) {
                continue;
            }

            $version = (int) DB::table('grievance_decisions')->where('case_stage_id', $stage->id)->max('version_no') + 1;
            $status = self::RESPONSE_MAP[$response->status] ?? 'draft';
            $body = $response->response_body_am && ! $response->response_body_en
                ? $response->response_body_am
                : trim($response->response_body_en."\n\n".($response->response_body_am ?? ''));

            DB::table('grievance_decisions')->insert([
                'id' => (string) Str::uuid7(),
                'grievance_id' => $response->grievance_id,
                'case_stage_id' => $stage->id,
                'version_no' => $version,
                'decision_type' => null,
                'decision_text' => $body,
                'status' => $status,
                'requires_executive_approval' => true,
                'prepared_by_employee_id' => $response->compiled_by_employee_id ?? $response->drafted_by_employee_id,
                'submitted_for_approval_at' => $response->compiled_at,
                'approved_by' => $response->approved_by_user_id,
                'approved_at' => $response->approved_at,
                'rejected_by' => $status === 'returned_for_correction' ? null : $response->rejected_by_user_id,
                'returned_at' => $status === 'returned_for_correction' ? $response->rejected_at : null,
                'finalized_at' => in_array($status, ['approved', 'issued'], true) ? $response->approved_at : null,
                'issued_at' => $status === 'issued' ? ($response->approved_at ?? $response->updated_at) : null,
                'legacy_response_id' => $response->id,
                'created_at' => $response->created_at ?? now(),
                'updated_at' => now(),
            ]);

            if ($status === 'returned_for_correction' && $response->rejection_reason) {
                $decisionId = DB::table('grievance_decisions')->where('legacy_response_id', $response->id)->value('id');
                DB::table('grievance_decision_approvals')->insert([
                    'id' => (string) Str::uuid7(),
                    'decision_id' => $decisionId,
                    'action' => 'return_for_correction',
                    'actor_user_id' => $response->rejected_by_user_id,
                    'comment' => $response->rejection_reason,
                    'acted_at' => $response->rejected_at ?? now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function backfillLetters(): void
    {
        foreach (DB::table('grievance_decision_letters')->get() as $letter) {
            if (DB::table('grievance_letters')->where('legacy_decision_letter_id', $letter->id)->exists()) {
                continue;
            }

            $grievance = DB::table('grievances')->where('id', $letter->grievance_id)->first();
            $decisionId = DB::table('grievance_decisions')->where('legacy_response_id', $letter->response_id)->value('id');

            DB::table('grievance_letters')->insert([
                'id' => (string) Str::uuid7(),
                'grievance_id' => $letter->grievance_id,
                'decision_id' => $decisionId,
                'organization_id' => $grievance?->organization_id,
                'letter_type' => 'decision_letter',
                'language' => 'bilingual',
                'reference_number' => $letter->letter_reference,
                'subject' => 'Grievance decision — '.($grievance?->reference_number ?? ''),
                'body' => '',
                'letter_date' => Carbon::parse($letter->generated_at)->toDateString(),
                'status' => 'issued',
                'signature_method' => 'electronic_approval',
                'pdf_disk' => 'local',
                'pdf_path' => $letter->file_path,
                'generated_at' => $letter->generated_at,
                'visible_to_complainant' => true,
                'issued_by' => $letter->generated_by_user_id,
                'issued_at' => $letter->generated_at,
                'legacy_decision_letter_id' => $letter->id,
                'created_by' => $letter->generated_by_user_id,
                'created_at' => $letter->created_at ?? now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function mapStatuses(): void
    {
        foreach (self::STATUS_MAP as $legacy => $current) {
            // lazyById, not each(): each() pages by offset, and rewriting the
            // filtered column mid-scan would skip rows.
            foreach (DB::table('grievances')->where('status', $legacy)->lazyById(200) as $row) {
                $metadata = json_decode((string) ($row->metadata ?? 'null'), true) ?: [];
                $metadata['legacy_status'] = $legacy;
                DB::table('grievances')->where('id', $row->id)->update([
                    'status' => $current,
                    'metadata' => json_encode($metadata),
                ]);
            }
        }

        DB::table('grievances')->whereIn('status', ['closed', 'withdrawn', 'rejected_at_intake'])->update(['record_state' => 'closed']);
        DB::table('grievances')->where('status', 'withdrawn')->whereNull('withdrawn_at')->update(['withdrawn_at' => DB::raw('updated_at')]);
    }
};
