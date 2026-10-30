<?php

declare(strict_types=1);

namespace Modules\Discipline\Application\Queries;

use App\Models\User;
use Illuminate\Support\Carbon;
use Modules\Discipline\Application\Services\DisciplinaryCaseAccess;
use Modules\Discipline\Contracts\Data\DisciplinaryFinding;
use Modules\Discipline\Contracts\Data\DisciplinaryPenalty;
use Modules\Discipline\Contracts\DisciplineCases;
use Modules\Discipline\Domain\Models\DisciplinaryCase;
use Modules\Discipline\Domain\Models\DisciplinaryDecision;

/** Discipline cases for the statutory rules, court filing and deadline alerts (moved here from Legal and court-ms code). */
final class DisciplineCasesQuery implements DisciplineCases
{
    public function __construct(private readonly DisciplinaryCaseAccess $access) {}

    public function penalisedSince(string $employeeId, Carbon $since): bool
    {
        // A penalty set aside by the Administrative Court is not a penalty (Art. 6(2)).
        return DisciplinaryDecision::query()
            ->whereHas('disciplinaryCase', fn ($q) => $q->where('accused_employee_id', $employeeId))
            ->where('finding', DisciplinaryFinding::Responsible->value)
            ->whereIn('penalty', DisciplinaryPenalty::values())
            ->where('decided_at', '>=', $since)
            ->whereDoesntHave('appeal', fn ($q) => $q->where('outcome', 'reversed'))
            ->exists();
    }

    public function deliveredCasesOf(string $employeeId, int $limit = 50): array
    {
        return $this->delivered($employeeId)->latest('delivered_at')->limit($limit)->get()
            ->map(fn (DisciplinaryCase $c): array => [
                'id' => (string) $c->getKey(),
                'case_number' => (string) $c->case_number,
                'summary' => $c->summary ? mb_substr(strip_tags((string) $c->summary), 0, 200) : null,
                'status' => $c->status?->value,
                'delivered_at' => $c->delivered_at?->toIso8601String(),
            ])->values()->all();
    }

    public function ownDeliveredCaseIds(string $employeeId, array $caseIds): array
    {
        return $caseIds === [] ? [] : $this->delivered($employeeId)->whereKey($caseIds)->pluck('id')->map(fn ($id): string => (string) $id)->all();
    }

    public function courtSnapshots(array $caseIds): array
    {
        return $caseIds === [] ? [] : DisciplinaryCase::query()->whereKey($caseIds)->with(['charges', 'responses', 'decisions'])->orderBy('charged_at')->get()
            ->map(fn (DisciplinaryCase $c): array => $this->disciplineSnapshot($c))->values()->all();
    }

    public function deadlineAlertTarget(string $caseId): array
    {
        $case = DisciplinaryCase::query()->find($caseId);
        if ($case === null || $case->status->isFinal()) {
            return [null, null, []];
        }
        $users = User::permission('discipline.manage')->where('status', 'active')->get()
            ->filter(fn (User $u) => $this->access->canOperate($u, $case, 'discipline.manage'))->values()->all();

        return [(string) $case->case_number, '/discipline/cases/'.$case->getKey(), $users];
    }

    private function delivered(string $employeeId)
    {
        return DisciplinaryCase::query()->where('accused_employee_id', $employeeId)->whereNotNull('delivered_at');
    }

    /**
     * What court-ms keeps of a discipline case, in the grievances' form: the
     * charges, the employee's answers (as responses) and the delivered
     * decisions, as plain text.
     *
     * @return array<string, mixed>
     */
    private function disciplineSnapshot(DisciplinaryCase $case): array
    {
        $charges = $case->charges->map(fn ($charge): string => trim(implode(' — ', array_filter([(string) $charge->title, $charge->violated_provision ? (string) $charge->violated_provision : null]))))->filter()->values();

        return [
            'uuid' => (string) $case->getKey(),
            'reference' => $case->case_number,
            'subject' => $charges->first() ?: ((string) $case->summary ?: (string) $case->case_number),
            'category' => $case->severity?->value,
            'status' => $case->status?->value,
            'description' => trim(implode("\n\n", array_filter([
                $this->plain((string) $case->summary),
                ...$case->charges->map(fn ($charge): string => trim(implode("\n", array_filter([(string) $charge->title, $this->plain((string) $charge->particulars)]))))->all(),
            ]))),
            'submitted_at' => $case->charged_at?->toIso8601String(),
            'closed_at' => ($case->closed_at ?? $case->delivered_at)?->toIso8601String(),
            'responses' => [
                ...$case->responses->map(fn ($response): array => [
                    'body' => $this->plain(trim(implode("\n\n", array_filter([(string) $response->objection_text, (string) $response->defense_evidence_list])))),
                    'at' => $response->received_at?->toDateString(),
                ])->filter(fn (array $row): bool => $row['body'] !== '')->values()->all(),
                ...($case->final_statement ? [['body' => $this->plain((string) $case->final_statement), 'at' => $case->final_statement_at?->toDateString()]] : []),
            ],
            'decisions' => $case->decisions->whereNotNull('delivered_at')->map(fn ($decision): array => [
                'number' => null,
                // The penalty in words, its amount and the article it rests on, e.g. "Written warning (Art. 69)".
                'type' => $decision->penalty !== null
                    ? trim(ucfirst(str_replace('_', ' ', $decision->penalty->value)).($decision->penalty_amount ? ' '.$decision->penalty_amount : '').' ('.$decision->penalty->article().')')
                    : ($decision->action?->value !== null ? ucfirst(str_replace('_', ' ', $decision->action->value)) : null),
                'text' => $this->plain((string) $decision->reason),
                'at' => ($decision->decided_at ?? $decision->delivered_at)?->toDateString(),
            ])->values()->all(),
        ];
    }

    private function plain(string $html): string
    {
        $text = preg_replace(['~<\s*br\s*/?>~i', '~</\s*(p|div|li|h[1-6]|tr)\s*>~i'], ["\n", "\n\n"], $html) ?? $html;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+/', ' ', $text) ?? $text) ?? $text);
    }
}
