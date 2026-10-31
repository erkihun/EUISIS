<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\ServiceMonitoring\Application\Services\FiscalCalendar;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAudit;
use Modules\ServiceMonitoring\Application\Services\SubmissionGuard;
use Modules\ServiceMonitoring\Domain\Enums\ServiceLevel;
use Modules\ServiceMonitoring\Domain\Enums\VerificationStatus;
use Modules\ServiceMonitoring\Domain\Models\DigitalService;
use Modules\ServiceMonitoring\Domain\Models\Submission;

/**
 * Adds or changes a Section III row (D20). A row links to the institution's
 * own catalog service when one exists; otherwise it is a free-text "catalog
 * gap". The catalog has no main/sub hierarchy, so sub services point at a
 * main row of the same submission. Only services that went live in the
 * current fiscal year count. Any change sends the row back to verification.
 */
final readonly class SaveSection3Entry
{
    public function __construct(private SubmissionGuard $guard, private FiscalCalendar $calendar, private ServiceMonitoringAudit $audit) {}

    /** @param array<string, mixed> $data validated by SaveDigitalServiceRequest */
    public function execute(Submission $submission, array $data, User $actor, ?DigitalService $entry = null): DigitalService
    {
        $this->guard->assertWritable($submission);
        if ($submission->section_3_nothing) {
            throw ValidationException::withMessages(['section_3' => __('service-monitoring.validation.nothing_not_empty', ['section' => 3])]);
        }

        $level = ServiceLevel::from((string) $data['level']);
        $errors = [];
        $service = null;
        if (! empty($data['service_id'])) {
            $service = Service::query()->whereKey($data['service_id'])->where('organization_id', $submission->organization_id)->first();
            if ($service === null) {
                $errors['service_id'] = __('service-monitoring.validation.service_out_of_scope');
            }
        }
        $parentId = $level === ServiceLevel::Sub ? ($data['parent_entry_id'] ?? null) : null;
        if ($level === ServiceLevel::Sub && ($parentId === null || ! DigitalService::query()->whereKey($parentId)->where('submission_id', $submission->id)->where('level', ServiceLevel::Main->value)->exists())) {
            $errors['parent_entry_id'] = __('service-monitoring.validation.parent_main');
        }
        $ecYear = (int) $submission->period->fiscalYear->ec_year;
        [$from, $to] = $this->calendar->fiscalYear($ecYear);
        $goLive = (string) $data['go_live_on'];
        if ($goLive < $from->toDateString() || $goLive > $to->toDateString()) {
            $errors['go_live_on'] = __('service-monitoring.validation.go_live_year', ['year' => $ecYear, 'from' => $this->calendar->display($from->toDateString()), 'to' => $this->calendar->display($to->toDateString())]);
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($submission, $data, $actor, $entry, $level, $service, $parentId, $goLive): DigitalService {
            $values = [
                'service_id' => $service?->id,
                'parent_entry_id' => $parentId,
                'level' => $level,
                'service_name_am' => $service?->name_am ?? ($data['service_name_am'] ?? null),
                'service_name_en' => $service?->name_en ?? ($data['service_name_en'] ?? null),
                'is_catalog_gap' => $service === null,
                'technology_type' => $data['technology_type'],
                'technology_other' => $data['technology_type'] === 'other' ? ($data['technology_other'] ?? null) : null,
                'system_name' => $data['system_name'],
                'system_count' => (int) $data['system_count'],
                'go_live_on' => $goLive,
                'remark' => $data['remark'] ?? null,
                'verification_status' => VerificationStatus::Pending,
                'verified_by' => null,
                'verified_at' => null,
                'rejection_reason' => null,
            ];
            if ($entry === null) {
                $entry = DigitalService::query()->create($values + [
                    'submission_id' => $submission->id,
                    'sequence' => (int) DigitalService::query()->where('submission_id', $submission->id)->max('sequence') + 1,
                ]);
            } else {
                $entry->fill($values)->save();
            }
            $this->audit->record(AuditEventType::ServiceMonitoringSectionSaved, $actor, $entry, $submission->organization_id, [
                'section' => 3, 'submission_id' => $submission->id, 'level' => $level->value, 'service_id' => $service?->id, 'catalog_gap' => $service === null,
            ]);

            return $entry;
        });
    }
}
