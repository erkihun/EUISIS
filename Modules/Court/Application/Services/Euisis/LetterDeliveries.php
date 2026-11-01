<?php

declare(strict_types=1);

namespace Modules\Court\Application\Services\Euisis;

use Modules\Court\Domain\Models\Letter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Flow 5 (docs/euisis-integration.md): letters the court sends to an EUISIS
 * institution. When a letter on a case whose respondent is an EUISIS institution
 * is approved (and marked for the respondent), it joins the delivery queue; EUISIS
 * reads the queue after a cursor (its court-ms poll) with each letter's PDF and
 * delivers it to that institution.
 */
final class LetterDeliveries
{
    public function record(Letter $letter): void
    {
        if (! filled($letter->case_number) || ! (bool) ($letter->send_to_respondent ?? true)) {
            return;
        }

        $case = DB::table('court_cases')->where('case_number', $letter->case_number)->first(['id', 'respondent_organization_uuid']);
        if ($case === null || ! filled($case->respondent_organization_uuid)) {
            return;
        }

        DB::table('court_euisis_letter_deliveries')->insertOrIgnore([
            'letter_id' => $letter->id,
            'court_case_id' => $case->id,
            'organization_uuid' => $case->respondent_organization_uuid,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array{data: list<array<string, mixed>>, next_cursor: int, has_more: bool} */
    public function after(int $since, int $limit): array
    {
        $rows = DB::table('court_euisis_letter_deliveries as d')
            ->join('court_letters as l', 'l.id', '=', 'd.letter_id')
            ->leftJoin('court_cases as c', 'c.id', '=', 'd.court_case_id')
            ->where('d.id', '>', $since)
            ->where('l.approval_status', 'approved')
            ->orderBy('d.id')
            ->limit($limit + 1)
            ->get(['d.id as cursor', 'd.organization_uuid', 'l.id as letter_id', 'l.subject', 'l.reference_number', 'l.recipient_name',
                'l.recipient_title', 'l.recipient_company', 'l.approved_by_name', 'l.approved_by_title', 'l.updated_at', 'c.case_number', 'c.title as case_title']);

        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);

        return [
            'data' => $rows->map(fn (object $row): array => [
                'cursor' => (int) $row->cursor,
                'letter_id' => (int) $row->letter_id,
                'organization_uuid' => (string) $row->organization_uuid,
                'case_number' => $row->case_number,
                'case_title' => $row->case_title,
                'subject' => $row->subject,
                'reference_number' => $row->reference_number,
                'recipient' => trim(implode(', ', array_filter([$row->recipient_name, $row->recipient_title, $row->recipient_company]))),
                'approved_by' => trim(implode(', ', array_filter([$row->approved_by_name, $row->approved_by_title]))) ?: null,
                'approved_at' => $row->updated_at ? Carbon::parse($row->updated_at)->toIso8601String() : null,
                'pdf_url' => '/api/v1/integration/letters/'.$row->letter_id.'/pdf',
            ])->values()->all(),
            'next_cursor' => (int) ($rows->last()->cursor ?? $since),
            'has_more' => $hasMore,
        ];
    }

    /** The approved letter as a PDF, when it is in the delivery queue; null otherwise. */
    public function pdf(Letter $letter): ?string
    {
        $queued = DB::table('court_euisis_letter_deliveries')->where('letter_id', $letter->id)->exists();
        if (! $queued || $letter->approval_status !== 'approved') {
            return null;
        }

        $case = DB::table('court_cases')->where('case_number', $letter->case_number)->first(['id', 'case_number', 'title']);

        return Pdf::loadView('court::pdf.letter-approved', ['letter' => $letter, 'case' => $case, 'caseNumber' => $letter->case_number])->setPaper('a4')->output();
    }
}
