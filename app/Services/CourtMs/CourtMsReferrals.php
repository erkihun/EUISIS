<?php

declare(strict_types=1);

namespace App\Services\CourtMs;

use App\Enums\AuditEventType;
use App\Enums\Grievance\GrievanceEvidenceStatus;
use App\Jobs\Grievances\SendCourtMsReferral;
use App\Models\AdministrativeTribunalCase;
use App\Models\GrievanceEvidence;
use App\Models\User;
use App\Services\Grievances\GrievanceAudit;
use App\Services\Grievances\GrievanceTimeline;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Refers a tribunal case to court-ms (ADR 0013, Flow 2): a person confirms
 * what is sent, the send is queued and retried, and court-ms answers with
 * its case number. The grievance UUID is the idempotency key, so a retry
 * never opens a second court case.
 */
final class CourtMsReferrals
{
    public function __construct(
        private readonly CourtMsClient $client,
        private readonly GrievanceTimeline $timeline,
        private readonly GrievanceAudit $audit,
    ) {}

    /** What the referral form starts from. @return array<string, mixed> */
    public function defaults(AdministrativeTribunalCase $case): array
    {
        $grievance = $case->grievance;
        $map = (array) config('court_ms.case_type_map', []);
        $categoryCode = $grievance?->category?->code;

        return [
            'case_type_id' => $categoryCode !== null && isset($map[$categoryCode]) ? (int) $map[$categoryCode] : config('court_ms.default_case_type_id'),
            'title' => (string) ($grievance?->subject ?? ''),
            'summary' => self::plainText((string) ($grievance?->description ?? '')),
            'complainant_phone' => $grievance?->employee?->phone,
            'complainant_email' => $grievance?->employee?->email,
            'respondent_name' => (string) ($grievance?->organization?->name_en ?? ''),
        ];
    }

    /** Grievance descriptions are stored as HTML; the tribunal gets text with its paragraph breaks. */
    public static function plainText(string $html): string
    {
        $text = preg_replace(['#<br\s*/?>#i', '#</(p|div|li|h[1-6])>#i'], ["\n", "\n\n"], $html) ?? $html;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/\n{3,}/", "\n\n", str_replace("\r", '', $text)) ?? $text);
    }

    /** PDF evidence of the grievance a person may choose to send. @return Collection<int, GrievanceEvidence> */
    public function attachableEvidence(AdministrativeTribunalCase $case): Collection
    {
        if ($case->grievance === null) {
            return collect();
        }

        return GrievanceEvidence::query()
            ->where('grievance_id', $case->grievance_id)
            ->whereIn('status', [GrievanceEvidenceStatus::Submitted->value, GrievanceEvidenceStatus::Accepted->value])
            ->where('mime_type', 'application/pdf')
            ->where('size_bytes', '<=', (int) config('court_ms.max_document_bytes'))
            ->orderBy('submitted_at')
            ->get(['id', 'title', 'original_name', 'size_bytes', 'submitted_at']);
    }

    /**
     * Records what the officer chose and queues the send.
     *
     * @param  array<string, mixed>  $input  validated form data
     */
    public function queue(AdministrativeTribunalCase $case, User $actor, array $input): void
    {
        if ($case->isReferred()) {
            throw ValidationException::withMessages(['referral' => __('grievances.courtMs.alreadyReferred')]);
        }
        $allowed = $this->attachableEvidence($case)->pluck('id')->all();
        $documents = array_values(array_intersect((array) ($input['evidence_ids'] ?? []), $allowed));

        DB::transaction(function () use ($case, $actor, $input, $documents): void {
            $case->forceFill([
                'referral_state' => AdministrativeTribunalCase::REFERRAL_QUEUED,
                'referral_attempts' => 0,
                'referral_error' => null,
                'referred_at' => now(),
                'referred_by_user_id' => $actor->getKey(),
                'referral_payload' => [
                    'case_type_id' => (int) $input['case_type_id'],
                    'title' => (string) $input['title'],
                    'summary' => (string) $input['summary'],
                    'relief_requested' => $input['relief_requested'] ?? null,
                    'complainant_phone' => $input['complainant_phone'] ?? null,
                    'complainant_email' => $input['complainant_email'] ?? null,
                    'respondent_name' => (string) $input['respondent_name'],
                    'evidence_ids' => $documents,
                    'referring_officer' => $actor->name,
                ],
            ])->save();

            $this->timeline->record($case->grievance, 'court_ms_referral_queued', $actor, ['tribunal_case' => $case->case_number]);
            $this->audit->record(AuditEventType::GrievanceConfigurationChanged, $actor, $case,
                ['court_ms_referral' => 'queued', 'case_type_id' => (int) $input['case_type_id'], 'documents' => count($documents)]);
        });

        DB::afterCommit(fn () => SendCourtMsReferral::dispatch($case->getKey()));
    }

