<?php

declare(strict_types=1);

namespace Modules\Court\Application\Services;

use Modules\Court\Domain\Models\CourtCase;
use Modules\Court\Domain\Models\Decision;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;
use Throwable;

/**
 * The record a party takes to the appellate court (EUISIS ADR 0013 #14): one PDF,
 * in the order things happened. It opens with the EUISIS proceeding the case
 * started from (the grievance or the discipline case, with its decisions), then
 * the case at this court (parties, claim, responses and replies, hearings and the
 * signed bench notes, witnesses, letters), the decision, the appeal itself, and an
 * index of the documents, which follow as annexes, each page stamped with its
 * annex number. A document that cannot be merged (a damaged or protected PDF, or
 * one in a form the merger cannot read) is listed as provided separately.
 */
final class AppealRecordBundle
{
    /** A document larger than this is listed, not merged. */
    private const MAX_DOCUMENT_BYTES = 15 * 1024 * 1024;

    public function __construct(private readonly CaseParties $parties) {}

    /** The bundled PDF's bytes. */
    public function build(CourtCase $case): string
    {
        $data = $this->data($case);
        $annexes = $this->annexes($case);

        // The record is drawn first to learn how many pages it takes, then with the annexes' page
        // numbers (once more if numbering them changed its length).
        $data['annexes'] = $annexes;
        $recordPages = $this->pageCount($this->render($data)) ?? 1;
        for ($pass = 0; $pass < 2; $pass++) {
            $next = $recordPages + 1;
            foreach ($annexes as $i => $annex) {
                $annexes[$i]['page'] = $annex['pages'] !== null ? $next : null;
                $next += (int) $annex['pages'];
            }
            $data['annexes'] = $annexes;
            $record = $this->render($data);
            $pages = $this->pageCount($record) ?? $recordPages;
            if ($pages === $recordPages) {
                break;
            }
            $recordPages = $pages;
        }

        return $this->merge($record, $annexes, (string) $case->case_number);
    }

