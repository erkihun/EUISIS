<?php

declare(strict_types=1);

namespace Modules\WorkExperience\Application\Services;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\User;
use App\Services\Calendar\EthiopianCalendarService;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\WorkExperience\Contracts\Data\WorkExperienceStatus;
use Modules\WorkExperience\Domain\Models\WorkExperienceRecord;
use Modules\WorkExperience\Domain\Models\WorkExperienceRequest;

/**
 * The life of a request for a work experience certificate (ADR 0040):
 *
 *   submitted  the employee asked (a form, not a letter); the office is told
 *   accepted   the office accepted it: the certificate is numbered and frozen, the employee is told
 *   returned   the office sent it back with the reason; the employee is told
 *   sent       the printed, signed and stamped certificate was sent; the employee is told
 *   cancelled  the employee withdrew it before it was decided
 *
 * Only the employee asks, signed in with their own account. The one exception
 * is an employee who has left: they have no account, so the office enters the
 * request for them.
 */
class WorkExperienceRequests
{
    /** Employees still in service ask for themselves. */
    private const IN_SERVICE = [EmployeeStatus::Draft, EmployeeStatus::Active, EmployeeStatus::Suspended];

    public function __construct(
        private readonly WorkExperienceAccess $access,
        private readonly WorkExperienceTimeline $timeline,
        private readonly WorkExperienceOffice $office,
        private readonly WorkExperienceNotifier $notifier,
        private readonly EthiopianCalendarService $ethiopian,
        private readonly WriteAuditLogAction $audit,
    ) {}

    /** Has this employee left the service, so that the office may ask on their behalf? */
    public function hasLeft(Employee $employee): bool
    {
        return ! in_array($employee->status, self::IN_SERVICE, true);
    }

    /**
     * @param  array{addressee?: ?string, purpose: string, language?: ?string}  $data
     * @param  bool  $byOffice  the office enters it for an employee who has left
     */
    public function submit(User $actor, Employee $employee, array $data, bool $byOffice = false): WorkExperienceRequest
    {
        if ($byOffice && ! $this->hasLeft($employee)) {
            throw $this->invalid('employee_id', 'still_in_service');
        }
        $organizationId = $this->access->officeOf($employee);
        if ($organizationId === null) {
            throw $this->invalid($byOffice ? 'employee_id' : 'purpose', 'no_office');
        }

        $request = DB::transaction(function () use ($actor, $employee, $data, $byOffice, $organizationId): WorkExperienceRequest {
            $request = WorkExperienceRequest::query()->create([
                'reference_number' => $this->reference(),
                'employee_id' => $employee->id,
                'organization_id' => $organizationId,
                'requester_user_id' => $actor->getKey(),
                'registered_by_office' => $byOffice,
                'addressee' => filled($data['addressee'] ?? null) ? trim((string) $data['addressee']) : null,
                'purpose' => trim((string) $data['purpose']),
                'language' => ($data['language'] ?? 'am') === 'en' ? 'en' : 'am',
                'status' => WorkExperienceStatus::Submitted,
                'submitted_at' => now(),
            ]);
            $this->audit->execute(AuditEventType::WorkExperienceRequested, $actor, $request, $organizationId, null, [
                'reference_number' => $request->reference_number, 'language' => $request->language, 'registered_by_office' => $byOffice,
            ]);

            return $request;
        });

        $this->notifier->toOffice($request);

        return $request;
    }

    /** Accept the request: number the certificate and freeze what it says. */
    public function accept(User $actor, WorkExperienceRequest $request): WorkExperienceRequest
    {
        $accepted = $this->numbered(function () use ($actor, $request): WorkExperienceRequest {
            return DB::transaction(function () use ($actor, $request): WorkExperienceRequest {
                $locked = $this->lock($request);
                if ($locked->status !== WorkExperienceStatus::Submitted) {
                    throw $this->invalid('status', 'not_waiting');
                }
                $certificate = $this->certificateFor($locked);
                $year = $this->ethiopianYear();
                $sequence = (int) WorkExperienceRequest::query()->where('organization_id', $locked->organization_id)->where('certificate_year', $year)->max('certificate_sequence') + 1;

                $locked->forceFill([
                    'status' => WorkExperienceStatus::Accepted,
                    'decided_by' => $actor->getKey(),
                    'decided_at' => now(),
                    'decision_comment' => null,
                    'certificate_year' => $year,
                    'certificate_sequence' => $sequence,
                    'certificate_number' => sprintf('WE/%04d/%d', $sequence, $year),
                    'verification_code' => $this->verificationCode(),
                    'certificate' => $certificate,
                ])->save();
                $this->audit->execute(AuditEventType::WorkExperienceAccepted, $actor, $locked, $locked->organization_id, ['status' => WorkExperienceStatus::Submitted->value], [
                    'status' => $locked->status->value, 'certificate_number' => $locked->certificate_number, 'lines' => count($certificate['lines']),
                ]);

                return $locked;
            });
        });

        $this->notifier->toEmployee($accepted, 'accepted');

        return $accepted;
    }

