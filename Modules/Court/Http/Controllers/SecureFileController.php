<?php

namespace Modules\Court\Http\Controllers;

use Modules\Court\Domain\Models\ApplicantResponseReply;
use Modules\Court\Domain\Models\Respondent;
use Modules\Court\Domain\Models\RespondentResponse;
use Modules\Court\Application\Support\CaseScope;
use Modules\Court\Application\Services\CaseParties;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SecureFileController extends Controller
{
    public function caseEvidence(int $caseId, int $evidenceId): StreamedResponse
    {
        $this->authorizeCaseAccess($caseId);

        $doc = DB::table('court_case_evidences')
            ->where('id', $evidenceId)
            ->where('case_id', $caseId)
            ->first();

        abort_if(!$doc, 404, 'Document not found.');

        return $this->downloadFile(
            $doc->file_path ?? $doc->path ?? null,
            $doc->title ?? $doc->label ?? null,
            $doc->mime ?? null
        );
    }

    public function caseFile(int $caseId, int $fileId): StreamedResponse
    {
        $this->authorizeCaseAccess($caseId);

        $row = DB::table('court_case_files')
            ->where('id', $fileId)
            ->where('case_id', $caseId)
            ->first();

        abort_if(!$row, 404, 'File not found.');

        return $this->downloadFile(
            $row->path ?? null,
            $row->label ?? null,
            $row->mime ?? null
        );
    }

    public function lawyerDocument(int $caseId): StreamedResponse
    {
        // Staff only — lawyer credential documents are reviewed by court staff.
        abort_unless($this->staffCan('cases.view'), 403);
        $this->ensureStaffScope(DB::table('court_cases')->where('id', $caseId)->first());

        $path = DB::table('court_cases as c')
            ->leftJoin('court_applicants as a', 'a.id', '=', 'c.applicant_id')
            ->where('c.id', $caseId)
            ->value('a.lawyer_document_path');

        abort_if(empty($path), 404, 'Document not found.');

        return $this->downloadFile($path, basename((string) $path), 'application/pdf', true);
    }

    public function respondentResponse(Request $request, RespondentResponse $response): StreamedResponse
    {
        $inline = $request->boolean('inline');

        if ($this->staffCan('cases.view')) {
            if ($response->case_number) {
                $this->ensureStaffScope(DB::table('court_cases')->where('case_number', $response->case_number)->first());
            }

            return $this->downloadFile($response->pdf_path, basename((string) $response->pdf_path), null, $inline);
        }

        $applicant = auth('applicant')->user();
        abort_unless($applicant, 403);

        // Any party on the applicant's side of the case (Modules\Court\Application\Services\CaseParties).
        $ownsCase = DB::table('court_cases')
            ->where('case_number', $response->case_number)
            ->whereIn('id', CaseParties::caseIdsFor((int) $applicant->id))
            ->exists();

        if ($ownsCase) {
            abort_if(($response->review_status ?? null) !== 'accepted', 403);
            return $this->downloadFile($response->pdf_path, basename((string) $response->pdf_path), null, $inline);
        }

        $respondentId = Respondent::where('email', $applicant->email)->value('id');
        abort_if(!$respondentId || (int) $response->respondent_id !== (int) $respondentId, 403);

        return $this->downloadFile($response->pdf_path, basename((string) $response->pdf_path), null, $inline);
    }

    public function applicantResponseReply(Request $request, int $caseId, int $responseId, ApplicantResponseReply $reply): StreamedResponse
    {
        $inline = $request->boolean('inline');

        if ($this->staffCan('cases.view')) {
            $this->ensureStaffScope(DB::table('court_cases')->where('id', $reply->case_id)->first());

            return $this->downloadFile($reply->pdf_path, basename((string) $reply->pdf_path), null, $inline);
        }

        $applicant = auth('applicant')->user();
        abort_unless($applicant, 403);

        $matchesTarget = DB::table('court_applicant_response_replies')
            ->where('id', $reply->id)
            ->where('case_id', $caseId)
            ->where('respondent_response_id', $responseId)
            ->exists();

        abort_unless($matchesTarget, 404);

        // A reply on a case the account is a party to: the parties see each other's replies.
        $applicantOwnsReply = DB::table('court_applicant_response_replies')
            ->where('id', $reply->id)
            ->where('case_id', $caseId)
            ->where('respondent_response_id', $responseId)
            ->exists() && CaseParties::canFollow((int) $applicant->id, $caseId);

        if ($applicantOwnsReply) {
            return $this->downloadFile($reply->pdf_path, basename((string) $reply->pdf_path), null, $inline);
        }

        $respondentId = Respondent::where('email', $applicant->email)->value('id');
        if ($respondentId) {
            $respondentAuthorized = DB::table('court_applicant_response_replies as arr')
                ->join('court_respondent_responses as rr', 'rr.id', '=', 'arr.respondent_response_id')
                ->join('court_respondent_case_views as rcv', function ($join) use ($respondentId) {
                    $join->on('rcv.case_id', '=', 'arr.case_id')
                        ->where('rcv.respondent_id', '=', $respondentId);
                })
                ->where('arr.id', $reply->id)
                ->where('arr.case_id', $caseId)
                ->where('arr.respondent_response_id', $responseId)
                ->where('arr.review_status', 'accepted')
                ->where('rr.respondent_id', $respondentId)
                ->exists();

            if ($respondentAuthorized) {
                return $this->downloadFile($reply->pdf_path, basename((string) $reply->pdf_path), null, $inline);
            }
        }

        abort(403);
    }

    public function respondentApplicantResponseReply(Request $request, ApplicantResponseReply $reply): StreamedResponse
    {
        $inline = $request->boolean('inline');

        if ($this->staffCan('cases.view')) {
            $this->ensureStaffScope(DB::table('court_cases')->where('id', $reply->case_id)->first());

            return $this->downloadFile($reply->pdf_path, basename((string) $reply->pdf_path), null, $inline);
        }

        $applicant = auth('applicant')->user();
        abort_unless($applicant, 403);

        $respondentId = Respondent::where('email', $applicant->email)->value('id');
        abort_if(!$respondentId, 403);

        $respondentAuthorized = DB::table('court_applicant_response_replies as arr')
            ->join('court_respondent_responses as rr', 'rr.id', '=', 'arr.respondent_response_id')
            ->join('court_respondent_case_views as rcv', function ($join) use ($respondentId) {
                $join->on('rcv.case_id', '=', 'arr.case_id')
                    ->where('rcv.respondent_id', '=', $respondentId);
            })
            ->where('arr.id', $reply->id)
            ->where('arr.review_status', 'accepted')
            ->where('rr.respondent_id', $respondentId)
            ->exists();

        abort_unless($respondentAuthorized, 403);

        return $this->downloadFile($reply->pdf_path, basename((string) $reply->pdf_path), null, $inline);
    }

    public function appealDocument(int $appealId, int $docId): StreamedResponse
    {
        if (!$this->staffCan('appeals.view') && !$this->staffCan('appeals.edit') && !$this->staffCan('appeals.decide')) {
            abort(403);
        }

        $doc = DB::table('court_appeal_documents')
            ->where('id', $docId)
            ->where('appeal_id', $appealId)
            ->first();

        abort_if(!$doc, 404, 'Document not found.');

        return $this->downloadFile(
            $doc->path ?? null,
            $doc->label ?? null,
            $doc->mime ?? null
        );
    }

    /** Staff with cases.view reach a case's files only within their team scope (CaseScope). */
    private function ensureStaffScope(?object $case): void
    {
        abort_if($case === null, 404);
        CaseScope::ensure($case);
    }

    private function authorizeCaseAccess(int $caseId): void
    {
        if ($this->staffCan('cases.view')) {
            $this->ensureStaffScope(DB::table('court_cases')->where('id', $caseId)->first());

            return;
        }

        if ($this->applicantOwnsCase($caseId)) {
            return;
        }

        if ($this->respondentHasViewedCase($caseId)) {
            return;
        }

        abort(403);
    }

    private function applicantOwnsCase(int $caseId): bool
    {
        $aid = auth('applicant')->id();
        if (!$aid) {
            return false;
        }

        return CaseParties::canFollow((int) $aid, $caseId);
    }

    private function respondentHasViewedCase(int $caseId): bool
    {
        $respondentId = $this->actingRespondentId();
        if (!$respondentId) {
            return false;
        }

        $access = session('respondent_case_access', []);
        if (!is_array($access)) {
            return false;
        }

        $lifetime = (int) config('session.lifetime', 120);
        $threshold = now()->subMinutes($lifetime)->timestamp;
        $access = array_filter($access, fn ($ts) => is_int($ts) && $ts >= $threshold);

        session(['respondent_case_access' => $access]);

        return array_key_exists($caseId, $access);
    }

    private function actingRespondentId(): ?int
    {
        if (!session('acting_as_respondent')) {
            return null;
        }

        $applicant = auth('applicant')->user();
        if (!$applicant) {
            return null;
        }

        return Respondent::where('email', $applicant->email)->value('id');
    }

    private function staffCan(string $perm): bool
    {
        $user = Auth::user();
        if (!$user) {
            return false;
        }

        if (method_exists($user, 'hasRole') && $user->hasRole('admin')) {
            return true;
        }

        if (method_exists($user, 'hasPermission') && $user->hasPermission($perm)) {
            return true;
        }

        if (method_exists($user, 'can') && $user->can($perm)) {
            return true;
        }

        if (function_exists('userHasPermission') && \Modules\Court\Application\Support\CourtPermissions::check($perm)) {
            return true;
        }

        return false;
    }

    private function downloadFile(?string $path, ?string $name = null, ?string $mime = null, bool $inline = false): StreamedResponse
    {
        abort_if(empty($path), 404, 'File missing.');

        $disk = Storage::disk('court_private');
        if (!$disk->exists($path)) {
            $fallback = Storage::disk('court_public');
            abort_if(!$fallback->exists($path), 404, 'File missing.');
            $disk = $fallback;
        }

        $downloadName = $this->sanitizeDownloadName($name ?: basename($path), basename($path));
        $resolvedMime = $mime;
        if (empty($resolvedMime)) {
            try {
                $resolvedMime = $disk->mimeType($path) ?: 'application/octet-stream';
            } catch (\Throwable $e) {
                $resolvedMime = 'application/octet-stream';
            }
        }
        $headers = [
            'Content-Type' => $resolvedMime,
        ];

        if ($inline) {
            return $disk->response($path, $downloadName, $headers);
        }

        return $disk->download($path, $downloadName, $headers);
    }

    private function sanitizeDownloadName(?string $preferredName, string $pathBasename): string
    {
        $name = trim((string) $preferredName);
        if ($name === '') {
            $name = $pathBasename;
        }

        // Avoid header/filename breaking chars.
        $name = str_replace(["\r", "\n"], '', $name);
        $name = str_replace(['/', '\\'], '-', $name);
        $name = trim($name, " .\t\n\r\0\x0B");

        if ($name === '') {
            $name = $pathBasename ?: 'download';
        }

        $ext = pathinfo($pathBasename, PATHINFO_EXTENSION);
        if ($ext !== '' && !Str::endsWith(Str::lower($name), '.' . Str::lower($ext))) {
            $name .= '.' . $ext;
        }

        return $name;
    }
}
