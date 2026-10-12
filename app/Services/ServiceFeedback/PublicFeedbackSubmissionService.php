<?php

declare(strict_types=1);

namespace App\Services\ServiceFeedback;

use App\Actions\Audit\WriteAuditLogAction;
use App\Actions\CodeRules\GenerateCodeAction;
use App\Enums\AuditEventType;
use App\Enums\CodeRuleEntityType;
use App\Enums\PublicFeedbackTargetType;
use App\Enums\ServiceFeedbackStatus;
use App\Enums\ServiceFeedbackType;
use App\Enums\SubmitterIdentityMode;
use App\Models\EmployeeServiceFeedback;
use App\Models\PositionService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Creates a public submission for a resolved target (brief §106).
 *
 * The target comes only from PublicFeedbackTargetResolver — never from the
 * request — and is written with its snapshots so later transfers, renames or
 * QR rotations cannot move the row. Complaints get their reference from the
 * code rules and keep their attachments on the private disk. An accidental
 * double submit inside a short window returns the row already stored rather
 * than creating a twin (brief §39).
 */
class PublicFeedbackSubmissionService
{
    private const DUPLICATE_WINDOW_SECONDS = 120;

    public function __construct(
        private readonly WriteAuditLogAction $audit,
        private readonly GenerateCodeAction $codes,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated form data; target fields in it are ignored
     */
    public function submit(TargetContext $target, ServiceFeedbackType $type, array $data, Request $request): EmployeeServiceFeedback
    {
        $existing = $this->duplicateOf($target, $type, $data);

        if ($existing !== null) {
            return $existing;
        }

        $service = $target->targetType === PublicFeedbackTargetType::Employee && ! empty($data['position_service_id'])
            ? PositionService::query()->find($data['position_service_id'])
            : null;
        $isComplaint = $type === ServiceFeedbackType::Complaint;
        $storedPaths = [];

        try {
            return DB::transaction(function () use ($target, $type, $data, $request, $service, $isComplaint, &$storedPaths): EmployeeServiceFeedback {
                $entry = EmployeeServiceFeedback::query()->create([
                    // Target and source: only ever from the resolved context.
                    'target_type' => $target->targetType,
                    'source_type' => $target->sourceType,
                    'employee_id' => $target->employeeId,
                    'organization_target_id' => $target->organizationTargetId,
                    'employee_feedback_token_id' => $target->targetType === PublicFeedbackTargetType::Employee ? $target->sourceId : null,
                    'source_organization_qr_id' => $target->targetType === PublicFeedbackTargetType::Organization ? $target->sourceId : null,
                    'employee_assignment_id' => $target->employeeAssignmentId,
                    'organization_id' => $target->contextOrganizationId,
                    'organization_unit_id' => $target->contextUnitId,
                    'position_id' => $target->contextPositionId,
                    'target_name_snapshot' => $target->targetNameSnapshot,
                    'target_code_snapshot' => $target->targetCodeSnapshot,
                    'type' => $type,
                    'position_service_id' => $service?->getKey(),
                    'service_no_snapshot' => $service?->service_no,
                    'service_name_snapshot' => $service?->name_en,
                    'rating' => $type === ServiceFeedbackType::Feedback ? (int) $data['rating'] : null,
                    'comment' => $type === ServiceFeedbackType::Feedback
                        ? $this->trimmedOrNull($data['comment'] ?? null)
                        : trim((string) ($data['message'] ?? '')),
                    'subject' => $this->trimmedOrNull($data['subject'] ?? null),
                    // Applicability was checked by the controller against the resolved target.
                    'category_id' => $this->trimmedOrNull($data['category_id'] ?? null),
                    'client_name' => $this->trimmedOrNull($data['client_name'] ?? null),
                    'client_contact' => $this->trimmedOrNull($data['client_contact'] ?? null),
                    'submitter_identity_mode' => SubmitterIdentityMode::Optional,
                    'incident_date' => $isComplaint ? ($data['incident_date'] ?? null) : null,
                    'incident_location' => $isComplaint ? $this->trimmedOrNull($data['incident_location'] ?? null) : null,
                    'ip_address' => $request->ip(),
                    'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
                    'status' => ServiceFeedbackStatus::Pending,
                    'submitted_at' => now(),
                ]);

                if ($isComplaint) {
                    $entry->forceFill([
                        'reference_number' => $this->codes->execute(
                            CodeRuleEntityType::PublicServiceComplaint,
                            ['organization_id' => $target->contextOrganizationId],
                            null,
                            null,
                            'reference_number',
                            $entry->getKey(),
                        ),
                    ])->save();

                    foreach ($data['attachments'] ?? [] as $file) {
                        if (! $file instanceof UploadedFile) {
                            continue;
                        }

                        $storedPaths[] = $this->storeAttachment($entry, $file);
                    }
                }

                $this->audit->execute(
                    eventType: match ($type) {
                        ServiceFeedbackType::Feedback => AuditEventType::ServiceFeedbackSubmitted,
                        ServiceFeedbackType::Suggestion => AuditEventType::ServiceSuggestionSubmitted,
                        ServiceFeedbackType::Complaint => AuditEventType::ServiceComplaintSubmitted,
                    },
                    // Anonymous by design: there is no actor to attribute this to.
                    actor: null,
                    auditable: $entry,
                    organizationId: $target->contextOrganizationId,
                    reason: 'Public '.$type->value.' submitted about '.$target->targetType->value,
                    request: $request,
                );

                return $entry;
            });
        } catch (\Throwable $exception) {
            foreach ($storedPaths as $path) {
                Storage::disk('local')->delete($path);
            }

            throw $exception;
        }
    }

    /**
     * The row an identical submission created moments ago, if any. The
     * fingerprint covers the source, the type and the content, so two
     * different messages from one visitor are two submissions.
     */
    private function duplicateOf(TargetContext $target, ServiceFeedbackType $type, array $data): ?EmployeeServiceFeedback
    {
        $fingerprint = hash('sha256', implode('|', [
            $target->sourceId,
            $type->value,
            trim((string) ($data['message'] ?? $data['comment'] ?? '')),
            (string) ($data['rating'] ?? ''),
            trim((string) ($data['client_contact'] ?? '')),
        ]));

        if (Cache::add('public-feedback:dup:'.$fingerprint, 1, now()->addSeconds(self::DUPLICATE_WINDOW_SECONDS))) {
            return null;
        }

        $query = EmployeeServiceFeedback::query()
            ->where('type', $type->value)
            ->where('created_at', '>=', now()->subSeconds(self::DUPLICATE_WINDOW_SECONDS))
            ->latest('created_at');

        $query = $target->targetType === PublicFeedbackTargetType::Employee
            ? $query->where('employee_feedback_token_id', $target->sourceId)
            : $query->where('source_organization_qr_id', $target->sourceId);

        return $query->first();
    }

    private function storeAttachment(EmployeeServiceFeedback $entry, UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $path = $file->storeAs('service-feedback/'.$entry->getKey(), Str::uuid7().'.'.$extension, 'local');

        if ($path === false) {
            throw new \RuntimeException('Complaint attachment could not be stored.');
        }

        $entry->attachments()->create([
            'disk' => 'local',
            'path' => $path,
            'original_name' => $this->safeAttachmentName($file->getClientOriginalName()),
            'mime_type' => (string) $file->getMimeType(),
            'size_bytes' => (int) $file->getSize(),
            'sha256' => hash_file('sha256', $file->getRealPath()),
            'scan_status' => 'not_scanned',
        ]);

        return $path;
    }

    private function safeAttachmentName(string $name): string
    {
        $base = basename(str_replace('\\', '/', $name));
        $safe = trim((string) preg_replace('/[^\pL\pN._ -]/u', '_', $base), ' .');

        return Str::limit($safe !== '' ? $safe : 'attachment', 255, '');
    }

    private function trimmedOrNull(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
