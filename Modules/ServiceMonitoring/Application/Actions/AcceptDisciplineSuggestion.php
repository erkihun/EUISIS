<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Actions;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Modules\ServiceMonitoring\Application\Services\AccountabilitySuggestionService;
use Modules\ServiceMonitoring\Domain\Enums\EntrySource;
use Modules\ServiceMonitoring\Domain\Enums\SubjectType;
use Modules\ServiceMonitoring\Domain\Models\AccountabilityEntry;
use Modules\ServiceMonitoring\Domain\Models\Submission;

/**
 * Turns a final disciplinary decision into a Section I row (source =
 * discipline). The decision is read, never written; the same decision cannot
 * be accepted twice in a submission (also a unique index).
 */
final readonly class AcceptDisciplineSuggestion
{
    public function __construct(private AccountabilitySuggestionService $suggestions, private SaveSection1Entry $save) {}

    /** @param array{decision_id: string, role_category: string, reason_category: string, reason_detail: string, office_name?: ?string, remark?: ?string, repeat_confirmed?: bool} $data */
    public function execute(Submission $submission, array $data, User $actor): AccountabilityEntry
    {
        if (AccountabilityEntry::query()->where('submission_id', $submission->id)->where('source_decision_id', $data['decision_id'])->exists()) {
            throw ValidationException::withMessages(['decision_id' => __('service-monitoring.validation.decision_used')]);
        }
        $decision = $this->suggestions->find($submission, $actor, $data['decision_id']);
        if ($decision === null) {
            throw ValidationException::withMessages(['decision_id' => __('service-monitoring.validation.decision_unavailable')]);
        }
        $case = $decision->disciplinaryCase;
        $penalty = $decision->penalty;
        $amount = $decision->penalty_amount !== null ? ' ('.$decision->penalty_amount.' '.($penalty->amountUnit() ?? '').')' : '';

        return $this->save->execute($submission, [
            'subject_type' => SubjectType::Employee->value,
            'employee_id' => $case->accused_employee_id,
            'role_category' => $data['role_category'],
            'reason_category' => $data['reason_category'],
            'reason_detail' => $data['reason_detail'],
            'final_action' => AccountabilitySuggestionService::finalActionFor($penalty)->value,
            'action_detail' => trim(__('service-monitoring.enums.final_action.'.AccountabilitySuggestionService::finalActionFor($penalty)->value).$amount.' — '.$penalty->article().($case->case_number ? ', '.$case->case_number : '')),
            'action_date' => $decision->delivered_at->setTimezone('Africa/Addis_Ababa')->toDateString(),
            'office_name' => $data['office_name'] ?? null,
            'remark' => $data['remark'] ?? null,
            'repeat_confirmed' => $data['repeat_confirmed'] ?? false,
        ], $actor, null, EntrySource::Discipline, (string) $decision->id);
    }
}