    public function sendBack(User $actor, WorkExperienceRequest $request, string $comment): WorkExperienceRequest
    {
        $returned = DB::transaction(function () use ($actor, $request, $comment): WorkExperienceRequest {
            $locked = $this->lock($request);
            if ($locked->status !== WorkExperienceStatus::Submitted) {
                throw $this->invalid('status', 'not_waiting');
            }
            $locked->forceFill(['status' => WorkExperienceStatus::Returned, 'decided_by' => $actor->getKey(), 'decided_at' => now(), 'decision_comment' => trim($comment)])->save();
            $this->audit->execute(AuditEventType::WorkExperienceReturned, $actor, $locked, $locked->organization_id, ['status' => WorkExperienceStatus::Submitted->value], ['status' => $locked->status->value], trim($comment));

            return $locked;
        });

        $this->notifier->toEmployee($returned, 'returned');

        return $returned;
    }

    /** The record was corrected after the request was accepted: write the certificate again, before it is sent. */
    public function refresh(User $actor, WorkExperienceRequest $request): WorkExperienceRequest
    {
        return DB::transaction(function () use ($actor, $request): WorkExperienceRequest {
            $locked = $this->lock($request);
            if ($locked->status !== WorkExperienceStatus::Accepted) {
                throw $this->invalid('status', 'not_accepted');
            }
            $certificate = $this->certificateFor($locked);
            $locked->forceFill(['certificate' => $certificate])->save();
            $this->audit->execute(AuditEventType::WorkExperienceAccepted, $actor, $locked, $locked->organization_id, null, [
                'certificate_number' => $locked->certificate_number, 'lines' => count($certificate['lines']), 'rewritten' => true,
            ]);

            return $locked;
        });
    }

    /** The certificate page was opened for printing. */
    public function recordPrint(User $actor, WorkExperienceRequest $request): void
    {
        $request->forceFill(['print_count' => $request->print_count + 1, 'printed_at' => now()])->save();
        $this->audit->execute(AuditEventType::WorkExperiencePrinted, $actor, $request, $request->organization_id, null, [
            'certificate_number' => $request->certificate_number, 'print_count' => $request->print_count,
        ]);
    }

    /** The printed, signed and stamped certificate was sent to the employee. */
    public function markSent(User $actor, WorkExperienceRequest $request, ?string $note): WorkExperienceRequest
    {
        $sent = DB::transaction(function () use ($actor, $request, $note): WorkExperienceRequest {
            $locked = $this->lock($request);
            if ($locked->status !== WorkExperienceStatus::Accepted) {
                throw $this->invalid('status', 'not_accepted');
            }
            if ($locked->print_count < 1) {
                throw $this->invalid('status', 'not_printed');
            }
            $locked->forceFill(['status' => WorkExperienceStatus::Sent, 'sent_by' => $actor->getKey(), 'sent_at' => now(), 'delivery_note' => filled($note) ? trim((string) $note) : null])->save();
            $this->audit->execute(AuditEventType::WorkExperienceSent, $actor, $locked, $locked->organization_id, ['status' => WorkExperienceStatus::Accepted->value], [
                'status' => $locked->status->value, 'certificate_number' => $locked->certificate_number,
            ], $locked->delivery_note);

            return $locked;
        });

        $this->notifier->toEmployee($sent, 'sent');

        return $sent;
    }

    public function cancel(User $actor, WorkExperienceRequest $request): WorkExperienceRequest
    {
        return DB::transaction(function () use ($actor, $request): WorkExperienceRequest {
            $locked = $this->lock($request);
            if ($locked->status !== WorkExperienceStatus::Submitted) {
                throw $this->invalid('status', 'not_waiting');
            }
            $locked->forceFill(['status' => WorkExperienceStatus::Cancelled])->save();
            $this->audit->execute(AuditEventType::WorkExperienceCancelled, $actor, $locked, $locked->organization_id, ['status' => WorkExperienceStatus::Submitted->value], ['status' => $locked->status->value]);

            return $locked;
        });
    }

