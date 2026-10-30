<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAudit;
use Modules\ServiceMonitoring\Application\Services\SubmissionGuard;
use Modules\ServiceMonitoring\Domain\Forms\FormDefinition;
use Modules\ServiceMonitoring\Domain\Models\InstitutionBuilding;
use Modules\ServiceMonitoring\Domain\Models\Submission;

/**
 * Sets or clears a section's "የለም" flag (D10). A flagged section must be
 * empty: Sections I and III without rows, Section IV without counts. For
 * Section II the quarter's flow figures become 0 (only empty ones are
 * filled; a non-zero figure must be cleared first) and the stock figures
 * stay required.
 */
final readonly class SetNothingToReport
{
    public function __construct(private SubmissionGuard $guard, private ServiceMonitoringAudit $audit) {}

    public function execute(Submission $submission, int $section, bool $nothing, User $actor): Submission
    {
        $this->guard->assertWritable($submission);

        if ($nothing) {
            $notEmpty = match ($section) {
                1 => $submission->accountabilityEntries()->exists(),
                3 => $submission->digitalServices()->exists(),
                4 => $submission->standardPerformance()->where(fn ($q) => collect(FormDefinition::SECTION_4)->each(fn (string $f) => $q->orWhere($f, '>', 0)))->exists(),
                2 => InstitutionBuilding::query()->where('submission_id', $submission->id)
                    ->where(fn ($q) => collect(FormDefinition::section2Flow())->each(fn (string $f) => $q->orWhere($f, '>', 0)))->exists(),
                default => throw ValidationException::withMessages(['section' => 'Unknown section.']),
            };
            if ($notEmpty) {
                throw ValidationException::withMessages(["section_{$section}" => $section === 2
                    ? __('service-monitoring.validation.flow_must_be_zero', ['fields' => implode(', ', array_map(fn (string $f): string => (string) __("service-monitoring.form.section_2.fields.{$f}"), FormDefinition::section2Flow()))])
                    : __('service-monitoring.validation.nothing_not_empty', ['section' => $section])]);
            }
        }

        DB::transaction(function () use ($submission, $section, $nothing, $actor): void {
            $submission->forceFill(["section_{$section}_nothing" => $nothing])->save();
            if ($nothing && $section === 2) {
                $building = InstitutionBuilding::query()->firstOrNew(['submission_id' => $submission->id]);
                foreach (FormDefinition::section2Flow() as $field) {
                    $building->{$field} ??= 0;
                }
                $building->save();
            }
            $this->audit->record(AuditEventType::ServiceMonitoringSectionSaved, $actor, $submission, $submission->organization_id, ['section' => $section, 'nothing_to_report' => $nothing]);
        });

        return $submission;
    }
}
