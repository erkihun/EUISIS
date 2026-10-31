<?php

namespace Modules\Court\Http\Controllers\Api;

use Modules\Court\Http\Controllers\Api\Concerns\HandlesCaseAuthorization;
use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Http\Resources\CourtCaseResource;
use Modules\Court\Domain\Models\Applicant;
use Modules\Court\Domain\Models\CaseType;
use Modules\Court\Domain\Models\CourtCase;
use Modules\Court\Application\Services\ResponseNotificationService;
use Modules\Court\Application\Services\SecureUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Court\Application\Support\Purifier;

class CaseController extends Controller
{
    use HandlesCaseAuthorization;

    public function index(Request $request)
    {
        $actor = $request->user();

        $query = CourtCase::query()
            ->with([
                'caseType:id,name,prefix',
                'applicant:id,first_name,middle_name,last_name,email',
            ]);

        $this->applyActorScope($query, $actor);

        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('case_number', 'like', '%' . $search . '%')
                    ->orWhere('title', 'like', '%' . $search . '%');
            });
        }

        $statusFilter = $request->input('status');
        if (!is_null($statusFilter)) {
            $statuses = is_array($statusFilter)
                ? $statusFilter
                : array_filter(array_map('trim', explode(',', (string) $statusFilter)));

            if (!empty($statuses)) {
                $query->whereIn('status', $statuses);
            }
        }

        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);

        $cases = $query
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();

        return CourtCaseResource::collection($cases)->additional(['ok' => true]);
    }

    public function store(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor instanceof Applicant, 403, 'Only applicants can create cases.');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:10000'],
            'relief_requested' => ['nullable', 'string', 'max:5000'],
            'respondent_name' => ['nullable', 'string', 'max:255'],
            'respondent_address' => ['nullable', 'string', 'max:500'],
            'case_type_id' => ['required', 'integer', 'exists:court_case_types,id'],
            'filing_date' => ['required', 'date'],
            'first_hearing_date' => ['nullable', 'date'],

            'evidence_titles.*' => ['nullable', 'string', 'max:255'],
            'evidence_files.*' => ['nullable', 'file', 'mimes:pdf', 'max:5120'],

            'witnesses' => ['array'],
            'witnesses.*.full_name' => ['required_with:witnesses.*', 'string', 'max:255'],
            'witnesses.*.phone' => ['nullable', 'string', 'max:60'],
            'witnesses.*.email' => ['nullable', 'email', 'max:150'],
            'witnesses.*.address' => ['nullable', 'string', 'max:255'],
        ]);

        $descHtml = $this->cleanHtml($data['description'] ?? '');
        $reliefHtml = $this->cleanHtml($data['relief_requested'] ?? '');

        if ($this->wordCount($descHtml) > 1300 || $this->wordCount($reliefHtml) > 1300) {
            throw ValidationException::withMessages([
                'description' => ['Please keep each rich text field under ~1,300 words.'],
            ]);
        }

        DB::beginTransaction();

        try {
            $caseNumber = $this->generateCaseNumber($data['case_type_id']);

            $case = CourtCase::create([
                'applicant_id' => $actor->id,
                'case_number' => $caseNumber,
                'code' => $this->generateCaseCode(),
                'title' => trim($data['title']),
                'description' => $descHtml,
                'relief_requested' => $reliefHtml ?: null,
                'respondent_name' => $data['respondent_name'] ?? null,
                'respondent_address' => $data['respondent_address'] ?? null,
                'case_type_id' => $data['case_type_id'],
                'filing_date' => $data['filing_date'],
                'first_hearing_date' => $data['first_hearing_date'] ?? null,
                'status' => 'pending',
                'review_status' => 'awaiting_review',
            ]);

            $this->storeStatusLog($case->id, 'pending');
            // The applicant is the case's lead party (Modules\Court\Application\Services\CaseParties).
            app(\Modules\Court\Application\Services\CaseParties::class)->ensureLead((int) $case->id);
            $this->storeEvidence($case->id, $request, (int) $actor->id);
            $this->storeWitnesses($case->id, $request);

            DB::commit();

            ResponseNotificationService::notifyApplicantCaseCreated((int) $case->id);

            $case->loadMissing([
                'caseType:id,name,prefix',
                'applicant:id,first_name,middle_name,last_name,email',
            ]);

            return (new CourtCaseResource($case))
                ->additional([
                    'ok' => true,
                    'message' => 'Case created successfully.',
                ])
                ->response()
                ->setStatusCode(201);
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);

            return response()->json([
                'ok' => false,
                'message' => 'Failed to create case.',
            ], 500);
        }
    }

    public function show(Request $request, CourtCase $case)
    {
        $actor = $request->user();

        $this->assertCanViewCase($actor, $case);

        $case->loadMissing([
            'caseType:id,name,prefix',
            'applicant:id,first_name,middle_name,last_name,email',
        ]);

        return (new CourtCaseResource($case))->additional(['ok' => true]);
    }

    private function generateCaseNumber(int $caseTypeId): string
    {
        $caseType = CaseType::findOrFail($caseTypeId);
        $prefixBase = (string) ($caseType->prifix ?? $caseType->prefix ?? $caseType->name ?? 'CASE');
        $clean = preg_replace('/[\s\p{P}]+/u', '', $prefixBase);
        $prefix = mb_strtoupper(mb_substr($clean ?: 'CASE', 0, 4)) ?: 'CASE';
        $year = now()->format('y');

        $maxSeq = DB::table('court_cases')
            ->where('case_number', 'LIKE', "{$prefix}/%/{$year}")
            ->lockForUpdate()
            ->selectRaw("MAX(".\Modules\Court\Application\Support\Sql::middleSegmentNumber('case_number').") as max_seq")
            ->value('max_seq');

        $nextNumber = ((int) $maxSeq) + 1;
        $sequence = str_pad($nextNumber, 5, '0', STR_PAD_LEFT);

        return "{$prefix}/{$sequence}/{$year}";
    }

    private function generateCaseCode(): string
    {
        do {
            $code = str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);
            $exists = DB::table('court_cases')->where('code', $code)->exists();
        } while ($exists);

        return $code;
    }

    private function storeStatusLog(int $caseId, string $status): void
    {
        DB::table('court_case_status_logs')->insert([
            'case_id' => $caseId,
            'from_status' => null,
            'to_status' => $status,
            'changed_by_user_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function storeEvidence(int $caseId, Request $request, int $applicantId): void
    {
        if (!$request->hasFile('evidence_files')) {
            return;
        }

        $titles = $request->input('evidence_titles', []);

        foreach ($request->file('evidence_files') as $i => $file) {
            if (!$file instanceof UploadedFile) {
                continue;
            }

            $stored = app(SecureUploadService::class)->store($file, 'evidences', 'court_private', [
                'related_type' => 'court_case',
                'related_id' => $caseId,
                'applicant_id' => $applicantId,
            ]);

            $insert = [
                'case_id' => $caseId,
                'type' => 'document',
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (Schema::hasColumn('court_case_evidences', 'file_path')) {
                $insert['file_path'] = $stored;
            } elseif (Schema::hasColumn('court_case_evidences', 'path')) {
                $insert['path'] = $stored;
            }

            if (Schema::hasColumn('court_case_evidences', 'title')) {
                $insert['title'] = $titles[$i] ?? ('Document ' . ($i + 1));
            }
            if (Schema::hasColumn('court_case_evidences', 'description')) {
                $insert['description'] = null;
            }
            if (Schema::hasColumn('court_case_evidences', 'mime')) {
                $insert['mime'] = $file->getClientMimeType() ?: 'application/pdf';
            }
            if (Schema::hasColumn('court_case_evidences', 'size')) {
                $insert['size'] = $file->getSize();
            }

            if (Schema::hasColumn('court_case_evidences', 'uploaded_by_applicant_id')) {
                $insert['uploaded_by_applicant_id'] = $applicantId;
            } elseif (Schema::hasColumn('court_case_evidences', 'uploaded_by_user_id')) {
                $insert['uploaded_by_user_id'] = $applicantId;
            }

            DB::table('court_case_evidences')->insert($insert);
        }
    }

    private function storeWitnesses(int $caseId, Request $request): void
    {
        $witnesses = $request->input('witnesses', []);

        foreach ($witnesses as $w) {
            $fullName = trim((string) ($w['full_name'] ?? ''));
            if ($fullName === '') {
                continue;
            }

            $row = [
                'case_id' => $caseId,
                'full_name' => $fullName,
                'phone' => $w['phone'] ?? null,
                'email' => $w['email'] ?? null,
                'address' => $w['address'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (Schema::hasColumn('court_case_witnesses', 'created_by_user_id')) {
                $row['created_by_user_id'] = null;
            }
            if (Schema::hasColumn('court_case_witnesses', 'updated_by_user_id')) {
                $row['updated_by_user_id'] = null;
            }

            DB::table('court_case_witnesses')->insert($row);
        }
    }

    private function cleanHtml(?string $html): string
    {
        $s = (string) ($html ?? '');
        if ($s === '') {
            return '';
        }

        if (str_contains($s, '&lt;') || str_contains($s, '&gt;')) {
            $s = htmlspecialchars_decode($s, ENT_QUOTES);
        }

        return \Modules\Court\Application\Support\Purifier::clean($s, 'cases');
    }

    private function wordCount(string $html): int
    {
        return str_word_count(strip_tags($html));
    }
}