    /**
     * Sends a queued referral. Throws CourtMsException when court-ms could
     * not take it; a refusal of the content marks the referral failed at once.
     */
    public function send(AdministrativeTribunalCase $case): void
    {
        if ($case->referral_state === AdministrativeTribunalCase::REFERRAL_SENT || $case->grievance === null) {
            return;
        }
        $choice = (array) $case->referral_payload;
        $grievance = $case->grievance->loadMissing(['employee', 'organization']);

        $payload = [
            'referral_id' => $grievance->getKey(),
            'grievance_reference' => $grievance->reference_number,
            'tribunal_case_number' => $case->case_number,
            'case_type_id' => (int) ($choice['case_type_id'] ?? 0),
            'title' => (string) ($choice['title'] ?? $grievance->subject),
            'summary' => (string) ($choice['summary'] ?? ''),
            'relief_requested' => $choice['relief_requested'] ?? null,
            'complainant' => array_filter([
                'name' => (string) ($grievance->employee?->full_name ?? $grievance->employee?->name_en ?? ''),
                'employee_uuid' => $grievance->employee_id,
                'phone' => $choice['complainant_phone'] ?? null,
                'email' => $choice['complainant_email'] ?? null,
            ], fn ($v) => $v !== null && $v !== ''),
            'respondent' => array_filter([
                'name' => (string) ($choice['respondent_name'] ?? $grievance->organization?->name_en ?? ''),
                'organization_uuid' => $grievance->organization_id,
            ], fn ($v) => $v !== null && $v !== ''),
            'referring_officer' => $choice['referring_officer'] ?? null,
        ];

        $case->forceFill(['referral_attempts' => (int) $case->referral_attempts + 1])->save();

        try {
            $record = $this->client->refer(array_filter($payload, fn ($v) => $v !== null), $this->documents($choice['evidence_ids'] ?? []));
        } catch (CourtMsException $e) {
            $case->forceFill(['referral_error' => mb_substr($e->getMessage(), 0, 500)])->save();
            if (! $e->retryable) {
                $this->fail($case, $e->getMessage());
            }
            throw $e;
        }

        DB::transaction(function () use ($case, $record): void {
            $case->forceFill([
                'referral_state' => AdministrativeTribunalCase::REFERRAL_SENT,
                'referral_error' => null,
                'court_case_id' => $record['case_id'] ?? null,
                'court_case_number' => $record['case_number'] ?? null,
                'court_case_code' => $record['code'] ?? null,
                'court_review_status' => $record['review_status'] ?? null,
                'court_status' => $record['status'] ?? null,
                'court_synced_at' => now(),
            ])->save();
            $this->timeline->record($case->grievance, 'court_ms_referred', $case->referredByUser,
                ['court_case_number' => $record['case_number'] ?? null, 'tribunal_case' => $case->case_number]);
        });
    }

    /** Marks a referral failed, for a person to correct and send again. */
    public function fail(AdministrativeTribunalCase $case, string $reason): void
    {
        $case->forceFill([
            'referral_state' => AdministrativeTribunalCase::REFERRAL_FAILED,
            'referral_error' => mb_substr($reason, 0, 500),
        ])->save();
        if ($case->grievance !== null) {
            $this->timeline->record($case->grievance, 'court_ms_referral_failed', null, ['tribunal_case' => $case->case_number]);
        }
    }

    /** @return list<array{name: string, contents: string, title: string}> */
    private function documents(array $evidenceIds): array
    {
        if ($evidenceIds === []) {
            return [];
        }
        $out = [];
        foreach (GrievanceEvidence::query()->whereKey($evidenceIds)->get() as $evidence) {
            $disk = Storage::disk($evidence->disk);
            if (! $disk->exists($evidence->path)) {
                continue;
            }
            $out[] = [
                'name' => (string) ($evidence->original_name ?: 'evidence.pdf'),
                'contents' => (string) $disk->get($evidence->path),
                'title' => (string) ($evidence->title ?: $evidence->original_name),
            ];
        }

        return $out;
    }
}