    public function filename(CourtCase $case): string
    {
        return 'appeal-record-'.(preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($case->case_number ?: $case->id)) ?: $case->id).'.pdf';
    }

    /** @return array<string, mixed> */
    private function data(CourtCase $case): array
    {
        $case->loadMissing(['applicant', 'caseType']);
        $this->parties->ensureLead((int) $case->id);

        $parties = DB::table('court_case_applicants')->where('case_id', $case->id)->whereIn('status', ['active', 'admitted', 'invited'])
            ->orderByRaw("CASE role WHEN 'lead' THEN 0 WHEN 'co_applicant' THEN 1 ELSE 2 END")->orderBy('id')->get();
        $lawyers = DB::table('court_case_representatives as r')->join('court_applicants as a', 'a.id', '=', 'r.lawyer_applicant_id')
            ->where('r.case_id', $case->id)->where('r.status', 'accepted')
            ->get(['r.case_applicant_id', 'a.first_name', 'a.middle_name', 'a.last_name', 'a.lawyer_license_number'])
            ->groupBy('case_applicant_id');

        $responses = DB::table('court_respondent_responses')->where('case_number', $case->case_number)->where('review_status', 'accepted')->orderBy('created_at')->get();

        $decision = Decision::query()->where('court_case_id', $case->id)->orderByDesc('decision_date')->orderByDesc('id')->get()
            ->first(fn (Decision $decision): bool => $decision->isDownloadableByParties());

        return [
            'case' => $case,
            'generatedAt' => now(),
            'origins' => DB::table('court_case_euisis_grievances')->where('court_case_id', $case->id)->orderBy('submitted_at')->get()
                ->map(function (object $row): object {
                    $row->responses = (array) json_decode((string) $row->responses, true);
                    $row->decisions = (array) json_decode((string) $row->decisions, true);

                    return $row;
                }),
            'parties' => $parties->map(function (object $party) use ($lawyers): object {
                $party->lawyers = ($lawyers->get($party->id) ?? collect())->map(fn (object $lawyer): string => trim(CaseParties::fullName($lawyer).' ('.$lawyer->lawyer_license_number.')'))->all();

                return $party;
            }),
            'responses' => $responses,
            'replies' => DB::table('court_applicant_response_replies')->where('case_id', $case->id)->where('review_status', 'accepted')->orderBy('created_at')->get()
                ->map(function (object $reply) use ($responses): object {
                    $reply->response_title = $responses->firstWhere('id', $reply->respondent_response_id)?->title;

                    return $reply;
                }),
            'hearings' => DB::table('court_case_hearings')->where('case_id', $case->id)->orderBy('hearing_at')->get(),
            'benchNotes' => DB::table('court_bench_notes as b')
                ->leftJoin('court_staff as j1', 'j1.id', '=', 'b.judge_one_id')->leftJoin('court_staff as j2', 'j2.id', '=', 'b.judge_two_id')->leftJoin('court_staff as j3', 'j3.id', '=', 'b.judge_three_id')
                ->where('b.case_id', $case->id)->orderBy('b.created_at')
                ->get(['b.*', 'j1.name as judge_one_name', 'j2.name as judge_two_name', 'j3.name as judge_three_name']),
            'witnesses' => DB::table('court_case_witnesses')->where('case_id', $case->id)->orderBy('id')->get(),
            'letters' => DB::table('court_letters')->where('case_number', $case->case_number)->where('approval_status', 'approved')->orderBy('created_at')->get(),
            'decision' => $decision,
            'closedAt' => DB::table('court_case_status_logs')->where('case_id', $case->id)->whereIn('to_status', ['closed', 'dismissed'])->max('created_at'),
            'appeals' => DB::table('court_appeals as a')->leftJoin('court_staff as u', 'u.id', '=', 'a.released_by_user_id')
                ->where('a.court_case_id', $case->id)->whereNotNull('a.released_at')->orderBy('a.id')
                ->get(['a.*', 'u.name as released_by']),
        ];
    }

    /**
     * The documents that follow the record, in the order of the proceedings.
     *
     * @return list<array{title: string, stage: string, date: ?string, contents: ?string, pages: ?int}>
     */
    private function annexes(CourtCase $case): array
    {
        $items = collect();
        foreach (DB::table('court_case_evidences')->where('case_id', $case->id)->orderBy('created_at')->orderBy('id')->get() as $evidence) {
            $items->push(['title' => (string) ($evidence->title ?: __('court::appeal_records.bundle.document')), 'stage' => 'filing', 'date' => $evidence->created_at, 'path' => $evidence->file_path ?? $evidence->path]);
        }
        foreach (DB::table('court_respondent_responses')->where('case_number', $case->case_number)->where('review_status', 'accepted')->whereNotNull('pdf_path')->orderBy('created_at')->get() as $response) {
            $items->push(['title' => (string) ($response->title ?: __('court::appeal_records.bundle.response')), 'stage' => 'response', 'date' => $response->created_at, 'path' => $response->pdf_path]);
        }
        foreach (DB::table('court_applicant_response_replies')->where('case_id', $case->id)->where('review_status', 'accepted')->whereNotNull('pdf_path')->orderBy('created_at')->get() as $reply) {
            $items->push(['title' => __('court::appeal_records.bundle.reply'), 'stage' => 'reply', 'date' => $reply->created_at, 'path' => $reply->pdf_path]);
        }
        foreach (DB::table('court_case_files')->where('case_id', $case->id)->orderBy('created_at')->get() as $file) {
            if (($file->mime ?? 'application/pdf') === 'application/pdf') {
                $items->push(['title' => (string) ($file->label ?: __('court::appeal_records.bundle.document')), 'stage' => 'proceedings', 'date' => $file->created_at, 'path' => $file->path]);
            }
        }

        return $items->map(function (array $item): array {
            $contents = $this->read((string) $item['path']);
            $pages = $contents !== null ? $this->pageCount($contents) : null;
            // Most PDFs saved by Word or a scanner keep their objects in compressed streams,
            // which the free FPDI parser cannot read: rewritten plain, they merge.
            if ($contents !== null && $pages === null && ($plain = PdfNormalizer::normalize($contents)) !== null && ($plainPages = $this->pageCount($plain)) !== null) {
                [$contents, $pages] = [$plain, $plainPages];
            }

            return [
                'title' => $item['title'],
                'stage' => $item['stage'],
                'date' => $item['date'] ? Carbon::parse($item['date'])->toDateString() : null,
                'contents' => $pages !== null ? $contents : null,
                'pages' => $pages,
            ];
        })->values()->all();
    }

    /** @param  array<string, mixed>  $data */
    private function render(array $data): string
    {
        return Pdf::loadView('court::pdf.appeal-bundle', $data)->setPaper('a4')->setOption('isRemoteEnabled', false)->output();
    }

    /** The page count of a PDF the merger can read; null when it cannot. */
    private function pageCount(string $pdf): ?int
    {
        try {
            $reader = new Fpdi;

            return $reader->setSourceFile(StreamReader::createByString($pdf));
        } catch (Throwable) {
            return null;
        }
    }

    /** @param  list<array{title: string, stage: string, date: ?string, contents: ?string, pages: ?int, page?: ?int}>  $annexes */
    private function merge(string $record, array $annexes, string $caseNumber): string
    {
        $pdf = new Fpdi;
        $pdf->SetAutoPageBreak(false);
        $this->append($pdf, $record, null, $caseNumber);
        foreach ($annexes as $i => $annex) {
            if ($annex['contents'] !== null && $annex['pages'] !== null) {
                $this->append($pdf, $annex['contents'], $i + 1, $caseNumber);
            }
        }

        return $pdf->Output('S');
    }

    /** Appends every page of a PDF, stamping an annex's pages with its number and the case number. */
    private function append(Fpdi $pdf, string $contents, ?int $annex, string $caseNumber): void
    {
        $count = $pdf->setSourceFile(StreamReader::createByString($contents));
        for ($page = 1; $page <= $count; $page++) {
            $template = $pdf->importPage($page);
            $size = $pdf->getTemplateSize($template);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($template);
            if ($annex !== null) {
                // ASCII only: the annex index in the record names the document in its own language.
                $pdf->SetFont('Helvetica', '', 8);
                $pdf->SetTextColor(71, 85, 105);
                $pdf->SetXY(8, 4);
                // The core fonts have no Ethiopic letters: a number such as "ይ/00173/26" is stamped "Case 00173/26".
                $ascii = trim((string) preg_replace('/[^\x20-\x7E]/', '', $caseNumber), ' /-');
                $pdf->Cell($size['width'] - 16, 4, sprintf('Annex %d  |  Case %s  |  %d/%d', $annex, $ascii, $page, $count), 0, 0, 'R');
            }
        }
    }

    /** A stored PDF's bytes, from the private disk (or the public one, for older uploads); null when missing or not a PDF. */
    private function read(string $path): ?string
    {
        if ($path === '') {
            return null;
        }
        foreach (['court_private', 'court_public', 'local'] as $disk) {
            try {
                $storage = Storage::disk($disk);
                if ($storage->exists($path) && $storage->size($path) <= self::MAX_DOCUMENT_BYTES) {
                    $contents = (string) $storage->get($path);

                    return str_starts_with($contents, '%PDF') ? $contents : null;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }
}