    /**
     * What the certificate of this request says, from the record as it stands now.
     *
     * @return array<string, mixed>
     */
    public function certificateFor(WorkExperienceRequest $request): array
    {
        $employee = Employee::query()->findOrFail($request->employee_id);
        $settings = $this->office->settingsOf($request->organization_id);
        if (! $settings['configured']) {
            throw $this->invalid('status', 'no_signatory');
        }

        $today = $this->timeline->today();
        $lines = $this->timeline->linesFor($employee, $request->organization_id, $today);
        if (! $settings['include_external']) {
            $lines = array_values(array_filter($lines, static fn (array $line): bool => $line['kind'] === WorkExperienceRecord::KIND_INTERNAL));
        }
        if ($lines === []) {
            throw $this->invalid('status', 'no_experience');
        }
        $last = $lines[count($lines) - 1];
        $organization = Organization::query()->find($request->organization_id);

        return [
            'issued_on' => $today,
            'organization' => [
                'name_am' => $organization?->name_am ?: $organization?->name_en,
                'name_en' => $organization?->name_en ?: $organization?->name_am,
                'logo_path' => $organization?->logo_path,
            ],
            'employee' => [
                'name_am' => $employee->fullNameAm() ?: $employee->fullNameEn(),
                'name_en' => $employee->fullNameEn() ?: $employee->fullNameAm(),
                'employee_number' => $employee->employee_number,
            ],
            'lines' => array_map(static fn (array $line): array => [
                'employer_am' => $line['employer_am'], 'employer_en' => $line['employer_en'],
                'position_am' => $line['position_am'], 'position_en' => $line['position_en'],
                'unit_am' => $line['unit_am'], 'unit_en' => $line['unit_en'],
                'started_on' => $line['started_on'], 'ended_on' => $line['ended_on'],
                'monthly_salary' => $settings['include_salary'] ? $line['monthly_salary'] : null,
                'duration' => $line['duration'],
            ], $lines),
            'total' => $this->timeline->total($lines, $today),
            'first_day' => $lines[0]['started_on'],
            // Null while the last line is still running: the employee is in service today.
            'last_day' => $last['ended_on'],
            'include_salary' => $settings['include_salary'],
            'signatory' => [
                'name_am' => $settings['signatory_name_am'] ?: $settings['signatory_name_en'],
                'name_en' => $settings['signatory_name_en'] ?: $settings['signatory_name_am'],
                'title_am' => $settings['signatory_title_am'] ?: $settings['signatory_title_en'],
                'title_en' => $settings['signatory_title_en'] ?: $settings['signatory_title_am'],
            ],
        ];
    }

    private function lock(WorkExperienceRequest $request): WorkExperienceRequest
    {
        return WorkExperienceRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * Two officers accepting at the same moment would take the same number: the
     * unique index refuses the second, which simply takes the next one.
     *
     * @param  callable(): WorkExperienceRequest  $work
     */
    private function numbered(callable $work): WorkExperienceRequest
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $work();
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt >= 5) {
                    throw $exception;
                }
            }
        }
    }

    private function ethiopianYear(): int
    {
        $today = CarbonImmutable::parse($this->timeline->today());

        return (int) $this->ethiopian->gregorianToEthiopian($today->year, $today->month, $today->day)['year'];
    }

    private function reference(): string
    {
        $year = $this->ethiopianYear();
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $reference = sprintf('WX-%d-%06d', $year, random_int(0, 999999));
            if (! WorkExperienceRequest::query()->where('reference_number', $reference)->exists()) {
                return $reference;
            }
        }

        throw new \RuntimeException('No free work experience reference number.');
    }

    private function verificationCode(): string
    {
        // No 0/O or 1/I: the code is read off paper and typed by hand.
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = '';
            for ($i = 0; $i < 10; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            if (! WorkExperienceRequest::query()->where('verification_code', $code)->exists()) {
                return $code;
            }
        }

        throw new \RuntimeException('No free work experience verification code.');
    }

    private function invalid(string $field, string $key): ValidationException
    {
        return ValidationException::withMessages([$field => __("work_experience::messages.errors.{$key}")]);
    }
}
