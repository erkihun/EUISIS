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
use Modules\ServiceMonitoring\Domain\Models\PrefillValue;
use Modules\ServiceMonitoring\Domain\Models\Submission;

/**
 * Saves the Section II figures (autosave keeps partial input). When a figure
 * differs from its system figure (D18), the reason is required at once; with
 * "የለም" the quarter's flow figures must be 0 (D10).
 */
final readonly class SaveSection2
{
    public function __construct(private SubmissionGuard $guard, private ServiceMonitoringAudit $audit) {}

    /**
     * @param  array<string, ?int>  $values  field => figure (null = not entered yet)
     * @param  array<string, ?string>  $overrideReasons
     */
    public function execute(Submission $submission, array $values, ?string $narrative, array $overrideReasons, User $actor): InstitutionBuilding
    {
        $this->guard->assertWritable($submission);
        $values = array_intersect_key($values, array_flip(FormDefinition::section2Keys()));
        $prefills = PrefillValue::query()->where('submission_id', $submission->id)->get()->keyBy('field');

        $errors = [];
        foreach (FormDefinition::overridableFields() as $field) {
            $system = $prefills[$field]->system_value ?? null;
            $reported = $values[$field] ?? null;
            if ($system !== null && $reported !== null && $reported !== $system && mb_strlen(trim((string) ($overrideReasons[$field] ?? ''))) < FormDefinition::MIN_OVERRIDE_REASON) {
                $errors["override_reasons.{$field}"] = __('service-monitoring.validation.override_reason', ['system' => $system, 'min' => FormDefinition::MIN_OVERRIDE_REASON]);
            }
        }
        if ($submission->section_2_nothing) {
            $nonZero = array_filter(FormDefinition::section2Flow(), fn (string $f): bool => ($values[$f] ?? 0) !== 0);
            if ($nonZero !== []) {
                $errors['section_2'] = __('service-monitoring.validation.flow_must_be_zero', ['fields' => implode(', ', array_map(fn (string $f): string => (string) __("service-monitoring.form.section_2.fields.{$f}"), $nonZero))]);
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($submission, $values, $narrative, $overrideReasons, $prefills, $actor): InstitutionBuilding {
            $building = InstitutionBuilding::query()->firstOrNew(['submission_id' => $submission->id]);
            $building->fill($values)->save();
            $submission->forceFill(['special_support_narrative' => $narrative])->save();
            foreach (FormDefinition::overridableFields() as $field) {
                if (isset($prefills[$field])) {
                    $differs = $prefills[$field]->system_value !== null && ($values[$field] ?? null) !== null && $values[$field] !== $prefills[$field]->system_value;
                    $prefills[$field]->forceFill(['override_reason' => $differs ? trim((string) ($overrideReasons[$field] ?? '')) : null])->save();
                }
            }
            $this->audit->record(AuditEventType::ServiceMonitoringSectionSaved, $actor, $submission, $submission->organization_id, ['section' => 2, 'values' => $values]);

            return $building;
        });
    }
}
