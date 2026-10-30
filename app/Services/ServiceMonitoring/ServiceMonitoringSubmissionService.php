<?php

declare(strict_types=1);

namespace App\Services\ServiceMonitoring;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\ServiceMonitoring\FormStatus;
use App\Enums\ServiceMonitoring\SubmissionStatus;
use App\Models\Organization;
use App\Models\ServiceMonitoringForm;
use App\Models\ServiceMonitoringPeriod;
use App\Models\ServiceMonitoringSubmission;
use App\Models\User;
use App\Support\ServiceMonitoring\FormSchema;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * An institution's report workflow (docs/service-monitoring.md §Reports).
 *
 *   draft ─submit→ submitted ─accept→ accepted
 *     ↑                 └─return→ returned ─submit→ submitted
 *
 * The institution edits only a draft or a returned report, and only while
 * the period is open. Answers are validated against the exact form version
 * the report was started on; every total is recomputed here.
 */
final readonly class ServiceMonitoringSubmissionService
{
    public const DISK = 'local';

    public function __construct(private WriteAuditLogAction $audit) {}

    /** Find or start the one report of an organization for a form and period. */
    public function start(User $actor, ServiceMonitoringForm $form, ServiceMonitoringPeriod $period, Organization $organization): ServiceMonitoringSubmission
    {
        return DB::transaction(function () use ($actor, $form, $period, $organization): ServiceMonitoringSubmission {
            $existing = ServiceMonitoringSubmission::query()
                ->where('form_code', $form->code)
                ->where('period_id', $period->getKey())
                ->where('organization_id', $organization->getKey())
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            if ($form->status !== FormStatus::Published) {
                throw ValidationException::withMessages(['form_id' => __('service-monitoring.errors.form_not_published')]);
            }
            if (! $period->isOpen()) {
                throw ValidationException::withMessages(['period_id' => __('service-monitoring.errors.period_closed')]);
            }
            if (! $form->appliesToOrganization($organization)) {
                throw ValidationException::withMessages(['organization_id' => __('service-monitoring.errors.form_not_assigned')]);
            }

            $submission = ServiceMonitoringSubmission::query()->create([
                'form_id' => $form->getKey(),
                'form_code' => $form->code,
                'period_id' => $period->getKey(),
                'organization_id' => $organization->getKey(),
                'status' => SubmissionStatus::Draft,
                'data' => FormSchema::from($form->schema)->normalize([], complete: false)['data'],
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);
            $this->log(AuditEventType::ServiceMonitoringReportSaved, $actor, $submission);

            return $submission;
        });
    }

    /**
     * Save answers; with `$submit` also validate completeness and submit.
     *
     * @param  array<string, mixed>  $data
     */
    public function save(User $actor, ServiceMonitoringSubmission $submission, array $data, bool $submit, ?UploadedFile $attachment = null): ServiceMonitoringSubmission
    {
        $storedPath = $attachment?->store('service-monitoring/'.$submission->getKey(), self::DISK);

        try {
            return DB::transaction(function () use ($actor, $submission, $data, $submit, $attachment, $storedPath): ServiceMonitoringSubmission {
                $submission = ServiceMonitoringSubmission::query()->with(['form', 'period'])->lockForUpdate()->findOrFail($submission->getKey());
                $this->assertEditable($submission);

                $result = FormSchema::from($submission->form->schema)->normalize($data, complete: $submit);
                if ($result['errors'] !== []) {
                    throw ValidationException::withMessages($result['errors']);
                }

                $previousPath = $submission->attachment_path;
                $attributes = ['data' => $result['data'], 'updated_by' => $actor->getKey()];
                if ($storedPath !== null && $storedPath !== false) {
                    $attributes['attachment_path'] = $storedPath;
                    $attributes['attachment_name'] = mb_substr($attachment?->getClientOriginalName() ?? 'attachment', 0, 255);
                }
                if ($submit) {
                    $attributes += [
                        'status' => SubmissionStatus::Submitted,
                        'submitted_by' => $actor->getKey(),
                        'submitted_at' => now(),
                    ];
                }
                $submission->update($attributes);

                if (isset($attributes['attachment_path']) && $previousPath !== null) {
                    DB::afterCommit(static fn () => Storage::disk(self::DISK)->delete($previousPath));
                }
                $this->log($submit ? AuditEventType::ServiceMonitoringReportSubmitted : AuditEventType::ServiceMonitoringReportSaved, $actor, $submission);

                return $submission;
            });
        } catch (\Throwable $e) {
            if (is_string($storedPath)) {
                Storage::disk(self::DISK)->delete($storedPath);
            }

            throw $e;
        }
    }

    public function accept(User $actor, ServiceMonitoringSubmission $submission, ?string $note): ServiceMonitoringSubmission
    {
        return $this->review($actor, $submission, SubmissionStatus::Accepted, $note, AuditEventType::ServiceMonitoringReportAccepted);
    }

    public function returnForCorrection(User $actor, ServiceMonitoringSubmission $submission, string $note): ServiceMonitoringSubmission
    {
        return $this->review($actor, $submission, SubmissionStatus::Returned, $note, AuditEventType::ServiceMonitoringReportReturned);
    }

    private function review(User $actor, ServiceMonitoringSubmission $submission, SubmissionStatus $to, ?string $note, AuditEventType $event): ServiceMonitoringSubmission
    {
        return DB::transaction(function () use ($actor, $submission, $to, $note, $event): ServiceMonitoringSubmission {
            $submission = ServiceMonitoringSubmission::query()->lockForUpdate()->findOrFail($submission->getKey());
            if ($submission->status !== SubmissionStatus::Submitted) {
                throw ValidationException::withMessages(['status' => __('service-monitoring.errors.only_submitted_reviewable')]);
            }
            // Maker ≠ checker: the person who submitted cannot review their own report.
            if ((string) $submission->submitted_by === (string) $actor->getKey()) {
                throw ValidationException::withMessages(['status' => __('service-monitoring.errors.own_report')]);
            }
            $submission->update([
                'status' => $to,
                'review_note' => $note,
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
            ]);
            $this->log($event, $actor, $submission);

            return $submission;
        });
    }

    private function assertEditable(ServiceMonitoringSubmission $submission): void
    {
        if (! $submission->status->isEditable()) {
            throw ValidationException::withMessages(['status' => __('service-monitoring.errors.report_locked')]);
        }
        if (! $submission->period->isOpen()) {
            throw ValidationException::withMessages(['period_id' => __('service-monitoring.errors.period_closed')]);
        }
    }

    private function log(AuditEventType $event, User $actor, ServiceMonitoringSubmission $submission): void
    {
        $this->audit->execute($event, $actor, $submission, $submission->organization_id, null, [
            'form_code' => $submission->form_code,
            'period_id' => $submission->period_id,
            'status' => $submission->status->value,
        ], $submission->review_note, request());
    }
}
