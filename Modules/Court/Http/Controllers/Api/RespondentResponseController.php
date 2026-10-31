<?php

namespace Modules\Court\Http\Controllers\Api;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Domain\Models\Respondent;
use Modules\Court\Domain\Models\RespondentResponse;
use Modules\Court\Application\Services\ResponseNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RespondentResponseController extends Controller
{
    public function index(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor instanceof Respondent, 403, 'Only respondents can view responses.');

        $responses = RespondentResponse::where('respondent_id', $actor->id)
            ->orderByDesc('created_at')
            ->get(['id', 'title', 'case_number', 'response_number', 'description', 'created_at']);

        return response()->json([
            'ok' => true,
            'data' => $responses,
        ]);
    }

    public function show(Request $request, RespondentResponse $response)
    {
        $actor = $request->user();
        abort_unless($actor instanceof Respondent, 403, 'Only respondents can view responses.');
        abort_unless((int) $response->respondent_id === (int) $actor->id, 403, 'Not your response.');

        return response()->json([
            'ok' => true,
            'data' => [
                'id' => $response->id,
                'title' => $response->title,
                'case_number' => $response->case_number,
                'response_number' => $response->response_number,
                'description' => $response->description,
                'created_at' => $response->created_at,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor instanceof Respondent, 403, 'Only respondents can submit responses.');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'case_number' => ['nullable', 'string', 'max:64'],
            'pdf' => ['required', 'file', 'mimes:pdf', 'max:5120'],
        ]);

        $caseNumber = $this->normalizeCaseNumber($data['case_number'] ?? null);

        if ($caseNumber !== null) {
            $status = DB::table('court_cases')
                ->where('case_number', $caseNumber)
                ->value('status');

            if ($status === 'closed') {
                throw ValidationException::withMessages([
                    'case_number' => [__('court::respondent.case_closed')],
                ]);
            }
        }

        $this->assertCaseAuthorized($caseNumber, $actor->id, true);

        $path = app(\Modules\Court\Application\Services\SecureUploadService::class)->store(
            $request->file('pdf'),
            'respondent/responses',
            'court_private',
            ['related_type' => 'respondent']
        );

        $response = DB::transaction(function () use ($actor, $caseNumber, $data, $path) {
            return RespondentResponse::create([
                'respondent_id' => $actor->id,
                'case_number' => $caseNumber,
                'response_number' => $this->nextResponseNumber($caseNumber),
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'pdf_path' => $path,
            ]);
        });

        ResponseNotificationService::notifyRespondentResponseCreated($response);

        return response()->json([
            'ok' => true,
            'data' => [
                'id' => $response->id,
                'title' => $response->title,
                'case_number' => $response->case_number,
                'response_number' => $response->response_number,
                'created_at' => $response->created_at,
            ],
        ], 201);
    }

    private function assertCaseAuthorized(?string $caseNumber, int $respondentId, bool $api): void
    {
        if (!$caseNumber) {
            return;
        }

        $caseExists = DB::table('court_cases')->where('case_number', $caseNumber)->exists();
        if (!$caseExists) {
            $this->throwCaseNumberError(__('court::respondent.case_not_found'), $api);
        }

        $authorized = DB::table('court_respondent_case_views')
            ->where('respondent_id', $respondentId)
            ->where('case_number', $caseNumber)
            ->exists();

        if (!$authorized) {
            $this->throwCaseNumberError(__('court::respondent.case_not_authorized'), $api);
        }
    }

    private function throwCaseNumberError(string $message, bool $api): void
    {
        if ($api) {
            throw ValidationException::withMessages(['case_number' => [$message]]);
        }

        abort(403, $message);
    }

    private function normalizeCaseNumber(?string $caseNumber): ?string
    {
        if ($caseNumber === null) {
            return null;
        }

        $normalized = trim($caseNumber);
        return $normalized === '' ? null : $normalized;
    }

    private function nextResponseNumber(?string $caseNumber): ?string
    {
        if ($caseNumber === null) {
            return null;
        }

        DB::table('court_cases')
            ->where('case_number', $caseNumber)
            ->lockForUpdate()
            ->first();

        return RespondentResponse::nextResponseNumberForCase($caseNumber);
    }
}
