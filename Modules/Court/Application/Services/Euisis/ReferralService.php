<?php

declare(strict_types=1);

namespace Modules\Court\Application\Services\Euisis;

use Modules\Court\Domain\Models\EuisisReferral;
use Modules\Court\Domain\Models\IntegrationClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Opens a court case from an EUISIS tribunal referral
 * (docs/euisis-integration.md, Flow 2). The case enters the existing intake
 * review (`review_status = awaiting_review`) like any filed case; the
 * registrar accepts, returns or rejects it. The grievance UUID is the
 * idempotency key: the same referral twice returns the same case.
 */
final class ReferralService
{
    public function __construct(
        private readonly CaseNumbers $numbers,
        private readonly CaseUpdateRecorder $updates,
        private readonly CaseDocuments $documents,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated request data
     * @param  list<array{file: UploadedFile, title: string}>  $documents
     * @return array{0: EuisisReferral, 1: bool} the referral, and whether this call created it
     */
    public function receive(IntegrationClient $client, array $data, array $documents): array
    {
        $existing = EuisisReferral::query()->where('referral_uuid', $data['referral_id'])->first();
        if ($existing !== null) {
            return [$existing, false];
        }

        $referral = DB::transaction(function () use ($client, $data, $documents): EuisisReferral {
            $caseId = DB::table('court_cases')->insertGetId([
                'applicant_id' => null,
                'case_number' => $this->numbers->next((int) $data['case_type_id']),
                'code' => $this->numbers->code(),
                'title' => trim(strip_tags((string) $data['title'])),
                'respondent_name' => strip_tags((string) ($data['respondent']['name'] ?? '')) ?: null,
                'respondent_address' => isset($data['respondent']['address']) ? strip_tags((string) $data['respondent']['address']) : null,
                'description' => $this->paragraphs((string) $data['summary']),
                'relief_requested' => isset($data['relief_requested']) ? $this->paragraphs((string) $data['relief_requested']) : null,
                'case_type_id' => (int) $data['case_type_id'],
                'filing_date' => now()->toDateString(),
                'status' => 'pending',
                'review_status' => 'awaiting_review',
                'source' => 'euisis',
                'euisis_referral_id' => $data['referral_id'],
                'respondent_organization_uuid' => $data['respondent']['organization_uuid'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('court_case_status_logs')->insert([
                'case_id' => $caseId, 'from_status' => null, 'to_status' => 'pending', 'changed_by_user_id' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            foreach ($documents as $document) {
                $this->documents->store($caseId, $document['file'], $document['title'], 'EUISIS referral');
            }

            $complainant = (array) ($data['complainant'] ?? []);

            return EuisisReferral::query()->create([
                'referral_uuid' => $data['referral_id'],
                'court_case_id' => $caseId,
                'integration_client_id' => $client->id,
                'grievance_reference' => $data['grievance_reference'] ?? null,
                'tribunal_case_number' => $data['tribunal_case_number'] ?? null,
                'complainant_name' => $complainant['name'] ?? null,
                'complainant_employee_uuid' => $complainant['employee_uuid'] ?? null,
                'complainant_phone' => $complainant['phone'] ?? null,
                'complainant_email' => $complainant['email'] ?? null,
                'respondent_organization_uuid' => $data['respondent']['organization_uuid'] ?? null,
                'referring_officer' => $data['referring_officer'] ?? null,
                'payload' => collect($data)->except(['documents', 'document_titles'])->all(),
            ]);
        });

        // The starting state is what the referral answer reports; later changes become update rows.
        $referral->forceFill(['last_reported_state' => $this->updates->stateOf($referral->court_case_id)])->save();

        return [$referral->fresh(['courtCase']), true];
    }

    /** Plain text as the escaped paragraphs the case views render (they show sanitized HTML). */
    private function paragraphs(string $text): string
    {
        $parts = preg_split('/\R{2,}/', trim($text)) ?: [];

        return implode('', array_map(fn (string $p): string => '<p>'.nl2br(e(trim($p)), false).'</p>', array_filter($parts, fn ($p) => trim($p) !== '')));
    }
}
