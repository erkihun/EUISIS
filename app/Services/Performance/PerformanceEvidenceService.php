<?php

declare(strict_types=1);

namespace App\Services\Performance;

use App\Enums\AuditEventType;
use App\Enums\Performance\AgreementStatus;
use App\Enums\Performance\EvidenceType;
use App\Models\DailyActivityItem;
use App\Models\EmployeePerformanceAgreement;
use App\Models\PerformanceEvidence;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Evidence behind KPI actuals. Files go to the PRIVATE disk under a random
 * name and are streamed only through an authorized route. The employee may
 * add evidence to their own agreement; verification is a manager act.
 */
final class PerformanceEvidenceService
{
    public function __construct(private readonly EpmsAccess $access, private readonly EpmsAudit $audit) {}

    /** @param array<string, mixed> $data */
    public function add(EmployeePerformanceAgreement $agreement, array $data, ?UploadedFile $file, User $actor): PerformanceEvidence
    {
        $own = $this->access->isOwn($actor, $agreement);
        $this->access->authorize($own || $this->access->canManageAgreement($actor, $agreement));
        if (! in_array($agreement->status, [AgreementStatus::Active, AgreementStatus::UnderReview, AgreementStatus::Agreed], true)) {
            throw ValidationException::withMessages(['agreement' => __('performance.errors.agreement_not_active')]);
        }

        $itemId = $data['employee_performance_item_id'] ?? null;
        if ($itemId !== null && ! $agreement->items()->whereKey($itemId)->exists()) {
            throw ValidationException::withMessages(['employee_performance_item_id' => __('performance.errors.item_not_in_agreement')]);
        }

        // A linked daily activity must be the agreement employee's own.
        $dailyItemId = $data['daily_activity_item_id'] ?? null;
        if ($dailyItemId !== null && ! DailyActivityItem::query()->whereKey($dailyItemId)->whereHas('log', fn ($q) => $q->where('employee_id', $agreement->employee_id))->exists()) {
            throw ValidationException::withMessages(['daily_activity_item_id' => __('performance.errors.item_not_in_agreement')]);
        }

        $type = $own ? ($dailyItemId !== null ? EvidenceType::DailyActivity : EvidenceType::Document) : EvidenceType::from($data['evidence_type'] ?? EvidenceType::ManagerConfirmation->value);

        $evidence = $agreement->evidence()->create([
            'employee_performance_item_id' => $itemId,
            'kpi_id' => $itemId !== null ? $agreement->items()->whereKey($itemId)->value('kpi_id') : null,
            'daily_activity_item_id' => $dailyItemId,
            'evidence_type' => $type->value,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'file_path' => $file?->storeAs('performance-evidence/'.$agreement->getKey(), Str::uuid7().'.'.$file->extension(), 'local'),
            'original_name' => $file?->getClientOriginalName(),
            'mime_type' => $file?->getMimeType(),
            'file_size' => $file?->getSize(),
            'submitted_by' => $actor->getKey(),
            'submitted_at' => now(),
        ]);

        $this->audit->record(AuditEventType::PerformanceEvidenceAdded, $actor, $agreement, ['evidence' => $evidence->getKey(), 'type' => $type->value, 'item' => $itemId]);

        return $evidence;
    }

    public function verify(PerformanceEvidence $evidence, User $actor): PerformanceEvidence
    {
        $this->access->authorize($actor->can('kpi_actuals.verify') && $this->access->canManageAgreement($actor, $evidence->agreement));
        $evidence->forceFill(['verified' => true, 'verified_by' => $actor->getKey(), 'verified_at' => now()])->save();

        return $evidence;
    }

    public function canDownload(User $user, PerformanceEvidence $evidence): bool
    {
        return $evidence->file_path !== null && $this->access->canViewAgreement($user, $evidence->agreement);
    }

    public function path(PerformanceEvidence $evidence): string
    {
        return Storage::disk('local')->path($evidence->file_path);
    }
}
